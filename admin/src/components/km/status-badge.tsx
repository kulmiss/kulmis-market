import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'

const tones: Record<string, string> = {
  good: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
  warn: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
  bad: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
  info: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300',
  muted: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
}

const map: Record<string, keyof typeof tones> = {
  active: 'good', paid: 'good', delivered: 'good', available: 'good', win: 'good', cleared: 'good', open: 'info',
  pending_payment: 'warn', pending: 'warn', awaiting_review: 'warn', requested: 'warn', grace: 'warn', making: 'info',
  shipped: 'info', on_hold: 'warn', partially_refunded: 'warn', breakeven: 'muted', draft: 'muted', archived: 'muted',
  cancelled: 'muted', ended: 'muted', skipped: 'muted', closed: 'muted', expired: 'bad', failed: 'bad', rejected: 'bad',
  refunded: 'bad', reversed: 'bad', loss: 'bad', suspended: 'bad', confirmed: 'bad',
  live: 'good', scheduled: 'info', sold_out: 'muted', off: 'muted',
}

/** Easy words for statuses (the data keeps its codes; CSV keeps the codes too). */
export const WORDS: Record<string, string> = {
  active: 'Active', paid: 'Paid', delivered: 'Delivered', available: 'Ready to use', win: 'Win', cleared: 'Cleared (OK)', open: 'Open',
  pending_payment: 'Waiting for payment', pending: 'Waiting', awaiting_review: 'Receipt to check', requested: 'Asked', grace: 'Late (grace days)',
  making: 'Being made', shipped: 'On its way', on_hold: 'Held for a check', partially_refunded: 'Part given back', breakeven: 'No gain, no loss',
  draft: 'Draft (hidden)', archived: 'Archived (hidden)', cancelled: 'Cancelled', ended: 'Ended', skipped: 'Not paid out', closed: 'Closed',
  expired: 'Ended', failed: 'Failed', rejected: 'Not approved', refunded: 'Money given back', reversed: 'Taken back', loss: 'Loss',
  suspended: 'Blocked', confirmed: 'Fraud (confirmed)', none: 'Not a member', not_active: 'Not active',
  live: 'Running now', scheduled: 'Starts later', sold_out: 'Sold out', off: 'Off',
}

/** In money lists "pending" means the money is on its way to the member. */
const MONEY_WORDS: Record<string, string> = { pending: 'On the way', available: 'Ready to use' }

export function statusWord(status: string, context?: 'money'): string {
  return (context === 'money' ? MONEY_WORDS[status] : undefined) ?? WORDS[status] ?? status.replaceAll('_', ' ')
}

/** Yes/no check with plain labels, e.g. "Set" / "Not set". */
export function Flag({ ok, yes = 'Set', no = 'Not set', noTone = 'warn' }: { ok: boolean; yes?: string; no?: string; noTone?: keyof typeof tones }) {
  return (
    <Badge variant='outline' className={cn('border-transparent', tones[ok ? 'good' : noTone])}>
      {ok ? yes : no}
    </Badge>
  )
}

export function StatusBadge({ status, className, context }: { status: string | null | undefined; className?: string; context?: 'money' }) {
  if (!status) return <span className='text-muted-foreground'>—</span>
  return (
    <Badge variant='outline' className={cn('border-transparent', tones[map[status] ?? 'muted'], className)} title={status}>
      {statusWord(status, context)}
    </Badge>
  )
}
