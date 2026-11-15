import { createFileRoute } from '@tanstack/react-router'
import { FraudPage } from '@/features/money/money-pages'

export const Route = createFileRoute('/_authenticated/fraud')({ component: FraudPage })
