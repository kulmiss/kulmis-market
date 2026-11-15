<?php

namespace App\Referral;

use App\Models\Contest;
use App\Models\User;
use App\Rewards\Challenges;
use App\Rewards\Contests;
use App\Support\Features;
use App\Support\Money;
use App\Support\Settings;

/** "How can I earn more?" — up to three easy tips with a one-tap message. */
final class Tips
{
    /**
     * @param  array<string, mixed>  $ref  TeamView::for()
     * @return list<array{text:string, share?:array{whatsapp:string, telegram:string, facebook:string}, link?:string, link_text?:string}>
     */
    public static function for(User $u, array $ref): array
    {
        if (Features::disabled('referral') || Features::disabled('referral.tips') || ! $u->referral_code) {
            return [];
        }
        $site = Settings::string('site.name');
        $tips = [];
        $team = $ref['team'];
        $waiting = $team['level1_count'] - $team['level1_active'];

        if ($team['level1_count'] === 0) {
            $tips[] = ['text' => 'Start small: send your link to two family or friends groups today.',
                'share' => ShareLinks::buttons(ShareLinks::for($u), "I shop at $site — handmade in Ethiopia. Have a look, and use my code {$u->referral_code}:")];
        } elseif ($waiting > 0) {
            $tips[] = ['text' => "$waiting ".($waiting === 1 ? 'person' : 'people').' in your team signed up but did not pay yet. A friendly reminder helps.',
                'share' => ShareLinks::buttons(ShareLinks::for($u, 'membership'), "Hi! Did you see the members' prices at $site? Membership starts here:")];
        }

        foreach (app(Challenges::class)->progress($u) as $row) {
            $left = $row['challenge']->target - $row['count'];
            if ($left > 0 && $left <= 2) {
                $tips[] = ['text' => "Only $left more new paying ".($left === 1 ? 'member' : 'members')." this month for “{$row['challenge']->name}” (".Money::format($row['challenge']->reward).').',
                    'link' => '/account/contests', 'link_text' => 'See challenges'];
                break;
            }
        }

        if (Features::enabled('referral.contests')) {
            $c = Contest::query()->where('status', 'active')->where('starts_at', '<=', now())->where('ends_at', '>', now())->orderBy('ends_at')->first();
            if ($c) {
                $mine = app(Contests::class)->mine($c, $u);
                $tips[] = ['text' => "Contest “{$c->name}” ends ".$c->ends_at->diffForHumans().($mine['place'] ? ". You are place {$mine['place']}." : '. You are not on the board yet: one new member gets you on it.'),
                    'link' => '/account/contests', 'link_text' => 'See the board'];
            }
        }

        if (Features::enabled('shop') && Features::enabled('referral.product_links')) {
            $tips[] = ['text' => 'Share a product you like with its own link: you earn on every sale.', 'link' => '/shop', 'link_text' => 'Pick a product'];
        }

        if (Features::disabled('referral.share_buttons')) {
            // Share buttons switched off: point to the link on the home page instead.
            $tips = array_map(fn ($t) => isset($t['share']) ? ['text' => $t['text'], 'link' => '/account#referral', 'link_text' => 'Copy your link'] : $t, $tips);
        }

        return array_slice($tips, 0, 3);
    }
}
