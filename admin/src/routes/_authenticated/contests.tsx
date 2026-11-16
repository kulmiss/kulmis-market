import { createFileRoute } from '@tanstack/react-router'
import { ContestsPage } from '@/features/badges/contests-page'

export const Route = createFileRoute('/_authenticated/contests')({ component: ContestsPage })
