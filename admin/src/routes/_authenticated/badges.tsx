import { createFileRoute } from '@tanstack/react-router'
import { BadgesPage } from '@/features/badges/badges-page'

export const Route = createFileRoute('/_authenticated/badges')({ component: BadgesPage })
