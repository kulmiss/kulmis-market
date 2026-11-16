<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Notify\WebPush;
use App\Support\Features;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** "You have news" to every phone of one person. Many events in a minute = one push. */
class SendWebPush implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 60;

    public function __construct(public readonly int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function handle(WebPush $push): void
    {
        if (Features::disabled('account.push') || ! WebPush::configured()) {
            return;
        }
        PushSubscription::query()->where('user_id', $this->userId)->limit(10)->get()
            ->each(fn (PushSubscription $s) => $push->send($s) ?: $s->delete());
    }
}
