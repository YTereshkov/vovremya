import { getCsrfTokens } from '@/features/auth/api/auth'

export async function apiRequest<T>(url: string, method = 'GET', data?: unknown, signal?: AbortSignal): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (method !== 'GET') {
    headers['X-CSRF-Token'] = (await getCsrfTokens()).mutationToken
    headers['Content-Type'] = 'application/json'
  }

  const response = await fetch(url, {
    method,
    headers,
    credentials: 'same-origin',
    signal,
    body: data === undefined ? undefined : JSON.stringify(data),
  })
  if (response.status === 204) return undefined as T

  const payload = await response.json() as T & { message?: string }
  if (!response.ok) throw new Error(payload.message ?? 'Не удалось сохранить изменения.')

  return payload
}
