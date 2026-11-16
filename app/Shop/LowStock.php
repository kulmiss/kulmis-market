<?php

namespace App\Shop;

use App\Jobs\SendCustomerMessage;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Notify\Message;
use App\Support\Features;
use App\Support\Settings;
use App\Telegram\TelegramClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** "Only N left": tell the owner (Telegram + email) the moment a product falls to its low-stock number. */
class LowStock
{
    public static function threshold(Product $p): int
    {
        return $p->low_stock_alert ?? Settings::int('catalog.low_stock');
    }

    /** Called after an order took $qty pieces: alert only when this order crossed the line. */
    public static function afterSale(Product $product, ?ProductVariant $variant, int $qty): void
    {
        if (Features::disabled('shop.low_stock_alerts') || $product->isMadeToOrder()) {
            return;
        }
        $now = (int) ($variant ? ProductVariant::query()->whereKey($variant->id)->value('stock') : Product::query()->whereKey($product->id)->value('stock'));
        $t = self::threshold($product);
        if ($now <= $t && $now + $qty > $t) {
            $name = $product->name.($variant ? ' — '.$variant->name : '');
            DB::afterCommit(fn () => app(self::class)->send($name, $now, $product->id));
        }
    }

    public function send(string $name, int $left, int $productId): void
    {
        $text = ($left === 0 ? "Sold out: $name." : "Only $left left: $name.").' Time to make or order more. '.url('/admin/products/'.$productId);
        Product::query()->whereKey($productId)->update(['low_stock_alerted_at' => now()]);
        $chat = config('services.telegram.admin_chat_id');
        if ($chat && app(TelegramClient::class)->configured()) {
            try {
                app(TelegramClient::class)->sendMessage($chat, e('📦 '.Settings::string('site.name').': '.$text));
            } catch (Throwable $e) {
                Log::warning('Low-stock Telegram alert failed: '.$e->getMessage());
            }
        }
        if (($email = Settings::string('site.support_email')) !== '') {
            SendCustomerMessage::dispatch(new Message('Low stock: '.$name, $text, null, $email));
        }
    }
}
