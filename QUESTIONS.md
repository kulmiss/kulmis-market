# Questions and decisions

Each question has a recommendation, and I build on it until you say
otherwise. On the night of 2026-11-14 you asked me to take my recommendation
everywhere, so every **Decided (recommended)** row below is my call. You can
change any of them.

| # | Question | Decision | Status |
|---|----------|----------|--------|
| 1 | Shop name | **Kulmis Market** | ✅ Answered by you |
| 2 | Base for the admin | satnaing/shadcn-admin + shadcn/ui, no saas-kit | ✅ Answered by you |
| 3 | Laravel version | **Laravel 13** (13.42). Laravel 11 had 5 unpatched security advisories. Needs PHP 8.3+. | ✅ Answered by you |
| 4 | SMS provider for Ethiopia | One `SmsGateway` interface with a `log` driver (default) and a generic HTTP JSON driver (`SMS_DRIVER=http`, `SMS_URL`, `SMS_TOKEN`). If your provider's API has a different shape, it's one small class to adapt. | Decided (recommended), you pick the provider |
| 5 | Domain | Placeholder `kulmis.market`. Change `APP_URL` and `MAIL_FROM_ADDRESS`. | Decided (recommended) |
| 6 | Static analysis (Larastan) | Left out for now: this build machine can't download it. Add it back with `composer require --dev larastan/larastan`. | Decided (recommended) |
| 7 | Repo layout | Laravel at the root, React admin in `admin/` building to `public/admin`. Works on shared hosting. | Decided (recommended) |
| 8 | Rounding | Customer discounts round half-up to the santim. Commissions and rewards round down. | Decided (recommended) |
| 9 | Members-only product: who can see it? | Everyone sees it with a "Members only" badge (good for "Join & save"). Only active members can buy it. | Decided (recommended) |
| 10 | Do members keep benefits during the 3-day grace period? | Yes. Discounts, signals and earnings continue until the grace period ends. | Decided (recommended) |
| 11 | Free shipping "over 3,000 ETB": is exactly 3,000 included? | Yes (≥ 3,000), measured on goods after member discount and coupon, excluding shipping. | Decided (recommended) |
| 12 | Can coupons stack with the member discount? | Yes. The coupon applies after the member discount. Use `members_only` or a lower value to control it. | Decided (recommended) |
| 13 | Someone signed up without a code: can a later code set their referrer? | No. The referrer is fixed at sign-up (none in this case). Only Super Admin can set or change it. | Decided (recommended) |
| 14 | Refund after earnings are already *available* | They're reversed from *available*. If the member already spent or withdrew them, the wallet goes negative and cash-out stays blocked until it's positive again. | Decided (recommended) |
| 15 | Guest buyers (no account) | The referrer is saved per order, and on the signals subscription at the first purchase (kept for renewals). | Decided (recommended) |
| 16 | Does a skipped level get passed up to the next active member? | No (as in your brief). The skipped share stays with the shop, and each level only ever gets its own rate. | ✅ From brief |
