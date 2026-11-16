<?php

namespace App\Jobs;

use App\Membership\Members;
use App\Models\Signal;
use App\Models\User;
use App\Notify\Inbox;
use App\Notify\NotifyPrefs;
use App\Support\Features;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A note in the bell for members who asked for it ("New trading signals",
 * off by default), only for the pairs they chose. The signal itself stays
 * in the private Telegram channel.
 */
class NotifyNewSignal implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $signalId) {}

    public function handle(): void
    {
        $signal = Signal::query()->find($this->signalId);
        if (! $signal || Features::disabled('signals') || Features::disabled('account.notifications')) {
            return;
        }
        // Default is off, so only people who saved their choices can be on.
        User::query()->whereNotNull('notify_prefs')->where('status', 'active')->chunkById(500, function ($users) use ($signal) {
            foreach ($users as $u) {
                /** @var User $u */
                $pairs = NotifyPrefs::pairs($u);
                if (! NotifyPrefs::on($u, 'bell.signal') || ($pairs !== [] && ! in_array($signal->pair, $pairs, true)) || ! Members::isActive($u)) {
                    continue;
                }
                Inbox::add($u, 'signal', 'New signal: '.$signal->pair.' '.strtoupper((string) $signal->direction),
                    'Open the private Telegram channel to see the entry, targets and stop loss.', '/account/signals', "signal:{$signal->id}");
            }
        });
    }
}
