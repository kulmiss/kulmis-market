<?php

namespace Tests\Feature\Signals;

use App\Jobs\NotifyNewSignal;
use App\Jobs\SendCustomerMessage;
use App\Models\ContactMessage;
use App\Models\Faq;
use App\Models\Signal;
use App\Models\User;
use App\Models\UserNotification;
use App\Notify\NotifyPrefs;
use App\Signals\SignalStats;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Signal results chart + pair filter + signal notes; help centre with FAQ and contact form. */
class SignalResultsHelpTest extends TestCase
{
    use RefreshDatabase;

    private function closed(string $pair, string $result, string $when = 'now'): Signal
    {
        return Signal::query()->create(['pair' => $pair, 'direction' => 'long', 'entry' => '1', 'targets' => ['2'], 'stop_loss' => '0',
            'status' => 'closed', 'result' => $result, 'closed_at' => now()->modify($when), 'locked_at' => now(), 'posted_at' => now()->modify($when)]);
    }

    public function test_win_rate_by_month_and_pair_filter(): void
    {
        $this->closed('XAU/USD', 'win');
        $this->closed('XAU/USD', 'loss');
        $this->closed('EUR/USD', 'win');
        $this->closed('EUR/USD', 'win', '-1 month');
        $this->closed('EUR/USD', 'breakeven');
        Cache::forget('signals.pairs');
        $stats = app(SignalStats::class);

        $months = $stats->monthly();
        $this->assertCount(12, $months);
        $this->assertSame(67, end($months)['rate']);              // 2 wins / 3 (breakeven not counted)
        $this->assertSame(100, $months[10]['rate']);
        $this->assertSame(50, $stats->monthly('XAU/USD')[11]['rate']);
        $this->assertSame(['EUR/USD', 'XAU/USD'], $stats->pairs());
        $this->assertNull($stats->validPair("x' OR 1=1"));

        $this->get('/signals/performance?pair='.urlencode('XAU/USD'))->assertOk()->assertSee('Signal results: XAU/USD')
            ->assertSee('Win rate by month')->assertSee('aria-current="true"', false);
        $this->get('/signals/performance?pair=nope')->assertOk()->assertSee('All pairs');
    }

    public function test_member_signal_notes_only_for_chosen_pairs(): void
    {
        $this->closed('XAU/USD', 'win');
        $this->closed('EUR/USD', 'win');
        Cache::forget('signals.pairs');
        $member = User::factory()->member()->create();
        $other = User::factory()->member()->create();
        $this->actingAs($member)->get('/account/signals')->assertOk()->assertSee('Signal notes')->assertSee('Win rate by month');
        $this->post('/account/signals/alerts', ['on' => '1', 'pairs' => ['XAU/USD', 'MADE/UP']])->assertRedirect();
        $this->assertSame(['XAU/USD'], NotifyPrefs::pairs($member->fresh()));
        NotifyPrefs::set($other, ['bell.signal' => false]);

        $gold = Signal::query()->create(['pair' => 'XAU/USD', 'direction' => 'short', 'entry' => '1', 'targets' => ['2'], 'stop_loss' => '3']);
        $euro = Signal::query()->create(['pair' => 'EUR/USD', 'direction' => 'long', 'entry' => '1', 'targets' => ['2'], 'stop_loss' => '3']);
        (new NotifyNewSignal($gold->id))->handle();
        (new NotifyNewSignal($euro->id))->handle();
        (new NotifyNewSignal($gold->id))->handle(); // a retry never doubles it
        $this->assertSame(1, UserNotification::query()->where('user_id', $member->id)->where('type', 'signal')->count());
        $this->assertSame(0, UserNotification::query()->where('user_id', $other->id)->count());
        $this->assertSame('New signal: XAU/USD SHORT', UserNotification::query()->value('title'));
    }

    public function test_help_page_search_and_switch(): void
    {
        $this->get('/help')->assertOk()->assertSee('How do I pay?')->assertSee('data-contact-form', false)->assertSee('wa.me/', false);
        Faq::query()->create(['category' => 'Orders', 'question' => 'Hidden one', 'answer' => 'x', 'is_published' => false]);
        $this->get('/help')->assertDontSee('Hidden one');
        $this->get('/help?q=cash-out')->assertSee('How do I cash out?')->assertDontSee('How do I pay?');
        $this->get('/')->assertSee('Help and contact');
        Features::set('site.help_centre', false);
        $this->get('/help')->assertNotFound();
        $this->postJson('/api/v1/contact', ['name' => 'A', 'contact' => '0911000000', 'message' => 'Hello there team'])->assertNotFound();
    }

    public function test_contact_form_stores_tells_support_and_blocks_spam(): void
    {
        Bus::fake([SendCustomerMessage::class]);
        $ok = ['name' => '<b>Abebe</b>', 'contact' => '0911223344', 'topic' => 'order', 'message' => 'Where is my order KM12345? 🙏 ሰላም'];
        $this->postJson('/api/v1/contact', $ok)->assertCreated()->assertJsonPath('data.message', fn ($m) => str_contains($m, 'Thank you'));
        $m = ContactMessage::query()->firstOrFail();
        $this->assertSame('Abebe', $m->name);
        $this->assertSame('+251911223344', $m->contact);
        Bus::assertDispatched(SendCustomerMessage::class, fn ($j) => str_contains($j->message->subject, 'Help centre'));

        $this->postJson('/api/v1/contact', $ok + ['website' => 'http://spam'])->assertJsonValidationErrors('website');
        $this->postJson('/api/v1/contact', ['contact' => 'not a phone'] + $ok)->assertJsonValidationErrors('contact');
        $this->postJson('/api/v1/contact', ['message' => 'hi'] + $ok)->assertJsonValidationErrors('message');
        foreach (range(1, 3) as $_) {
            $this->postJson('/api/v1/contact', $ok);
        }
        $this->postJson('/api/v1/contact', $ok)->assertStatus(429); // 5 per 10 minutes
    }

    public function test_admin_manages_questions_and_messages(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/v1/admin/help/faqs', ['category' => 'Orders', 'question' => 'Can I pick it up?', 'answer' => 'Yes, in Addis.'])
            ->assertCreated()->json('data.id');
        $this->putJson("/api/v1/admin/help/faqs/$id", ['question' => 'Can I pick it up myself?', 'answer' => 'Yes.', 'is_published' => false])->assertOk();
        $this->assertFalse(Faq::query()->findOrFail($id)->is_published);
        $this->deleteJson("/api/v1/admin/help/faqs/$id")->assertOk();

        $msg = ContactMessage::query()->create(['name' => 'A', 'contact' => 'a@example.com', 'message' => 'Hello hello']);
        $this->getJson('/api/v1/admin/help/messages')->assertJsonCount(1, 'data');
        $this->putJson("/api/v1/admin/help/messages/{$msg->id}", ['status' => 'answered'])->assertOk();
        $this->getJson('/api/v1/admin/help/messages')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/help/messages?status=all')->assertJsonCount(1, 'data');

        $this->actingAsAdmin('support');
        $this->getJson('/api/v1/admin/help/messages')->assertOk(); // support staff answer messages
        $this->postJson('/api/v1/admin/help/faqs', ['question' => 'x', 'answer' => 'y'])->assertForbidden();
    }
}
