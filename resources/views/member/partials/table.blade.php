{{--
    The member-area data table. $table: App\Member\MemberTable (filled by fromRows / fromQuery).
    Rows: arrays keyed by column; optional _id, _url (row link), _html[key] (custom cell HTML).
--}}
@php
    use App\Badges\BadgeIcons as I;
    $t = $table;
    $rows = $t->page->items();
    $sort = $t->state['sort'];
    $count = $t->activeCount();
    $hasControls = $t->filters || $t->sortable();
    $cell = function (string $key, array $row) use ($t) {
        if (isset($row['_html'][$key])) {
            return $row['_html'][$key];
        }
        $type = $t->columns[$key][1] ?? 'text';
        $text = e($t->plain($key, $row[$key] ?? ''));
        if ($type === 'status' && ($row[$key] ?? '') !== '') {
            $tone = in_array($row[$key], ['available', 'paid', 'delivered', 'active', 'win'], true) ? 'm-soft m-accent-text'
                : (in_array($row[$key], ['rejected', 'reversed', 'cancelled', 'refunded', 'loss', 'reduced', 'not_active'], true) ? 'bg-red-500/10 text-red-700 dark:text-red-300' : 'bg-black/5 dark:bg-white/10');

            return new \Illuminate\Support\HtmlString('<span class="inline-block rounded-full px-2 py-0.5 text-xs font-semibold '.$tone.'">'.$text.'</span>');
        }

        return new \Illuminate\Support\HtmlString($text);
    };
@endphp
<div class="grid gap-3" data-mtable="{{ $t->id }}" data-mtable-keys="{{ implode(',', array_map(fn ($k) => $t->p($k), array_merge(['q', 'sort', 'per_page'], array_keys($t->filters)))) }}" data-mtable-page="{{ $t->p('page') }}">
    <form method="get" class="flex flex-wrap items-center gap-2" data-mtable-form>
        @if ($t->searchPlaceholder)
            <label class="relative w-full sm:w-64">
                <span class="sr-only">{{ $t->searchPlaceholder }}</span>
                <span class="pointer-events-none absolute start-3 top-1/2 -translate-y-1/2 m-muted">{!! I::svg('search', 16) !!}</span>
                <input type="search" name="{{ $t->p('q') }}" value="{{ $t->state['q'] }}" placeholder="{{ $t->searchPlaceholder }}" class="m-input min-h-12 ps-9" autocomplete="off">
            </label>
        @endif
        @if ($hasControls)
            {{-- Phones: folded, with a count, remembered per screen. Desktop: always open. --}}
            <details class="w-full sm:w-auto" data-collapse="{{ $t->id }}-filters">
                <summary class="m-btn-ghost min-h-12 cursor-pointer list-none sm:hidden">{!! I::svg('sliders-horizontal', 16) !!}{{ $t->filters ? 'Filters' : 'Sort' }}@if ($count) ({{ $count }})@endif</summary>
                <div class="mt-2 grid gap-2 sm:mt-0 sm:flex sm:flex-wrap">
                    @foreach ($t->filters as $key => $f)
                        <label><span class="sr-only">{{ $f['label'] }}</span>
                            <select name="{{ $t->p($key) }}" class="m-input min-h-12 sm:min-h-10" data-autosubmit>
                                <option value="">{{ $f['label'] }}: all</option>
                                @foreach ($f['options'] as $v => $label)<option value="{{ $v }}" @selected(($t->state['filters'][$key] ?? '') === (string) $v)>{{ $label }}</option>@endforeach
                            </select>
                        </label>
                    @endforeach
                    @if ($t->sortable())
                        <label class="md:hidden"><span class="sr-only">Sort</span>
                            <select name="{{ $t->p('sort') }}" class="m-input min-h-12 sm:min-h-10" data-autosubmit>
                                <option value="">Sort: newest first</option>
                                @foreach ($t->sortable() as $s)
                                    <option value="{{ $s['key'] }}" @selected($sort === $s['key'])>{{ $s['label'] }}: low to high</option>
                                    <option value="-{{ $s['key'] }}" @selected($sort === '-'.$s['key'])>{{ $s['label'] }}: high to low</option>
                                @endforeach
                            </select>
                        </label>
                    @endif
                </div>
            </details>
        @endif
        <input type="hidden" name="{{ $t->p('per_page') }}" value="{{ $t->state['per_page'] }}" data-mtable-per-page-field>
        @foreach (request()->except(array_merge(array_map(fn ($k) => $t->p($k), array_merge(['q', 'sort', 'per_page', 'page'], array_keys($t->filters))), ['export'])) as $k => $v)
            @if (is_string($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
        @endforeach
        <div class="ms-auto flex flex-wrap gap-2">
            <details class="relative">
                <summary class="m-btn-ghost min-h-12 cursor-pointer list-none sm:min-h-10">{!! I::svg('columns-3', 16) !!}Columns</summary>
                <div class="absolute end-0 z-20 mt-2 grid w-56 gap-1 rounded-xl border p-2 text-sm shadow-lg" style="background: var(--m-card); border-color: var(--m-line)">
                    @foreach ($t->columns as $key => $c)
                        <label class="flex min-h-11 items-center gap-2 rounded-lg px-2"><input type="checkbox" class="size-5" data-col-toggle="{{ $key }}" @checked(! ($c[4] ?? false))> {{ $c[0] }}</label>
                    @endforeach
                    <a href="{{ $t->url(array_fill_keys(array_map(fn ($k) => $t->p($k), array_merge(['q', 'sort', 'per_page', 'page'], array_keys($t->filters))), null)) }}" class="flex min-h-11 items-center gap-2 rounded-lg px-2 m-muted" data-mtable-reset>{!! I::svg('rotate-ccw', 16) !!}Reset this table</a>
                </div>
            </details>
            <a href="{{ $t->csvUrl() }}" class="m-btn-ghost min-h-12 sm:min-h-10" download>{!! I::svg('download', 16) !!}CSV</a>
        </div>
        <noscript><button class="m-btn min-h-12">Show</button></noscript>
    </form>

    <div class="hidden flex-wrap items-center gap-2 rounded-xl px-3 py-2 text-sm m-soft" data-mtable-bulk>
        <b data-mtable-count>0</b> selected
        <button type="button" class="m-btn-ghost min-h-10" data-mtable-csv>{!! I::svg('download', 16) !!}CSV of selected</button>
        <button type="button" class="m-btn-ghost min-h-10" data-mtable-clear>Clear</button>
    </div>

    @if (! count($rows))
        <p class="rounded-xl p-6 text-center text-sm m-muted m-soft">{{ $t->state['q'] || $t->state['filters'] ? 'Nothing matches. Try other words or filters.' : $t->empty }}</p>
    @else
        {{-- Tablet and desktop: table with a sticky header --}}
        <div class="hidden max-h-[70vh] overflow-auto rounded-xl border md:block" style="border-color: var(--m-line)">
            <table class="w-full text-sm">
                <thead class="sticky top-0 z-10 text-start text-xs m-muted" style="background: var(--m-card)">
                    <tr>
                        <th class="w-10 px-3 py-2 text-start"><input type="checkbox" class="size-5" aria-label="Select all on this page" data-mtable-all></th>
                        @foreach ($t->columns as $key => $c)
                            @php $dir = $sort === $key ? 'asc' : ($sort === '-'.$key ? 'desc' : null); @endphp
                            <th data-col="{{ $key }}" @class(['whitespace-nowrap px-3 py-2 font-semibold', 'text-end' => ($c[3] ?? '') === 'end', 'text-start' => ($c[3] ?? '') !== 'end', 'hidden' => $c[4] ?? false]) @if ($dir) aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif>
                                @if ($c[2] ?? false)
                                    <a href="{{ $t->url([$t->p('sort') => $dir === 'asc' ? '-'.$key : ($dir === 'desc' ? null : $key), $t->p('page') => null]) }}" class="inline-flex min-h-9 items-center gap-1 hover:underline">{{ $c[0] }}<span aria-hidden="true">{{ $dir === 'asc' ? '↑' : ($dir === 'desc' ? '↓' : '↕') }}</span></a>
                                @else
                                    {{ $c[0] }}
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr class="border-t" style="border-color: var(--m-line)" data-mtable-row="{{ $row['_id'] ?? $loop->index }}">
                            <td class="px-3 py-2"><input type="checkbox" class="size-5" aria-label="Select row" data-mtable-select></td>
                            @foreach ($t->columns as $key => $c)
                                <td data-col="{{ $key }}" data-csv="{{ \App\Member\MemberTable::safe($t->plain($key, $row[$key] ?? '')) }}" @class(['px-3 py-2', 'text-end tabular-nums whitespace-nowrap' => ($c[3] ?? '') === 'end', 'hidden' => $c[4] ?? false])>
                                    @if ($loop->first && isset($row['_url']))<a class="font-medium hover:underline" href="{{ $row['_url'] }}">{{ $cell($key, $row) }}</a>@else{{ $cell($key, $row) }}@endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Phones: one card per row --}}
        <ul class="grid gap-2 md:hidden">
            @foreach ($rows as $row)
                @php $keys = array_keys($t->columns); $first = array_shift($keys); @endphp
                <li class="rounded-xl border p-3 text-sm" style="border-color: var(--m-line)" data-mtable-row="{{ $row['_id'] ?? $loop->index }}">
                    <div class="flex min-h-12 items-center gap-3">
                        <label class="grid size-12 shrink-0 place-items-center"><span class="sr-only">Select row</span><input type="checkbox" class="size-6" data-mtable-select></label>
                        <div class="min-w-0 flex-1 font-medium break-words" data-col="{{ $first }}" data-csv="{{ \App\Member\MemberTable::safe($t->plain($first, $row[$first] ?? '')) }}">
                            @if (isset($row['_url']))<a class="hover:underline" href="{{ $row['_url'] }}">{{ $cell($first, $row) }}</a>@else{{ $cell($first, $row) }}@endif
                        </div>
                    </div>
                    <dl class="mt-1 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                        @foreach ($keys as $key)
                            <div @class(['contents', 'hidden' => $t->columns[$key][4] ?? false]) data-col="{{ $key }}" data-csv="{{ \App\Member\MemberTable::safe($t->plain($key, $row[$key] ?? '')) }}">
                                <dt class="m-muted">{{ $t->columns[$key][0] }}</dt>
                                <dd class="min-w-0 text-end break-words tabular-nums">{{ $cell($key, $row) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </li>
            @endforeach
        </ul>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-2 text-sm m-muted">
        <span aria-live="polite">@if ($t->page->total()){{ $t->page->firstItem() }}–{{ $t->page->lastItem() }} of {{ $t->page->total() }}@else 0 rows @endif</span>
        <div class="flex items-center gap-2">
            <label><span class="sr-only">Rows per page</span>
                <select class="m-input min-h-12 sm:min-h-10" data-mtable-per-page>
                    @foreach (\App\Member\MemberTable::PAGE_SIZES as $n)<option value="{{ $n }}" @selected($t->state['per_page'] === $n)>{{ $n }} a page</option>@endforeach
                </select>
            </label>
            @if ($t->page->onFirstPage())<span class="m-btn-ghost min-h-12 opacity-40 sm:min-h-10" aria-disabled="true">←</span>@else<a class="m-btn-ghost min-h-12 sm:min-h-10" href="{{ $t->page->previousPageUrl() }}" aria-label="Previous page">←</a>@endif
            <span class="tabular-nums">{{ $t->page->currentPage() }}/{{ $t->page->lastPage() }}</span>
            @if ($t->page->hasMorePages())<a class="m-btn-ghost min-h-12 sm:min-h-10" href="{{ $t->page->nextPageUrl() }}" aria-label="Next page">→</a>@else<span class="m-btn-ghost min-h-12 opacity-40 sm:min-h-10" aria-disabled="true">→</span>@endif
        </div>
    </div>
</div>
