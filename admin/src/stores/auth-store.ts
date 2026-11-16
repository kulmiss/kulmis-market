import { create } from 'zustand'
import { api } from '@/lib/api'
import { type CurrencyFormat, setCurrency } from '@/lib/money'

export type AdminUser = {
  id: number
  name: string
  email: string | null
  role: string
  role_label: string
  abilities: string[]
  two_factor: boolean
  two_factor_required: boolean
  currency?: CurrencyFormat
  setup_needed?: boolean
}

type AuthState = {
  user: AdminUser | null
  setUser: (user: AdminUser | null) => void
  can: (ability: string) => boolean
  fetchMe: () => Promise<AdminUser | null>
  signOut: () => Promise<void>
}

export const useAuthStore = create<AuthState>()((set, get) => ({
  user: null,
  setUser: (user) => {
    setCurrency(user?.currency)
    set({ user })
  },
  can: (ability) => {
    const abilities = get().user?.abilities ?? []
    return abilities.includes('*') || abilities.includes(ability)
  },
  fetchMe: async () => {
    try {
      const { data } = await api.get<AdminUser>('/auth/me')
      setCurrency(data.currency)
      set({ user: data })
      return data
    } catch {
      set({ user: null })
      return null
    }
  },
  signOut: async () => {
    await api.post('/auth/logout').catch(() => undefined)
    set({ user: null })
  },
}))
