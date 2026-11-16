import { create } from 'zustand'
import { api } from '@/lib/api'

export type FeatureRow = { key: string; label: string; group: string; description: string; enabled: boolean; effective: boolean; parent: string | null }
type FeatureState = Pick<FeatureRow, 'key' | 'effective'> & { label?: string }

type FeaturesState = {
  rows: FeatureState[]
  /** Words of each feature (config/feature_words.php), for "This text mentions …" warnings. */
  words: Record<string, string[]>
  loaded: boolean
  load: () => Promise<void>
  setRows: (rows: FeatureState[]) => void
  on: (key?: string) => boolean
  label: (key: string) => string
}

/** Effective on/off for the admin menu (switched-off screens move to their own group). */
export const useFeatures = create<FeaturesState>()((set, get) => ({
  rows: [],
  words: {},
  loaded: false,
  load: async () => {
    try {
      const { data } = await api.get<{ data: FeatureState[]; words?: Record<string, string[]> }>('/features/state') // readable by every staff role
      set({ rows: data.data, words: data.words ?? {}, loaded: true })
    } catch {
      set({ loaded: true })
    }
  },
  setRows: (rows) => set({ rows: rows.map((r) => ({ key: r.key, effective: r.effective, label: r.label })) }),
  on: (key) => {
    if (!key) return true
    const row = get().rows.find((r) => r.key === key)
    return row ? row.effective : true
  },
  label: (key) => get().rows.find((r) => r.key === key)?.label ?? key,
}))

const escape = (w: string) => w.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&')

/** Text without its {if feature}…{/if} parts (those are already safe). Inner parts first. */
function outsideBlocks(text: string): string {
  let t = text
  for (let i = 0; i < 10 && /\{if !?[a-z0-9_.]+\}/.test(t); i++) {
    t = t.replace(/\{if !?[a-z0-9_.]+\}((?:(?!\{if ).)*?)\{\/if\}/gs, '')
  }
  return t
}

/**
 * Labels of switched-off features this text still talks about, the same rule
 * as the server (FeatureText::mentionsOff): whole words, outside {if} parts.
 */
export function offMentions(text: string, words: Record<string, string[]>, on: (key: string) => boolean, label: (key: string) => string): { key: string; label: string }[] {
  const plain = outsideBlocks(text).replace(/<[^>]*>/g, ' ').toLowerCase()
  const found = new Map<string, { key: string; label: string }>()
  for (const [feature, list] of Object.entries(words)) {
    if (on(feature)) continue
    const name = label(feature)
    if (!found.has(name) && list.some((w) => new RegExp(`(?<![\\p{L}\\p{N}])${escape(w)}(?![\\p{L}\\p{N}])`, 'u').test(plain))) found.set(name, { key: feature, label: name })
  }
  return [...found.values()]
}
