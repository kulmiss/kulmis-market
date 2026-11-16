<?php

namespace Tests\Feature\Platform;

use App\Http\Middleware\ThrottlePerRoute;
use App\Membership\Benefits;
use App\Models\User;
use App\Notify\Message;
use App\Support\Features;
use App\Support\FeatureUrls;
use App\Support\Settings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Feature\Platform\Concerns\BuildsEveryFeature;
use Tests\Feature\Referral\ReferralTestCase;

/**
 * "Switched off" must look like "never existed". For every feature that has
 * words in config/feature_words.php:
 *   1. switch it off (alone) and crawl the shop as a guest, a member with a
 *      team, a new member, an expired member and a shopper: every page reached
 *      by links, plus the sitemap, robots, app manifests and the offline page;
 *   2. no page, API answer or message template may contain its words (or those
 *      of the switches under it), and no link may point to its pages;
 *   3. its pages and API endpoints answer the normal 404 page;
 *   4. switch it back on: its words are back where they were.
 */
class FeatureNeverExistedTest extends ReferralTestCase
{
    use BuildsEveryFeature;

    /** Public API answers checked for every switch: [who, path]. */
    private const API = [
        ['guest', '/api/v1/products'], ['guest', '/api/v1/categories'], ['guest', '/api/v1/membership/info'], ['guest', '/api/v1/signals/info'],
        ['guest', '/api/v1/signals/performance'], ['guest', '/api/v1/referral/top'], ['guest', '/api/v1/shipping/zones'], ['guest', '/api/v1/cart'],
        ['member', '/api/v1/me'], ['member', '/api/v1/membership'], ['member', '/api/v1/referral'], ['member', '/api/v1/referral/history'],
        ['member', '/api/v1/wallet'], ['member', '/api/v1/badges'], ['member', '/api/v1/notifications'], ['member', '/api/v1/me/orders'],
        ['member', '/api/v1/signals/member-access'],
    ];

    /** Files that are always checked. */
    private const FILES = ['/sitemap.xml', '/robots.txt', '/account.webmanifest', '/site.webmanifest', '/offline.html'];

    /** @var list<string> */
    private array $report = [];

    public function test_every_switch_works_like_the_feature_never_existed(): void
    {
        $this->withoutMiddleware([ThrottleRequests::class, ThrottlePerRoute::class]); // hundreds of pages from one "phone"
        $this->allOn();
        $this->fixture();
        $base = $this->crawlAll();
        $only = getenv('ONLY');
        $unseen = [];
        foreach ($this->families() as $feature => $family) {
            if ($only && $only !== $feature) {
                continue;
            }
            $shown = $this->pagesWithWords($family, $base);
            if ($shown === []) {
                $unseen[] = $feature;
            }
            Features::set($feature, false);
            $this->check($feature, $family, $this->crawlAll());
            Features::set($feature, true);
            $this->backOn($feature, $family, $shown);
        }
        if ($file = getenv('REPORT')) {
            file_put_contents($file, implode("\n", array_unique($this->report))."\n");
        }
        $this->assertSame([], array_values(array_unique($this->report)), "A switched-off feature still shows (or did not come back):\n".implode("\n", array_slice(array_unique($this->report), 0, 40)));
        // Every feature must really be on some page when on, or the test above proves nothing for it.
        $this->assertSame(self::NOT_ON_PAGES, $only ? array_values(array_intersect(self::NOT_ON_PAGES, [$only])) : $unseen, 'Features whose words appear on no page when on (add them to the fixture, or to NOT_ON_PAGES with a reason)');
    }

    /**
     * Features whose words are not on any page of the fixture shop even when on
     * (each is still checked when off: its words must not appear anywhere).
     */
    private const NOT_ON_PAGES = [];

    /**
     * @param  list<string>  $family
     * @param  array<string, array{0:int, 1:string}>  $pages
     * @return array<string, string> "who path" => a word of the family that the page shows
     */
    private function pagesWithWords(array $family, array $pages): array
    {
        $out = [];
        foreach ($pages as $key => [$status, $body]) {
            if ($status !== 200 || isset($out[$key])) {
                continue;
            }
            $text = $this->visible($body);
            foreach ($family as $f) {
                foreach (config('feature_words')[$f] as $w) {
                    if ($this->has($text, $w)) {
                        $out[$key] = $w;

                        continue 3;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Switched back on, the feature's words are back on the pages that had them.
     *
     * @param  list<string>  $family
     * @param  array<string, string>  $shown
     */
    private function backOn(string $feature, array $family, array $shown): void
    {
        foreach ($shown as $key => $word) {
            [$who, $path] = str_starts_with($key, 'api ') ? [explode(' ', $key)[1], explode(' ', $key, 3)[2]] : $this->splitKey($key);
            [$status, $body] = $this->fetch($who, $path, $this->users()[$who] ?? null);
            if ($status !== 200 || ! $this->has($this->visible($body), $word)) {
                $this->report[] = "BACK ON [$feature] '$word' did not come back on $key (status $status)";
            }
        }
    }

    /** @return array{0:string, 1:string} */
    private function splitKey(string $key): array
    {
        $at = strpos($key, ' /');

        return [substr($key, 0, (int) $at), substr($key, (int) $at + 1)];
    }

    private function has(string $text, string $word): bool
    {
        return (bool) preg_match('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/u', $text);
    }

    /** @return array<string, ?User> */
    private function users(): array
    {
        return ['guest' => null, 'member' => $this->member, 'new member' => $this->newMember, 'expired' => $this->expired, 'shopper' => $this->shopper];
    }

    /** @return array<string, list<string>> feature => itself and the switches under it that have words */
    private function families(): array
    {
        $out = [];
        foreach (array_keys(config('feature_words')) as $f) {
            $out[$f] = array_values(array_filter(array_keys(config('feature_words')), fn ($k) => $this->isUnder($k, $f)));
        }

        return $out;
    }

    private function isUnder(string $key, string $parent): bool
    {
        for ($k = $key; $k !== null; $k = config('features')[$k][3] ?? null) {
            if ($k === $parent) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $family
     * @param  array<string, array{0:int, 1:string}>  $pages  "who path" => [status, body]
     */
    private function check(string $feature, array $family, array $pages): void
    {
        $pages['guest /no-such-page'] = $this->fetch('guest', '/no-such-page'); // the 404 page is a page too
        foreach ($pages as $key => [$status, $body]) {
            if ($status !== 200 && $status !== 404) {
                continue;
            }
            $text = $this->visible($body);
            foreach ($family as $f) {
                foreach (config('feature_words')[$f] as $w) {
                    if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($w, '/').'(?![\p{L}\p{N}])/u', $text, $m, PREG_OFFSET_CAPTURE)) { // same as has(), with the place
                        $ctx = preg_replace('/\s+/', ' ', mb_strcut($text, max(0, $m[0][1] - 70), 140));
                        $this->report[] = "WORD [$feature] '$w' on $key :: $ctx";
                        break;
                    }
                }
            }
            if (preg_match_all('/href="([^"]+)"/', $body, $hrefs)) {
                foreach (array_unique($hrefs[1]) as $href) {
                    if ($this->ours($href) && ! FeatureUrls::available($href)) {
                        $this->report[] = "LINK [$feature] to $href on $key";
                    }
                }
            }
        }
        // The feature's own pages and API: the normal 404 page.
        $urls = array_merge(array_keys(FeatureUrls::PAGES), array_map(fn ($a) => $a[1], self::API));
        foreach (array_unique($urls) as $url) {
            if (FeatureUrls::available($url) || in_array($url, ['/r', '/gift', '/c', '/pay', '/downloads', '/account/cash-out'], true)) {
                continue;
            }
            foreach (['guest', 'member'] as $who) {
                if ($who === 'member' && $url === '/register') {
                    continue; // signed-in people are sent home from the sign-up page anyway
                }
                $res = $this->fetch($who, $url);
                if ($res[0] !== 404 && ! ($res[0] === 302 && $who === 'guest')) {
                    $this->report[] = "STATUS [$feature] $who $url answered {$res[0]} (want 404)";
                } elseif ($res[0] === 404 && ! str_starts_with($url, '/api/') && ! str_contains($res[1], "can't find that page")) {
                    $this->report[] = "STATUS [$feature] $who $url is 404 but not the normal 404 page";
                }
            }
        }
        // Message templates (a message of a switched-off kind is not sent at all).
        foreach (array_keys(config('settings')) as $key) {
            if (! str_starts_with($key, 'notify.')) {
                continue;
            }
            $type = substr($key, 7);
            $needs = Message::featureOf($type);
            if ((Features::exists("messages.$type") && Features::disabled("messages.$type")) || ($needs && Features::disabled($needs))) {
                continue;
            }
            $text = mb_strtolower(strtr(Settings::template($key), ['{benefits}' => Benefits::sentence()]));
            foreach ($family as $f) {
                foreach (config('feature_words')[$f] as $w) {
                    if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($w, '/').'(?![\p{L}\p{N}])/u', $text)) {
                        $this->report[] = "MESSAGE [$feature] '$w' in $key";
                        break;
                    }
                }
            }
        }
    }

    /**
     * Lower-case text that a person or a search engine sees: page text,
     * structured data, and the attributes that are read out or shown
     * (meta content, alt, title, aria-label, placeholder). Class names and
     * other markup are not text. For JSON: only the values (the field names
     * stay the same so the phone app keeps working).
     */
    private function visible(string $body): string
    {
        $json = json_decode($body, true);
        if (is_array($json)) {
            $values = [];
            array_walk_recursive($json, function ($v) use (&$values) {
                if (is_string($v) && ! preg_match('/^[a-z0-9]+(?:[._:-][a-z0-9]+)+$/', $v)) {
                    $values[] = $v; // codes like "commission.released" are for apps, not people
                }
            });

            return mb_strtolower(implode(' | ', $values));
        }
        $html = (string) preg_replace('#<script(?![^>]*application/ld\+json)[^>]*>.*?</script>#si', ' ', $body);
        $html = (string) preg_replace('#<style[^>]*>.*?</style>#si', ' ', $html);
        $html = (string) preg_replace('#<svg[^>]*>.*?</svg>#si', ' ', $html);
        preg_match_all('/\s(?:content|alt|title|aria-label|placeholder)="([^"]*)"/i', $html, $attrs);
        $text = (string) preg_replace('/<[^>]*>/', ' ', $html);

        return mb_strtolower(html_entity_decode(implode(' | ', $attrs[1]).' | '.$text, ENT_QUOTES | ENT_HTML5));
    }

    private function ours(string $href): bool
    {
        if (str_starts_with($href, '/')) {
            return ! str_starts_with($href, '//');
        }
        $host = parse_url($href, PHP_URL_HOST);

        return is_string($host) && in_array($host, ['localhost', parse_url((string) config('app.url'), PHP_URL_HOST)], true);
    }

    /** @return array<string, array{0:int, 1:string}> "who path" => [status, body] */
    private function crawlAll(): array
    {
        $all = [];
        foreach ($this->users() as $who => $user) {
            $queue = $user ? ['/account', '/'] : array_merge(['/'], self::FILES);
            $seen = [];
            while ($queue !== [] && count($seen) < 120) {
                $path = array_shift($queue);
                if (isset($seen[$path])) {
                    continue;
                }
                $seen[$path] = true;
                [$status, $body] = $this->fetch($who, $path, $user);
                $all["$who $path"] = [$status, $body];
                if ($status !== 200) {
                    continue;
                }
                preg_match_all('/(?:href="|<loc>)([^"<]+)/', $body, $m);
                foreach ($m[1] as $href) {
                    $p = $this->crawlable($href);
                    if ($p !== null && ! isset($seen[$p])) {
                        $queue[] = $p;
                    }
                }
            }
        }
        foreach (self::API as [$who, $path]) {
            $all["api $who $path"] = $this->fetch($who, $path);
        }

        return $all;
    }

    private function crawlable(string $href): ?string
    {
        if (! $this->ours($href)) {
            return null;
        }
        $path = (string) parse_url($href, PHP_URL_PATH);
        if ($path === '' || parse_url($href, PHP_URL_QUERY) !== null) {
            return null;
        }
        foreach (['/admin', '/logout', '/build', '/storage', '/downloads', '/r/', '/gift/', '/api', '/cart-reminders', '/pay/', '/account/referral-qr'] as $skip) {
            if (str_starts_with($path, $skip)) {
                return null;
            }
        }

        return preg_match('/\.(png|jpe?g|webp|avif|svg|ico|pdf|css|js)$/', $path) ? null : $path;
    }

    /** @return array{0:int, 1:string} */
    private function fetch(string $who, string $path, ?User $user = null): array
    {
        $this->app['auth']->forgetGuards();
        $user ??= $who === 'member' ? $this->member : null;
        if ($user && str_starts_with($path, '/api/')) {
            Sanctum::actingAs($user);
        } elseif ($user) {
            $this->actingAs($user);
        }
        $req = $this->cart && in_array($path, ['/cart', '/checkout'], true) ? $this->withCookie('km_cart', $this->cart) : $this;
        $sep = str_contains($path, '?') ? '&' : '?';
        $res = str_starts_with($path, '/api/') ? $req->getJson($path) : $req->get(in_array($path, self::FILES, true) ? $path : $path.$sep.'nocache='.mt_rand());
        $body = $res->baseResponse instanceof BinaryFileResponse
            ? (string) file_get_contents($res->baseResponse->getFile()->getPathname()) : (string) $res->getContent();
        $this->app['auth']->forgetGuards();

        return [$res->getStatusCode(), $body];
    }
}
