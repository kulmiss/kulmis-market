<?php

namespace App\Events;

use App\Models\Order;
use App\Models\OrderStatusChange;
use Illuminate\Foundation\Events\Dispatchable;

class OrderStatusChanged
{
    use Dispatchable;

    public function __construct(public Order $order, public OrderStatusChange $change) {}
}
