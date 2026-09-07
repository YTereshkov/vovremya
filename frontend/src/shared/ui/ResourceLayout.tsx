import { ArrowLeft, X } from 'lucide-react'
import { useEffect, useRef, type ReactNode } from 'react'
import { Link } from 'react-router-dom'

export const resourceFieldClass = 'h-11 min-w-0 w-full rounded-lg border border-border bg-white/90 px-3 outline-none focus:border-primary focus:ring-2 focus:ring-primary/20'
export const resourceSurfaceClass = 'rounded-lg border border-border bg-white/75 p-5 shadow-surface'

export function ResourceFrame({ title, back, action, children }: { title: string; back?: string; action?: ReactNode; children: ReactNode }) {
  return <section className="mx-auto min-h-screen w-full max-w-5xl px-4 pb-28 pt-[calc(env(safe-area-inset-top)+24px)] sm:px-8 lg:py-8">
    <header className="mb-7 flex min-h-11 flex-wrap items-center gap-3">
      {back ? <Link to={back} aria-label="Назад" title="Назад" className="grid size-10 place-items-center text-muted"><ArrowLeft className="size-6" /></Link> : null}
      <h1 className="min-w-0 flex-1 text-2xl font-semibold sm:text-3xl">{title}</h1>
      {action}
    </header>
    {children}
  </section>
}

export function ResourceAvatar({ name }: { name: string }) {
  const initials = name.split(/\s+/).filter(Boolean).slice(0, 2).map((word) => word[0]).join('')
  return <span aria-hidden="true" className="grid size-14 shrink-0 place-items-center rounded-full bg-primary-soft text-xl font-medium text-primary">{initials}</span>
}

export function ResourceFeedback({ error }: { error: unknown }) {
  return error ? <p role="alert" className="my-4 text-sm text-danger">{error instanceof Error ? error.message : String(error)}</p> : null
}

export function ResourceModal({ title, close, children }: { title: string; close: () => void; children: ReactNode }) {
  const ref = useRef<HTMLDialogElement>(null)
  useEffect(() => {
    const dialog = ref.current
    dialog?.showModal()
    return () => dialog?.close()
  }, [])

  return <dialog ref={ref} onCancel={close} aria-label={title} className="fixed inset-0 m-auto max-h-[90dvh] w-[calc(100%-32px)] max-w-lg overflow-auto rounded-lg border border-border bg-[#f8faf8] p-5 text-ink shadow-xl backdrop:bg-ink/30">
    <header className="mb-6 flex items-center justify-between gap-3">
      <h2 className="text-xl font-semibold">{title}</h2>
      <button type="button" aria-label="Закрыть" title="Закрыть" onClick={close}><X /></button>
    </header>
    {children}
  </dialog>
}
