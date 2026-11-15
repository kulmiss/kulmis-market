<?php

namespace App\Wallet;

use App\Fraud\FraudGuard;
use App\Jobs\SendCustomerMessage;
use App\Ledger\Account;
use App\Ledger\Ledger;
use App\Ledger\Wallet;
use App\Models\PayoutAccount;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Notify\Message;
use App\Support\Activity;
use App\Support\Features;
use App\Support\Money;
use App\Support\Settings;
use App\Telegram\TelegramClient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Cash out to Telebirr or bank. Minimum 1,000 ETB, any time; Super Admin
 * approves and pays within 3 days. The payout account name must match the
 * member's name. Requested money is moved to a "payout" hold right away.
 * Only to the member's saved account (48-hour wait after a change, verified
 * by Super Admin on the first payout), with daily limits.
 */
class CashOuts
{
    public function __construct(private readonly Ledger $ledger, private readonly FraudGuard $guard) {}

    /**
     * Ask to cash out $amount to the member's saved account. Safe to repeat
     * with the same $idempotencyKey (double clicks, app retries).
     */
    public function request(User $user, int $amount, ?string $idempotencyKey = null): PayoutRequest
    {
        if (Features::disabled('wallet') || Features::disabled('wallet.cashout')) {
            throw ValidationException::withMessages(['amount' => 'Cash out is not available right now.']);
        }
        $key = $idempotencyKey ? 'u'.$user->id.':'.mb_substr($idempotencyKey, 0, 64) : null;
        if ($key && ($done = PayoutRequest::query()->where('idempotency_key', $key)->first())) {
            return $done;
        }
        $min = Settings::int('wallet.cashout_min');
        if ($amount < $min) {
            throw ValidationException::withMessages(['amount' => 'The minimum cash out is '.Money::format($min).'.']);
        }
        $account = app(PayoutAccounts::class)->current($user);
        if (! $account) {
            throw ValidationException::withMessages(['account' => 'Add your cash-out account first.']);
        }
        if (! $account->isUsable()) {
            throw ValidationException::withMessages(['account' => 'For your safety, a new or changed cash-out account can be used from '
                .$account->usable_after->timezone(config('app.timezone'))->format('d M Y H:i').'.']);
        }

        try {
            return DB::transaction(function () use ($user, $amount, $account, $key) {
                User::query()->whereKey($user->id)->lockForUpdate()->first();
                $since = now()->subDay();
                $recent = PayoutRequest::query()->where('user_id', $user->id)->where('created_at', '>=', $since)->where('status', '!=', 'rejected');
                if ((clone $recent)->count() >= max(1, Settings::int('wallet.cashout_daily_count'))) {
                    throw ValidationException::withMessages(['amount' => 'You reached the number of cash-outs allowed in 24 hours. Try again tomorrow.']);
                }
                $dailyMax = Settings::int('wallet.cashout_daily_max');
                $used = (int) (clone $recent)->sum('amount');
                if ($dailyMax > 0 && $used + $amount > $dailyMax) {
                    throw ValidationException::withMessages(['amount' => 'You can cash out up to '.Money::format(max(0, $dailyMax - $used)).' more in the next 24 hours.']);
                }
                $available = Wallet::for($user)->available();
                if ($amount > $available) {
                    throw ValidationException::withMessages(['amount' => 'You have '.Money::format(max(0, $available)).' available.']);
                }
                $hold = match (true) {
                    $this->guard->isOnHold($user) => 'Account under review',
                    $this->isFirstFromNewAccount($user) => 'First cash-out of a new account: reviewed before paying',
                    default => null,
                };
                $request = PayoutRequest::query()->create([
                    'user_id' => $user->id, 'payout_account_id' => $account->id, 'amount' => $amount, 'method' => $account->method,
                    'account_name' => $account->account_name, 'account_number' => $account->account_number,
                    'account_fingerprint' => $account->account_fingerprint, 'bank_name' => $account->bank_name,
                    'status' => $hold ? 'on_hold' : 'requested', 'hold_reason' => $hold, 'idempotency_key' => $key,
                    'due_at' => now()->addDays(Settings::int('wallet.payout_days')),
                ]);
                $availableAccount = $this->ledger->userAccount($user, Wallet::AVAILABLE);
                $this->ledger->post('payout.requested', [
                    [$availableAccount, $amount],
                    [$this->ledger->userAccount($user, Wallet::PAYOUT), -$amount],
                ], "payout:{$request->id}:requested", $request, description: 'Cash-out requested', nonNegative: [$availableAccount]);
                Activity::log('payout.requested', $request, ['amount' => $amount, 'hold' => $hold], null, $user->id);

                return $request;
            });
        } catch (QueryException $e) {
            // Two identical requests raced on the idempotency key: return the first.
            if ($key && ($done = PayoutRequest::query()->where('idempotency_key', $key)->first())) {
                return $done;
            }
            throw $e;
        }
    }

    private function isFirstFromNewAccount(User $user): bool
    {
        $days = Settings::int('wallet.new_account_review_days');

        return $days > 0 && $user->created_at !== null && $user->created_at->gt(now()->subDays($days))
            && ! PayoutRequest::query()->where('user_id', $user->id)->where('status', 'paid')->exists();
    }

    /** Super Admin paid it out (outside the system) and records the reference. */
    public function markPaid(PayoutRequest $request, string $reference, int $actorId, bool $accountChecked = false): PayoutRequest
    {
        return DB::transaction(function () use ($request, $reference, $actorId, $accountChecked) {
            $r = PayoutRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'requested') {
                throw new InvalidArgumentException($r->status === 'on_hold' ? 'This cash-out is on hold for review.' : 'Already processed.');
            }
            // Money only goes to verified accounts: the first payout to an
            // account needs Super Admin to confirm the name matches.
            $account = $r->payout_account_id ? PayoutAccount::query()->lockForUpdate()->find($r->payout_account_id) : null;
            if ($account && ! $account->verified_at) {
                if (! $accountChecked) {
                    throw new InvalidArgumentException('First payout to this account: check that the name on the Telebirr/bank account is '
                        .$r->account_name.', then tick "I checked the account".');
                }
                $account->update(['verified_at' => now(), 'verified_by' => $actorId]);
                Activity::log('wallet.payout_account_verified', $account, [], null, $actorId);
            }
            $this->ledger->post('payout.paid', [
                [$this->ledger->userAccount($r->user_id, Wallet::PAYOUT), $r->amount],
                [Account::CASH, -$r->amount],
            ], "payout:{$r->id}:paid", $r, description: 'Cash-out paid', actorId: $actorId);
            $r->update(['status' => 'paid', 'processed_by' => $actorId, 'processed_at' => now(), 'payout_reference' => $reference]);
            Activity::log('payout.paid', $r, ['reference' => $reference], null, $actorId);
            DB::afterCommit(fn () => $this->notify($r, Settings::string('notify.payout_paid'), null, 'payout_paid'));

            return $r;
        });
    }

    public function reject(PayoutRequest $request, string $reason, int $actorId): PayoutRequest
    {
        return DB::transaction(function () use ($request, $reason, $actorId) {
            $r = PayoutRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if (! in_array($r->status, ['requested', 'on_hold'], true)) {
                throw new InvalidArgumentException('Already processed.');
            }
            $this->ledger->post('payout.rejected', [
                [$this->ledger->userAccount($r->user_id, Wallet::PAYOUT), $r->amount],
                [$this->ledger->userAccount($r->user_id, Wallet::AVAILABLE), -$r->amount],
            ], "payout:{$r->id}:rejected", $r, $reason, $actorId, 'Cash-out returned to wallet');
            $r->update(['status' => 'rejected', 'processed_by' => $actorId, 'processed_at' => now(), 'reject_reason' => $reason]);
            Activity::log('payout.rejected', $r, [], $reason, $actorId);
            DB::afterCommit(fn () => $this->notify($r, Settings::string('notify.payout_rejected'), $reason, 'payout_rejected'));

            return $r;
        });
    }

    /** Daily reminder to Super Admin: due soon or overdue. */
    public function remindAdmin(TelegramClient $telegram): int
    {
        $due = PayoutRequest::query()->with('user')->whereIn('status', ['requested', 'on_hold'])
            ->where('due_at', '<=', now()->addDay())->orderBy('due_at')->get();
        if ($due->isEmpty()) {
            return 0;
        }
        $lines = $due->map(fn (PayoutRequest $r) => sprintf('#%d %s %s via %s — %s%s', $r->id, $r->user->name, Money::format($r->amount),
            $r->method, $r->due_at->isPast() ? 'OVERDUE since ' : 'due ', $r->due_at->timezone(config('app.timezone'))->format('d M H:i'))
            .($r->status === 'on_hold' ? ' (on hold)' : ''));
        $body = "Cash-outs to pay:\n".$lines->implode("\n");
        $chat = config('services.telegram.admin_chat_id');
        try {
            if ($chat && $telegram->configured()) {
                $telegram->sendMessage($chat, e($body));
            }
            SendCustomerMessage::dispatch(new Message('Cash-outs waiting', $body, null, Settings::string('site.support_email')));
        } catch (Throwable $e) {
            Log::warning('Payout reminder failed: '.$e->getMessage());
        }
        PayoutRequest::query()->whereKey($due->pluck('id'))->update(['last_reminded_at' => now()]);

        return $due->count();
    }

    public static function nameMatches(User $user, string $accountName): bool
    {
        $norm = fn (string $s) => array_values(array_filter(preg_split('/[^\p{L}]+/u', mb_strtolower(Str::ascii($s))) ?: []));
        $tokens = $norm($accountName);
        $first = $norm($user->first_name)[0] ?? null;
        $last = $norm($user->last_name)[0] ?? null;

        return $first !== null && in_array($first, $tokens, true) && ($last === null || in_array($last, $tokens, true));
    }

    private function notify(PayoutRequest $r, string $template, ?string $reason, string $type): void
    {
        $user = $r->user()->firstOrFail();
        SendCustomerMessage::dispatch(new Message('Your cash-out', strtr($template, [
            '{name}' => $user->first_name, '{amount}' => Money::format($r->amount), '{reference}' => (string) $r->payout_reference,
            '{reason}' => (string) $reason,
        ]), $user->phone, $user->email, $user->telegram_user_id, $type));
    }
}
