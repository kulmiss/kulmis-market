<?php

namespace App\Http\Controllers\Web;

use App\Cart\CartManager;
use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\WishlistItem;
use App\Pricing\PricingException;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Wishlist, saved addresses and "Buy again" in the member area. */
class ShopAccountController extends Controller
{
    public function wishlist(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $items = WishlistItem::query()->where('user_id', $user->id)->latest('id')
            ->with(['product' => fn ($q) => $q->with(['images', 'variants'])])->get()
            ->filter(fn (WishlistItem $w) => $w->product && $w->product->status === 'active');

        return view('member.wishlist', ['items' => $items, 'member' => $user->isActiveMember()]);
    }

    /** Heart button: add or remove. */
    public function toggleWishlist(Request $request, Product $product): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($product->status === 'active', 404);
        $existing = WishlistItem::query()->where('user_id', $user->id)->where('product_id', $product->id)->first();
        if ($existing) {
            $existing->delete();

            return back()->with('status', 'Removed from your wishlist.');
        }
        if (WishlistItem::query()->where('user_id', $user->id)->count() >= 200) {
            return back()->withErrors(['wishlist' => 'Your wishlist is full (200 items). Remove some first.']);
        }
        WishlistItem::query()->firstOrCreate(['user_id' => $user->id, 'product_id' => $product->id]);

        return back()->with('status', 'Saved to your wishlist.');
    }

    public function addresses(Request $request): View
    {
        return view('member.addresses', [
            'addresses' => Address::query()->where('user_id', $request->user()->id)->orderByDesc('is_default')->orderBy('id')->get(),
        ]);
    }

    public function saveAddress(Request $request, ?Address $address = null): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($address) {
            abort_unless($address->user_id === $user->id, 404);
        } elseif (Address::query()->where('user_id', $user->id)->count() >= Address::MAX_PER_USER) {
            return back()->withErrors(['address' => 'You can keep up to '.Address::MAX_PER_USER.' addresses. Delete one first.']);
        }
        $data = $this->validated($request);
        DB::transaction(function () use ($user, $address, $data) {
            $address ??= new Address;
            $address->user_id = $user->id;
            $address->fill($data);
            $first = ! Address::query()->where('user_id', $user->id)->exists();
            $address->is_default = $first || (bool) ($data['is_default'] ?? false);
            if ($address->is_default) {
                Address::query()->where('user_id', $user->id)->update(['is_default' => false]);
            }
            $address->save();
        });

        return redirect('/account/addresses')->with('status', 'Address saved.');
    }

    public function deleteAddress(Request $request, Address $address): RedirectResponse
    {
        abort_unless($address->user_id === $request->user()->id, 404);
        DB::transaction(function () use ($address) {
            $address->delete();
            if ($address->is_default) {
                Address::query()->where('user_id', $address->user_id)->orderBy('id')->first()?->update(['is_default' => true]);
            }
        });

        return redirect('/account/addresses')->with('status', 'Address deleted.');
    }

    /** Put the items of a past order back in the cart, at today's prices. */
    public function buyAgain(Request $request, CartManager $carts, string $number): RedirectResponse
    {
        $order = Order::query()->where('number', $number)->where('user_id', $request->user()->id)->with('items')->firstOrFail();
        $cart = $carts->findOrCreate($request);
        $added = 0;
        $skipped = [];
        foreach ($order->items as $item) {
            $product = $item->product_id ? Product::query()->with('variants')->find($item->product_id) : null;
            if (! $product || ! $product->isPurchasableBy($request->user()->isActiveMember())) {
                $skipped[] = $item->name;

                continue;
            }
            try {
                $carts->add($cart, $product->id, $item->product_variant_id, $item->quantity);
                $added++;
            } catch (ValidationException|PricingException) {
                $skipped[] = $item->name;
            }
        }
        $message = $added ? "Added $added ".($added === 1 ? 'item' : 'items').' to your cart at today\'s prices.' : 'None of these items can be bought right now.';
        if ($skipped) {
            $message .= ' Not available now: '.implode(', ', array_slice($skipped, 0, 5)).'.';
        }

        return redirect('/cart')->with('status', $message);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $request->merge(['phone' => Phone::normalize($request->input('phone')) ?? $request->input('phone')]);

        return $request->validate([
            'label' => ['nullable', 'string', 'max:40'],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'phone' => ['required', fn ($a, $v, $fail) => Phone::isValid($v) ?: $fail('Enter a valid Ethiopian mobile number.')],
            'city' => ['required', 'string', 'max:80'],
            'address' => ['required', 'string', 'max:500'],
            'is_default' => ['nullable', 'boolean'],
        ]) + ['label' => 'Home'];
    }
}
