import { createFileRoute } from '@tanstack/react-router'
import { SignalsPage } from '@/features/signals/signals-pages'

export const Route = createFileRoute('/_authenticated/signals/')({ component: SignalsPage })
