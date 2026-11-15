import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from '@tanstack/react-router'
import { AlertTriangle, ArrowLeft, ImagePlus, Plus, Save, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { api, errorMessage } from '@/lib/api'
import { formatETB } from '@/lib/money'
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

type Draft = Omit<Product, 'id' | 'images' | 'warnings' | 'available_stock' | 'total_cost' | 'profit' | 'tags' | 'slug'> & { slug?: string; tags: string[] }

const empty: Draft = {
  name: '', short_description: '', description: '', category_id: null, price: 0, cost_materials: 0, cost_labour: 0,
  cost_packaging: 0, weight_grams: 500, stock_mode: 'stock', stock: 0, making_days: null, is_featured: false,
  is_members_only: false, release_at: null, status: 'draft', seo_title: '', seo_description: '', tags: [], variants: [],
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
  if (!current) return <Page title='Product'>Loading…</Page>
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
        <Button onClick={save} disabled={saving}><Save /> Save</Button>
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
              Cost {formatETB(cost)} · price is <b>{ratio ? `${ratio.toFixed(2)}×` : '—'}</b> cost
              {p?.profit && <div className='mt-1 text-xs text-muted-foreground'>
                Worst-case member sale: {formatETB(p.profit.member_price)} − commissions {formatETB(p.profit.commission)} − fee {formatETB(p.profit.payment_fee)} − cost {formatETB(p.profit.cost)} = <b>{formatETB(p.profit.profit)}</b>
              </div>}
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle className='text-base'>Stock & shipping</CardTitle></CardHeader>
          <CardContent className='grid gap-3'>
            <Toggle label='Made to order' checked={d.stock_mode === 'made_to_order'} onChange={(v) => set('stock_mode', v ? 'made_to_order' : 'stock')} />
            {d.stock_mode === 'made_to_order'
              ? <Field label='Making days'><Input type='number' min={1} value={d.making_days ?? ''} onChange={(e) => set('making_days', Number(e.target.value))} /></Field>
              : d.variants.length === 0 && <Field label='Stock'><Input type='number' min={0} value={d.stock ?? 0} onChange={(e) => set('stock', Number(e.target.value))} /></Field>}
            <Field label='Weight (grams)'><Input type='number' min={0} value={d.weight_grams} onChange={(e) => set('weight_grams', Number(e.target.value))} /></Field>
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
                      <Button size='icon' variant='destructive' className='absolute end-1 top-1 size-7 opacity-0 group-hover:opacity-100' onClick={() => removeImage(img.id)}><Trash2 /></Button>
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

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return <div className='grid gap-1.5'><Label>{label}</Label>{children}</div>
}

function Toggle({ label, checked, onChange }: { label: string; checked: boolean; onChange: (v: boolean) => void }) {
  return <label className='flex items-center justify-between gap-3 text-sm'>{label}<Switch checked={checked} onCheckedChange={onChange} /></label>
}
