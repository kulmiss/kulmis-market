import { useMemo, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { CircleSlash, Search } from 'lucide-react'
import { toast } from 'sonner'
import { api, errorMessage } from '@/lib/api'
import { type FeatureRow, useFeatures } from '@/stores/features-store'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Page } from '@/components/km/page'
import { PageSkeleton } from '@/components/km/page-skeleton'

type Show = 'all' | 'on' | 'off'

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
  const [q, setQ] = useState('')
  const [show, setShow] = useState<Show>('all')
  const { data, isLoading } = useQuery({
    queryKey: ['features'],
    queryFn: async () => (await api.get<{ data: FeatureRow[] }>('/features')).data.data,
  })

  async function toggle(row: FeatureRow, enabled: boolean) {
    try {
      const res = await api.put<{ data: FeatureRow[] }>('/features', { features: { [row.key]: enabled } })
      qc.setQueryData(['features'], res.data.data)
      setRows(res.data.data)
      toast.success(`${row.label} is now ${enabled ? 'on' : 'off'}`)
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
      description='Turn parts of the shop on or off. Off hides it everywhere: pages, app, admin and background work. Nothing is deleted. Turn it on again and all is still there.'
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
                  <span className={on === inGroup.length ? 'text-muted-foreground tabular-nums' : 'font-semibold text-amber-600 tabular-nums dark:text-amber-400'}>
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
        <Tabs value={show} onValueChange={(v) => setShow(v as Show)}>
          <TabsList className='h-12'>
            <TabsTrigger value='all' className='min-h-10 px-4'>All</TabsTrigger>
            <TabsTrigger value='on' className='min-h-10 px-4'>On</TabsTrigger>
            <TabsTrigger value='off' className='min-h-10 px-4'>Off</TabsTrigger>
          </TabsList>
        </Tabs>
      </div>

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
                    <label key={r.key} className='flex min-h-12 cursor-pointer items-center justify-between gap-3 rounded-md px-2 py-2 hover:bg-accent/50'>
                      <span className='grid min-w-0 gap-0.5'>
                        <span className='text-sm font-medium'>{r.label}</span>
                        {r.description && <span className='text-xs text-muted-foreground'>{r.description}</span>}
                        {r.enabled && !r.effective && (
                          <Badge variant='outline' className='w-fit gap-1 text-[11px]'>
                            <CircleSlash className='size-3' /> Off because "{parent?.label ?? r.parent}" is off
                          </Badge>
                        )}
                      </span>
                      <Switch checked={r.enabled} onCheckedChange={(v) => toggle(r, v)} aria-label={r.label} className='shrink-0' />
                    </label>
                  )
                })}
              </CardContent>
            </Card>
          )
        })}
      </div>
    </Page>
  )
}
