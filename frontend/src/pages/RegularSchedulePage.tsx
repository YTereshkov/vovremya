import { useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, CalendarDays, Clock3, Pencil, RefreshCcw, Trash2, UserRound } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'

import { useAuth } from '@/features/auth/AuthProvider'
import {
  endRegularSchedule,
  endRegularScheduleDay,
  replaceRegularScheduleDay,
  retryScheduleGenerationIssue,
  useRegularSchedule,
  type RegularScheduleDay,
} from '@/features/regular-schedules/api'
import { useSpecialists } from '@/features/workforce/api'
import { Button } from '@/shared/ui/Button'
import { ResourceFeedback, ResourceFrame, ResourceModal, resourceFieldClass, resourceSurfaceClass } from '@/shared/ui/ResourceLayout'

const weekdays = ['Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', 'Воскресенье']

function todayIn(timezone: string): string {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date())
  const value = Object.fromEntries(parts.map((part) => [part.type, part.value]))
  return `${value.year}-${value.month}-${value.day}`
}

function formatDate(date: string): string {
  return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${date}T00:00:00Z`))
}

export function RegularSchedulePage() {
  const { id = '' } = useParams()
  const { user } = useAuth()
  const schedule = useRegularSchedule(id)
  const specialists = useSpecialists()
  const queryClient = useQueryClient()
  const today = todayIn(user?.organization.timezone ?? 'UTC')
  const [ending, setEnding] = useState(false)
  const [endingDay, setEndingDay] = useState<RegularScheduleDay | null>(null)
  const [editingDay, setEditingDay] = useState<RegularScheduleDay | null>(null)

  const refresh = async () => {
    await queryClient.invalidateQueries({ queryKey: ['regular-schedules', user?.organization.id, id] })
    await queryClient.invalidateQueries({ queryKey: ['calendar', user?.organization.id] })
  }
  const endSchedule = useMutation({ mutationFn: (fromDate: string) => endRegularSchedule(id, fromDate), onSuccess: async () => { setEnding(false); await refresh() } })
  const endDay = useMutation({ mutationFn: ({ dayId, fromDate }: { dayId: string; fromDate: string }) => endRegularScheduleDay(id, dayId, fromDate), onSuccess: async () => { setEndingDay(null); await refresh() } })
  const editDay = useMutation({ mutationFn: ({ dayId, fromDate, startTime, durationMinutes }: { dayId: string; fromDate: string; startTime: string; durationMinutes: number }) => replaceRegularScheduleDay(id, dayId, { fromDate, startTime, durationMinutes }), onSuccess: async () => { setEditingDay(null); await refresh() } })
  const retry = useMutation({ mutationFn: retryScheduleGenerationIssue, onSuccess: refresh })
  const changeDate = schedule.data && schedule.data.startsOn > today ? schedule.data.startsOn : today

  return <ResourceFrame back="/calendar" title="Регулярное расписание" action={schedule.data?.active ? <Button onClick={() => setEnding(true)} variant="outline">Завершить</Button> : undefined}>
    {schedule.isPending ? <p role="status" className="text-muted">Загружаем расписание...</p> : null}
    <ResourceFeedback error={schedule.error} />
    {schedule.data ? <div className="space-y-5">
      <section className={resourceSurfaceClass}>
        <div className="flex items-start gap-3"><UserRound className="mt-1 size-5 text-primary" /><div><p className="text-sm text-muted">Клиент</p><Link className="text-xl font-semibold hover:text-primary" to={`/clients/${schedule.data.client.id}`}>{schedule.data.client.name}</Link></div></div>
        <div className="mt-5 flex items-start gap-3"><CalendarDays className="mt-1 size-5 text-primary" /><div><p className="text-sm text-muted">Услуга</p><p className="font-semibold">{schedule.data.service.name}</p></div></div>
        {specialists.data && specialists.data.length > 1 ? <div className="mt-5 flex items-start gap-3"><UserRound className="mt-1 size-5 text-primary" /><div><p className="text-sm text-muted">Специалист</p><p className="font-semibold">{schedule.data.specialist.name}</p></div></div> : null}
        <div className="mt-5 flex items-start gap-3"><RefreshCcw className="mt-1 size-5 text-primary" /><div><p className="text-sm text-muted">Период</p><p className="font-semibold">с {formatDate(schedule.data.startsOn)}{schedule.data.endsOn ? ` по ${formatDate(schedule.data.endsOn)}` : ''}</p><p className={`mt-1 text-sm ${schedule.data.active ? 'text-success' : 'text-muted'}`}>{schedule.data.active ? 'Расписание действует' : 'Расписание завершено'}</p></div></div>
      </section>

      <section>
        <h2 className="mb-3 text-lg font-semibold">Дни занятий</h2>
        <div className="space-y-3">{schedule.data.days.map((day) => <article className={`${resourceSurfaceClass} flex flex-wrap items-center gap-4`} key={day.id}>
          <div className="min-w-0 flex-1"><p className="font-semibold">{weekdays[day.weekday - 1]}</p><p className="mt-1 flex items-center gap-2 text-muted"><Clock3 className="size-4" />{day.startTime} · {day.durationMinutes} минут</p></div>
          {schedule.data.active ? <div className="flex gap-1"><button aria-label={`Изменить ${weekdays[day.weekday - 1]}`} className="grid size-11 place-items-center text-primary" onClick={() => setEditingDay(day)} type="button"><Pencil className="size-5" /></button><button aria-label={`Удалить ${weekdays[day.weekday - 1]}`} className="grid size-11 place-items-center text-danger" onClick={() => setEndingDay(day)} type="button"><Trash2 className="size-5" /></button></div> : null}
        </article>)}</div>
        {!schedule.data.days.length ? <p className="rounded-lg border border-border bg-white/70 p-4 text-muted">Активных дней нет.</p> : null}
      </section>

      {schedule.data.issues.length ? <section className="rounded-lg border border-warning/40 bg-white/80 p-5">
        <h2 className="flex items-center gap-2 font-semibold text-warning"><AlertTriangle className="size-5" />Требуют решения</h2>
        <ul className="mt-4 space-y-4">{schedule.data.issues.map((issue) => <li className="flex flex-wrap items-center gap-3 border-t border-border pt-4 first:border-0 first:pt-0" key={issue.id}><div className="min-w-0 flex-1"><p className="font-semibold">{formatDate(issue.date)}</p><p className="text-sm text-muted">{issue.message}</p></div><Button disabled={retry.isPending} onClick={() => retry.mutate(issue.id)} variant="outline">Проверить снова</Button></li>)}</ul>
        <ResourceFeedback error={retry.error} />
      </section> : null}
    </div> : null}

    {ending ? <EndModal title="Завершить регулярные занятия" initialDate={changeDate} pending={endSchedule.isPending} error={endSchedule.error} close={() => setEnding(false)} submit={(date) => endSchedule.mutate(date)} /> : null}
    {endingDay ? <EndModal title={`Удалить ${weekdays[endingDay.weekday - 1].toLowerCase()} из расписания`} initialDate={changeDate} pending={endDay.isPending} error={endDay.error} close={() => setEndingDay(null)} submit={(date) => endDay.mutate({ dayId: endingDay.id, fromDate: date })} /> : null}
    {editingDay ? <EditDayModal day={editingDay} initialDate={changeDate} pending={editDay.isPending} error={editDay.error} close={() => setEditingDay(null)} submit={(data) => editDay.mutate({ dayId: editingDay.id, ...data })} /> : null}
  </ResourceFrame>
}

function EndModal({ title, initialDate, pending, error, close, submit }: { title: string; initialDate: string; pending: boolean; error: unknown; close: () => void; submit: (date: string) => void }) {
  const [date, setDate] = useState(initialDate)
  return <ResourceModal title={title} close={close}>
    <p className="text-muted">Будущие занятия с выбранной даты исчезнут из календаря. История сохранится.</p>
    <label className="mt-5 block"><span className="mb-2 block">С какой даты</span><input aria-label="С какой даты" className={resourceFieldClass} min={initialDate} type="date" value={date} onChange={(event) => setDate(event.target.value)} /></label>
    <ResourceFeedback error={error} />
    <div className="mt-6 flex gap-3"><Button disabled={pending} onClick={() => submit(date)}>Подтвердить</Button><Button disabled={pending} onClick={close} variant="outline">Отмена</Button></div>
  </ResourceModal>
}

function EditDayModal({ day, initialDate, pending, error, close, submit }: { day: RegularScheduleDay; initialDate: string; pending: boolean; error: unknown; close: () => void; submit: (data: { fromDate: string; startTime: string; durationMinutes: number }) => void }) {
  const [fromDate, setFromDate] = useState(initialDate)
  const [startTime, setStartTime] = useState(day.startTime)
  const [durationMinutes, setDurationMinutes] = useState(day.durationMinutes)
  function send(event: FormEvent) { event.preventDefault(); submit({ fromDate, startTime, durationMinutes }) }

  return <ResourceModal title={`Изменить ${weekdays[day.weekday - 1].toLowerCase()}`} close={close}>
    <form className="space-y-4" onSubmit={send}>
      <label className="block"><span className="mb-2 block">Изменения действуют с</span><input aria-label="Изменения действуют с" className={resourceFieldClass} min={initialDate} required type="date" value={fromDate} onChange={(event) => setFromDate(event.target.value)} /></label>
      <label className="block"><span className="mb-2 block">Новое время</span><input aria-label="Новое время" className={resourceFieldClass} required type="time" value={startTime} onChange={(event) => setStartTime(event.target.value)} /></label>
      <label className="block"><span className="mb-2 block">Продолжительность, минут</span><input aria-label="Продолжительность, минут" className={resourceFieldClass} min={1} max={1440} required type="number" value={durationMinutes} onChange={(event) => setDurationMinutes(event.target.valueAsNumber)} /></label>
      <ResourceFeedback error={error} />
      <div className="flex gap-3"><Button disabled={pending} type="submit">Сохранить</Button><Button disabled={pending} onClick={close} type="button" variant="outline">Отмена</Button></div>
    </form>
  </ResourceModal>
}
