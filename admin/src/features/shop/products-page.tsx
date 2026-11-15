import { useState } from 'react'
import { Link, useNavigate } from '@tanstack/react-router'
import { type ColumnDef } from '@tanstack/react-table'
import { AlertTriangle, Plus } from 'lucide-react'
import { useMutate } from '@/hooks/use-mutate'
import { formatMoney } from '@/lib/money'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { FilterBar, SearchInput, SelectFilter, opts } from '@/components/km/filter-bar'
import { Page } from '@/components/km/page'
import { ResourceTable } from '@/components/km/resource-table'
import { StatusBadge } from '@/components/km/status-badge'
import { type Product } from './types'

const columns: ColumnDef<Product, unknown>[] = [
  { header: 'Product', cell: ({ row }) => (
    <span className='flex items-center gap-2'>
      {row.original.images[0] ? <img src={row.original.images[0].thumb} alt='' className='size-9 rounded object-cover' /> : <span className='size-9 rounded bg-muted' />}
      <span>
        <span className='font-medium'>{row.original.name}</span>
        <span className='flex gap-1'>
          {row.original.is_featured && <Badge variant='secondary' className='text-[10px]'>Featured</Badge>}
          {row.original.is_members_only && <Badge variant='secondary' className='text-[10px]'>Members only</Badge>}
          {row.original.release_at && new Date(row.original.release_at) > new Date() && <Badge variant='secondary' className='text-[10px]'>Scheduled</Badge>}
        </span>
      </span>
    </span>
  ) },
  { header: 'Price', cell: ({ row }) => formatMoney(row.original.price) },
  { header: 'Cost', cell: ({ row }) => formatMoney(row.original.total_cost) },
  { header: 'Member profit', cell: ({ row }) => row.original.profit ? formatMoney(row.original.profit.profit) : '—' },
  { header: 'Stock', cell: ({ row }) => (row.original.stock_mode === 'made_to_order' ? `Made to order (${row.original.making_days} d)` : row.original.available_stock) },
  { header: 'Status', cell: ({ row }) => <StatusBadge status={row.original.status} /> },
  { header: '', id: 'warn', cell: ({ row }) => row.original.warnings.length > 0 && (
    <span title={row.original.warnings.map((w) => w.message).join('\n')}><AlertTriangle className='size-4 text-amber-500' /></span>
  ) },
]

export function ProductsPage() {
  const navigate = useNavigate()
  const [q, setQ] = useState('')
  const [status, setStatus] = useState('all')
  const [lowStock, setLowStock] = useState(false)
  const bulk = useMutate('post', ['products'], 'Products updated')

  return (
    <Page title='Products' description='Warnings show when price is under 2.5× cost or a member order would lose money.'
      actions={<Button asChild><Link to='/products/$id' params={{ id: 'new' }}><Plus /> New product</Link></Button>}>
      <FilterBar>
        <SearchInput value={q} onChange={setQ} placeholder='Name or slug' />
        <SelectFilter value={status} onChange={setStatus} placeholder='Status' options={opts('active', 'draft', 'archived')} />
        <Button size='sm' variant={lowStock ? 'default' : 'outline'} onClick={() => setLowStock(!lowStock)}>Low stock</Button>
      </FilterBar>
      <ResourceTable<Product>
        queryKey='products' path='/products' params={{ q, status, low_stock: lowStock ? 1 : undefined }} columns={columns} selectable
        onRowClick={(p) => navigate({ to: '/products/$id', params: { id: String(p.id) } })}
        bulkActions={(rows, clear) => (['activate', 'draft', 'archive', 'feature', 'unfeature'] as const).map((a) => (
          <Button key={a} size='sm' variant='outline' onClick={async () => { await bulk.mutateAsync({ path: '/products/bulk', body: { ids: rows.map((r) => r.id), action: a } }); clear() }}>{a}</Button>
        ))}
      />
    </Page>
  )
}
