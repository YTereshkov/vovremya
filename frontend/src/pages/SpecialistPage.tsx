import { useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { CalendarClock, CalendarOff, CalendarPlus, ChevronRight, Clock, Pencil, Trash2 } from 'lucide-react'
import { useSpecialist, useWorkforceKey, workforceRequest, type AdditionalDay, type Interval, type ProfileInput, type Specialist } from '@/features/workforce/api'
import { Avatar, Feedback, Modal, ProfileForm, TimeFields, WorkforceFrame, fieldClass, formatDate, surfaceClass } from '@/features/workforce/components'
import { Button } from '@/shared/ui/Button'

const dayNames = ['Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', 'Воскресенье']

export function SpecialistPage() {
  const { id = '' } = useParams()
  const query = useSpecialist(id)
  const client = useQueryClient()
  const key = useWorkforceKey()
  const navigate = useNavigate()
  const [editing, setEditing] = useState(false)
  const [deleting, setDeleting] = useState(false)
  const [day, setDay] = useState<AdditionalDay | 'new' | null>(null)
  const update = useMutation({ mutationFn: (data: ProfileInput) => workforceRequest<Specialist>(`/${id}`, 'PUT', data), onSuccess: async () => { await client.invalidateQueries({ queryKey: key }); setEditing(false) } })
  const remove = useMutation({ mutationFn: () => workforceRequest(`/${id}`, 'DELETE'), onSuccess: async () => { await client.invalidateQueries({ queryKey: key }); navigate('/specialists') } })
  const s = query.data
  if (!s) return <WorkforceFrame title="Специалист" back="/specialists"><Feedback error={query.error} />{query.isPending ? <p role="status">Загружаем...</p> : null}</WorkforceFrame>
  return <WorkforceFrame title="Специалист" back="/specialists" action={<button aria-label="Редактировать специалиста" className="p-2 text-primary" onClick={() => { update.reset(); setEditing(true) }}><Pencil className="size-5" /></button>}>
    <div className="space-y-4">
      <section className={`${surfaceClass} flex items-center gap-4`}><Avatar name={s.name} /><div className="min-w-0"><h2 className="break-words text-xl font-semibold">{s.name}</h2><p className="mt-2 text-muted">{s.specialization}</p>{s.administratorId ? <span className="mt-2 inline-block rounded bg-primary-soft px-2 py-1 text-xs text-primary">Также администратор</span> : null}</div></section>
      <section className={surfaceClass}><h2 className="text-lg font-semibold">Основное расписание</h2>
        <p className="mt-1 text-sm text-muted">Обычные рабочие дни и часы, которые повторяются каждую неделю.</p>
        {s.weeklyHours.some((d) => d.enabled && d.work) ? <div className="mt-4 divide-y divide-border">{s.weeklyHours.filter((d) => d.enabled && d.work).map((d) => <div className="flex flex-wrap items-center justify-between gap-2 py-3" key={d.weekday}><span className="font-medium">{dayNames[d.weekday - 1]}</span><span>{d.work!.start}–{d.work!.end}{d.lunch ? <span className="ml-2 text-sm text-muted">Обед {d.lunch.start}–{d.lunch.end}</span> : null}</span></div>)}</div> : <div className="mt-4 rounded-lg border border-dashed border-border bg-surface-raised p-5 text-center"><CalendarClock className="mx-auto size-7 text-muted" /><p className="mt-2 font-medium">Расписание ещё не настроено</p><p className="mt-1 text-sm text-muted">Укажите рабочие дни и часы специалиста.</p><Button asChild className="mt-4" size="compact" variant="outline"><Link to={`/specialists/${id}/hours`}>Настроить расписание</Link></Button></div>}
      </section>
      <section className={surfaceClass}><h2 className="text-lg font-semibold">Разовые рабочие дни</h2><p className="mt-1 text-sm text-muted">Работа вне обычного расписания — например, в выходной или праздник.</p>{s.additionalDays.length ? <div className="mt-2 divide-y divide-border">{s.additionalDays.map((d) => <button key={d.id} onClick={() => setDay(d)} className="flex w-full items-center gap-3 py-4 text-left"><CalendarPlus className="size-6 shrink-0 text-primary" /><span className="flex-1"><span className="block">{formatDate(d.date)}</span><span className="text-sm text-muted">{d.work.start}–{d.work.end}</span></span><ChevronRight className="size-5 shrink-0" /></button>)}</div> : <p className="mt-4 text-muted">Разовых рабочих дней пока нет.</p>}</section>
      {s.absences.length ? <section className={surfaceClass}><h2 className="text-lg font-semibold">Отсутствия</h2><div className="mt-2 divide-y divide-border">{s.absences.map((absence) => <div className="flex items-start gap-3 py-4" key={absence.id}><CalendarOff className="mt-0.5 size-5 shrink-0 text-warning" /><div><p>{absenceLabel(absence.type)} · {formatDate(absence.startsOn)}–{formatDate(absence.endsOn)}</p>{absence.comment ? <p className="mt-1 text-sm text-muted">{absence.comment}</p> : null}</div></div>)}</div></section> : null}
      <section className={surfaceClass}><h2 className="mb-2 text-lg font-semibold">Действия</h2><Link className="flex items-center gap-3 border-b border-border py-4" to={`/specialists/${id}/hours`}><Clock className="size-6 text-success" /><span className="flex-1">Изменить основное расписание</span><ChevronRight className="size-5" /></Link><Link className="flex items-center gap-3 border-b border-border py-4" to={`/specialists/${id}/absence`}><CalendarOff className="size-6 text-warning" /><span className="flex-1">Оформить отсутствие</span><ChevronRight className="size-5" /></Link><button className="flex w-full items-center gap-3 py-4 text-left" onClick={() => setDay('new')}><CalendarPlus className="size-6 text-primary" /><span className="flex-1">Добавить разовый рабочий день</span><ChevronRight className="size-5" /></button></section>
      <button className="flex items-center gap-2 px-2 py-3 text-sm text-danger" onClick={() => { remove.reset(); setDeleting(true) }}><Trash2 className="size-4" />Удалить специалиста</button>
    </div>
    {editing ? <Modal title="Редактировать специалиста" close={() => !update.isPending && setEditing(false)}><ProfileForm initial={s} onSave={(data) => update.mutate(data)} close={() => setEditing(false)} pending={update.isPending} error={update.error} /></Modal> : null}
    {deleting ? <Modal title="Удалить специалиста?" close={() => !remove.isPending && setDeleting(false)}><p>Профиль «{s.name}», его рабочие часы и дополнительные дни будут удалены. Аккаунт администратора сохранится.</p><Feedback error={remove.error} /><div className="mt-6 flex gap-3"><Button disabled={remove.isPending} onClick={() => remove.mutate()}>Удалить</Button><Button variant="outline" onClick={() => setDeleting(false)} disabled={remove.isPending}>Отмена</Button></div></Modal> : null}
    {day ? <AdditionalDayForm specialistId={id} initial={day === 'new' ? undefined : day} close={() => setDay(null)} /> : null}
  </WorkforceFrame>
}

function absenceLabel(type: Specialist['absences'][number]['type']) {
  if (type === 'VACATION') return 'Отпуск'
  if (type === 'SICK_LEAVE') return 'Больничный'
  return 'Другое'
}

function AdditionalDayForm({ specialistId, initial, close }: { specialistId: string; initial?: AdditionalDay; close: () => void }) {
  const [date, setDate] = useState(initial?.date ?? '')
  const [work, setWork] = useState<Interval>(initial?.work ?? { start: '10:00', end: '15:00' })
  const [confirmDelete, setConfirmDelete] = useState(false)
  const key = useWorkforceKey()
  const client = useQueryClient()
  const path = `/${specialistId}/additional-days${initial ? `/${initial.id}` : ''}`
  const change = useMutation({ mutationFn: (remove: boolean) => workforceRequest(path, remove ? 'DELETE' : initial ? 'PUT' : 'POST', remove ? undefined : { date, work }), onSuccess: async () => { await client.invalidateQueries({ queryKey: key }); close() } })
  function submit(event: FormEvent) { event.preventDefault(); change.mutate(false) }
  return <Modal title={initial ? 'Разовый рабочий день' : 'Добавить разовый рабочий день'} close={() => !change.isPending && close()}>
    <form onSubmit={submit} className="space-y-5"><label className="block space-y-2"><span>Дата</span><input type="date" className={fieldClass} required value={date} onChange={(event) => setDate(event.target.value)} /></label>
      <div><p className="mb-2">Рабочее время</p><TimeFields label="Рабочее время" value={work} onChange={setWork} /></div>
      <Feedback error={change.error} /><Button className="w-full" type="submit" disabled={change.isPending}>{change.isPending ? 'Сохраняем...' : 'Сохранить день'}</Button>
      <Button variant="outline" className="w-full" type="button" disabled={change.isPending} onClick={close}>Отмена</Button>
      {initial ? <button type="button" className="text-danger" disabled={change.isPending} onClick={() => setConfirmDelete(true)}>Удалить рабочий день</button> : null}
      {confirmDelete ? <div role="group" aria-label="Подтверждение удаления дня"><p className="mb-3">Удалить дополнительный день {formatDate(initial!.date)}?</p><Button type="button" disabled={change.isPending} onClick={() => change.mutate(true)}>Подтвердить удаление</Button></div> : null}
    </form>
  </Modal>
}
