import { useMemo, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { CircleSlash, Search, TriangleAlert } from 'lucide-react'
import { toast } from 'sonner'
import { api, errorMessage } from '@/lib/api'
import { type FeatureRow, useFeatures } from '@/stores/features-store'
import { formatDate, formatMoney } from '@/lib/money'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { Segmented } from '@/components/km/segmented'
import { Page } from '@/components/km/page'
import { PageSkeleton } from '@/components/km/page-skeleton'

type Show = 'all' | 'on' | 'off'
type Paid = { feature: string; people: number; last_until: string | null; unused_total: number; can_credit: boolean }
type Impact = { key: string; lines: string[]; paid: Paid | null }
type OffText = { where: string; title: string; features: string[]; what_happens: string; link: string }
const PAID = ['membership', 'signals']

/** Lower-case words for search: label, description, section and key. */
function haystack(r: FeatureRow) {
  return `${r.label} ${r.description} ${r.group} ${r.key}`.toLowerCase()
}

function matches(r: FeatureRow, q: string, show: Show) {
  if (show === 'on' && !r.effective) return false
  if (show === 'off' && r.effective) return false
  const words = q.toLowerCase().split(/\s+/).filter(Boolean)
  const text = haystack(r)
  return words.every((w) => text.includes(w))
}

const sectionId = (g: string) => `sw-${g.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`

/** Every switch, grouped by section, with search and an on/off overview. */
export function FeaturesPage() {
  const qc = useQueryClient()
  const setRows = useFeatures((s) => s.setRows)
  // ?q=coupons (from the top-bar search) starts with that search.
  const [q, setQ] = useState(() => new URLSearchParams(window.location.search).get('q') ?? '')
  const [show, setShow] = useState<Show>('all')
  const { data, isLoading } = useQuery({
    queryKey: ['features'],
    queryFn: async () => (await api.get<{ data: FeatureRow[] }>('/features')).data.data,
  })

  // Switching off first shows what it means (who keeps paid access, open work).
  const [asking, setAsking] = useState<{ row: FeatureRow; impact: Impact } | null>(null)
  async function ask(row: FeatureRow, enabled: boolean) {
    if (enabled) return toggle(row, true)
    try {
      const { data } = await api.get<{ data: Impact }>(`/features/impact/${row.key}`)
      setAsking({ row, impact: data.data })
    } catch {
      toggle(row, false)
    }
  }

  async function toggle(row: FeatureRow, enabled: boolean) {
    try {
      const res = await api.put<{ data: FeatureRow[] }>('/features', { features: { [row.key]: enabled } })
      qc.setQueryData(['features'], res.data.data)
      setRows(res.data.data)
      toast.success(`${row.label} is now ${enabled ? 'on' : 'off'}`)
      qc.invalidateQueries({ queryKey: ['feature-texts'] })
      qc.invalidateQueries({ queryKey: ['paid-access'] })
    } catch (e) {
      toast.error(errorMessage(e))
    }
  }

  const rows = useMemo(() => data ?? [], [data])
  const groups = useMemo(() => [...new Set(rows.map((r) => r.group))], [rows])
  const visible = useMemo(() => rows.filter((r) => matches(r, q, show)), [rows, q, show])
  const onCount = rows.filter((r) => r.effective).length
  const byKey = useMemo(() => new Map(rows.map((r) => [r.key, r])), [rows])

  if (isLoading) return <PageSkeleton cards={1} rows={8} />

  return (
    <Page
      title='Feature switches'
      description='Turn parts of the shop on or off. Off: customers and members see nothing of it, as if it never existed (pages, app, messages, background work). Its admin pages and history stay under "Switched-off features". Nothing is deleted.'
    >
      {/* Overview */}
      <Card className='mb-4'>
        <CardContent className='grid gap-3 pt-6'>
          <p className='text-sm'>
            <b className='text-2xl tabular-nums'>{onCount}</b> on · <b className='text-2xl tabular-nums'>{rows.length - onCount}</b> off
          </p>
          <nav aria-label='Sections' className='flex flex-wrap gap-2'>
            {groups.map((g) => {
              const inGroup = rows.filter((r) => r.group === g)
              const on = inGroup.filter((r) => r.effective).length
              return (
                <a
                  key={g}
                  href={`#${sectionId(g)}`}
                  className='inline-flex min-h-12 items-center gap-2 rounded-md border px-3 text-sm hover:bg-accent'
                >
                  {g}
                  <span className={on === inGroup.length ? 'text-muted-foreground tabular-nums' : 'font-semibold text-amber-700 tabular-nums dark:text-amber-400'}>
                    {on}/{inGroup.length}
                  </span>
                </a>
              )
            })}
          </nav>
        </CardContent>
      </Card>

      {/* Search + show */}
      <div className='mb-4 flex flex-col gap-3 sm:flex-row sm:items-center'>
        <div className='relative flex-1'>
          <Search className='pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground' />
          <Input
            type='search'
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder='Search switches, e.g. "coupon" or "sms"'
            aria-label='Search switches'
            className='h-12 ps-9'
          />
        </div>
        <Segmented<Show> label='Show' value={show} onChange={setShow} className='h-12' itemClassName='px-4'
          options={[{ value: 'all', label: 'All' }, { value: 'on', label: 'On' }, { value: 'off', label: 'Off' }]} />
      </div>

      <OffTexts />

      {visible.length === 0 && <p className='py-10 text-center text-sm text-muted-foreground'>No switch matches. Try another word.</p>}

      <div className='grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3'>
        {groups.map((g) => {
          const list = visible.filter((r) => r.group === g)
          if (list.length === 0) return null
          return (
            <Card key={g} id={sectionId(g)} className='scroll-mt-20'>
              <CardHeader className='pb-2'><CardTitle className='text-base'>{g}</CardTitle></CardHeader>
              <CardContent className='grid gap-1'>
                {list.map((r) => {
                  const parent = r.parent ? byKey.get(r.parent) : undefined
                  return (
                    <div key={r.key}>
                    <label className='flex min-h-12 cursor-pointer items-center justify-between gap-3 rounded-md px-2 py-2 hover:bg-accent/50'>
                      <span className='grid min-w-0 gap-0.5'>
                        <span className='text-sm font-medium'>{r.label}</span>
                        {r.description && <span className='text-xs text-muted-foreground'>{r.description}</span>}
                        {r.enabled && !r.effective && (
                          <Badge variant='outline' className='w-fit gap-1 text-[11px]'>
                            <CircleSlash className='size-3' /> Off because "{parent?.label ?? r.parent}" is off
                          </Badge>
                        )}
                      </span>
                      <Switch checked={r.enabled} onCheckedChange={(v) => ask(r, v)} aria-label={r.label} className='shrink-0' />
                    </label>
                    {PAID.includes(r.key) && !r.effective && <EndAccess feature={r.key} label={r.label} />}
                    </div>
                  )
                })}
              </CardContent>
            </Card>
          )
        })}
      </div>

      {asking && (
        <ConfirmDialog
          open
          onOpenChange={(o) => !o && setAsking(null)}
          title={`Switch off ${asking.row.label}?`}
          desc={
            <ul className='grid list-disc gap-2 ps-5 text-start text-sm'>
              {asking.impact.lines.map((l) => <li key={l}>{l}</li>)}
            </ul>
          }
          confirmText='Switch off'
          destructive
          handleConfirm={() => {
            const row = asking.row
            setAsking(null)
            toggle(row, false)
          }}
        />
      )}
    </Page>
  )
}

/** Membership or signals is off but people still have paid days: end it now, with money back if wanted. */
function EndAccess({ feature, label }: { feature: string; label: string }) {
  const qc = useQueryClient()
  const [open, setOpen] = useState(false)
  const [credit, setCredit] = useState(true)
  const [busy, setBusy] = useState(false)
  const { data } = useQuery({
    queryKey: ['paid-access', feature],
    queryFn: async () => (await api.get<{ data: Paid }>(`/features/paid-access/${feature}`)).data.data,
  })
  if (!data || data.people === 0) return null
  const who = `${data.people} ${data.people === 1 ? 'person keeps' : 'people keep'} ${label.toLowerCase()} until their month ends`
  return (
    <div className='mx-2 mb-2 grid gap-2 rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-xs'>
      <p>{who} (the last on {formatDate(data.last_until ?? '')}).</p>
      <Button size='sm' variant='outline' className='min-h-10 w-fit' onClick={() => setOpen(true)}>End access now</Button>
      <ConfirmDialog
        open={open}
        onOpenChange={setOpen}
        title={`End ${label.toLowerCase()} for everyone now?`}
        desc={
          <span className='grid gap-3 text-start text-sm'>
            <span>{who}. They lose it now{feature === 'membership' ? ', and leave the signals channel' : ' and leave the channel'}.</span>
            {data.can_credit && (
              <label className='flex min-h-10 items-center gap-2'>
                <Checkbox checked={credit} onCheckedChange={(v) => setCredit(v === true)} />
                Add the days they did not use to their wallet ({formatMoney(data.unused_total)} in all, worked out from what each month cost)
              </label>
            )}
          </span>
        }
        confirmText='End access now'
        destructive
        isLoading={busy}
        handleConfirm={async () => {
          setBusy(true)
          try {
            const res = await api.post<{ data: { ended: number; credited: number; credit_total: number } }>(`/features/paid-access/${feature}/end`, { credit: data.can_credit && credit })
            const r = res.data.data
            toast.success(`Ended for ${r.ended}${r.credited ? ` · ${formatMoney(r.credit_total)} added to ${r.credited} wallets` : ''}`)
            setOpen(false)
            qc.invalidateQueries({ queryKey: ['paid-access', feature] })
          } catch (e) {
            toast.error(errorMessage(e))
          } finally {
            setBusy(false)
          }
        }}
      />
    </div>
  )
}

/** Texts you edit that still talk about a switched-off feature. */
function OffTexts() {
  const { data } = useQuery({
    queryKey: ['feature-texts'],
    queryFn: async () => (await api.get<{ data: OffText[] }>('/features/texts')).data.data,
  })
  if (!data || data.length === 0) return null
  return (
    <Card className='mb-4 border-amber-500/40'>
      <CardHeader className='pb-2'>
        <CardTitle className='flex items-center gap-2 text-base'><TriangleAlert className='size-4 text-amber-600' /> Texts that mention a switched-off feature</CardTitle>
      </CardHeader>
      <CardContent className='grid gap-2'>
        {data.map((t) => (
          <a key={`${t.where}-${t.title}`} href={`/admin${t.link}`} className='grid min-h-12 gap-0.5 rounded-md px-2 py-2 text-sm hover:bg-accent/50'>
            <span><b>{t.where}:</b> {t.title} — mentions {t.features.join(', ')}</span>
            <span className='text-xs text-muted-foreground'>{t.what_happens}</span>
          </a>
        ))}
      </CardContent>
    </Card>
  )
}
