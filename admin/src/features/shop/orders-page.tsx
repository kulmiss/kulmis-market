import { useNavigate } from '@tanstack/react-router'
import { Crown } from 'lucide-react'
import { useMutate } from '@/hooks/use-mutate'
import { formatDate, formatMoney } from '@/lib/money'
import { Button } from '@/components/ui/button'
import { opts } from '@/components/km/filter-bar'
import { Page } from '@/components/km/page'
import { ResourceTable, type ResourceColumn } from '@/components/km/resource-table'
import { StatusBadge } from '@/components/km/status-badge'
import { type Order } from './types'

const columns: ResourceColumn<Order>[] = [
  { header: 'Order', cell: ({ row }) => <span className='font-mono text-xs'>{row.original.number}</span>, meta: { sort: 'number', csv: (o) => o.number } },
  { header: 'Date', cell: ({ row }) => formatDate(row.original.created_at, true), meta: { sort: 'placed', csv: (o) => o.created_at } },
  { header: 'Customer', cell: ({ row }) => (
    <span className='flex items-center gap-1'>
      {row.original.first_name} {row.original.last_name}
      {row.original.is_member_order && <Crown className='size-3.5 text-amber-500' aria-label='Member' />}
    </span>
  ), meta: { sort: 'name', csv: (o) => `${o.first_name} ${o.last_name}` } },
  { header: 'Phone', accessorKey: 'phone' },
  { header: 'City', accessorKey: 'city' },
  { header: 'Items', accessorKey: 'items_count', meta: { sort: 'items', align: 'end' } },
  { header: 'Total', cell: ({ row }) => formatMoney(row.original.total), meta: { sort: 'total', align: 'end', csv: (o) => o.total / 100 } },
  { header: 'Status', cell: ({ row }) => <StatusBadge status={row.original.status} />, meta: { sort: 'status', csv: (o) => o.status } },
]

export function OrdersPage() {
  const navigate = useNavigate()
  const bulk = useMutate('post', ['orders'], 'Orders updated')

  return (
    <Page title='Orders' description='Paid → making → shipped → delivered. Customers get SMS / email / Telegram on every step.'>
      <ResourceTable<Order>
        queryKey='orders' path='/orders' columns={columns} selectable search={{ placeholder: 'Number, phone or name' }}
        filters={[
          { key: 'status', label: 'Status', options: opts('pending_payment', 'paid', 'making', 'shipped', 'delivered', 'cancelled', 'refunded') },
          { key: 'member', label: 'Buyer', options: [{ value: '1', label: 'Members' }, { value: '0', label: 'Guests' }] },
        ]}
        onRowClick={(o) => navigate({ to: '/orders/$id', params: { id: String(o.id) } })}
        bulkActions={(rows, clear) => (
          <>
            {(['making', 'delivered', 'cancelled'] as const).map((s) => (
              <Button key={s} size='sm' variant={s === 'cancelled' ? 'destructive' : 'outline'}
                onClick={async () => { await bulk.mutateAsync({ path: '/orders/bulk-status', body: { ids: rows.map((r) => r.id), status: s } }); clear() }}>
                Mark {s}
              </Button>
            ))}
          </>
        )}
      />
    </Page>
  )
}
