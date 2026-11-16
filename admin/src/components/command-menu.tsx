import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import {
  ArrowRight, Banknote, Clock, CreditCard, Gift, Laptop, Moon, Package, PiggyBank, Plus, Radio, Receipt, Settings2, SlidersHorizontal,
  Sun, ToggleRight, Users, X, type LucideIcon,
} from 'lucide-react'
import { api } from '@/lib/api'
import { toSantim } from '@/lib/money'
import { cn } from '@/lib/utils'
import { useAuthStore } from '@/stores/auth-store'
import { useFeatures } from '@/stores/features-store'
import { useSearch } from '@/context/search-provider'
import { useTheme } from '@/context/theme-provider'
import { Button } from '@/components/ui/button'
import { CommandDialog, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList, CommandSeparator } from '@/components/ui/command'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { navLinks } from './layout/nav-access'

type Item = { id: number | string; title: string; sub: string; url: string }
type Group = { type: string; label: string; items: Item[] }
type Filters = { type: string; status: string; from: string; to: string; min: string; max: string }

const ICONS: Record<string, LucideIcon> = {
  members: Users, orders: Package, products: Gift, payments: Receipt, cashouts: Banknote, signals: Radio, coupons: CreditCard,
  badges: Gift, settings: Settings2,
}
const TYPES = [
  ['members', 'Members'], ['orders', 'Orders'], ['products', 'Products'], ['payments', 'Payments and receipts'], ['cashouts', 'Cash-outs'],
  ['signals', 'Signals'], ['coupons', 'Coupons'], ['badges', 'Badges'], ['settings', 'Settings'],
] as const
const RECENT_KEY = 'km.search.recent'
const EMPTY: Filters = { type: 'all', status: '', from: '', to: '', min: '', max: '' }

function loadRecent(): string[] {
  try { return JSON.parse(localStorage.getItem(RECENT_KEY) ?? '[]') as string[] } catch { return [] }
}
function saveRecent(q: string) {
  try { localStorage.setItem(RECENT_KEY, JSON.stringify([q, ...loadRecent().filter((r) => r !== q)].slice(0, 8))) } catch { /* private mode */ }
}

function useDebounced<V>(value: V, ms: number): V {
  const [v, setV] = useState(value)
  useEffect(() => {
    const t = setTimeout(() => setV(value), ms)
    return () => clearTimeout(t)
  }, [value, ms])
  return v
}

/**
 * Global search (Ctrl/⌘ K): members, orders, products, payments and receipts,
 * cash-outs, signals, coupons, badges, settings and admin pages, grouped.
 * Only what the signed-in role may open. Recent searches and quick actions.
 * Advanced: type, status, dates, amounts; "#123" finds by ID, "…" exact phrase.
 */
export function CommandMenu() {
  const navigate = useNavigate()
  const { setTheme } = useTheme()
  const { open, setOpen } = useSearch()
  const can = useAuthStore((s) => s.can)
  const featureOn = useFeatures((s) => s.on)
  const [q, setQ] = useState('')
  const [advanced, setAdvanced] = useState(false)
  const [f, setF] = useState<Filters>(EMPTY)
  const [recent, setRecent] = useState<string[]>(loadRecent)
  const dq = useDebounced(q.trim(), 150)
  const df = useDebounced(f, 300)

  const params = useMemo(() => {
    const p: Record<string, string | number> = { q: dq }
    if (df.type !== 'all') p.type = df.type
    if (df.status) p.status = df.status.trim().toLowerCase().replaceAll(' ', '_')
    if (df.from) p.from = df.from
    if (df.to) p.to = df.to
    if (df.min) p.min = toSantim(df.min)
    if (df.max) p.max = toSantim(df.max)
    return p
  }, [dq, df])

  const results = useQuery({
    queryKey: ['global-search', params],
    enabled: open && dq.length >= 2,
    staleTime: 10_000,
    queryFn: async ({ signal }) => (await api.get<{ data: { groups: Group[]; took_ms: number } }>('/search', { params, signal })).data.data,
  })

  const pages = useMemo(() => navLinks.filter((l) => (!l.ability || can(l.ability)) && featureOn(l.feature)), [can, featureOn])
  const actions = useMemo(() => [
    { title: 'Add a product', icon: Plus, url: '/products/new', ok: can('manage-products') && featureOn('shop') },
    { title: 'Check receipts waiting', icon: Receipt, url: '/payments', ok: can('approve-payments') },
    { title: 'Pay cash-outs', icon: Banknote, url: '/cash-outs', ok: can('approve-cashouts') && featureOn('wallet') },
    { title: 'Post a signal', icon: Radio, url: '/signals', ok: can('manage-signals') && featureOn('signals') },
    { title: 'Badge bonus: check and pay', icon: PiggyBank, url: '/badge-bonus', ok: can('manage-settings') && featureOn('badges.bonus') },
    { title: 'Turn features on or off', icon: ToggleRight, url: '/features', ok: can('manage-features') },
  ].filter((a) => a.ok), [can, featureOn])

  const words = q.trim().toLowerCase()
  const matchPages = words ? pages.filter((p) => p.title.toLowerCase().includes(words)).slice(0, 6) : pages
  const matchActions = words ? actions.filter((a) => a.title.toLowerCase().includes(words)) : actions

  function go(url: string, remember = true) {
    if (remember && q.trim().length >= 2) {
      saveRecent(q.trim())
      setRecent(loadRecent())
    }
    setOpen(false)
    const [path, search] = url.split('?')
    navigate({ to: path as never, search: (search ? Object.fromEntries(new URLSearchParams(search)) : {}) as never })
  }

  const activeFilters = Object.entries(f).filter(([k, v]) => (k === 'type' ? v !== 'all' : v !== '')).length

  return (
    <CommandDialog modal open={open} onOpenChange={(o) => { setOpen(o); if (!o) setQ('') }} shouldFilter={false}
      title='Search' description='Search members, orders, payments and more' className='sm:max-w-2xl'>
      <CommandInput placeholder='Search name, phone, order, receipt, #ID…' value={q} onValueChange={setQ} aria-label='Search everything' />
      <div className='flex flex-wrap items-center gap-2 border-b px-3 py-2 text-xs text-muted-foreground'>
        <Button type='button' variant={advanced ? 'secondary' : 'ghost'} size='sm' aria-expanded={advanced} onClick={() => setAdvanced(!advanced)}>
          <SlidersHorizontal /> Advanced{activeFilters ? ` (${activeFilters})` : ''}
        </Button>
        {activeFilters > 0 && <Button type='button' variant='ghost' size='sm' onClick={() => setF(EMPTY)}><X /> Clear filters</Button>}
        <span className='ms-auto hidden sm:inline'>Tip: <kbd className='rounded border px-1'>#123</kbd> finds by ID, <kbd className='rounded border px-1'>"…"</kbd> exact words</span>
      </div>
      {advanced && (
        <div className='grid grid-cols-2 gap-2 border-b p-3 sm:grid-cols-3'>
          <Select value={f.type} onValueChange={(v) => setF({ ...f, type: v })}>
            <SelectTrigger className='w-full' aria-label='Look in'><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value='all'>Look in: everything</SelectItem>
              {TYPES.map(([v, l]) => <SelectItem key={v} value={v}>{l}</SelectItem>)}
            </SelectContent>
          </Select>
          <Input placeholder='Status, e.g. paid' aria-label='Status' value={f.status} onChange={(e) => setF({ ...f, status: e.target.value })} />
          <Input type='date' aria-label='From date' value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} />
          <Input type='date' aria-label='To date' value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} />
          <Input inputMode='decimal' placeholder='Amount from' aria-label='Amount from' value={f.min} onChange={(e) => setF({ ...f, min: e.target.value.replace(/[^\d.]/g, '') })} />
          <Input inputMode='decimal' placeholder='Amount up to' aria-label='Amount up to' value={f.max} onChange={(e) => setF({ ...f, max: e.target.value.replace(/[^\d.]/g, '') })} />
        </div>
      )}
      <CommandList className='max-h-[min(60vh,28rem)]'>
        {dq.length >= 2 && !results.isFetching && (results.data?.groups.length ?? 0) === 0 && matchPages.length === 0 && matchActions.length === 0 && (
          <CommandEmpty>Nothing found for “{dq}”. Try fewer letters, a phone number or #ID.</CommandEmpty>
        )}
        {q.trim().length < 2 && recent.length > 0 && (
          <CommandGroup heading='Recent searches'>
            {recent.map((r) => (
              <CommandItem key={r} value={`recent-${r}`} onSelect={() => setQ(r)}><Clock className='text-muted-foreground' /> {r}</CommandItem>
            ))}
          </CommandGroup>
        )}
        {results.data?.groups.map((g) => {
          const Icon = ICONS[g.type] ?? ArrowRight
          return (
            <CommandGroup key={g.type} heading={g.label}>
              {g.items.map((it) => (
                <CommandItem key={`${g.type}-${it.id}`} value={`${g.type}-${it.id}`} onSelect={() => go(it.url)} className='min-h-12'>
                  <Icon className='text-muted-foreground' />
                  <span className='min-w-0'>
                    <span className='block truncate font-medium'>{it.title}</span>
                    <span className='block truncate text-xs text-muted-foreground'>{it.sub}</span>
                  </span>
                </CommandItem>
              ))}
            </CommandGroup>
          )
        })}
        {matchActions.length > 0 && (
          <CommandGroup heading='Quick actions'>
            {matchActions.map((a) => (
              <CommandItem key={a.url + a.title} value={`action-${a.title}`} onSelect={() => go(a.url, false)} className='min-h-12'><a.icon className='text-muted-foreground' /> {a.title}</CommandItem>
            ))}
          </CommandGroup>
        )}
        {matchPages.length > 0 && (
          <CommandGroup heading='Admin pages'>
            {matchPages.map((p) => (
              <CommandItem key={String(p.url)} value={`page-${p.title}`} onSelect={() => go(String(p.url), false)} className='min-h-12'>
                {p.icon ? <p.icon className='text-muted-foreground' /> : <ArrowRight className='text-muted-foreground' />} {p.title}
              </CommandItem>
            ))}
          </CommandGroup>
        )}
        {!words && (
          <>
            <CommandSeparator />
            <CommandGroup heading='Look'>
              <CommandItem value='theme-light' onSelect={() => { setOpen(false); setTheme('light') }}><Sun /> Light</CommandItem>
              <CommandItem value='theme-dark' onSelect={() => { setOpen(false); setTheme('dark') }}><Moon /> Dark</CommandItem>
              <CommandItem value='theme-system' onSelect={() => { setOpen(false); setTheme('system') }}><Laptop /> Same as this device</CommandItem>
            </CommandGroup>
          </>
        )}
      </CommandList>
      <div className={cn('flex justify-between border-t px-3 py-1.5 text-[11px] text-muted-foreground', !results.data && 'hidden')}>
        <span>{results.isFetching ? 'Searching…' : `${results.data?.groups.reduce((n, g) => n + g.items.length, 0) ?? 0} found`}</span>
        <span>{results.data ? `${results.data.took_ms} ms` : ''}</span>
      </div>
    </CommandDialog>
  )
}
