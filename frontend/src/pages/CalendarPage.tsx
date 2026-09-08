import { CalendarPlus, ChevronLeft, ChevronRight, Maximize2, Minimize2 } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'

import { useAuth } from '@/features/auth/AuthProvider'
import { useCalendar } from '@/features/calendar/api'
import { DayAgenda, DesktopDayGrid, DesktopWeekGrid, WeekAgenda } from '@/features/calendar/components'
import { formatDate, longDate, shiftDate, todayInTimezone, weekDates } from '@/features/calendar/date'
import { useSpecialists } from '@/features/workforce/api'
import { cn } from '@/shared/lib/cn'
import { Button } from '@/shared/ui/Button'
import { ResourceFeedback, resourceFieldClass } from '@/shared/ui/ResourceLayout'

type CalendarMode = 'day' | 'week'

export function CalendarPage() {
  const { user } = useAuth()
  const today = todayInTimezone(user?.organization.timezone ?? 'UTC')
  const [date, setDate] = useState(today)
  const [mode, setMode] = useState<CalendarMode>('day')
  const [specialistId, setSpecialistId] = useState('')
  const [fullscreen, setFullscreen] = useState(false)
  const dates = mode === 'week' ? weekDates(date) : [date]
  const from = dates[0]
  const to = dates[dates.length - 1]
  const calendar = useCalendar(from, to, specialistId)
  const specialists = useSpecialists()
  const selectedSpecialists = specialistId
    ? specialists.data?.filter((specialist) => specialist.id === specialistId) ?? []
    : specialists.data ?? []
  const step = mode === 'week' ? 7 : 1
  const appointments = calendar.data?.appointments ?? []
  const showSpecialist = !specialistId && (specialists.data?.length ?? 0) > 1
  const title = mode === 'week'
    ? `${formatDate(from, { day: 'numeric', month: 'short' })} – ${formatDate(to, { day: 'numeric', month: 'short', year: 'numeric' })}`
    : longDate(date)

  function switchMode(nextMode: CalendarMode) {
    setMode(nextMode)
    setFullscreen(false)
  }

  return <div className={cn('min-h-screen', fullscreen ? 'fixed inset-0 z-50 overflow-auto bg-[#f8faf8]' : null)}>
    <div className={cn('mx-auto w-full px-4 pb-28 pt-[calc(env(safe-area-inset-top)+20px)] sm:px-7 lg:px-8 lg:py-8', fullscreen ? 'max-w-none' : 'max-w-[1500px]')}>
      <header className="flex flex-wrap items-center gap-3">
        <div className="min-w-0 flex-1">
          <h1 className="text-2xl font-semibold sm:text-3xl">Календарь</h1>
          <p className="mt-1 text-sm capitalize text-muted">{title}</p>
        </div>
        <Button asChild size="compact"><Link to="/appointments/new"><CalendarPlus className="size-5" />Новое занятие</Link></Button>
      </header>

      <div className="mt-6 flex flex-wrap items-center gap-3 border-y border-border py-3">
        <div aria-label="Вид календаря" className="grid grid-cols-2 rounded-lg border border-border bg-white p-1" role="group">
          {(['day', 'week'] as const).map((value) => <button aria-pressed={mode === value} className={cn('h-9 min-w-20 rounded-md px-3 text-sm font-medium', mode === value ? 'bg-primary-soft text-primary' : 'text-muted')} key={value} onClick={() => switchMode(value)} type="button">{value === 'day' ? 'День' : 'Неделя'}</button>)}
        </div>
        <div className="flex items-center gap-1">
          <Button aria-label="Предыдущий период" onClick={() => setDate(shiftDate(date, -step))} size="icon" title="Предыдущий период" variant="ghost"><ChevronLeft /></Button>
          <Button onClick={() => setDate(today)} size="compact" variant="outline">Сегодня</Button>
          <Button aria-label="Следующий период" onClick={() => setDate(shiftDate(date, step))} size="icon" title="Следующий период" variant="ghost"><ChevronRight /></Button>
        </div>
        <input aria-label="Дата календаря" className={`${resourceFieldClass} w-auto min-w-40`} onChange={(event) => setDate(event.target.value)} type="date" value={date} />
        {specialists.data && specialists.data.length > 1 ? <select aria-label="Фильтр специалиста" className={`${resourceFieldClass} min-w-48 flex-1 lg:max-w-72`} onChange={(event) => setSpecialistId(event.target.value)} value={specialistId}><option value="">Все специалисты</option>{specialists.data.map((specialist) => <option key={specialist.id} value={specialist.id}>{specialist.name}</option>)}</select> : specialists.data?.[0] ? <span className="text-sm text-muted">{specialists.data[0].name}</span> : null}
        {mode === 'week' ? <Button aria-label={fullscreen ? 'Выйти из полноэкранного режима' : 'На весь экран'} className="ml-auto hidden lg:inline-flex" onClick={() => setFullscreen((value) => !value)} size="icon" title={fullscreen ? 'Выйти из полноэкранного режима' : 'На весь экран'} variant="ghost">{fullscreen ? <Minimize2 /> : <Maximize2 />}</Button> : null}
      </div>

      {calendar.isPending || specialists.isPending ? <p className="py-12 text-center text-muted" role="status">Загружаем календарь...</p> : null}
      <ResourceFeedback error={calendar.error ?? specialists.error} />
      {!calendar.isPending && !specialists.isPending && !calendar.error && !specialists.error ? <div className="mt-6">
        <div className="lg:hidden">{mode === 'day' ? <DayAgenda appointments={appointments} showSpecialist={showSpecialist} /> : <WeekAgenda appointments={appointments} dates={dates} showSpecialist={showSpecialist} />}</div>
        <div className="hidden lg:block">{mode === 'day' ? <DesktopDayGrid appointments={appointments} specialists={selectedSpecialists} /> : <DesktopWeekGrid appointments={appointments} dates={dates} showSpecialist={showSpecialist} />}</div>
      </div> : null}
    </div>
  </div>
}
