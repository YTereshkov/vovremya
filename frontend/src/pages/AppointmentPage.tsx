import { CalendarDays, Clock3, Hourglass, UserRound } from 'lucide-react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'

import { useAppointment, useAppointmentConfirmation, useAppointmentHistory, type AppointmentConfirmation } from '@/features/calendar/api'
import { AppointmentResultBadge } from '@/features/calendar/AppointmentResultStatus'
import { ConfirmationStatusBadge } from '@/features/calendar/ConfirmationStatus'
import { longDate } from '@/features/calendar/date'
import { apiRequest } from '@/shared/api/request'
import { Button } from '@/shared/ui/Button'
import { ResourceFeedback, ResourceFrame, resourceSurfaceClass } from '@/shared/ui/ResourceLayout'

export function AppointmentPage() {
  const { id = '' } = useParams()
  const appointment = useAppointment(id)
  const confirmation = useAppointmentConfirmation(id)
  const history = useAppointmentHistory(id)
  const queryClient = useQueryClient()
  const sendConfirmation = useMutation({
    mutationFn: () => apiRequest<AppointmentConfirmation>(`/api/appointments/${id}/confirmation`, 'POST'),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['calendar'] }),
        queryClient.invalidateQueries({ queryKey: ['communications'] }),
      ])
    },
  })

  return <ResourceFrame back="/calendar" title="Занятие">
    {appointment.isPending ? <p role="status" className="text-muted">Загружаем занятие...</p> : null}
    <ResourceFeedback error={appointment.error} />
    {appointment.data ? <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
      <section className={resourceSurfaceClass}>
        <p className="text-sm capitalize text-muted">{longDate(appointment.data.date)}</p>
        <div className="mt-2 flex items-baseline gap-3">
          <h2 className="text-3xl font-semibold tabular-nums">{appointment.data.startTime}</h2>
          <span className="text-muted">– {appointment.data.endTime}</span>
        </div>
        <dl className="mt-8 divide-y divide-border">
          <div className="grid grid-cols-[28px_1fr] gap-3 py-4"><UserRound className="size-5 text-primary" /><div><dt className="text-xs text-muted">Клиент</dt><dd className="mt-1 font-semibold"><Link className="hover:text-primary" to={`/clients/${appointment.data.client.id}`}>{appointment.data.client.name}</Link></dd></div></div>
          <div className="grid grid-cols-[28px_1fr] gap-3 py-4"><CalendarDays className="size-5 text-primary" /><div><dt className="text-xs text-muted">Услуга</dt><dd className="mt-1 font-semibold">{appointment.data.service.name}</dd></div></div>
          <div className="grid grid-cols-[28px_1fr] gap-3 py-4"><Clock3 className="size-5 text-primary" /><div><dt className="text-xs text-muted">Специалист</dt><dd className="mt-1 font-semibold">{appointment.data.specialist.name}</dd><dd className="text-sm text-muted">{appointment.data.specialist.specialization}</dd></div></div>
          <div className="grid grid-cols-[28px_1fr] gap-3 py-4"><Hourglass className="size-5 text-primary" /><div><dt className="text-xs text-muted">Продолжительность</dt><dd className="mt-1 font-semibold">{appointment.data.durationMinutes} минут</dd></div></div>
        </dl>
      </section>
      <aside className="border-l-2 border-primary px-5 py-2">
        <h2 className="text-sm font-semibold">Подтверждение посещения</h2>
        {confirmation.isPending ? <p className="mt-3 text-sm text-muted">Загружаем...</p> : null}
        {confirmation.data ? <div className="mt-3"><ConfirmationStatusBadge status={confirmation.data.status} /></div> : null}
        {confirmation.data?.status === 'NOT_REQUESTED' ? <Button className="mt-4 w-full" disabled={sendConfirmation.isPending} onClick={() => sendConfirmation.mutate()} size="compact">{sendConfirmation.isPending ? 'Отправляем...' : 'Отправить запрос'}</Button> : null}
        <ResourceFeedback error={confirmation.error ?? sendConfirmation.error} />
        <div className="my-6 border-t border-border" />
        <h2 className="text-sm font-semibold">Результат занятия</h2>
        {appointment.data.resultStatus ? <div className="mt-3"><AppointmentResultBadge status={appointment.data.resultStatus} />{appointment.data.lateCancellation ? <span className="ml-2 text-xs font-medium text-danger">Поздняя отмена</span> : null}{appointment.data.resultComment ? <p className="mt-2 text-sm text-muted">{appointment.data.resultComment}</p> : null}</div> : <p className="mt-3 text-sm text-muted">Занятие запланировано</p>}
        <Button asChild className="mt-4 w-full" size="compact" variant="outline"><Link to={`/appointments/${id}/result`}>{appointment.data.resultStatus ? 'Изменить результат' : 'Указать результат'}</Link></Button>
        <div className="my-6 border-t border-border" />
        <h2 className="text-sm font-semibold">История</h2>
        {history.isPending ? <p className="mt-3 text-sm text-muted">Загружаем...</p> : null}
        {history.data?.length ? <ol className="mt-3 space-y-3">{history.data.map((event) => <li className="border-l-2 border-border pl-3 text-sm" key={event.id}><p>{historyLabel(event.type)}</p><time className="text-xs text-muted">{new Intl.DateTimeFormat('ru-RU', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(event.occurredAt))}</time></li>)}</ol> : !history.isPending ? <p className="mt-3 text-sm text-muted">Событий пока нет</p> : null}
        <ResourceFeedback error={history.error} />
        <div className="my-6 border-t border-border" />
        <h2 className="text-sm font-semibold">Данные услуги на момент записи</h2>
        <p className="mt-3 text-sm text-muted">Базовая длительность: {appointment.data.service.defaultDurationMinutes} минут</p>
        <p className="mt-1 text-sm text-muted">Допустимый диапазон: {appointment.data.service.minimumDurationMinutes ?? 1}–{appointment.data.service.maximumDurationMinutes ?? 1440} минут</p>
      </aside>
    </div> : null}
  </ResourceFrame>
}

function historyLabel(type: string): string {
  const labels: Record<string, string> = {
    SOFT_WARNINGS_ACCEPTED: 'Предупреждения приняты',
    CONFIRMATION_REQUESTED: 'Запрошено подтверждение',
    CONFIRMATION_CONFIRMED: 'Клиент подтвердил посещение',
    CONFIRMATION_CANNOT_ATTEND: 'Клиент сообщил, что не сможет прийти',
    CONFIRMATION_NO_RESPONSE: 'Ответ не получен',
    REMINDER_SENT: 'Отправлено повторное напоминание',
    RESULT_CHANGED: 'Результат занятия изменён',
    SPECIALIST_ABSENCE_CANCELLED: 'Отменено из-за отсутствия специалиста',
    CLIENT_ABSENCE_CANCELLED: 'Отменено на период отсутствия клиента',
  }
  return labels[type] ?? type
}
