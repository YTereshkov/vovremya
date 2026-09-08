import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { CalendarDays, ChevronRight, Clock3, Hourglass, RefreshCcw, UserRound, X } from 'lucide-react'
import { Link } from 'react-router-dom'

import {
  createAppointment,
  type AppointmentErrorPayload,
  type AppointmentRecord,
  type AppointmentWarning,
  type AppointmentWarningCode,
} from '@/features/appointments/api'
import { useAuth } from '@/features/auth/AuthProvider'
import { useServices } from '@/features/catalog/api'
import { useClients } from '@/features/clients/api'
import { useSpecialists } from '@/features/workforce/api'
import { ApiError } from '@/shared/api/request'
import { Button } from '@/shared/ui/Button'
import { ResourceFeedback, resourceFieldClass } from '@/shared/ui/ResourceLayout'

const cardClass = 'rounded-2xl border border-border bg-white/75 p-4 shadow-surface sm:p-5'

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

function DecisionDialog({
  error,
  pending,
  close,
  changeTime,
  continueCreation,
}: {
  error: AppointmentErrorPayload
  pending: boolean
  close: () => void
  changeTime: () => void
  continueCreation: (warnings: AppointmentWarningCode[]) => void
}) {
  const dialog = useRef<HTMLDialogElement>(null)
  const warnings = error.warnings ?? []
  const soft = error.kind === 'SOFT_WARNING'

  useEffect(() => {
    dialog.current?.showModal()
    return () => dialog.current?.close()
  }, [])

  return <dialog ref={dialog} onCancel={close} aria-label={soft ? 'Предупреждение о времени' : 'Время недоступно'} className="fixed inset-0 m-auto max-h-[92dvh] w-[calc(100%-32px)] max-w-lg overflow-auto rounded-3xl border-0 bg-[#f8faf8] p-6 text-ink shadow-2xl backdrop:bg-ink/70 sm:p-8">
    <div className={`mx-auto grid size-16 place-items-center rounded-full border-2 ${soft ? 'border-warning text-warning' : 'border-danger text-danger'}`}>
      <span className="text-4xl leading-none">{soft ? '!' : '×'}</span>
    </div>
    <h2 className="mt-5 text-center text-2xl font-semibold">{soft ? warnings[0]?.title ?? 'Проверьте время' : 'Время недоступно'}</h2>
    <div className="mt-4 space-y-3 text-center text-muted">
      {soft ? warnings.map((warning: AppointmentWarning) => <div key={warning.code}>
        {warning.title !== warnings[0]?.title ? <p className="font-semibold text-ink">{warning.title}</p> : null}
        <p>{warning.message}</p>
      </div>) : <p>{error.conflict?.message ?? error.message ?? 'Выбранный интервал больше недоступен.'}</p>}
    </div>
    <div className="mt-7 space-y-3">
      <Button className="w-full" disabled={pending} onClick={changeTime}>Изменить время</Button>
      {soft ? <Button className="w-full border-warning text-warning" disabled={pending} onClick={() => continueCreation(warnings.map(({ code }) => code))} variant="outline">{pending ? 'Создаём...' : 'Всё равно создать'}</Button> : null}
      <button className="h-11 w-full text-muted" disabled={pending} onClick={close} type="button">Отмена</button>
    </div>
  </dialog>
}

export function AppointmentFormPage() {
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const specialists = useSpecialists()
  const clients = useClients('')
  const services = useServices()
  const [specialistId, setSpecialistId] = useState('')
  const [clientId, setClientId] = useState('')
  const [serviceId, setServiceId] = useState('')
  const [date, setDate] = useState(() => todayIn(user?.organization.timezone ?? 'UTC'))
  const [startTime, setStartTime] = useState('09:00')
  const [duration, setDuration] = useState('')
  const [decision, setDecision] = useState<AppointmentErrorPayload | null>(null)
  const [created, setCreated] = useState<AppointmentRecord | null>(null)
  const timeInput = useRef<HTMLInputElement>(null)

  const selectedSpecialistId = specialistId || specialists.data?.[0]?.id || ''
  const selectedClientId = clientId || clients.data?.[0]?.id || ''
  const selectedServiceId = serviceId || services.data?.[0]?.id || ''
  const selectedService = services.data?.find(({ id }) => id === selectedServiceId)
  const durationValue = duration || String(selectedService?.defaultDurationMinutes ?? '')
  const mutation = useMutation<AppointmentRecord, ApiError<AppointmentErrorPayload>, AppointmentWarningCode[]>({
    mutationFn: (acceptedWarnings) => createAppointment({
      specialistId: selectedSpecialistId,
      clientId: selectedClientId,
      serviceId: selectedServiceId,
      date,
      startTime,
      durationMinutes: Number(durationValue),
      acceptedWarnings,
    }),
    onSuccess: (appointment) => {
      setDecision(null)
      setCreated(appointment)
      void queryClient.invalidateQueries({ queryKey: ['calendar', user?.organization.id] })
    },
    onError: (error) => {
      setCreated(null)
      if (error.payload.kind === 'SOFT_WARNING' || error.payload.kind === 'HARD_CONFLICT') setDecision(error.payload)
    },
  })
  const loading = specialists.isPending || clients.isPending || services.isPending
  const ready = Boolean(selectedSpecialistId && selectedClientId && selectedServiceId && date && startTime && durationValue)

  function submit(event: FormEvent) {
    event.preventDefault()
    setDecision(null)
    setCreated(null)
    mutation.mutate([])
  }

  function changeService(nextId: string) {
    setServiceId(nextId)
    const next = services.data?.find(({ id }) => id === nextId)
    setDuration(next ? String(next.defaultDurationMinutes) : '')
  }

  function changeTime() {
    setDecision(null)
    window.setTimeout(() => timeInput.current?.focus(), 0)
  }

  const error = decision ? null : mutation.error

  return <main className="app-backdrop min-h-screen px-4 pb-10 pt-[calc(env(safe-area-inset-top)+18px)] sm:px-8">
    <div className="mx-auto w-full max-w-3xl">
      <header className="relative flex min-h-14 items-center justify-center">
        <Link aria-label="Закрыть" className="absolute left-0 grid size-11 place-items-center text-primary" to="/calendar"><X className="size-7" /></Link>
        <h1 className="text-2xl font-semibold">Новое занятие</h1>
      </header>

      <div className="mt-6 grid grid-cols-2 overflow-hidden rounded-2xl border border-border bg-white/75 p-2">
        <span className="flex h-14 items-center justify-center gap-2 rounded-xl bg-primary-soft font-medium text-primary"><CalendarDays className="size-5" />Разовое</span>
        <Link className="flex h-14 items-center justify-center gap-2 text-muted" to="/regular-schedules/new"><RefreshCcw className="size-5" />Регулярное</Link>
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

        <label className={`${cardClass} block`}><span className="mb-2 flex items-center gap-3 text-sm text-muted"><CalendarDays className="size-5 text-primary" />Дата</span><input aria-label="Дата" className={`${resourceFieldClass} border-0 bg-transparent px-0 text-lg font-semibold focus:ring-0`} required type="date" value={date} onChange={(event) => setDate(event.target.value)} /></label>

        <div className="grid grid-cols-2 gap-3">
          <label className={cardClass}><span className="mb-2 flex items-center gap-2 text-sm text-muted"><Clock3 className="size-5 text-primary" />Начало</span><input ref={timeInput} aria-label="Начало" className={`${resourceFieldClass} border-0 bg-transparent px-0 text-lg font-semibold focus:ring-0`} required type="time" value={startTime} onChange={(event) => setStartTime(event.target.value)} /></label>
          <label className={cardClass}><span className="mb-2 flex items-center gap-2 text-sm text-muted"><Hourglass className="size-5 text-primary" />Продолжительность</span><input aria-label="Продолжительность" className={`${resourceFieldClass} border-0 bg-transparent px-0 text-lg font-semibold focus:ring-0`} max={selectedService?.maximumDurationMinutes ?? 1440} min={selectedService?.minimumDurationMinutes ?? 1} required type="number" value={durationValue} onChange={(event) => setDuration(event.target.value)} /><span className="mt-2 block text-xs text-muted">{selectedService ? `Из услуги · допустимо ${selectedService.minimumDurationMinutes ?? 1}–${selectedService.maximumDurationMinutes ?? 1440} минут` : 'Выберите услугу'}</span></label>
        </div>

        {loading ? <p role="status" className="text-muted">Загружаем данные...</p> : null}
        {!loading && !ready ? <p role="alert" className="text-danger">Для занятия нужны специалист, клиент и услуга.</p> : null}
        {created ? <div role="status" className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-success/30 bg-white/75 p-4 text-success"><span>Занятие «{created.service.name}» создано.</span><Link className="font-medium underline" to={`/appointments/${created.id}`}>Открыть занятие</Link></div> : null}
        <ResourceFeedback error={error} />
        <Button className="w-full" disabled={loading || !ready || mutation.isPending} type="submit">{mutation.isPending ? 'Создаём...' : 'Создать занятие'}</Button>
        <Button asChild className="w-full" variant="outline"><Link to="/calendar">Отмена</Link></Button>
      </form>
    </div>

    {decision ? <DecisionDialog error={decision} pending={mutation.isPending} close={() => setDecision(null)} changeTime={changeTime} continueCreation={(warnings) => mutation.mutate(warnings)} /> : null}
  </main>
}
