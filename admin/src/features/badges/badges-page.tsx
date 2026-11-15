import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  Award, CalendarCheck, Coins, Crown, Flame, Gem, Gift, Handshake, Heart, Leaf, Medal, Network, Pencil, Plus, Rocket, ShoppingBag,
  Sparkles, Star, Sun, Target, Trash2, Trophy, Users, type LucideIcon,
} from 'lucide-react'
import { useMutate } from '@/hooks/use-mutate'
import { api } from '@/lib/api'
import { formatMoney } from '@/lib/money'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { FormDialog } from '@/components/km/form-dialog'
import { MoneyInput } from '@/components/km/money-input'
import { DataTable } from '@/components/km/data-table'
import { Page } from '@/components/km/page'

type Badge = { id: number; key: string; name: string; description: string; icon: string; color: string; rule: string; threshold: number; rank: number; is_active: boolean; holders_count: number; monthly_bonus: number; one_time_bonus: number }
type Rule = { key: string; label: string; unit: string }
type Data = { data: Badge[]; rules: Rule[]; icons: string[] }

/** Same names as App\Badges\BadgeIcons on the storefront. */
const ICONS: Record<string, LucideIcon> = {
  medal: Medal, star: Star, crown: Crown, gem: Gem, users: Users, network: Network, flame: Flame, heart: Heart, trophy: Trophy,
  coins: Coins, 'shopping-bag': ShoppingBag, rocket: Rocket, sparkles: Sparkles, leaf: Leaf, award: Award, handshake: Handshake,
  gift: Gift, sun: Sun, 'calendar-check': CalendarCheck, target: Target,
}

export function BadgeIcon({ name, className }: { name: string; className?: string }) {
  const Icon = ICONS[name] ?? Award
  return <Icon className={className} aria-hidden />
}

function thresholdText(b: Pick<Badge, 'threshold'>, rule?: Rule): string {
  switch (rule?.unit) {
    case 'money': return formatMoney(b.threshold)
    case 'rank': return `rank ${b.threshold} or better`
    case 'position': return `first ${b.threshold} members`
    default: return `${b.threshold} ${rule?.unit ?? ''}`
  }
}

/** Every badge: name, icon, colour, rule, threshold, on/off. New badges from the rules list. */
export function BadgesPage() {
  const q = useQuery({ queryKey: ['badges'], queryFn: async () => (await api.get<Data>('/badges')).data })
  const save = useMutate('put', ['badges'], 'Badge saved')
  const del = useMutate('delete', ['badges'], 'Badge deleted')
  const [deleting, setDeleting] = useState<Badge | null>(null)
  const rules = q.data?.rules ?? []
  const ruleOf = (k: string) => rules.find((r) => r.key === k)

  return (
    <Page title='Badges' description='Members unlock badges automatically (checked after every payment and once a day). Unlocked badges are kept.'
      actions={q.data && <BadgeDialog rules={rules} icons={q.data.icons} />}>
      <DataTable<Badge> id='badges' rows={q.data?.data ?? []} loading={q.isLoading} search={{ placeholder: 'Badge name' }} defaultPageSize={50}
        filters={[{ key: 'rule', label: 'Rule', options: rules.map((r) => ({ value: r.key, label: r.label })) }, { key: 'is_active', label: 'On/off', options: [{ value: 'true', label: 'On' }, { value: 'false', label: 'Off' }] }]}
        columns={[
          { header: 'Badge', accessorKey: 'name', meta: { sort: 'name' }, cell: ({ row }) => {
            const b = row.original
            return (
              <div className={`flex items-center gap-3 ${b.is_active ? '' : 'opacity-60'}`}>
                <span className='grid size-9 shrink-0 place-items-center rounded-full text-white' style={{ background: b.color }}><BadgeIcon name={b.icon} className='size-5' /></span>
                <div className='whitespace-normal'><div className='font-medium'>{b.name}</div><div className='text-xs text-muted-foreground'>{b.description}</div></div>
              </div>
            )
          } },
          { header: 'Rule', cell: ({ row }) => <span className='block max-w-56 text-sm whitespace-normal'>{ruleOf(row.original.rule)?.label ?? row.original.rule}</span>, meta: { sort: 'rule', csv: (b) => ruleOf(b.rule)?.label ?? b.rule } },
          { header: 'Unlocks at', cell: ({ row }) => thresholdText(row.original, ruleOf(row.original.rule)), meta: { sort: 'threshold', csv: (b) => b.threshold } },
          { header: 'Bonus', cell: ({ row }) => (row.original.monthly_bonus > 0 ? `${formatMoney(row.original.monthly_bonus)} / month` : row.original.one_time_bonus > 0 ? `${formatMoney(row.original.one_time_bonus)} once` : '—'), meta: { sort: 'monthly_bonus', align: 'end', csv: (b) => (b.monthly_bonus || b.one_time_bonus) / 100 } },
          { header: 'Importance', accessorKey: 'rank', meta: { sort: 'rank', align: 'end' } },
          { header: 'Members', accessorKey: 'holders_count', meta: { sort: 'holders_count', align: 'end' } },
          { header: 'On', cell: ({ row }) => <Switch checked={row.original.is_active} aria-label={`${row.original.name} on/off`} onCheckedChange={(v) => save.mutate({ path: `/badges/${row.original.id}`, body: { is_active: v } })} />, meta: { csv: (b) => (b.is_active ? 'on' : 'off') } },
          { header: '', id: 'edit', meta: { label: 'Edit' }, cell: ({ row }) => q.data && (
            <div className='text-end whitespace-nowrap'>
              <BadgeDialog badge={row.original} rules={rules} icons={q.data.icons} />
              <Button size='icon' variant='ghost' aria-label={`Delete ${row.original.name}`} disabled={row.original.holders_count > 0} title={row.original.holders_count > 0 ? 'Members have it: switch it off instead' : 'Delete'} onClick={() => setDeleting(row.original)}><Trash2 /></Button>
            </div>
          ) },
        ]} />
      <ConfirmDialog open={deleting !== null} onOpenChange={(o) => !o && setDeleting(null)} title={`Delete ${deleting?.name ?? ''}?`}
        desc='Nobody has this badge yet. It will be removed from the list.' destructive confirmText='Delete'
        handleConfirm={async () => { if (deleting) await del.mutateAsync({ path: `/badges/${deleting.id}` }); setDeleting(null) }} />
    </Page>
  )
}

function BadgeDialog({ badge, rules, icons: iconNames }: { badge?: Badge; rules: Rule[]; icons: string[] }) {
  const [d, setD] = useState({
    name: badge?.name ?? '', description: badge?.description ?? '', icon: badge?.icon ?? 'award', color: badge?.color ?? '#3f8a4e',
    rule: badge?.rule ?? 'active_level1', threshold: badge?.threshold ?? 5, rank: badge?.rank ?? 10, is_active: badge?.is_active ?? true,
    monthly_bonus: badge?.monthly_bonus ?? 0, one_time_bonus: badge?.one_time_bonus ?? 0,
  })
  const monthly = d.rule === 'active_level1'
  const save = useMutate(badge ? 'put' : 'post', ['badges'], 'Badge saved')
  const unit = rules.find((r) => r.key === d.rule)?.unit
  return (
    <FormDialog title={badge ? `Edit ${badge.name}` : 'New badge'}
      trigger={badge ? <Button size='icon' variant='ghost' aria-label={`Edit ${badge.name}`}><Pencil /></Button> : <Button><Plus /> New badge</Button>}
      onSave={() => save.mutateAsync({ path: badge ? `/badges/${badge.id}` : '/badges', body: { ...d, monthly_bonus: monthly ? d.monthly_bonus : 0, one_time_bonus: monthly ? 0 : d.one_time_bonus } })}>
      <div className='flex items-center gap-3 rounded-md border p-3'>
        <span className='grid size-12 place-items-center rounded-full text-white' style={{ background: d.color }}><BadgeIcon name={d.icon} className='size-6' /></span>
        <div><div className='font-semibold'>{d.name || 'Badge name'}</div><div className='text-xs text-muted-foreground'>{d.description || 'Short description'}</div></div>
      </div>
      <L label='Name'><Input value={d.name} maxLength={60} onChange={(e) => setD({ ...d, name: e.target.value })} /></L>
      <L label='Description'><Input value={d.description} maxLength={200} onChange={(e) => setD({ ...d, description: e.target.value })} /></L>
      <div className='grid grid-cols-[1fr_auto] gap-3'>
        <L label='Icon'>
          <Select value={d.icon} onValueChange={(v) => setD({ ...d, icon: v })}>
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>{iconNames.map((i) => <SelectItem key={i} value={i}><span className='flex items-center gap-2'><BadgeIcon name={i} className='size-4' />{i}</span></SelectItem>)}</SelectContent>
          </Select>
        </L>
        <L label='Colour'><Input type='color' className='h-9 w-16 p-1' value={d.color} onChange={(e) => setD({ ...d, color: e.target.value })} /></L>
      </div>
      <L label='Rule'>
        <Select value={d.rule} onValueChange={(v) => setD({ ...d, rule: v })}>
          <SelectTrigger><SelectValue /></SelectTrigger>
          <SelectContent>{rules.map((r) => <SelectItem key={r.key} value={r.key}>{r.label}</SelectItem>)}</SelectContent>
        </Select>
      </L>
      <div className='grid grid-cols-1 gap-3 sm:grid-cols-2'>
        <L label={unit === 'money' ? 'Unlocks at (earned)' : unit === 'rank' ? 'Rank (1 = top)' : unit === 'position' ? 'First N members' : `Unlocks at (${unit ?? 'count'})`}>
          {unit === 'money'
            ? <MoneyInput value={d.threshold} onChange={(v) => setD({ ...d, threshold: v })} />
            : <Input type='number' min={1} value={d.threshold} onChange={(e) => setD({ ...d, threshold: Number(e.target.value) })} />}
        </L>
        <L label='Importance (higher shows first)'><Input type='number' min={0} max={1000} value={d.rank} onChange={(e) => setD({ ...d, rank: Number(e.target.value) })} /></L>
      </div>
      {monthly
        ? <L label='Bonus every month (only the highest badge pays; 0 = none)'><MoneyInput value={d.monthly_bonus} onChange={(v) => setD({ ...d, monthly_bonus: v })} /></L>
        : <L label='One-time bonus when unlocked (0 = none)'><MoneyInput value={d.one_time_bonus} onChange={(v) => setD({ ...d, one_time_bonus: v })} /></L>}
      <label className='flex items-center gap-2 text-sm'><Switch checked={d.is_active} onCheckedChange={(v) => setD({ ...d, is_active: v })} /> On</label>
    </FormDialog>
  )
}

function L({ label, children }: { label: string; children: React.ReactNode }) {
  return <label className='grid gap-1.5 text-sm leading-none font-medium'>{label}{children}</label>
}
