# REST API v1

Base URL: `/api/v1`. JSON everywhere. Money is **integer santim** (500000 = 5,000 ETB).

- **Customer auth:** `Authorization: Bearer <token>` from `auth/register` or `auth/login` (Sanctum).
- **Cart:** the `X-Cart-Token` header (the app) or the `km_cart` cookie (website).
- **Errors:** `{"message": "...", "errors": {field: [...]}}` with status 401, 403, 404, 422 or 429.
- A feature that's switched off answers **404**.
- **Admin:** `/api/v1/admin/*` uses the same-domain session + CSRF cookie and 2FA. It is not for the app.
- **Webhooks:** `webhooks/telegram` (secret header).

## Customer / public endpoints

| Method | Path | Auth |
|---|---|---|
| POST | `/api/v1/auth/forgot` | public |
| POST | `/api/v1/auth/login` | public |
| POST | `/api/v1/auth/logout` | token |
| POST | `/api/v1/auth/register` | public |
| POST | `/api/v1/auth/reset` | public |
| GET | `/api/v1/cart` | public |
| PUT | `/api/v1/cart/coupon` | public |
| POST | `/api/v1/cart/items` | public |
| PATCH | `/api/v1/cart/items/{item}` | public |
| GET | `/api/v1/categories` | public |
| POST | `/api/v1/checkout` | public |
| GET | `/api/v1/me` | token |
| PUT | `/api/v1/me` | token |
| GET | `/api/v1/me/orders` | token |
| GET | `/api/v1/me/orders/{number}` | token |
| PUT | `/api/v1/me/password` | token |
| GET | `/api/v1/membership` | token |
| GET | `/api/v1/membership/info` | public |
| POST | `/api/v1/membership/pay` | token |
| POST | `/api/v1/membership/payment-proof` | token |
| POST | `/api/v1/orders/track` | public |
| POST | `/api/v1/orders/{number}/pay` | public |
| POST | `/api/v1/orders/{number}/payment-options` | public |
| POST | `/api/v1/orders/{number}/payment-proof` | public |
| GET | `/api/v1/payments/{payment}/receipt` | signed link |
| GET | `/api/v1/ping` | public |
| GET | `/api/v1/products` | public |
| GET | `/api/v1/products/{slug}` | public |
| GET | `/api/v1/referral` | token |
| PUT | `/api/v1/referral/code` | token |
| GET | `/api/v1/referral/history` | token |
| GET | `/api/v1/referral/qr.svg` | token |
| GET | `/api/v1/referral/top` | public |
| GET | `/api/v1/shipping/zones` | public |
| GET | `/api/v1/signals/info` | public |
| GET | `/api/v1/signals/member-access` | token |
| POST | `/api/v1/signals/payment-proof` | public |
| GET | `/api/v1/signals/performance` | public |
| POST | `/api/v1/signals/subscribe` | public |
| GET | `/api/v1/wallet` | token |
| POST | `/api/v1/wallet/cash-out` | token |
| POST | `/api/v1/webhooks/telegram` | public |

## Admin endpoints

| Method | Path |
|---|---|
| GET | `/api/v1/admin/activity` |
| POST | `/api/v1/admin/auth/login` |
| POST | `/api/v1/admin/auth/logout` |
| GET | `/api/v1/admin/auth/me` |
| POST | `/api/v1/admin/auth/two-factor` |
| GET | `/api/v1/admin/cash-outs` |
| GET | `/api/v1/admin/cash-outs/{payout}` |
| POST | `/api/v1/admin/cash-outs/{payout}/pay` |
| POST | `/api/v1/admin/cash-outs/{payout}/reject` |
| GET | `/api/v1/admin/categories` |
| POST | `/api/v1/admin/categories` |
| PUT | `/api/v1/admin/categories/{category}` |
| GET | `/api/v1/admin/coupons` |
| POST | `/api/v1/admin/coupons` |
| PUT | `/api/v1/admin/coupons/{coupon}` |
| GET | `/api/v1/admin/couriers` |
| POST | `/api/v1/admin/couriers` |
| PUT | `/api/v1/admin/couriers/{courier}` |
| GET | `/api/v1/admin/features` |
| PUT | `/api/v1/admin/features` |
| GET | `/api/v1/admin/fraud` |
| POST | `/api/v1/admin/fraud/users/{user}` |
| POST | `/api/v1/admin/fraud/{flag}/review` |
| GET | `/api/v1/admin/ledger/accounts` |
| POST | `/api/v1/admin/ledger/adjustments` |
| GET | `/api/v1/admin/ledger/entries` |
| GET | `/api/v1/admin/ledger/wallets/{user}` |
| GET | `/api/v1/admin/members` |
| GET | `/api/v1/admin/members/{user}` |
| POST | `/api/v1/admin/members/{user}/membership` |
| POST | `/api/v1/admin/members/{user}/status` |
| GET | `/api/v1/admin/orders` |
| POST | `/api/v1/admin/orders/bulk-status` |
| GET | `/api/v1/admin/orders/{order}` |
| PUT | `/api/v1/admin/orders/{order}/shipping` |
| POST | `/api/v1/admin/orders/{order}/status` |
| GET | `/api/v1/admin/payments` |
| GET | `/api/v1/admin/payments/{payment}` |
| POST | `/api/v1/admin/payments/{payment}/approve` |
| POST | `/api/v1/admin/payments/{payment}/check` |
| GET | `/api/v1/admin/payments/{payment}/proof` |
| GET | `/api/v1/admin/payments/{payment}/receipt` |
| POST | `/api/v1/admin/payments/{payment}/refund` |
| POST | `/api/v1/admin/payments/{payment}/reject` |
| GET | `/api/v1/admin/products` |
| POST | `/api/v1/admin/products` |
| POST | `/api/v1/admin/products/bulk` |
| GET | `/api/v1/admin/products/{product}` |
| PUT | `/api/v1/admin/products/{product}` |
| POST | `/api/v1/admin/products/{product}/images` |
| DELETE | `/api/v1/admin/products/{product}/images/{image}` |
| GET | `/api/v1/admin/referrals/commissions` |
| POST | `/api/v1/admin/referrals/members/{user}/referrer` |
| GET | `/api/v1/admin/referrals/tree/{user}` |
| GET | `/api/v1/admin/settings` |
| PUT | `/api/v1/admin/settings` |
| GET | `/api/v1/admin/shipping-zones` |
| POST | `/api/v1/admin/shipping-zones` |
| PUT | `/api/v1/admin/shipping-zones/{zone}` |
| GET | `/api/v1/admin/signals` |
| POST | `/api/v1/admin/signals` |
| GET | `/api/v1/admin/signals/subscribers` |
| POST | `/api/v1/admin/signals/subscribers` |
| POST | `/api/v1/admin/signals/subscribers/{subscription}/remove` |
| GET | `/api/v1/admin/signals/telegram` |
| PUT | `/api/v1/admin/signals/{signal}` |
| POST | `/api/v1/admin/signals/{signal}/close` |
| DELETE | `/api/v1/admin/two-factor` |
| POST | `/api/v1/admin/two-factor/confirm` |
| POST | `/api/v1/admin/two-factor/setup` |

_Regenerate this list with:_ `php artisan route:list --path=api/v1 --json -v | python3 scripts/api-doc.py`
