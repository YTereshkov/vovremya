import { Bell, CalendarDays } from 'lucide-react'
import { Link } from 'react-router-dom'

import { useConfirmationAttention } from '@/features/communications/api'
import { useAuth } from '@/features/auth/AuthProvider'
import { ResourceFeedback, resourceSurfaceClass } from '@/shared/ui/ResourceLayout'

export function NotificationsPage() {
  const query = useConfirmationAttention()
  const { user } = useAuth()
  const timezone = user?.organization.timezone ?? 'UTC'

  return <section className="mx-auto min-h-screen w-full max-w-[1000px] px-4 pb-28 pt-[calc(env(safe-area-inset-top)+24px)] sm:px-8 lg:py-8">
    <h1 className="text-2xl font-semibold sm:text-3xl">Уведомления</h1>
    <p className="mt-2 text-sm text-muted">Занятия, по которым требуется внимание</p>
    <ResourceFeedback error={query.error} />
    {query.isPending ? <p className="py-12 text-center text-muted" role="status">Загружаем уведомления...</p> : null}
    {query.data?.length ? <div className="mt-6 space-y-3">{query.data.map((item) => <Link className={`${resourceSurfaceClass} flex items-center gap-4 hover:border-primary/40`} key={item.appointmentId} to={`/appointments/${item.appointmentId}`}>
      <span className="grid size-11 shrink-0 place-items-center rounded-full bg-danger/10 text-danger"><Bell className="size-5" /></span>
      <span className="min-w-0 flex-1"><strong className="block truncate">Нет ответа: {item.clientName}</strong><span className="mt-1 flex items-center gap-1 text-sm text-muted"><CalendarDays className="size-4" />{new Intl.DateTimeFormat('ru-RU', { dateStyle: 'medium', timeStyle: 'short', timeZone: timezone }).format(new Date(item.startsAt))}</span></span>
    </Link>)}</div> : null}
    {!query.isPending && !query.error && !query.data?.length ? <div className="mt-6 grid min-h-52 place-items-center rounded-lg border border-border bg-white/70 p-8 text-center text-muted"><div><Bell className="mx-auto size-8" /><p className="mt-3">Нет уведомлений, требующих внимания</p></div></div> : null}
  </section>
}
