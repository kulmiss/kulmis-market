import { create } from 'zustand'
import { api } from '@/lib/api'

export type FeatureRow = { key: string; label: string; group: string; enabled: boolean; effective: boolean; parent: string | null }
type FeatureState = Pick<FeatureRow, 'key' | 'effective'>

type FeaturesState = {
  rows: FeatureState[]
  loaded: boolean
  load: () => Promise<void>
  setRows: (rows: FeatureState[]) => void
  on: (key?: string) => boolean
}

/** Effective on/off for hiding admin screens of disabled features. */
export const useFeatures = create<FeaturesState>()((set, get) => ({
  rows: [],
  loaded: false,
  load: async () => {
    try {
      const { data } = await api.get<{ data: FeatureState[] }>('/features/state') // readable by every staff role
      set({ rows: data.data, loaded: true })
    } catch {
      set({ loaded: true })
    }
  },
  setRows: (rows) => set({ rows }),
  on: (key) => {
    if (!key) return true
    const row = get().rows.find((r) => r.key === key)
    return row ? row.effective : true
  },
}))
