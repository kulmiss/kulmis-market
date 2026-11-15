import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { toast } from 'sonner'
import { api, errorMessage } from '@/lib/api'
import { useMutate } from '@/hooks/use-mutate'
import { formatDate, formatMoney } from '@/lib/money'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { MoneyInput } from '@/components/km/money-input'
import { Page } from '@/components/km/page'
import { ResourceTable, type ResourceColumn } from '@/components/km/resource-table'
import { StatusBadge } from '@/components/km/status-badge'

type Card = { id: number; last4: string | null; amount: number; balance: number; status: string; buyer_name: string; buyer_phone: string | null
  recipient_name: string | null; expires_at: string | null; created_at: string }

const STATUS: Record<string, string> = { pending: 'pending_payment', active: 'active', used: 'ended', disabled: 'off', refunded: 'refunded' }

const columns: ResourceColumn<Card>[] = [
  { header: 'Card', cell: ({ row }) => <span className='font-mono'>{row.original.last4 ? `••••${row.original.last4}` : '—'}</span>, meta: { csv: (c) => c.last4, card: 'title' } },
  { header: 'Amount', cell: ({ row }) => formatMoney(row.original.amount), meta: { sort: 'amount', align: 'end', csv: (c) => c.amount / 100 } },
  { header: 'Left', cell: ({ row }) => <b>{formatMoney(row.original.balance)}</b>, meta: { sort: 'balance', align: 'end', csv: (c) => c.balance / 100 } },
  { header: 'Bought by', cell: ({ row }) => <span>{row.original.buyer_name}<span className='block text-xs text-muted-foreground'>{row.original.recipient_name ? `for ${row.original.recipient_name}` : ''}</span></span>, meta: { csv: (c) => c.buyer_name } },
  { header: 'Ends', cell: ({ row }) => formatDate(row.original.expires_at), meta: { sort: 'expires', csv: (c) => c.expires_at } },
  { header: 'Status', cell: ({ row }) => <StatusBadge status={STATUS[row.original.status] ?? row.original.status} />, meta: { csv: (c) => c.status } },
  { header: '', id: 'act', meta: { label: 'Actions' }, cell: ({ row }) => <Disable card={row.original} /> },
]

export function GiftCardsPage() {
  const owed = useQuery({ queryKey: ['gift-cards-owed'], queryFn: async () => (await api.get<{ owed: number }>('/gift-cards', { params: { per_page: 1 } })).data.owed })
  return (
    <Page title='Gift cards' description={`Money on gift cards not spent yet: ${formatMoney(owed.data ?? 0)}. Online cards are made when you approve the receipt in Payments.`} actions={<SellDialog />}>
      <ResourceTable<Card> queryKey='gift-cards' path='/gift-cards' columns={columns} search={{ placeholder: 'Last 4, buyer name or phone' }}
        filters={[{ key: 'status', label: 'Status', options: [{ value: 'active', label: 'Active' }, { value: 'used', label: 'Used up' }, { value: 'pending', label: 'Waiting for payment' }, { value: 'disabled', label: 'Off' }] }]} />
    </Page>
  )
}

function Disable({ card }: { card: Card }) {
  const m = useMutate('post', ['gift-cards'], 'Gift card switched off')
  if (card.status !== 'active') return null
  return <Button size='sm' variant='outline' onClick={() => { if (confirm('Switch this card off? Its money can no longer be spent.')) m.mutate({ path: `/gift-cards/${card.id}/disable` }) }}>Switch off</Button>
}

function SellDialog() {
  const qc = useQueryClient()
  const [open, setOpen] = useState(false)
  const [d, setD] = useState({ amount: 100000, buyer_name: '', buyer_phone: '' })
  const [code, setCode] = useState<string | null>(null)
  const sell = async () => {
    try {
      const r = (await api.post<{ data: { code: string } }>('/gift-cards/sell', d)).data.data
      setCode(r.code)
      qc.invalidateQueries({ queryKey: ['gift-cards'] })
      qc.invalidateQueries({ queryKey: ['gift-cards-owed'] })
    } catch (e) { toast.error(errorMessage(e)) }
  }
  return (
    <Dialog open={open} onOpenChange={(o) => { setOpen(o); if (!o) setCode(null) }}>
      <DialogTrigger asChild><Button><Plus /> Sold in the shop</Button></DialogTrigger>
      <DialogContent className='sm:max-w-md'>
        <DialogHeader><DialogTitle>{code ? 'Give this code to the buyer' : 'Gift card sold for cash'}</DialogTitle></DialogHeader>
        {code ? (
          <div className='grid gap-3 text-center'>
            <p className='rounded-md bg-muted p-4 font-mono text-2xl tracking-wider select-all'>{code}</p>
            <p className='text-sm text-muted-foreground'>Write it down now: it is shown only this once (we keep only a secret fingerprint of it).</p>
            <Button onClick={() => setOpen(false)}>Done</Button>
          </div>
        ) : (
          <div className='grid gap-3'>
            <label className='grid gap-1.5 text-sm font-medium'>Amount<MoneyInput value={d.amount} onChange={(v) => setD({ ...d, amount: v })} /></label>
            <label className='grid gap-1.5 text-sm font-medium'>Buyer name<Input value={d.buyer_name} onChange={(e) => setD({ ...d, buyer_name: e.target.value })} /></label>
            <label className='grid gap-1.5 text-sm font-medium'>Buyer phone (optional)<Input value={d.buyer_phone} onChange={(e) => setD({ ...d, buyer_phone: e.target.value })} /></label>
            <Button onClick={sell}>I got the money — make the card</Button>
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}
