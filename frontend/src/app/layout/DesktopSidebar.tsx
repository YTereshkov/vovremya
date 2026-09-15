import { CalendarDays, LogOut } from 'lucide-react'
import { Link, NavLink } from 'react-router-dom'

import { desktopNavigation, getNavigationBadge } from '@/app/layout/navigation'
import { useAuth } from '@/features/auth/AuthProvider'
import { useNotificationCenter } from '@/features/communications/api'
import { cn } from '@/shared/lib/cn'
import { useConnectivity } from '@/shared/lib/connectivity'

export function DesktopSidebar() {
  const { logout, user } = useAuth()
  const connected = useConnectivity()
  const attention = useNotificationCenter(connected)

  return (
    <aside className="hidden min-h-screen border-r border-border bg-surface px-3.5 py-8 lg:flex lg:flex-col">
      <Link className="block px-3 text-[29px] font-bold tracking-[-0.035em] text-ink transition-colors hover:text-accent focus-visible:rounded-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent" title="На главную" to="/">Vovremya</Link>

      <nav aria-label="Основная навигация" className="mt-8 flex flex-col gap-1.5">
        {desktopNavigation.map((item) => {
          const { icon: Icon, label, to } = item
          const visibleBadge = getNavigationBadge(item, attention.data?.unreadCount)
          const unavailable = !connected && to !== '/' && to !== '/calendar'
          return (
          <NavLink
            aria-disabled={unavailable}
            className={({ isActive }) =>
              cn(
                'flex min-h-14 items-center gap-3 rounded-xl px-3 text-[16px] font-medium text-ink transition-colors',
                'hover:bg-accent-soft/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
                isActive ? 'bg-accent-soft text-accent' : null,
                unavailable ? 'cursor-not-allowed opacity-50' : null,
              )
            }
            end={to === '/'}
            key={to}
            onClick={(event) => { if (unavailable) event.preventDefault() }}
            tabIndex={unavailable ? -1 : undefined}
            to={to}
          >
            <Icon aria-hidden="true" className="size-6 shrink-0" strokeWidth={1.8} />
            <span>{label}</span>
            {visibleBadge !== undefined ? (
              <span aria-hidden={import.meta.env.DEV} className="ml-auto flex h-7 min-w-7 shrink-0 items-center justify-center rounded-full bg-primary px-2 text-sm font-semibold text-white" title={import.meta.env.DEV ? 'Тестовое значение' : to === '/notifications' ? `Непрочитанных уведомлений: ${visibleBadge}` : undefined}>
                {visibleBadge}
              </span>
            ) : null}
          </NavLink>
        )})}
      </nav>

      <NavLink
        aria-disabled={!connected}
        className={cn('mt-auto flex min-h-14 items-center gap-4 rounded-xl border border-border bg-surface-raised px-3.5 text-[16px] font-medium text-ink transition-colors hover:border-primary/50 hover:text-primary', !connected ? 'cursor-not-allowed opacity-50' : null)}
        onClick={(event) => { if (!connected) event.preventDefault() }}
        tabIndex={connected ? undefined : -1}
        to="/my-schedule"
      >
        <CalendarDays aria-hidden="true" className="size-6" strokeWidth={1.8} />
        Моё расписание
      </NavLink>
      <div className="mt-5 border-t border-border pt-4">
        <div className="truncate px-3 text-sm text-muted">{user?.email ?? 'Гость'}</div>
        <button
          className="mt-2 flex min-h-11 w-full items-center rounded-xl px-3 text-left text-sm font-medium text-muted transition-colors hover:bg-primary-soft hover:text-primary"
          disabled={!connected}
          onClick={() => void logout()}
          type="button"
        >
          <LogOut aria-hidden="true" className="size-5" />
          Выйти
        </button>
      </div>
    </aside>
  )
}
