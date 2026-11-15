# Badges

Badges are rows in the `badges` table. **Super Admin → Members → Badges**
can change each one (name, description, icon, colour, rule, threshold,
importance, on/off) and add new ones from the rules list. A badge members
already hold can only be switched off, not deleted. Unlocked badges are kept,
even if the team shrinks later.

## Rules and defaults
| Rule | Counts | Default badges |
|------|--------|----------------|
| `active_level1` | Active members you invited yourself | Bronze 3, Silver 10, Gold 25, Platinum 50, Diamond 100 |
| `total_team` | Active members in all 3 levels | Growing team 25, Big family 100, Community builder 250 |
| `loyalty_months` | Months of membership in a row | 3, 6, 12 months |
| `link_sales` | Paid orders through your link (guests with your code, or people you invited) | First sale (1) |
| `earnings_total` | Commissions earned, minus refunds | 10,000 / 50,000 / 100,000 / 500,000 |
| `top_referrer_month` | Rank in a month's top referrers | Top referrer (rank 1) |
| `early_member` | Position among everyone who became a member | Early supporter (first 100) |
| `orders_count` | Your own paid orders | First order 1, Regular 5, Super shopper 10 |

Money thresholds are stored in minor units (santim), like all money, and
shown in the shop currency.

## When badges unlock
- After every payment: the buyer and the 3 people above them are checked
  (queued job `EvaluateBadges`, after the commissions exist).
- Daily `badges:check` (04:17): loyalty streaks and lowered thresholds.
- Monthly `badges:check --top-referrer` (1st, 05:13): last month's top
  referrers.
- Opening the badges page also checks right away.

On unlock the member gets an **in-app notification** and a **celebration**
(confetti, honours "reduce motion") the next time they open their account.
SMS / email / Telegram messages for badges are off by default (feature
switch `badges.messages`, text `notify.badge_unlocked`).

## Where badges show
- Member: `/account/badges` (every badge with a progress bar, e.g.
  "3/10 active members to Silver"), highest badge and next-badge progress on
  the dashboard.
- Public top referrers list: first name + highest badge (by importance).
- API: `GET /api/v1/badges` (progress + summary), `POST /api/v1/badges/seen`,
  `GET /api/v1/notifications`, `POST /api/v1/notifications/read`.
- Admin API: `GET/POST /api/v1/admin/badges`, `PUT/DELETE /api/v1/admin/badges/{id}`
  (`manage-settings`).
- Monthly badge bonus: [badge-bonus.md](badge-bonus.md).
