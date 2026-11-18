import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { Monitor, Moon, Smartphone, Sun } from 'lucide-react'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/button'
import type { Section, Seo } from './types'

export type Device = 'phone' | 'desktop'
const WIDTH: Record<Device, number> = { phone: 390, desktop: 1280 }

/**
 * Live preview: the server makes the page from the same Blade partials as
 * the real home page. It runs in a sandboxed frame with no scripts; links do nothing.
 */
export function Preview({ sections, seo, selected, html: fixedHtml }: { sections?: Section[]; seo?: Seo; selected?: string | null; html?: string }) {
  const [device, setDevice] = useState<Device>(() => (window.innerWidth < 1024 ? 'phone' : 'desktop'))
  const [dark, setDark] = useState(false)
  const [html, setHtml] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const box = useRef<HTMLDivElement>(null)
  const frame = useRef<HTMLIFrameElement>(null)
  const [scale, setScale] = useState(1)
  const [height, setHeight] = useState(800)

  // Ask for a new preview a moment after the last change.
  useEffect(() => {
    if (fixedHtml !== undefined || !sections) return
    const ctrl = new AbortController()
    const t = setTimeout(async () => {
      setBusy(true)
      try {
        const r = await api.post<{ html: string }>('/home-page/preview', { sections, seo, dark }, { signal: ctrl.signal })
        setHtml(r.data.html)
        setError(null)
      } catch (e) {
        if (!ctrl.signal.aborted) setError('The preview could not be made. Your draft is still saved.')
        void e
      } finally {
        if (!ctrl.signal.aborted) setBusy(false)
      }
    }, 450)
    return () => { clearTimeout(t); ctrl.abort() }
  }, [sections, seo, dark, fixedHtml])

  const shown = fixedHtml !== undefined ? withDark(fixedHtml, dark) : html

  // Fit the page width into the box.
  useLayoutEffect(() => {
    const el = box.current
    if (!el) return
    const ro = new ResizeObserver(() => setScale(Math.min(1, el.clientWidth / WIDTH[device])))
    ro.observe(el)
    return () => ro.disconnect()
  }, [device])

  // Scroll to and outline the section being edited.
  useEffect(() => {
    const doc = frame.current?.contentDocument
    if (!doc || !selected) return
    doc.querySelectorAll('[data-home-section]').forEach((n) => (n as HTMLElement).style.removeProperty('outline'))
    const el = doc.querySelector<HTMLElement>(`[data-home-section="${CSS.escape(selected)}"]`)
    if (el) {
      el.style.outline = '3px solid #1f8a4c'
      el.style.outlineOffset = '-3px'
      // Scroll only the preview column (never the whole admin page).
      const sc = box.current?.closest<HTMLElement>('[data-preview-scroll]')
      if (sc && box.current) {
        const top = sc.scrollTop + box.current.getBoundingClientRect().top - sc.getBoundingClientRect().top + el.getBoundingClientRect().top * scale - 16
        sc.scrollTo({ top: Math.max(0, top), behavior: 'smooth' })
      }
    }
  }, [selected, shown, scale])

  return (
    <div className='flex min-h-0 flex-col gap-2'>
      <div className='flex flex-wrap items-center gap-1'>
        <Button type='button' size='sm' variant={device === 'phone' ? 'default' : 'outline'} onClick={() => setDevice('phone')} aria-pressed={device === 'phone'}><Smartphone /> Phone</Button>
        <Button type='button' size='sm' variant={device === 'desktop' ? 'default' : 'outline'} onClick={() => setDevice('desktop')} aria-pressed={device === 'desktop'}><Monitor /> Desktop</Button>
        <Button type='button' size='sm' variant='outline' onClick={() => setDark(!dark)} aria-pressed={dark}>{dark ? <Moon /> : <Sun />} {dark ? 'Dark' : 'Light'}</Button>
        <span className='ms-auto text-xs text-muted-foreground' aria-live='polite'>{busy ? 'Updating…' : error ?? 'English'}</span>
      </div>
      <div ref={box} className='relative overflow-hidden rounded-md border bg-muted/40' style={{ height: Math.round(height * scale) + 2 }}>
        <iframe
          ref={frame}
          title='Preview of the home page'
          sandbox='allow-same-origin'
          srcDoc={shown}
          className='absolute top-0 left-1/2 origin-top border-0 bg-white'
          style={{ width: WIDTH[device], height, transform: `translateX(-50%) scale(${scale})` }}
          onLoad={() => {
            const doc = frame.current?.contentDocument
            if (doc) setHeight(Math.min(20000, Math.max(600, doc.documentElement.scrollHeight)))
          }}
        />
      </div>
    </div>
  )
}

function withDark(html: string, dark: boolean): string {
  return dark ? html.replace('<html lang="en" class="h-full', '<html lang="en" class="h-full dark') : html
}
