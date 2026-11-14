import { createFileRoute } from '@tanstack/react-router'
import { CashOutsPage } from '@/features/money/money-pages'

export const Route = createFileRoute('/_authenticated/cash-outs')({ component: CashOutsPage })
