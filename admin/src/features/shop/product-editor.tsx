import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from '@tanstack/react-router'
import { AlertTriangle, ArrowLeft, ImagePlus, Plus, Save, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { api, errorMessage } from '@/lib/api'
import { formatMoney } from '@/lib/money'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { MoneyInput } from '@/components/km/money-input'
import { Page } from '@/components/km/page'
import { type Category, type Product, type Variant } from './types'
import { PageSkeleton } from '@/components/km/page-skeleton'

type Draft = Omit<Product, 'id' | 'images' | 'warnings' | 'available_stock' | 'total_cost' | 'profit' | 'tags' | 'slug'> & { slug?: string; tags: string[] }

const empty: Draft = {
  name: '', short_description: '', description: '', category_id: null, price: 0, cost_materials: 0, cost_labour: 0,
  cost_packaging: 0, weight_grams: 500, stock_mode: 'stock', stock: 0, making_days: null, is_featured: false,
  is_members_only: false, release_at: null, status: 'draft', seo_title: '', seo_description: '', tags: [], variants: [],
  kind: 'physical', member_discount: true, booking_at: null,
}

export function ProductEditor({ id }: { id: string }) {
  const isNew = id === 'new'
  const qc = useQueryClient()
  const navigate = useNavigate()
  const product = useQuery({
    queryKey: ['product', id], enabled: !isNew,
    queryFn: async () => (await api.get<{ data: Product }>(`/products/${id}`)).data.data,
  })
  const categories = useQuery({ queryKey: ['categories'], queryFn: async () => (await api.get<{ data: Category[] }>('/categories')).data.data })
  const [draft, setDraft] = useState<Draft | null>(null)
  const [saving, setSaving] = useState(false)

  const loaded: Draft | null = isNew ? empty : product.data ? { ...product.data, tags: (product.data.tags ?? []).map((t) => t.name) } : null
  const current = draft ?? loaded
  if (!current) return <Page title='Product'><PageSkeleton cards={0} rows={10} /></Page>
  const d: Draft = current
  const set = <K extends keyof Draft>(k: K, v: Draft[K]) => setDraft({ ...d, [k]: v })
  const setVariant = (i: number, patch: Partial<Variant>) => set('variants', d.variants.map((v, j) => (j === i ? { ...v, ...patch } : v)))

  async function save() {
    setSaving(true)
    try {
      const body = { ...d, release_at: d.release_at || null, variants: d.variants.filter((v) => v.is_active !== false) }
      const res = isNew ? await api.post<{ data: Product }>('/products', body) : await api.put<{ data: Product }>(`/products/${id}`, body)
      toast.success('Saved')
      qc.setQueryData(['product', String(res.data.data.id)], res.data.data)
      qc.invalidateQueries({ queryKey: ['products'] })
      setDraft(null)
      res.data.data.warnings.forEach((w) => toast.warning(w.message))
      if (isNew) navigate({ to: '/products/$id', params: { id: String(res.data.data.id) } })
    } catch (e) {
      toast.error(errorMessage(e))
    } finally {
      setSaving(false)
    }
  }

  async function upload(files: FileList | null) {
    if (!files || isNew) return
    for (const file of Array.from(files)) {
      const form = new FormData()
      form.append('image', file)
      try {
        await api.post(`/products/${id}/images`, form)
      } catch (e) {
        toast.error(errorMessage(e))
      }
    }
    qc.invalidateQueries({ queryKey: ['product', id] })
  }

  async function removeImage(imageId: number) {
    await api.delete(`/products/${id}/images/${imageId}`)
    qc.invalidateQueries({ queryKey: ['product', id] })
  }

  const p = product.data
  const cost = d.cost_materials + d.cost_labour + d.cost_packaging
  const ratio = cost > 0 ? d.price / cost : null

  return (
    <Page title={isNew ? 'New product' : d.name} description={p ? `/${p.slug}` : undefined}
      actions={<>
        <Button variant='outline' asChild><Link to='/products'><ArrowLeft /> Products</Link></Button>
        <Button onClick={save} disabled={saving}><Save /> {isNew ? 'Add product' : 'Save changes'}</Button>
      </>}>
      {p?.warnings.map((w) => (
        <Alert key={w.code} className='mb-3 border-amber-300 bg-amber-50 text-amber-900 dark:bg-amber-950/40 dark:text-amber-200'>
          <AlertTriangle /><AlertDescription>{w.message}</AlertDescription>
        </Alert>
      ))}
      <div className='grid grid-cols-1 gap-4 lg:grid-cols-3'>
        <Card className='lg:col-span-2'>
          <CardHeader><CardTitle className='text-base'>Details</CardTitle></CardHeader>
          <CardContent className='grid gap-3'>
            <Field label='Name'><Input value={d.name} onChange={(e) => set('name', e.target.value)} /></Field>
            <Field label='Short description'><Input value={d.short_description ?? ''} onChange={(e) => set('short_description', e.target.value)} /></Field>
            <Field label='Description'><Textarea rows={6} value={d.description ?? ''} onChange={(e) => set('description', e.target.value)} /></Field>
            <div className='grid grid-cols-1 gap-3 sm:grid-cols-2'>
              <Field label='Category'>
                <Select value={d.category_id ? String(d.category_id) : 'none'} onValueChange={(v) => set('category_id', v === 'none' ? null : Number(v))}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value='none'>No category</SelectItem>
                    {categories.data?.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                  </SelectContent>
                </Select>
              </Field>
              <Field label='Tags (comma separated)'>
                <Input value={d.tags.join(', ')} onChange={(e) => set('tags', e.target.value.split(',').map((t) => t.trim()).filter(Boolean))} />
              </Field>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle className='text-base'>Status & visibility</CardTitle></CardHeader>
          <CardContent className='grid gap-3'>
            <Field label='Status'>
              <Select value={d.status} onValueChange={(v) => set('status', v as Draft['status'])}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>{['draft', 'active', 'archived'].map((s) => <SelectItem key={s} value={s}>{s}</SelectItem>)}</SelectContent>
              </Select>
            </Field>
            <Field label='Type'>
              <Select value={d.kind ?? 'physical'} onValueChange={(v) => set('kind', v as Draft['kind'])}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value='physical'>Physical product (shipped)</SelectItem>
                  <SelectItem value='digital'>Digital (files or a private video link)</SelectItem>
                  <SelectItem value='addon'>Paid add-on (session, workshop…)</SelectItem>
                </SelectContent>
              </Select>
            </Field>
            {d.kind === 'addon' && <Field label='Date of the session (optional)'><Input type='datetime-local' value={d.booking_at ? d.booking_at.slice(0, 16) : ''} onChange={(e) => set('booking_at', e.target.value || null)} /></Field>}
            <Toggle label='Members get their member price' checked={d.member_discount !== false} onChange={(v) => set('member_discount', v)} />
            {d.kind !== 'digital' && <Toggle label='Pre-order (pay part now, the rest before shipping)' checked={d.preorder_deposit_bps != null} onChange={(v) => set('preorder_deposit_bps', v ? 3000 : null)} />}
            {d.preorder_deposit_bps != null && (
              <div className='grid grid-cols-2 gap-3'>
                <Field label='Paid now (%)'><Input type='number' min={10} max={90} value={d.preorder_deposit_bps / 100} onChange={(e) => set('preorder_deposit_bps', Math.round(Number(e.target.value) * 100))} /></Field>
                <Field label='Ships about'><Input type='date' value={d.preorder_ships_on?.slice(0, 10) ?? ''} onChange={(e) => set('preorder_ships_on', e.target.value || null)} /></Field>
              </div>
            )}
            <Toggle label='Featured' checked={d.is_featured} onChange={(v) => set('is_featured', v)} />
            <Toggle label='Members-only product' checked={d.is_members_only} onChange={(v) => set('is_members_only', v)} />
            <Field label='Release date (members see it 48 h earlier)'>
              <Input type='datetime-local' value={d.release_at ? d.release_at.slice(0, 16) : ''} onChange={(e) => set('release_at', e.target.value || null)} />
            </Field>
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle className='text-base'>Price & cost</CardTitle></CardHeader>
          <CardContent className='grid gap-3'>
            <Field label='Price (guests)'><MoneyInput value={d.price} onChange={(v) => set('price', v)} /></Field>
            <Field label='Materials'><MoneyInput value={d.cost_materials} onChange={(v) => set('cost_materials', v)} /></Field>
            <Field label='Labour'><MoneyInput value={d.cost_labour} onChange={(v) => set('cost_labour', v)} /></Field>
            <Field label='Packaging'><MoneyInput value={d.cost_packaging} onChange={(v) => set('cost_packaging', v)} /></Field>
            <div className='rounded-md bg-muted p-3 text-sm'>
              Cost {formatMoney(cost)} · price is <b>{ratio ? `${ratio.toFixed(2)}×` : '—'}</b> cost
              {p?.profit && <div className='mt-1 text-xs text-muted-foreground'>
                Worst-case member sale: {formatMoney(p.profit.member_price)} − commissions {formatMoney(p.profit.commission)} − fee {formatMoney(p.profit.payment_fee)} − cost {formatMoney(p.profit.cost)} = <b>{formatMoney(p.profit.profit)}</b>
              </div>}
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle className='text-base'>Stock & shipping</CardTitle></CardHeader>
          <CardContent className='grid gap-3'>
            {d.kind === 'digital' ? <p className='text-sm text-muted-foreground'>Digital: no stock and no delivery. Buyers get safe download links after paying.</p> : <>
            <Toggle label='Made to order' checked={d.stock_mode === 'made_to_order'} onChange={(v) => set('stock_mode', v ? 'made_to_order' : 'stock')} />
            {d.stock_mode === 'made_to_order'
              ? <Field label='Making days'><Input type='number' min={1} value={d.making_days ?? ''} onChange={(e) => set('making_days', Number(e.target.value))} /></Field>
              : d.variants.length === 0 && <Field label='Stock'><Input type='number' min={0} value={d.stock ?? 0} onChange={(e) => set('stock', Number(e.target.value))} /></Field>}
            {d.stock_mode !== 'made_to_order' && <Field label='Tell me when only this many are left (empty = Settings)'><Input type='number' min={0} value={d.low_stock_alert ?? ''} onChange={(e) => set('low_stock_alert', e.target.value === '' ? null : Number(e.target.value))} /></Field>}
            <Field label={d.kind === 'addon' ? 'Weight (grams, 0 if nothing is shipped)' : 'Weight (grams)'}><Input type='number' min={0} value={d.weight_grams} onChange={(e) => set('weight_grams', Number(e.target.value))} /></Field>
            </>}
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle className='text-base'>Photos</CardTitle></CardHeader>
          <CardContent className='grid gap-3'>
            {isNew ? <p className='text-sm text-muted-foreground'>Save the product first, then add photos.</p> : (
              <>
                <div className='grid grid-cols-3 gap-2'>
                  {p?.images.map((img) => (
                    <div key={img.id} className='group relative'>
                      <img src={img.thumb} alt={img.alt ?? ''} className='aspect-square w-full rounded object-cover' />
                      <Button size='icon' variant='destructive' className='absolute end-1 top-1 size-12 sm:size-7 sm:opacity-0 sm:group-hover:opacity-100 sm:focus-visible:opacity-100' aria-label='Remove photo' onClick={() => removeImage(img.id)}><Trash2 /></Button>
                    </div>
                  ))}
                </div>
                <Label className='flex cursor-pointer items-center justify-center gap-2 rounded-md border border-dashed p-4 text-sm'>
                  <ImagePlus className='size-4' /> Add photos (JPG, PNG, WebP; converted to AVIF/WebP)
                  <input type='file' accept='image/jpeg,image/png,image/webp' multiple className='hidden' onChange={(e) => upload(e.target.files)} />
                </Label>
              </>
            )}
          </CardContent>
        </Card>

        {d.kind === 'digital' && !isNew && <DigitalFiles productId={id} />}

        <Card className='lg:col-span-3'>
          <CardHeader className='flex-row items-center justify-between'>
            <CardTitle className='text-base'>Variants (size / colour)</CardTitle>
            <Button size='sm' variant='outline' onClick={() => set('variants', [...d.variants, { name: '', options: {}, stock: 0, is_active: true }])}><Plus /> Add variant</Button>
          </CardHeader>
          <CardContent className='grid gap-2'>
            {d.variants.filter((v) => v.is_active !== false).length === 0 && <p className='text-sm text-muted-foreground'>No variants — one simple product.</p>}
            {d.variants.map((v, i) => v.is_active === false ? null : (
              <div key={v.id ?? `new-${i}`} className='grid items-end gap-2 sm:grid-cols-6'>
                <Field label='Name'><Input value={v.name} placeholder='Large / Red' onChange={(e) => setVariant(i, { name: e.target.value })} /></Field>
                <Field label='Size'><Input value={v.options?.size ?? ''} onChange={(e) => setVariant(i, { options: { ...v.options, size: e.target.value } })} /></Field>
                <Field label='Colour'><Input value={v.options?.colour ?? ''} onChange={(e) => setVariant(i, { options: { ...v.options, colour: e.target.value } })} /></Field>
                <Field label='Price (optional)'><MoneyInput value={v.price ?? null} onChange={(x) => setVariant(i, { price: x || null })} /></Field>
                <Field label='Stock'><Input type='number' min={0} value={v.stock ?? 0} onChange={(e) => setVariant(i, { stock: Number(e.target.value) })} /></Field>
                <Button variant='ghost' onClick={() => setVariant(i, { is_active: false })}><Trash2 /> Remove</Button>
              </div>
            ))}
          </CardContent>
        </Card>

        <Card className='lg:col-span-3'>
          <CardHeader><CardTitle className='text-base'>SEO</CardTitle></CardHeader>
          <CardContent className='grid grid-cols-1 gap-3 sm:grid-cols-2'>
            <Field label='SEO title'><Input value={d.seo_title ?? ''} onChange={(e) => set('seo_title', e.target.value)} /></Field>
            <Field label='SEO description'><Input value={d.seo_description ?? ''} onChange={(e) => set('seo_description', e.target.value)} /></Field>
          </CardContent>
        </Card>
      </div>
    </Page>
  )
}

type DFile = { id: number; kind: 'file' | 'video'; title: string; size: number | null; has_link: boolean }

function DigitalFiles({ productId }: { productId: string }) {
  const qc = useQueryClient()
  const files = useQuery({ queryKey: ['product-files', productId], queryFn: async () => (await api.get<{ data: DFile[] }>(`/products/${productId}/files`)).data.data })
  const [title, setTitle] = useState('')
  const [video, setVideo] = useState('')
  const refresh = () => qc.invalidateQueries({ queryKey: ['product-files', productId] })
  const add = async (file?: File) => {
    const form = new FormData()
    form.append('title', title || file?.name || 'Video')
    if (file) form.append('file', file)
    else form.append('video_url', video)
    try { await api.post(`/products/${productId}/files`, form); setTitle(''); setVideo(''); refresh(); toast.success('Added') } catch (e) { toast.error(errorMessage(e)) }
  }
  return (
    <Card className='lg:col-span-3'>
      <CardHeader><CardTitle className='text-base'>What the buyer gets</CardTitle></CardHeader>
      <CardContent className='grid gap-3'>
        {(files.data ?? []).length === 0 && <p className='text-sm text-muted-foreground'>Add at least one file or private video link.</p>}
        {(files.data ?? []).map((f) => (
          <div key={f.id} className='flex items-center justify-between gap-2 rounded-md border p-2 text-sm'>
            <span>{f.kind === 'video' ? '🎬' : '📄'} {f.title}{f.size ? ` · ${(f.size / 1048576).toFixed(1)} MB` : ''}</span>
            <Button size='icon' variant='ghost' aria-label='Remove' onClick={async () => { await api.delete(`/products/${productId}/files/${f.id}`); refresh() }}><Trash2 /></Button>
          </div>
        ))}
        <div className='grid gap-2 sm:grid-cols-3'>
          <Input placeholder='Title shown to the buyer' value={title} onChange={(e) => setTitle(e.target.value)} />
          <Label className='flex cursor-pointer items-center justify-center gap-2 rounded-md border border-dashed p-2 text-sm'>Upload a file (up to 200 MB)
            <input type='file' className='hidden' onChange={(e) => e.target.files?.[0] && add(e.target.files[0])} /></Label>
          <div className='flex gap-2'><Input placeholder='https://… private video link' value={video} onChange={(e) => setVideo(e.target.value)} /><Button variant='outline' disabled={!video} onClick={() => add()}>Add</Button></div>
        </div>
        <p className='text-xs text-muted-foreground'>Files stay private. Each buyer gets links that end after the days and downloads set in Settings.</p>
      </CardContent>
    </Card>
  )
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  // A real <label> around the field, so it is named for screen readers and clicking the text focuses it.
  return <label className='grid gap-1.5 text-sm leading-none font-medium'>{label}{children}</label>
}

function Toggle({ label, checked, onChange }: { label: string; checked: boolean; onChange: (v: boolean) => void }) {
  return <label className='flex items-center justify-between gap-3 text-sm'>{label}<Switch checked={checked} onCheckedChange={onChange} /></label>
}
