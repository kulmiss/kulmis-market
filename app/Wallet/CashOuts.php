<?php

namespace App\Wallet;

use App\Fraud\FraudGuard;
use App\Jobs\SendCustomerMessage;
use App\Ledger\Account;
use App\Ledger\Ledger;
use App\Ledger\Wallet;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Notify\Message;
use App\Support\Activity;
use App\Support\Features;
use App\Support\Money;
use App\Support\Settings;
use App\Telegram\TelegramClient;
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
 */
class CashOuts
{
    public function __construct(private readonly Ledger $ledger, private readonly FraudGuard $guard) {}

    /** @param array{amount:int,method:string,account_name:string,account_number:string,bank_name?:?string} $data */
    public function request(User $user, array $data): PayoutRequest
    {
        if (Features::disabled('wallet') || Features::disabled('wallet.cashout')) {
            throw ValidationException::withMessages(['amount' => 'Cash out is not available right now.']);
        }
        $min = Settings::int('wallet.cashout_min');
        if ($data['amount'] < $min) {
            throw ValidationException::withMessages(['amount' => 'The minimum cash out is '.Money::format($min).'.']);
        }
        if (! self::nameMatches($user, $data['account_name'])) {
            throw ValidationException::withMessages(['account_name' => 'The account name must match your name ('.$user->name.').']);
        }
        $fingerprint = FraudGuard::fingerprint($data['account_number']);

        return DB::transaction(function () use ($user, $data, $fingerprint) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $available = Wallet::for($user)->available();
            if ($data['amount'] > $available) {
                throw ValidationException::withMessages(['amount' => 'You have '.Money::format(max(0, $available)).' available.']);
            }
            $hold = null;
            $shared = PayoutRequest::query()->where('account_fingerprint', $fingerprint)->where('user_id', '!=', $user->id)->pluck('user_id')->unique();
            if ($shared->isNotEmpty()) {
                $this->guard->flag($user, 'shared_payout_account', ['other_users' => $shared->values()->all()]);
                foreach ($shared as $other) {
                    $this->guard->flag((int) $other, 'shared_payout_account', ['other_users' => [$user->id]]);
                }
            }
            if ($this->guard->isOnHold($user)) {
                $hold = 'Account under review';
            }
            $request = PayoutRequest::query()->create([
                'user_id' => $user->id, 'amount' => $data['amount'], 'method' => $data['method'],
                'account_name' => trim($data['account_name']), 'account_number' => trim($data['account_number']),
                'account_fingerprint' => $fingerprint, 'bank_name' => $data['bank_name'] ?? null,
                'status' => $hold ? 'on_hold' : 'requested', 'hold_reason' => $hold,
                'due_at' => now()->addDays(Settings::int('wallet.payout_days')),
            ]);
            $available = $this->ledger->userAccount($user, Wallet::AVAILABLE);
            $this->ledger->post('payout.requested', [
                [$available, $data['amount']],
                [$this->ledger->userAccount($user, Wallet::PAYOUT), -$data['amount']],
            ], "payout:{$request->id}:requested", $request, description: 'Cash-out requested', nonNegative: [$available]);
            Activity::log('payout.requested', $request, ['amount' => $data['amount']], null, $user->id);

            return $request;
        });
    }

    /** Super Admin paid it out (outside the system) and records the reference. */
    public function markPaid(PayoutRequest $request, string $reference, int $actorId): PayoutRequest
    {
        return DB::transaction(function () use ($request, $reference, $actorId) {
            $r = PayoutRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'requested') {
                throw new InvalidArgumentException($r->status === 'on_hold' ? 'This cash-out is on hold for review.' : 'Already processed.');
            }
            $this->ledger->post('payout.paid', [
                [$this->ledger->userAccount($r->user_id, Wallet::PAYOUT), $r->amount],
                [Account::CASH, -$r->amount],
            ], "payout:{$r->id}:paid", $r, description: 'Cash-out paid', actorId: $actorId);
            $r->update(['status' => 'paid', 'processed_by' => $actorId, 'processed_at' => now(), 'payout_reference' => $reference]);
            Activity::log('payout.paid', $r, ['reference' => $reference], null, $actorId);
            DB::afterCommit(fn () => $this->notify($r, Settings::string('notify.payout_paid')));

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
            DB::afterCommit(fn () => $this->notify($r, Settings::string('notify.payout_rejected'), $reason));

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

    private function notify(PayoutRequest $r, string $template, ?string $reason = null): void
    {
        $user = $r->user()->firstOrFail();
        SendCustomerMessage::dispatch(new Message('Your cash-out', strtr($template, [
            '{name}' => $user->first_name, '{amount}' => Money::format($r->amount), '{reference}' => (string) $r->payout_reference,
            '{reason}' => (string) $reason,
        ]), $user->phone, $user->email, $user->telegram_user_id));
    }
}
