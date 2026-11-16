<?php

namespace Tests\Feature\Platform;

use App\Jobs\SendCustomerMessage;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use App\Reports\Reports;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\Feature\Referral\ReferralTestCase;

/** Reports (CSV/PDF), bulk edit, CSV import with preview, low-stock alerts, announcement bar, staff overview. */
class ReportsAdminToolsTest extends ReferralTestCase
{
    public function test_an_evening_sale_is_in_todays_report(): void
    {
        $this->travelTo(now(config('app.timezone'))->setTime(22, 30)); // 22:30 in the shop's time zone: sales after 21:00 used to drop out of today's report
        $this->buyProducts(200_000);
        $this->actingAsAdmin();
        $this->assertSame(200_000, $this->getJson('/api/v1/admin/reports/products')->json('data.totals.revenue'));
        $this->travelTo(now(config('app.timezone'))->addDay()->setTime(1, 0)); // early next morning: yesterday's sale is not "today"
        $today = now(config('app.timezone'))->toDateString();
        $this->assertSame(0, (int) $this->getJson("/api/v1/admin/reports/products?from=$today&to=$today")->json('data.totals.revenue'));
    }

    public function test_every_report_runs_and_exports_safely(): void
    {
        $this->buyProducts(200_000);
        $o = $this->buyProducts(100_000);
        $o->items()->first()->update(['name' => '=HYPERLINK("http://evil")']);
        $this->actingAsAdmin();
        foreach (array_keys(Reports::TYPES) as $type) {
            $this->getJson("/api/v1/admin/reports/$type")->assertOk()->assertJsonStructure(['data' => ['title', 'columns', 'rows', 'totals']]);
        }
        $p = $this->getJson('/api/v1/admin/reports/products')->json('data');
        $this->assertSame(300_000, $p['totals']['revenue']);
        $this->assertSame(300_000 - 2 * 75_000, $p['totals']['profit']);
        $rev = collect($this->getJson('/api/v1/admin/reports/revenue')->json('data.rows'))->firstWhere('source', 'Shop sales');
        $this->assertSame(300_000 + 2 * 20_000, $rev['amount']); // goods + delivery

        $csv = $this->get('/api/v1/admin/reports/products?format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('3000.00', $csv);
        $pdf = $this->get('/api/v1/admin/reports/customers?format=pdf')->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
        $this->getJson('/api/v1/admin/reports/nope')->assertNotFound();

        $this->actingAsAdmin('support');
        $this->getJson('/api/v1/admin/reports/products')->assertForbidden();
    }

    public function test_bulk_edit_and_csv_import_with_preview(): void
    {
        $this->actingAsAdmin();
        $cat = Category::query()->create(['name' => 'Baskets', 'slug' => 'baskets', 'is_active' => true]);
        [$a, $b] = [Product::factory()->create(['price' => 100_000]), Product::factory()->create(['price' => 205_000])];
        $this->postJson('/api/v1/admin/products/bulk', ['ids' => [$a->id, $b->id], 'action' => 'price_percent', 'value' => 10])->assertOk();
        $this->assertSame([110_000, 225_500], [$a->fresh()->price, $b->fresh()->price]); // rounded to whole ETB
        $this->postJson('/api/v1/admin/products/bulk', ['ids' => [$a->id], 'action' => 'category', 'value' => $cat->id])->assertOk();
        $this->assertSame($cat->id, $a->fresh()->category_id);
        $this->postJson('/api/v1/admin/products/bulk', ['ids' => [$a->id], 'action' => 'price_percent', 'value' => -95])->assertJsonValidationErrors('value');

        $export = $this->get('/api/v1/admin/products-export.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('id,name,slug,status,category,price', $export);

        $csv = "id,name,price,category,stock\n{$a->id},,1500,Baskets,7\n,New mesob,2400.50,,3\n,Bad one,0.5,Nowhere,x\n";
        $file = fn () => UploadedFile::fake()->createWithContent('p.csv', $csv);
        $r = $this->post('/api/v1/admin/products-import', ['file' => $file()], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->assertFalse($r['ok']);
        $this->assertSame(['update', 'create', 'create'], array_column($r['rows'], 'action'));
        $this->assertNotEmpty($r['rows'][2]['errors']);
        $this->assertSame([110_000, 150_000], $r['rows'][0]['changes']['price']);
        $this->post('/api/v1/admin/products-import', ['file' => $file(), 'apply' => 1], ['Accept' => 'application/json'])->assertJsonPath('data.applied', false);
        $this->assertSame(110_000, $a->fresh()->price); // nothing saved while a row is wrong

        $good = "id,name,price,stock\n{$a->id},,1500,7\n,New mesob,2400.50,3\n";
        $this->post('/api/v1/admin/products-import', ['file' => UploadedFile::fake()->createWithContent('p.csv', $good), 'apply' => 1], ['Accept' => 'application/json'])
            ->assertJsonPath('data.applied', true);
        $this->assertSame([150_000, 7], [$a->fresh()->price, $a->fresh()->stock]);
        $new = Product::query()->where('name', 'New mesob')->firstOrFail();
        $this->assertSame([240_050, 'draft'], [$new->price, $new->status]);
    }

    public function test_low_stock_alert_once_when_an_order_crosses_the_line(): void
    {
        Bus::fake([SendCustomerMessage::class]);
        Settings::set('site.support_email', 'owner@example.com');
        $p = Product::factory()->create(['price' => 100_000, 'stock' => 5, 'low_stock_alert' => 3]);
        $buy = function () use ($p) {
            $this->flushHeaders();
            $t = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id, 'quantity' => 1])->json('token');
            $this->withHeaders(['X-Cart-Token' => $t])->postJson('/api/v1/checkout', ['checkout_key' => (string) Str::uuid(),
                'first_name' => 'A', 'phone' => '0911000'.random_int(100, 999), 'city' => 'Adama', 'address' => 'x'])->assertCreated();
        };
        $buy(); // 4 left
        Bus::assertNotDispatched(SendCustomerMessage::class, fn ($j) => str_starts_with($j->message->subject, 'Low stock'));
        $buy(); // 3 left → alert
        $buy(); // 2 left → no second alert
        $this->assertCount(1, Bus::dispatched(SendCustomerMessage::class, fn ($j) => str_starts_with($j->message->subject, 'Low stock') && $j->message->email === 'owner@example.com'));
    }

    public function test_announcement_bar_colour_and_end_date_and_staff_overview(): void
    {
        Banner::query()->create(['placement' => 'top_bar', 'title' => 'Timket sale this week!', 'is_active' => true, 'color' => 'gold', 'ends_at' => now()->addDays(3)]);
        $this->get('/')->assertSee('Timket sale this week!')->assertSee('bg-gold-500', false)->assertSee('data-ends=', false);

        $admin = $this->actingAsAdmin();
        $this->postJson('/api/v1/admin/banners', ['placement' => 'top_bar', 'title' => 'x', 'color' => 'purple'])->assertJsonValidationErrors('color');
        Activity::log('order.paid', null, [], null, $admin->id);
        $staff = $this->getJson('/api/v1/admin/activity/staff?days=7')->assertOk()->json('data.staff');
        $me = collect($staff)->firstWhere('id', $admin->id);
        $this->assertGreaterThanOrEqual(1, $me['actions']);
    }
}
