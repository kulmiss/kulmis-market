import { createFileRoute } from '@tanstack/react-router'
import { ActivityPage } from '@/features/platform/activity-page'

export const Route = createFileRoute('/_authenticated/activity')({ component: ActivityPage })
