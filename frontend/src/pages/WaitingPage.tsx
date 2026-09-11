import { ArrowRight, CalendarClock, Info, LockKeyhole } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useState, type ReactNode } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'

import { useAuth } from '@/features/auth/AuthProvider'
import { longDate } from '@/features/calendar/date'
import { cancelFreeWindowOffer, createFreeWindowOffer, useFreeWindowCandidates, useFreeWindows, type FreeWindow, type FreeWindowOffer } from '@/features/waiting/api'
import { Button } from '@/shared/ui/Button'
import { ResourceFeedback, ResourceModal, resourceSurfaceClass } from '@/shared/ui/ResourceLayout'

export function WaitingPage() {
  const windows = useFreeWindows()
  return <section className="mx-auto min-h-screen w-full max-w-[1306px] px-5 pb-28 pt-[calc(env(safe-area-inset-top)+36px)] sm:px-8 lg:px-7 lg:py-8">
    <h1 className="text-[32px] font-semibold leading-tight lg:text-[36px]">Ожидание</h1>
    <div className="mt-6 flex gap-5 border-b border-border text-sm font-medium"><button className="border-b-2 border-primary px-1 pb-3 text-primary" type="button">Разовые окна</button><button className="px-1 pb-3 text-muted" disabled type="button">Постоянные места</button></div>
    <ResourceFeedback error={windows.error} />
    {windows.isPending ? <p className="py-10 text-muted" role="status">Загружаем свободные окна...</p> : null}
    {windows.data?.length ? <div className="mt-5 space-y-5">{windows.data.map((window) => <WindowWithCandidates key={window.id} window={window} />)}</div> : null}
    {windows.data && windows.data.length === 0 ? <div className={`${resourceSurfaceClass} mt-5 grid min-h-52 place-items-center text-center text-muted`}><div><CalendarClock className="mx-auto size-8" /><p className="mt-3">Свободных окон сейчас нет</p></div></div> : null}
  </section>
}

function WindowWithCandidates({ window }: { window: FreeWindow }) {
  const [expanded, setExpanded] = useState(false)
  const [cancelling, setCancelling] = useState(false)
  const candidates = useFreeWindowCandidates(window.id, expanded && !window.activeOffer)
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const timezone = user?.organization.timezone ?? 'UTC'
  const createOffer = useMutation({
    mutationFn: ({ targetType, candidateId }: { targetType: FreeWindowOffer['targetType']; candidateId: string }) => createFreeWindowOffer(window.id, targetType, candidateId),
    onSuccess: async () => {
      setExpanded(false)
      await queryClient.invalidateQueries({ queryKey: ['free-windows'] })
    },
  })
  const cancelOffer = useMutation({
    mutationFn: () => cancelFreeWindowOffer(window.activeOffer?.id ?? ''),
    onSuccess: async () => {
      setCancelling(false)
      await queryClient.invalidateQueries({ queryKey: ['free-windows'] })
    },
  })
  return <article className="grid gap-4 lg:grid-cols-[minmax(280px,0.8fr)_minmax(360px,1.2fr)]">
    <div className={`${resourceSurfaceClass} border border-dashed border-primary/50`}><p className="text-sm capitalize text-muted">{longDate(window.date)}</p><p className="mt-2 text-2xl font-semibold tabular-nums">{window.startTime}–{window.endTime}</p><p className="mt-3 font-medium">{window.service.name}</p><p className="mt-1 text-sm text-muted">{window.durationMinutes} минут</p>{window.activeOffer ? <><p className="mt-4 font-semibold">Предложено {window.activeOffer.client.name}</p><p className="mt-2 inline-flex items-center gap-2 rounded-lg bg-primary-soft px-3 py-2 text-sm text-primary"><LockKeyhole className="size-4" />Временно зарезервировано</p></> : null}<Link className="mt-5 block text-sm font-semibold text-primary" to={`/appointments/${window.sourceAppointmentId}`}>Исходное занятие</Link></div>
    <div className="space-y-4">
      {window.activeOffer ? <ActiveOffer offer={window.activeOffer} cancel={() => setCancelling(true)} timezone={timezone} /> : <Button aria-expanded={expanded} className="w-full" onClick={() => setExpanded((current) => !current)} type="button" variant="outline">{expanded ? 'Скрыть подходящих клиентов' : 'Показать подходящих клиентов'}</Button>}
      {expanded && !window.activeOffer ? <>
        {candidates.isPending ? <p className="px-2 text-sm text-muted">Ищем подходящих клиентов...</p> : null}
        {candidates.data?.moveEarlier.length ? <CandidateGroup title="Можно перенести раньше">{candidates.data.moveEarlier.map((candidate) => <div className={`${resourceSurfaceClass} flex flex-wrap items-center gap-3`} key={candidate.appointmentId}><Link className="min-w-0 flex-1" to={`/appointments/${candidate.appointmentId}`}><p className="font-semibold">{candidate.client.name}</p><p className="mt-1 text-sm text-muted">сейчас {time(candidate.currentStartsAt, timezone)} <ArrowRight className="mx-1 inline size-4" /> можно {time(candidate.proposedStartsAt, timezone)}</p></Link><Button disabled={createOffer.isPending} onClick={() => createOffer.mutate({ targetType: 'MOVE_EARLIER', candidateId: candidate.appointmentId })} size="compact" type="button" variant="outline">Предложить</Button></div>)}</CandidateGroup> : null}
        {candidates.data?.waitingClients.length ? <CandidateGroup title="Другие подходящие клиенты">{candidates.data.waitingClients.map((candidate) => <div className={`${resourceSurfaceClass} flex flex-wrap items-center gap-3`} key={candidate.waitingListEntryId}><Link className="min-w-0 flex-1" to={`/clients/${candidate.client.id}`}><p className="font-semibold">{candidate.client.name}</p><p className="mt-1 text-sm text-muted">{candidate.availability}</p></Link><Button disabled={createOffer.isPending} onClick={() => createOffer.mutate({ targetType: 'WAITING_LIST', candidateId: candidate.waitingListEntryId })} size="compact" type="button" variant="outline">Предложить</Button></div>)}</CandidateGroup> : null}
        {candidates.data && candidates.data.moveEarlier.length === 0 && candidates.data.waitingClients.length === 0 ? <div className={`${resourceSurfaceClass} text-sm text-muted`}>Подходящих клиентов сейчас нет</div> : null}
        <ResourceFeedback error={candidates.error ?? createOffer.error} />
      </> : null}
    </div>
    {cancelling && window.activeOffer ? <ResourceModal close={() => !cancelOffer.isPending && setCancelling(false)} title={`Отменить предложение ${window.activeOffer.client.name}?`}><p className="text-muted">Временный резерв будет снят. Окно снова станет доступно, клиент получит уведомление.</p><ResourceFeedback error={cancelOffer.error} /><div className="mt-6 flex gap-3"><Button className="flex-1" disabled={cancelOffer.isPending} onClick={() => setCancelling(false)} type="button" variant="outline">Назад</Button><Button className="flex-1 border-danger text-danger" disabled={cancelOffer.isPending} onClick={() => cancelOffer.mutate()} type="button" variant="outline">Отменить предложение</Button></div></ResourceModal> : null}
  </article>
}

function ActiveOffer({ offer, cancel, timezone }: { offer: FreeWindowOffer; cancel: () => void; timezone: string }) {
  return <section><h2 className="mb-3 text-xl font-semibold">Активное предложение</h2><div className={resourceSurfaceClass}><p className="font-semibold">{offer.client.name}</p><p className="mt-1 text-sm text-muted">Отправлено {new Intl.DateTimeFormat('ru-RU', { dateStyle: 'medium', timeStyle: 'short', timeZone: timezone }).format(new Date(offer.createdAt))}</p><Button className="mt-5 w-full border-danger text-danger" onClick={cancel} type="button" variant="outline">Отменить предложение</Button></div><p className="mt-4 flex items-start gap-2 rounded-lg border border-border p-4 text-sm text-muted"><Info className="mt-0.5 size-4 shrink-0 text-primary" />Пока предложение активно, окно недоступно другим клиентам.</p></section>
}

function CandidateGroup({ title, children }: { title: string; children: ReactNode }) {
  return <section><h2 className="mb-3 text-xl font-semibold">{title}</h2><div className="space-y-3">{children}</div></section>
}

function time(value: string, timezone: string): string {
  return new Intl.DateTimeFormat('ru-RU', { hour: '2-digit', minute: '2-digit', timeZone: timezone }).format(new Date(value))
}
