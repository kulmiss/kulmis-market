import { type SVGProps } from 'react'
import { cn } from '@/lib/utils'

/** Kulmis Market mark, Logo A "smiling mesob" (same as the shop and the favicon; source resources/brand/logo-mark.svg). */
export function Logo({ className, ...props }: SVGProps<SVGSVGElement>) {
  return (
    <svg viewBox='0 0 64 64' xmlns='http://www.w3.org/2000/svg' height='24' width='24' className={cn('size-6', className)} aria-hidden='true' {...props}>
      <circle cx='32' cy='32' r='31' fill='#b5532b'/>
      <circle cx='32' cy='14.5' r='3.2' fill='#e9b949'/>
      <path d='M15 31c0-9.4 7.6-15 17-15s17 5.6 17 15z' fill='#fbf8f3'/>
      <path d='M20 26.5h24' stroke='#e9b949' strokeWidth='2.4' strokeLinecap='round' strokeDasharray='0.1 5.2'/>
      <path d='M17.5 34h29l-3.6 13.2a4 4 0 0 1-3.9 2.9H25a4 4 0 0 1-3.9-2.9z' fill='#fbf8f3'/>
      <path d='M25 39.5q7 6.5 14 0' stroke='#b5532b' strokeWidth='3' fill='none' strokeLinecap='round'/>
    </svg>
  )
}
