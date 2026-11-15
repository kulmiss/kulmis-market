<?php

namespace App\Wallet;

use App\Fraud\FraudGuard;
use App\Models\PayoutAccount;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Security\SecurityAlerts;
use App\Security\TwoFactor;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The member's cash-out account (Telebirr or bank). Adding or changing it
 * needs the password (and a 2FA code when on), tells the member on every
 * channel, and starts a wait before cash-outs can go to it — so someone who
 * got into the account can't redirect the money before the owner notices.
 */
class PayoutAccounts
{
    public function __construct(private readonly FraudGuard $guard, private readonly SecurityAlerts $alerts) {}

    public function current(User $user): ?PayoutAccount
    {
        return PayoutAccount::query()->where('user_id', $user->id)->where('status', 'active')->latest('id')->first();
    }

    /** @param array{method:string, account_name:string, account_number:string, bank_name?:?string} $data */
    public function save(User $user, array $data, string $password, ?string $code = null): PayoutAccount
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Your password is not right.']);
        }
        if ($user->hasTwoFactor() && ! app(TwoFactor::class)->check($user, (string) $code)) {
            throw ValidationException::withMessages(['code' => 'Enter the code from your authenticator app.']);
        }
        if (! CashOuts::nameMatches($user, $data['account_name'])) {
            throw ValidationException::withMessages(['account_name' => 'The account name must match your name ('.$user->name.').']);
        }
        $number = preg_replace('/[\s-]+/', '', trim($data['account_number'])) ?? '';
        $fingerprint = FraudGuard::fingerprint($number);
        $current = $this->current($user);
        if ($current && $current->account_fingerprint === $fingerprint && $current->method === $data['method']) {
            return $current; // nothing changed: no new wait
        }

        $account = DB::transaction(function () use ($user, $data, $number, $fingerprint, $current) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $current?->update(['status' => 'replaced']);
            $account = PayoutAccount::query()->create([
                'user_id' => $user->id, 'method' => $data['method'], 'account_name' => trim($data['account_name']),
                'account_number' => $number, 'account_fingerprint' => $fingerprint, 'bank_name' => $data['bank_name'] ?? null,
                'status' => 'active', 'usable_after' => now()->addHours(max(0, Settings::int('wallet.payout_account_hold_hours'))),
            ]);
            // Requests still waiting for payment that pointed at the old account wait for review.
            PayoutRequest::query()->where('user_id', $user->id)->where('status', 'requested')
                ->update(['status' => 'on_hold', 'hold_reason' => 'Cash-out account changed after this request']);

            return $account;
        });

        $others = PayoutAccount::query()->where('account_fingerprint', $fingerprint)->where('user_id', '!=', $user->id)->pluck('user_id')->unique();
        if ($others->isNotEmpty()) {
            $this->guard->flag($user, 'shared_payout_account', ['other_users' => $others->values()->all()]);
            foreach ($others as $other) {
                $this->guard->flag((int) $other, 'shared_payout_account', ['other_users' => [$user->id]]);
            }
        }
        Activity::log('wallet.payout_account_saved', $account, ['method' => $account->method, 'replaced' => $current?->id], null, $user->id);
        $this->alerts->notify($user, 'payout_account_changed', [
            'account' => strtoupper($account->method).' '.$account->masked(),
            'usable_at' => $account->usable_after->timezone(config('app.timezone'))->format('d M H:i'),
        ]);

        return $account;
    }
}
