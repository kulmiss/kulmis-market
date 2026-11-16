<?php

namespace Tests\Feature\Story;

use App\Demo\Story;
use App\Ledger\Wallet;
use App\Models\User;
use App\Support\TestClock;
use App\Support\Totp;
use Database\Seeders\ShopBasicsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** km:test-clock and km:story: only on your own computer, never on a live shop. */
class TestToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        @unlink(storage_path('framework/test-clock'));
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    public function test_the_test_clock_moves_the_shop_forward_on_a_local_computer_only(): void
    {
        $this->app['env'] = 'local';
        $real = time();
        $this->artisan('km:test-clock', ['by' => '+15days'])->assertSuccessful();
        $this->assertEqualsWithDelta($real + 15 * 86400, now()->getTimestamp(), 5);
        $this->assertTrue(TestClock::active());
        $this->artisan('km:test-clock', ['by' => '+29 days', '--no-jobs' => true])->assertSuccessful();
        $this->assertEqualsWithDelta($real + 44 * 86400, now()->getTimestamp(), 5);
        // The phone app's two-step codes keep using the real time.
        $secret = Totp::generateSecret();
        $this->assertNotNull(Totp::verify($secret, Totp::code($secret, intdiv(time(), 30))));
        $this->artisan('km:test-clock', ['by' => 'yesterday'])->assertFailed();
        $this->artisan('km:test-clock', ['--reset' => true])->assertSuccessful();
        $this->assertFalse(TestClock::active());
        $this->assertEqualsWithDelta($real, now()->getTimestamp(), 5);

        $this->app['env'] = 'production';
        $this->artisan('km:test-clock', ['by' => '+15days'])->assertFailed();
        file_put_contents(storage_path('framework/test-clock'), '999999');
        TestClock::apply();
        $this->assertEqualsWithDelta(time(), now()->getTimestamp(), 5, 'a live shop ignores the file');
    }

    public function test_km_story_makes_the_people_of_the_guide_once(): void
    {
        $this->app['env'] = 'local';
        $this->seed(ShopBasicsSeeder::class);
        $this->artisan('km:story', ['--team' => true])->assertSuccessful();
        $abdi = User::query()->where('email', Story::email('abdi'))->firstOrFail();
        $this->assertSame('ABDI', $abdi->referral_code);
        $this->assertTrue($abdi->isActiveMember());
        $this->assertSame(225_000, Wallet::for($abdi)->available());
        $ali = User::query()->where('email', Story::email('ali'))->firstOrFail();
        $this->assertSame(Story::email('omar'), $ali->referrer?->email);
        $this->assertTrue(Hash::check(Story::PASSWORD, $abdi->password));
        $this->artisan('km:story')->assertFailed(); // already there: start clean first

        $this->app['env'] = 'production';
        $this->artisan('km:story')->assertFailed();
    }
}
