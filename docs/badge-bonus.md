# Monthly badge bonus

Members with a **team badge** get a bonus every month. Turn it on/off in
**Feature switches → Badges → Badge bonuses**. Amounts are on the
**Badges** page; limits in **Settings → Badge bonus**; everything else on
the **Badge bonus** page (Super Admin).

## The table (defaults, all editable)

| Badge | Active level-1 members | Bonus every month |
|-------|-----------------------:|------------------:|
| Bronze | 3 | 200 ETB |
| Silver | 10 | 600 ETB |
| Gold | 25 | 1,500 ETB |
| Platinum | 50 | 3,000 ETB |
| Diamond | 100 | 6,000 ETB |

- Only the **highest** badge pays (a Silver member gets 600, not 600 + 200).
- Every other badge has a **one-time bonus** field (default 0). It is paid
  with the monthly run for the month the badge was unlocked.
- The whole-team badges (25 / 100 / 250 in all 3 levels) are "other" badges.

## Who gets it

Checked on the **1st** for the month that just ended:

1. The member's membership was **paid for every day** of the month. Grace
   days (paying late) or a refunded month = no bonus that month. A month
   counts from the moment it was paid.
2. **Active level-1 members** are counted at the **last second of the
   month**: only people whose membership was paid at that moment. Grace,
   expired, refunded or free months given by staff don't count.
3. Badges can go down: the badge is worked out again each month.
4. Suspended accounts get nothing.

## Safety limits (you are always in profit)

1. **Per member:** the monthly bonus is never more than **2%** of the
   membership money that member's own level-1 people really paid that month
   (after refunds). With the default table this never cuts anyone who has
   real paying members (Bronze needs 3 × 5,000 = 15,000 ETB → limit 300 ETB).
2. **The shop:** all bonuses together are never more than **5%** of the
   month's membership money. Over it, everyone is cut by the same share
   and sees **"Bonus adjusted this month"**.
3. **Settings guard:** the Super Admin can't save commission, bonus,
   loyalty or price numbers that could pay out more than **35%** of a
   membership. The page shows "You keep at least … of every …".
4. Paid only for a **finished** month, from money really paid.

### The maths (defaults)

| Out of every 5,000 ETB membership | Worst case |
|---|---:|
| Team earnings (10% + 5% + 2%) | 850 ETB (17%) |
| Badge bonus: highest of bonus ÷ (members × price) = 200 ÷ 15,000 (Bronze) | 67 ETB (1.34%) |
| Loyalty reward: 300 ETB every 3 months ÷ 15,000 | 100 ETB (2%) |
| **Most that can go out** | **1,017 ETB (20.34%)** |
| **You keep at least** | **3,983 ETB (79.66%)** |

Each membership has only one level-1 referrer, so all badge bonuses
together are at most 1.34% of the month's membership money: the 5% budget
never cuts anyone with the default table.

`tests/Feature/Referral/BonusProfitSimulationTest.php` pays a month for
2,500 random members (late payers, refunds, free months, joiners, leavers)
in four set-ups (defaults, 10× bonuses, tiny thresholds + one-time bonuses,
a 0.2% budget) and checks the shop keeps what the guard promises
(defaults: 84% kept in the simulation, at least 79.66% guaranteed).

## Paying

- Wallet **"on the way"** (pending) for 14 days (`bonus.pending_days`),
  then **ready to use**. Ledger type **Badge bonus** (`badge.bonus`, expense
  account `expense:badge_bonuses`).
- Just before it is ready it is worked out again: a refund since the run
  lowers or cancels it (`badge.bonus.reduced`). It is never raised.
- Fraud holds keep it on the way until the flag is cleared.
- **Once only:** one run per month, one bonus per member/month/badge, one
  ledger entry per bonus. Running the job again does nothing.
- **First month by hand:** the job on the 1st waits until the Super Admin
  has checked the list (dry run) and pressed **Pay now** once. After that
  it pays by itself (`badges:bonus`, 1st at 06:23).
- **Skip this month:** nothing is paid for it, now or later.
- Command line: `php artisan badges:bonus --dry-run`, `--month=2026-10`.

## Members see

"Your badge bonus" on the account home: badge now, expected bonus for this
month, why there is none (unpaid days), the next badge with a progress bar
and its bonus, and the history (on the way / ready / taken back).

Demo members are paid from demo money only and never mixed with real totals.
