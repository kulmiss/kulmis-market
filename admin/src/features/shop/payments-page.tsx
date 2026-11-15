import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useLocation, useNavigate } from '@tanstack/react-router'
import { api } from '@/lib/api'
import { formatDate, formatMoney } from '@/lib/money'
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { opts } from '@/components/km/filter-bar'
import { Page } from '@/components/km/page'
import { ResourceTable, type ResourceColumn } from '@/components/km/resource-table'
import { StatusBadge } from '@/components/km/status-badge'
import { PaymentRow } from './order-detail'
import { type Payment } from './types'

const forWhat = (p: Payment) => `${p.payable_type.split('\\').pop()} ${p.payable?.number ?? `#${p.payable_id}`}`

const columns: ResourceColumn<Payment>[] = [
  { header: 'For', cell: ({ row }) => forWhat(row.original), meta: { csv: forWhat } },
  { header: 'Reference', accessorKey: 'tx_ref', cell: ({ row }) => <span className='font-mono text-xs'>{row.original.tx_ref}</span> },
  { header: 'Method', accessorKey: 'provider', cell: ({ row }) => row.original.provider.replaceAll('_', ' '), meta: { sort: 'method' } },
  { header: 'Amount', cell: ({ row }) => formatMoney(row.original.amount), meta: { sort: 'amount', align: 'end', csv: (p) => p.amount / 100 } },
  { header: 'Refunded', cell: ({ row }) => (row.original.refunded_amount ? formatMoney(row.original.refunded_amount) : ''), meta: { align: 'end', hidden: true, csv: (p) => p.refunded_amount / 100 } },
  { header: 'Status', cell: ({ row }) => <StatusBadge status={row.original.status} />, meta: { sort: 'status', csv: (p) => p.status } },
  { header: 'Sent', cell: ({ row }) => formatDate(row.original.created_at, true), meta: { sort: 'created', csv: (p) => p.created_at } },
]

/** All payments; "awaiting review" first is the receipt-checking queue. Tap a row to check it. */
export function PaymentsPage() {
  const navigate = useNavigate()
  // ?open=12 (from the top-bar search) opens that payment.
  const fromSearch = Number((useLocation({ select: (l) => l.search }) as { open?: string | number }).open) || null
  const [picked, setOpen] = useState<number | null>(null)
  const open = picked ?? fromSearch
  const close = () => { setOpen(null); if (fromSearch) navigate({ to: '/payments', search: {} as never }) }
  const one = useQuery({
    queryKey: ['payments', 'one', open],
    enabled: open !== null,
    queryFn: async () => (await api.get<{ data: Payment }>(`/payments/${open}`)).data.data,
  })

  return (
    <Page title='Payments' description='Check bank / Telebirr receipts, give money back, download receipts. Tap a payment to open it.'>
      <ResourceTable<Payment> queryKey='payments' path='/payments' columns={columns} search={{ placeholder: 'Reference' }}
        filters={[
          { key: 'status', label: 'Status', default: 'awaiting_review', options: opts('awaiting_review', 'pending', 'paid', 'partially_refunded', 'refunded', 'rejected', 'failed') },
          { key: 'provider', label: 'Method', options: opts('telebirr', 'bank_transfer', 'wallet') },
        ]}
        onRowClick={(p) => setOpen(p.id)} empty='No payments here.' />
      <Sheet open={open !== null} onOpenChange={(o) => !o && close()}>
        <SheetContent className='w-full overflow-y-auto sm:max-w-lg'>
          <SheetHeader>
            <SheetTitle>{one.data ? forWhat(one.data) : 'Payment'}</SheetTitle>
            <SheetDescription>{one.data ? `${formatMoney(one.data.amount)} · ${one.data.provider.replaceAll('_', ' ')}` : 'Loading…'}</SheetDescription>
          </SheetHeader>
          <div className='p-4'>{one.data && <PaymentRow payment={one.data} />}</div>
        </SheetContent>
      </Sheet>
    </Page>
  )
}
