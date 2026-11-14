import axios, { AxiosError } from 'axios'

/**
 * Admin API client. Same domain as Laravel, so the session cookie and the
 * XSRF-TOKEN cookie (sent back as X-XSRF-TOKEN by axios) just work.
 */
export const api = axios.create({
  baseURL: '/api/v1/admin',
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
})

export type Paginated<T> = {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export type ValidationErrors = Record<string, string[]>

/** First readable message from a Laravel error response. */
export function errorMessage(error: unknown): string {
  if (error instanceof AxiosError) {
    const data = error.response?.data as { message?: string; errors?: ValidationErrors } | undefined
    const first = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined
    return first ?? data?.message ?? error.message
  }
  return error instanceof Error ? error.message : 'Something went wrong'
}

export function fieldErrors(error: unknown): ValidationErrors {
  if (error instanceof AxiosError) {
    return ((error.response?.data as { errors?: ValidationErrors })?.errors ?? {}) as ValidationErrors
  }
  return {}
}

/** Laravel answers with a fresh XSRF cookie on any web-group request. */
export async function ensureCsrf(): Promise<void> {
  if (!document.cookie.includes('XSRF-TOKEN=')) {
    await api.get('/auth/me').catch(() => undefined)
  }
}
