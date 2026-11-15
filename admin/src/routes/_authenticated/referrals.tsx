import { z } from 'zod'
import { createFileRoute } from '@tanstack/react-router'
import { ReferralTreePage } from '@/features/money/money-pages'

export const Route = createFileRoute('/_authenticated/referrals')({
  validateSearch: z.object({ user: z.coerce.number().optional() }),
  component: function ReferralsRoute() {
    const { user } = Route.useSearch()
    return <ReferralTreePage key={user} initialUser={user} />
  },
})
