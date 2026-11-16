<?php

namespace App\Shop;

use App\Jobs\SendCustomerMessage;
use App\Models\Cart;
use App\Models\CartItem;
use App\Notify\Message;
use App\Notify\NotifyPrefs;
use App\Support\Features;
use App\Support\Settings;
use Illuminate\Support\Facades\URL;

/**
 * One friendly reminder for a signed-in customer's cart, N hours (default 24)
 * after the last change. Never for guests, never twice for the same cart,
 * and the person can stop them with one tap.
 */
class CartReminders
{
    public function run(): int
    {
        if (Features::disabled('shop') || Features::disabled('messages.cart_reminder')) {
            return 0;
        }
        $hours = max(1, Settings::int('cart_reminder.hours'));
        $sent = 0;
        Cart::query()->whereNotNull('user_id')->whereNull('reminded_at')
            ->whereBetween('updated_at', [now()->subHours($hours + 48), now()->subHours($hours)])
            ->whereHas('items')->with(['user', 'items.product'])
            ->chunkById(200, function ($carts) use (&$sent) {
                foreach ($carts as $cart) {
                    /** @var Cart $cart */
                    $user = $cart->user;
                    if (! $user || ! $user->isActiveAccount() || ! NotifyPrefs::on($user, 'cart_reminder')) {
                        continue;
                    }
                    // Atomic: two schedulers can't both send it.
                    if (Cart::query()->whereKey($cart->id)->whereNull('reminded_at')->update(['reminded_at' => now()]) === 0) {
                        continue;
                    }
                    $names = $cart->items->map(fn (CartItem $i) => $i->product->name)->unique()->values();
                    $items = $names->first().($names->count() > 1 ? ' and '.($names->count() - 1).' more' : '');
                    $body = strtr(Settings::template('notify.cart_reminder'), [
                        '{name}' => $user->first_name, '{items}' => $items, '{site}' => Settings::string('site.name'),
                        '{url}' => URL::temporarySignedRoute('cart.resume', now()->addDays(7), ['cart' => $cart->id]),
                        '{stop}' => URL::signedRoute('cart-reminders.stop', ['user' => $user->id]),
                    ]);
                    SendCustomerMessage::dispatch(new Message('Your cart is waiting', $body, $user->phone, $user->email, $user->telegram_user_id, 'cart_reminder'));
                    $sent++;
                }
            });

        return $sent;
    }
}
