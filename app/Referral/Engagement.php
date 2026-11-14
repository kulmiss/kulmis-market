<?php

namespace App\Referral;

use App\Membership\Members;
use App\Models\User;
use App\Support\Features;
use App\Support\Settings;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/** Badges, monthly top referrers, QR and share links. */
class Engagement
{
    public const BADGES = ['gold', 'silver', 'bronze'];

    /** Badge by number of ACTIVE level-1 members. */
    public function badgeFor(int $activeLevel1): ?string
    {
        if (Features::disabled('badges')) {
            return null;
        }
        foreach (self::BADGES as $badge) {
            if ($activeLevel1 >= Settings::int("badges.$badge")) {
                return $badge;
            }
        }

        return null;
    }

    public function activeLevel1(User $user): int
    {
        return User::query()->with('membership')->where('referrer_id', $user->id)->get()
            ->filter(fn (User $u) => Members::isActive($u))->count();
    }

    /** @return array{badge:?string, active_level1:int, next:?array{badge:string, needed:int}} */
    public function progress(User $user): array
    {
        $active = $this->activeLevel1($user);
        $badge = $this->badgeFor($active);
        $next = null;
        if (Features::enabled('badges')) {
            foreach (array_reverse(self::BADGES) as $b) {
                $need = Settings::int("badges.$b");
                if ($active < $need) {
                    $next = ['badge' => $b, 'needed' => $need - $active];
                    break;
                }
            }
        }

        return ['badge' => $badge, 'active_level1' => $active, 'next' => $next];
    }

    /**
     * Monthly ranking by new level-1 people who are active members.
     * Public: first name + badge only.
     *
     * @return list<array{rank:int, name:string, badge:?string, count:int}>
     */
    public function topReferrers(?Carbon $month = null, int $limit = 10): array
    {
        if (Features::disabled('top_referrers')) {
            return [];
        }
        $month ??= now();
        $key = 'top-referrers:'.$month->format('Y-m').':'.$limit;

        return Cache::remember($key, now()->addHour(), function () use ($month, $limit) {
            $start = $month->copy()->startOfMonth();
            $end = $month->copy()->endOfMonth();
            $counts = User::query()->with('membership')->whereNotNull('referrer_id')->whereBetween('referred_at', [$start, $end])->get()
                ->filter(fn (User $u) => Members::isActive($u))->countBy('referrer_id')->sortDesc()->take($limit * 2);

            $rows = [];
            foreach ($counts as $referrerId => $count) {
                $ref = User::query()->find($referrerId);
                if (! $ref || ! Members::isActive($ref) || ! $ref->isActiveAccount()) {
                    continue;
                }
                $rows[] = ['name' => $ref->first_name, 'badge' => $this->badgeFor($this->activeLevel1($ref)), 'count' => (int) $count];
                if (count($rows) === $limit) {
                    break;
                }
            }

            return array_values(array_map(fn ($r, $i) => ['rank' => $i + 1] + $r, $rows, array_keys($rows)));
        });
    }

    public function qrSvg(string $url): string
    {
        $options = new QROptions(['outputType' => 'svg', 'outputBase64' => false, 'eccLevel' => 1, 'addQuietzone' => true]);

        return (new QRCode($options))->render($url);
    }

    /** @return array<string, string> */
    public function shareLinks(string $url, string $code): array
    {
        $text = 'Join me on '.Settings::string('site.name').' — handmade in Ethiopia. Use my code '.$code.': ';

        return [
            'telegram' => 'https://t.me/share/url?url='.rawurlencode($url).'&text='.rawurlencode($text),
            'whatsapp' => 'https://wa.me/?text='.rawurlencode($text.$url),
        ];
    }
}
