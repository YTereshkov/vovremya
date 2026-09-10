import { CalendarDays, LogOut } from 'lucide-react'
import { NavLink } from 'react-router-dom'

import { desktopNavigation } from '@/app/layout/navigation'
import { useAuth } from '@/features/auth/AuthProvider'
import { useConfirmationAttention } from '@/features/communications/api'
import { cn } from '@/shared/lib/cn'

export function DesktopSidebar() {
  const { logout, user } = useAuth()
  const attention = useConfirmationAttention()

  return (
    <aside className="hidden min-h-screen border-r border-border bg-white/55 px-3.5 py-8 backdrop-blur-xl lg:flex lg:flex-col">
      <div className="px-3 text-[29px] font-semibold tracking-[-0.035em] text-primary">Vovremya</div>

      <nav aria-label="Основная навигация" className="mt-8 flex flex-col gap-1.5">
        {desktopNavigation.map(({ badge, icon: Icon, label, to }) => {
          const visibleBadge = to === '/notifications' ? attention.data?.length : badge
          return (
          <NavLink
            className={({ isActive }) =>
              cn(
                'flex min-h-14 items-center gap-4 rounded-xl px-3.5 text-[16px] font-medium text-ink transition-colors',
                'hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                isActive ? 'bg-primary-soft text-primary' : null,
              )
            }
            end={to === '/'}
            key={to}
            to={to}
          >
            <Icon aria-hidden="true" className="size-6 shrink-0" strokeWidth={1.8} />
            <span>{label}</span>
            {visibleBadge ? (
              <span className="ml-auto flex size-7 items-center justify-center rounded-full bg-primary text-sm font-semibold text-white">
                {visibleBadge}
              </span>
            ) : null}
          </NavLink>
        )})}
      </nav>

      <NavLink
        className="mt-auto flex min-h-14 items-center gap-4 rounded-xl border border-border bg-white/70 px-3.5 text-[16px] font-medium text-ink transition-colors hover:border-primary/40 hover:text-primary"
        to="/my-schedule"
      >
        <CalendarDays aria-hidden="true" className="size-6" strokeWidth={1.8} />
        Моё расписание
      </NavLink>
      <div className="mt-5 border-t border-border pt-4">
        <div className="truncate px-3 text-sm text-muted">{user?.email ?? 'Гость'}</div>
        <button
          className="mt-2 flex min-h-11 w-full items-center rounded-xl px-3 text-left text-sm font-medium text-muted transition-colors hover:bg-primary-soft hover:text-primary"
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
