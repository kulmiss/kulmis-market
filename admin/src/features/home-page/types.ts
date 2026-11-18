/** Home page builder: the shapes the API sends (app/Http/Controllers/Admin/HomePageController.php). */

export type FieldType =
  | 'text' | 'textarea' | 'link' | 'video_url' | 'select' | 'toggle' | 'number' | 'image' | 'video'
  | 'color' | 'checks' | 'list' | 'categories' | 'products' | 'faqs' | 'testimonials'

export type Field = {
  type: FieldType
  label: string
  max?: number
  min?: number
  required?: boolean
  options?: Record<string, string>
  fields?: Record<string, Field>
  when?: [string, string]
}

export type SectionType = {
  key: string
  label: string
  group: string
  icon: string
  description: string
  note: string | null
  off: string | null
  fields: Record<string, Field>
  defaults: Record<string, unknown>
}

export type Data = Record<string, unknown>
export type Section = { id: string; type: string; on: boolean; data: Data }
export type Seo = { title: string; description: string; image: number | null }
export type Problem = { section: string | null; field: string | null; message: string }
export type Problems = { errors: Problem[]; warnings: Problem[] }

export type Summary = { id: number; note: string | null; sections: number; restored_from: number | null; published_at: string | null; by: string | null }

export type HomeState = {
  enabled: boolean
  draft: { sections: Section[]; seo: Seo; revision: number; saved: boolean; updated_at: string | null }
  problems: Problems
  live: Summary | null
  scheduled: (Summary & { publish_at: string | null }) | null
  types: SectionType[]
  templates: Record<string, { label: string; description: string }>
  tokens: { token: string; value: string; label: string }[]
  link_help: string
  video_upload: boolean
}

export type Media = { id: number; kind: 'image' | 'video'; alt: string | null; width: number; height: number; bytes: number; thumb: string | null; url: string }

export type Quote = {
  id: number; name: string; city: string | null; text: string; confirmed_real: boolean; photo_ok: boolean
  is_active: boolean; position: number; is_demo: boolean; photo: string | null; not_shown: string[]
}

export const ACCENTS: Record<string, { label: string; swatch: string }> = {
  soft: { label: 'Soft green', swatch: '#f2f8f4' },
  green: { label: 'Green', swatch: '#135d33' },
  gold: { label: 'Gold', swatch: '#8f6514' },
  dark: { label: 'Dark', swatch: '#11201a' },
  light: { label: 'Light', swatch: '#f2f8f4' },
  none: { label: 'No colour', swatch: 'transparent' },
}

export function newId(): string {
  const a = 'abcdefghijklmnopqrstuvwxyz0123456789'
  let s = ''
  const r = crypto.getRandomValues(new Uint8Array(10))
  r.forEach((b) => { s += a[b % a.length] })
  return s
}

/** WCAG contrast of the best text colour (white or near-black) on a background. Same rule as App\Home\Contrast. */
export function bestContrast(hex: string): number {
  const lum = (h: string) => {
    const c = [0, 2, 4].map((i) => {
      const v = parseInt(h.slice(1 + i, 3 + i), 16) / 255
      return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4
    })
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]
  }
  const ratio = (a: string, b: string) => {
    const [x, y] = [lum(a), lum(b)]
    return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05)
  }
  return Math.max(ratio(hex, '#ffffff'), ratio(hex, '#11201a'))
}

/** A short line for the section list: its headline, title or text. */
export function summaryOf(s: Section): string {
  const d = s.data
  for (const k of ['headline', 'title', 'text']) {
    const v = d[k]
    if (typeof v === 'string' && v.trim() !== '') return v
  }
  if (Array.isArray(d.items)) return `${d.items.length} item${d.items.length === 1 ? '' : 's'}`
  return ''
}
