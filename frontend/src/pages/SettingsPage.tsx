import { ChevronRight, LogOut, MessageCircle } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'

import { useAuth } from '@/features/auth/AuthProvider'
import { useChannelSettings, useCommunicationsKey } from '@/features/communications/api'
import { ConfirmationSettingsForm } from '@/features/communications/ConfirmationSettingsForm'
import { SchedulingSettingsForm } from '@/features/scheduling/SchedulingSettingsForm'
import { apiRequest } from '@/shared/api/request'
import { Button } from '@/shared/ui/Button'
import { InfoHint } from '@/shared/ui/InfoHint'
import { resourceSurfaceClass } from '@/shared/ui/ResourceLayout'

export function SettingsPage() {
  const { logout, user } = useAuth()
  const [error, setError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const channels = useChannelSettings()
  const communicationsKey = useCommunicationsKey()
  const queryClient = useQueryClient()
  const changeDefault = useMutation({
    mutationFn: (provider: string) => apiRequest('/api/communications/channels/default', 'PUT', { provider }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [...communicationsKey, 'channels'] }),
  })

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
      <h2 className="mt-7 text-sm font-medium uppercase tracking-wide text-primary">Каналы</h2>
      <section className={`${resourceSurfaceClass} mt-3 divide-y divide-border p-0`}>
        <div className="flex min-h-16 items-center gap-3 px-5"><span className="flex min-w-0 flex-1 items-center gap-2">Канал по умолчанию для новых клиентов<InfoHint label="Канал по умолчанию">Этот канал будет заранее выбран при создании нового клиента. Для конкретного клиента его можно изменить.</InfoHint></span><select aria-label="Канал по умолчанию" className="bg-transparent text-primary" disabled={channels.isPending || changeDefault.isPending} onChange={(event) => changeDefault.mutate(event.target.value)} value={channels.data?.defaultProvider ?? user?.organization.defaultChannel ?? 'MAX'}>{channels.data?.providers.map(({ provider }) => <option key={provider} value={provider}>{provider === 'WHATSAPP' ? 'WhatsApp' : provider === 'TELEGRAM' ? 'Telegram' : provider}</option>)}</select></div>
        <Link className="flex min-h-16 items-center gap-3 px-5" to="/settings/message-templates"><MessageCircle className="size-5 text-primary" /><span className="flex-1">Шаблоны сообщений</span><span className="text-primary">Настроить</span><ChevronRight className="size-5 text-muted" /></Link>
      </section>
      {channels.error || changeDefault.error ? <p className="mt-3 text-sm text-danger" role="alert">{(channels.error ?? changeDefault.error)?.message}</p> : null}

      <h2 className="mt-7 text-sm font-medium uppercase tracking-wide text-primary">Подтверждения и напоминания</h2>
      <ConfirmationSettingsForm />

      <h2 className="mt-7 text-sm font-medium uppercase tracking-wide text-primary">Расписание</h2>
      <SchedulingSettingsForm />

      <div className="mt-8 border-t border-border pt-6">
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
