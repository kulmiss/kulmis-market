import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'

const tones: Record<string, string> = {
  good: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
  warn: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
  bad: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
  info: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300',
  muted: 'bg-muted text-muted-foreground',
}

const map: Record<string, keyof typeof tones> = {
  active: 'good', paid: 'good', delivered: 'good', available: 'good', win: 'good', cleared: 'good', open: 'info',
  pending_payment: 'warn', pending: 'warn', awaiting_review: 'warn', requested: 'warn', grace: 'warn', making: 'info',
  shipped: 'info', on_hold: 'warn', partially_refunded: 'warn', breakeven: 'muted', draft: 'muted', archived: 'muted',
  cancelled: 'muted', ended: 'muted', skipped: 'muted', closed: 'muted', expired: 'bad', failed: 'bad', rejected: 'bad',
  refunded: 'bad', reversed: 'bad', loss: 'bad', suspended: 'bad', confirmed: 'bad',
}

export function StatusBadge({ status, className }: { status: string | null | undefined; className?: string }) {
  if (!status) return <span className='text-muted-foreground'>—</span>
  return (
    <Badge variant='outline' className={cn('border-transparent capitalize', tones[map[status] ?? 'muted'], className)}>
      {status.replaceAll('_', ' ')}
    </Badge>
  )
}
