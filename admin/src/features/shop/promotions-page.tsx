import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Pencil, Plus, Trash2, X } from 'lucide-react'
import { useMutate } from '@/hooks/use-mutate'
import { api } from '@/lib/api'
import { formatDate, formatMoney } from '@/lib/money'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { FormDialog } from '@/components/km/form-dialog'
import { MoneyInput } from '@/components/km/money-input'
import { Page } from '@/components/km/page'
import { ResourceTable, type ResourceColumn } from '@/components/km/resource-table'
import { StatusBadge } from '@/components/km/status-badge'
import { type Product } from './types'

type Profit = { profit: number; line: string; regular?: number }

/** "2026-11-16T10:00" for <input type=datetime-local>, in the browser's time. */
const toLocal = (iso: string | null | undefined) => {
  if (!iso) return ''
  const d = new Date(iso)
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16)
}
const fromLocal = (v: string) => (v ? new Date(v).toISOString() : null)

function useDebounced<T>(value: T, ms = 400): T {
  const [v, setV] = useState(value)
  useEffect(() => { const t = setTimeout(() => setV(value), ms); return () => clearTimeout(t) }, [value, ms])
  return v
}

/** Search a product by name, then (if it has options) pick one. */
function ProductPicker({ value, label, onChange }: { value: { id: number; name: string } | null; label?: string; onChange: (p: Product | null) => void }) {
  const [q, setQ] = useState('')
  const term = useDebounced(q, 300)
  const list = useQuery({
    queryKey: ['product-picker', term], enabled: term.length > 1,
    queryFn: async () => (await api.get<{ data: Product[] }>('/products', { params: { q: term, per_page: 8, status: 'active' } })).data.data,
  })
  if (value) {
    return (
      <div className='flex items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm'>
        <span className='truncate'>{value.name}</span>
        <Button size='icon' variant='ghost' aria-label='Choose another product' onClick={() => onChange(null)}><X /></Button>
      </div>
    )
  }
  return (
    <div className='grid gap-1'>
      <Input placeholder={label ?? 'Type a product name'} value={q} onChange={(e) => setQ(e.target.value)} />
      {(list.data ?? []).length > 0 && (
        <ul className='max-h-48 overflow-auto rounded-md border text-sm'>
          {list.data!.map((p) => (
            <li key={p.id}><button type='button' className='flex w-full justify-between gap-2 px-3 py-2.5 text-start hover:bg-muted' onClick={() => { onChange(p); setQ('') }}>
              <span className='truncate'>{p.name}</span><span className='text-muted-foreground'>{formatMoney(p.price)}</span>
            </button></li>
          ))}
        </ul>
      )}
      {term.length > 1 && list.data?.length === 0 && <p className='text-xs text-muted-foreground'>No active product found.</p>}
    </div>
  )
}

function ProfitLine({ data, loading }: { data?: Profit; loading?: boolean }) {
  if (loading) return <p className='text-sm text-muted-foreground'>Checking the profit…</p>
  if (!data) return null
  return <p className={`rounded-md p-3 text-sm ${data.profit < 0 ? 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300' : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300'}`}>{data.line}</p>
}

/* ---------- Flash sales ---------- */

type FlashSale = {
  id: number; product_id: number; product_variant_id: number | null; product: string; variant: string | null
  price: number; regular_price: number; starts_at: string; ends_at: string; stock_limit: number | null; sold: number
  is_active: boolean; status: string; profit: number; profit_line: string
}

const flashColumns: ResourceColumn<FlashSale>[] = [
  { header: 'Product', cell: ({ row }) => <span>{row.original.product}{row.original.variant && <span className='text-muted-foreground'> — {row.original.variant}</span>}</span>, meta: { csv: (s) => s.product + (s.variant ? ` — ${s.variant}` : ''), card: 'title' } },
  { header: 'Sale price', cell: ({ row }) => <span><s className='me-1 text-muted-foreground'>{formatMoney(row.original.regular_price)}</s>{formatMoney(row.original.price)}</span>, meta: { sort: 'price', align: 'end', csv: (s) => s.price / 100 } },
  { header: 'Starts', cell: ({ row }) => formatDate(row.original.starts_at, true), meta: { sort: 'starts', csv: (s) => s.starts_at } },
  { header: 'Ends', cell: ({ row }) => formatDate(row.original.ends_at, true), meta: { sort: 'ends', csv: (s) => s.ends_at } },
  { header: 'Sold', cell: ({ row }) => `${row.original.sold}${row.original.stock_limit ? ` / ${row.original.stock_limit}` : ''}`, meta: { sort: 'sold', align: 'end', csv: (s) => s.sold } },
  { header: 'Per member order', cell: ({ row }) => <span className={row.original.profit < 0 ? 'font-medium text-red-700 dark:text-red-400' : ''}>{row.original.profit < 0 ? 'Loses ' : 'Keeps '}{formatMoney(Math.abs(row.original.profit))}</span>, meta: { align: 'end', csv: (s) => s.profit / 100 } },
  { header: 'Status', cell: ({ row }) => <StatusBadge status={row.original.status} />, meta: { csv: (s) => s.status } },
  { header: '', id: 'edit', meta: { label: 'Edit' }, cell: ({ row }) => <div className='flex justify-end gap-1'><FlashDialog sale={row.original} /><RemoveButton path={`/flash-sales/${row.original.id}`} queryKey='flash-sales' /></div> },
]

export function FlashSalesPage() {
  return (
    <Page title='Flash sales' description='A lower price for a short time. Members get their member price on top. We warn you if a sale would lose money.' actions={<FlashDialog />}>
      <ResourceTable<FlashSale> queryKey='flash-sales' path='/flash-sales' columns={flashColumns} search={{ placeholder: 'Product name' }}
        filters={[{ key: 'status', label: 'Status', options: [{ value: 'live', label: 'Running now' }, { value: 'scheduled', label: 'Starts later' }, { value: 'ended', label: 'Ended' }, { value: 'sold_out', label: 'Sold out' }, { value: 'off', label: 'Off' }] }]} />
    </Page>
  )
}

function FlashDialog({ sale }: { sale?: FlashSale }) {
  const now = new Date()
  const [product, setProduct] = useState<{ id: number; name: string; variants?: Product['variants'] } | null>(sale ? { id: sale.product_id, name: sale.product } : null)
  const [d, setD] = useState({
    product_variant_id: sale?.product_variant_id ?? null as number | null, price: sale?.price ?? 0,
    starts_at: toLocal(sale?.starts_at ?? now.toISOString()), ends_at: toLocal(sale?.ends_at ?? new Date(now.getTime() + 86400000).toISOString()),
    stock_limit: sale?.stock_limit ?? null as number | null, is_active: sale?.is_active ?? true, accept_loss: false,
  })
  const price = useDebounced(d.price)
  const check = useQuery({
    queryKey: ['flash-check', product?.id, d.product_variant_id, price], enabled: !!product && price > 0,
    queryFn: async () => (await api.get<{ data: Profit }>('/flash-sales/check', { params: { product_id: product!.id, variant_id: d.product_variant_id ?? undefined, price } })).data.data,
  })
  const save = useMutate(sale ? 'put' : 'post', ['flash-sales'], 'Flash sale saved')
  const variants = product?.variants?.filter((v) => v.is_active) ?? []
  return (
    <FormDialog title={sale ? 'Edit flash sale' : 'New flash sale'}
      trigger={sale ? <Button size='icon' variant='ghost' aria-label='Edit'><Pencil /></Button> : <Button><Plus /> New flash sale</Button>}
      onSave={() => save.mutateAsync({ path: sale ? `/flash-sales/${sale.id}` : '/flash-sales', body: {
        ...d, product_id: product?.id, starts_at: fromLocal(d.starts_at), ends_at: fromLocal(d.ends_at) } })}>
      <L label='Product'>{sale ? <p className='text-sm'>{sale.product}{sale.variant ? ` — ${sale.variant}` : ''}</p> : <ProductPicker value={product} onChange={(p) => { setProduct(p); setD({ ...d, product_variant_id: null }) }} />}</L>
      {!sale && variants.length > 0 && (
        <L label='Option (empty = all options)'>
          <Select value={d.product_variant_id ? String(d.product_variant_id) : 'all'} onValueChange={(v) => setD({ ...d, product_variant_id: v === 'all' ? null : Number(v) })}>
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent><SelectItem value='all'>All options</SelectItem>{variants.map((v) => <SelectItem key={v.id} value={String(v.id)}>{v.name}</SelectItem>)}</SelectContent>
          </Select>
        </L>
      )}
      <div className='grid grid-cols-1 gap-3 sm:grid-cols-2'>
        <L label={`Sale price${check.data?.regular ? ` (normal ${formatMoney(check.data.regular)})` : ''}`}><MoneyInput value={d.price} onChange={(v) => setD({ ...d, price: v })} /></L>
        <L label='Pieces at this price (empty = no limit)'><Input type='number' min={1} value={d.stock_limit ?? ''} onChange={(e) => setD({ ...d, stock_limit: e.target.value ? Number(e.target.value) : null })} /></L>
        <L label='Starts'><Input type='datetime-local' value={d.starts_at} onChange={(e) => setD({ ...d, starts_at: e.target.value })} /></L>
        <L label='Ends'><Input type='datetime-local' value={d.ends_at} onChange={(e) => setD({ ...d, ends_at: e.target.value })} /></L>
      </div>
      <ProfitLine data={check.data} loading={check.isFetching && !check.data} />
      {check.data && check.data.profit < 0 && <label className='flex items-center gap-2 text-sm'><Switch checked={d.accept_loss} onCheckedChange={(v) => setD({ ...d, accept_loss: v })} /> I know it loses money — save anyway</label>}
      <label className='flex items-center gap-2 text-sm'><Switch checked={d.is_active} onCheckedChange={(v) => setD({ ...d, is_active: v })} /> On</label>
    </FormDialog>
  )
}

function RemoveButton({ path, queryKey }: { path: string; queryKey: string }) {
  const del = useMutate('delete', [queryKey], 'Removed')
  return <Button size='icon' variant='ghost' aria-label='Remove' onClick={() => { if (confirm('Remove this? (If something was already sold, it is only switched off.)')) del.mutate({ path }) }}><Trash2 /></Button>
}

/* ---------- Bundles ---------- */

type BundleItem = { product_id: number; product_variant_id: number | null; quantity: number; name: string; variants?: Product['variants'] }
type Bundle = {
  id: number; name: string; slug: string; description: string | null; price: number; regular_price: number; is_active: boolean
  starts_at: string | null; ends_at: string | null; items: BundleItem[]; profit: number; profit_line: string
}

const bundleColumns: ResourceColumn<Bundle>[] = [
  { header: 'Set', accessorKey: 'name', meta: { sort: 'name', card: 'title' } },
  { header: 'Pieces', cell: ({ row }) => <span className='block max-w-xs truncate text-sm'>{row.original.items.map((i) => `${i.quantity} × ${i.name}`).join(', ')}</span>, meta: { csv: (b) => b.items.map((i) => `${i.quantity} x ${i.name}`).join('; ') } },
  { header: 'Set price', cell: ({ row }) => <span><s className='me-1 text-muted-foreground'>{formatMoney(row.original.regular_price)}</s>{formatMoney(row.original.price)}</span>, meta: { sort: 'price', align: 'end', csv: (b) => b.price / 100 } },
  { header: 'Per member order', cell: ({ row }) => <span className={row.original.profit < 0 ? 'font-medium text-red-700 dark:text-red-400' : ''}>{row.original.profit < 0 ? 'Loses ' : 'Keeps '}{formatMoney(Math.abs(row.original.profit))}</span>, meta: { align: 'end', csv: (b) => b.profit / 100 } },
  { header: 'Status', cell: ({ row }) => <StatusBadge status={row.original.is_active ? 'active' : 'off'} />, meta: { csv: (b) => (b.is_active ? 'on' : 'off') } },
  { header: '', id: 'edit', meta: { label: 'Edit' }, cell: ({ row }) => <div className='flex justify-end gap-1'><BundleDialog bundle={row.original} /><RemoveButton path={`/bundles/${row.original.id}`} queryKey='bundles' /></div> },
]

export function BundlesPage() {
  return (
    <Page title='Bundles' description='Sets of products at one price. When all pieces are in the cart, the saving is taken off by itself.' actions={<BundleDialog />}>
      <ResourceTable<Bundle> queryKey='bundles' path='/bundles' columns={bundleColumns} search={{ placeholder: 'Set name' }}
        filters={[{ key: 'active', label: 'Status', options: [{ value: '1', label: 'On' }, { value: '0', label: 'Off' }] }]} />
    </Page>
  )
}

function BundleDialog({ bundle }: { bundle?: Bundle }) {
  const [items, setItems] = useState<BundleItem[]>(bundle?.items ?? [])
  const [d, setD] = useState({ name: bundle?.name ?? '', description: bundle?.description ?? '', price: bundle?.price ?? 0, is_active: bundle?.is_active ?? true, accept_loss: false,
    starts_at: toLocal(bundle?.starts_at), ends_at: toLocal(bundle?.ends_at) })
  const body = { ...d, starts_at: fromLocal(d.starts_at), ends_at: fromLocal(d.ends_at), items: items.map(({ product_id, product_variant_id, quantity }) => ({ product_id, product_variant_id, quantity })) }
  const sent = useDebounced(JSON.stringify({ price: d.price, items: body.items }))
  const check = useQuery({
    queryKey: ['bundle-check', sent], enabled: items.length >= 2 && d.price > 0, retry: false,
    queryFn: async () => { const r = (await api.post<{ data: Bundle }>('/bundles/check', JSON.parse(sent))).data.data; return { profit: r.profit, line: r.profit_line, regular: r.regular_price } as Profit },
  })
  const save = useMutate(bundle ? 'put' : 'post', ['bundles'], 'Bundle saved')
  return (
    <FormDialog title={bundle ? `Edit ${bundle.name}` : 'New bundle'}
      trigger={bundle ? <Button size='icon' variant='ghost' aria-label='Edit'><Pencil /></Button> : <Button><Plus /> New bundle</Button>}
      onSave={() => save.mutateAsync({ path: bundle ? `/bundles/${bundle.id}` : '/bundles', body })}>
      <L label='Name'><Input value={d.name} placeholder='Coffee set' onChange={(e) => setD({ ...d, name: e.target.value })} /></L>
      <L label='Short text (optional)'><Input value={d.description} onChange={(e) => setD({ ...d, description: e.target.value })} /></L>
      <div className='grid gap-2'>
        <span className='text-sm font-medium'>Pieces (at least 2)</span>
        {items.map((it, n) => {
          const variants = it.variants?.filter((v) => v.is_active) ?? []
          return (
            <div key={n} className='grid grid-cols-[1fr_auto_auto] items-center gap-2 rounded-md border p-2 text-sm'>
              <div className='min-w-0'>
                <p className='truncate'>{it.name}</p>
                {variants.length > 0 && (
                  <Select value={it.product_variant_id ? String(it.product_variant_id) : ''} onValueChange={(v) => setItems(items.map((x, i) => (i === n ? { ...x, product_variant_id: Number(v) } : x)))}>
                    <SelectTrigger className='mt-1 h-9'><SelectValue placeholder='Choose an option' /></SelectTrigger>
                    <SelectContent>{variants.map((v) => <SelectItem key={v.id} value={String(v.id)}>{v.name}</SelectItem>)}</SelectContent>
                  </Select>
                )}
              </div>
              <Input type='number' min={1} max={20} className='w-16' aria-label='How many' value={it.quantity} onChange={(e) => setItems(items.map((x, i) => (i === n ? { ...x, quantity: Math.max(1, Number(e.target.value)) } : x)))} />
              <Button size='icon' variant='ghost' aria-label='Remove piece' onClick={() => setItems(items.filter((_, i) => i !== n))}><X /></Button>
            </div>
          )
        })}
        <ProductPicker value={null} label='Add a piece: type a product name' onChange={(p) => p && !items.some((i) => i.product_id === p.id) && setItems([...items, { product_id: p.id, product_variant_id: null, quantity: 1, name: p.name, variants: p.variants }])} />
      </div>
      <L label={`Set price${check.data?.regular ? ` (one by one ${formatMoney(check.data.regular)})` : ''}`}><MoneyInput value={d.price} onChange={(v) => setD({ ...d, price: v })} /></L>
      <div className='grid grid-cols-1 gap-3 sm:grid-cols-2'>
        <L label='Starts (optional)'><Input type='datetime-local' value={d.starts_at} onChange={(e) => setD({ ...d, starts_at: e.target.value })} /></L>
        <L label='Ends (optional)'><Input type='datetime-local' value={d.ends_at} onChange={(e) => setD({ ...d, ends_at: e.target.value })} /></L>
      </div>
      {check.isError ? <p className='text-sm text-red-700 dark:text-red-400'>{(check.error as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Check the pieces and the price.'}</p> : <ProfitLine data={check.data} loading={check.isFetching && !check.data} />}
      {check.data && check.data.profit < 0 && <label className='flex items-center gap-2 text-sm'><Switch checked={d.accept_loss} onCheckedChange={(v) => setD({ ...d, accept_loss: v })} /> I know it loses money — save anyway</label>}
      <label className='flex items-center gap-2 text-sm'><Switch checked={d.is_active} onCheckedChange={(v) => setD({ ...d, is_active: v })} /> On</label>
    </FormDialog>
  )
}

function L({ label, children }: { label: string; children: React.ReactNode }) {
  return <div className='grid gap-1.5 text-sm leading-none font-medium'><span>{label}</span>{children}</div>
}
