import { createFileRoute, redirect } from '@tanstack/react-router'
import { useAuthStore } from '@/stores/auth-store'
import { TwoFactorSetup } from '@/features/two-factor'

export const Route = createFileRoute('/(auth)/two-factor-setup')({
  beforeLoad: async () => {
    const user = useAuthStore.getState().user ?? (await useAuthStore.getState().fetchMe())
    if (!user) throw redirect({ to: '/sign-in' })
  },
  component: TwoFactorSetup,
})
