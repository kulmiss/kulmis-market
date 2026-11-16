/** Easy words for money history (the ledger). Codes stay in the data and in CSV files. */
export const ENTRY_TYPES: Record<string, string> = {
  'payment.received': 'Payment received', 'payment.fee': 'Payment fee', 'payment.refunded': 'Money given back',
  'commission.earned': 'Team earnings (on the way)', 'commission.released': 'Team earnings ready to use', 'commission.reversed': 'Team earnings taken back (refund)',
  'badge.bonus': 'Badge bonus', 'badge.bonus.released': 'Badge bonus ready to use', 'badge.bonus.reduced': 'Badge bonus taken back (refund)',
  'loyalty.reward': 'Loyalty reward', 'wallet.spend': 'Paid with wallet', 'wallet.refunded': 'Money back to wallet',
  'payout.requested': 'Cash-out asked', 'payout.paid': 'Cash-out sent', 'payout.rejected': 'Cash-out not approved (back in wallet)',
  'manual.adjustment': 'Fix by hand',
}

const ACCOUNTS: Record<string, string> = {
  'asset:cash': 'Shop money (bank & Telebirr)', 'expense:commissions': 'Team earnings paid', 'expense:loyalty': 'Loyalty rewards paid',
  'expense:badge_bonuses': 'Badge bonuses paid', 'expense:payment_fees': 'Payment fees', 'income:memberships': 'Membership sales',
  'income:sales': 'Shop sales', 'income:signals': 'Signal sales', 'income:wallet_sales': 'Sales paid with wallet', 'equity:adjustments': 'Fixes by hand',
}
const BUCKETS: Record<string, string> = { pending: 'on the way', available: 'ready to use', payout: 'cash-out asked' }

export function entryType(code: string): string {
  return ENTRY_TYPES[code] ?? code
}

export function accountName(code: string): string {
  const m = /^user:(\d+):(\w+)$/.exec(code)
  if (m) return `Member #${m[1]} wallet, ${BUCKETS[m[2]] ?? m[2]}`
  return ACCOUNTS[code] ?? code
}

/** Does this line make the account's total bigger? (Shop money and costs grow with +; wallets, sales and fixes grow with −.) */
export function goesUp(code: string, amount: number): boolean {
  const debitNormal = code.startsWith('asset:') || code.startsWith('expense:')
  return debitNormal ? amount > 0 : amount < 0
}
