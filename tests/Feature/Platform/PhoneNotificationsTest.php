<?php

namespace Tests\Feature\Platform;

use App\Jobs\SendWebPush;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notify\Inbox;
use App\Notify\NotifyPrefs;
use App\Notify\WebPush;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Member app (home-screen icon) and phone notifications without any message content. */
class PhoneNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private const FCM = 'https://fcm.googleapis.com/fcm/send/abc123';

    private function keys(): void
    {
        $k = WebPush::newKeys();
        config(['services.webpush.public_key' => $k['public'], 'services.webpush.private_key' => $k['private'], 'services.webpush.subject' => 'mailto:test@example.com']);
    }

    private static function rawToDer(string $raw): string
    {
        $int = function (string $b): string {
            $b = ltrim($b, "\x00");
            if ($b === '' || ord($b[0]) & 0x80) {
                $b = "\x00".$b;
            }

            return "\x02".chr(strlen($b)).$b;
        };
        $seq = $int(substr($raw, 0, 32)).$int(substr($raw, 32));

        return "\x30".chr(strlen($seq)).$seq;
    }

    public function test_vapid_token_is_a_valid_es256_signature(): void
    {
        $this->keys();
        $jwt = app(WebPush::class)->jwt('https://fcm.googleapis.com');
        [$h, $p, $s] = explode('.', $jwt);
        $claims = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
        $this->assertSame('https://fcm.googleapis.com', $claims['aud']);
        $this->assertGreaterThan(time(), $claims['exp']);
        $raw = base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4));
        $this->assertSame(64, strlen($raw));
        $priv = openssl_pkey_get_private(base64_decode((string) config('services.webpush.private_key')));
        $pub = openssl_pkey_get_details($priv)['key'];
        $this->assertSame(1, openssl_verify("$h.$p", self::rawToDer($raw), $pub, OPENSSL_ALGO_SHA256));
        $this->assertSame(87, strlen(WebPush::publicKey())); // 65 bytes, base64url
    }

    public function test_only_real_push_services_are_called(): void
    {
        foreach ([self::FCM, 'https://updates.push.services.mozilla.com/wpush/v2/x', 'https://web.push.apple.com/abc', 'https://wns2-par02p.notify.windows.com/w/?token=x'] as $ok) {
            $this->assertTrue(WebPush::allowedEndpoint($ok), $ok);
        }
        foreach (['http://fcm.googleapis.com/x', 'https://fcm.googleapis.com.evil.com/x', 'https://localhost/x', 'https://169.254.169.254/latest',
            'https://user@fcm.googleapis.com/x', 'https://fcm.googleapis.com:8443/x', 'https://evil.com/fcm.googleapis.com'] as $bad) {
            $this->assertFalse(WebPush::allowedEndpoint($bad), $bad);
        }
    }

    public function test_send_has_no_body_and_gone_phones_are_removed(): void
    {
        $this->keys();
        $u = User::factory()->member()->create();
        $sub = PushSubscription::query()->create(['user_id' => $u->id, 'endpoint_hash' => hash('sha256', self::FCM), 'endpoint' => self::FCM]);
        Http::fake([self::FCM => Http::sequence()->push('', 201)->push('', 410)]);

        (new SendWebPush($u->id))->handle(app(WebPush::class));
        Http::assertSent(fn ($r) => $r->url() === self::FCM && $r->body() === '' && str_starts_with($r->header('Authorization')[0], 'vapid t=')
            && $r->header('TTL')[0] === '86400');
        $this->assertNotNull($sub->fresh()->last_used_at);

        (new SendWebPush($u->id))->handle(app(WebPush::class));
        $this->assertNull($sub->fresh()); // 410 Gone → forgotten
    }

    public function test_member_turns_it_on_and_off_and_news_triggers_one_push(): void
    {
        $this->keys();
        Bus::fake([SendWebPush::class]);
        $u = User::factory()->member()->create();
        $this->actingAs($u)->get('/account/notifications')->assertSee('km-push-key', false)->assertSee('Phone notifications');

        $this->postJson('/account/push', ['endpoint' => 'https://evil.example/collect'])->assertJsonValidationErrors('endpoint');
        $this->postJson('/account/push', ['endpoint' => self::FCM, 'device' => '<b>Android · Chrome</b>', 'kinds' => ['earning', 'order']])->assertCreated();
        $this->assertSame('Android · Chrome', PushSubscription::query()->value('device'));
        $u->refresh();
        $this->assertTrue(NotifyPrefs::on($u, 'push.earning'));
        $this->assertFalse(NotifyPrefs::on($u, 'push.cashout'));

        Inbox::add($u, 'earning', 'You earned 500 ETB', null, null, 'c1');
        Inbox::add($u, 'cashout', 'Cash-out sent: 900 ETB', null, null, 'p1');
        Bus::assertDispatchedTimes(SendWebPush::class, 1);

        // The phone asks what to show: safe words only.
        $this->getJson('/account/notifications/latest')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.title', 'Cash-out update')->assertDontSee('900');

        $this->deleteJson('/account/push', ['endpoint' => self::FCM])->assertOk();
        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_other_people_and_switches(): void
    {
        $this->keys();
        $u = User::factory()->member()->create();
        $other = User::factory()->member()->create();
        PushSubscription::query()->create(['user_id' => $other->id, 'endpoint_hash' => 'h', 'endpoint' => self::FCM]);
        $this->actingAs($u)->deleteJson('/account/push')->assertOk();
        $this->assertSame(1, PushSubscription::query()->count()); // never someone else's

        Features::set('account.push', false);
        $this->postJson('/account/push', ['endpoint' => self::FCM])->assertNotFound();
        $this->get('/account/notifications')->assertDontSee('km-push-key', false);

        config(['services.webpush.public_key' => null]);
        Features::set('account.push', true);
        $this->postJson('/account/push', ['endpoint' => self::FCM])->assertNotFound(); // not set up yet

        $this->get('/account')->assertSee('account.webmanifest', false);
        Features::set('account.app', false);
        $this->get('/account')->assertSee('site.webmanifest', false);
        $this->assertStringContainsString('"start_url": "/account?source=app"', (string) file_get_contents(public_path('account.webmanifest')));
    }
}
