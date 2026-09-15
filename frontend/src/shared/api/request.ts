import { getCsrfTokens } from '@/features/auth/api/auth'
import { isConnected, markConnected, markDisconnected } from '@/shared/lib/connectivity'

export class ApiError<T = unknown> extends Error {
  constructor(public readonly status: number, public readonly payload: T & { message?: string }) {
    super(payload.message ?? 'Не удалось сохранить изменения.')
    this.name = 'ApiError'
  }
}

export async function apiRequest<T>(url: string, method = 'GET', data?: unknown, signal?: AbortSignal): Promise<T> {
  if (method !== 'GET' && !isConnected()) throw new Error('Без интернета доступен только просмотр расписания.')
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (method !== 'GET') {
    headers['X-CSRF-Token'] = (await getCsrfTokens()).mutationToken
    headers['Content-Type'] = 'application/json'
  }

  let response: Response
  try {
    response = await fetch(url, {
      method,
      headers,
      credentials: 'same-origin',
      signal,
      body: data === undefined ? undefined : JSON.stringify(data),
    })
  } catch (error) {
    if (!signal?.aborted) markDisconnected()
    throw error
  }
  markConnected()
  if (response.status === 204) return undefined as T

  const payload = await response.json() as T & { message?: string }
  if (!response.ok) throw new ApiError(response.status, payload)

  return payload
}
