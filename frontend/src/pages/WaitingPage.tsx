import { ArrowRight, CalendarClock } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useState, type ReactNode } from 'react'

import { useAuth } from '@/features/auth/AuthProvider'
import { longDate } from '@/features/calendar/date'
import { useFreeWindowCandidates, useFreeWindows, type FreeWindow } from '@/features/waiting/api'
import { Button } from '@/shared/ui/Button'
import { ResourceFeedback, resourceSurfaceClass } from '@/shared/ui/ResourceLayout'

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
  const candidates = useFreeWindowCandidates(window.id, expanded)
  const { user } = useAuth()
  const timezone = user?.organization.timezone ?? 'UTC'
  return <article className="grid gap-4 lg:grid-cols-[minmax(280px,0.8fr)_minmax(360px,1.2fr)]">
    <div className={`${resourceSurfaceClass} border border-dashed border-primary/50`}><p className="text-sm capitalize text-muted">{longDate(window.date)}</p><p className="mt-2 text-2xl font-semibold tabular-nums">{window.startTime}–{window.endTime}</p><p className="mt-3 font-medium">{window.service.name}</p><p className="mt-1 text-sm text-muted">{window.durationMinutes} минут</p><Link className="mt-5 inline-flex text-sm font-semibold text-primary" to={`/appointments/${window.sourceAppointmentId}`}>Исходное занятие</Link></div>
    <div className="space-y-4">
      <Button aria-expanded={expanded} className="w-full" onClick={() => setExpanded((current) => !current)} type="button" variant="outline">{expanded ? 'Скрыть подходящих клиентов' : 'Показать подходящих клиентов'}</Button>
      {expanded ? <>
        {candidates.isPending ? <p className="px-2 text-sm text-muted">Ищем подходящих клиентов...</p> : null}
        {candidates.data?.moveEarlier.length ? <CandidateGroup title="Можно перенести раньше">{candidates.data.moveEarlier.map((candidate) => <Link className={`${resourceSurfaceClass} flex items-center gap-3`} key={candidate.appointmentId} to={`/appointments/${candidate.appointmentId}`}><div className="min-w-0 flex-1"><p className="font-semibold">{candidate.client.name}</p><p className="mt-1 text-sm text-muted">сейчас {time(candidate.currentStartsAt, timezone)} <ArrowRight className="mx-1 inline size-4" /> можно {time(candidate.proposedStartsAt, timezone)}</p></div></Link>)}</CandidateGroup> : null}
        {candidates.data?.waitingClients.length ? <CandidateGroup title="Другие подходящие клиенты">{candidates.data.waitingClients.map((candidate) => <Link className={`${resourceSurfaceClass} block`} key={candidate.waitingListEntryId} to={`/clients/${candidate.client.id}`}><p className="font-semibold">{candidate.client.name}</p><p className="mt-1 text-sm text-muted">{candidate.availability}</p></Link>)}</CandidateGroup> : null}
        {candidates.data && candidates.data.moveEarlier.length === 0 && candidates.data.waitingClients.length === 0 ? <div className={`${resourceSurfaceClass} text-sm text-muted`}>Подходящих клиентов сейчас нет</div> : null}
        <ResourceFeedback error={candidates.error} />
      </> : null}
    </div>
  </article>
}

function CandidateGroup({ title, children }: { title: string; children: ReactNode }) {
  return <section><h2 className="mb-3 text-xl font-semibold">{title}</h2><div className="space-y-3">{children}</div></section>
}

function time(value: string, timezone: string): string {
  return new Intl.DateTimeFormat('ru-RU', { hour: '2-digit', minute: '2-digit', timeZone: timezone }).format(new Date(value))
}
