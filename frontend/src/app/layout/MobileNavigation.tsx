import { X } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { NavLink, useLocation } from 'react-router-dom'

import { MobileMorePanel } from '@/app/layout/MobileMorePanel'
import { getNavigationBadge, mobileMoreNavigation, mobileNavigation } from '@/app/layout/navigation'
import { useNotificationCenter } from '@/features/communications/api'
import { cn } from '@/shared/lib/cn'
import { useConnectivity } from '@/shared/lib/connectivity'

export function MobileNavigation() {
  const { pathname } = useLocation()
  const connected = useConnectivity()
  const attention = useNotificationCenter(connected)
  const [moreOpen, setMoreOpen] = useState(false)
  const moreButtonRef = useRef<HTMLButtonElement>(null)

  const closeMore = useCallback(() => {
    setMoreOpen(false)
    moreButtonRef.current?.focus()
  }, [])

  useEffect(() => { setMoreOpen(false) }, [pathname])

  return (
    <>
      <MobileMorePanel onClose={closeMore} open={moreOpen} />
      <nav
        aria-label="Мобильная навигация"
        className="fixed inset-x-0 bottom-0 z-40 grid grid-cols-5 border-t border-border bg-surface-raised px-1 pb-[max(8px,env(safe-area-inset-bottom))] pt-2 max-[450px]:px-0 lg:hidden"
      >
        {mobileNavigation.map((item) => {
          const { icon: Icon, label, to } = item
          const badge = getNavigationBadge(item, attention.data?.unreadCount)
          const unavailable = !connected && to !== '/' && to !== '/calendar' && to !== '/more'
          const selected = to === '/more'
            ? moreOpen || mobileMoreNavigation.some(({ to: moreTo }) => pathname === moreTo || pathname.startsWith(`${moreTo}/`))
            : to === '/'
              ? pathname === '/'
              : pathname === to || pathname.startsWith(`${to}/`)
          const className = cn(
              'flex min-h-16 min-w-0 flex-col items-center justify-center gap-1 text-[12px] font-medium text-muted transition-colors max-[450px]:mx-0.5 max-[450px]:text-[11.5px] max-[450px]:tracking-[-0.035em] max-[385px]:min-h-14 max-[385px]:gap-0',
              'focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-accent',
              selected ? 'text-accent max-[385px]:rounded-xl max-[385px]:bg-accent-soft/70' : null,
              unavailable ? 'cursor-not-allowed opacity-50' : null,
          )

          if (to === '/more') {
            return (
              <button
                aria-controls="mobile-more-panel"
                aria-expanded={moreOpen}
                aria-label={moreOpen ? 'Закрыть меню «Ещё»' : 'Открыть меню «Ещё»'}
                className={className}
                key={to}
                onClick={() => setMoreOpen((open) => !open)}
                ref={moreButtonRef}
                type="button"
              >
                <span className="relative size-7">
                  <Icon aria-hidden="true" className={cn('absolute inset-0 size-7 transition-[opacity,transform] duration-200', moreOpen ? 'rotate-90 opacity-0' : 'rotate-0 opacity-100')} strokeWidth={1.8} />
                  <X aria-hidden="true" className={cn('absolute inset-0 size-7 transition-[opacity,transform] duration-200', moreOpen ? 'rotate-0 opacity-100' : '-rotate-90 opacity-0')} strokeWidth={1.8} />
                </span>
                <span className="truncate max-[385px]:hidden">{label}</span>
              </button>
            )
          }

          return (
            <NavLink
              aria-current={selected ? 'page' : undefined}
              aria-disabled={unavailable}
              aria-label={to === '/notifications' && badge !== undefined && !import.meta.env.DEV ? `${label}, ${badge} непрочитанных` : label}
              className={className}
              key={to}
              onClick={(event) => { if (unavailable) event.preventDefault() }}
              tabIndex={unavailable ? -1 : undefined}
              to={to}
            >
              <span className="relative size-7">
                <Icon aria-hidden="true" className="size-7" strokeWidth={1.8} />
                {badge !== undefined ? <span aria-hidden={import.meta.env.DEV} className="absolute -right-2 -top-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-semibold text-white" title={import.meta.env.DEV ? 'Тестовое значение' : `Непрочитанных уведомлений: ${badge}`}>{badge}</span> : null}
              </span>
              <span className="truncate max-[385px]:hidden">{label}</span>
            </NavLink>
          )
        })}
      </nav>
    </>
  )
}
