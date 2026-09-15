import { CalendarPlus, ChevronRight } from 'lucide-react'
import { Link } from 'react-router-dom'

import { useAuth } from '@/features/auth/AuthProvider'
import { useCalendar } from '@/features/calendar/api'
import { DayAgenda } from '@/features/calendar/components'
import { confirmationStatusLabel } from '@/features/calendar/ConfirmationStatus'
import { longDate, todayInTimezone } from '@/features/calendar/date'
import { OfflineNotice, ReadOnlyNotice } from '@/features/offline/OfflineNotice'
import { useOfflineSnapshot } from '@/features/offline/snapshot'
import { useSpecialists } from '@/features/workforce/api'
import { useConnectivity } from '@/shared/lib/connectivity'
import { Button } from '@/shared/ui/Button'
import { ResourceFeedback } from '@/shared/ui/ResourceLayout'

export function TodayPage() {
  const { user } = useAuth()
  const connected = useConnectivity()
  const today = todayInTimezone(user?.organization.timezone ?? 'UTC')
  const calendar = useCalendar(today, today, '', connected)
  const specialists = useSpecialists(connected)
  const offline = useOfflineSnapshot()
  const snapshot = offline.data ?? null
  const withinSnapshot = connected || Boolean(snapshot && today >= snapshot.from && today <= snapshot.to)
  const appointments = connected ? calendar.data?.appointments ?? [] : withinSnapshot && snapshot
    ? snapshot.appointments.filter((appointment) => appointment.date === today) : []
  const specialistList = connected ? specialists.data : snapshot?.specialists
  const showSpecialist = (specialistList?.length ?? 0) > 1
  const loading = connected ? calendar.isPending || specialists.isPending : offline.isPending
  const error = connected ? calendar.error ?? specialists.error : offline.error
  const available = connected ? !loading && !error : !loading && !error && snapshot && withinSnapshot

  return <div className="mx-auto w-full max-w-[1306px] px-4 pb-28 pt-[calc(env(safe-area-inset-top)+20px)] sm:px-7 lg:px-8 lg:py-8">
    {!connected ? <OfflineNotice snapshot={snapshot} timezone={user?.organization.timezone ?? 'UTC'} /> : null}
    <header className="flex items-center gap-4 max-[385px]:flex-col max-[385px]:items-stretch">
      <div className="min-w-0 flex-1">
        <p className="text-sm capitalize text-muted">{longDate(today)}</p>
        <h1 className="mt-1 text-2xl font-semibold sm:text-3xl">Сегодня</h1>
      </div>
      {connected ? <Button asChild className="max-[385px]:w-full" size="compact"><Link to="/appointments/new"><CalendarPlus className="size-5" />Новое занятие</Link></Button> : <Button className="max-[385px]:w-full" disabled size="compact" title="Доступно после подключения"><CalendarPlus className="size-5" />Новое занятие</Button>}
    </header>

    {available ? <section aria-label="Сводка занятий" className="mt-6 border-y border-border py-5">
      <strong className="text-3xl font-semibold tabular-nums">{appointments.length}</strong>
      <span className="ml-3 font-medium">{appointments.length === 1 ? 'занятие' : appointments.length > 1 && appointments.length < 5 ? 'занятия' : 'занятий'}</span>
      {connected && specialists.data?.length === 1 ? <p className="mt-2 text-sm text-muted">{specialists.data[0].name} · {specialists.data[0].specialization}</p> : null}
      {appointments.length ? <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">{(['CONFIRMED', 'PENDING', 'NO_RESPONSE'] as const).map((status) => {
        const count = appointments.filter((appointment) => !appointment.resultStatus && appointment.confirmationStatus === status).length
        return count ? <span key={status}>{confirmationStatusLabel(status)}: {count}</span> : null
      })}</div> : null}
    </section> : null}

    <div className="mt-6 flex items-center justify-between gap-3">
      <h2 className="text-lg font-semibold">Расписание</h2>
      <Link className="flex items-center gap-1 text-sm font-medium text-primary" to="/calendar">Открыть календарь<ChevronRight className="size-4" /></Link>
    </div>
    {loading ? <p className="py-12 text-center text-muted" role="status">Загружаем расписание...</p> : null}
    <ResourceFeedback error={error} />
    {!connected && !loading && !error && !snapshot ? <p className="mt-4 rounded-lg border border-border bg-surface p-5 text-muted">Копия расписания недоступна. Подключитесь к интернету, чтобы синхронизировать данные.</p> : null}
    {!connected && snapshot && !withinSnapshot ? <p className="mt-4 rounded-lg border border-border bg-surface p-5 text-muted">За сегодня нет сохранённых данных. Подключитесь к интернету, чтобы обновить расписание.</p> : null}
    {available ? <div className="mt-3"><DayAgenda appointments={appointments} readOnly={!connected} showSpecialist={showSpecialist} /></div> : null}
    {!connected ? <ReadOnlyNotice /> : null}
  </div>
}
