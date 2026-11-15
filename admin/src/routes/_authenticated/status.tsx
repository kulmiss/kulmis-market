import { createFileRoute } from '@tanstack/react-router'
import { StatusPage } from '@/features/dashboard/status-page'

export const Route = createFileRoute('/_authenticated/status')({ component: StatusPage })
