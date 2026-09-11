import { useEffect, useState, type FormEvent } from 'react'
import { Plus, Trash2 } from 'lucide-react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router-dom'

import { useServices } from '@/features/catalog/api'
import { useClient } from '@/features/clients/api'
import { endWaitingList, saveWaitingList, useWaitingList, type WaitingAvailability } from '@/features/waiting/api'
import { useSpecialists } from '@/features/workforce/api'
import { Button } from '@/shared/ui/Button'
import { ResourceFeedback, ResourceFrame, resourceFieldClass, resourceSurfaceClass } from '@/shared/ui/ResourceLayout'

const weekdays = ['Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', 'Воскресенье']

export function WaitingListFormPage() {
  const { id = '' } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const client = useClient(id)
  const services = useServices()
  const specialists = useSpecialists()
  const waiting = useWaitingList(id)
  const [serviceId, setServiceId] = useState('')
  const [specialistId, setSpecialistId] = useState('')
  const [frequency, setFrequency] = useState(1)
  const [readyForOneOff, setReadyForOneOff] = useState(true)
  const [comment, setComment] = useState('')
  const [days, setDays] = useState<WaitingAvailability[]>([{ weekday: 1, startTime: '09:00', endTime: null }])
  const [initialized, setInitialized] = useState(false)

  useEffect(() => {
    if (initialized || waiting.isPending || services.isPending) return
    if (waiting.data) {
      setServiceId(waiting.data.service.id)
      setSpecialistId(waiting.data.specialist?.id ?? '')
      setFrequency(waiting.data.requiredFrequency)
      setReadyForOneOff(waiting.data.readyForOneOff)
      setComment(waiting.data.comment ?? '')
      setDays(waiting.data.availability)
    } else if (services.data?.[0]) {
      setServiceId(services.data[0].id)
    }
    setInitialized(true)
  }, [initialized, services.data, services.isPending, waiting.data, waiting.isPending])

  const save = useMutation({
    mutationFn: () => saveWaitingList(id, {
      serviceId,
      specialistId: specialistId || null,
      requiredFrequency: frequency,
      readyForOneOff,
      comment: comment || null,
      availability: days,
    }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['waiting-list'] })
      navigate(`/clients/${id}`)
    },
  })
  const end = useMutation({
    mutationFn: () => endWaitingList(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['waiting-list'] })
      navigate(`/clients/${id}`)
    },
  })
  const selectedService = services.data?.find(({ id: value }) => value === serviceId)

  function submit(event: FormEvent) {
    event.preventDefault()
    save.mutate()
  }

  function changeDay(index: number, patch: Partial<WaitingAvailability>) {
    setDays((current) => current.map((day, position) => position === index ? { ...day, ...patch } : day))
  }

  return <ResourceFrame back={`/clients/${id}`} title="Настроить ожидание">
    <form className="space-y-4" onSubmit={submit}>
      <section className={resourceSurfaceClass}><label className="block space-y-2"><span className="text-sm text-muted">Клиент</span><input className={resourceFieldClass} disabled value={client.data?.name ?? 'Загружаем...'} /></label></section>
      <section className={resourceSurfaceClass}><label className="block space-y-2"><span className="text-sm text-muted">Услуга</span><select className={resourceFieldClass} required value={serviceId} onChange={(event) => setServiceId(event.target.value)}>{waiting.data && !waiting.data.service.active && !services.data?.some(({ id: value }) => value === waiting.data?.service.id) ? <option value={waiting.data.service.id}>{waiting.data.service.name} · удалена</option> : null}{services.data?.map((service) => <option key={service.id} value={service.id}>{service.name}</option>)}</select></label>{selectedService ? <p className="mt-2 text-sm text-muted">Длительность: {selectedService.defaultDurationMinutes} минут</p> : waiting.data?.service.id === serviceId ? <p className="mt-2 text-sm text-muted">Длительность: {waiting.data.service.durationMinutes} минут</p> : null}</section>
      {specialists.data && specialists.data.length > 1 ? <section className={resourceSurfaceClass}><label className="block space-y-2"><span className="text-sm text-muted">Специалист</span><select className={resourceFieldClass} value={specialistId} onChange={(event) => setSpecialistId(event.target.value)}><option value="">Любой подходящий</option>{specialists.data.map((specialist) => <option key={specialist.id} value={specialist.id}>{specialist.name}</option>)}</select></label></section> : null}
      <section className={resourceSurfaceClass}><label className="block space-y-2"><span className="text-sm text-muted">Необходимая частота занятий</span><select className={resourceFieldClass} value={frequency} onChange={(event) => setFrequency(Number(event.target.value))}>{[1, 2, 3, 4, 5, 6, 7].map((value) => <option key={value} value={value}>{value} {value === 1 ? 'раз' : value < 5 ? 'раза' : 'раз'} в неделю</option>)}</select></label></section>

      <section className={resourceSurfaceClass}>
        <h2 className="font-semibold">Подходящие дни и время</h2>
        <div className="mt-4 space-y-3">{days.map((day, index) => <div className="grid gap-2 rounded-xl border border-border p-3 sm:grid-cols-[1fr_130px_130px_44px]" key={`${day.weekday}-${index}`}>
          <select aria-label="День недели" className={resourceFieldClass} value={day.weekday} onChange={(event) => changeDay(index, { weekday: Number(event.target.value) })}>{weekdays.map((label, position) => <option key={label} value={position + 1}>{label}</option>)}</select>
          <input aria-label="Время с" className={resourceFieldClass} required type="time" value={day.startTime} onChange={(event) => changeDay(index, { startTime: event.target.value })} />
          <input aria-label="Время до" className={resourceFieldClass} min={day.startTime} type="time" value={day.endTime ?? ''} onChange={(event) => changeDay(index, { endTime: event.target.value || null })} />
          <button aria-label="Удалить день" className="grid min-h-11 place-items-center text-danger" disabled={days.length === 1} onClick={() => setDays((current) => current.filter((_, position) => position !== index))} type="button"><Trash2 className="size-5" /></button>
        </div>)}</div>
        <Button className="mt-4 w-full" disabled={days.length >= 7} onClick={() => setDays((current) => [...current, { weekday: firstFreeWeekday(current), startTime: '09:00', endTime: null }])} type="button" variant="outline"><Plus className="size-5" />Добавить день</Button>
      </section>

      <section className={resourceSurfaceClass}><label className="flex items-center justify-between gap-4"><span>Готов приходить на разовые свободные окна</span><input className="size-6 accent-primary" checked={readyForOneOff} onChange={(event) => setReadyForOneOff(event.target.checked)} type="checkbox" /></label></section>
      <section className={resourceSurfaceClass}><label className="block space-y-2"><span>Организационный комментарий</span><textarea className={`${resourceFieldClass} min-h-24 resize-y`} maxLength={300} value={comment} onChange={(event) => setComment(event.target.value)} /></label><p className="mt-1 text-right text-xs text-muted">{comment.length}/300</p></section>
      <ResourceFeedback error={client.error ?? services.error ?? specialists.error ?? waiting.error ?? save.error ?? end.error} />
      <Button className="w-full" disabled={!serviceId || save.isPending}>Сохранить условия</Button>
      <Button className="w-full" disabled={save.isPending} onClick={() => navigate(`/clients/${id}`)} type="button" variant="outline">Отмена</Button>
      {waiting.data ? <button className="mx-auto block min-h-11 text-danger" disabled={end.isPending} onClick={() => end.mutate()} type="button">Удалить из листа ожидания</button> : null}
    </form>
  </ResourceFrame>
}

function firstFreeWeekday(days: WaitingAvailability[]): number {
  return [1, 2, 3, 4, 5, 6, 7].find((weekday) => !days.some((day) => day.weekday === weekday)) ?? 1
}
