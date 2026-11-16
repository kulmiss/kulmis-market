import { createFileRoute } from '@tanstack/react-router'
import { BonusPage } from '@/features/badges/bonus-page'

export const Route = createFileRoute('/_authenticated/badge-bonus')({ component: BonusPage })
