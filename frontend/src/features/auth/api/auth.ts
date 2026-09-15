import { clearOfflineState, readOfflineIdentity, saveOfflineIdentity } from '@/features/offline/storage'
import { isConnected, markConnected, markDisconnected } from '@/shared/lib/connectivity'

export interface AuthUser {
  id: string
  email: string
  organization: {
    id: string
    name: string
    timezone: string
    defaultChannel: 'MAX' | 'TELEGRAM' | 'WHATSAPP'
  }
}

interface CsrfResponse {
  token: string
  logoutToken: string
  mutationToken: string
}

async function readJson<T>(response: Response): Promise<T> {
  const payload = (await response.json()) as T & { message?: string }

  if (!response.ok) {
    throw new Error(payload.message ?? 'Request failed.')
  }

  return payload
}

async function request(url: string, options: RequestInit = {}): Promise<Response> {
  try {
    const response = await fetch(url, options)
    markConnected()
    return response
  } catch (error) {
    if (!options.signal?.aborted) markDisconnected()
    throw error
  }
}

export async function getCurrentUser(signal?: AbortSignal): Promise<AuthUser | null> {
  if (!isConnected()) return readOfflineIdentity().catch(() => null)

  let response: Response
  try {
    response = await request('/api/me', { credentials: 'same-origin', signal })
  } catch (error) {
    if (signal?.aborted) throw error
    return readOfflineIdentity().catch(() => null)
  }

  if (response.status === 401) {
    await clearOfflineState().catch(() => undefined)
    return null
  }

  const identity = await readJson<AuthUser>(response)
  if (signal?.aborted) throw new DOMException('Authentication request was cancelled.', 'AbortError')
  await saveOfflineIdentity(identity).catch(() => undefined)
  return identity
}

export async function getCsrfTokens(): Promise<CsrfResponse> {
  const response = await request('/api/auth/csrf', { credentials: 'same-origin' })

  return readJson<CsrfResponse>(response)
}

export async function login(email: string, password: string): Promise<void> {
  if (!isConnected()) throw new Error('Вход доступен после подключения к интернету.')
  const { token } = await getCsrfTokens()
  const body = new URLSearchParams({
    email,
    password,
    _csrf_token: token,
  })
  const response = await request('/api/login', {
    method: 'POST',
    body,
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  })

  await readJson(response)
}

export async function logout(): Promise<void> {
  if (!isConnected()) throw new Error('Выход доступен после подключения к интернету.')
  const { logoutToken } = await getCsrfTokens()
  const response = await request('/api/logout', {
    method: 'POST',
    body: new URLSearchParams({ _csrf_token: logoutToken }),
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  })

  if (!response.ok) {
    await readJson(response)
  }
  await clearOfflineState().catch(() => undefined)
}
