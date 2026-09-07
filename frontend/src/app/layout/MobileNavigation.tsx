import { NavLink, useLocation } from 'react-router-dom'

import { mobileNavigation } from '@/app/layout/navigation'
import { cn } from '@/shared/lib/cn'

const moreRoutes = new Set(['/notifications', '/specialists', '/statistics', '/settings', '/my-schedule'])

export function MobileNavigation() {
  const location = useLocation()

  return (
    <nav
      aria-label="Мобильная навигация"
      className="fixed inset-x-0 bottom-0 z-40 grid grid-cols-5 border-t border-border bg-white/95 px-1 pb-[max(8px,env(safe-area-inset-bottom))] pt-2 backdrop-blur-xl lg:hidden"
    >
      {mobileNavigation.map(({ icon: Icon, label, to }) => {
        const selected = to === '/settings' ? [...moreRoutes].some((path) => location.pathname === path || location.pathname.startsWith(`${path}/`)) : location.pathname === to

        return (
          <NavLink
            aria-current={selected ? 'page' : undefined}
            className={cn(
              'flex min-h-16 min-w-0 flex-col items-center justify-center gap-1 text-[12px] font-medium text-muted transition-colors',
              'focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-primary',
              selected ? 'text-primary' : null,
            )}
            key={to}
            to={to}
          >
            <Icon aria-hidden="true" className="size-7" strokeWidth={1.8} />
            <span className="truncate">{label}</span>
          </NavLink>
        )
      })}
    </nav>
  )
}
