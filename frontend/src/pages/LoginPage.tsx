import { type FormEvent, useState } from 'react'
import { Navigate, useLocation, useNavigate } from 'react-router-dom'

import { useAuth } from '@/features/auth/AuthProvider'
import { Button } from '@/shared/ui/Button'

export function LoginPage() {
  const navigate = useNavigate()
  const location = useLocation()
  const { isLoading, login, user } = useAuth()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const destination = (location.state as { from?: string } | null)?.from ?? '/'

  if (isLoading) {
    return <main className="grid min-h-screen place-items-center text-muted">Проверяем вход...</main>
  }

  if (user) {
    return <Navigate to={destination} replace />
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError(null)
    setIsSubmitting(true)

    try {
      await login(email, password)
      navigate(destination, { replace: true })
    } catch {
      setError('Не удалось войти. Проверьте email и пароль.')
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <main className="app-backdrop grid min-h-screen place-items-center px-5 py-8">
      <section className="w-full max-w-[420px] rounded-lg border border-border bg-white/80 p-6 shadow-surface sm:p-8">
        <p className="text-2xl font-semibold text-primary">Vovremya</p>
        <h1 className="mt-8 text-2xl font-semibold">Вход в кабинет</h1>
        <p className="mt-2 text-sm text-muted">Используйте email администратора.</p>
        <form className="mt-8 space-y-5" onSubmit={submit}>
          <label className="block text-sm font-medium">
            Email
            <input
              autoComplete="username"
              className="mt-2 h-12 w-full rounded-lg border border-border bg-white px-3 outline-none focus:border-primary focus:ring-2 focus:ring-primary/20"
              onChange={(event) => setEmail(event.target.value)}
              required
              type="email"
              value={email}
            />
          </label>
          <label className="block text-sm font-medium">
            Пароль
            <input
              autoComplete="current-password"
              className="mt-2 h-12 w-full rounded-lg border border-border bg-white px-3 outline-none focus:border-primary focus:ring-2 focus:ring-primary/20"
              onChange={(event) => setPassword(event.target.value)}
              required
              type="password"
              value={password}
            />
          </label>
          {error ? <p className="text-sm text-danger" role="alert">{error}</p> : null}
          <Button className="w-full" disabled={isSubmitting} type="submit">
            {isSubmitting ? 'Входим...' : 'Войти'}
          </Button>
        </form>
      </section>
    </main>
  )
}
