import { useMemo, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Save } from 'lucide-react'
import { toast } from 'sonner'
import { api, errorMessage } from '@/lib/api'
import { formatBps, formatETB } from '@/lib/money'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { MoneyInput } from '@/components/km/money-input'
import { Page } from '@/components/km/page'

type SettingRow = { key: string; type: string; label: string; group: string; value: unknown; default: unknown }

/** Every price, percentage, minimum, period and text. */
export function SettingsPage() {
  const qc = useQueryClient()
  const { data } = useQuery({ queryKey: ['settings'], queryFn: async () => (await api.get<{ data: SettingRow[] }>('/settings')).data.data })
  const [draft, setDraft] = useState<Record<string, unknown>>({})
  const [reason, setReason] = useState('')
  const [group, setGroup] = useState<string>('')

  const rows = useMemo(() => data ?? [], [data])
  const groups = useMemo(() => [...new Set(rows.map((r) => r.group))], [rows])
  const current = group || groups[0] || ''

  const changed = Object.keys(draft).filter((k) => draft[k] !== rows.find((r) => r.key === k)?.value)

  async function save() {
    try {
      const body = { settings: Object.fromEntries(changed.map((k) => [k, draft[k]])), reason: reason || undefined }
      const res = await api.put<{ data: SettingRow[] }>('/settings', body)
      qc.setQueryData(['settings'], res.data.data)
      setDraft({})
      setReason('')
      toast.success(`Saved ${changed.length} setting(s)`)
    } catch (e) {
      toast.error(errorMessage(e))
    }
  }

  const value = (r: SettingRow) => (r.key in draft ? draft[r.key] : r.value)
  const set = (k: string, v: unknown) => setDraft((d) => ({ ...d, [k]: v }))

  return (
    <Page title='Settings' description='Prices, rates, minimums, periods and texts. Changes are logged.'
      actions={<>
        <Input className='h-9 w-full sm:w-64' placeholder='Reason (optional, logged)' value={reason} onChange={(e) => setReason(e.target.value)} />
        <Button onClick={save} disabled={changed.length === 0}><Save /> Save {changed.length > 0 ? `(${changed.length})` : ''}</Button>
      </>}>
      <div className='mb-4 flex flex-wrap gap-2'>
        {groups.map((g) => (
          <Button key={g} size='sm' variant={g === current ? 'default' : 'outline'} onClick={() => setGroup(g)}>{g}</Button>
        ))}
      </div>
      <Card>
        <CardHeader><CardTitle className='text-base'>{current}</CardTitle></CardHeader>
        <CardContent className='grid grid-cols-1 gap-5 md:grid-cols-2'>
          {rows.filter((r) => r.group === current).map((r) => (
            <div key={r.key} className={r.type === 'text' ? 'grid gap-2 md:col-span-2' : 'grid gap-2'}>
              <Label htmlFor={r.key} className='flex justify-between gap-2'>
                <span>{r.label}</span>
                <span className='font-normal text-muted-foreground'>default {describe(r.type, r.default)}</span>
              </Label>
              <Field row={r} value={value(r)} onChange={(v) => set(r.key, v)} />
            </div>
          ))}
        </CardContent>
      </Card>
    </Page>
  )
}

function describe(type: string, v: unknown): string {
  if (type === 'money') return formatETB(Number(v))
  if (type === 'bps') return formatBps(Number(v))
  if (type === 'ratio') return `${Number(v) / 100}×`
  if (type === 'bool') return v ? 'on' : 'off'
  if (type === 'text') return ''
  return String(v)
}

function Field({ row, value, onChange }: { row: SettingRow; value: unknown; onChange: (v: unknown) => void }) {
  switch (row.type) {
    case 'money':
      return <MoneyInput id={row.key} value={Number(value)} onChange={onChange} />
    case 'bps':
      return (
        <div className='relative'>
          <Input id={row.key} inputMode='decimal' value={Number(value) / 100} onChange={(e) => onChange(Math.round(Number(e.target.value) * 100))} className='pe-8' />
          <span className='absolute inset-y-0 end-3 flex items-center text-xs text-muted-foreground'>%</span>
        </div>
      )
    case 'ratio':
      return <Input id={row.key} inputMode='decimal' value={Number(value) / 100} onChange={(e) => onChange(Math.round(Number(e.target.value) * 100))} />
    case 'int':
      return <Input id={row.key} type='number' min={0} value={Number(value)} onChange={(e) => onChange(Number(e.target.value))} />
    case 'bool':
      return <Switch id={row.key} checked={Boolean(value)} onCheckedChange={onChange} />
    case 'text':
      return <Textarea id={row.key} rows={3} value={String(value ?? '')} onChange={(e) => onChange(e.target.value)} />
    default:
      return <Input id={row.key} value={String(value ?? '')} onChange={(e) => onChange(e.target.value)} />
  }
}
