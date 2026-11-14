<?php

namespace App\Orders;

use App\Models\Cart;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/** Later features add order columns at checkout (referral, wallet...). */
class CheckoutExtras
{
    /** @var list<Closure(Request, Cart, ?User, array<string, mixed>): array<string, mixed>> */
    private array $resolvers = [];

    public function add(Closure $resolver): void
    {
        $this->resolvers[] = $resolver;
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed> */
    public function collect(Request $request, Cart $cart, ?User $user, array $data): array
    {
        $extra = [];
        foreach ($this->resolvers as $r) {
            $extra = array_merge($extra, $r($request, $cart, $user, $data));
        }

        return $extra;
    }
}
