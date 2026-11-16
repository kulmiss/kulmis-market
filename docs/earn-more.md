# Earn more (Round 5)

New ways for **members** and for **the shop** to earn, plus helpers around them.
Every feature has its own switch (Admin → Feature switches) with an easy
description, and every number is in Admin → Settings. Decisions: QUESTIONS.md
#70–#133.

## Profit rule (applies to everything below)

- Every new payout (bonus, prize, reward) is a ledger posting, waits the
  14-day "on the way" period (`referral.pending_days`), is held while a fraud
  check is open, and is taken back if the payment behind it is refunded.
- It only comes from money really paid. Nothing is promised in advance.
- **ProfitGuard** counts every payout switch in the worst case and refuses
  settings that would pay out more than 35% of a membership. Worst case with
  the defaults: 25.34% (you keep 3,733 of 5,000 ETB). A running contest adds
  up to 2% (`contest.budget`), challenges up to 2% (`challenge.budget`).
- The random-teams simulation (`BonusProfitSimulationTest`) runs with every
  payout on and checks that the shop keeps at least 75% of membership money.
- The product-order simulation (`ProductProfitSimulationTest`, 3 × 90 random
  orders with sales, bundles, coupons up to half price, extras and all 3 team
  levels) checks that no paid order loses money: goods + extras − cost − team
  earnings − payment fee − courier ≥ 0, in random cities with their own
  courier cost. Free delivery is included: it is only given when the order
  still pays for the courier.
- **Coupon safety** (`shop.coupon_floor`, on): a coupon is made smaller only
  when it would take an order below cost + the most team earnings + the
  payment fee. Flash sales and bundles that lose money need "I know it loses
  money" in the admin.

## Members earn more

| Feature | Switch | Settings | How it pays |
|---|---|---|---|
| First-payment bonus | `referral.first_payment_bonus` | `bonus.first_payment` (250 ETB) | Once per new member, to their inviter, when the new member's first real membership payment is approved |
| Welcome-back reward | `membership.comeback_reward` | `reward.comeback` (0 = off), `reward.comeback_days` | To the inviter when someone comes back after N days away |
| Referral contests | `referral.contests` | `contest.budget` | Fixed prizes, paid after the end + 14 days, cut to fit the budget; real payments only |
| Team challenges | `referral.challenges` | `challenge.budget` | "Bring N paying members this month" → reward, with a monthly cap |
| Product share links | `referral.product_links` | — | `/r/CODE/shop/<product>`, `/r/CODE/shop`, `/r/CODE/membership`, `/r/CODE/signals`: normal referral sales |
| Share kit + picture cards | `referral.share_kit` | — | Ready posts with `{name}`, `{code}`, `{link}`; picture cards are made on the phone |
| Earnings tips | `referral.tips` | — | Up to 3 tips on the member home |
| Gift membership | `membership.gifts` | — | Anyone pays a month for someone; the receiver joins the giver's team |
| Monthly goals, bell, phone notifications | `account.goals`, `account.notifications`, `account.push` | — | Help members follow their money |

Code: `App\Rewards\*` (rewards, contests, challenges, scores),
`App\Referral\ShareLinks`, `App\Referral\Tips`, `App\Membership\Gifts`,
`App\Notify\*`.

## The shop earns more

| Feature | Switch | Settings | Notes |
|---|---|---|---|
| Flash sales | `shop.flash_sales` | — | Price for a time, optional piece limit (counted with a lock), countdown; profit check with "accept loss" |
| Bundles | `shop.bundles` | — | Sets at one price; the saving is applied in the cart |
| Upsells | `shop.upsells` | — | "Bought together" and "You may also like" |
| Checkout extras | `shop.extras.*` (off) | `extras.*` | Gift wrap, express, insurance |
| Cash-out fee | `wallet.cashout_fee` (off) | `wallet.cashout_fee_fixed`, `_bps` | Shown before the member asks; shop income |
| Gift cards | `shop.gift_cards` | `giftcards.amounts`, `giftcards.valid_days` | Codes are random, stored only as a fingerprint, 5 tries per 10 minutes. **Selling for cash in the shop needs Manager or Super Admin** |
| Digital products | `shop.digital` | `digital.link_days`, `digital.max_downloads` | Private disk, one link per file, counted atomically; never "sold out"; no address at checkout |
| Paid add-ons | `shop.addons` | `referral.rate.addons.l1..l3` | Seats = stock, own team-earning rates |
| Pre-orders | `shop.preorders` | per product | Share paid now, the rest before shipping; team earnings only at the end |
| Custom orders | `shop.custom_orders` | `custom.deposit_bps`, `custom.quote_days` | Request → price → deposit → rest |
| Win-back offers | `shop.winback` | `winback.days`, `winback.percent_bps` | Once per person, one-use code |
| Wishlist, back-in-stock, buy again, saved addresses, reviews | `shop.*` | — | — |
| Reports, bulk edit, CSV import, low-stock alerts | — | — | Admin → Reports / Products |

## Who can do what (staff)

- Promotions (flash sales, bundles): `manage-products`.
- Contests and challenges: `manage-referrals`.
- Gift cards list and switch off: `manage-orders`; **sell for cash**: also
  `approve-payments`.
- Custom orders: `manage-orders`. Help centre answers: `answer-help`.
- Reports and the dashboard: `view-reports`.

## Demo data

The demo seeder adds a live flash sale, a bundle, a contest, a challenge, a
pre-order, a download, a gift card and a custom order. They are marked demo
like the rest. The demo contest leaderboard stays empty because contests only
count real money.
