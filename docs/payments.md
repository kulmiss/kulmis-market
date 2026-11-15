# Payments (ETB)

There is no card or online gateway. Customers pay in two ways:

## Bank transfer / Telebirr with a receipt
1. The pay page (`/pay/{order}`) shows the Telebirr and bank details
   (Settings → Payments: `payments.telebirr_details`, `payments.bank_details`).
2. The customer transfers and sends the receipt:
   `POST /api/v1/orders/{number}/payment-proof` (multipart: method
   `telebirr` | `bank_transfer`, proof image/PDF ≤ 5 MB, reference, payer
   account). Proofs are stored on the **private** disk.
3. Finance (or anyone with `approve-payments`) opens the proof in admin and
   approves it, or rejects it with a reason the customer can see.

An order with a receipt waiting for review is never auto-cancelled. The same
flow is used for membership (`/api/v1/membership/payment-proof`) and guest
signals (`/api/v1/signals/payment-proof`).

## Wallet
Members can pay all or part of an order or membership from their wallet
(see [wallet-cashout-fraud.md](wallet-cashout-fraud.md)). If the wallet
doesn't cover everything, the rest is paid by transfer + receipt.

## Exactly once
`PaymentService::markPaid()` locks the payment row. Two admins approving at
the same time, or a double click, still give value once:
- order → paid
- ledger: `asset:cash` ↔ `income:sales`
- `PaymentSucceeded` event (referral commissions in PR 11)

A receipt approved after the order was cancelled is recorded as
paid, and the order is flagged `order.paid_after_cancel` so you can re-open
it or refund it.

## Refunds
Admin: `POST /api/v1/admin/payments/{id}/refund {amount, reason, restock?}`
(Finance / Super Admin). Full or partial. Transfers are refunded by hand
(Finance sends the money back and the refund is recorded as `manual`);
wallet payments go straight back to the wallet. The ledger
entry is reversed, the order moves to `refunded` on a full refund, and
`PaymentRefunded` fires, which reverses commissions automatically in PR 11.

## Receipts
`Receipt::link($payment)` gives the customer a signed PDF link valid for 30
days. Admins can download from `/api/v1/admin/payments/{id}/receipt`.
