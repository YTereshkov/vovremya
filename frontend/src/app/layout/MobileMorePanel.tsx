import { ChevronRight, X } from 'lucide-react'
import { useEffect, useRef } from 'react'
import { Link } from 'react-router-dom'

import { mobileMoreNavigation } from '@/app/layout/navigation'
import { cn } from '@/shared/lib/cn'
import { useConnectivity } from '@/shared/lib/connectivity'

interface MobileMorePanelProps {
  open: boolean
  onClose: () => void
}

export function MobileMorePanel({ onClose, open }: MobileMorePanelProps) {
  const connected = useConnectivity()
  const closeButtonRef = useRef<HTMLButtonElement>(null)

  useEffect(() => {
    if (!open) return

    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    closeButtonRef.current?.focus()

    const onKeyDown = (event: KeyboardEvent) => { if (event.key === 'Escape') onClose() }
    document.addEventListener('keydown', onKeyDown)

    return () => {
      document.body.style.overflow = previousOverflow
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [onClose, open])

  return (
    <aside
      aria-hidden={!open}
      aria-labelledby="mobile-more-heading"
      className={cn(
        'fixed inset-0 z-30 overflow-y-auto overscroll-contain bg-surface-raised px-5 pb-28 pt-[calc(env(safe-area-inset-top)+24px)] shadow-2xl transition-transform duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] sm:px-8 lg:hidden',
        open ? 'translate-x-0' : 'pointer-events-none translate-x-full',
      )}
      id="mobile-more-panel"
      inert={!open}
      role="dialog"
    >
      <div className="mx-auto w-full max-w-[900px]">
        <header className="flex items-center justify-between border-b border-border pb-5">
          <h2 className="text-[32px] font-semibold leading-tight" id="mobile-more-heading">Ещё</h2>
          <button aria-label="Закрыть меню" className="grid size-11 place-items-center rounded-xl text-ink hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent" onClick={onClose} ref={closeButtonRef} type="button">
            <X aria-hidden="true" className="size-6" />
          </button>
        </header>
        <nav aria-label="Остальные разделы" className="mt-5 divide-y divide-border">
          {mobileMoreNavigation.map(({ icon: Icon, label, to }) => (
            <Link
              aria-disabled={!connected}
              className={cn('flex min-h-18 items-center gap-4 rounded-lg px-2 text-[17px] font-medium text-ink hover:bg-primary-soft/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent', !connected ? 'cursor-not-allowed opacity-50' : null)}
              key={to}
              onClick={(event) => { if (!connected) event.preventDefault(); else onClose() }}
              tabIndex={connected ? undefined : -1}
              to={to}
            >
              <Icon aria-hidden="true" className="size-6 shrink-0 text-primary" strokeWidth={1.8} />
              <span className="flex-1">{label}</span>
              <ChevronRight aria-hidden="true" className="size-5 shrink-0 text-muted" />
            </Link>
          ))}
        </nav>
      </div>
    </aside>
  )
}
