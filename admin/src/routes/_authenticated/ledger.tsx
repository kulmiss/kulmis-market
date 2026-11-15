import { createFileRoute } from '@tanstack/react-router'
import { LedgerPage } from '@/features/money/money-pages'

export const Route = createFileRoute('/_authenticated/ledger')({ component: LedgerPage })
