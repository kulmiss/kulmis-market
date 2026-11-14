<?php

namespace Tests\Feature\Money;

use App\Ledger\Account;
use App\Ledger\InsufficientFunds;
use App\Ledger\JournalEntry;
use App\Ledger\Ledger;
use App\Ledger\LedgerException;
use App\Ledger\Wallet;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private Ledger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(Ledger::class);
    }

    private function credit(User $user, int $amount, string $bucket = Wallet::AVAILABLE, ?string $key = null): JournalEntry
    {
        return $this->ledger->post('test.credit', [
            [$this->ledger->userAccount($user, $bucket), -$amount],
            [Account::COMMISSIONS, $amount],
        ], $key);
    }

    public function test_balances_are_derived_from_postings(): void
    {
        $user = User::factory()->create();
        $this->credit($user, 10_000);
        $this->credit($user, 2_500, Wallet::PENDING);

        $this->assertSame(['pending' => 2_500, 'available' => 10_000, 'payout' => 0], Wallet::for($user)->summary());
        $this->assertSame(12_500, $this->ledger->balance(Account::COMMISSIONS));
    }

    public function test_unbalanced_or_float_entries_are_rejected(): void
    {
        $this->expectException(LedgerException::class);
        $this->ledger->post('test', [[Account::CASH, 100], [Account::COMMISSIONS, -99]]);
    }

    public function test_zero_and_single_lines_are_rejected(): void
    {
        foreach ([[[Account::CASH, 0], [Account::COMMISSIONS, 0]], [[Account::CASH, 100]]] as $lines) {
            try {
                $this->ledger->post('test', $lines);
                $this->fail('accepted');
            } catch (LedgerException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_idempotency_key_prevents_double_credit(): void
    {
        $user = User::factory()->create();
        $a = $this->credit($user, 5_000, key: 'commission:payment:1:l1');
        $b = $this->credit($user, 5_000, key: 'commission:payment:1:l1');

        $this->assertTrue($a->is($b));
        $this->assertSame(5_000, Wallet::for($user)->available());
        $this->assertSame(1, JournalEntry::query()->count());
    }

    public function test_non_negative_guard_rolls_back(): void
    {
        $user = User::factory()->create();
        $this->credit($user, 1_000);
        $available = $this->ledger->userAccount($user, Wallet::AVAILABLE);

        try {
            $this->ledger->post('wallet.spend', [[$available, 1_500], [Account::WALLET_SALES, -1_500]], nonNegative: [$available]);
            $this->fail('overspend allowed');
        } catch (InsufficientFunds) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(1_000, Wallet::for($user)->available());
        $this->assertSame(1, JournalEntry::query()->count());
    }

    public function test_manual_entries_need_a_reason_and_are_logged(): void
    {
        $user = User::factory()->create();
        $acc = $this->ledger->userAccount($user, Wallet::AVAILABLE);
        try {
            $this->ledger->post('manual.adjustment', [[$acc, -100], [Account::ADJUSTMENTS, 100]]);
            $this->fail('no reason accepted');
        } catch (LedgerException) {
            $this->addToAssertionCount(1);
        }
        $this->ledger->post('manual.adjustment', [[$acc, -100], [Account::ADJUSTMENTS, 100]], reason: 'Goodwill credit');
        $this->assertSame(100, Wallet::for($user)->available());
        $this->assertSame(1, ActivityLog::query()->where('action', 'ledger.manual_entry')->count());
    }

    public function test_entries_and_postings_are_immutable(): void
    {
        $user = User::factory()->create();
        $entry = $this->credit($user, 100);

        $this->expectException(\LogicException::class);
        $entry->postings()->first()?->update(['amount' => 1]);
    }

    public function test_full_and_partial_reversal(): void
    {
        $user = User::factory()->create();
        $entry = $this->credit($user, 10_001, Wallet::PENDING);

        $this->ledger->reverse($entry, 'commission.reversed', 'refund', 'rev:1:half', null, 1, 2);
        $this->assertSame(5_001, Wallet::for($user)->pending()); // 10001 − floor(10001/2)

        $this->ledger->reverse($entry, 'commission.reversed', 'refund', 'rev:1:half'); // same key → no-op
        $this->assertSame(5_001, Wallet::for($user)->pending());
    }

    public function test_admin_manual_adjustment_endpoint(): void
    {
        $this->actingAsAdmin();
        $member = User::factory()->create();

        $this->postJson('/api/v1/admin/ledger/adjustments', ['user_id' => $member->id, 'bucket' => 'available', 'amount' => 30_000])
            ->assertUnprocessable(); // reason missing

        $this->postJson('/api/v1/admin/ledger/adjustments', ['user_id' => $member->id, 'bucket' => 'available', 'amount' => 30_000, 'reason' => 'Promo credit'])
            ->assertCreated()->assertJsonPath('data.wallet.available', 30_000);

        $this->getJson("/api/v1/admin/ledger/wallets/{$member->id}")->assertOk()->assertJsonPath('data.available', 30_000);
        $this->getJson('/api/v1/admin/ledger/entries?user_id='.$member->id)->assertOk()->assertJsonPath('total', 1);
    }

    public function test_finance_role_can_use_ledger_but_fulfilment_cannot(): void
    {
        $this->actingAsAdmin('fulfilment');
        $this->getJson('/api/v1/admin/ledger/accounts')->assertForbidden();
        $this->actingAsAdmin('finance');
        $this->getJson('/api/v1/admin/ledger/accounts')->assertOk();
    }
}
