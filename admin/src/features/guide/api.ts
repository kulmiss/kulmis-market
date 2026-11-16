import { api } from '@/lib/api'

export type GuideItem = { slug: string; title: string; part: string; pages: string[]; text: string }

export const guideListQuery = {
  queryKey: ['guide'],
  queryFn: async () => (await api.get<{ data: GuideItem[] }>('/guide')).data.data,
  staleTime: 60 * 60 * 1000,
}

/** The guide page for the screen you are on (longest matching path). */
export function guideFor(list: GuideItem[] | undefined, path: string): string | null {
  if (!list) return null
  const p = '/' + path.replace(/^\/admin/, '').replace(/^\/+|\/+$/g, '')
  let best: string | null = null
  let len = -1
  for (const g of list) {
    for (const s of g.pages) {
      const hit = s === '/' ? p === '/' : p === s || p.startsWith(s.replace(/\/$/, '') + '/')
      if (hit && s.length > len) {
        best = g.slug
        len = s.length
      }
    }
  }
  return best
}
