import { api } from '@/lib/api'
import type { Media } from './types'

export const mediaQuery = { queryKey: ['home-media'], queryFn: async () => (await api.get<{ data: Media[] }>('/home-page/media')).data.data }
