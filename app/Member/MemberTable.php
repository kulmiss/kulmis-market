<?php

namespace App\Member;

use App\Support\Money;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One table in the member area (same features as the admin data table):
 * search, sort by column, filters, show/hide columns (in the browser),
 * page size, server paging, CSV of everything or of selected rows, saved
 * settings per table (in the browser), cards on phones.
 *
 * Columns: key => [label, type, sortable?, align?, hidden?]. Types:
 * text, money, date, datetime, status, number. Rows are plain arrays keyed
 * by column key; "_id" identifies a row for selection.
 */
final class MemberTable
{
    public const PAGE_SIZES = [10, 25, 50, 100];

    public const EXPORT_LIMIT = 10_000;

    /** Easy words for statuses members see. */
    public const STATUS = [
        'pending' => 'On the way', 'available' => 'Ready to use', 'reversed' => 'Taken back', 'skipped' => 'Not paid',
        'requested' => 'Asked', 'on_hold' => 'Asked', 'paid' => 'Paid', 'rejected' => 'Not approved',
        'pending_payment' => 'Waiting for payment', 'making' => 'Being made', 'shipped' => 'On its way', 'delivered' => 'Delivered',
        'cancelled' => 'Cancelled', 'refunded' => 'Money given back', 'partially_refunded' => 'Part given back',
        'active' => 'Active', 'not_active' => 'Not active', 'open' => 'Open', 'closed' => 'Closed', 'win' => 'Win', 'loss' => 'Loss',
        'breakeven' => 'No gain, no loss', 'reduced' => 'Taken back',
    ];

    /** @var array{q:string, sort:string, per_page:int, filters: array<string, string>} */
    public array $state = ['q' => '', 'sort' => '', 'per_page' => 25, 'filters' => []];

    /** @var LengthAwarePaginator<int, array<string, mixed>> */
    public LengthAwarePaginator $page;

    /** @var Collection<int, array<string, mixed>> */
    private Collection $all;

    /** @var (Closure(): Collection<int, array<string, mixed>>)|null */
    private ?Closure $exportQuery = null;

    /**
     * @param  array<string, array{0:string, 1:string, 2?:bool, 3?:string, 4?:bool}>  $columns
     * @param  array<string, array{label: string, options: array<int|string, string>}>  $filters
     */
    public function __construct(
        public readonly string $id,
        public readonly array $columns,
        public readonly array $filters = [],
        public readonly ?string $searchPlaceholder = null,
        public readonly string $defaultSort = '',
        public readonly string $empty = 'Nothing here yet.',
        /** Needed when a page has two tables: "c_" makes ?c_q=, ?c_sort= … */
        public readonly string $prefix = '',
    ) {}

    /** The address parameter for one setting of this table. */
    public function p(string $name): string
    {
        return $this->prefix.$name;
    }

    /**
     * All rows are here already (a member's own lists are small): search,
     * filter, sort and page in PHP, show one page.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    public function fromRows(Request $request, Collection $rows): self
    {
        $this->readState($request);
        $words = array_filter(preg_split('/\s+/', mb_strtolower($this->state['q'])) ?: []);
        $rows = $rows->filter(function (array $row) use ($words) {
            foreach ($this->state['filters'] as $key => $value) {
                if ((string) ($row[$key] ?? '') !== $value) {
                    return false;
                }
            }
            if ($words === []) {
                return true;
            }
            $text = mb_strtolower(implode(' ', array_map(fn ($k) => $this->plain($k, $row[$k] ?? ''), array_keys($this->columns))));

            return collect($words)->every(fn ($w) => str_contains($text, $w));
        });
        if ($this->state['sort'] !== '') {
            $key = ltrim($this->state['sort'], '-');
            $rows = $rows->sortBy(fn (array $r) => $r[$key] ?? null, SORT_REGULAR, str_starts_with($this->state['sort'], '-'));
        }
        $this->all = $rows->values();
        $current = max(1, (int) $request->query($this->p('page'), 1));
        $this->page = new LengthAwarePaginator($this->all->forPage($current, $this->state['per_page'])->values(), $this->all->count(),
            $this->state['per_page'], $current, ['path' => $request->url(), 'query' => $request->query(), 'pageName' => $this->p('page')]);

        return $this;
    }

    /**
     * Big lists: the database searches, sorts and pages.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, string>  $sortMap  column key => database column
     * @param  Closure(Builder<TModel>, string): mixed  $search
     * @param  Closure(TModel): array<string, mixed>  $map
     * @param  array<string, string|Closure(Builder<TModel>, string): mixed>  $filterColumns  filter key => database column (or a closure)
     */
    public function fromQuery(Request $request, Builder $query, array $sortMap, Closure $search, Closure $map, array $filterColumns = []): self
    {
        $this->readState($request);
        foreach ($this->state['filters'] as $key => $value) {
            $f = $filterColumns[$key] ?? null;
            if ($f instanceof Closure) {
                $f($query, $value);
            } elseif (is_string($f)) {
                $query->where($f, $value);
            }
        }
        if ($this->state['q'] !== '') {
            $search($query, $this->state['q']);
        }
        $sort = $this->state['sort'];
        $col = $sortMap[ltrim($sort, '-')] ?? null;
        $id = $query->getModel()->getQualifiedKeyName();
        $col ? $query->orderBy($col, str_starts_with($sort, '-') ? 'desc' : 'asc')->orderByDesc($id) : $query->orderByDesc($id);
        $this->all = collect();
        $this->exportQuery = fn () => (clone $query)->limit(self::EXPORT_LIMIT)->get()->map($map);
        $page = $query->paginate($this->state['per_page'], ['*'], $this->p('page'))->withQueryString();
        $page->setCollection($page->getCollection()->map($map));
        $this->page = $page;

        return $this;
    }

    public function wantsCsv(Request $request): bool
    {
        return $request->query('export') === $this->id;
    }

    public function csvUrl(): string
    {
        return $this->url(['export' => $this->id]);
    }

    /** Everything that matches the search and filters (not only this page). */
    public function csv(): StreamedResponse
    {
        $rows = $this->exportQuery ? ($this->exportQuery)() : $this->all->take(self::EXPORT_LIMIT);
        $name = $this->id.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, array_map(fn ($c) => $c[0], $this->columns), escape: '');
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($k) => self::safe($this->plain($k, $row[$k] ?? '')), array_keys($this->columns)), escape: '');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=utf-8', 'Cache-Control' => 'private, no-store']);
    }

    /** What a cell shows, as text (also the CSV value). */
    public function plain(string $key, mixed $value): string
    {
        $type = $this->columns[$key][1] ?? 'text';
        if ($value === null || $value === '') {
            return '';
        }

        return match ($type) {
            'money' => Money::format((int) $value),
            'date' => Carbon::parse((string) $value)->timezone(config('app.timezone'))->format('j M Y'),
            'datetime' => Carbon::parse((string) $value)->timezone(config('app.timezone'))->format('j M Y H:i'),
            'status' => self::STATUS[(string) $value] ?? ucfirst(str_replace('_', ' ', (string) $value)),
            default => (string) $value,
        };
    }

    /** Spreadsheets must not run data as a formula. */
    public static function safe(string $cell): string
    {
        return preg_match('/^[=+\-@\t\r]/', $cell) && ! preg_match('/^-?\d+([.,]\d+)?$/', $cell) ? "'".$cell : $cell;
    }

    /** How many filters (and a sort) are on: "Filters (2)". */
    public function activeCount(): int
    {
        return count($this->state['filters']) + ($this->state['sort'] !== '' && $this->state['sort'] !== $this->defaultSort ? 1 : 0);
    }

    /** @return list<array{key:string, label:string}> */
    public function sortable(): array
    {
        $out = [];
        foreach ($this->columns as $key => $c) {
            if ($c[2] ?? false) {
                $out[] = ['key' => $key, 'label' => $c[0]];
            }
        }

        return $out;
    }

    /**
     * URL of this page with some table settings changed.
     *
     * @param  array<string, string|int|null>  $changes
     */
    public function url(array $changes): string
    {
        $query = request()->query();
        unset($query['export']);
        $query = array_filter(array_merge($query, $changes), fn ($v) => $v !== null && $v !== '');

        return request()->url().($query ? '?'.http_build_query($query) : '');
    }

    private function readState(Request $request): void
    {
        $q = $request->query($this->p('q'));
        $sort = $request->query($this->p('sort'));
        $per = (int) $request->query($this->p('per_page'), 25);
        $filters = [];
        foreach ($this->filters as $key => $f) {
            $v = $request->query($this->p($key));
            if (is_string($v) && $v !== '' && $v !== 'all' && array_key_exists($v, $f['options'])) {
                $filters[$key] = $v;
            }
        }
        $sortKey = is_string($sort) ? ltrim($sort, '-') : '';
        $this->state = [
            'q' => is_string($q) ? mb_substr(trim($q), 0, 80) : '',
            'sort' => is_string($sort) && ($this->columns[$sortKey][2] ?? false) ? $sort : $this->defaultSort,
            'per_page' => in_array($per, self::PAGE_SIZES, true) ? $per : 25,
            'filters' => $filters,
        ];
    }
}
