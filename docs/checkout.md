# Cart, checkout and tracking

- **Cart:** identified by the essential `km_cart` cookie (a random token only,
  HttpOnly, SameSite=Lax) or the `X-Cart-Token` header (for the app).
  `GET /api/v1/cart` returns the items, a full quote (member prices,
  coupon, shipping, Join & save preview) and any `problem`, such as
  "only 1 left".
- **Checkout:** `POST /api/v1/checkout` with name, Ethiopian phone (stored as
  `+2519…`), optional email, city, address, optional zone and notes, plus a
  client-generated `checkout_key`.
  - The same `checkout_key` always returns the same order, so double clicks
    and retries never create two orders.
  - Stock rows are locked and decremented in the same transaction, so stock
    can't be oversold.
  - Guest checkout can be switched off (`shop.guest_checkout`). Signed-in
    users still check out.
  - The order stores a snapshot of prices **and unit cost** for profit
    reports.
- **Unpaid orders** are cancelled after `orders.unpaid_cancel_hours`
  (default 48) by `orders:cancel-unpaid` (hourly). Stock and coupon uses are
  given back.
- **Tracking:** `POST /api/v1/orders/track {number, phone}`, no account
  needed, rate limited.
- Order statuses: `pending_payment → paid → making → shipped → delivered`,
  plus `cancelled` and `refunded`. Allowed moves are in `Order::TRANSITIONS`.
