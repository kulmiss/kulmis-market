import { useCallback, useState } from 'react'

/** Per-table (or per-screen) settings kept in this browser. Never throws. */
export function useSavedView<T extends object>(key: string, initial: T): [T, (patch: Partial<T>) => void, () => void] {
  const storageKey = `km.view.${key}`
  const [view, setView] = useState<T>(() => {
    try {
      const raw = localStorage.getItem(storageKey)
      return raw ? { ...initial, ...(JSON.parse(raw) as Partial<T>) } : initial
    } catch {
      return initial
    }
  })
  const update = useCallback((patch: Partial<T>) => {
    setView((v) => {
      const next = { ...v, ...patch }
      try { localStorage.setItem(storageKey, JSON.stringify(next)) } catch { /* private mode */ }
      return next
    })
  }, [storageKey])
  const reset = useCallback(() => {
    try { localStorage.removeItem(storageKey) } catch { /* ignore */ }
    setView(initial)
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [storageKey])
  return [view, update, reset]
}
