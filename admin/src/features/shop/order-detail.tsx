import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import { ArrowLeft, Check, ExternalLink, FileDown, RotateCcw, X } from 'lucide-react'
import { useMutate } from '@/hooks/use-mutate'
import { api } from '@/lib/api'
import { useAuthStore } from '@/stores/auth-store'
import { useFeatures } from '@/stores/features-store'
import { formatDate, formatMoney } from '@/lib/money'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Separator } from '@/components/ui/separator'
import { Switch } from '@/components/ui/switch'
import { MoneyInput } from '@/components/km/money-input'
import { Page } from '@/components/km/page'
import { StatusBadge } from '@/components/km/status-badge'
import { type Courier, type Order, type Payment } from './types'
import { PageSkeleton } from '@/components/km/page-skeleton'

export function OrderDetail({ id }: { id: string }) {
  const order = useQuery({ queryKey: ['order', id], queryFn: async () => (await api.get<{ data: Order }>(`/orders/${id}`)).data.data })
  const couriers = useQuery({ queryKey: ['couriers'], queryFn: async () => (await api.get<{ data: Courier[] }>('/couriers')).data.data })
  const payments = useQuery({
    queryKey: ['payments', 'order', id],
    queryFn: async () => (await api.get<{ data: Payment[] }>('/payments', { params: { order_id: id, per_page: 50 } })).data.data,
  })
  const status = useMutate('post', ['order', 'orders'], 'Status updated')
  const [courier, setCourier] = useState<string>('')
  const [tracking, setTracking] = useState('')
  const [note, setNote] = useState('')

  const o = order.data
  if (!o) return <Page title='Order'><PageSkeleton cards={0} /></Page>
  const move = (to: string) => status.mutate({ path: `/orders/${id}/status`, body: { status: to, note: note || undefined,
    courier_id: courier ? Number(courier) : undefined, tracking_number: tracking || undefined } })

  return (
    <Page title={`Order ${o.number}`} description={`${formatDate(o.created_at, true)} · ${o.is_member_order ? 'Member order' : 'Guest order'}`}
      actions={<Button variant='outline' asChild><Link to='/orders'><ArrowLeft /> Orders</Link></Button>}>
      <div className='grid grid-cols-1 gap-4 lg:grid-cols-3'>
        <Card className='lg:col-span-2'>
          <CardHeader className='flex-row items-center justify-between'>
            <CardTitle className='text-base'>Items</CardTitle><StatusBadge status={o.status} />
          </CardHeader>
          <CardContent className='grid gap-2 text-sm'>
            {o.items?.map((i) => (
              <div key={i.id} className='flex justify-between gap-2'>
                <span>{i.quantity} × {i.name}{i.made_to_order && <span className='text-muted-foreground'> · made to order ({i.making_days} days)</span>}</span>
                <span className='text-end'>{formatMoney(i.line_total)}<span className='block text-xs text-muted-foreground'>cost {formatMoney(i.unit_cost * i.quantity)}</span></span>
              </div>
            ))}
            <Separator />
            <Row label='Subtotal (guest prices)' value={formatMoney(o.list_subtotal)} />
            {o.member_discount > 0 && <Row label='Member discount' value={`−${formatMoney(o.member_discount)}`} />}
            {o.coupon_discount > 0 && <Row label={`Coupon ${o.coupon_code}`} value={`−${formatMoney(o.coupon_discount)}`} />}
            <Row label='Shipping' value={o.shipping_discount ? `${formatMoney(o.shipping)} → free` : formatMoney(o.shipping)} />
            <Row label='Total' value={formatMoney(o.total)} strong />
            {o.wallet_used > 0 && <Row label='Paid from wallet' value={formatMoney(o.wallet_used)} />}
            {o.payment_fee > 0 && <Row label='Payment fee' value={formatMoney(o.payment_fee)} />}
            {o.refunded_amount > 0 && <Row label='Refunded' value={formatMoney(o.refunded_amount)} />}
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle className='text-base'>Customer</CardTitle></CardHeader>
          <CardContent className='grid gap-1 text-sm'>
            <span className='font-medium'>{o.first_name} {o.last_name}</span>
            <a className='underline' href={`tel:${o.phone}`}>{o.phone}</a>
            {o.email && <span>{o.email}</span>}
            <span>{o.city}</span><span className='text-muted-foreground'>{o.address}</span>
            {o.notes && <span className='mt-2 rounded bg-muted p-2'>{o.notes}</span>}
            {o.referral_code && <span className='mt-2 text-xs'>Referral code: <b>{o.referral_code}</b></span>}
          </CardContent>
        </Card>

        <Card className='lg:col-span-2'>
          <CardHeader><CardTitle className='text-base'>Next step</CardTitle></CardHeader>
          <CardContent className='grid gap-3'>
            <div className='grid grid-cols-1 gap-3 sm:grid-cols-3'>
              <div className='grid gap-1'>
                <Label>Courier</Label>
                <Select value={courier || (o.courier_id ? String(o.courier_id) : '')} onValueChange={setCourier}>
                  <SelectTrigger><SelectValue placeholder='Choose courier' /></SelectTrigger>
                  <SelectContent>{couriers.data?.filter((c) => c.is_active).map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                </Select>
              </div>
              <div className='grid gap-1'><Label htmlFor='od-tracking'>Tracking number</Label><Input id='od-tracking' value={tracking} placeholder={o.tracking_number ?? ''} onChange={(e) => setTracking(e.target.value)} /></div>
              <div className='grid gap-1'><Label htmlFor='od-note'>Note (sent with cancel)</Label><Input id='od-note' value={note} onChange={(e) => setNote(e.target.value)} /></div>
            </div>
            <div className='flex flex-wrap gap-2'>
              {(o.allowed_next ?? []).filter((s) => !['paid', 'refunded'].includes(s)).map((s) => (
                <Button key={s} variant={s === 'cancelled' ? 'destructive' : 'default'} disabled={status.isPending} onClick={() => move(s)}>Mark {s}</Button>
              ))}
              {o.status === 'pending_payment' && <span className='text-sm text-muted-foreground'>Waiting for payment. Confirm payment proofs below.</span>}
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle className='text-base'>Timeline</CardTitle></CardHeader>
          <CardContent className='grid gap-2 text-sm'>
            {o.history?.map((h) => (
              <div key={h.id}>
                <StatusBadge status={h.to} /> <span className='text-xs text-muted-foreground'>{formatDate(h.created_at, true)}</span>
                {h.note && <div className='text-xs'>{h.note}</div>}
                {h.notified && <div className='text-xs text-muted-foreground'>{Object.entries(h.notified).map(([k, v]) => `${k}: ${v}`).join(' · ')}</div>}
              </div>
            ))}
          </CardContent>
        </Card>

        <Card className='lg:col-span-3'>
          <CardHeader><CardTitle className='text-base'>Payments</CardTitle></CardHeader>
          <CardContent className='grid gap-3'>
            {payments.data?.length === 0 && <span className='text-sm text-muted-foreground'>No payments yet.</span>}
            {payments.data?.map((p) => <PaymentRow key={p.id} payment={p} />)}
          </CardContent>
        </Card>
      </div>
    </Page>
  )
}

function Row({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
  return <div className={`flex justify-between ${strong ? 'font-semibold' : ''}`}><span>{label}</span><span>{value}</span></div>
}

/** Approve / reject proofs, refund (full or partial), receipt. Used on orders and the payments page. */
export function PaymentRow({ payment: p }: { payment: Payment }) {
  const canApprove = useAuthStore((st) => st.can('approve-payments'))
  const receipts = useFeatures((st) => st.on('payments.pdf_receipts'))
  const act = useMutate('post', ['payments', 'order', 'orders'], 'Done')
  const [reason, setReason] = useState('')
  const [amount, setAmount] = useState(p.amount - p.refunded_amount)
  const [restock, setRestock] = useState(false)
  const [refunding, setRefunding] = useState(false)

  return (
    <div className='grid gap-2 rounded-md border p-3 text-sm'>
      <div className='flex flex-wrap items-center gap-2'>
        <StatusBadge status={p.status} />
        <span className='font-medium'>{formatMoney(p.amount)}</span>
        <span className='text-muted-foreground'>{p.provider.replaceAll('_', ' ')} · {p.tx_ref}</span>
        {p.provider_reference && <span className='text-muted-foreground'>ref {p.provider_reference}</span>}
        {p.proof_reference && <span className='text-muted-foreground'>proof ref {p.proof_reference}</span>}
        <span className='ms-auto flex gap-2'>
          {p.proof_path && <Button size='sm' variant='outline' asChild><a href={`/api/v1/admin/payments/${p.id}/proof`} target='_blank' rel='noreferrer'><ExternalLink /> Proof</a></Button>}
          {receipts && ['paid', 'partially_refunded', 'refunded'].includes(p.status) && <Button size='sm' variant='outline' asChild><a href={`/api/v1/admin/payments/${p.id}/receipt`}><FileDown /> Receipt</a></Button>}
        </span>
      </div>
      {p.status === 'awaiting_review' && !canApprove && <p className='text-xs text-muted-foreground'>Waiting for Finance or a Manager to approve this transfer.</p>}
      {p.status === 'awaiting_review' && canApprove && (
        <div className='flex flex-wrap items-center gap-2'>
          <Button size='sm' onClick={() => act.mutate({ path: `/payments/${p.id}/approve` })}><Check /> Approve</Button>
          <Input className='h-8 w-full sm:w-64' placeholder='Reason to reject' value={reason} onChange={(e) => setReason(e.target.value)} />
          <Button size='sm' variant='destructive' disabled={!reason} onClick={() => act.mutate({ path: `/payments/${p.id}/reject`, body: { reason } })}><X /> Reject</Button>
        </div>
      )}
      {['paid', 'partially_refunded'].includes(p.status) && (refunding ? (
        <div className='flex flex-wrap items-center gap-2'>
          <div className='w-full sm:w-40'><MoneyInput value={amount} onChange={setAmount} /></div>
          <Input className='h-9 w-full sm:w-64' placeholder='Reason (required)' value={reason} onChange={(e) => setReason(e.target.value)} />
          <label className='flex items-center gap-2'><Switch checked={restock} onCheckedChange={setRestock} /> Put items back in stock</label>
          <Button size='sm' variant='destructive' disabled={!reason || amount < 1}
            onClick={() => act.mutate({ path: `/payments/${p.id}/refund`, body: { amount, reason, restock } })}>Refund {formatMoney(amount)}</Button>
          <Button size='sm' variant='ghost' onClick={() => setRefunding(false)}>Cancel</Button>
        </div>
      ) : (
        <div><Button size='sm' variant='outline' onClick={() => setRefunding(true)}><RotateCcw /> Refund…</Button>
          <span className='ms-2 text-xs text-muted-foreground'>Commissions on this sale are reversed automatically.</span></div>
      ))}
      {p.refunds?.map((r) => <div key={r.id} className='text-xs text-muted-foreground'>Refunded {formatMoney(r.amount)} ({r.method}) · {r.reason}</div>)}
    </div>
  )
}
