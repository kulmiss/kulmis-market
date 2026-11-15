<?php

namespace App\Payments;

use App\Models\Payment;

/**
 * Anything that can be paid: shop orders, membership months, signals months.
 */
interface Payable
{
    /** Amount still to pay in santim. */
    public function payableAmount(): int;

    public function payableTitle(): string;

    /** Ledger income account, e.g. income:sales. */
    public function payableIncomeAccount(): string;

    /** @return array{first_name:string,last_name:string,phone:?string,email:?string} */
    public function payableCustomer(): array;

    public function payableUserId(): ?int;

    public function isSettled(): bool;

    /** Called once, inside the payment transaction. */
    public function markPaidFrom(Payment $payment): void;

    /** Called for each refund (full or partial), inside the refund transaction. */
    public function refundedFrom(Payment $payment, int $amount, bool $full): void;
}
