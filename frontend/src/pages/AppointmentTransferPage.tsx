import { useEffect, useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { CircleHelp, Plus, Trash2, X } from 'lucide-react'
import { Link, useNavigate, useParams } from 'react-router-dom'

import type { AppointmentErrorPayload, AppointmentWarningCode } from '@/features/appointments/api'
import { useAppointment } from '@/features/calendar/api'
import { useClient } from '@/features/clients/api'
import { cancelTransfer, offerTransferOptions, useTransferState, type TransferOptionInput, type TransferState } from '@/features/transfers/api'
import { ApiError } from '@/shared/api/request'
import { formatNumericDate, formatNumericDateTime } from '@/shared/lib/date'
import { Button } from '@/shared/ui/Button'
import { ResourceFeedback, resourceFieldClass } from '@/shared/ui/ResourceLayout'

const cardClass = 'rounded-2xl border border-border bg-surface p-4 shadow-surface'

function nextDate(date: string, days: number): string {
  const value = new Date(`${date}T12:00:00Z`)
  value.setUTCDate(value.getUTCDate() + days)
  return value.toISOString().slice(0, 10)
}

export function AppointmentTransferPage() {
  const { id = '' } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const appointment = useAppointment(id)
  const transfer = useTransferState(id)
  const client = useClient(appointment.data?.client.id ?? '')
  const [options, setOptions] = useState<TransferOptionInput[]>([])
  const [decision, setDecision] = useState<AppointmentErrorPayload | null>(null)

  useEffect(() => {
    if (appointment.data && options.length === 0) {
      setOptions([
        { date: nextDate(appointment.data.date, 1), startTime: appointment.data.startTime },
        { date: nextDate(appointment.data.date, 2), startTime: appointment.data.startTime },
      ])
    }
  }, [appointment.data, options.length])

  const offer = useMutation<TransferState, ApiError<AppointmentErrorPayload>, AppointmentWarningCode[]>({
    mutationFn: (acceptedWarnings) => offerTransferOptions(id, options, acceptedWarnings),
    onSuccess: async () => {
      setDecision(null)
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['transfers'] }),
        queryClient.invalidateQueries({ queryKey: ['calendar'] }),
        queryClient.invalidateQueries({ queryKey: ['communications'] }),
      ])
    },
    onError: (error) => {
      if (error.payload.kind === 'SOFT_WARNING' || error.payload.kind === 'HARD_CONFLICT') setDecision(error.payload)
    },
  })
  const cancel = useMutation({
    mutationFn: (requestId: string) => cancelTransfer(requestId),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['transfers'] })
      navigate(`/appointments/${id}`)
    },
  })

  function submit(event: FormEvent) {
    event.preventDefault()
    setDecision(null)
    offer.mutate([])
  }

  function update(index: number, field: keyof TransferOptionInput, value: string) {
    setOptions((current) => current.map((option, optionIndex) => optionIndex === index ? { ...option, [field]: value } : option))
  }

  const active = transfer.data?.request
  const primary = client.data?.channels.find(({ id: channelId }) => channelId === client.data?.primaryChannelId)

  return <main className="app-backdrop min-h-screen px-4 pb-10 pt-[calc(env(safe-area-inset-top)+18px)] sm:px-8">
    <div className="mx-auto w-full max-w-3xl">
      <header className="relative flex min-h-14 items-center justify-center">
        <Link aria-label="Закрыть" className="absolute left-0 grid size-11 place-items-center text-primary" to={`/appointments/${id}`}><X className="size-7" /></Link>
        <h1 className="text-2xl font-semibold">Запрос переноса</h1>
      </header>

      {appointment.data ? <section className={`${cardClass} mt-6`}>
        <p className="text-xl font-semibold">{appointment.data.client.name}</p>
        <p className="mt-1 text-muted">{appointment.data.service.name}</p>
        <p className="mt-3 font-medium">{formatNumericDate(appointment.data.date)} · {appointment.data.startTime}–{appointment.data.endTime}</p>
        <p className="mt-2 text-sm text-muted">Существующее время сохраняется до выбора нового.</p>
      </section> : null}
      <ResourceFeedback error={appointment.error ?? transfer.error} />

      {active?.status === 'OPTIONS_SENT' ? <section className={`${cardClass} mt-5`}>
        <h2 className="text-lg font-semibold">Варианты отправлены</h2>
        <div className="mt-4 space-y-3">{transfer.data?.options.map((option) => <div className="rounded-xl border border-border p-3" key={option.id}>{formatNumericDateTime(option.startsAt, 'Europe/Moscow')}</div>)}</div>
        <p className="mt-4 text-sm text-muted">Автоматического срока ожидания нет. Предложенные варианты не удерживают время.</p>
        <Button className="mt-5 w-full" disabled={cancel.isPending} onClick={() => cancel.mutate(active.id)} variant="outline">Отменить предложение</Button>
        <ResourceFeedback error={cancel.error} />
      </section> : <form className="mt-5 space-y-4" onSubmit={submit}>
        {active?.status === 'AWAITING_OPTIONS' ? <div className="rounded-xl border border-warning/40 bg-warning/5 p-4 text-sm">Клиент запросил перенос. Предложите подходящие варианты.</div> : null}
        <section className={cardClass}>
          <h2 className="text-lg font-semibold">Свободные подходящие окна</h2>
          <div className="mt-4 space-y-3">{options.map((option, index) => <div className="grid grid-cols-[1fr_120px_auto] gap-2" key={index}>
            <label><span className="sr-only">Дата варианта {index + 1}</span><input aria-label={`Дата варианта ${index + 1}`} className={resourceFieldClass} required type="date" value={option.date} onChange={(event) => update(index, 'date', event.target.value)} /></label>
            <label><span className="sr-only">Время варианта {index + 1}</span><input aria-label={`Время варианта ${index + 1}`} className={resourceFieldClass} required type="time" value={option.startTime} onChange={(event) => update(index, 'startTime', event.target.value)} /></label>
            <button aria-label={`Удалить вариант ${index + 1}`} className="grid size-11 place-items-center text-danger disabled:opacity-40" disabled={options.length === 1} onClick={() => setOptions((current) => current.filter((_, optionIndex) => optionIndex !== index))} type="button"><Trash2 className="size-5" /></button>
          </div>)}</div>
          {options.length < 10 ? <Button className="mt-4" onClick={() => setOptions((current) => [...current, { date: current.at(-1)?.date ?? '', startTime: current.at(-1)?.startTime ?? '09:00' }])} size="compact" type="button" variant="ghost"><Plus className="size-4" />Добавить вариант</Button> : null}
        </section>

        <div className="flex gap-3 rounded-2xl bg-primary-soft p-4 text-sm"><CircleHelp className="size-6 shrink-0 text-primary" /><p>Предложенные варианты не занимают время в расписании. Перед переносом система повторно проверит выбранное время.</p></div>
        {primary ? <section className={cardClass}><p className="text-sm text-muted">Канал уведомления</p><p className="mt-2 font-semibold">{primary.provider}</p><p className="text-sm text-muted">Получатель: {primary.recipientName}</p></section> : null}
        {decision ? <section role="alert" className={`${cardClass} border-warning/40`}><p className="font-semibold">{decision.kind === 'HARD_CONFLICT' ? 'Время недоступно' : 'Проверьте выбранное время'}</p><p className="mt-2 text-sm text-muted">{decision.conflict?.message ?? decision.warnings?.map(({ message }) => message).join(' ') ?? decision.message}</p>{decision.kind === 'SOFT_WARNING' ? <Button className="mt-4 w-full" disabled={offer.isPending} onClick={() => offer.mutate((decision.warnings ?? []).map(({ code }) => code))} type="button" variant="outline">Всё равно предложить</Button> : null}</section> : null}
        <ResourceFeedback error={decision ? null : offer.error} />
        <Button className="w-full" disabled={offer.isPending || options.length === 0} type="submit">{offer.isPending ? 'Отправляем...' : 'Предложить варианты'}</Button>
        <Button asChild className="w-full" variant="outline"><Link to={`/appointments/${id}`}>Отмена</Link></Button>
      </form>}
    </div>
  </main>
}
