// Re-take the pictures used in the guides, from the demo data on your own computer.
//
//   php artisan db:seed --class=DemoSeeder      (once, on a local copy, never on the live shop)
//   npm install -g playwright && npx playwright install chromium   (once)
//   node scripts/screenshots.mjs [http://localhost:8090] [only-this-name]
//
// Writes:  public/help-screens/*.jpg   (member Help, phone size)
//          docs/screenshots/*.jpg      (Super Admin guide, desktop and phone)
// Staff sign in with two-step codes: the first time the script sets up two-step
// sign-in for the demo Super Admin and keeps the secret in .screenshots/admin.secret
// (not committed). If it was set up before, put that secret in the file yourself.
import { createHmac } from 'node:crypto'
import { createRequire } from 'node:module'
import { execSync } from 'node:child_process'
import { mkdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs'

// Playwright from this project or from the global install.
const require = createRequire(import.meta.url)
let playwright
try { playwright = require('playwright') } catch { playwright = require(`${execSync('npm root -g').toString().trim()}/playwright`) }
const { chromium } = playwright

const BASE = process.argv[2] || 'http://localhost:8090'
const ONLY = process.argv[3] || ''
const PASSWORD = 'kulmis demo 2026'
const STATE = '.screenshots'
mkdirSync(STATE, { recursive: true })
mkdirSync('public/help-screens', { recursive: true })
mkdirSync('docs/screenshots', { recursive: true })

const PHONE = { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true }
const DESKTOP = { viewport: { width: 1366, height: 860 } }

// [file, who, path, size, optional steps on the page]
const SHOTS = [
  // Member Help (phone)
  ['help/member-home-phone', 'member', '/account', PHONE],
  ['help/member-wallet-phone', 'member', '/account/wallet', PHONE],
  ['help/member-share-kit-phone', 'member', '/account/share', PHONE],
  ['help/member-team-phone', 'member', '/account/team', PHONE],
  ['help/member-earnings-phone', 'member', '/account/earnings', PHONE],
  ['docs/help-centre-phone', 'member', '/help', PHONE],
  ['docs/help-article-phone', 'member', '/help/how-earning-works', PHONE],
  // Super Admin guide (desktop, plus a few on a phone)
  ['docs/admin-dashboard', 'admin', '/admin/', DESKTOP],
  ['docs/admin-dashboard-phone', 'admin', '/admin/', PHONE],
  ['docs/admin-money-health', 'admin', '/admin/money-health', DESKTOP],
  ['docs/admin-products', 'admin', '/admin/products', DESKTOP],
  ['docs/admin-orders', 'admin', '/admin/orders', DESKTOP],
  ['docs/admin-payments', 'admin', '/admin/payments', DESKTOP, async (p) => { await p.locator('main tbody tr').first().click(); await p.waitForTimeout(1200) }],
  ['docs/admin-payments-phone', 'admin', '/admin/payments', PHONE],
  ['docs/admin-members', 'admin', '/admin/members', DESKTOP],
  ['docs/admin-team-tree', 'admin', '/admin/referrals', DESKTOP],
  ['docs/admin-cash-outs', 'admin', '/admin/cash-outs', DESKTOP],
  ['docs/admin-fraud', 'admin', '/admin/fraud', DESKTOP],
  ['docs/admin-badge-bonus', 'admin', '/admin/badge-bonus', DESKTOP],
  ['docs/admin-contests', 'admin', '/admin/contests', DESKTOP],
  ['docs/admin-signals', 'admin', '/admin/signals', DESKTOP],
  ['docs/admin-telegram', 'admin', '/admin/signals/telegram', DESKTOP],
  ['docs/admin-features', 'admin', '/admin/features', DESKTOP],
  ['docs/admin-settings', 'admin', '/admin/config', DESKTOP],
  ['docs/admin-content', 'admin', '/admin/content', DESKTOP],
  ['docs/admin-help-centre', 'admin', '/admin/help', DESKTOP, async (p) => { await p.getByRole('tab', { name: 'Articles' }).click(); await p.waitForTimeout(1000) }],
  ['docs/admin-activity', 'admin', '/admin/activity', DESKTOP],
  ['docs/admin-reports', 'admin', '/admin/reports', DESKTOP],
  ['docs/admin-status', 'admin', '/admin/status', DESKTOP],
  ['docs/admin-demo', 'admin', '/admin/demo', DESKTOP],
  ['docs/admin-guide', 'admin', '/admin/guide', DESKTOP],
  ['docs/admin-guide-page', 'admin', '/admin/guide/daily-wallets-cash-outs', DESKTOP],
  ['docs/admin-guide-phone', 'admin', '/admin/guide', PHONE],
  // Setup wizard (opened again for the pictures, then finished) and the to-do list
  ...[1, 2, 3, 4, 5].flatMap((n) => [[`docs/wizard-${n}-desktop`, 'admin', '/admin/', DESKTOP, wizardPage(n)], [`docs/wizard-${n}-phone`, 'admin', '/admin/', PHONE, wizardPage(n)]]),
  ['docs/dashboard-checklist-desktop', 'admin', '/admin/', DESKTOP, finishWizard],
  // Member welcome tour
  ...[1, 2, 3].flatMap((n) => [[`docs/tour-${n}-phone`, 'member', '/account?tour=1', PHONE, tourSlide(n)], [`docs/tour-${n}-desktop`, 'member', '/account?tour=1', DESKTOP, tourSlide(n)]]),
  ['docs/admin-guide-question-button', 'admin', '/admin/cash-outs', DESKTOP, async (p) => { await p.locator('[data-page-help]').click(); await p.waitForTimeout(1500) }],
]

async function adminApi(p, method, path, body) {
  return p.evaluate(async ([method, path, body]) => {
    const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '')
    const r = await fetch('/api/v1/admin' + path, { method, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf }, body: body ? JSON.stringify(body) : undefined })
    return r.status
  }, [method, path, body])
}
function wizardPage(n) {
  return async (p) => {
    await adminApi(p, 'PUT', '/setup', { restart: true })
    await adminApi(p, 'PUT', '/setup', { step: n })
    await p.goto(`${BASE}/admin/setup`)
    await p.waitForSelector(`[data-page='${n}']`)
    await p.waitForTimeout(1200)
  }
}
async function finishWizard(p) {
  await adminApi(p, 'PUT', '/setup', { finished: true })
  await p.goto(`${BASE}/admin/`)
  await p.waitForTimeout(2500)
}
function tourSlide(n) {
  return async (p) => {
    for (let i = 1; i < n; i++) { await p.click('[data-tour-next]'); await p.waitForTimeout(300) }
  }
}

function totp(secret) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'
  let bits = ''
  for (const c of secret.replace(/=+$/, '')) bits += alphabet.indexOf(c).toString(2).padStart(5, '0')
  const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)))
  const counter = Buffer.alloc(8)
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)))
  const h = createHmac('sha1', key).update(counter).digest()
  const o = h[h.length - 1] & 15
  return String((h.readUInt32BE(o) & 0x7fffffff) % 1000000).padStart(6, '0')
}

async function signInMember(ctx) {
  const p = await ctx.newPage()
  await p.goto(`${BASE}/login`)
  await p.fill('[name=login]', 'demo-member@kulmis.test')
  await p.fill('[name=password]', PASSWORD)
  await p.click('form button.btn-primary')
  await p.waitForLoadState('networkidle')
  await p.request.post(`${BASE}/account/tour/seen`, { headers: { Accept: 'application/json', 'X-CSRF-TOKEN': await p.locator('meta[name=csrf-token]').getAttribute('content') } }).catch(() => {})
  await p.close()
}

async function signInAdmin(ctx) {
  const p = await ctx.newPage()
  await p.goto(`${BASE}/admin/`)
  await p.waitForTimeout(1500)
  if (p.url().includes('sign-in')) {
    await p.fill('#login', 'demo-super_admin@kulmis.test')
    await p.fill('#password', PASSWORD)
    await p.keyboard.press('Enter')
    await p.waitForTimeout(2500)
    const secretFile = `${STATE}/admin.secret`
    if (p.url().includes('two-factor-setup')) {
      const secret = (await p.locator('body').innerText()).match(/\b([A-Z2-7]{32})\b/)[1]
      writeFileSync(secretFile, secret)
      await p.locator('input').last().fill(totp(secret))
      await p.keyboard.press('Enter')
      await p.waitForTimeout(2500)
    } else if (!existsSync(secretFile)) {
      console.error(`The demo Super Admin already has two-step sign-in. Put its secret (the 32 letters shown at set-up) in ${secretFile} and run again.`)
      process.exit(1)
    } else {
      if (Date.now() % 30000 > 25000) await p.waitForTimeout(6000)
      await p.locator('input[inputmode=numeric], input[autocomplete=one-time-code]').first().fill(totp(readFileSync(secretFile, 'utf8').trim()))
      await p.keyboard.press('Enter')
      await p.waitForTimeout(2500)
    }
  }
  await p.close()
}

const browser = await chromium.launch()
const contexts = {}
async function context(who, size) {
  const key = `${who}-${size === PHONE ? 'phone' : 'desktop'}`
  if (!contexts[key]) {
    contexts[key] = await browser.newContext({ ...size, colorScheme: 'light' })
    await (who === 'admin' ? signInAdmin : signInMember)(contexts[key])
  }
  return contexts[key]
}

let taken = 0
for (const [name, who, path, size, steps] of SHOTS) {
  if (ONLY && !name.includes(ONLY)) continue
  const ctx = await context(who, size)
  const p = await ctx.newPage()
  await p.goto(BASE + path)
  await p.waitForLoadState('networkidle')
  await p.waitForTimeout(1200)
  const yay = p.getByRole('button', { name: /Yay/ })
  if (await yay.count() && await yay.first().isVisible()) await yay.first().click()
  if (steps) await steps(p)
  const file = name.startsWith('help/') ? `public/help-screens/${name.slice(5)}.jpg` : `docs/screenshots/${name.slice(5)}.jpg`
  await p.screenshot({ path: file, type: 'jpeg', quality: 72 })
  await p.close()
  taken++
  console.log('✓', file)
}
await browser.close()
console.log(`${taken} pictures taken.`)
