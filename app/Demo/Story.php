<?php

namespace App\Demo;

use App\Badges\BadgeEngine;
use App\Membership\MembershipService;
use App\Models\Membership;
use App\Models\MembershipPurchase;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Payments\PaymentService;
use App\Referral\ReferralCodes;
use App\Support\TestClock;
use App\Wallet\PayoutAccounts;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The people of TESTING_GUIDE.md ("the Abdi story"), made fresh by
 * `php artisan km:story`. Day 0 = the moment it runs.
 *
 * - Owner: a Super Admin to approve receipts.
 * - Abdi: a member for two months already, membership paid until day 30
 *   (grace until day 33), code ABDI, a saved Telebirr account.
 * - Bekele, Chaltu and Dawit: invited by Abdi; each paid one membership on
 *   the 2nd day of last month. So Abdi already earned 3 × 500 ETB team
 *   earnings + 3 × 250 ETB first-payment bonus = 2,250 ETB (ready to use),
 *   had 3 active members at the end of last month (Bronze badge) and was paid
 *   for every day of last month (so last month's badge bonus can be paid).
 * - "Story basket": 2,000 ETB, stock 50, cost 800 ETB.
 *
 * Sara, Hana, Omar and Ali are NOT made: you sign them up by hand through
 * each other's links (that's the test). With $withTeam they are made too
 * (signed up, not paid), for a quick run.
 */
final class Story
{
    public const PASSWORD = 'kulmis story 2026';

    public const DOMAIN = 'story.test';

    /** Phones +251900 2… are demo numbers: no real SMS goes out. */
    public const PEOPLE = [
        'owner' => ['Owner', 'Kulmis', '+251900200000'],
        'abdi' => ['Abdi', 'Bekele', '+251900200001'],
        'bekele' => ['Bekele', 'Alemu', '+251900200011'],
        'chaltu' => ['Chaltu', 'Gemechu', '+251900200012'],
        'dawit' => ['Dawit', 'Haile', '+251900200013'],
        'sara' => ['Sara', 'Tesfaye', '+251900200002'],
        'hana' => ['Hana', 'Girma', '+251900200003'],
        'omar' => ['Omar', 'Yusuf', '+251900200004'],
        'ali' => ['Ali', 'Hassan', '+251900200005'],
    ];

    /** @var array<string, User> */
    public array $people = [];

    public function __construct(
        private readonly PaymentService $payments,
        private readonly MembershipService $memberships,
    ) {}

    public static function email(string $key): string
    {
        return $key.'@'.self::DOMAIN;
    }

    public static function exists(): bool
    {
        return User::query()->where('email', self::email('abdi'))->exists();
    }

    /** @return array<string, User> */
    public function build(bool $withTeam = false): array
    {
        if (self::exists()) {
            throw new RuntimeException('The story people are already there. Start clean first: php artisan migrate:fresh --seed --force');
        }
        $day0 = now()->startOfMinute();
        $lastMonth = $day0->copy()->startOfMonth()->subMonthNoOverflow();
        $hash = bcrypt(self::PASSWORD);
        $make = function (string $key, string $role = 'customer', ?User $ref = null, ?Carbon $at = null) use ($hash): User {
            [$first, $last, $phone] = self::PEOPLE[$key];
            $u = new User;
            $u->forceFill(['first_name' => $first, 'last_name' => $last, 'email' => self::email($key), 'phone' => $phone,
                'password' => $hash, 'role' => $role, 'email_verified_at' => now(), 'referrer_id' => $ref?->id, 'referred_at' => $ref ? ($at ?? now()) : null,
                'created_at' => $at ?? now()])->save();
            app(ReferralCodes::class)->assign($u);

            return $this->people[$key] = $u->fresh();
        };

        try {
            $owner = $make('owner', 'super_admin');
            $abdi = $make('abdi', 'customer', null, $day0->copy()->subDays(70));
            $abdi->forceFill(['referral_code' => 'ABDI'])->save();

            // Abdi: paid for every day from 3 days before last month until day 30.
            $this->pay($abdi, $lastMonth->copy()->subDays(3), $lastMonth->copy()->subDays(3), $day0, $owner);
            $this->pay($abdi, $day0, $day0, $day0->copy()->addDays(30), $owner);
            Membership::query()->where('user_id', $abdi->id)->update([
                'status' => 'active', 'current_period_end' => $day0->copy()->addDays(30),
                'grace_ends_at' => $day0->copy()->addDays(33), 'continuous_since' => $lastMonth->copy()->subDays(3),
            ]);

            // Abdi's first three: each paid once, on the 2nd day of last month (one month long).
            foreach (['bekele', 'chaltu', 'dawit'] as $i => $key) {
                $u = $make($key, 'customer', $abdi, $lastMonth->copy()->subDays(10));
                $paid = $lastMonth->copy()->addDay()->addMinutes($i);
                $this->pay($u, $paid, $paid, $paid->copy()->addDays(31), $owner);
            }
            // Their earnings are ready by now; Abdi's Bronze badge was unlocked at the end of last month.
            Carbon::setTestNow($lastMonth->copy()->endOfMonth()->subHour());
            app(BadgeEngine::class)->evaluate($abdi->fresh());
            Carbon::setTestNow($day0);
            Artisan::call('referrals:release');

            Carbon::setTestNow($day0->copy()->subDays(5));
            app(PayoutAccounts::class)->save($abdi->fresh(), ['method' => 'telebirr', 'account_name' => 'Abdi Bekele', 'account_number' => '0911000001'], self::PASSWORD);
            Carbon::setTestNow($day0);

            Product::query()->updateOrCreate(['slug' => 'story-basket'], ['name' => 'Story basket', 'price' => 200_000,
                'cost_materials' => 50_000, 'cost_labour' => 25_000, 'cost_packaging' => 5_000, 'stock' => 50, 'weight_grams' => 800,
                'status' => 'active', 'short_description' => 'The basket of the testing guide.', 'description' => 'A handmade basket used in the testing guide.']);

            if ($withTeam) {
                $prev = $abdi;
                foreach (['sara', 'hana', 'omar', 'ali'] as $key) {
                    $prev = $make($key, 'customer', $prev, $day0);
                }
            }
        } finally {
            Carbon::setTestNow(null);
            TestClock::apply();
        }

        return $this->people;
    }

    /** A membership month paid by receipt and approved by the owner, at $at, covering [$from, $to]. */
    private function pay(User $u, Carbon $at, Carbon $from, Carbon $to, User $owner): void
    {
        Carbon::setTestNow($at);
        $purchase = $this->memberships->newPurchase($u->fresh());
        $p = $this->payments->submitProof($purchase, Payment::TELEBIRR, UploadedFile::fake()->image('receipt.jpg', 40, 40), 'STORY', null);
        $this->payments->approveProof($p, $owner->id);
        // Exact story dates (a real month is 28–31 days).
        MembershipPurchase::query()->whereKey($purchase->id)->update(['period_start' => $from, 'period_end' => $to, 'paid_at' => $at]);
        DB::table('memberships')->where('user_id', $u->id)->update(['current_period_end' => $to, 'grace_ends_at' => $to->copy()->addDays(3)]);
    }
}
