<?php

namespace App\Shop;

use App\Jobs\SendCustomerMessage;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockAlert;
use App\Models\User;
use App\Notify\Message;
use App\Support\Features;
use App\Support\Phone;
use App\Support\Settings;
use Illuminate\Validation\ValidationException;

/** "Tell me when it's back": one message (SMS or email) when a sold-out item has stock again. */
final class StockAlerts
{
    public const MAX_PER_CONTACT = 20;

    public function subscribe(Product $product, ?ProductVariant $variant, string $contact, ?User $user = null): StockAlert
    {
        [$value, $channel] = $this->contact($contact);
        if ($variant && $variant->product_id !== $product->id) {
            throw ValidationException::withMessages(['variant_id' => 'Choose an option of this product.']);
        }
        if ($product->isMadeToOrder() || $this->inStock($product, $variant)) {
            throw ValidationException::withMessages(['contact' => 'Good news: this is in stock now. You can add it to your cart.']);
        }
        if (StockAlert::query()->where('contact', $value)->whereNull('notified_at')->count() >= self::MAX_PER_CONTACT) {
            throw ValidationException::withMessages(['contact' => 'You already asked about many items. We will tell you when they are back.']);
        }

        return StockAlert::query()->updateOrCreate(
            ['product_id' => $product->id, 'product_variant_id' => $variant?->id, 'contact' => $value],
            ['channel' => $channel, 'user_id' => $user?->id, 'notified_at' => null],
        );
    }

    /** Called when stock goes from 0 to more: send each waiting person one message. */
    public function notify(int $productId, ?int $variantId): int
    {
        if (Features::disabled('shop.stock_alerts')) {
            return 0;
        }
        $product = Product::query()->find($productId);
        if (! $product || $product->status !== 'active') {
            return 0;
        }
        $sent = 0;
        StockAlert::query()->where('product_id', $productId)->whereNull('notified_at')
            ->where(fn ($q) => $variantId ? $q->where('product_variant_id', $variantId)->orWhereNull('product_variant_id') : $q->whereNull('product_variant_id'))
            ->orderBy('id')->chunkById(200, function ($alerts) use ($product, &$sent) {
                foreach ($alerts as $a) {
                    // Mark first: a second stock change never sends twice.
                    if (StockAlert::query()->whereKey($a->id)->whereNull('notified_at')->update(['notified_at' => now()]) === 0) {
                        continue;
                    }
                    $body = strtr(Settings::string('notify.back_in_stock'), [
                        '{product}' => $product->name, '{url}' => url('/shop/'.$product->slug), '{site}' => Settings::string('site.name'),
                    ]);
                    SendCustomerMessage::dispatch(new Message('Back in stock: '.$product->name, $body,
                        $a->channel === 'sms' ? $a->contact : null, $a->channel === 'email' ? $a->contact : null, null, 'back_in_stock'));
                    $sent++;
                }
            });

        return $sent;
    }

    private function inStock(Product $product, ?ProductVariant $variant): bool
    {
        return $variant ? (int) $variant->stock > 0 : (int) ($product->availableStock() ?? 1) > 0;
    }

    /** @return array{0:string, 1:string} [normalised contact, channel] */
    private function contact(string $raw): array
    {
        $raw = trim($raw);
        if (filter_var($raw, FILTER_VALIDATE_EMAIL)) {
            return [mb_strtolower($raw), 'email'];
        }
        $phone = Phone::normalize($raw);
        if ($phone && Phone::isValid($phone)) {
            return [$phone, 'sms'];
        }
        throw ValidationException::withMessages(['contact' => 'Enter your phone number (09…) or email.']);
    }
}
