import { CalendarDays, Clock3, Hourglass, UserRound } from 'lucide-react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'

import { useAppointment, useAppointmentConfirmation, type AppointmentConfirmation } from '@/features/calendar/api'
import { ConfirmationStatusBadge } from '@/features/calendar/ConfirmationStatus'
import { longDate } from '@/features/calendar/date'
import { apiRequest } from '@/shared/api/request'
import { Button } from '@/shared/ui/Button'
import { ResourceFeedback, ResourceFrame, resourceSurfaceClass } from '@/shared/ui/ResourceLayout'

export function AppointmentPage() {
  const { id = '' } = useParams()
  const appointment = useAppointment(id)
  const confirmation = useAppointmentConfirmation(id)
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
        <h2 className="text-sm font-semibold">Данные услуги на момент записи</h2>
        <p className="mt-3 text-sm text-muted">Базовая длительность: {appointment.data.service.defaultDurationMinutes} минут</p>
        <p className="mt-1 text-sm text-muted">Допустимый диапазон: {appointment.data.service.minimumDurationMinutes ?? 1}–{appointment.data.service.maximumDurationMinutes ?? 1440} минут</p>
      </aside>
    </div> : null}
  </ResourceFrame>
}
