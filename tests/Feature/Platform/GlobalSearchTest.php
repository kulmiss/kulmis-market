<?php

namespace Tests\Feature\Platform;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Top-bar search: grouped, role-aware, by ID / exact phrase / filters, and fast. */
class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        // Fixed name and email: a random one (e.g. "Abernathy") would also match the searches below.
        $u = User::factory()->role($role)->create(['first_name' => 'Staff', 'last_name' => 'Tester', 'email' => "staff-$role@kulmis.test", 'phone' => '+251944000'.str_pad((string) (crc32($role) % 1000), 3, '0', STR_PAD_LEFT)]);
        $u->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();

        return $u;
    }

    private function order(string $number, int $total, string $status = 'paid', ?string $phone = '+251911223344'): Order
    {
        return Order::query()->create(['number' => $number, 'first_name' => 'Selam', 'phone' => $phone, 'city' => 'A', 'address' => 'B',
            'list_subtotal' => $total, 'merchandise_total' => $total, 'total' => $total, 'checkout_key' => Str::random(10), 'status' => $status]);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function find(string $q, array $extra = []): array
    {
        $res = $this->getJson('/api/v1/admin/search?'.http_build_query(['q' => $q] + $extra))->assertOk();

        return collect($res->json('data.groups'))->mapWithKeys(fn ($g) => [$g['type'] => $g['items']])->all();
    }

    public function test_finds_members_by_name_phone_email_and_referral_code(): void
    {
        $abebe = User::factory()->create(['first_name' => 'Abebe', 'last_name' => 'Kebede', 'phone' => '+251911556677', 'email' => 'abebe@example.com', 'referral_code' => 'ABEBE25']);
        User::factory()->create(['first_name' => 'Hanna', 'last_name' => 'Girma', 'email' => 'hanna@kulmis.test', 'phone' => '+251922000111']);
        $this->actingAs($this->staff('super_admin'));

        foreach (['abe', 'Abebe Keb', '0911 55', '+251911556677', 'abebe@ex', 'abebe25', '#'.$abebe->id, '"Abebe"'] as $q) {
            $this->assertSame([$abebe->id], array_column($this->find($q)['members'] ?? [], 'id'), "search: $q");
        }
        $this->assertArrayNotHasKey('members', $this->find('"Abeb"'), 'exact phrase means exact');
        $this->getJson('/api/v1/admin/search?q=a')->assertJsonValidationErrors('q');
    }

    public function test_finds_orders_payments_settings_and_switches_grouped(): void
    {
        $o = $this->order('KM261115-00042', 250_000);
        Payment::query()->create(['uuid' => (string) Str::uuid(), 'payable_type' => $o->getMorphClass(), 'payable_id' => $o->id, 'provider' => 'telebirr',
            'tx_ref' => 'KMT-ABC123', 'amount' => 250_000, 'status' => 'paid', 'proof_reference' => 'TB99887766']);
        $this->actingAs($this->staff('super_admin'));

        $this->assertSame('KM261115-00042', $this->find('km2611')['orders'][0]['title']);
        $this->assertSame('KM261115-00042', $this->find('261115')['orders'][0]['title'], 'digits of the number');
        $this->assertSame('KM261115-00042', $this->find('0911223')['orders'][0]['title'], 'customer phone');
        $this->assertSame('KMT-ABC123', $this->find('TB998877')['payments'][0]['title'], 'receipt reference');
        $this->assertNotEmpty($this->find('membership price')['settings']);
        $product = Product::factory()->create(['name' => 'Woven Mesob Basket']);
        $this->assertSame([$product->id], array_column($this->find('woven')['products'], 'id'));
        $this->assertSame('Coupons', collect($this->find('coupon')['settings'])->firstWhere('sub', 'Feature switch · on')['title'] ?? null);
    }

    public function test_advanced_filters_type_status_dates_amounts(): void
    {
        $this->order('KM261115-00001', 100_000, 'paid');
        $this->order('KM261115-00002', 900_000, 'delivered');
        $this->actingAs($this->staff('super_admin'));

        $this->assertSame(['KM261115-00002'], array_column($this->find('KM2611', ['min' => 500_000])['orders'], 'title'));
        $this->assertSame(['KM261115-00001'], array_column($this->find('KM2611', ['status' => 'paid'])['orders'], 'title'));
        $this->assertSame([], $this->find('KM2611', ['from' => now()->addDay()->toDateString()]));
        $this->assertSame(['orders'], array_keys($this->find('KM2611', ['type' => 'orders'])));
        $this->getJson('/api/v1/admin/search?q=ab&type=passwords')->assertJsonValidationErrors('type');
    }

    public function test_staff_only_see_groups_their_role_can_open(): void
    {
        User::factory()->create(['first_name' => 'Kidist']);
        $this->order('KM261115-00077', 100_000);
        $this->actingAs($this->staff('fulfilment'));
        $this->assertSame(['orders'], array_keys($this->find('KM2611')));
        $this->assertArrayNotHasKey('members', $this->find('Kidist'), 'fulfilment can not open members');
        $this->assertArrayNotHasKey('settings', $this->find('membership price'));

        Features::set('shop', false);
        $this->assertArrayNotHasKey('orders', $this->find('KM2611'), 'switched-off features are not searched');
    }

    public function test_it_is_fast_on_a_big_shop(): void
    {
        $now = now()->format('Y-m-d H:i:s');
        foreach (array_chunk(range(1, 5000), 500) as $chunk) {
            DB::table('users')->insert(array_map(fn ($i) => ['first_name' => 'Name'.$i, 'last_name' => 'Sim', 'phone' => '+25991'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'email' => "u$i@sim.example", 'password' => 'x', 'referral_code' => 'R'.$i, 'created_at' => $now, 'updated_at' => $now], $chunk));
        }
        $this->actingAs($this->staff('super_admin'));
        $this->find('Name4'); // warm up
        $took = $this->getJson('/api/v1/admin/search?q=Name49')->assertOk()->json('data.took_ms');
        $this->assertLessThan(100, $took);
    }
}
