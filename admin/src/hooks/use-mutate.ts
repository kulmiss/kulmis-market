import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { api, errorMessage } from '@/lib/api'

type Method = 'post' | 'put' | 'patch' | 'delete'

/** POST/PUT to the admin API, toast the result, refresh the given queries. */
export function useMutate<TBody = unknown, TRes = unknown>(method: Method, invalidate: string[] = [], success?: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ path, body }: { path: string; body?: TBody }) =>
      (await api.request<TRes>({ method, url: path, data: body })).data,
    onSuccess: () => {
      if (success) toast.success(success)
      invalidate.forEach((key) => qc.invalidateQueries({ queryKey: [key] }))
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
}
