import { api } from '@/lib/api'
import type { SetupState } from './index'

export const setupQuery = { queryKey: ['setup'], queryFn: async () => (await api.get<{ data: SetupState }>('/setup')).data.data }
