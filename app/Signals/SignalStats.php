<?php

namespace App\Signals;

use App\Models\Signal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Win rate overall and month by month, optionally for one pair.
 * "Win rate" = wins ÷ (wins + losses); "no gain, no loss" doesn't count.
 */
class SignalStats
{
    /** @return list<string> pairs with at least one closed signal */
    public function pairs(): array
    {
        return Cache::remember('signals.pairs', 600, fn () => Signal::query()->where('status', 'closed')
            ->distinct()->orderBy('pair')->pluck('pair')->map(fn ($p) => (string) $p)->all());
    }

    /** A pair from the query string, only if it is a real one. */
    public function validPair(?string $pair): ?string
    {
        return $pair !== null && in_array($pair, $this->pairs(), true) ? $pair : null;
    }

    /** @return array{wins:int, losses:int, breakeven:int, closed:int, win_rate:?float} */
    public function totals(?string $pair = null): array
    {
        $rows = Signal::query()->where('status', 'closed')->when($pair, fn ($q, $p) => $q->where('pair', $p))
            ->selectRaw('result, COUNT(*) as c')->groupBy('result')->pluck('c', 'result');
        $wins = (int) ($rows['win'] ?? 0);
        $losses = (int) ($rows['loss'] ?? 0);
        $be = (int) ($rows['breakeven'] ?? 0);

        return ['wins' => $wins, 'losses' => $losses, 'breakeven' => $be, 'closed' => $wins + $losses + $be,
            'win_rate' => $wins + $losses > 0 ? round($wins * 100 / ($wins + $losses), 1) : null];
    }

    /** @return list<array{label:string, wins:int, losses:int, rate:?int}> oldest first */
    public function monthly(?string $pair = null, int $months = 12): array
    {
        $tz = (string) config('app.timezone');
        $from = now()->timezone($tz)->subMonthsNoOverflow($months - 1)->startOfMonth();
        $byMonth = Signal::query()->where('status', 'closed')->whereIn('result', ['win', 'loss'])
            ->where('closed_at', '>=', $from)->when($pair, fn ($q, $p) => $q->where('pair', $p))
            ->get(['closed_at', 'result'])
            ->groupBy(fn (Signal $s) => Carbon::parse($s->closed_at)->timezone($tz)->format('Y-m'));
        $out = [];
        for ($m = $from->copy(); count($out) < $months; $m->addMonthNoOverflow()) {
            $g = $byMonth->get($m->format('Y-m'), collect());
            $w = $g->where('result', 'win')->count();
            $l = $g->where('result', 'loss')->count();
            $out[] = ['label' => $m->format('M'), 'wins' => $w, 'losses' => $l, 'rate' => $w + $l > 0 ? (int) round($w * 100 / ($w + $l)) : null];
        }

        return $out;
    }
}
