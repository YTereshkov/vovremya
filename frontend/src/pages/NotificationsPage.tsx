import { Bell, CalendarClock, ChevronRight, CircleAlert, Gift, MoveRight, UserRoundCheck } from 'lucide-react'
import { Link } from 'react-router-dom'

import { useMarkAllNotificationsRead, useNotificationCenter, type NotificationCenterItem } from '@/features/communications/api'
import { useAuth } from '@/features/auth/AuthProvider'
import { formatNumericDateTime } from '@/shared/lib/date'
import { ResourceFeedback, resourceSurfaceClass } from '@/shared/ui/ResourceLayout'

const icons = {
  'confirmation-no-response': CircleAlert,
  'transfer-request': MoveRight,
  'transfer-declined': MoveRight,
  'free-window': CalendarClock,
  'permanent-place': Gift,
  'specialist-absence': UserRoundCheck,
  'delivery-failed': CircleAlert,
} satisfies Record<NotificationCenterItem['type'], typeof Bell>

export function NotificationsPage() {
  const query = useNotificationCenter()
  const markAll = useMarkAllNotificationsRead()
  const { user } = useAuth()
  const timezone = user?.organization.timezone ?? 'UTC'
  const today = new Intl.DateTimeFormat('en-CA', { timeZone: timezone }).format(new Date())
  const grouped = query.data?.items.reduce<{ today: NotificationCenterItem[]; earlier: NotificationCenterItem[] }>((result, item) => {
    const itemDate = new Intl.DateTimeFormat('en-CA', { timeZone: timezone }).format(new Date(item.createdAt))
    result[itemDate === today ? 'today' : 'earlier'].push(item)
    return result
  }, { today: [], earlier: [] })

  return <section className="mx-auto min-h-screen w-full max-w-[1000px] px-4 pb-28 pt-[calc(env(safe-area-inset-top)+24px)] sm:px-8 lg:py-8">
    <header className="flex items-center justify-between gap-4">
      <h1 className="text-2xl font-semibold sm:text-3xl">Уведомления</h1>
      {query.data?.unreadCount ? <button className="text-sm font-medium text-primary disabled:opacity-60" disabled={markAll.isPending} onClick={() => markAll.mutate()} type="button">Прочитать всё</button> : null}
    </header>
    <ResourceFeedback error={query.error ?? markAll.error} />
    {query.isPending ? <p className="py-12 text-center text-muted" role="status">Загружаем уведомления...</p> : null}
    {grouped ? <div className="mt-6 space-y-7">
      <NotificationGroup items={grouped.today} label="Сегодня" timezone={timezone} />
      <NotificationGroup items={grouped.earlier} label="Ранее" timezone={timezone} />
    </div> : null}
    {!query.isPending && !query.error && !query.data?.items.length ? <div className="mt-6 grid min-h-52 place-items-center rounded-lg border border-border bg-surface p-8 text-center text-muted"><div><Bell className="mx-auto size-8" /><p className="mt-3">Новых уведомлений нет</p></div></div> : null}
  </section>
}

function NotificationGroup({ items, label, timezone }: { items: NotificationCenterItem[]; label: string; timezone: string }) {
  if (!items.length) return null

  return <section><h2 className="mb-3 text-sm font-medium uppercase tracking-wide text-muted">{label}</h2><div className="space-y-3">{items.map((item) => {
    const Icon = icons[item.type]
    const startsAt = item.metadata.startsAt
    return <Link className={`${resourceSurfaceClass} flex items-center gap-4 hover:border-primary/40`} key={`${item.type}-${item.id}`} to={item.href}>
      <span className={`grid size-11 shrink-0 place-items-center rounded-full ${item.severity === 'danger' ? 'bg-danger/10 text-danger' : item.severity === 'success' ? 'bg-success/10 text-success' : 'bg-primary-soft text-primary'}`}><Icon className="size-5" /></span>
      <span className="min-w-0 flex-1"><span className="flex items-center gap-2"><strong className="truncate">{item.title}</strong>{item.unread ? <span aria-label="Не прочитано" className="size-2 shrink-0 rounded-full bg-primary" /> : null}</span><span className="mt-1 block text-sm text-muted">{item.subtitle}{startsAt ? ` · ${formatNumericDateTime(startsAt, timezone)}` : ''}</span></span>
      <ChevronRight className="size-5 shrink-0 text-muted" />
    </Link>
  })}</div></section>
}
