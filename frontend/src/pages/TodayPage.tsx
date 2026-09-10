import { CalendarPlus, ChevronRight } from 'lucide-react'
import { Link } from 'react-router-dom'

import { useAuth } from '@/features/auth/AuthProvider'
import { useCalendar } from '@/features/calendar/api'
import { DayAgenda } from '@/features/calendar/components'
import { confirmationStatusLabel } from '@/features/calendar/ConfirmationStatus'
import { longDate, todayInTimezone } from '@/features/calendar/date'
import { useSpecialists } from '@/features/workforce/api'
import { Button } from '@/shared/ui/Button'
import { ResourceFeedback } from '@/shared/ui/ResourceLayout'

export function TodayPage() {
  const { user } = useAuth()
  const today = todayInTimezone(user?.organization.timezone ?? 'UTC')
  const calendar = useCalendar(today, today)
  const specialists = useSpecialists()
  const appointments = calendar.data?.appointments ?? []
  const showSpecialist = (specialists.data?.length ?? 0) > 1

  return <div className="mx-auto w-full max-w-[1306px] px-4 pb-28 pt-[calc(env(safe-area-inset-top)+20px)] sm:px-7 lg:px-8 lg:py-8">
    <header className="flex items-center gap-4">
      <div className="min-w-0 flex-1">
        <p className="text-sm capitalize text-muted">{longDate(today)}</p>
        <h1 className="mt-1 text-2xl font-semibold sm:text-3xl">Сегодня</h1>
      </div>
      <Button asChild size="compact"><Link to="/appointments/new"><CalendarPlus className="size-5" />Новое занятие</Link></Button>
    </header>

    <section aria-label="Сводка занятий" className="mt-6 border-y border-border py-5">
      <strong className="text-3xl font-semibold tabular-nums">{appointments.length}</strong>
      <span className="ml-3 font-medium">{appointments.length === 1 ? 'занятие' : appointments.length > 1 && appointments.length < 5 ? 'занятия' : 'занятий'}</span>
      {specialists.data?.length === 1 ? <p className="mt-2 text-sm text-muted">{specialists.data[0].name} · {specialists.data[0].specialization}</p> : null}
      {appointments.length ? <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">{(['CONFIRMED', 'PENDING', 'NO_RESPONSE'] as const).map((status) => {
        const count = appointments.filter((appointment) => !appointment.resultStatus && appointment.confirmationStatus === status).length
        return count ? <span key={status}>{confirmationStatusLabel(status)}: {count}</span> : null
      })}</div> : null}
    </section>

    <div className="mt-6 flex items-center justify-between gap-3">
      <h2 className="text-lg font-semibold">Расписание</h2>
      <Link className="flex items-center gap-1 text-sm font-medium text-primary" to="/calendar">Открыть календарь<ChevronRight className="size-4" /></Link>
    </div>
    {calendar.isPending || specialists.isPending ? <p className="py-12 text-center text-muted" role="status">Загружаем расписание...</p> : null}
    <ResourceFeedback error={calendar.error ?? specialists.error} />
    {!calendar.isPending && !specialists.isPending && !calendar.error && !specialists.error ? <div className="mt-3"><DayAgenda appointments={appointments} showSpecialist={showSpecialist} /></div> : null}
  </div>
}
