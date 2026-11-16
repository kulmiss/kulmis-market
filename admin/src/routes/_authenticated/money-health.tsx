import { createFileRoute } from '@tanstack/react-router'
import { MoneyHealthPage } from '@/features/money/money-health-page'

export const Route = createFileRoute('/_authenticated/money-health')({ component: MoneyHealthPage })
