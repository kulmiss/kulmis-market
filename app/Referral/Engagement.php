<?php

namespace App\Referral;

use App\Badges\BadgeEngine;
use App\Membership\Members;
use App\Models\User;
use App\Support\Features;
use App\Support\Settings;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/** Monthly top referrers, QR and share links. Badges: App\\Badges\\BadgeEngine. */
class Engagement
{
    public function activeLevel1(User $user): int
    {
        return User::query()->with('membership')->where('referrer_id', $user->id)->get()
            ->filter(fn (User $u) => Members::isActive($u))->count();
    }

    /**
     * Monthly ranking by new level-1 people who are active members.
     * Public: first name + highest badge only.
     *
     * @return list<array{rank:int, name:string, badge:?array{name:string, icon:string, color:string}, count:int}>
     */
    public function topReferrers(?Carbon $month = null, int $limit = 10): array
    {
        if (Features::disabled('top_referrers')) {
            return [];
        }
        $month ??= now();
        $key = 'top-referrers:'.$month->format('Y-m').':'.$limit;

        return Cache::remember($key, now()->addHour(), function () use ($month, $limit) {
            $badges = app(BadgeEngine::class);
            $out = [];
            foreach ($this->ranking($month, $limit) as $i => $row) {
                $b = $badges->highest($row['user']);
                $out[] = ['rank' => $i + 1, 'name' => $row['user']->first_name,
                    'badge' => $b ? ['name' => $b->name, 'icon' => $b->icon, 'color' => $b->color] : null, 'count' => $row['count']];
            }

            return $out;
        });
    }

    /**
     * Who brought the most new active level-1 members in $month (active members only).
     *
     * @return list<array{user: User, count: int}>
     */
    public function ranking(Carbon $month, int $limit = 10): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $counts = User::query()->with('membership')->whereNotNull('referrer_id')->whereBetween('referred_at', [$start, $end])->get()
            ->filter(fn (User $u) => Members::isActive($u))->countBy('referrer_id')->sortDesc()->take($limit * 2);

        $rows = [];
        foreach ($counts as $referrerId => $count) {
            $ref = User::query()->with('membership')->find($referrerId);
            if (! $ref || ! Members::isActive($ref) || ! $ref->isActiveAccount()) {
                continue;
            }
            $rows[] = ['user' => $ref, 'count' => (int) $count];
            if (count($rows) === $limit) {
                break;
            }
        }

        return $rows;
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
