<?php

namespace Tests\Feature\Storefront;

use App\Mail\PlainMessage;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ShippingZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Logo A ("smiling mesob") is used everywhere: storefront, errors, admin, emails, PDFs, favicon, social preview. */
class BrandTest extends TestCase
{
    use RefreshDatabase;

    private const SMILE = 'M25 39.5q7 6.5 14 0';

    public function test_one_mark_everywhere(): void
    {
        $source = (string) file_get_contents(resource_path('brand/logo-mark.svg'));
        $this->assertStringContainsString(self::SMILE, $source);
        $this->assertStringContainsString('fill="#1f8a4c"', $source, 'the logo uses the member-area green');
        $this->assertStringNotContainsString('#b5532b', $source);
        $this->assertStringNotContainsString('#b5532b', (string) file_get_contents(resource_path('views/partials/logo-mark.blade.php')));
        $this->assertStringContainsString("fill='#1f8a4c'", (string) file_get_contents(base_path('admin/src/assets/logo.tsx')));
        $this->assertSame($source, file_get_contents(public_path('favicon.svg')));
        $this->assertStringContainsString("d='".self::SMILE."'", (string) file_get_contents(base_path('admin/src/assets/logo.tsx')));
        $this->assertStringNotContainsString('M8 20c4-8 12-8 16 0', (string) file_get_contents(base_path('admin/src/assets/logo.tsx')));

        $home = $this->get('/')->assertOk();
        $home->assertSee(self::SMILE, false)->assertSee('apple-touch-icon.png', false)->assertSee('site.webmanifest', false)
            ->assertSee('og-default.png', false)->assertDontSee('M8 20c4-8 12-8 16 0', false);
        $this->get('/no-such-page')->assertNotFound()->assertSee(self::SMILE, false);
    }

    public function test_icon_files_and_social_preview(): void
    {
        foreach (['favicon.ico', 'apple-touch-icon.png', 'brand/icon-192.png', 'brand/icon-512.png', 'brand/logo-96.png'] as $f) {
            $this->assertFileExists(public_path($f));
        }
        [$w, $h] = getimagesize(public_path('og-default.png')) ?: [0, 0];
        $this->assertSame([1200, 630], [$w, $h]);
        [$w, $h] = getimagesize(public_path('apple-touch-icon.png')) ?: [0, 0];
        $this->assertSame([180, 180], [$w, $h]);
        $manifest = json_decode((string) file_get_contents(public_path('site.webmanifest')), true);
        $this->assertSame('/brand/icon-512.png', $manifest['icons'][1]['src']);
        $this->assertSame('#1f8a4c', $manifest['theme_color']);
        $this->assertSame([31, 138, 76], $this->pixel('brand/icon-192.png', 20, 96), 'app icon is green');
    }

    public function test_emails_and_receipts_carry_the_logo(): void
    {
        $html = (new PlainMessage('Hello', 'Body text'))->render();
        $this->assertStringContainsString('brand/logo-96.png', $html);

        ShippingZone::query()->create(['name' => 'All', 'cities' => [], 'base_fee' => 10_000, 'is_default' => true]);
        $p = Product::factory()->create(['price' => 100_000, 'stock' => 5]);
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');
        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', [
            'checkout_key' => (string) Str::uuid(), 'first_name' => 'Selam', 'phone' => '0933445566', 'city' => 'Adama', 'address' => 'x',
        ])->assertCreated();
        $order = Order::query()->latest('id')->firstOrFail()->load('items');
        $payment = new Payment(['tx_ref' => 'KM-TEST', 'amount' => $order->total, 'provider' => Payment::BANK, 'paid_at' => now()]);
        $receipt = view('pdf.receipt', ['payment' => $payment, 'payable' => $order, 'order' => $order,
            'customer' => $order->payableCustomer(), 'title' => $order->payableTitle()])->render();
        $this->assertStringContainsString('data:image/png;base64,'.base64_encode((string) file_get_contents(public_path('brand/logo-96.png'))), $receipt);
    }

    /** @return array{int, int, int} */
    private function pixel(string $file, int $x, int $y): array
    {
        $img = imagecreatefrompng(public_path($file));
        $this->assertNotFalse($img);
        $rgb = imagecolorat($img, $x, $y);

        return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
    }
}
