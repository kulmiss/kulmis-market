<?php

namespace Tests\Feature\Referral;

use App\Jobs\SendCustomerMessage;
use App\Models\Cart;
use App\Models\Product;
use App\Models\User;
use App\Models\UserNotification;
use App\Notify\Inbox;
use App\Notify\NotifyPrefs;
use App\Referral\Attribution;
use App\Referral\Goals;
use App\Shop\CartReminders;
use App\Support\Features;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\URL;

/** The bell, what to hear about, monthly goals and the one cart reminder. */
class NotificationCentreTest extends ReferralTestCase
{
    public function test_earnings_team_joins_and_orders_land_in_the_bell(): void
    {
        [, , $l1] = $this->chain();
        $buyer = User::factory()->member()->create(['first_name' => 'Dawit']);
        app(Attribution::class)->attachAtSignup(Request::create('/register', 'POST', ['referral_code' => $l1->referral_code]), $buyer);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $l1->id, 'type' => 'team', 'title' => 'Dawit joined your team']);
        $buyer->refresh();

        $this->payMembership($buyer);
        $n = UserNotification::query()->where('user_id', $l1->id)->where('type', 'earning')->firstOrFail();
        $this->assertStringStartsWith('You earned', $n->title);
        $this->assertStringContainsString('Dawit', (string) $n->body); // level 1 sees the first name
        $up = UserNotification::query()->where('type', 'earning')->where('user_id', '!=', $l1->id)->firstOrFail();
        $this->assertStringContainsString('someone in your team', (string) $up->body); // levels 2–3 don't

        $order = $this->buyProducts(100_000, $buyer);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $buyer->id, 'type' => 'order', 'title' => "Order {$order->number}: paid, thank you"]);
    }

    public function test_same_event_is_shown_once_and_preferences_mute_a_type(): void
    {
        $u = $this->member();
        Inbox::add($u, 'earning', 'A', null, null, 'k1');
        Inbox::add($u, 'earning', 'A again', null, null, 'k1');
        $this->assertSame(1, UserNotification::query()->count());

        NotifyPrefs::set($u, ['bell.earning' => false, 'nonsense' => true]);
        Inbox::add($u->fresh(), 'earning', 'B', null, null, 'k2');
        $this->assertSame(1, UserNotification::query()->count());
        $this->assertArrayNotHasKey('nonsense', $u->fresh()->notify_prefs);

        Features::set('account.notifications', false);
        Inbox::add($u->fresh(), 'team', 'C');
        $this->assertSame(1, UserNotification::query()->count());
    }

    public function test_bell_page_counts_marks_read_and_saves_settings(): void
    {
        $u = $this->member();
        Inbox::add($u, 'team', 'Hanna joined your team', 'They signed up.', '/account/team', 't1');
        $this->actingAs($u)->get('/account')->assertSee('Notifications (1 new)', false);
        $this->get('/account/notifications')->assertOk()->assertSee('Hanna joined your team')->assertSee('New');
        $this->assertSame(0, Inbox::unread($u));
        $this->get('/account')->assertDontSee('1 new');

        $this->post('/account/notifications/settings', ['bell_team' => '0', 'bell_earning' => '1', 'cart_reminder' => '0'])->assertRedirect();
        $u->refresh();
        $this->assertFalse(NotifyPrefs::on($u, 'bell.team'));
        $this->assertTrue(NotifyPrefs::on($u, 'bell.badge')); // not in the form → unchanged
        $this->assertFalse(NotifyPrefs::on($u, 'cart_reminder'));

        // Someone else's notifications never show.
        $other = $this->member(null, 'Other');
        Inbox::add($other, 'team', 'Secret news');
        $this->get('/account/notifications')->assertDontSee('Secret news');
    }

    public function test_monthly_goal_progress_and_one_reached_message(): void
    {
        [, , $l1] = $this->chain();
        $this->actingAs($l1)->post('/account/goal', ['goal' => '50'])->assertSessionHasErrors('goal'); // under 100 ETB
        $this->post('/account/goal', ['goal' => '500'])->assertRedirect();
        $this->assertSame(50_000, $l1->fresh()->goal_amount);
        $this->get('/account')->assertSee('Goal:')->assertSee('to go this month');

        $this->payMembership($this->member($l1, 'Eden')); // level 1: 10% of 5,000 = 500 ETB
        $this->assertTrue(Goals::progress($l1->fresh())['reached']);
        $this->payMembership($this->member($l1, 'Fikir'));
        $this->assertSame(1, UserNotification::query()->where('user_id', $l1->id)->where('type', 'goal')->count());

        $this->post('/account/goal', ['goal' => ''])->assertRedirect();
        $this->assertNull($l1->fresh()->goal_amount);
        Features::set('account.goals', false);
        $this->post('/account/goal', ['goal' => '500'])->assertNotFound();
    }

    public function test_cart_reminder_once_signed_in_only_and_can_be_stopped(): void
    {
        $u = User::factory()->create(['first_name' => 'Selam']);
        $p = Product::factory()->create(['name' => 'Jebena']);
        $mine = Cart::query()->create(['token' => str_repeat('a', 40), 'user_id' => $u->id]);
        $mine->items()->create(['product_id' => $p->id, 'quantity' => 1]);
        $guest = Cart::query()->create(['token' => str_repeat('b', 40)]);
        $guest->items()->create(['product_id' => $p->id, 'quantity' => 1]);

        $this->assertSame(0, app(CartReminders::class)->run()); // not 24 hours yet
        $this->travel(25)->hours();
        $this->assertSame(1, app(CartReminders::class)->run());
        $this->assertSame(0, app(CartReminders::class)->run()); // only once
        Bus::assertDispatched(SendCustomerMessage::class, fn ($job) => $job->message->type === 'cart_reminder'
            && str_contains($job->message->body, 'Jebena') && str_contains($job->message->body, '/cart/resume/'));
        Bus::assertDispatchedTimes(SendCustomerMessage::class, 1);

        // The link opens that cart on any phone.
        $this->get(URL::temporarySignedRoute('cart.resume', now()->addDay(), ['cart' => $mine->id]))->assertRedirect('/cart')->assertCookie('km_cart');
        $this->get('/cart/resume/'.$mine->id)->assertForbidden(); // unsigned

        // "Stop these" works without signing in.
        $this->get(URL::signedRoute('cart-reminders.stop', ['user' => $u->id]))->assertOk()->assertSee('Cart reminders stopped');
        $this->assertFalse(NotifyPrefs::on($u->fresh(), 'cart_reminder'));
        $mine->update(['reminded_at' => null]);
        $mine->touch();
        $this->travel(25)->hours();
        $this->assertSame(0, app(CartReminders::class)->run());
    }

    public function test_signed_in_cart_gets_linked_to_the_person(): void
    {
        $u = User::factory()->create();
        $p = Product::factory()->create();
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');
        $this->assertNull(Cart::query()->where('token', $token)->value('user_id'));
        $this->actingAs($u)->withCookie('km_cart', $token)->get('/account')->assertOk();
        $this->assertSame($u->id, Cart::query()->where('token', $token)->value('user_id'));
    }
}
