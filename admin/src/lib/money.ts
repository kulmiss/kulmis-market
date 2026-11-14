/** Money is integer santim in the API (500000 = 5,000 ETB). */
export function formatETB(santim: number | null | undefined, withCurrency = true): string {
  if (santim === null || santim === undefined) return '—'
  const birr = santim / 100
  const text = birr.toLocaleString('en-US', {
    minimumFractionDigits: santim % 100 === 0 ? 0 : 2,
    maximumFractionDigits: 2,
  })
  return withCurrency ? `${text} ETB` : text
}

export function toSantim(birr: string | number): number {
  const n = typeof birr === 'number' ? birr : Number(String(birr).replace(/,/g, ''))
  return Number.isFinite(n) ? Math.round(n * 100) : 0
}

export function toBirr(santim: number | null | undefined): string {
  if (santim === null || santim === undefined) return ''
  return (santim / 100).toString()
}

/** Basis points → "15%" */
export function formatBps(bps: number): string {
  return `${(bps / 100).toLocaleString('en-US', { maximumFractionDigits: 2 })}%`
}

export function formatDate(iso: string | null | undefined, withTime = false): string {
  if (!iso) return '—'
  const d = new Date(iso)
  return d.toLocaleString('en-GB', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    ...(withTime ? { hour: '2-digit', minute: '2-digit' } : {}),
    timeZone: 'Africa/Addis_Ababa',
  })
}
