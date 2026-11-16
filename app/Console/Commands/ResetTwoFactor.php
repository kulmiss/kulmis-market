<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Security\SecurityAlerts;
use App\Security\Sessions;
use App\Support\Activity;
use App\Support\Phone;
use Illuminate\Console\Command;

/**
 * Lost the phone with the code app AND the recovery codes: someone with access
 * to the server turns two-step sign-in off for one account. They set it up
 * again at the next sign-in (staff must). The person is told on every channel.
 */
class ResetTwoFactor extends Command
{
    protected $signature = 'km:reset-2fa {login : email or phone of the account} {--force : do not ask}';

    protected $description = 'Turn two-step sign-in off for one account (lost phone and recovery codes)';

    public function handle(Sessions $sessions, SecurityAlerts $alerts): int
    {
        $login = (string) $this->argument('login');
        $user = User::query()->where('email', mb_strtolower($login))->orWhere('phone', Phone::normalize($login) ?? $login)->first();
        if (! $user) {
            $this->error('No account with that email or phone.');

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm("Turn two-step sign-in off for {$user->name}? They will be signed out everywhere.")) {
            return self::FAILURE;
        }
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'two_factor_last_step' => null])->save();
        $sessions->signOutOthers($user, null, null, false);
        $alerts->notify($user, 'two_factor_off');
        Activity::log('security.two_factor_reset_on_server', $user, [], 'km:reset-2fa');
        $this->info("Done. {$user->name} can sign in with the password and set up two-step sign-in again.");

        return self::SUCCESS;
    }
}
