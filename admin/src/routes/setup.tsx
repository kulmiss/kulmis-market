import { createFileRoute, redirect } from '@tanstack/react-router'
import { useAuthStore } from '@/stores/auth-store'
import { SetupWizard } from '@/features/setup'

export const Route = createFileRoute('/setup')({
  beforeLoad: async () => {
    const user = useAuthStore.getState().user ?? (await useAuthStore.getState().fetchMe())
    if (!user) throw redirect({ to: '/sign-in', search: { redirect: '/setup' } })
    if (user.two_factor_required && !user.two_factor) throw redirect({ to: '/two-factor-setup' })
    if (user.role !== 'super_admin') throw redirect({ to: '/' })
  },
  component: SetupWizard,
})
