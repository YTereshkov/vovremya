import { CalendarClock } from 'lucide-react'
import { Link } from 'react-router-dom'

import { longDate } from '@/features/calendar/date'
import { useFreeWindows } from '@/features/waiting/api'
import { ResourceFeedback, resourceSurfaceClass } from '@/shared/ui/ResourceLayout'

export function WaitingPage() {
  const windows = useFreeWindows()
  return <section className="mx-auto min-h-screen w-full max-w-[1306px] px-5 pb-28 pt-[calc(env(safe-area-inset-top)+36px)] sm:px-8 lg:px-7 lg:py-8">
    <h1 className="text-[32px] font-semibold leading-tight lg:text-[36px]">Ожидание</h1>
    <div className="mt-6 flex gap-5 border-b border-border text-sm font-medium"><button className="border-b-2 border-primary px-1 pb-3 text-primary" type="button">Разовые окна</button><button className="px-1 pb-3 text-muted" disabled type="button">Постоянные места</button></div>
    <ResourceFeedback error={windows.error} />
    {windows.isPending ? <p className="py-10 text-muted" role="status">Загружаем свободные окна...</p> : null}
    {windows.data?.length ? <div className="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">{windows.data.map((window) => <article className={resourceSurfaceClass} key={window.id}><p className="text-sm capitalize text-muted">{longDate(window.date)}</p><p className="mt-2 text-2xl font-semibold tabular-nums">{window.startTime}–{window.endTime}</p><p className="mt-3 font-medium">{window.service.name}</p><p className="mt-1 text-sm text-muted">{window.durationMinutes} минут</p><Link className="mt-5 inline-flex text-sm font-semibold text-primary" to={`/appointments/${window.sourceAppointmentId}`}>Исходное занятие</Link></article>)}</div> : null}
    {windows.data && windows.data.length === 0 ? <div className={`${resourceSurfaceClass} mt-5 grid min-h-52 place-items-center text-center text-muted`}><div><CalendarClock className="mx-auto size-8" /><p className="mt-3">Свободных окон сейчас нет</p></div></div> : null}
  </section>
}
