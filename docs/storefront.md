# Storefront

Server-rendered Blade (`resources/views/store`), Tailwind 4 and about 10 KB
of vanilla JS (`resources/js/app.js`). No web fonts, no frameworks, no
trackers. Built CSS/JS are committed in `public/build`, so no Node is needed
on the server.

## Pages
`/` · `/shop` · `/c/{category}` · `/shop/{product}` (gallery with zoom,
variants, member price, JSON-LD) · `/membership` · `/referral-program` ·
`/signals` · `/signals/performance` · `/p/{page}` · `/cart` · `/checkout` ·
`/pay/{order}` · `/track` · `/login` · `/register` · `/forgot` · `/account`
(membership, signals link, referral link + QR + share, team, earnings,
wallet, cash-out, orders, early access) · `/sitemap.xml` · `/robots.txt`

Every product shows the **member price** next to the guest price. The cart
shows **Join & save** with the exact amount a guest would save.

## Member area (`/account`)
A separate, app-like area with its own layout (`layouts/member.blade.php`):
a sidebar on desktop and a bottom bar on phones, green & white with a
matching dark mode. The accent colour is **Settings → Appearance → Member
area accent colour** (default `#1f8a4c`); every shade (soft backgrounds,
dark mode) is mixed from it. The admin keeps its own look.

| Page | What's on it |
|------|--------------|
| Home `/account` | membership status, quick actions (cash out, shop, renew), total earned / pending / available / paid out, this month vs last month, earnings chart (daily 30 days / monthly 12 months, SVG, no JS), earnings per level and by type, next badge, referral link + code + copy + QR + Telegram/WhatsApp/share, team per level with active / not active, newest in your team, membership + loyalty streak, signals + Telegram button, recent activity, early access |
| Team `/account/team` | level 1 names (first name + initial) with active / not active; levels 2–3 as counts |
| Earnings `/account/earnings` | totals, 12-month chart, full history |
| Wallet `/account/wallet` | balances, cash-out account, cash-out form, cash-out history |
| Badges, Orders, Signals, Security | as named |

New members see friendly empty states ("share your link"). Everything is
server-rendered (fast on slow phones); the only JS is copy/share and the
light/dark switch. Data: `App\Member\MemberDashboard` (same privacy rules
as `TeamView`).

## Privacy: no session for guests
Public pages use the `storefront` middleware group. It starts a session
**only if a session cookie already exists** (i.e. after signing in). Guests
get no cookie except the two essential ones: `km_cart` (random token) and
`km_ref` (referral code, 30 days). No IP is stored for guests anywhere.
Guest checkout, payment and tracking go through the stateless JSON API.

## Static page cache
Guest HTML for plain GETs without a query string is written to
`public/page-cache/…html`, and the web server serves it **without PHP**:
- nginx: see `docker/nginx/default.conf` (`$page_cache` + `try_files`)
- Apache / shared hosting: rules in `public/.htaccess`

Anyone with the `km_session` cookie (signed in) always gets a live page.
The cache is cleared whenever products, categories, pages, banners,
settings or feature switches change, and every 15 minutes (so early-access
releases show up on time). Turn it off with `PAGE_CACHE=false`.

## Performance
Checked in CI (`lighthouse` job): Lighthouse ≥ 95 in all four categories,
desktop LCP ≤ 1.0 s, phone LCP ≤ 1.8 s on simulated slow 4G, CLS ≤ 0.01, and
server time (`scripts/perf-budget.sh`): uncached pages p95 < 150 ms, API p95
< 100 ms. Query budgets per page are tests (`PerformanceTest`).
- Static page cache (above): guest pages in < 1 ms, without PHP
- Responsive AVIF/WebP images (AVIF quality 45) with width/height (no layout
  shift); the main photo is preloaded before the CSS; lazy below the fold
- One CSS file (~9 KB gzipped), one small deferred JS module, system fonts
- **Instant navigation:** Speculation Rules prefetch a public page when the
  pointer rests on its link or a finger touches it (Chrome/Edge; a tiny
  `<link rel=prefetch>` fallback elsewhere). Account, admin, cart, checkout,
  pay, API and sign-in pages are never prefetched
- **Service worker** (`public/sw.js`): versioned files and photos come from
  the cache; public pages always from the network, with a saved copy only when
  offline, then `public/offline.html`. Private pages and non-public responses
  are never stored. To turn it off, replace `sw.js` with one that unregisters
- Light/dark follows the device, with a toggle saved in `localStorage`
- After every deploy run `php artisan optimize`: it caches config, routes,
  views and events **and empties the page cache** (new CSS/JS file names)

## SEO
Per-page title and description, canonical URL, Open Graph and Twitter
images (product photo, or `public/og-default.png`), Product JSON-LD,
sitemap, robots (admin/account/checkout/API excluded).

## Content
Pages (Markdown, rendered safely) and banners (top bar, home, shop,
membership, signals, with dates) are edited in **Admin → Pages & banners**.

## Demo data
`php artisan db:seed --class=DemoSeeder` adds demo data for every section (see [local-setup.md](local-setup.md#demo-data)).

## Screen review (security review, 15 Nov)
Every storefront page (phone + desktop, light + dark, guest + member) and
every admin page (desktop light/dark + phone) was checked in headless Chrome
with realistic data. Fixed: branded error pages (404/403/419/429/500/503,
no database needed), invisible "Made to order" badge in dark mode, admin
template logo/favicons replaced with the Kulmis mark, readable fraud-flag
details, member page overflowing on phones (grid columns), missing labels,
heading order on /shop, and text compression (gzip/deflate) in nginx and
`.htaccess`.

Lighthouse (desktop, as in CI): 100 / 98–100 / 100 / 100 on all key pages
(best practices up from 96). Phone through nginx with the page cache:
LCP 1.2–1.4 s. Cached pages answer in < 1 ms; uncached in ~30 ms.

## Colours
White and green, set once in `resources/css/app.css` (`@theme`):
- `brand-500/600/700`: the logo green `#1f8a4c` and two darker shades.
  Buttons use `brand-600` so white text passes WCAG AA (5.8:1).
- `mist-50…300`: white and very light greens for backgrounds and borders.
- `ink-700…950`: dark green-grey text, and the dark-mode background.
- `gold-500`: small accents only.

In dark mode, green text switches to a lighter green so it stays readable.

**Light / dark switch:** Super Admin → Features → *Appearance → Light / dark
switch for customers*. On (default): the moon button shows in the shop and
the member area, and the device setting is followed until someone picks one.
Off: no button and no dark mode at all, the shop and member area are always
light. (The admin keeps its own theme setting.)

**Header:** menu (phones), light/dark, cart, then the account at the far
right: a person icon (Sign in) for guests, or the member's initial and first
name when signed in.
Emails, PDF receipts, the social preview (`resources/brand/og-default.html`)
and the browser theme colour use the same palette.

## Logo and icons
Logo A, the "smiling mesob" (chosen from [three options](logo-options.png)), in
the member-area green (`#1f8a4c`, the default of Settings → Appearance →
member accent) with the gold knob and sand basket. The web-app manifest uses
the same green as its theme colour.
One source file: `resources/brand/logo-mark.svg`. It is used in:
- the storefront header (`partials/logo-mark.blade.php`), error pages, and
  the admin (`admin/src/assets/logo.tsx`)
- `public/favicon.svg` + `favicon.ico`, `apple-touch-icon.png`, and
  `brand/icon-192/512.png` (`site.webmanifest`)
- emails (`brand/logo-96.png`, a PNG because many mail apps block SVG) and
  PDF receipts (the same PNG, embedded)
- the social preview `og-default.png` (1200×630)

To change the logo, replace the SVG and regenerate the PNGs (for example
`rsvg-convert -w 512 -h 512 resources/brand/logo-mark.svg -o public/brand/icon-512.png`).
