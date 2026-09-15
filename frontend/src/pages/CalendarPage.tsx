import { CalendarPlus, ChevronLeft, ChevronRight, Maximize2, Minimize2 } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'

import { useAuth } from '@/features/auth/AuthProvider'
import { useCalendar } from '@/features/calendar/api'
import { DayAgenda, DesktopDayGrid, DesktopWeekGrid, WeekAgenda } from '@/features/calendar/components'
import { longDate, shiftDate, todayInTimezone, weekDates } from '@/features/calendar/date'
import { useSpecialists } from '@/features/workforce/api'
import { OfflineNotice, ReadOnlyNotice } from '@/features/offline/OfflineNotice'
import { useOfflineSnapshot } from '@/features/offline/snapshot'
import { useConnectivity } from '@/shared/lib/connectivity'
import { cn } from '@/shared/lib/cn'
import { formatNumericDate } from '@/shared/lib/date'
import { Button } from '@/shared/ui/Button'
import { ResourceFeedback, resourceFieldClass } from '@/shared/ui/ResourceLayout'

type CalendarMode = 'day' | 'week'

export function CalendarPage() {
  const { user } = useAuth()
  const connected = useConnectivity()
  const today = todayInTimezone(user?.organization.timezone ?? 'UTC')
  const [date, setDate] = useState(today)
  const [mode, setMode] = useState<CalendarMode>('day')
  const [specialistId, setSpecialistId] = useState('')
  const [fullscreen, setFullscreen] = useState(false)
  const dates = mode === 'week' ? weekDates(date) : [date]
  const from = dates[0]
  const to = dates[dates.length - 1]
  const calendar = useCalendar(from, to, specialistId, connected)
  const specialists = useSpecialists(connected)
  const offline = useOfflineSnapshot()
  const snapshot = offline.data ?? null
  const specialistList = connected ? specialists.data : snapshot?.specialists
  const selectedSpecialists = specialistId
    ? specialistList?.filter((specialist) => specialist.id === specialistId) ?? []
    : specialistList ?? []
  const step = mode === 'week' ? 7 : 1
  const withinSnapshot = connected || Boolean(snapshot && from >= snapshot.from && to <= snapshot.to)
  const appointments = connected ? calendar.data?.appointments ?? [] : withinSnapshot && snapshot
    ? snapshot.appointments.filter((appointment) => appointment.date >= from && appointment.date <= to && (!specialistId || appointment.specialist.id === specialistId)) : []
  const showSpecialist = !specialistId && (specialistList?.length ?? 0) > 1
  const title = mode === 'week'
    ? `${formatNumericDate(from)} – ${formatNumericDate(to)}`
    : longDate(date)

  function switchMode(nextMode: CalendarMode) {
    setMode(nextMode)
    setFullscreen(false)
  }

  return <div className={cn('min-h-screen', fullscreen ? 'fixed inset-0 z-50 overflow-auto bg-canvas' : null)}>
    <div className={cn('mx-auto w-full px-4 pb-28 pt-[calc(env(safe-area-inset-top)+20px)] sm:px-7 lg:px-8 lg:py-8', fullscreen ? 'max-w-none' : 'max-w-[1500px]')}>
      {!connected ? <OfflineNotice snapshot={snapshot} timezone={user?.organization.timezone ?? 'UTC'} /> : null}
      <header className="flex flex-wrap items-center gap-3 max-[385px]:flex-col max-[385px]:items-stretch">
        <div className="min-w-0 flex-1">
          <h1 className="text-2xl font-semibold sm:text-3xl">Календарь</h1>
          <p className="mt-1 text-sm capitalize text-muted">{title}</p>
        </div>
        {connected ? <Button asChild className="max-[385px]:w-full" size="compact"><Link to="/appointments/new"><CalendarPlus className="size-5" />Новое занятие</Link></Button> : <Button className="max-[385px]:w-full" disabled size="compact" title="Доступно после подключения"><CalendarPlus className="size-5" />Новое занятие</Button>}
      </header>

      <div className="mt-6 flex flex-wrap items-center gap-3 border-y border-border py-3">
        <div aria-label="Вид календаря" className="grid grid-cols-2 rounded-lg border border-border bg-surface-raised p-1" role="group">
          {(['day', 'week'] as const).map((value) => <button aria-pressed={mode === value} className={cn('h-9 min-w-20 rounded-md px-3 text-sm font-medium', mode === value ? 'bg-accent-soft text-accent' : 'text-muted')} key={value} onClick={() => switchMode(value)} type="button">{value === 'day' ? 'День' : 'Неделя'}</button>)}
        </div>
        <div className="flex items-center gap-1">
          <Button aria-label="Предыдущий период" disabled={!connected && !snapshot} onClick={() => setDate(shiftDate(date, -step))} size="icon" title="Предыдущий период" variant="ghost"><ChevronLeft /></Button>
          <Button onClick={() => setDate(today)} size="compact" variant="outline">Сегодня</Button>
          <Button aria-label="Следующий период" disabled={!connected && !snapshot} onClick={() => setDate(shiftDate(date, step))} size="icon" title="Следующий период" variant="ghost"><ChevronRight /></Button>
        </div>
        <input aria-label="Дата календаря" className={`${resourceFieldClass} w-auto min-w-40`} disabled={!connected && !snapshot} max={!connected ? snapshot?.to : undefined} min={!connected ? snapshot?.from : undefined} onChange={(event) => setDate(event.target.value)} type="date" value={date} />
        {specialistList && specialistList.length > 1 ? <select aria-label="Фильтр специалиста" className={`${resourceFieldClass} min-w-48 flex-1 lg:max-w-72`} onChange={(event) => setSpecialistId(event.target.value)} value={specialistId}><option value="">Все специалисты</option>{specialistList.map((specialist) => <option key={specialist.id} value={specialist.id}>{specialist.name}</option>)}</select> : connected && specialists.data?.[0] ? <span className="text-sm text-muted">{specialists.data[0].name}</span> : null}
        {mode === 'week' ? <Button aria-label={fullscreen ? 'Выйти из полноэкранного режима' : 'На весь экран'} className="ml-auto hidden lg:inline-flex" onClick={() => setFullscreen((value) => !value)} size="icon" title={fullscreen ? 'Выйти из полноэкранного режима' : 'На весь экран'} variant="ghost">{fullscreen ? <Minimize2 /> : <Maximize2 />}</Button> : null}
      </div>

      {connected && (calendar.isPending || specialists.isPending) || !connected && offline.isPending ? <p className="py-12 text-center text-muted" role="status">Загружаем календарь...</p> : null}
      <ResourceFeedback error={connected ? calendar.error ?? specialists.error : offline.error} />
      {!connected && !offline.isPending && !offline.error && !snapshot ? <p className="mt-6 rounded-lg border border-border bg-surface p-5 text-muted">Копия расписания недоступна. Подключитесь к интернету, чтобы синхронизировать данные.</p> : null}
      {!connected && snapshot && !withinSnapshot ? <p className="mt-6 rounded-lg border border-border bg-surface p-5 text-muted">За выбранный период нет сохранённых данных. Выберите дату из доступного диапазона.</p> : null}
      {(connected && !calendar.isPending && !specialists.isPending && !calendar.error && !specialists.error || !connected && !offline.isPending && !offline.error && snapshot && withinSnapshot) ? <div className="mt-6">
        <div className="lg:hidden">{mode === 'day' ? <DayAgenda appointments={appointments} readOnly={!connected} showSpecialist={showSpecialist} /> : <WeekAgenda appointments={appointments} dates={dates} readOnly={!connected} showSpecialist={showSpecialist} />}</div>
        <div className="hidden lg:block">{mode === 'day' ? <DesktopDayGrid appointments={appointments} readOnly={!connected} specialists={selectedSpecialists} /> : <DesktopWeekGrid appointments={appointments} dates={dates} readOnly={!connected} showSpecialist={showSpecialist} />}</div>
      </div> : null}
      {!connected ? <ReadOnlyNotice /> : null}
    </div>
  </div>
}
