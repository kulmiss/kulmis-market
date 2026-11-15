<?php

namespace App\Member;

use App\Ledger\Posting;
use App\Ledger\Wallet;
use App\Models\Commission;
use App\Models\Order;
use App\Models\PayoutRequest;
use App\Models\Signal;
use App\Models\User;
use App\Notify\OrderNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** The tables of the member area, each in easy words. */
final class MemberTables
{
    /** Money history: what each wallet line means. */
    public const MONEY_TYPES = [
        'commission.earned' => 'Team earnings', 'commission.released' => 'Team earnings ready', 'commission.reversed' => 'Team earnings taken back (refund)',
        'badge.bonus' => 'Badge bonus', 'badge.bonus.released' => 'Badge bonus ready', 'badge.bonus.reduced' => 'Badge bonus taken back (refund)',
        'loyalty.reward' => 'Loyalty reward', 'wallet.spend' => 'Paid with your wallet', 'wallet.refunded' => 'Money back to your wallet',
        'payout.requested' => 'Cash-out asked', 'payout.paid' => 'Cash-out sent to you', 'payout.rejected' => 'Cash-out not approved (back in your wallet)',
        'manual.adjustment' => 'Fixed by our team',
    ];

    public const BUCKET = [Wallet::PENDING => 'On the way', Wallet::AVAILABLE => 'Ready to use', Wallet::PAYOUT => 'Cash-out asked'];

    /** @param array<string, mixed> $ref TeamView::for() */
    public static function team(Request $request, array $ref): MemberTable
    {
        $level1 = $ref['team']['level1'] ?? [];
        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = ($level1 instanceof Collection ? $level1 : new Collection((array) $level1))->values()->map(fn (array $p, int $i): array => [
            '_id' => $i, 'name' => $p['name'], 'joined' => $p['joined'], 'status' => $p['active'] ? 'active' : 'not_active',
        ]);

        return (new MemberTable('team', [
            'name' => ['Name', 'text', true],
            'joined' => ['Joined', 'date', true],
            'status' => ['Membership', 'status', true],
        ], ['status' => ['label' => 'Membership', 'options' => ['active' => 'Active', 'not_active' => 'Not active']]],
            'Search a name', '-joined', 'No one yet. Share your link from the home screen.'))->fromRows($request, $rows);
    }

    public static function earnings(Request $request, User $user): MemberTable
    {
        return (new MemberTable('earnings', [
            'created_at' => ['Date', 'date', true],
            'type' => ['From', 'text', true],
            'level' => ['Level', 'number', true, 'end'],
            'amount' => ['Amount', 'money', true, 'end'],
            'status' => ['Status', 'status', true],
            'available_at' => ['Ready on', 'date', true, '', true],
        ], [
            'status' => ['label' => 'Status', 'options' => ['pending' => 'On the way', 'available' => 'Ready to use', 'reversed' => 'Taken back']],
            'type' => ['label' => 'From', 'options' => ['membership' => 'Memberships', 'products' => 'Shop orders', 'signals' => 'Signals']],
            'level' => ['label' => 'Level', 'options' => ['1' => 'Level 1', '2' => 'Level 2', '3' => 'Level 3']],
        ], null, '', 'Nothing yet. When someone in your team pays, you see it here.'))->fromQuery(
            $request,
            Commission::query()->where('recipient_id', $user->id)->where('status', '!=', Commission::SKIPPED),
            ['created_at' => 'created_at', 'type' => 'type', 'level' => 'level', 'amount' => 'amount', 'status' => 'status', 'available_at' => 'available_at'],
            fn (Builder $q, string $v) => $q->where('type', 'like', "%$v%"),
            fn (Commission $c) => ['_id' => $c->id, 'created_at' => $c->created_at?->toIso8601String(), 'type' => match ($c->type) {
                'membership' => 'Membership', 'products' => 'Shop order', 'signals' => 'Signals', default => ucfirst($c->type),
            }, 'level' => $c->level, 'amount' => $c->amount - $c->reversed_amount, 'status' => $c->status,
                'available_at' => $c->status === Commission::PENDING ? $c->available_at?->toIso8601String() : null],
            ['status' => 'status', 'type' => 'type', 'level' => 'level'],
        );
    }

    public static function money(Request $request, User $user): MemberTable
    {
        $accounts = DB::table('ledger_accounts')->where('user_id', $user->id)->pluck('id')->all();

        return (new MemberTable('money', [
            'created_at' => ['Date', 'datetime', true],
            'what' => ['What', 'text'],
            'where' => ['Where', 'text'],
            'amount' => ['Amount', 'money', true, 'end'],
        ], ['bucket' => ['label' => 'Where', 'options' => self::BUCKET]], 'Search, e.g. bonus', '', 'No money moved yet.'))->fromQuery(
            $request,
            Posting::query()->whereIn('ledger_postings.ledger_account_id', $accounts ?: [0])
                ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_postings.ledger_account_id')
                ->join('journal_entries', 'journal_entries.id', '=', 'ledger_postings.journal_entry_id')
                ->select('ledger_postings.id', 'ledger_postings.amount', 'ledger_postings.created_at', 'ledger_accounts.code', 'journal_entries.type', 'journal_entries.description'),
            ['created_at' => 'ledger_postings.created_at', 'amount' => 'ledger_postings.amount'],
            function (Builder $q, string $v) {
                $types = array_keys(array_filter(self::MONEY_TYPES, fn ($label) => str_contains(mb_strtolower($label), mb_strtolower($v))));
                $q->where(fn ($w) => $w->where('journal_entries.description', 'like', "%$v%")->orWhereIn('journal_entries.type', $types ?: ['']));
            },
            fn (Posting $p) => ['_id' => $p->id, 'created_at' => $p->created_at->toIso8601String(),
                'what' => self::MONEY_TYPES[(string) $p->getAttribute('type')] ?? (string) $p->getAttribute('description'),
                'where' => self::BUCKET[explode(':', (string) $p->getAttribute('code'))[2] ?? ''] ?? '',
                // The wallet is money the shop owes you: a credit is money in.
                'amount' => -$p->amount],
            ['bucket' => fn (Builder $q, string $b) => $q->where('ledger_accounts.code', "user:{$user->id}:$b")],
        );
    }

    public static function cashouts(Request $request, User $user): MemberTable
    {
        return (new MemberTable('cashouts', [
            'created_at' => ['Asked on', 'date', true],
            'amount' => ['Amount', 'money', true, 'end'],
            'to' => ['To', 'text'],
            'status' => ['Status', 'status', true],
            'reference' => ['Reference', 'text', false, '', true],
            'note' => ['Note', 'text', false, '', true],
        ], ['status' => ['label' => 'Status', 'options' => ['requested' => 'Asked', 'paid' => 'Paid', 'rejected' => 'Not approved']]],
            null, '', 'No cash-outs yet.', 'c_'))->fromQuery(
                $request,
                PayoutRequest::query()->where('user_id', $user->id),
                ['created_at' => 'created_at', 'amount' => 'amount', 'status' => 'status'],
                fn (Builder $q, string $v) => $q->where('payout_reference', 'like', "%$v%"),
                fn (PayoutRequest $r) => ['_id' => $r->id, 'created_at' => $r->created_at?->toIso8601String(), 'amount' => $r->amount,
                    'to' => ucfirst($r->method).' '.$r->maskedAccount(), 'status' => $r->status === 'on_hold' ? 'requested' : $r->status,
                    'reference' => (string) $r->payout_reference, 'note' => $r->status === 'rejected' ? (string) $r->reject_reason : ''],
                ['status' => fn (Builder $q, string $v) => $v === 'requested' ? $q->whereIn('status', ['requested', 'on_hold']) : $q->where('status', $v)],
            );
    }

    public static function orders(Request $request, User $user): MemberTable
    {
        return (new MemberTable('orders', [
            'number' => ['Order', 'text', true],
            'created_at' => ['Date', 'date', true],
            'total' => ['Total', 'money', true, 'end'],
            'status' => ['Status', 'status', true],
        ], ['status' => ['label' => 'Status', 'options' => ['pending_payment' => 'Waiting for payment', 'paid' => 'Paid', 'making' => 'Being made',
            'shipped' => 'On its way', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled', 'refunded' => 'Money given back']]],
            'Order number', '', 'No orders yet.'))->fromQuery(
                $request,
                Order::query()->where('user_id', $user->id),
                ['number' => 'id', 'created_at' => 'created_at', 'total' => 'total', 'status' => 'status'],
                fn (Builder $q, string $v) => $q->where('number', 'like', '%'.strtoupper($v).'%'),
                fn (Order $o) => ['_id' => $o->id, 'number' => $o->number, 'created_at' => $o->created_at->toIso8601String(), 'total' => $o->total,
                    'status' => $o->status, '_url' => OrderNotifier::trackUrl($o)],
                ['status' => 'status'],
            );
    }

    public static function signals(Request $request): MemberTable
    {
        return (new MemberTable('signals', [
            'pair' => ['Pair', 'text', true],
            'direction' => ['Side', 'text'],
            'posted_at' => ['Posted', 'date', true],
            'result' => ['Result', 'status', true],
            'value' => ['Result value', 'text', false, 'end'],
        ], ['result' => ['label' => 'Result', 'options' => ['open' => 'Open', 'win' => 'Win', 'loss' => 'Loss', 'breakeven' => 'No gain, no loss']]],
            'Pair, e.g. BTC', '', 'No signals yet.'))->fromQuery(
                $request,
                Signal::query()->whereNotNull('posted_at'),
                ['pair' => 'pair', 'posted_at' => 'posted_at', 'result' => 'result'],
                fn (Builder $q, string $v) => $q->where('pair', 'like', "%$v%"),
                fn (Signal $s) => ['_id' => $s->id, 'pair' => $s->pair, 'direction' => strtoupper((string) $s->direction),
                    'posted_at' => $s->posted_at?->toIso8601String(), 'result' => $s->result ?? 'open', 'value' => (string) $s->result_value],
                ['result' => fn (Builder $q, string $v) => $v === 'open' ? $q->whereNull('result') : $q->where('result', $v)],
            );
    }
}
