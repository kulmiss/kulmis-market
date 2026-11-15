import { useMemo, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Save } from 'lucide-react'
import { toast } from 'sonner'
import { api, errorMessage } from '@/lib/api'
import { type CurrencyFormat, formatBps, formatMoney, setCurrency } from '@/lib/money'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { MoneyInput } from '@/components/km/money-input'
import { Page } from '@/components/km/page'

type SettingRow = { key: string; type: string; label: string; group: string; value: unknown; default: unknown; options?: string[] }

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
  const currencyChanged = changed.some((k) => k.startsWith('currency.'))
  const [confirmOpen, setConfirmOpen] = useState(false)

  async function save(confirmCurrency = false) {
    if (currencyChanged && !confirmCurrency) {
      setConfirmOpen(true)
      return
    }
    try {
      const body = { settings: Object.fromEntries(changed.map((k) => [k, draft[k]])), reason: reason || undefined, confirm_currency_change: confirmCurrency || undefined }
      const res = await api.put<{ data: SettingRow[] }>('/settings', body)
      qc.setQueryData(['settings'], res.data.data)
      if (currencyChanged) setCurrency(currencyFrom(res.data.data, {}))
      setDraft({})
      setReason('')
      setConfirmOpen(false)
      toast.success(`Saved ${changed.length} setting(s)`)
    } catch (e) {
      toast.error(errorMessage(e))
    }
  }

  const value = (r: SettingRow) => (r.key in draft ? draft[r.key] : r.value)
  const preview = currencyFrom(rows, draft)
  const set = (k: string, v: unknown) => setDraft((d) => ({ ...d, [k]: v }))

  return (
    <Page title='Settings' description='Prices, rates, minimums, periods and texts. Changes are logged.'
      actions={<>
        <Input className='h-9 w-full sm:w-64' placeholder='Reason (optional, logged)' value={reason} onChange={(e) => setReason(e.target.value)} />
        <Button onClick={() => save()} disabled={changed.length === 0}><Save /> {changed.length > 1 ? `Save ${changed.length} changes` : 'Save change'}</Button>
      </>}>
      <div className='mb-4 flex flex-wrap gap-2'>
        {groups.map((g) => (
          <Button key={g} size='sm' variant={g === current ? 'default' : 'outline'} onClick={() => setGroup(g)}>{g}</Button>
        ))}
      </div>
      <Card>
        <CardHeader><CardTitle className='text-base'>{current}</CardTitle></CardHeader>
        <CardContent className='grid grid-cols-1 gap-5 md:grid-cols-2'>
          {current === 'General' && (
            <div className='rounded-md border bg-muted/40 p-3 text-sm md:col-span-2' data-testid='currency-preview'>
              Prices will look like <b>{formatMoney(123456789, true, preview)}</b>, <b>{formatMoney(500000, true, preview)}</b> and <b>{formatMoney(1250, true, preview)}</b>.
              <span className='block text-muted-foreground'>Changing the currency does not convert prices, balances or earnings: the numbers stay the same.</span>
            </div>
          )}
          {rows.filter((r) => r.group === current).map((r) => (
            <div key={r.key} className={r.type === 'text' ? 'grid gap-2 md:col-span-2' : 'grid gap-2'}>
              <Label htmlFor={r.key} className='flex justify-between gap-2'>
                <span>{r.label}</span>
                <span className='font-normal text-muted-foreground'>default {describe(r.type, r.default)}</span>
              </Label>
              <Field row={r} value={value(r)} onChange={(v) => set(r.key, v)} preview={preview} />
            </div>
          ))}
        </CardContent>
      </Card>
      <ConfirmDialog open={confirmOpen} onOpenChange={setConfirmOpen} title='Change the currency?'
        desc={<span>Existing prices, wallet balances, earnings and orders are <b>not converted</b>. They keep their numbers and only the symbol and format change, for example {formatMoney(500000)} will show as <b>{formatMoney(500000, true, preview)}</b>.</span>}
        confirmText='Yes, change it' destructive handleConfirm={() => save(true)} />
    </Page>
  )
}

function currencyFrom(rows: SettingRow[], draft: Record<string, unknown>): CurrencyFormat {
  const v = (k: string, d: string) => String(k in draft ? draft[k] : (rows.find((r) => r.key === k)?.value ?? d))
  const t = v('currency.thousands', ',')
  return {
    code: v('currency.code', 'ETB').toUpperCase(),
    symbol: v('currency.symbol', 'ETB'),
    position: v('currency.position', 'after_space') as CurrencyFormat['position'],
    decimals: v('currency.decimals', 'auto') as CurrencyFormat['decimals'],
    thousands_char: t === 'space' ? '\u00A0' : t === 'none' ? '' : t,
    decimal_point: v('currency.decimal_point', '.'),
  }
}

const CHOICE_LABELS: Record<string, Record<string, string>> = {
  'currency.decimals': { auto: 'Only when needed (5,000 / 12.50)', '0': 'Never (5,000)', '2': 'Always (5,000.00)' },
  'currency.thousands': { ',': 'Comma (1,000)', '.': 'Dot (1.000)', space: 'Space (1 000)', "'": "Apostrophe (1'000)", none: 'None (1000)' },
  'currency.decimal_point': { '.': 'Dot (0.50)', ',': 'Comma (0,50)' },
}

function choiceLabel(key: string, option: string, preview: CurrencyFormat): string {
  if (key === 'currency.position') return formatMoney(500000, true, { ...preview, position: option as CurrencyFormat['position'] })
  return CHOICE_LABELS[key]?.[option] ?? option
}

function describe(type: string, v: unknown): string {
  if (type === 'money') return formatMoney(Number(v))
  if (type === 'bps') return formatBps(Number(v))
  if (type === 'ratio') return `${Number(v) / 100}×`
  if (type === 'bool') return v ? 'on' : 'off'
  if (type === 'text') return ''
  if (type === 'choice') return v === ',' ? 'comma' : v === '.' ? 'dot' : String(v).replace('_', ' ')
  return String(v)
}

function Field({ row, value, onChange, preview }: { row: SettingRow; value: unknown; onChange: (v: unknown) => void; preview: CurrencyFormat }) {
  switch (row.type) {
    case 'choice':
      return (
        <Select value={String(value)} onValueChange={onChange}>
          <SelectTrigger id={row.key} className='w-full'><SelectValue /></SelectTrigger>
          <SelectContent>
            {(row.options ?? []).map((o) => <SelectItem key={o} value={o}>{choiceLabel(row.key, o, preview)}</SelectItem>)}
          </SelectContent>
        </Select>
      )
    case 'color':
      return (
        <div className='flex items-center gap-2'>
          <Input id={row.key} type='color' className='h-9 w-14 p-1' value={String(value)} onChange={(e) => onChange(e.target.value)} />
          <Input aria-label={`${row.label} (hex)`} className='w-32 font-mono' value={String(value)} onChange={(e) => onChange(e.target.value)} />
          <span className='rounded-md px-3 py-1.5 text-sm font-medium text-white' style={{ background: String(value) }}>Preview</span>
        </div>
      )
    case 'currency_code':
      return <Input id={row.key} maxLength={3} className='uppercase' value={String(value ?? '')} onChange={(e) => onChange(e.target.value.toUpperCase())} />
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
