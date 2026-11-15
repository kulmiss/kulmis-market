import { useState } from 'react'
import { Link } from '@tanstack/react-router'
import { useMutate } from '@/hooks/use-mutate'
import { formatDate, formatMoney } from '@/lib/money'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { FormDialog } from '@/components/km/form-dialog'
import { MoneyInput } from '@/components/km/money-input'
import { Page } from '@/components/km/page'
import { ResourceTable, type ResourceColumn } from '@/components/km/resource-table'
import { StatusBadge } from '@/components/km/status-badge'

type Req = { id: number; name: string; phone: string; city: string; description: string; budget: number | null; status: string; created_at: string
  photo_urls: string[]; deposit_bps: number; order: { id: number; number: string; status: string; total: number; deposit_amount: number; deposit_paid: number } | null }

const STATUS: Record<string, string> = { new: 'pending', quoted: 'awaiting_review', declined: 'rejected', ordered: 'paid' }

const columns: ResourceColumn<Req>[] = [
  { header: 'When', cell: ({ row }) => formatDate(row.original.created_at), meta: { sort: 'created', csv: (r) => r.created_at } },
  { header: 'From', cell: ({ row }) => <span>{row.original.name}<span className='block text-xs text-muted-foreground'>{row.original.phone} · {row.original.city}</span></span>, meta: { csv: (r) => `${r.name} ${r.phone}`, card: 'title' } },
  { header: 'Wants', cell: ({ row }) => (
    <div className='grid max-w-md gap-2 text-sm'>
      <p className='whitespace-pre-wrap break-words'>{row.original.description}</p>
      {row.original.photo_urls.length > 0 && <div className='flex gap-1'>{row.original.photo_urls.map((u) => <a key={u} href={u} target='_blank' rel='noopener noreferrer'><img src={u} alt='' className='size-14 rounded object-cover' /></a>)}</div>}
    </div>), meta: { csv: (r) => r.description } },
  { header: 'Budget', cell: ({ row }) => (row.original.budget ? formatMoney(row.original.budget) : '—'), meta: { align: 'end', csv: (r) => (r.budget ?? 0) / 100 } },
  { header: 'Status', cell: ({ row }) => <span className='grid gap-1'><StatusBadge status={STATUS[row.original.status] ?? row.original.status} />{row.original.order && <Link className='text-xs underline' to='/orders/$id' params={{ id: String(row.original.order.id) }}>{row.original.order.number}{row.original.order.deposit_paid > 0 ? ' · deposit paid' : ''}</Link>}</span>, meta: { csv: (r) => r.status } },
  { header: '', id: 'act', meta: { label: 'Actions' }, cell: ({ row }) => ['new', 'quoted'].includes(row.original.status) ? <div className='flex justify-end gap-1'><QuoteDialog r={row.original} /><DeclineDialog r={row.original} /></div> : null },
]

export function CustomOrdersPage() {
  return (
    <Page title='Custom orders' description='Requests from /custom-order. Send a price: it becomes an order paid as a deposit now and the rest before shipping.'>
      <ResourceTable<Req> queryKey='custom-requests' path='/custom-requests' columns={columns} search={{ placeholder: 'Name, phone or text' }}
        filters={[{ key: 'status', label: 'Status', default: 'new', sendAll: true, options: [{ value: 'new', label: 'New' }, { value: 'quoted', label: 'Price sent' }, { value: 'declined', label: 'Declined' }, { value: 'all', label: 'All' }] }]} />
    </Page>
  )
}

function QuoteDialog({ r }: { r: Req }) {
  const [d, setD] = useState({ price: r.budget ?? 0, cost: 0, making_days: 14, deposit_bps: r.deposit_bps, note: '' })
  const save = useMutate('post', ['custom-requests'], 'Price sent to the customer')
  return (
    <FormDialog title={`Price for ${r.name}`} saveText='Send the price' trigger={<Button size='sm'>Send a price</Button>}
      onSave={() => save.mutateAsync({ path: `/custom-requests/${r.id}/quote`, body: d })}>
      <div className='grid grid-cols-2 gap-3'>
        <L label='Price (delivery included)'><MoneyInput value={d.price} onChange={(v) => setD({ ...d, price: v })} /></L>
        <L label='Your cost (for profit reports)'><MoneyInput value={d.cost} onChange={(v) => setD({ ...d, cost: v })} /></L>
        <L label='Ready in (days)'><Input type='number' min={1} value={d.making_days} onChange={(e) => setD({ ...d, making_days: Number(e.target.value) })} /></L>
        <L label='Deposit (%)'><Input type='number' min={10} max={100} value={d.deposit_bps / 100} onChange={(e) => setD({ ...d, deposit_bps: Math.round(Number(e.target.value) * 100) })} /></L>
      </div>
      <L label='Note for the customer (optional)'><Input value={d.note} onChange={(e) => setD({ ...d, note: e.target.value })} /></L>
      <p className='text-sm text-muted-foreground'>Deposit now: {formatMoney(Math.round((d.price * d.deposit_bps) / 10000))}. The rest before shipping.</p>
    </FormDialog>
  )
}

function DeclineDialog({ r }: { r: Req }) {
  const [reason, setReason] = useState('')
  const save = useMutate('post', ['custom-requests'], 'Declined')
  return (
    <FormDialog title='Decline this request' saveText='Decline and tell them' trigger={<Button size='sm' variant='ghost'>Decline</Button>}
      onSave={() => save.mutateAsync({ path: `/custom-requests/${r.id}/decline`, body: { reason } })}>
      <L label='Why (sent to the customer)'><Input value={reason} placeholder='We cannot make this size' onChange={(e) => setReason(e.target.value)} /></L>
    </FormDialog>
  )
}

function L({ label, children }: { label: string; children: React.ReactNode }) {
  return <div className='grid gap-1.5 text-sm leading-none font-medium'><span>{label}</span>{children}</div>
}
