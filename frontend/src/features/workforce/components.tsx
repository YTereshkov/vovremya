import { ArrowLeft, X } from 'lucide-react'
import { useEffect, useRef, type ReactNode, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '@/features/auth/AuthProvider'
import { formatNumericDate } from '@/shared/lib/date'
import { Button } from '@/shared/ui/Button'
import type { Interval, ProfileInput, Specialist } from './api'

export const dayNames = ['Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', 'Воскресенье']
export const shortDays = ['ПН', 'ВТ', 'СР', 'ЧТ', 'ПТ', 'СБ', 'ВС']
export const fieldClass = 'h-11 min-w-0 w-full rounded-lg border border-border bg-surface-raised px-3 outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 disabled:bg-surface disabled:text-muted'
export const surfaceClass = 'rounded-2xl border border-border bg-surface p-5 shadow-surface'

export function WorkforceFrame({ title, back, action, children }: { title: string; back?: string; action?: ReactNode; children: ReactNode }) {
  return <section className="mx-auto min-h-screen w-full max-w-5xl px-4 pb-28 pt-[calc(env(safe-area-inset-top)+24px)] sm:px-8 lg:py-8">
    <header className="mb-7 flex flex-wrap items-center gap-3">
      {back ? <Link to={back} aria-label="Назад" className="p-1 text-muted"><ArrowLeft className="size-6" /></Link> : null}
      <h1 className="min-w-0 flex-1 text-2xl font-semibold sm:text-3xl">{title}</h1>{action}
    </header>{children}
  </section>
}

export function Avatar({ name }: { name: string }) {
  return <span aria-hidden="true" className="grid size-14 shrink-0 place-items-center rounded-full bg-primary-soft text-xl font-medium text-primary">{name.split(/\s+/).slice(0, 2).map((word) => word[0]).join('')}</span>
}

export function Feedback({ error }: { error: unknown }) {
  return error ? <p role="alert" className="my-4 text-sm text-danger">{error instanceof Error ? error.message : String(error)}</p> : null
}

export function Modal({ title, close, children }: { title: string; close: () => void; children: ReactNode }) {
  const ref = useRef<HTMLDialogElement>(null)
  useEffect(() => { const dialog = ref.current; dialog?.showModal(); return () => dialog?.close() }, [])
  return <dialog ref={ref} onCancel={close} aria-label={title} className="fixed inset-0 m-auto max-h-[90dvh] w-[calc(100%-32px)] max-w-lg overflow-auto rounded-2xl border border-border bg-surface-raised p-5 text-ink shadow-surface backdrop:bg-ink/40">
    <header className="mb-6 flex items-center justify-between gap-3"><h2 className="text-xl font-semibold">{title}</h2><button type="button" aria-label="Закрыть" onClick={close}><X /></button></header>
    {children}
  </dialog>
}

export function ProfileForm({ initial, onSave, close, pending, error }: { initial?: Specialist; onSave: (data: ProfileInput) => void; close: () => void; pending: boolean; error: unknown }) {
  const { user } = useAuth()
  const [name, setName] = useState(initial?.name ?? '')
  const [specialization, setSpecialization] = useState(initial?.specialization ?? '')
  const [isMe, setIsMe] = useState(initial?.administratorId === user?.id)
  function submit(event: FormEvent) {
    event.preventDefault()
    onSave({ name, specialization, administratorId: isMe ? user!.id : initial?.administratorId === user?.id ? null : initial?.administratorId ?? null })
  }
  return <form onSubmit={submit} className="space-y-5">
    <label className="block space-y-2"><span>Имя специалиста</span><input className={fieldClass} value={name} onChange={(event) => setName(event.target.value)} maxLength={160} required autoFocus /></label>
    <label className="block space-y-2"><span>Специализация</span><input className={fieldClass} value={specialization} onChange={(event) => setSpecialization(event.target.value)} maxLength={100} required placeholder="Логопед" /></label>
    {!initial?.administratorId || initial.administratorId === user?.id ? <label className="flex items-center gap-3"><input type="checkbox" className="size-5 accent-accent" checked={isMe} onChange={(event) => setIsMe(event.target.checked)} /><span>Это мой профиль специалиста</span></label> : <p className="text-muted">Также администратор</p>}
    <Feedback error={error} />
    <Button className="w-full" type="submit" disabled={pending}>{pending ? 'Сохраняем...' : 'Сохранить специалиста'}</Button>
    <Button className="w-full" type="button" variant="outline" onClick={close} disabled={pending}>Отмена</Button>
  </form>
}

export function TimeFields({ value, onChange, label }: { value: Interval; onChange: (value: Interval) => void; label: string }) {
  return <div className="flex min-w-0 items-center gap-2">
    <input type="time" aria-label={`${label}: начало`} className={`${fieldClass} px-1 text-center`} required value={value.start} onChange={(event) => onChange({ ...value, start: event.target.value })} />
    <span aria-hidden="true">–</span>
    <input type="time" aria-label={`${label}: окончание`} className={`${fieldClass} px-1 text-center`} required value={value.end} onChange={(event) => onChange({ ...value, end: event.target.value })} />
  </div>
}

export function formatDate(date: string) {
  return formatNumericDate(date)
}
