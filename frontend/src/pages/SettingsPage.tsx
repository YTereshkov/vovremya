import { ChevronRight, ClipboardList, LogOut, UserRound } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'

import { useAuth } from '@/features/auth/AuthProvider'
import { Button } from '@/shared/ui/Button'
import { resourceSurfaceClass } from '@/shared/ui/ResourceLayout'

export function SettingsPage() {
  const { logout, user } = useAuth()
  const [error, setError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  async function submitLogout() {
    setError(null)
    setIsSubmitting(true)

    try {
      await logout()
    } catch {
      setError('Не удалось выйти. Попробуйте ещё раз.')
      setIsSubmitting(false)
    }
  }

  return (
    <section className="mx-auto min-h-screen w-full max-w-[1306px] px-5 pb-28 pt-[calc(env(safe-area-inset-top)+36px)] sm:px-8 lg:px-7 lg:py-8">
      <h1 className="text-[32px] font-semibold leading-tight lg:text-[36px]">Настройки</h1>
      <nav aria-label="Настройки справочников" className={`${resourceSurfaceClass} mt-6 max-w-xl divide-y divide-border p-0`}>
        <Link className="flex min-h-16 items-center gap-3 px-5" to="/specialists"><UserRound className="size-5 text-primary" /><span className="flex-1">Специалисты</span><ChevronRight className="size-5 text-muted" /></Link>
        <Link className="flex min-h-16 items-center gap-3 px-5" to="/settings/services"><ClipboardList className="size-5 text-primary" /><span className="flex-1">Услуги</span><ChevronRight className="size-5 text-muted" /></Link>
        <Link className="flex min-h-16 items-center gap-3 px-5" to="/my-schedule"><UserRound className="size-5 text-primary" /><span className="flex-1">Моё расписание</span><ChevronRight className="size-5 text-muted" /></Link>
      </nav>

      <div className="mt-8 max-w-xl border-t border-border pt-6">
        <h2 className="text-lg font-semibold">Аккаунт</h2>
        <dl className="mt-5 grid gap-4 text-sm sm:grid-cols-[140px_minmax(0,1fr)]">
          <dt className="text-muted">Email</dt>
          <dd className="break-words">{user?.email}</dd>
          <dt className="text-muted">Организация</dt>
          <dd className="break-words">{user?.organization.name}</dd>
        </dl>
        <Button
          className="mt-7"
          disabled={isSubmitting}
          onClick={() => void submitLogout()}
          variant="outline"
        >
          <LogOut aria-hidden="true" className="size-5" />
          {isSubmitting ? 'Выходим...' : 'Выйти'}
        </Button>
        {error ? <p className="mt-3 text-sm text-danger" role="alert">{error}</p> : null}
      </div>
    </section>
  )
}
