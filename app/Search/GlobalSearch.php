<?php

namespace App\Search;

use App\Models\Badge;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PayoutRequest;
use App\Models\Product;
use App\Models\Signal;
use App\Models\User;
use App\Support\Features;
use App\Support\Money;
use App\Support\Phone;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The Super Admin top-bar search (Ctrl/⌘ K). One query per group, at most
 * a few rows each, by first letters (indexed) so it stays under 100 ms.
 * Every group needs its own permission and feature switch: staff only see
 * what their role may open.
 *
 * Words: plain text ("abebe", "0911 22", "KM2611"); "#123" or "id:123" finds
 * by ID; "…" in quotes finds the exact phrase. Filters: type, status, from,
 * to (dates), min and max (amounts in santim).
 *
 * @phpstan-type Q array{text:string, exact:bool, id:?int, phone:?string}
 */
final class GlobalSearch
{
    public const TYPES = ['members', 'orders', 'products', 'payments', 'cashouts', 'signals', 'coupons', 'badges', 'settings'];

    public const LIMIT = 6;

    /**
     * @param  array{type?: ?string, status?: ?string, from?: ?string, to?: ?string, min?: ?int, max?: ?int}  $filters
     * @return array{groups: list<array{type:string, label:string, items: list<array<string, mixed>>}>, took_ms: float}
     */
    public function search(User $staff, string $raw, array $filters = []): array
    {
        $start = microtime(true);
        $q = $this->parse($raw);
        $groups = [];
        foreach ($this->groups() as $type => [$label, $allowed, $run]) {
            if (($filters['type'] ?? null) && $filters['type'] !== $type) {
                continue;
            }
            if (! $allowed($staff)) {
                continue;
            }
            $items = $run($q, $filters);
            if ($items !== []) {
                $groups[] = ['type' => $type, 'label' => $label, 'items' => $items];
            }
        }

        return ['groups' => $groups, 'took_ms' => round((microtime(true) - $start) * 1000, 1)];
    }

    /** @return Q */
    private function parse(string $raw): array
    {
        $raw = trim(mb_substr($raw, 0, 80));
        $exact = (bool) preg_match('/^"(.+)"$/u', $raw, $m);
        $text = $exact ? $m[1] : $raw;
        $id = preg_match('/^(?:#|id:\s*)(\d{1,12})$/i', $text, $m2) ? (int) $m2[1] : null;
        $digits = preg_replace('/\D/', '', $text) ?? '';
        $phone = strlen($digits) >= 4 && preg_match('/^[\d\s+()-]+$/', $text) ? $this->phonePrefix($digits) : null;

        return ['text' => addcslashes($text, '%_\\'), 'exact' => $exact, 'id' => $id, 'phone' => $phone];
    }

    /** "0911 22…" and "+251 911 22…" both find +25191122… */
    private function phonePrefix(string $digits): string
    {
        if (str_starts_with($digits, '251')) {
            return '+'.$digits;
        }
        if (str_starts_with($digits, '0')) {
            return '+251'.substr($digits, 1);
        }
        if (strlen($digits) >= 9 && str_starts_with($digits, '9')) {
            return '+251'.$digits;
        }

        return $digits;
    }

    /** @return array<string, array{0:string, 1:Closure(User):bool, 2:Closure(Q, array<string, mixed>):list<array<string, mixed>>}> */
    private function groups(): array
    {
        return [
            'members' => ['Members', fn (User $s) => $s->can('manage-members'), fn ($q, $f) => $this->members($q, $f)],
            'orders' => ['Orders', fn (User $s) => $s->can('manage-orders') && Features::enabled('shop'), fn ($q, $f) => $this->orders($q, $f)],
            'products' => ['Products', fn (User $s) => $s->can('manage-products') && Features::enabled('shop'), fn ($q, $f) => $this->products($q, $f)],
            'payments' => ['Payments and receipts', fn (User $s) => $s->can('view-payments'), fn ($q, $f) => $this->payments($q, $f)],
            'cashouts' => ['Cash-outs', fn (User $s) => $s->can('approve-cashouts') && Features::enabled('wallet'), fn ($q, $f) => $this->cashouts($q, $f)],
            'signals' => ['Signals', fn (User $s) => $s->can('manage-signals') && Features::enabled('signals'), fn ($q, $f) => $this->signals($q, $f)],
            'coupons' => ['Coupons', fn (User $s) => $s->can('manage-products') && Features::enabled('shop.coupons'), fn ($q, $f) => $this->coupons($q, $f)],
            'badges' => ['Badges', fn (User $s) => $s->can('manage-settings') && Features::enabled('badges'), fn ($q, $f) => $this->badges($q)],
            'settings' => ['Settings', fn (User $s) => $s->can('manage-settings'), fn ($q, $f) => $this->settings($q)],
        ];
    }

    /**
     * Text match on indexed columns: exact phrase = equal, otherwise first letters.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $b
     * @param  Q  $q
     * @param  list<string>  $cols
     * @return Builder<TModel>
     */
    private function match(Builder $b, array $q, array $cols): Builder
    {
        if ($q['id'] !== null) {
            return $b->whereKey($q['id']);
        }

        return $b->where(function (Builder $w) use ($q, $cols) {
            foreach ($cols as $col) {
                $q['exact'] ? $w->orWhere($col, stripslashes($q['text'])) : $w->orWhere($col, 'like', $q['text'].'%');
            }
        });
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $b
     * @param  array<string, mixed>  $f
     * @return Builder<TModel>
     */
    private function filters(Builder $b, array $f, ?string $amount = null, string $date = 'created_at', bool $status = true): Builder
    {
        return $b
            ->when($status && ! empty($f['status']), fn ($x) => $x->where('status', $f['status']))
            ->when(! empty($f['from']), fn ($x) => $x->where($date, '>=', Carbon::parse((string) $f['from'])->startOfDay()))
            ->when(! empty($f['to']), fn ($x) => $x->where($date, '<=', Carbon::parse((string) $f['to'])->endOfDay()))
            ->when($amount && isset($f['min']), fn ($x) => $x->where($amount, '>=', (int) $f['min']))
            ->when($amount && isset($f['max']), fn ($x) => $x->where($amount, '<=', (int) $f['max']));
    }

    /**
     * @param  Q  $q
     * @param  array<string, mixed>  $f
     * @return list<array<string, mixed>>
     */
    private function members(array $q, array $f): array
    {
        $b = User::query()->where('role', User::ROLE_CUSTOMER);
        if ($q['phone'] && $q['id'] === null) {
            $b->where('phone', 'like', $q['phone'].'%');
        } else {
            $parts = preg_split('/\s+/', stripslashes($q['text'])) ?: [];
            if (! $q['exact'] && $q['id'] === null && count($parts) === 2) {
                // "Abebe Kebede": first and last name.
                $b->where('first_name', 'like', addcslashes($parts[0], '%_\\').'%')->where('last_name', 'like', addcslashes($parts[1], '%_\\').'%');
            } else {
                $this->match($b, $q, ['first_name', 'last_name', 'email', 'referral_code']);
            }
        }
        $this->filters($b, $f, null, 'created_at', false);

        return $b->with('membership:id,user_id,status')->limit(self::LIMIT)->get(['id', 'first_name', 'last_name', 'phone', 'email', 'referral_code', 'status'])
            ->map(fn (User $u) => ['id' => $u->id, 'title' => trim($u->first_name.' '.$u->last_name),
                'sub' => implode(' · ', array_filter([$u->phone, $u->referral_code, $u->membership->status ?? 'no membership'])),
                'url' => '/members/'.$u->id])->all();
    }

    /**
     * @param  Q  $q
     * @param  array<string, mixed>  $f
     * @return list<array<string, mixed>>
     */
    private function orders(array $q, array $f): array
    {
        $b = Order::query();
        if ($q['phone'] && $q['id'] === null) {
            // Digits: a phone, or the digits of an order number (KM261115-…).
            $b->where(fn ($w) => $w->where('phone', 'like', $q['phone'].'%')->orWhere('number', 'like', 'KM'.$q['text'].'%'));
        } else {
            $this->match($b, ['text' => strtoupper($q['text'])] + $q, ['number', 'first_name']);
        }
        $this->filters($b, $f, 'total');

        return $b->latest('id')->limit(self::LIMIT)->get(['id', 'number', 'first_name', 'last_name', 'phone', 'total', 'status'])
            ->map(fn (Order $o) => ['id' => $o->id, 'title' => $o->number, 'sub' => trim("{$o->first_name} {$o->last_name}").' · '.Money::format($o->total).' · '.str_replace('_', ' ', $o->status),
                'url' => '/orders/'.$o->id])->all();
    }

    /**
     * @param  Q  $q
     * @param  array<string, mixed>  $f
     * @return list<array<string, mixed>>
     */
    private function products(array $q, array $f): array
    {
        $b = Product::query();
        if ($q['id'] !== null) {
            $b->whereKey($q['id']);
        } else {
            $op = $q['exact'] ? '=' : 'like';
            $v = $q['exact'] ? stripslashes($q['text']) : $q['text'].'%';
            $b->where(fn ($w) => $w->where('name', $op, $v)->orWhere('slug', $op, $v)
                ->orWhereHas('variants', fn ($x) => $x->where('sku', $op, $v)));
        }
        $this->filters($b, $f, 'price');

        return $b->limit(self::LIMIT)->get(['id', 'name', 'price', 'status'])
            ->map(fn (Product $p) => ['id' => $p->id, 'title' => $p->name, 'sub' => Money::format($p->price).' · '.$p->status, 'url' => '/products/'.$p->id])->all();
    }

    /**
     * @param  Q  $q
     * @param  array<string, mixed>  $f
     * @return list<array<string, mixed>>
     */
    private function payments(array $q, array $f): array
    {
        $b = $this->match(Payment::query(), $q, ['tx_ref', 'provider_reference', 'proof_reference']);
        $this->filters($b, $f, 'amount');

        return $b->latest('id')->limit(self::LIMIT)->get(['id', 'tx_ref', 'provider', 'amount', 'status', 'payable_type', 'payable_id'])
            ->map(fn (Payment $p) => ['id' => $p->id, 'title' => $p->tx_ref, 'sub' => str_replace('_', ' ', $p->provider).' · '.Money::format($p->amount).' · '.str_replace('_', ' ', $p->status),
                'url' => '/payments?open='.$p->id])->all();
    }

    /**
     * @param  Q  $q
     * @param  array<string, mixed>  $f
     * @return list<array<string, mixed>>
     */
    private function cashouts(array $q, array $f): array
    {
        $b = PayoutRequest::query()->with('user:id,first_name,last_name,phone');
        if ($q['phone'] && $q['id'] === null) {
            $b->whereHas('user', fn ($u) => $u->where('phone', 'like', $q['phone'].'%'));
        } else {
            $this->match($b, $q, ['payout_reference']);
        }
        $this->filters($b, $f, 'amount');

        return $b->latest('id')->limit(self::LIMIT)->get()
            ->map(fn (PayoutRequest $r) => ['id' => $r->id, 'title' => Money::format($r->amount).' to '.trim(($r->user->first_name ?? '').' '.($r->user->last_name ?? '')),
                'sub' => implode(' · ', array_filter([$r->status === 'on_hold' ? 'requested' : $r->status, $r->payout_reference, $r->created_at?->format('d M Y')])),
                'url' => '/cash-outs'])->all();
    }

    /**
     * @param  Q  $q
     * @param  array<string, mixed>  $f
     * @return list<array<string, mixed>>
     */
    private function signals(array $q, array $f): array
    {
        $b = $this->match(Signal::query(), ['text' => strtoupper($q['text'])] + $q, ['pair']);
        $this->filters($b, $f, null, 'posted_at');

        return $b->latest('id')->limit(self::LIMIT)->get(['id', 'pair', 'direction', 'status', 'result', 'posted_at'])
            ->map(fn (Signal $s) => ['id' => $s->id, 'title' => $s->pair.' '.strtoupper((string) $s->direction),
                'sub' => ($s->result ?? $s->status).' · '.$s->posted_at?->format('d M Y'), 'url' => '/signals'])->all();
    }

    /**
     * @param  Q  $q
     * @param  array<string, mixed>  $f
     * @return list<array<string, mixed>>
     */
    private function coupons(array $q, array $f): array
    {
        $b = $this->match(Coupon::query(), ['text' => strtoupper($q['text'])] + $q, ['code']);
        $this->filters($b, $f, null, 'created_at', false);

        return $b->limit(self::LIMIT)->get(['id', 'code', 'type', 'value', 'is_active'])
            ->map(fn (Coupon $c) => ['id' => $c->id, 'title' => $c->code, 'sub' => ($c->type === 'percent' ? ($c->value / 100).'%' : Money::format($c->value)).' off · '.($c->is_active ? 'active' : 'off'),
                'url' => '/coupons'])->all();
    }

    /**
     * @param  Q  $q
     * @return list<array<string, mixed>>
     */
    private function badges(array $q): array
    {
        return $this->match(Badge::query(), $q, ['name', 'key'])->limit(self::LIMIT)->get(['id', 'name', 'description', 'is_active'])
            ->map(fn (Badge $b) => ['id' => $b->id, 'title' => $b->name, 'sub' => $b->description.($b->is_active ? '' : ' (off)'), 'url' => '/badges'])->all();
    }

    /**
     * Settings names and feature switches (from config, no database).
     *
     * @param  Q  $q
     * @return list<array<string, mixed>>
     */
    private function settings(array $q): array
    {
        if ($q['id'] !== null) {
            return [];
        }
        $words = array_filter(preg_split('/\s+/', mb_strtolower(stripslashes($q['text']))) ?: []);
        $hit = fn (string $text) => $words !== [] && collect($words)->every(fn ($w) => str_contains(mb_strtolower($text), $w));
        $out = [];
        foreach (config('settings') as $key => $def) {
            if ($hit($def[2].' '.$key.' '.$def[3])) {
                $out[] = ['id' => $key, 'title' => $def[2], 'sub' => 'Setting · '.$def[3], 'url' => '/settings'];
            }
        }
        foreach (config('features') as $key => $def) {
            if ($hit($def[0].' '.$key.' '.$def[1].' '.($def[4] ?? ''))) {
                $out[] = ['id' => $key, 'title' => $def[0], 'sub' => 'Feature switch · '.(Features::enabled($key) ? 'on' : 'off'), 'url' => '/features?q='.rawurlencode($def[0])];
            }
        }

        return array_slice($out, 0, self::LIMIT);
    }
}
