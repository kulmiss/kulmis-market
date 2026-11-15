<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the security event log for a year (+ a month), then removes the
 * routine sign-in noise. Money, admin and account-change events are audit
 * records and are never removed. Also drops expired sessions/devices data.
 */
class PruneLogs extends Command
{
    protected $signature = 'km:prune-logs';

    protected $description = 'Remove routine security events older than 13 months and stale device records';

    /** Routine events that only matter for a while. */
    public const ROUTINE = ['security.login_failed', 'security.login_while_locked', 'security.admin_session_expired',
        'security.two_factor_failed', 'security.bad_webhook_signature', 'customer.login', 'admin.login', 'admin.logout', 'payment.proof_viewed'];

    public function handle(): int
    {
        $cutoff = now()->subMonths(13);
        $events = DB::table('activity_log')->whereIn('action', self::ROUTINE)->where('created_at', '<', $cutoff)->delete();
        $devices = DB::table('user_devices')->where('last_seen_at', '<', now()->subMonths(13))->delete();
        $this->info("Removed $events routine log entries and $devices stale device records.");

        return self::SUCCESS;
    }
}
