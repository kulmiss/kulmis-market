# Payments (ETB)

## Chapa
1. `POST /api/v1/orders/{number}/pay {method: "chapa", phone}` creates a
   `payments` row (tx_ref `KM-…`) and returns Chapa's `checkout_url`. This
   covers Telebirr, CBE Birr, M-Pesa and cards.
2. Chapa calls `POST /api/v1/webhooks/chapa`. We check `x-chapa-signature`
   (HMAC-SHA256 of the body) or `chapa-signature` (HMAC of the secret).
   Then we **always re-verify** with `GET /transaction/verify/{tx_ref}`
   before giving value.
3. The customer's redirect (`/api/v1/payments/chapa/callback`) verifies the
   same way, so whichever arrives first wins.

Value is given only when the status is `success`, the currency is ETB and
the amount is at least what's due. An underpaid or wrong-currency payment is
flagged (`meta.mismatch`) and never accepted automatically.

## Bank transfer / Telebirr with proof (fallback)
`POST /api/v1/orders/{number}/payment-proof` (multipart: method, proof
image/PDF ≤ 5 MB, reference, payer account). Proofs are stored on the
**private** disk. In admin: view proof → approve or reject (with a reason).
An order with a proof waiting for review is never auto-cancelled.

## Exactly once
`PaymentService::markPaid()` locks the payment row. Webhooks, redirects and
admin approvals can race, and value is still given once:
- order → paid
- ledger: `asset:cash` ↔ `income:sales` (plus the `expense:payment_fees` entry)
- `PaymentSucceeded` event (referral commissions in PR 11)

A payment that arrives after the order was auto-cancelled is recorded as
paid, and the order is flagged `order.paid_after_cancel` so you can re-open
it or refund it.

## Refunds
Admin: `POST /api/v1/admin/payments/{id}/refund {amount, reason, restock?}`
(Finance / Super Admin). Full or partial refunds go through Chapa's refund
API when it's available, otherwise they're recorded as manual. The ledger
entry is reversed, the order moves to `refunded` on a full refund, and
`PaymentRefunded` fires, which reverses commissions automatically in PR 11.

## Receipts
`Receipt::link($payment)` gives the customer a signed PDF link valid for 30
days. Admins can download from `/api/v1/admin/payments/{id}/receipt`.
