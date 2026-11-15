/** One CSV cell: quoted, and safe to open in Excel (no formulas from data). */
export function csvCell(value: unknown): string {
  let s = value === null || value === undefined ? '' : String(value)
  // A cell starting with = + - @ (or tab/CR) would run as a formula in a spreadsheet.
  if (/^[=+\-@\t\r]/.test(s) && !/^-?\d+(\.\d+)?$/.test(s)) s = `'${s}`
  return `"${s.replaceAll('"', '""')}"`
}

export function toCsv(head: string[], rows: unknown[][]): string {
  return [head, ...rows].map((r) => r.map(csvCell).join(',')).join('\r\n')
}

/** Download text as a file (UTF-8 with BOM so Excel shows Amharic names right). */
export function downloadCsv(name: string, csv: string) {
  const blob = new Blob(['﻿', csv], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `${name}-${new Date().toISOString().slice(0, 10)}.csv`
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
