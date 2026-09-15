import { CircleHelp } from 'lucide-react'
import { useId } from 'react'

export function InfoHint({ label, children }: { label: string; children: string }) {
  const id = useId()

  return <span className="group relative inline-flex shrink-0 align-middle">
    <button aria-describedby={id} aria-label={`Подробнее: ${label}`} className="grid size-6 place-items-center rounded-full text-muted transition-colors hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30" type="button">
      <CircleHelp aria-hidden="true" className="size-4" />
    </button>
    <span className="pointer-events-none absolute bottom-full right-0 z-30 mb-2 w-[min(16rem,calc(100vw-2rem))] rounded-lg bg-ink px-3 py-2 text-left text-xs font-normal leading-relaxed text-canvas opacity-0 shadow-lg transition-opacity group-hover:opacity-100 group-focus-within:opacity-100 sm:left-1/2 sm:right-auto sm:-translate-x-1/2" id={id} role="tooltip">
      {children}
    </span>
  </span>
}
