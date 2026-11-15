/** Shop currency format (Super Admin → Settings → General); sent with /auth/me. */
export type CurrencyFormat = {
  code: string
  symbol: string
  position: 'after_space' | 'after' | 'before_space' | 'before'
  decimals: 'auto' | '0' | '2'
  thousands_char: string
  decimal_point: string
}

let currency: CurrencyFormat = { code: 'ETB', symbol: 'ETB', position: 'after_space', decimals: 'auto', thousands_char: ',', decimal_point: '.' }

export function setCurrency(c: Partial<CurrencyFormat> | null | undefined) {
  if (c) currency = { ...currency, ...c }
}

export function currencySymbol(): string {
  return currency.symbol
}

/** Money is integer minor units in the API (500000 = 5,000 ETB). Same rules as App\Support\Currency. */
export function formatMoney(minor: number | null | undefined, withCurrency = true, c: CurrencyFormat = currency): string {
  if (minor === null || minor === undefined) return '—'
  const abs = Math.abs(Math.round(minor))
  const dec = c.decimals === '0' ? 0 : c.decimals === '2' ? 2 : abs % 100 ? 2 : 0
  const [whole, frac] = (abs / 100).toFixed(dec).split('.')
  const num = whole.replace(/\B(?=(\d{3})+(?!\d))/g, c.thousands_char) + (frac ? c.decimal_point + frac : '')
  const sign = minor < 0 ? '-' : ''
  if (!withCurrency) return sign + num
  const out = { after: num + c.symbol, before_space: `${c.symbol} ${num}`, before: c.symbol + num, after_space: `${num} ${c.symbol}` }[c.position] ?? `${num} ${c.symbol}`
  return sign + out
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
