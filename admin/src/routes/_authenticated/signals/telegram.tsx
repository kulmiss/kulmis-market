import { createFileRoute } from '@tanstack/react-router'
import { TelegramPage } from '@/features/signals/signals-pages'

export const Route = createFileRoute('/_authenticated/signals/telegram')({ component: TelegramPage })
