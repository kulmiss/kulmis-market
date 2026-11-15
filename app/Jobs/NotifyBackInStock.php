<?php

namespace App\Jobs;

use App\Shop\StockAlerts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class NotifyBackInStock implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $productId, public ?int $variantId = null) {}

    public function handle(StockAlerts $alerts): void
    {
        $alerts->notify($this->productId, $this->variantId);
    }
}
