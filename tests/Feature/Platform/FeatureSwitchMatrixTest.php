<?php

namespace Tests\Feature\Platform;

use App\Membership\MembershipService;
use App\Models\Membership;
use App\Models\SignalSubscription;
use App\Models\User;
use App\Signals\SignalAccess;
use App\Support\Features;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Turning a feature off hides it everywhere: website pages, the API, the
 * admin API and the background jobs. Data is never deleted.
 */
class FeatureSwitchMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: list<array{0: string, 1: string, 2: string}>}> */
    public static function switches(): array
    {
        // [method, uri, who] — who: guest | customer | admin
        return [
            'shop' => ['shop', [
                ['GET', '/shop', 'guest'], ['GET', '/cart', 'guest'], ['GET', '/checkout', 'guest'], ['GET', '/track', 'guest'],
                ['GET', '/api/v1/products', 'guest'], ['GET', '/api/v1/categories', 'guest'], ['GET', '/api/v1/cart', 'guest'],
                ['POST', '/api/v1/cart/items', 'guest'], ['POST', '/api/v1/checkout', 'guest'], ['POST', '/api/v1/orders/track', 'guest'],
                ['POST', '/api/v1/orders/KM1/payment-options', 'guest'], ['POST', '/api/v1/orders/KM1/pay', 'guest'],
                ['GET', '/api/v1/shipping/zones', 'guest'], ['POST', '/checkout', 'customer'],
                ['GET', '/api/v1/admin/products', 'admin'], ['GET', '/api/v1/admin/orders', 'admin'],
                ['GET', '/api/v1/admin/categories', 'admin'], ['GET', '/api/v1/admin/shipping-zones', 'admin'],
            ]],
            'coupons' => ['shop.coupons', [['GET', '/api/v1/admin/coupons', 'admin']]],
            'membership' => ['membership', [
                ['GET', '/membership', 'guest'], ['GET', '/api/v1/membership/info', 'guest'], ['GET', '/api/v1/membership', 'customer'],
                ['POST', '/api/v1/membership/pay', 'customer'], ['POST', '/account/membership/pay', 'customer'],
                ['POST', '/api/v1/admin/members/1/membership', 'admin'],
            ]],
            'referral' => ['referral', [
                ['GET', '/referral-program', 'guest'], ['GET', '/api/v1/referral', 'customer'],
                ['GET', '/api/v1/referral/history', 'customer'], ['PUT', '/api/v1/referral/code', 'customer'],
                ['GET', '/account/referral-qr.svg', 'customer'], ['POST', '/account/referral-code', 'customer'],
                ['GET', '/api/v1/admin/referrals/commissions', 'admin'],
            ]],
            'top referrers' => ['top_referrers', [['GET', '/api/v1/referral/top', 'guest']]],
            'signals' => ['signals', [
                ['GET', '/signals', 'guest'], ['GET', '/signals/performance', 'guest'], ['GET', '/api/v1/signals/info', 'guest'],
                ['POST', '/api/v1/signals/subscribe', 'guest'], ['GET', '/api/v1/admin/signals', 'admin'],
                ['GET', '/api/v1/admin/signals/subscribers', 'admin'],
            ]],
            'performance page' => ['signals.performance_page', [['GET', '/signals/performance', 'guest'], ['GET', '/api/v1/signals/performance', 'guest']]],
            'wallet' => ['wallet', [
                ['GET', '/api/v1/wallet', 'customer'], ['POST', '/api/v1/wallet/cash-out', 'customer'], ['POST', '/account/cash-out', 'customer'],
                ['GET', '/api/v1/admin/cash-outs', 'admin'],
            ]],
            'cash out' => ['wallet.cashout', [['POST', '/api/v1/wallet/cash-out', 'customer'], ['POST', '/account/cash-out', 'customer']]],
        ];
    }

    /** @param list<array{0: string, 1: string, 2: string}> $routes */
    #[DataProvider('switches')]
    public function test_switching_off_hides_every_route(string $feature, array $routes): void
    {
        $customer = User::factory()->member()->create();
        $admin = User::factory()->superAdmin()->create();
        $admin->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();

        foreach ([true, false] as $on) {
            Features::set($feature, $on);
            foreach ($routes as [$method, $uri, $who]) {
                $this->app['auth']->forgetGuards();
                if ($who === 'customer') {
                    $this->actingAs($customer, str_starts_with($uri, '/api/') ? 'sanctum' : 'web');
                } elseif ($who === 'admin') {
                    $this->actingAs($admin, 'web');
                }
                $status = $this->withoutMiddleware(ValidateCsrfToken::class)
                    ->json($method, $uri)->status();
                if ($on) {
                    if (! $this->isAlwaysMissing($uri)) {
                        $this->assertNotSame(404, $status, "$method $uri is hidden while $feature is ON");
                    }
                } else {
                    $this->assertSame(404, $status, "$method $uri still answers ($status) while $feature is OFF");
                }
            }
        }
    }

    /** Some URIs point at records that don't exist in this test: a 404 then is fine when ON. */
    private function isAlwaysMissing(string $uri): bool
    {
        return (bool) preg_match('#/(KM1|ABCD|1)/|/r/ABCD$|/members/1/#', $uri);
    }

    public function test_membership_off_pauses_expiry_reminders_and_signals_removal(): void
    {
        $user = User::factory()->member()->create();
        $m = Membership::query()->where('user_id', $user->id)->firstOrFail();
        $m->update(['current_period_end' => now()->subDays(10), 'grace_ends_at' => now()->subDays(7)]);
        $sub = SignalSubscription::query()->create(['user_id' => $user->id, 'source' => SignalSubscription::MEMBERSHIP,
            'first_name' => 'A', 'status' => 'active', 'link_token' => Str::random(32)]);

        Features::set('membership', false);
        $this->assertSame(['grace' => 0, 'expired' => 0, 'reminders' => 0], app(MembershipService::class)->sweep());
        $this->assertSame(0, app(SignalAccess::class)->sweep());
        $this->assertSame('active', $m->fresh()->status);
        $this->assertSame('active', $sub->fresh()->status);

        // Back on: the overdue membership ends and the member leaves the channel.
        Features::set('membership', true);
        $this->assertSame(1, app(MembershipService::class)->sweep()['expired']);
        $this->assertSame('expired', $m->fresh()->status);
        $this->assertSame('ended', $sub->fresh()->status);
    }

    public function test_telegram_bot_off_means_nobody_is_marked_removed_without_being_removed(): void
    {
        $sub = SignalSubscription::query()->create(['source' => SignalSubscription::PURCHASE, 'first_name' => 'B', 'status' => 'active',
            'current_period_end' => now()->subDays(5), 'grace_ends_at' => now()->subDay(), 'link_token' => Str::random(32)]);
        Features::set('telegram_bot', false);
        $this->assertSame(0, app(SignalAccess::class)->sweep());
        $this->assertSame('active', $sub->fresh()->status);
        Features::set('telegram_bot', true);
        $this->assertSame(1, app(SignalAccess::class)->sweep());
    }

    public function test_loyalty_and_notification_switches_stop_their_jobs(): void
    {
        Features::set('loyalty_reward', false);
        $this->assertSame(0, app(MembershipService::class)->grantLoyaltyRewards());
    }
}
