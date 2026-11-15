import { type SVGProps } from 'react'
import { cn } from '@/lib/utils'

/** Kulmis Market mark (same as the shop and the favicon). */
export function Logo({ className, ...props }: SVGProps<SVGSVGElement>) {
  return (
    <svg viewBox='0 0 32 32' xmlns='http://www.w3.org/2000/svg' height='24' width='24' className={cn('size-6', className)} aria-hidden='true' {...props}>
      <circle cx='16' cy='16' r='15' fill='#b5532b' />
      <path d='M8 20c4-8 12-8 16 0M10 14c3-4 9-4 12 0' stroke='#fbf8f3' strokeWidth='2' fill='none' strokeLinecap='round' />
    </svg>
  )
}
