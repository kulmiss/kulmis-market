import { useMemo, useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import {
  type ColumnDef,
  type RowSelectionState,
  flexRender,
  getCoreRowModel,
  useReactTable,
} from '@tanstack/react-table'
import { ChevronLeft, ChevronRight, Loader2 } from 'lucide-react'
import { api, type Paginated } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'

type ResourceTableProps<T> = {
  queryKey: string
  path: string
  columns: ColumnDef<T, unknown>[]
  params?: Record<string, string | number | boolean | undefined | null>
  selectable?: boolean
  bulkActions?: (rows: T[], clear: () => void) => React.ReactNode
  onRowClick?: (row: T) => void
  empty?: string
  rowId?: (row: T) => string
}

/** Server-paginated table for any Laravel paginator endpoint. */
export function ResourceTable<T>({
  queryKey, path, columns, params = {}, selectable, bulkActions, onRowClick, empty = 'Nothing here yet.', rowId,
}: ResourceTableProps<T>) {
  const [page, setPage] = useState(1)
  const [selection, setSelection] = useState<RowSelectionState>({})
  const clean = Object.fromEntries(Object.entries(params).filter(([, v]) => v !== '' && v !== undefined && v !== null && v !== 'all'))

  const query = useQuery({
    queryKey: [queryKey, path, clean, page],
    queryFn: async () => (await api.get<Paginated<T>>(path, { params: { ...clean, page } })).data,
    placeholderData: keepPreviousData,
  })

  const allColumns = useMemo<ColumnDef<T, unknown>[]>(() => {
    if (!selectable) return columns
    return [
      {
        id: 'select',
        header: ({ table }) => (
          <Checkbox
            checked={table.getIsAllPageRowsSelected() || (table.getIsSomePageRowsSelected() && 'indeterminate')}
            onCheckedChange={(v) => table.toggleAllPageRowsSelected(!!v)}
            aria-label='Select all'
          />
        ),
        cell: ({ row }) => (
          <Checkbox checked={row.getIsSelected()} onCheckedChange={(v) => row.toggleSelected(!!v)} aria-label='Select row'
            onClick={(e) => e.stopPropagation()} />
        ),
      },
      ...columns,
    ]
  }, [columns, selectable])

  const table = useReactTable({
    data: query.data?.data ?? [],
    columns: allColumns,
    getCoreRowModel: getCoreRowModel(),
    manualPagination: true,
    pageCount: query.data?.last_page ?? 1,
    state: { rowSelection: selection },
    onRowSelectionChange: setSelection,
    getRowId: rowId ?? ((row, i) => String((row as { id?: number }).id ?? i)),
  })

  const selected = table.getSelectedRowModel().rows.map((r) => r.original)

  return (
    <div className='space-y-3'>
      {selectable && selected.length > 0 && bulkActions && (
        <div className='flex flex-wrap items-center gap-2 rounded-md border bg-muted/40 p-2 text-sm'>
          <span className='px-2'>{selected.length} selected</span>
          {bulkActions(selected, () => setSelection({}))}
        </div>
      )}
      <div className='overflow-x-auto rounded-md border'>
        <Table>
          <TableHeader>
            {table.getHeaderGroups().map((hg) => (
              <TableRow key={hg.id}>
                {hg.headers.map((h) => (
                  <TableHead key={h.id} className='whitespace-nowrap'>
                    {h.isPlaceholder ? null : flexRender(h.column.columnDef.header, h.getContext())}
                  </TableHead>
                ))}
              </TableRow>
            ))}
          </TableHeader>
          <TableBody>
            {query.isLoading ? (
              <TableRow><TableCell colSpan={allColumns.length} className='h-24 text-center'><Loader2 className='mx-auto animate-spin' /></TableCell></TableRow>
            ) : table.getRowModel().rows.length === 0 ? (
              <TableRow><TableCell colSpan={allColumns.length} className='h-24 text-center text-muted-foreground'>{empty}</TableCell></TableRow>
            ) : (
              table.getRowModel().rows.map((row) => (
                <TableRow key={row.id} data-state={row.getIsSelected() && 'selected'}
                  className={onRowClick ? 'cursor-pointer' : undefined} onClick={() => onRowClick?.(row.original)}>
                  {row.getVisibleCells().map((cell) => (
                    <TableCell key={cell.id} className='whitespace-nowrap'>{flexRender(cell.column.columnDef.cell, cell.getContext())}</TableCell>
                  ))}
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>
      <div className='flex items-center justify-between text-sm text-muted-foreground'>
        <span>{query.data ? `${query.data.total} total` : ''}{query.isFetching && !query.isLoading ? ' · updating…' : ''}</span>
        <div className='flex items-center gap-2'>
          <span>Page {query.data?.current_page ?? 1} of {query.data?.last_page ?? 1}</span>
          <Button variant='outline' size='icon' className='size-8' disabled={page <= 1} onClick={() => setPage((p) => p - 1)}><ChevronLeft /></Button>
          <Button variant='outline' size='icon' className='size-8' disabled={page >= (query.data?.last_page ?? 1)} onClick={() => setPage((p) => p + 1)}><ChevronRight /></Button>
        </div>
      </div>
    </div>
  )
}
