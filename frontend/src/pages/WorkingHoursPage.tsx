import { useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link, Navigate, useNavigate, useParams } from 'react-router-dom'
import { Copy, Plus, Trash2 } from 'lucide-react'
import { useAuth } from '@/features/auth/AuthProvider'
import { useSpecialist, useSpecialists, useWorkforceKey, workforceRequest, type Specialist, type Weekday } from '@/features/workforce/api'
import { Avatar, Feedback, TimeFields, WorkforceFrame, dayNames } from '@/features/workforce/components'
import { Button } from '@/shared/ui/Button'

export function WorkingHoursPage() {
  const { id = '' } = useParams()
  const query = useSpecialist(id)
  if (!query.data) return <WorkforceFrame title="Рабочие часы" back={`/specialists/${id}`}><Feedback error={query.error} />{query.isPending ? <p role="status">Загружаем...</p> : null}</WorkforceFrame>
  return <WorkingHoursForm key={id} specialist={query.data} />
}

function WorkingHoursForm({ specialist }: { specialist: Specialist }) {
  const [days, setDays] = useState(specialist.weeklyHours)
  const navigate = useNavigate()
  const key = useWorkforceKey()
  const client = useQueryClient()
  const save = useMutation({ mutationFn: () => workforceRequest(`/${specialist.id}/weekly-hours`, 'PUT', { days }), onSuccess: async () => { await client.invalidateQueries({ queryKey: key }); navigate(`/specialists/${specialist.id}`) } })
  function change(index: number, update: Partial<Weekday>) { setDays((current) => current.map((day, i) => i === index ? { ...day, ...update } : day)) }
  function submit(event: FormEvent) { event.preventDefault(); save.mutate() }
  return <WorkforceFrame title="Рабочие часы" back={`/specialists/${specialist.id}`}>
    <div className="mb-8 flex items-center gap-3"><Avatar name={specialist.name} /><p>{specialist.name} · <span className="text-primary">{specialist.specialization}</span></p></div>
    <form onSubmit={submit} className="max-w-2xl">
      <div className="divide-y divide-border">{days.map((day, index) => <fieldset key={day.weekday} className="min-w-0 py-5" disabled={save.isPending}>
        <legend className="sr-only">{dayNames[index]}</legend>
        <div className="mb-4 flex items-center gap-4"><span className="min-w-32 font-medium">{dayNames[index]}</span><button type="button" role="switch" aria-label={dayNames[index]} aria-checked={day.enabled} className={`relative h-6 w-10 shrink-0 rounded-full transition-colors ${day.enabled ? 'bg-success' : 'bg-muted/30'}`} onClick={() => change(index, { enabled: !day.enabled, work: day.work ?? { start: '09:00', end: '18:00' } })}><span className={`absolute top-0.5 size-5 rounded-full bg-white shadow ${day.enabled ? 'right-0.5' : 'left-0.5'}`} /></button>{!day.enabled ? <span className="ml-auto text-sm text-muted">Выходной</span> : null}</div>
        {day.enabled && day.work ? <div className="space-y-3">
          <div className="grid min-w-0 grid-cols-[100px_minmax(0,1fr)] items-center gap-3 sm:grid-cols-[140px_260px]"><span className="text-sm">Рабочее время</span><TimeFields value={day.work} onChange={(work) => change(index, { work })} label={`${dayNames[index]}, рабочее время`} /></div>
          <div className="grid min-w-0 grid-cols-[100px_minmax(0,1fr)] items-center gap-3 sm:grid-cols-[140px_260px]"><span className="text-sm">Обед</span>{day.lunch ? <div className="min-w-0"><TimeFields label={`${dayNames[index]}, обед`} value={day.lunch} onChange={(lunch) => change(index, { lunch })} /><button type="button" className="mt-2 flex items-center gap-1 text-xs text-danger" aria-label={`Удалить обед: ${dayNames[index]}`} onClick={() => change(index, { lunch: null })}><Trash2 className="size-4" />Удалить обед</button></div> : <button type="button" className="flex items-center gap-1 text-left text-sm text-primary" onClick={() => change(index, { lunch: { start: '13:00', end: '14:00' } })}><Plus className="size-4" />Добавить обед</button>}</div>
          {index === 0 ? <Button type="button" variant="outline" className="mt-2" onClick={() => setDays((current) => current.map((d, i) => i < 5 ? { ...day, weekday: d.weekday } : d))}><Copy className="size-4" />Применить к ПН–ПТ</Button> : null}
        </div> : null}
      </fieldset>)}</div>
      <Feedback error={save.error} /><Button className="mt-5 w-full" type="submit" disabled={save.isPending}>{save.isPending ? 'Сохраняем...' : 'Сохранить часы'}</Button>
      <Button className="mt-2 w-full" variant="outline" type="button" disabled={save.isPending} onClick={() => navigate(`/specialists/${specialist.id}`)}>Отмена</Button>
    </form>
  </WorkforceFrame>
}

export function MySchedulePage() {
  const { user } = useAuth()
  const query = useSpecialists()
  const mine = query.data?.find((s) => s.administratorId === user?.id)
  if (mine) return <Navigate to={`/specialists/${mine.id}/hours`} replace />
  return <WorkforceFrame title="Моё расписание"><Feedback error={query.error} />{query.isPending ? <p role="status">Загружаем...</p> : <p>Свяжите свой профиль в разделе <Link className="text-primary underline" to="/specialists">«Специалисты»</Link>.</p>}</WorkforceFrame>
}
