import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { type ColumnDef } from '@tanstack/react-table'
import { Pencil, Plus } from 'lucide-react'
import { useMutate } from '@/hooks/use-mutate'
import { api } from '@/lib/api'
import { formatBps, formatDate, formatMoney } from '@/lib/money'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { FormDialog } from '@/components/km/form-dialog'
import { MoneyInput } from '@/components/km/money-input'
import { Page } from '@/components/km/page'
import { ResourceTable } from '@/components/km/resource-table'
import { StatusBadge } from '@/components/km/status-badge'
import { type Category, type Courier, type Zone } from './types'

/* ---------- Categories ---------- */

export function CategoriesPage() {
  const list = useQuery({ queryKey: ['categories'], queryFn: async () => (await api.get<{ data: Category[] }>('/categories')).data.data })
  return (
    <Page title='Categories' actions={<CategoryDialog />}>
      <Table>
        <TableHeader><TableRow><TableHead>Name</TableHead><TableHead>Slug</TableHead><TableHead>Products</TableHead><TableHead>Active</TableHead><TableHead /></TableRow></TableHeader>
        <TableBody>
          {list.data?.map((c) => (
            <TableRow key={c.id}>
              <TableCell>{c.name}</TableCell><TableCell className='text-muted-foreground'>{c.slug}</TableCell>
              <TableCell>{c.products_count}</TableCell><TableCell>{c.is_active ? 'Yes' : 'No'}</TableCell>
              <TableCell className='text-end'><CategoryDialog category={c} /></TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </Page>
  )
}

function CategoryDialog({ category }: { category?: Category }) {
  const [d, setD] = useState({ name: category?.name ?? '', slug: category?.slug ?? '', description: category?.description ?? '', position: category?.position ?? 0, is_active: category?.is_active ?? true })
  const save = useMutate(category ? 'put' : 'post', ['categories'], 'Category saved')
  return (
    <FormDialog title={category ? 'Edit category' : 'New category'}
      trigger={category ? <Button size='icon' variant='ghost'><Pencil /></Button> : <Button><Plus /> New category</Button>}
      onSave={() => save.mutateAsync({ path: category ? `/categories/${category.id}` : '/categories', body: { ...d, slug: d.slug || undefined } })}>
      <L label='Name'><Input value={d.name} onChange={(e) => setD({ ...d, name: e.target.value })} /></L>
      <L label='Slug (optional)'><Input value={d.slug} onChange={(e) => setD({ ...d, slug: e.target.value })} /></L>
      <L label='Description'><Input value={d.description} onChange={(e) => setD({ ...d, description: e.target.value })} /></L>
      <L label='Position'><Input type='number' value={d.position} onChange={(e) => setD({ ...d, position: Number(e.target.value) })} /></L>
      <label className='flex items-center gap-2 text-sm'><Switch checked={d.is_active} onCheckedChange={(v) => setD({ ...d, is_active: v })} /> Active</label>
    </FormDialog>
  )
}

/* ---------- Coupons ---------- */

type Coupon = { id: number; code: string; type: 'percent' | 'fixed'; value: number; min_subtotal: number; max_discount: number | null
  starts_at: string | null; ends_at: string | null; max_uses: number | null; max_uses_per_customer: number | null; used_count: number
  members_only: boolean; is_active: boolean; note: string | null }

const couponColumns: ColumnDef<Coupon, unknown>[] = [
  { header: 'Code', cell: ({ row }) => <span className='font-mono'>{row.original.code}</span> },
  { header: 'Discount', cell: ({ row }) => (row.original.type === 'percent' ? formatBps(row.original.value) : formatMoney(row.original.value)) },
  { header: 'Min. order', cell: ({ row }) => formatMoney(row.original.min_subtotal) },
  { header: 'Used', cell: ({ row }) => `${row.original.used_count}${row.original.max_uses ? ` / ${row.original.max_uses}` : ''}` },
  { header: 'Ends', cell: ({ row }) => formatDate(row.original.ends_at) },
  { header: 'Members only', cell: ({ row }) => (row.original.members_only ? 'Yes' : '') },
  { header: 'Status', cell: ({ row }) => <StatusBadge status={row.original.is_active ? 'active' : 'archived'} /> },
  { header: '', id: 'edit', cell: ({ row }) => <CouponDialog coupon={row.original} /> },
]

export function CouponsPage() {
  return (
    <Page title='Coupons' description='Applied after the member discount, on goods only (not shipping).' actions={<CouponDialog />}>
      <ResourceTable<Coupon> queryKey='coupons' path='/coupons' columns={couponColumns} />
    </Page>
  )
}

function CouponDialog({ coupon }: { coupon?: Coupon }) {
  const [d, setD] = useState({
    code: coupon?.code ?? '', type: coupon?.type ?? 'percent', value: coupon?.value ?? 1000, min_subtotal: coupon?.min_subtotal ?? 0,
    max_discount: coupon?.max_discount ?? null, ends_at: coupon?.ends_at?.slice(0, 10) ?? '', max_uses: coupon?.max_uses ?? null,
    max_uses_per_customer: (coupon ? coupon.max_uses_per_customer : 1) as number | null, members_only: coupon?.members_only ?? false, is_active: coupon?.is_active ?? true,
  })
  const save = useMutate(coupon ? 'put' : 'post', ['coupons'], 'Coupon saved')
  return (
    <FormDialog title={coupon ? `Edit ${coupon.code}` : 'New coupon'}
      trigger={coupon ? <Button size='icon' variant='ghost'><Pencil /></Button> : <Button><Plus /> New coupon</Button>}
      onSave={() => save.mutateAsync({ path: coupon ? `/coupons/${coupon.id}` : '/coupons', body: { ...d, ends_at: d.ends_at || null } })}>
      <L label='Code'><Input value={d.code} onChange={(e) => setD({ ...d, code: e.target.value.toUpperCase() })} /></L>
      <div className='grid grid-cols-2 gap-3'>
        <L label='Type'>
          <Select value={d.type} onValueChange={(v) => setD({ ...d, type: v as 'percent' | 'fixed' })}>
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent><SelectItem value='percent'>Percent</SelectItem><SelectItem value='fixed'>Fixed amount</SelectItem></SelectContent>
          </Select>
        </L>
        <L label={d.type === 'percent' ? 'Percent' : 'Amount'}>
          {d.type === 'percent'
            ? <Input inputMode='decimal' value={d.value / 100} onChange={(e) => setD({ ...d, value: Math.round(Number(e.target.value) * 100) })} />
            : <MoneyInput value={d.value} onChange={(v) => setD({ ...d, value: v })} />}
        </L>
      </div>
      <div className='grid grid-cols-2 gap-3'>
        <L label='Minimum order'><MoneyInput value={d.min_subtotal} onChange={(v) => setD({ ...d, min_subtotal: v })} /></L>
        <L label='Max discount (optional)'><MoneyInput value={d.max_discount} onChange={(v) => setD({ ...d, max_discount: v || null })} /></L>
      </div>
      <div className='grid grid-cols-3 gap-3'>
        <L label='Ends on'><Input type='date' value={d.ends_at} onChange={(e) => setD({ ...d, ends_at: e.target.value })} /></L>
        <L label='Total uses'><Input type='number' min={1} value={d.max_uses ?? ''} onChange={(e) => setD({ ...d, max_uses: e.target.value ? Number(e.target.value) : null })} /></L>
        <L label='Per customer'><Input type='number' min={1} value={d.max_uses_per_customer ?? ''} onChange={(e) => setD({ ...d, max_uses_per_customer: e.target.value ? Number(e.target.value) : null })} /></L>
      </div>
      <label className='flex items-center gap-2 text-sm'><Switch checked={d.members_only} onCheckedChange={(v) => setD({ ...d, members_only: v })} /> Members only</label>
      <label className='flex items-center gap-2 text-sm'><Switch checked={d.is_active} onCheckedChange={(v) => setD({ ...d, is_active: v })} /> Active</label>
    </FormDialog>
  )
}

/* ---------- Shipping ---------- */

export function ShippingPage() {
  const zones = useQuery({ queryKey: ['zones'], queryFn: async () => (await api.get<{ data: Zone[] }>('/shipping-zones')).data.data })
  const couriers = useQuery({ queryKey: ['couriers'], queryFn: async () => (await api.get<{ data: Courier[] }>('/couriers')).data.data })
  return (
    <Page title='Shipping' description='Price per city/zone + weight. Members ship free over the threshold in Settings.'>
      <div className='mb-2 flex items-center justify-between'><h2 className='font-semibold'>Zones</h2><ZoneDialog /></div>
      <Table className='mb-8'>
        <TableHeader><TableRow><TableHead>Zone</TableHead><TableHead>Cities</TableHead><TableHead>First kg</TableHead><TableHead>Each extra kg</TableHead><TableHead>Delivery</TableHead><TableHead /></TableRow></TableHeader>
        <TableBody>
          {zones.data?.map((z) => (
            <TableRow key={z.id}>
              <TableCell>{z.name}{z.is_default && <span className='ms-2 text-xs text-muted-foreground'>(all other cities)</span>}{!z.is_active && ' (off)'}</TableCell>
              <TableCell className='max-w-xs truncate'>{z.cities.join(', ')}</TableCell>
              <TableCell>{formatMoney(z.base_fee)}</TableCell><TableCell>{formatMoney(z.per_kg_fee)}</TableCell>
              <TableCell>{z.eta_days_min ?? '?'}–{z.eta_days_max ?? '?'} days</TableCell>
              <TableCell className='text-end'><ZoneDialog zone={z} /></TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
      <div className='mb-2 flex items-center justify-between'><h2 className='font-semibold'>Couriers</h2><CourierDialog /></div>
      <Table>
        <TableHeader><TableRow><TableHead>Courier</TableHead><TableHead>Phone</TableHead><TableHead>Tracking link</TableHead><TableHead /></TableRow></TableHeader>
        <TableBody>
          {couriers.data?.map((c) => (
            <TableRow key={c.id}>
              <TableCell>{c.name}{!c.is_active && ' (off)'}</TableCell><TableCell>{c.phone}</TableCell>
              <TableCell className='max-w-xs truncate text-xs'>{c.tracking_url}</TableCell>
              <TableCell className='text-end'><CourierDialog courier={c} /></TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </Page>
  )
}

function ZoneDialog({ zone }: { zone?: Zone }) {
  const [d, setD] = useState({
    name: zone?.name ?? '', cities: zone?.cities.join(', ') ?? '', base_fee: zone?.base_fee ?? 15000, included_grams: zone?.included_grams ?? 1000,
    per_kg_fee: zone?.per_kg_fee ?? 5000, eta_days_min: zone?.eta_days_min ?? 1, eta_days_max: zone?.eta_days_max ?? 3,
    is_default: zone?.is_default ?? false, is_active: zone?.is_active ?? true, position: zone?.position ?? 0,
  })
  const save = useMutate(zone ? 'put' : 'post', ['zones'], 'Zone saved')
  return (
    <FormDialog title={zone ? `Edit ${zone.name}` : 'New zone'}
      trigger={zone ? <Button size='icon' variant='ghost'><Pencil /></Button> : <Button size='sm'><Plus /> New zone</Button>}
      onSave={() => save.mutateAsync({ path: zone ? `/shipping-zones/${zone.id}` : '/shipping-zones', body: { ...d, cities: d.cities.split(',').map((c) => c.trim()).filter(Boolean) } })}>
      <L label='Name'><Input value={d.name} onChange={(e) => setD({ ...d, name: e.target.value })} /></L>
      <L label='Cities (comma separated)'><Input value={d.cities} placeholder='Addis Ababa, Adama' onChange={(e) => setD({ ...d, cities: e.target.value })} /></L>
      <div className='grid grid-cols-2 gap-3'>
        <L label='Fee (first kg)'><MoneyInput value={d.base_fee} onChange={(v) => setD({ ...d, base_fee: v })} /></L>
        <L label='Each extra kg'><MoneyInput value={d.per_kg_fee} onChange={(v) => setD({ ...d, per_kg_fee: v })} /></L>
        <L label='Delivery from (days)'><Input type='number' value={d.eta_days_min} onChange={(e) => setD({ ...d, eta_days_min: Number(e.target.value) })} /></L>
        <L label='to (days)'><Input type='number' value={d.eta_days_max} onChange={(e) => setD({ ...d, eta_days_max: Number(e.target.value) })} /></L>
      </div>
      <label className='flex items-center gap-2 text-sm'><Switch checked={d.is_default} onCheckedChange={(v) => setD({ ...d, is_default: v })} /> Use for all cities not listed elsewhere</label>
      <label className='flex items-center gap-2 text-sm'><Switch checked={d.is_active} onCheckedChange={(v) => setD({ ...d, is_active: v })} /> Active</label>
    </FormDialog>
  )
}

function CourierDialog({ courier }: { courier?: Courier }) {
  const [d, setD] = useState({ name: courier?.name ?? '', phone: courier?.phone ?? '', tracking_url: courier?.tracking_url ?? '', is_active: courier?.is_active ?? true })
  const save = useMutate(courier ? 'put' : 'post', ['couriers'], 'Courier saved')
  return (
    <FormDialog title={courier ? `Edit ${courier.name}` : 'New courier'}
      trigger={courier ? <Button size='icon' variant='ghost'><Pencil /></Button> : <Button size='sm'><Plus /> New courier</Button>}
      onSave={() => save.mutateAsync({ path: courier ? `/couriers/${courier.id}` : '/couriers', body: { ...d, tracking_url: d.tracking_url || null } })}>
      <L label='Name'><Input value={d.name} placeholder='EMS, HudHud…' onChange={(e) => setD({ ...d, name: e.target.value })} /></L>
      <L label='Phone'><Input value={d.phone} onChange={(e) => setD({ ...d, phone: e.target.value })} /></L>
      <L label='Tracking link (use {number})'><Input value={d.tracking_url} placeholder='https://…?n={number}' onChange={(e) => setD({ ...d, tracking_url: e.target.value })} /></L>
      <label className='flex items-center gap-2 text-sm'><Switch checked={d.is_active} onCheckedChange={(v) => setD({ ...d, is_active: v })} /> Active</label>
    </FormDialog>
  )
}

function L({ label, children }: { label: string; children: React.ReactNode }) {
  return <div className='grid gap-1.5'><Label>{label}</Label>{children}</div>
}
