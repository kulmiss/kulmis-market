import { useEffect, useMemo, useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import {
  type ColumnDef,
  type RowSelectionState,
  type ColumnVisibilityState,
  columnVisibilityFeature,
  flexRender,
  rowSelectionFeature,
  tableFeatures,
  useTable,
} from '@tanstack/react-table'
import { ArrowDown, ArrowUp, ArrowUpDown, ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight, Columns3, Download, RotateCcw, Search, SlidersHorizontal } from 'lucide-react'
import { toast } from 'sonner'
import { api, errorMessage, type Paginated } from '@/lib/api'
import { downloadCsv, toCsv } from '@/lib/csv'
import { useSavedView } from '@/lib/saved-view'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
  DropdownMenu, DropdownMenuCheckboxItem, DropdownMenuContent, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'

/** Features every table uses. Sorting, filtering and paging run on the server (or in DataTable for local rows). */
const features = tableFeatures({ rowSelectionFeature, columnVisibilityFeature })

/**
 * Extra column settings:
 * - label: column name in "Columns", CSV and phone cards (defaults to a string header)
 * - sort: server sort key (?sort=key / -key); local tables sort by row[sort]
 * - csv: value for the CSV file (defaults to the accessor value)
 * - align: 'end' for numbers
 * - hidden: hidden until the user shows it
 */
export type ColumnMeta<T> = { label?: string; sort?: string; csv?: (row: T) => unknown; align?: 'end'; hidden?: boolean; card?: 'title' | 'hide' }

export type DataColumn<T extends object> = ColumnDef<typeof features, T, unknown> & { meta?: ColumnMeta<T> }

/** default: starting value; sendAll: send "all" to the server (when it has its own default). */
export type FilterDef = { key: string; label: string; options: { value: string; label: string }[]; all?: string; default?: string; sendAll?: boolean }

type View = { hidden: string[]; pageSize: number; sort: string; filters: Record<string, string>; filtersOpen: boolean }

type Props<T extends object> = {
  /** Saved view key: column choice, page size, sort and filters are kept per table in this browser. */
  id: string
  columns: DataColumn<T>[]
  /** Server mode: a Laravel paginator endpoint. */
  path?: string
  /** Local mode: all rows are already here. */
  rows?: T[]
  loading?: boolean
  params?: Record<string, string | number | boolean | undefined | null>
  search?: { placeholder: string; param?: string } | false
  filters?: FilterDef[]
  defaultSort?: string
  pageSizes?: number[]
  defaultPageSize?: number
  selectable?: boolean
  bulkActions?: (rows: T[], clear: () => void) => React.ReactNode
  onRowClick?: (row: T) => void
  empty?: string
  rowId?: (row: T) => string
  exportName?: string
  /** Extra toolbar buttons. */
  actions?: React.ReactNode
}

const clean = (o: Record<string, unknown>) => Object.fromEntries(Object.entries(o).filter(([, v]) => v !== '' && v !== undefined && v !== null && v !== 'all'))

function colId<T extends object>(c: DataColumn<T>, i: number): string {
  const c2 = c as { id?: string; accessorKey?: string; header?: unknown }
  return c2.id ?? c2.accessorKey ?? (typeof c2.header === 'string' ? c2.header : `col${i}`)
}

function colLabel<T extends object>(c: DataColumn<T>, id: string): string {
  return c.meta?.label ?? (typeof c.header === 'string' ? c.header : id)
}

function cellValue<T extends object>(c: DataColumn<T>, row: T): unknown {
  if (c.meta?.csv) return c.meta.csv(row)
  const key = (c as { accessorKey?: string }).accessorKey
  return key ? (row as Record<string, unknown>)[key] : undefined
}

/** Debounced value (search as you type, one request per pause). */
function useDebounced<V>(value: V, ms = 300): V {
  const [v, setV] = useState(value)
  useEffect(() => {
    const t = setTimeout(() => setV(value), ms)
    return () => clearTimeout(t)
  }, [value, ms])
  return v
}

/**
 * The one table for the whole admin: search, sort by column, filters,
 * show/hide columns, page size, row selection + bulk actions, CSV export,
 * sticky header, saved settings per table, server paging. Phones get cards.
 */
export function DataTable<T extends object>({
  id, columns, path, rows, loading, params = {}, search = { placeholder: 'Search…' }, filters = [], defaultSort = '',
  pageSizes = [10, 25, 50, 100, 200], defaultPageSize = 25, selectable, bulkActions, onRowClick, empty = 'Nothing here yet.',
  rowId, exportName, actions,
}: Props<T>) {
  const ids = useMemo(() => columns.map((c, i) => colId(c, i)), [columns])
  const initial: View = useMemo(() => ({
    hidden: columns.flatMap((c, i) => (c.meta?.hidden ? [ids[i]] : [])), pageSize: defaultPageSize, sort: defaultSort,
    filters: {}, filtersOpen: false,
  }), [columns, ids, defaultPageSize, defaultSort])
  const [view, setView, resetView] = useSavedView<View>(`table.${id}`, initial)
  const [q, setQ] = useState('')
  const dq = useDebounced(q)
  const [exporting, setExporting] = useState(false)
  const searchParam = search ? (search.param ?? 'q') : 'q'

  const filterValues = useMemo(() => Object.fromEntries(filters.map((f) => [f.key, view.filters[f.key] ?? f.default ?? 'all'])), [filters, view.filters])
  const serverParams = useMemo(() => ({
    ...clean({ ...params, ...filterValues, [searchParam]: search ? dq : undefined, sort: view.sort || undefined }),
    ...Object.fromEntries(filters.filter((f) => f.sendAll && filterValues[f.key] === 'all').map((f) => [f.key, 'all'])),
  }), [params, filterValues, filters, searchParam, search, dq, view.sort])
  // Back to page 1 (and nothing selected) when the search, a filter, the sort or the page size changes.
  const resetKey = `${JSON.stringify(serverParams)}|${view.pageSize}`
  const [pg, setPg] = useState({ key: resetKey, page: 1 })
  const page = pg.key === resetKey ? pg.page : 1
  const setPage = (next: number | ((p: number) => number)) => setPg({ key: resetKey, page: typeof next === 'function' ? next(page) : next })
  const [sel, setSel] = useState<{ key: string; rows: RowSelectionState }>({ key: resetKey, rows: {} })
  const selection = sel.key === resetKey ? sel.rows : {}
  const setSelection = (next: RowSelectionState | ((old: RowSelectionState) => RowSelectionState)) =>
    setSel((s) => ({ key: resetKey, rows: typeof next === 'function' ? next(s.key === resetKey ? s.rows : {}) : next }))

  const query = useQuery({
    queryKey: [id, path, serverParams, page, view.pageSize],
    enabled: !!path,
    queryFn: async () => (await api.get<Paginated<T>>(path as string, { params: { ...serverParams, page, per_page: view.pageSize } })).data,
    placeholderData: keepPreviousData,
  })

  // Local mode: search, filter, sort and page here.
  const local = useMemo(() => {
    if (!rows) return null
    const words = (search && dq ? dq.toLowerCase().split(/\s+/).filter(Boolean) : [])
    let list = rows.filter((r) => {
      for (const [k, v] of Object.entries(clean(filterValues))) if (String((r as Record<string, unknown>)[k]) !== String(v)) return false
      if (!words.length) return true
      const text = columns.map((c) => String(cellValue(c, r) ?? '')).join(' ').toLowerCase()
      return words.every((w) => text.includes(w))
    })
    if (view.sort) {
      const key = view.sort.replace(/^-/, '')
      const dir = view.sort.startsWith('-') ? -1 : 1
      list = [...list].sort((a, b) => {
        const x = (a as Record<string, unknown>)[key]
        const y = (b as Record<string, unknown>)[key]
        return (typeof x === 'number' && typeof y === 'number' ? x - y : String(x ?? '').localeCompare(String(y ?? ''))) * dir
      })
    }
    const lastPage = Math.max(1, Math.ceil(list.length / view.pageSize))
    return { all: list, data: list.slice((page - 1) * view.pageSize, page * view.pageSize), total: list.length, last_page: lastPage, current_page: page }
  }, [rows, search, dq, filterValues, view.sort, view.pageSize, page, columns])

  const pageData = local ?? query.data
  const data = useMemo(() => pageData?.data ?? [], [pageData])
  const isLoading = local ? !!loading : query.isLoading

  const allColumns = useMemo<DataColumn<T>[]>(() => {
    const withIds = columns.map((c, i) => ({ ...c, id: ids[i] }) as DataColumn<T>)
    if (!selectable) return withIds
    return [{
      id: '_select',
      header: ({ table }) => (
        <Checkbox checked={table.getIsAllPageRowsSelected() || (table.getIsSomePageRowsSelected() && 'indeterminate')}
          onCheckedChange={(v) => table.toggleAllPageRowsSelected(!!v)} aria-label='Select all on this page' className='size-5' />
      ),
      cell: ({ row }) => (
        <Checkbox checked={row.getIsSelected()} onCheckedChange={(v) => row.toggleSelected(!!v)} aria-label='Select row' className='size-5'
          onClick={(e) => e.stopPropagation()} />
      ),
    } as DataColumn<T>, ...withIds]
  }, [columns, ids, selectable])

  const visibility: ColumnVisibilityState = useMemo(() => Object.fromEntries(view.hidden.map((h) => [h, false])), [view.hidden])
  const table = useTable({
    features,
    data,
    columns: allColumns,
    state: { rowSelection: selection, columnVisibility: visibility },
    onRowSelectionChange: setSelection,
    getRowId: rowId ?? ((row, i) => String((row as { id?: number }).id ?? i)),
  })
  const selected = table.getSelectedRowModel().rows.map((r) => r.original)
  const shown = columns.map((c, i) => ({ c, id: ids[i] })).filter(({ id: cid }) => !view.hidden.includes(cid))
  const activeFilters = Object.values(clean(filterValues)).length + (view.sort ? 1 : 0)
  const sortable = columns.map((c, i) => ({ key: c.meta?.sort, label: colLabel(c, ids[i]) })).filter((c): c is { key: string; label: string } => !!c.key)

  function toggleSort(key?: string) {
    if (!key) return
    const next = view.sort === key ? `-${key}` : view.sort === `-${key}` ? '' : key
    setView({ sort: next })
  }

  async function exportCsv(only?: T[]) {
    setExporting(true)
    try {
      let list: T[] = only ?? []
      if (!only) {
        if (local) list = local.all
        else {
          // Every matching row, 200 at a time (at most 10,000).
          for (let p = 1; p <= 50; p++) {
            const res = (await api.get<Paginated<T>>(path as string, { params: { ...serverParams, page: p, per_page: 200 } })).data
            list = list.concat(res.data)
            if (p >= res.last_page) break
          }
        }
      }
      const cols = shown.filter(({ c }) => c.meta?.csv || (c as { accessorKey?: string }).accessorKey)
      downloadCsv(exportName ?? id, toCsv(cols.map(({ c, id: cid }) => colLabel(c, cid)), list.map((r) => cols.map(({ c }) => cellValue(c, r)))))
      toast.success(`Saved ${list.length} ${list.length === 1 ? 'row' : 'rows'} as a CSV file`)
    } catch (e) {
      toast.error(errorMessage(e))
    } finally {
      setExporting(false)
    }
  }

  const filterControls = filters.map((f) => (
    <Select key={f.key} value={filterValues[f.key]} onValueChange={(v) => setView({ filters: { ...view.filters, [f.key]: v } })}>
      <SelectTrigger className='h-12 w-full sm:h-9 sm:w-44' aria-label={f.label}><SelectValue placeholder={f.label} /></SelectTrigger>
      <SelectContent>
        <SelectItem value='all'>{f.all ?? `${f.label}: all`}</SelectItem>
        {f.options.map((o) => <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>)}
      </SelectContent>
    </Select>
  ))

  const total = pageData?.total ?? 0
  const from = total === 0 ? 0 : (page - 1) * view.pageSize + 1
  const to = Math.min(total, page * view.pageSize)
  const lastPage = pageData?.last_page ?? 1

  return (
    <div className='space-y-3' data-table={id}>
      {/* Toolbar */}
      <div className='flex flex-wrap items-center gap-2'>
        {search && (
          <div className='relative w-full sm:w-64'>
            <Search className='pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground' />
            <Input type='search' value={q} onChange={(e) => setQ(e.target.value)} placeholder={search.placeholder} aria-label={search.placeholder} className='h-12 ps-9 sm:h-9' />
          </div>
        )}
        {(filters.length > 0 || sortable.length > 0) && (
          <>
            {/* Phones: filters and sort folded away with a count (remembered); desktop: always open. */}
            <Button variant='outline' className='h-12 sm:hidden' aria-expanded={view.filtersOpen} onClick={() => setView({ filtersOpen: !view.filtersOpen })}>
              <SlidersHorizontal /> {filters.length > 0 ? 'Filters' : 'Sort'}{activeFilters ? ` (${activeFilters})` : ''}
            </Button>
            <div className={cn('w-full flex-wrap gap-2 sm:flex sm:w-auto', view.filtersOpen ? 'grid' : 'hidden')}>
              {filterControls}
              {sortable.length > 0 && (
                <Select value={view.sort || 'none'} onValueChange={(v) => setView({ sort: v === 'none' ? '' : v })}>
                  <SelectTrigger className='h-12 w-full sm:hidden' aria-label='Sort'><SelectValue placeholder='Sort' /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value='none'>Sort: newest first</SelectItem>
                    {sortable.flatMap((c) => [
                      <SelectItem key={c.key} value={c.key}>{c.label}: low to high</SelectItem>,
                      <SelectItem key={`-${c.key}`} value={`-${c.key}`}>{c.label}: high to low</SelectItem>,
                    ])}
                  </SelectContent>
                </Select>
              )}
            </div>
          </>
        )}
        <div className='ms-auto flex flex-wrap items-center gap-2'>
          {actions}
          <DropdownMenu>
            <DropdownMenuTrigger asChild><Button variant='outline' className='h-12 sm:h-9'><Columns3 /> Columns</Button></DropdownMenuTrigger>
            <DropdownMenuContent align='end' className='w-56'>
              <DropdownMenuLabel>Show columns</DropdownMenuLabel>
              <DropdownMenuSeparator />
              {columns.map((c, i) => (
                <DropdownMenuCheckboxItem key={ids[i]} className='min-h-10' checked={!view.hidden.includes(ids[i])} onSelect={(e) => e.preventDefault()}
                  onCheckedChange={(v) => setView({ hidden: v ? view.hidden.filter((h) => h !== ids[i]) : [...view.hidden, ids[i]] })}>
                  {colLabel(c, ids[i])}
                </DropdownMenuCheckboxItem>
              ))}
              <DropdownMenuSeparator />
              <DropdownMenuCheckboxItem className='min-h-10' checked={false} onSelect={() => { resetView(); setQ('') }}><RotateCcw className='me-2 size-4' /> Reset this table</DropdownMenuCheckboxItem>
            </DropdownMenuContent>
          </DropdownMenu>
          <Button variant='outline' className='h-12 sm:h-9' disabled={exporting} onClick={() => exportCsv()}><Download /> {exporting ? 'Saving…' : 'CSV'}</Button>
        </div>
      </div>

      {selectable && selected.length > 0 && (
        <div className='sticky top-16 z-20 flex flex-wrap items-center gap-2 rounded-md border bg-muted p-2 text-sm'>
          <span className='px-2 font-medium'>{selected.length} selected</span>
          {bulkActions?.(selected, () => setSelection({}))}
          <Button size='sm' variant='outline' onClick={() => exportCsv(selected)}><Download /> CSV of selected</Button>
          <Button size='sm' variant='ghost' onClick={() => setSelection({})}>Clear</Button>
        </div>
      )}

      {/* Desktop and tablet: table with a sticky header */}
      <div className='hidden max-h-[70vh] overflow-auto rounded-md border md:block'>
        <Table>
          <TableHeader className='sticky top-0 z-10 bg-background shadow-[0_1px_0_var(--border)]'>
            {table.getHeaderGroups().map((hg) => (
              <TableRow key={hg.id}>
                {hg.headers.map((h) => {
                  const meta = (h.column.columnDef as DataColumn<T>).meta
                  const dir = meta?.sort ? (view.sort === meta.sort ? 'asc' : view.sort === `-${meta.sort}` ? 'desc' : null) : null
                  return (
                    <TableHead key={h.id} className={cn('whitespace-nowrap', meta?.align === 'end' && 'text-end')}
                      aria-sort={dir === 'asc' ? 'ascending' : dir === 'desc' ? 'descending' : undefined}>
                      {h.isPlaceholder ? null : meta?.sort ? (
                        <button type='button' className={cn('inline-flex min-h-9 items-center gap-1 hover:text-foreground', meta.align === 'end' && 'flex-row-reverse')}
                          onClick={() => toggleSort(meta.sort)} title='Sort'>
                          {flexRender(h.column.columnDef.header, h.getContext())}
                          {dir === 'asc' ? <ArrowUp className='size-3.5' /> : dir === 'desc' ? <ArrowDown className='size-3.5' /> : <ArrowUpDown className='size-3.5 opacity-40' />}
                        </button>
                      ) : flexRender(h.column.columnDef.header, h.getContext())}
                    </TableHead>
                  )
                })}
              </TableRow>
            ))}
          </TableHeader>
          <TableBody>
            {isLoading ? (
              Array.from({ length: 8 }, (_, i) => (
                <TableRow key={`s${i}`} aria-busy='true'>
                  {table.getVisibleLeafColumns().map((c) => <TableCell key={c.id}><Skeleton className='h-5 w-full max-w-40' /></TableCell>)}
                </TableRow>
              ))
            ) : table.getRowModel().rows.length === 0 ? (
              <TableRow><TableCell colSpan={table.getVisibleLeafColumns().length} className='h-24 text-center text-muted-foreground'>{empty}</TableCell></TableRow>
            ) : (
              table.getRowModel().rows.map((row) => (
                <TableRow key={row.id} data-state={row.getIsSelected() && 'selected'}
                  className={onRowClick ? 'cursor-pointer' : undefined} onClick={() => onRowClick?.(row.original)}>
                  {row.getVisibleCells().map((cell) => (
                    <TableCell key={cell.id} className={cn('whitespace-nowrap', (cell.column.columnDef as DataColumn<T>).meta?.align === 'end' && 'text-end tabular-nums')}>
                      {flexRender(cell.column.columnDef.cell, cell.getContext())}
                    </TableCell>
                  ))}
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>

      {/* Phones: one card per row */}
      <div className='grid gap-2 md:hidden'>
        {isLoading && Array.from({ length: 4 }, (_, i) => <Skeleton key={i} className='h-24 w-full rounded-lg' />)}
        {!isLoading && table.getRowModel().rows.length === 0 && <p className='rounded-lg border p-6 text-center text-sm text-muted-foreground'>{empty}</p>}
        {!isLoading && table.getRowModel().rows.map((row) => {
          const cells = row.getVisibleCells().filter((c) => c.column.id !== '_select' && (c.column.columnDef as DataColumn<T>).meta?.card !== 'hide')
          const [title, ...rest] = cells
          return (
            <div key={row.id} className={cn('rounded-lg border bg-card p-3 text-sm', row.getIsSelected() && 'ring-2 ring-primary', onRowClick && 'cursor-pointer')}
              onClick={() => onRowClick?.(row.original)}>
              <div className='flex min-h-12 items-start gap-3'>
                {selectable && (
                  <div className='grid size-12 shrink-0 place-items-center' onClick={(e) => e.stopPropagation()}>
                    <Checkbox checked={row.getIsSelected()} onCheckedChange={(v) => row.toggleSelected(!!v)} aria-label='Select row' className='size-6' />
                  </div>
                )}
                <div className='min-w-0 flex-1 self-center font-medium break-words'>{title && flexRender(title.column.columnDef.cell, title.getContext())}</div>
              </div>
              {rest.length > 0 && (
                <dl className='mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1'>
                  {rest.map((cell) => (
                    <div key={cell.id} className='contents'>
                      <dt className='text-muted-foreground'>{colLabel(cell.column.columnDef as DataColumn<T>, cell.column.id)}</dt>
                      <dd className='min-w-0 text-end break-words tabular-nums [&>*]:justify-end'>{flexRender(cell.column.columnDef.cell, cell.getContext())}</dd>
                    </div>
                  ))}
                </dl>
              )}
            </div>
          )
        })}
      </div>

      {/* Paging */}
      <div className='flex flex-wrap items-center justify-between gap-2 text-sm text-muted-foreground'>
        <span aria-live='polite'>{total > 0 ? `${from}–${to} of ${total}` : '0 rows'}{!local && query.isFetching && !query.isLoading ? ' · updating…' : ''}</span>
        <div className='flex flex-wrap items-center gap-2'>
          <Select value={String(view.pageSize)} onValueChange={(v) => setView({ pageSize: Number(v) })}>
            <SelectTrigger className='h-12 w-[7.5rem] sm:h-8' aria-label='Rows per page'><SelectValue /></SelectTrigger>
            <SelectContent>{pageSizes.map((s) => <SelectItem key={s} value={String(s)}>{s} a page</SelectItem>)}</SelectContent>
          </Select>
          <Button variant='outline' size='icon' className='hidden size-8 sm:inline-flex' aria-label='First page' disabled={page <= 1} onClick={() => setPage(1)}><ChevronsLeft /></Button>
          <Button variant='outline' size='icon' className='size-12 sm:size-8' aria-label='Previous page' disabled={page <= 1} onClick={() => setPage((p) => p - 1)}><ChevronLeft /></Button>
          <span className='tabular-nums'>{page}/{lastPage}</span>
          <Button variant='outline' size='icon' className='size-12 sm:size-8' aria-label='Next page' disabled={page >= lastPage} onClick={() => setPage((p) => p + 1)}><ChevronRight /></Button>
          <Button variant='outline' size='icon' className='hidden size-8 sm:inline-flex' aria-label='Last page' disabled={page >= lastPage} onClick={() => setPage(lastPage)}><ChevronsRight /></Button>
        </div>
      </div>
    </div>
  )
}
