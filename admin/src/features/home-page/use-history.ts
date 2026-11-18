import { useCallback, useRef, useState } from 'react'

/**
 * Undo / redo for the editor: every change is a step (typing in one field is
 * grouped into one step for a moment, so Undo does not go letter by letter).
 */
export function useHistory<T>(initial: T) {
  const [state, setState] = useState({ past: [] as T[], present: initial, future: [] as T[] })
  const lastKey = useRef<{ key: string; at: number } | null>(null)

  const set = useCallback((next: T | ((prev: T) => T), groupKey?: string) => {
    setState((s) => {
      const value = typeof next === 'function' ? (next as (p: T) => T)(s.present) : next
      if (Object.is(value, s.present)) return s
      const now = Date.now()
      const grouped = groupKey && lastKey.current?.key === groupKey && now - lastKey.current.at < 1200
      lastKey.current = groupKey ? { key: groupKey, at: now } : null
      return { past: grouped ? s.past : [...s.past, s.present].slice(-100), present: value, future: [] }
    })
  }, [])

  const undo = useCallback(() => setState((s) => (s.past.length === 0 ? s
    : { past: s.past.slice(0, -1), present: s.past[s.past.length - 1], future: [s.present, ...s.future] })), [])
  const redo = useCallback(() => setState((s) => (s.future.length === 0 ? s
    : { past: [...s.past, s.present], present: s.future[0], future: s.future.slice(1) })), [])
  /** Replace without a step (loading from the server). */
  const reset = useCallback((value: T) => setState({ past: [], present: value, future: [] }), [])

  return { value: state.present, set, undo, redo, reset, canUndo: state.past.length > 0, canRedo: state.future.length > 0 }
}
