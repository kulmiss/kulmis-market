# Shipping and notifications

## Shipping
- **Zones:** a name, a list of cities, a base fee (covers `included_grams`,
  default 1 kg) and a fee for each extra started kg. Mark one zone as
  `is_default` ("Other cities") to cover cities you haven't listed.
- The fee is picked by zone id, else by city name (any letter case), else
  the default zone. Free shipping for members is applied by the pricing
  engine.
- **Courier cost per zone** ("Courier charges you", plus per extra kg):
  what the courier charges you. The price check of a product counts it for
  free-delivery orders, and free delivery is only given when the order still
  pays for the courier — otherwise the cart charges delivery with a short
  note. A zone whose courier costs more than the fee you charge shows ⚠ in
  the admin (your prices are not changed). Each order stores its courier cost.
- **Couriers:** EMS, HudHud and others. `tracking_url` can contain `{number}`.
- Admin API: `/api/v1/admin/shipping-zones`, `/api/v1/admin/couriers`.
  Public: `GET /api/v1/shipping/zones`.

## Order flow (admin)
`POST /api/v1/admin/orders/{id}/status` moves an order through
paid → making → shipped → delivered, or cancels it. Shipping requires a
courier, and the tracking number is optional. **Paid** comes only from a
confirmed payment and **refunded** only from the refund action (PR 8).
There is also a bulk move (`orders/bulk-status`) that reports per-order
failures.

## Customer messages
Every status change sends `SendCustomerMessage` (queued). The job uses:
- **SMS** (`notifications.sms`): `SMS_DRIVER=log` (default) or `http`
  (generic JSON API: `POST {to, message, sender}` with a Bearer token).
- **Email** (`notifications.email`).
- **Telegram** (`notifications.telegram`), for customers who linked the bot.

If one channel fails, the others still send. The result is saved on the
status history row (`notified`). The texts are editable settings
(`notify.order_*`) with placeholders: `{name} {number} {total} {courier}
{tracking} {tracking_link} {track_url} {making_days} {reason} {site}`.
