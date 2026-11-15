import { Skeleton } from '@/components/ui/skeleton'

/** Shown while a screen loads: the shape of the page instead of a spinner or "Loading…". */
export function PageSkeleton({ cards = 4, rows = 6 }: { cards?: number; rows?: number }) {
  return (
    <div className='grid gap-4' aria-busy='true' aria-label='Loading'>
      {cards > 0 && (
        <div className='grid grid-cols-2 gap-4 lg:grid-cols-4'>
          {Array.from({ length: cards }, (_, i) => <Skeleton key={i} className='h-24' />)}
        </div>
      )}
      <Skeleton className='h-64' />
      <div className='grid gap-2'>
        {Array.from({ length: rows }, (_, i) => <Skeleton key={i} className='h-9' />)}
      </div>
    </div>
  )
}
