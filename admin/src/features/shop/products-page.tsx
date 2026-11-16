import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from '@tanstack/react-router'
import { AlertTriangle, FileDown, FileUp, Plus } from 'lucide-react'
import { toast } from 'sonner'
import { api, errorMessage } from '@/lib/api'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
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
      actions={<div className='flex flex-wrap gap-2'>
        <Button variant='outline' asChild><a href='/api/v1/admin/products-export.csv'><FileDown /> Export CSV</a></Button>
        <ImportDialog />
        <Button asChild><Link to='/products/$id' params={{ id: 'new' }}><Plus /> New product</Link></Button>
      </div>}>
      <ResourceTable<Product>
        queryKey='products' path='/products' params={{ low_stock: lowStock ? 1 : undefined }} columns={columns} selectable defaultSort='-created_at'
        search={{ placeholder: 'Name or slug' }}
        filters={[{ key: 'status', label: 'Status', options: opts('active', 'draft', 'archived') }, { key: 'featured', label: 'Featured', options: [{ value: '1', label: 'Featured' }, { value: '0', label: 'Not featured' }] }]}
        actions={<Button className='h-12 sm:h-9' variant={lowStock ? 'default' : 'outline'} aria-pressed={lowStock} onClick={() => setLowStock(!lowStock)}>Low stock</Button>}
        onRowClick={(p) => navigate({ to: '/products/$id', params: { id: String(p.id) } })}
        bulkActions={(rows, clear) => <>
          {(['activate', 'draft', 'archive', 'feature', 'unfeature'] as const).map((a) => (
            <Button key={a} size='sm' variant='outline' onClick={async () => { await bulk.mutateAsync({ path: '/products/bulk', body: { ids: rows.map((r) => r.id), action: a } }); clear() }}>{a}</Button>
          ))}
          <BulkValue label='Change price %' action='price_percent' ids={rows.map((r) => r.id)} done={clear} placeholder='e.g. 10 or -5' />
          <BulkValue label='Set stock' action='stock' ids={rows.map((r) => r.id)} done={clear} placeholder='e.g. 20' />
          <BulkCategory ids={rows.map((r) => r.id)} done={clear} />
        </>}
      />
    </Page>
  )
}

function BulkValue({ label, action, ids, done, placeholder }: { label: string; action: string; ids: number[]; done: () => void; placeholder: string }) {
  const [v, setV] = useState('')
  const [open, setOpen] = useState(false)
  const bulk = useMutate('post', ['products'], 'Products updated')
  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild><Button size='sm' variant='outline'>{label}</Button></DialogTrigger>
      <DialogContent className='sm:max-w-sm'>
        <DialogHeader><DialogTitle>{label} for {ids.length} products</DialogTitle></DialogHeader>
        <Input inputMode='decimal' value={v} placeholder={placeholder} onChange={(e) => setV(e.target.value)} />
        {action === 'price_percent' && <p className='text-xs text-muted-foreground'>Prices are rounded to whole ETB. Check the profit warnings after.</p>}
        <Button disabled={v === ''} onClick={async () => { await bulk.mutateAsync({ path: '/products/bulk', body: { ids, action, value: Number(v) } }); setOpen(false); done() }}>Apply</Button>
      </DialogContent>
    </Dialog>
  )
}

function BulkCategory({ ids, done }: { ids: number[]; done: () => void }) {
  const cats = useQuery({ queryKey: ['categories'], queryFn: async () => (await api.get<{ data: { id: number; name: string }[] }>('/categories')).data.data })
  const bulk = useMutate('post', ['products'], 'Products updated')
  return (
    <Select onValueChange={async (v) => { await bulk.mutateAsync({ path: '/products/bulk', body: { ids, action: 'category', value: v === 'none' ? null : Number(v) } }); done() }}>
      <SelectTrigger className='h-9 w-40'><SelectValue placeholder='Move to category' /></SelectTrigger>
      <SelectContent><SelectItem value='none'>No category</SelectItem>{(cats.data ?? []).map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
    </Select>
  )
}

type Preview = { rows: { line: number; action: string; name: string; changes: Record<string, [unknown, unknown]>; errors: string[] }[]; ok: boolean; applied: boolean
  summary: { create?: number; update?: number; same?: number; errors?: number } }

function ImportDialog() {
  const qc = useQueryClient()
  const [file, setFile] = useState<File | null>(null)
  const [preview, setPreview] = useState<Preview | null>(null)
  const [busy, setBusy] = useState(false)
  const send = async (apply: boolean) => {
    if (!file) return
    const form = new FormData()
    form.append('file', file)
    form.append('apply', apply ? '1' : '0')
    setBusy(true)
    try {
      const r = (await api.post<{ data: Preview }>('/products-import', form)).data.data
      setPreview(r)
      if (r.applied) { toast.success('Products saved'); qc.invalidateQueries({ queryKey: ['products'] }) }
    } catch (e) { toast.error(errorMessage(e)) } finally { setBusy(false) }
  }
  return (
    <Dialog onOpenChange={(o) => { if (!o) { setPreview(null); setFile(null) } }}>
      <DialogTrigger asChild><Button variant='outline'><FileUp /> Import CSV</Button></DialogTrigger>
      <DialogContent className='max-h-[90vh] overflow-y-auto sm:max-w-3xl'>
        <DialogHeader><DialogTitle>Import products from a spreadsheet (CSV)</DialogTitle></DialogHeader>
        <p className='text-sm text-muted-foreground'>Tip: export first, change the file, import it back. Rows with an id are updated; rows without an id are new (saved as drafts). Empty cells keep what is there. Nothing is saved until you press Save.</p>
        <Input type='file' accept='.csv,text/csv' onChange={(e) => { setFile(e.target.files?.[0] ?? null); setPreview(null) }} />
        <div className='flex gap-2'>
          <Button variant='outline' disabled={!file || busy} onClick={() => send(false)}>Check the file</Button>
          {preview?.ok && !preview.applied && <Button disabled={busy} onClick={() => send(true)}>Save {(preview.summary.create ?? 0) + (preview.summary.update ?? 0)} changes</Button>}
        </div>
        {preview && (
          <div className='grid gap-2 text-sm'>
            <p><b>{preview.summary.create ?? 0}</b> new · <b>{preview.summary.update ?? 0}</b> changed · {preview.summary.same ?? 0} the same · <b className={preview.summary.errors ? 'text-red-700' : ''}>{preview.summary.errors ?? 0} with problems</b>{preview.applied && ' — saved ✓'}</p>
            <ul className='grid max-h-80 gap-1 overflow-y-auto'>
              {preview.rows.filter((r) => r.action !== 'same' || r.errors.length).map((r) => (
                <li key={r.line} className={`rounded border p-2 ${r.errors.length ? 'border-red-300 bg-red-50 dark:bg-red-950/30' : ''}`}>
                  <b>Line {r.line}</b> · {r.action === 'create' ? 'new' : r.action} · {r.name}
                  {r.errors.map((e) => <div key={e} className='text-red-700 dark:text-red-400'>• {e}</div>)}
                  {r.action === 'update' && <div className='text-xs text-muted-foreground'>{Object.entries(r.changes).map(([k, [a, b]]) => `${k}: ${String(a ?? '—')} → ${String(b)}`).join(' · ')}</div>}
                </li>
              ))}
            </ul>
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}
