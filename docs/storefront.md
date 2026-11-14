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

## Performance target
LCP ≤ 1.5 s and Lighthouse ≥ 95 in all four categories, checked by the
`lighthouse` CI job (`lighthouserc.json`, demo catalog seeded):
- Responsive AVIF/WebP images with fixed dimensions (no layout shift), a
  high-priority hero image, lazy loading below the fold
- One CSS file (about 7 KB gzipped), one small deferred JS module
- System fonts; light/dark follows the device, with a toggle saved in
  `localStorage` (not a cookie)

## SEO
Per-page title and description, canonical URL, Open Graph and Twitter
images (product photo, or `public/og-default.png`), Product JSON-LD,
sitemap, robots (admin/account/checkout/API excluded).

## Content
Pages (Markdown, rendered safely) and banners (top bar, home, shop,
membership, signals, with dates) are edited in **Admin → Pages & banners**.

## Demo data
`php artisan db:seed --class=DemoSeeder` adds 4 categories and 8 products
with generated photos, plus shipping zones and couriers.
