import { createFileRoute } from '@tanstack/react-router'
import { GuideIndex } from '@/features/guide'

export const Route = createFileRoute('/_authenticated/guide/')({ component: GuideIndex })
