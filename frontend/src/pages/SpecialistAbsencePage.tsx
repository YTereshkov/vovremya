import { useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { CalendarDays, Info } from 'lucide-react'
import { useNavigate, useParams } from 'react-router-dom'

import { addDays, todayInput, useAbsenceImpact, type SpecialistAbsenceInput } from '@/features/absences/api'
import { useSpecialist } from '@/features/workforce/api'
import { apiRequest } from '@/shared/api/request'
import { Button } from '@/shared/ui/Button'
import { ResourceAvatar, ResourceFeedback, ResourceFrame, resourceFieldClass, resourceSurfaceClass } from '@/shared/ui/ResourceLayout'

export function SpecialistAbsencePage() {
  const { id = '' } = useParams()
  const specialist = useSpecialist(id)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [type, setType] = useState<SpecialistAbsenceInput['type']>('VACATION')
  const [startsOn, setStartsOn] = useState(todayInput)
  const [endsOn, setEndsOn] = useState(() => addDays(todayInput(), 7))
  const [comment, setComment] = useState('')
  const [notifyClients, setNotifyClients] = useState(true)
  const impact = useAbsenceImpact('specialists', id, startsOn, endsOn)
  const save = useMutation({
    mutationFn: () => apiRequest(`/api/specialists/${id}/absences`, 'POST', { type, startsOn, endsOn, comment: comment || null, notifyClients }),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['workforce'] }),
        queryClient.invalidateQueries({ queryKey: ['calendar'] }),
        queryClient.invalidateQueries({ queryKey: ['free-windows'] }),
      ])
      navigate(`/specialists/${id}`)
    },
  })
  const profile = specialist.data
  function submit(event: FormEvent) { event.preventDefault(); save.mutate() }

  return <ResourceFrame title="Отсутствие специалиста" back={`/specialists/${id}`}>
    <form className="space-y-5" onSubmit={submit}>
      {profile ? <section className={`${resourceSurfaceClass} flex items-center gap-4`}><ResourceAvatar name={profile.name} /><div><h2 className="text-lg font-semibold">{profile.name}</h2><p className="text-muted">{profile.specialization}</p></div></section> : <ResourceFeedback error={specialist.error} />}
      <label className="block space-y-2"><span>Тип отсутствия</span><select className={resourceFieldClass} value={type} onChange={(event) => setType(event.target.value as SpecialistAbsenceInput['type'])}><option value="VACATION">Отпуск</option><option value="SICK_LEAVE">Больничный</option><option value="OTHER">Другое</option></select></label>
      <div className="grid grid-cols-2 gap-3"><label className="space-y-2"><span>С</span><input className={resourceFieldClass} required type="date" value={startsOn} onChange={(event) => setStartsOn(event.target.value)} /></label><label className="space-y-2"><span>По</span><input className={resourceFieldClass} min={startsOn} required type="date" value={endsOn} onChange={(event) => setEndsOn(event.target.value)} /></label></div>
      <label className="block space-y-2"><span>Комментарий (необязательно)</span><textarea className={`${resourceFieldClass} min-h-24 py-3`} maxLength={1000} value={comment} onChange={(event) => setComment(event.target.value)} /></label>
      <section className="rounded-2xl border border-primary/20 bg-primary-soft p-5">
        <div className="flex items-start gap-3"><Info className="mt-0.5 size-6 shrink-0 text-primary" /><div className="space-y-3"><p className="flex items-center gap-2"><CalendarDays className="size-5" /><strong>{impact.data?.appointments ?? '—'} занятий в периоде будут отменены</strong></p><p>Свободные окна не создаются — специалист не работает.</p><p>Регулярные расписания продолжатся после отсутствия.</p></div></div>
      </section>
      <label className={`${resourceSurfaceClass} flex items-center justify-between gap-3`}><span>Уведомить затронутых клиентов</span><input className="size-6 accent-primary" checked={notifyClients} onChange={(event) => setNotifyClients(event.target.checked)} type="checkbox" /></label>
      <ResourceFeedback error={save.error ?? impact.error} />
      <Button className="w-full" disabled={save.isPending || !profile || endsOn < startsOn}>{save.isPending ? 'Сохраняем...' : 'Сохранить отсутствие'}</Button>
      <Button className="w-full" type="button" variant="outline" onClick={() => navigate(`/specialists/${id}`)}>Отмена</Button>
    </form>
  </ResourceFrame>
}
