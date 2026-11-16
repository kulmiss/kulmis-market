<?php

namespace App\Http\Middleware;

use App\Cart\CartManager;
use App\Models\Cart;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A signed-in person's cart belongs to them (so the one cart reminder can
 * reach them). One small update per cart, remembered in the session.
 */
class LinkCartToUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $request->cookie(CartManager::COOKIE);
        if ($user !== null && is_string($token) && $request->hasSession() && $request->session()->get('cart_linked') !== $token
            && preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            Cart::query()->where('token', $token)->whereNull('user_id')->update(['user_id' => $user->getAuthIdentifier()]);
            $request->session()->put('cart_linked', $token);
        }

        return $next($request);
    }
}
