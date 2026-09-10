import { useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { CalendarDays } from 'lucide-react'
import { useNavigate, useParams } from 'react-router-dom'

import { addDays, todayInput, useAbsenceImpact, type ClientAbsenceMode } from '@/features/absences/api'
import { useClient } from '@/features/clients/api'
import { apiRequest } from '@/shared/api/request'
import { Button } from '@/shared/ui/Button'
import { ResourceAvatar, ResourceFeedback, ResourceFrame, resourceFieldClass, resourceSurfaceClass } from '@/shared/ui/ResourceLayout'

export function ClientAbsencePage() {
  const { id = '' } = useParams()
  const client = useClient(id)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [startsOn, setStartsOn] = useState(todayInput)
  const [endsOn, setEndsOn] = useState(() => addDays(todayInput(), 14))
  const [reason, setReason] = useState('Отпуск')
  const [mode, setMode] = useState<ClientAbsenceMode>('KEEP_PERMANENT_PLACE')
  const [createFreeWindows, setCreateFreeWindows] = useState(true)
  const [notifyClient, setNotifyClient] = useState(true)
  const impact = useAbsenceImpact('clients', id, startsOn, endsOn)
  const save = useMutation({
    mutationFn: () => apiRequest(`/api/clients/${id}/absences`, 'POST', {
      startsOn, endsOn, reason: reason || null, mode,
      createFreeWindows: mode === 'KEEP_PERMANENT_PLACE' && createFreeWindows,
      notifyClient,
    }),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['clients'] }),
        queryClient.invalidateQueries({ queryKey: ['calendar'] }),
        queryClient.invalidateQueries({ queryKey: ['regular-schedules'] }),
        queryClient.invalidateQueries({ queryKey: ['free-windows'] }),
      ])
      navigate(`/clients/${id}`)
    },
  })
  const profile = client.data
  function submit(event: FormEvent) { event.preventDefault(); save.mutate() }

  return <ResourceFrame title="Клиент отсутствует" back={`/clients/${id}`}>
    <form className="space-y-5" onSubmit={submit}>
      {profile ? <section className={`${resourceSurfaceClass} flex items-center gap-4`}><ResourceAvatar name={profile.name} /><h2 className="text-lg font-semibold">{profile.name}</h2></section> : <ResourceFeedback error={client.error} />}
      <div className="grid grid-cols-2 gap-3"><label className="space-y-2"><span>С</span><input className={resourceFieldClass} required type="date" value={startsOn} onChange={(event) => setStartsOn(event.target.value)} /></label><label className="space-y-2"><span>По</span><input className={resourceFieldClass} min={startsOn} required type="date" value={endsOn} onChange={(event) => setEndsOn(event.target.value)} /></label></div>
      <label className="block space-y-2"><span>Причина (необязательно)</span><select className={resourceFieldClass} value={reason} onChange={(event) => setReason(event.target.value)}><option value="Отпуск">Отпуск</option><option value="Болезнь">Болезнь</option><option value="">Не указана</option></select></label>
      <section className={resourceSurfaceClass}><p className="flex items-center gap-2"><CalendarDays className="size-5 text-primary" /><strong>{impact.data?.appointments ?? '—'} занятий в диапазоне</strong></p></section>
      <fieldset className="space-y-3"><legend className="mb-2">Что сделать с занятиями</legend>
        <label className={`block rounded-2xl border p-5 ${mode === 'KEEP_PERMANENT_PLACE' ? 'border-primary bg-primary-soft' : 'border-border bg-white/70'}`}><span className="flex items-start gap-3"><input checked={mode === 'KEEP_PERMANENT_PLACE'} className="mt-1 size-5 accent-primary" name="absence-mode" onChange={() => setMode('KEEP_PERMANENT_PLACE')} type="radio" /><span><strong className="block">Сохранить постоянное место</strong><span className="mt-1 block text-sm text-muted">После отсутствия регулярное расписание продолжится.</span></span></span>{mode === 'KEEP_PERMANENT_PLACE' ? <span className="mt-4 flex items-start gap-3 border-t border-primary/20 pt-4"><input checked={createFreeWindows} className="mt-1 size-5 accent-primary" onChange={(event) => setCreateFreeWindows(event.target.checked)} type="checkbox" /><span><strong className="block">Создать разовые свободные окна</strong><span className="text-sm text-muted">Интервалы можно будет предложить другим клиентам.</span></span></span> : null}</label>
        <label className={`flex items-start gap-3 rounded-2xl border p-5 ${mode === 'RELEASE_PERMANENT_PLACE' ? 'border-primary bg-primary-soft' : 'border-border bg-white/70'}`}><input checked={mode === 'RELEASE_PERMANENT_PLACE'} className="mt-1 size-5 accent-primary" name="absence-mode" onChange={() => setMode('RELEASE_PERMANENT_PLACE')} type="radio" /><span><strong className="block">Освободить постоянное место</strong><span className="mt-1 block text-sm text-muted">Регулярное расписание клиента завершится.</span></span></label>
      </fieldset>
      <label className={`${resourceSurfaceClass} flex items-center justify-between gap-3`}><span>Уведомить клиента</span><input checked={notifyClient} className="size-6 accent-primary" onChange={(event) => setNotifyClient(event.target.checked)} type="checkbox" /></label>
      <ResourceFeedback error={save.error ?? impact.error} />
      <Button className="w-full" disabled={save.isPending || !profile || endsOn < startsOn}>{save.isPending ? 'Сохраняем...' : 'Сохранить отсутствие'}</Button>
      <Button className="w-full" type="button" variant="outline" onClick={() => navigate(`/clients/${id}`)}>Отмена</Button>
    </form>
  </ResourceFrame>
}
