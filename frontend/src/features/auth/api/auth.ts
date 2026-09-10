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

export async function getCurrentUser(): Promise<AuthUser | null> {
  const response = await fetch('/api/me', { credentials: 'same-origin' })

  if (response.status === 401) {
    return null
  }

  return readJson<AuthUser>(response)
}

export async function getCsrfTokens(): Promise<CsrfResponse> {
  const response = await fetch('/api/auth/csrf', { credentials: 'same-origin' })

  return readJson<CsrfResponse>(response)
}

export async function login(email: string, password: string): Promise<void> {
  const { token } = await getCsrfTokens()
  const body = new URLSearchParams({
    email,
    password,
    _csrf_token: token,
  })
  const response = await fetch('/api/login', {
    method: 'POST',
    body,
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  })

  await readJson(response)
}

export async function logout(): Promise<void> {
  const { logoutToken } = await getCsrfTokens()
  const response = await fetch('/api/logout', {
    method: 'POST',
    body: new URLSearchParams({ _csrf_token: logoutToken }),
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  })

  if (!response.ok) {
    await readJson(response)
  }
}
