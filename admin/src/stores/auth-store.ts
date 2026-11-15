import { create } from 'zustand'
import { api } from '@/lib/api'

export type AdminUser = {
  id: number
  name: string
  email: string | null
  role: string
  role_label: string
  abilities: string[]
  two_factor: boolean
  two_factor_required: boolean
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
  setUser: (user) => set({ user }),
  can: (ability) => {
    const abilities = get().user?.abilities ?? []
    return abilities.includes('*') || abilities.includes(ability)
  },
  fetchMe: async () => {
    try {
      const { data } = await api.get<AdminUser>('/auth/me')
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
