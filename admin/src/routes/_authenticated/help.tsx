import { createFileRoute } from '@tanstack/react-router'
import { HelpPage } from '@/features/content/help-page'

export const Route = createFileRoute('/_authenticated/help')({ component: HelpPage })
