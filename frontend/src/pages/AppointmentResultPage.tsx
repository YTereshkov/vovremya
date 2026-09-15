import { useMutation, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'

import type { AppointmentResultStatus } from '@/features/calendar/api'
import { useAppointment, useAppointmentResult } from '@/features/calendar/api'
import { appointmentResultLabel } from '@/features/calendar/AppointmentResultStatus'
import { useSchedulingSettings } from '@/features/scheduling/api'
import { apiRequest } from '@/shared/api/request'
import { formatNumericDate } from '@/shared/lib/date'
import { Button } from '@/shared/ui/Button'
import { ResourceFeedback, ResourceFrame, resourceFieldClass, resourceSurfaceClass } from '@/shared/ui/ResourceLayout'

const statuses: AppointmentResultStatus[] = ['CONDUCTED', 'CANCELLED_BY_CLIENT', 'CANCELLED_BY_SPECIALIST', 'NO_SHOW']

export function AppointmentResultPage() {
  const { id = '' } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const appointment = useAppointment(id)
  const currentResult = useAppointmentResult(id)
  const settings = useSchedulingSettings()
  const [status, setStatus] = useState<AppointmentResultStatus>('CONDUCTED')
  const [respectfulReason, setRespectfulReason] = useState(false)
  const [comment, setComment] = useState('')
  const [createFreeWindow, setCreateFreeWindow] = useState(false)

  useEffect(() => {
    if (!appointment.data || !currentResult.data) return
    setStatus(currentResult.data.status ?? (new Date(appointment.data.startsAt).getTime() > Date.now() ? 'CANCELLED_BY_CLIENT' : 'CONDUCTED'))
    setRespectfulReason(currentResult.data.respectfulReason)
    setComment(currentResult.data.comment ?? '')
    setCreateFreeWindow(currentResult.data.freeWindowOpen)
  }, [appointment.data, currentResult.data])

  const future = appointment.data ? new Date(appointment.data.startsAt).getTime() > Date.now() : false
  const late = appointment.data && settings.data && status === 'CANCELLED_BY_CLIENT'
    ? Date.now() > new Date(appointment.data.startsAt).getTime() - settings.data.lateCancellationHours * 3_600_000
    : false
  const save = useMutation({
    mutationFn: () => apiRequest(`/api/appointments/${id}/result`, 'PUT', {
      status,
      respectfulReason: late ? respectfulReason : false,
      comment: comment.trim() || null,
      createFreeWindow: status === 'CANCELLED_BY_CLIENT' && future ? createFreeWindow : false,
    }),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['calendar'] }),
        queryClient.invalidateQueries({ queryKey: ['free-windows'] }),
      ])
      navigate(`/appointments/${id}`)
    },
  })

  return <ResourceFrame back={`/appointments/${id}`} title="Результат занятия">
    <ResourceFeedback error={appointment.error ?? currentResult.error ?? settings.error ?? save.error} />
    {appointment.data ? <form className={resourceSurfaceClass} onSubmit={(event) => { event.preventDefault(); save.mutate() }}>
      <p className="text-sm text-muted">{appointment.data.client.name} · {formatNumericDate(appointment.data.date)} в {appointment.data.startTime}</p>
      <fieldset className="mt-6 grid gap-3">
        <legend className="mb-2 font-semibold">Что произошло с занятием?</legend>
        {statuses.map((value) => <label className="flex min-h-12 items-center gap-3 rounded-lg border border-border px-4" key={value}><input checked={status === value} name="status" onChange={() => { setStatus(value); if (value !== 'CANCELLED_BY_CLIENT') { setRespectfulReason(false); setCreateFreeWindow(false) } }} type="radio" />{appointmentResultLabel(value)}</label>)}
      </fieldset>
      {late ? <div className="mt-5 rounded-lg bg-warning/10 p-4 text-sm text-ink"><p className="flex items-center gap-2 font-semibold"><AlertTriangle className="size-5 text-warning" />Поздняя отмена</p><p className="mt-1 text-muted">До занятия осталось меньше {settings.data?.lateCancellationHours} часов.</p><label className="mt-3 flex items-center gap-2"><input checked={respectfulReason} onChange={(event) => setRespectfulReason(event.target.checked)} type="checkbox" />Уважительная причина</label></div> : null}
      {status === 'CANCELLED_BY_CLIENT' && future ? <label className="mt-5 flex items-start gap-3 rounded-lg border border-border p-4"><input checked={createFreeWindow} className="mt-1" onChange={(event) => setCreateFreeWindow(event.target.checked)} type="checkbox" /><span><strong className="block">Создать разовое свободное окно</strong><span className="text-sm text-muted">Окно появится в разделе ожидания.</span></span></label> : null}
      <label className="mt-5 block text-sm font-medium">Комментарий<textarea className={`${resourceFieldClass} mt-2 min-h-24 w-full resize-y`} maxLength={1000} onChange={(event) => setComment(event.target.value)} value={comment} /></label>
      <Button className="mt-6 w-full" disabled={save.isPending} type="submit">{save.isPending ? 'Сохраняем...' : 'Сохранить результат'}</Button>
    </form> : appointment.isPending ? <p role="status">Загружаем...</p> : null}
  </ResourceFrame>
}
