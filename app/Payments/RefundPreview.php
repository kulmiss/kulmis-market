<?php

namespace App\Payments;

use App\Ledger\Wallet;
use App\Models\Commission;
use App\Models\Payment;
use App\Models\Reward;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Before staff refund a payment: how much team money a full refund takes back,
 * and who has already spent (or cashed out) part of it. Their wallet then shows
 * a minus amount, and their next earnings pay it back first.
 */
final class RefundPreview
{
    /** @return array{taken_back:int, people:int, already_used:list<array{name:string, amount:int}>} */
    public static function for(Payment $payment): array
    {
        $source = $payment->getRelationValue('payable');
        if (! $source instanceof Model) {
            return ['taken_back' => 0, 'people' => 0, 'already_used' => []];
        }
        $type = $source->getMorphClass();
        $id = $source->getKey();
        $live = [Commission::PENDING, Commission::AVAILABLE];

        /** @var array<int, array{all:int, ready:int}> $per */
        $per = [];
        $add = function (int $user, int $amount, bool $ready) use (&$per): void {
            $per[$user] ??= ['all' => 0, 'ready' => 0];
            $per[$user]['all'] += $amount;
            $per[$user]['ready'] += $ready ? $amount : 0;
        };
        Commission::query()->where('source_type', $type)->where('source_id', $id)->whereIn('status', $live)->get()
            ->each(fn (Commission $c) => $add($c->recipient_id, $c->amount - $c->reversed_amount, $c->status === Commission::AVAILABLE));
        Reward::query()->where('source_type', $type)->where('source_id', $id)->whereIn('status', [Reward::PENDING, Reward::AVAILABLE])->get()
            ->each(fn (Reward $r) => $add($r->user_id, $r->amount - $r->reversed_amount, $r->status === Reward::AVAILABLE));

        $used = [];
        foreach ($per as $userId => $sum) {
            $user = User::query()->find($userId);
            if (! $user || $sum['ready'] <= 0) {
                continue;
            }
            $short = $sum['ready'] - max(0, Wallet::for($user)->available());
            if ($short > 0) {
                $used[] = ['name' => trim($user->first_name.' '.$user->last_name), 'amount' => min($short, $sum['ready'])];
            }
        }

        return ['taken_back' => array_sum(array_column($per, 'all')), 'people' => count(array_filter($per, fn ($s) => $s['all'] > 0)), 'already_used' => $used];
    }
}
