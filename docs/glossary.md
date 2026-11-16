# Glossary: the easy words we use

The shop, the member area, the admin and all messages (SMS, email,
Telegram) use these easy words. The database and the code keep their
technical names; CSV files keep the codes so spreadsheets and other tools
still work. Legal pages (terms, privacy, refunds) keep their exact wording.

| What we say | What it means (technical name) |
|---|---|
| **On the way** | Money that is earned but can't be used yet, in case the payment is refunded (`pending`, 14 days) |
| **Ready to use** | Money the member can cash out or spend (`available`) |
| **Cash-out asked** | Money the member asked to receive; it waits for staff (`payout` bucket, `requested`) |
| **Money history** | Every money movement, in and out; nothing can be edited (the ledger, journal entries and postings) |
| **Money moved: + goes up, − goes down** | Each line of a money history entry: which totals grew and which shrank (debit / credit postings) |
| **Team earnings** | What a member earns when people in their 3 levels pay (commissions) |
| **Level 1 / 2 / 3** | People you invited / the people they invited / the next ones |
| **Taken back** | Earnings or a bonus cancelled because the money was refunded (`reversed`, `reduced`) |
| **Not paid out** | Earnings skipped because the person was not an active member; the shop keeps them (`skipped`) |
| **Money given back** | A refund (`refunded`); **Part given back** = partial refund |
| **Receipt to check** | A transfer receipt waiting for staff (`awaiting_review`) |
| **Not approved** | Rejected (`rejected`) |
| **Held for a check** | Money or a cash-out stopped while staff look at a fraud flag (`on_hold`) |
| **Late (grace days)** | The membership ended, but benefits stay a few more days to pay (`grace`) |
| **Ended** | Membership or access stopped (`expired`, `ended`) |
| **Waiting for payment** | An order not paid yet (`pending_payment`) |
| **Being made / On its way / Delivered** | Order steps (`making`, `shipped`, `delivered`) |
| **Blocked** | A suspended account (`suspended`) |
| **Badge bonus** | Monthly money for Bronze–Diamond team badges (ledger type `badge.bonus`) |
| **Bonus adjusted this month** | The month's budget was too small, so everyone got the same smaller share (pro-rata cut) |
| **Fix by hand** | A manual correction by staff, always with a reason (`manual.adjustment`) |
| **Feature switch** | Turn a part of the shop on or off; nothing is deleted (feature flag) |
| **Two-step sign-in** | A code from a phone app after the password (2FA / TOTP) |
| **Team tree** | Who invited whom (referral tree) |
| **Fraud checks** | Flags that hold money until staff clear them (fraud queue) |

Words we never show to members: idempotency, ledger, posting, debit,
credit, payable, webhook, bps, santim, accrual.

When writing new screens or messages:

- Short sentences, everyday words. Say what will happen: "Pay 600 ETB now",
  "Do not pay, put money back", not "Submit" or "Confirm".
- Numbers with the currency: "5,000 ETB", percentages as "10%".
- Use the words in this table so the same thing always has the same name.
