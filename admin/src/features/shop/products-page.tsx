import { useState } from 'react'
import { Link, useNavigate } from '@tanstack/react-router'
import { AlertTriangle, Plus } from 'lucide-react'
import { useMutate } from '@/hooks/use-mutate'
import { formatMoney } from '@/lib/money'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { opts } from '@/components/km/filter-bar'
import { Page } from '@/components/km/page'
import { ResourceTable, type ResourceColumn } from '@/components/km/resource-table'
import { StatusBadge } from '@/components/km/status-badge'
import { type Product } from './types'

const columns: ResourceColumn<Product>[] = [
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
  ), meta: { label: 'Product', sort: 'name', csv: (p) => p.name } },
  { header: 'Price', cell: ({ row }) => formatMoney(row.original.price), meta: { sort: 'price', align: 'end', csv: (p) => p.price / 100 } },
  { header: 'Cost', cell: ({ row }) => formatMoney(row.original.total_cost), meta: { align: 'end', csv: (p) => p.total_cost / 100 } },
  { header: 'Member profit', cell: ({ row }) => row.original.profit ? formatMoney(row.original.profit.profit) : '—', meta: { align: 'end', csv: (p) => (p.profit ? p.profit.profit / 100 : '') } },
  { header: 'Stock', cell: ({ row }) => (row.original.stock_mode === 'made_to_order' ? `Made to order (${row.original.making_days} d)` : row.original.available_stock), meta: { sort: 'stock', csv: (p) => (p.stock_mode === 'made_to_order' ? 'made to order' : p.available_stock) } },
  { header: 'Status', cell: ({ row }) => <StatusBadge status={row.original.status} />, meta: { sort: 'status', csv: (p) => p.status } },
  { header: '', id: 'warn', meta: { label: 'Warnings', csv: (p) => p.warnings.map((w) => w.message).join('; ') }, cell: ({ row }) => row.original.warnings.length > 0 && (
    <span title={row.original.warnings.map((w) => w.message).join('\n')}><AlertTriangle className='size-4 text-amber-500' /></span>
  ) },
]

export function ProductsPage() {
  const navigate = useNavigate()
  const [lowStock, setLowStock] = useState(false)
  const bulk = useMutate('post', ['products'], 'Products updated')

  return (
    <Page title='Products' description='Warnings show when price is under 2.5× cost or a member order would lose money.'
      actions={<Button asChild><Link to='/products/$id' params={{ id: 'new' }}><Plus /> New product</Link></Button>}>
      <ResourceTable<Product>
        queryKey='products' path='/products' params={{ low_stock: lowStock ? 1 : undefined }} columns={columns} selectable defaultSort='-created_at'
        search={{ placeholder: 'Name or slug' }}
        filters={[{ key: 'status', label: 'Status', options: opts('active', 'draft', 'archived') }, { key: 'featured', label: 'Featured', options: [{ value: '1', label: 'Featured' }, { value: '0', label: 'Not featured' }] }]}
        actions={<Button className='h-12 sm:h-9' variant={lowStock ? 'default' : 'outline'} aria-pressed={lowStock} onClick={() => setLowStock(!lowStock)}>Low stock</Button>}
        onRowClick={(p) => navigate({ to: '/products/$id', params: { id: String(p.id) } })}
        bulkActions={(rows, clear) => (['activate', 'draft', 'archive', 'feature', 'unfeature'] as const).map((a) => (
          <Button key={a} size='sm' variant='outline' onClick={async () => { await bulk.mutateAsync({ path: '/products/bulk', body: { ids: rows.map((r) => r.id), action: a } }); clear() }}>{a}</Button>
        ))}
      />
    </Page>
  )
}
