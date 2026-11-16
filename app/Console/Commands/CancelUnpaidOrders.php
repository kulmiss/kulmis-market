<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Orders\OrderService;
use App\Shop\Deposits;
use App\Support\Settings;
use Illuminate\Console\Command;

class CancelUnpaidOrders extends Command
{
    protected $signature = 'orders:cancel-unpaid';

    protected $description = 'Cancel unpaid orders after the configured hours and put the stock back.';

    public function handle(OrderService $orders): int
    {
        $cutoff = now()->subHours(Settings::int('orders.unpaid_cancel_hours'));
        $count = 0;
        Order::query()->where('status', Order::PENDING_PAYMENT)->where('created_at', '<', $cutoff)
            ->where('deposit_paid', 0) // a paid deposit keeps it open (the rest is due before shipping)
            // Custom-order quotes stay open longer than normal orders.
            ->where(fn ($q) => $q->where('checkout_key', 'not like', 'custom-%')->orWhere('created_at', '<', now()->subDays(Deposits::quoteDays())))
            // A payment proof waiting for review keeps the order open.
            ->whereDoesntHave('payments', fn ($p) => $p->where('status', 'awaiting_review')
                ->orWhere(fn ($q) => $q->where('status', 'pending')->where('created_at', '>', now()->subHour())))
            ->each(function (Order $order) use ($orders, &$count) {
                $orders->cancel($order, 'Not paid in time');
                $count++;
            });
        $this->info("Cancelled $count unpaid orders.");

        return self::SUCCESS;
    }
}
