import { useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { CalendarDays, ChevronRight, Clock3, Hourglass, Plus, RefreshCcw, Trash2, UserRound, X } from 'lucide-react'
import { Link } from 'react-router-dom'

import { useAuth } from '@/features/auth/AuthProvider'
import { useServices } from '@/features/catalog/api'
import { useClients } from '@/features/clients/api'
import {
  createRegularSchedule,
  type RegularScheduleConflictPayload,
  type RegularScheduleRecord,
} from '@/features/regular-schedules/api'
import { useSpecialists } from '@/features/workforce/api'
import { ApiError } from '@/shared/api/request'
import { formatNumericDate } from '@/shared/lib/date'
import { Button } from '@/shared/ui/Button'
import { ResourceFeedback, resourceFieldClass } from '@/shared/ui/ResourceLayout'

const weekdays = ['Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', 'Воскресенье']
const cardClass = 'rounded-2xl border border-border bg-surface p-4 shadow-surface sm:p-5'

interface DayInput {
  key: number
  weekday: number
  startTime: string
  durationMinutes: number
}

function todayIn(timezone: string): string {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: timezone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(new Date())
  const value = Object.fromEntries(parts.map((part) => [part.type, part.value]))
  return `${value.year}-${value.month}-${value.day}`
}

export function RegularScheduleFormPage() {
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const specialists = useSpecialists()
  const clients = useClients('')
  const services = useServices()
  const [specialistId, setSpecialistId] = useState('')
  const [clientId, setClientId] = useState('')
  const [serviceId, setServiceId] = useState('')
  const [startsOn, setStartsOn] = useState(() => todayIn(user?.organization.timezone ?? 'UTC'))
  const [endsOn, setEndsOn] = useState('')
  const [nextKey, setNextKey] = useState(2)
  const [days, setDays] = useState<DayInput[]>([{ key: 1, weekday: 1, startTime: '17:00', durationMinutes: 0 }])
  const [created, setCreated] = useState<RegularScheduleRecord | null>(null)

  const selectedSpecialistId = specialistId || specialists.data?.[0]?.id || ''
  const selectedClientId = clientId || clients.data?.[0]?.id || ''
  const selectedServiceId = serviceId || services.data?.[0]?.id || ''
  const selectedService = services.data?.find(({ id }) => id === selectedServiceId)
  const mutation = useMutation<RegularScheduleRecord, ApiError<RegularScheduleConflictPayload>>({
    mutationFn: () => createRegularSchedule({
      specialistId: selectedSpecialistId,
      clientId: selectedClientId,
      serviceId: selectedServiceId,
      startsOn,
      endsOn: endsOn || null,
      days: days.map(({ weekday, startTime, durationMinutes }) => ({ weekday, startTime, durationMinutes: durationMinutes || selectedService?.defaultDurationMinutes || 0 })),
    }),
    onSuccess: (schedule) => {
      setCreated(schedule)
      void queryClient.invalidateQueries({ queryKey: ['calendar', user?.organization.id] })
      void queryClient.invalidateQueries({ queryKey: ['regular-schedules', user?.organization.id] })
    },
    onError: () => setCreated(null),
  })
  const loading = specialists.isPending || clients.isPending || services.isPending
  const ready = Boolean(selectedSpecialistId && selectedClientId && selectedServiceId && startsOn && days.length && days.every((day) => day.startTime && (day.durationMinutes || selectedService?.defaultDurationMinutes)))
  const conflict = mutation.error?.payload.kind === 'HARD_CONFLICT' ? mutation.error.payload : null

  function submit(event: FormEvent) {
    event.preventDefault()
    setCreated(null)
    mutation.mutate()
  }

  function changeService(nextId: string) {
    const previousDefault = selectedService?.defaultDurationMinutes
    const next = services.data?.find(({ id }) => id === nextId)
    setServiceId(nextId)
    if (next) {
      setDays((current) => current.map((day) => ({
        ...day,
        durationMinutes: previousDefault === undefined || day.durationMinutes === previousDefault ? next.defaultDurationMinutes : day.durationMinutes,
      })))
    }
  }

  function updateDay(key: number, patch: Partial<DayInput>) {
    setDays((current) => current.map((day) => day.key === key ? { ...day, ...patch } : day))
  }

  function addDay() {
    const used = new Set(days.map(({ weekday }) => weekday))
    const weekday = weekdays.findIndex((_, index) => !used.has(index + 1)) + 1
    if (!weekday) return
    setDays((current) => [...current, {
      key: nextKey,
      weekday,
      startTime: '17:00',
      durationMinutes: selectedService?.defaultDurationMinutes ?? 45,
    }])
    setNextKey((value) => value + 1)
  }

  return <main className="app-backdrop min-h-screen px-4 pb-10 pt-[calc(env(safe-area-inset-top)+18px)] sm:px-8">
    <div className="mx-auto w-full max-w-3xl">
      <header className="relative flex min-h-14 items-center justify-center">
        <Link aria-label="Закрыть" className="absolute left-0 grid size-11 place-items-center text-primary" to="/calendar"><X className="size-7" /></Link>
        <h1 className="text-2xl font-semibold">Новое занятие</h1>
      </header>

      <div className="mt-6 grid grid-cols-2 overflow-hidden rounded-2xl border border-border bg-surface p-2">
        <Link className="flex h-14 items-center justify-center gap-2 text-muted" to="/appointments/new"><CalendarDays className="size-5" />Разовое</Link>
        <span className="flex h-14 items-center justify-center gap-2 rounded-xl bg-accent-soft font-medium text-accent"><RefreshCcw className="size-5" />Регулярное</span>
      </div>

      <form className="mt-6 space-y-4" onSubmit={submit}>
        <label className={`${cardClass} block`}>
          <span className="mb-2 flex items-center gap-3 text-sm text-muted"><UserRound className="size-5 text-primary" />Клиент</span>
          <span className="flex items-center gap-3"><select aria-label="Клиент" className={`${resourceFieldClass} appearance-none border-0 bg-transparent px-0 text-lg font-semibold focus:ring-0`} required value={selectedClientId} onChange={(event) => setClientId(event.target.value)}><option value="">Выберите клиента</option>{clients.data?.map((client) => <option key={client.id} value={client.id}>{client.name}</option>)}</select><ChevronRight className="size-5 shrink-0 text-primary" /></span>
        </label>

        <label className={`${cardClass} block`}>
          <span className="mb-2 flex items-center gap-3 text-sm text-muted"><CalendarDays className="size-5 text-primary" />Услуга</span>
          <span className="flex items-center gap-3"><select aria-label="Услуга" className={`${resourceFieldClass} appearance-none border-0 bg-transparent px-0 text-lg font-semibold focus:ring-0`} required value={selectedServiceId} onChange={(event) => changeService(event.target.value)}><option value="">Выберите услугу</option>{services.data?.map((service) => <option key={service.id} value={service.id}>{service.name}</option>)}</select><ChevronRight className="size-5 shrink-0 text-primary" /></span>
        </label>

        {specialists.data && specialists.data.length > 1 ? <label className={`${cardClass} block`}><span className="mb-2 block text-sm text-muted">Специалист</span><select aria-label="Специалист" className={resourceFieldClass} required value={selectedSpecialistId} onChange={(event) => setSpecialistId(event.target.value)}>{specialists.data.map((specialist) => <option key={specialist.id} value={specialist.id}>{specialist.name}</option>)}</select></label> : null}

        <fieldset className={`${cardClass} space-y-3`}>
          <legend className="px-1 text-lg font-semibold">Дни и время</legend>
          {days.map((day) => <div className="grid gap-3 rounded-xl border border-border p-3 sm:grid-cols-[minmax(0,1fr)_125px_125px_auto] sm:items-end" key={day.key}>
            <label><span className="mb-1 block text-sm text-muted">День</span><select aria-label="День недели" className={resourceFieldClass} value={day.weekday} onChange={(event) => updateDay(day.key, { weekday: Number(event.target.value) })}>{weekdays.map((label, index) => <option disabled={days.some((item) => item.key !== day.key && item.weekday === index + 1)} key={label} value={index + 1}>{label}</option>)}</select></label>
            <label><span className="mb-1 flex items-center gap-1 text-sm text-muted"><Clock3 className="size-4" />Время</span><input aria-label={`Время, ${weekdays[day.weekday - 1]}`} className={resourceFieldClass} required type="time" value={day.startTime} onChange={(event) => updateDay(day.key, { startTime: event.target.value })} /></label>
            <label><span className="mb-1 flex items-center gap-1 text-sm text-muted"><Hourglass className="size-4" />Минут</span><input aria-label={`Продолжительность, ${weekdays[day.weekday - 1]}`} className={resourceFieldClass} min={selectedService?.minimumDurationMinutes ?? 1} max={selectedService?.maximumDurationMinutes ?? 1440} required type="number" value={day.durationMinutes || selectedService?.defaultDurationMinutes || ''} onChange={(event) => updateDay(day.key, { durationMinutes: event.target.valueAsNumber })} /></label>
            <button aria-label={`Удалить ${weekdays[day.weekday - 1]}`} className="grid size-11 place-items-center text-danger disabled:opacity-30" disabled={days.length === 1} onClick={() => setDays((current) => current.filter(({ key }) => key !== day.key))} type="button"><Trash2 className="size-5" /></button>
          </div>)}
          <Button disabled={days.length === 7} onClick={addDay} type="button" variant="outline"><Plus className="size-5" />Добавить день</Button>
        </fieldset>

        <div className="grid gap-3 sm:grid-cols-2">
          <label className={cardClass}><span className="mb-2 block text-sm text-muted">Начало расписания</span><input aria-label="Начало расписания" className={`${resourceFieldClass} border-0 bg-transparent px-0 text-lg font-semibold focus:ring-0`} min={todayIn(user?.organization.timezone ?? 'UTC')} required type="date" value={startsOn} onChange={(event) => setStartsOn(event.target.value)} /></label>
          <label className={cardClass}><span className="mb-2 block text-sm text-muted">Окончание, если известно</span><input aria-label="Окончание расписания" className={`${resourceFieldClass} border-0 bg-transparent px-0 text-lg font-semibold focus:ring-0`} min={startsOn} type="date" value={endsOn} onChange={(event) => setEndsOn(event.target.value)} /></label>
        </div>

        {loading ? <p role="status" className="text-muted">Загружаем данные...</p> : null}
        {!loading && !ready ? <p role="alert" className="text-danger">Нужны специалист, клиент, услуга и хотя бы один день.</p> : null}
        {conflict ? <section role="alert" className="rounded-2xl border border-danger/30 bg-surface-raised p-4">
          <h2 className="font-semibold text-danger">Есть недоступные даты</h2>
          <p className="mt-1 text-sm text-muted">Регулярное расписание не создано. Измените день или время.</p>
          <ul className="mt-3 space-y-2">{conflict.conflicts?.map((item) => <li key={`${item.date}-${item.time}`}><strong>{formatNumericDate(item.date)}, {item.time}</strong><span className="block text-sm text-muted">{item.message}</span></li>)}</ul>
        </section> : null}
        {created ? <div role="status" className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-success/30 bg-primary-soft p-4 text-success"><span>Регулярное расписание создано.</span><Link className="font-medium underline" to={`/regular-schedules/${created.id}`}>Открыть расписание</Link></div> : null}
        <ResourceFeedback error={conflict ? null : mutation.error} />
        <Button className="w-full" disabled={loading || !ready || mutation.isPending} type="submit">{mutation.isPending ? 'Создаём...' : 'Создать расписание'}</Button>
        <Button asChild className="w-full" variant="outline"><Link to="/calendar">Отмена</Link></Button>
      </form>
    </div>
  </main>
}
