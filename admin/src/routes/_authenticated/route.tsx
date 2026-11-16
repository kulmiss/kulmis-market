import { createFileRoute, redirect } from '@tanstack/react-router'
import { useAuthStore } from '@/stores/auth-store'
import { AuthenticatedLayout } from '@/components/layout/authenticated-layout'

export const Route = createFileRoute('/_authenticated')({
  beforeLoad: async ({ location }) => {
    const store = useAuthStore.getState()
    const user = store.user ?? (await store.fetchMe())
    if (!user) {
      throw redirect({ to: '/sign-in', search: { redirect: location.href } })
    }
    if (user.two_factor_required && !user.two_factor) {
      throw redirect({ to: '/two-factor-setup' })
    }
    // First sign-in of the Super Admin: the setup wizard instead of the dashboard (until Finish).
    if (user.setup_needed) {
      throw redirect({ to: '/setup' })
    }
  },
  component: AuthenticatedLayout,
})
