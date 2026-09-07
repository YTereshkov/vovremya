import { useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router-dom'
import { Trash2 } from 'lucide-react'

import { useCatalogKey, useService, type ServiceDefinition, type ServiceInput } from '@/features/catalog/api'
import { apiRequest } from '@/shared/api/request'
import { Button } from '@/shared/ui/Button'
import { ResourceFeedback, ResourceFrame, ResourceModal, resourceFieldClass, resourceSurfaceClass } from '@/shared/ui/ResourceLayout'

export function ServiceFormPage() {
  const { id } = useParams()
  const query = useService(id ?? '')
  if (id && !query.data) {
    return <ResourceFrame title="Услуга" back="/settings/services"><ResourceFeedback error={query.error} />{query.isPending ? <p role="status">Загружаем...</p> : null}</ResourceFrame>
  }

  return <ServiceForm key={id ?? 'new'} initial={query.data} />
}

function ServiceForm({ initial }: { initial?: ServiceDefinition }) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const catalogKey = useCatalogKey()
  const [name, setName] = useState(initial?.name ?? '')
  const [duration, setDuration] = useState(initial?.defaultDurationMinutes ?? 45)
  const [minimum, setMinimum] = useState<number | null>(initial?.minimumDurationMinutes ?? null)
  const [maximum, setMaximum] = useState<number | null>(initial?.maximumDurationMinutes ?? null)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const save = useMutation({
    mutationFn: (input: ServiceInput) => apiRequest<ServiceDefinition>(initial ? `/api/services/${initial.id}` : '/api/services', initial ? 'PUT' : 'POST', input),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: catalogKey })
      navigate('/settings/services')
    },
  })
  const remove = useMutation({
    mutationFn: () => apiRequest(`/api/services/${initial!.id}`, 'DELETE'),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: catalogKey })
      navigate('/settings/services')
    },
  })

  function submit(event: FormEvent) {
    event.preventDefault()
    save.mutate({ name, defaultDurationMinutes: duration, minimumDurationMinutes: minimum, maximumDurationMinutes: maximum })
  }

  return <ResourceFrame title={initial ? 'Редактировать услугу' : 'Новая услуга'} back="/settings/services">
    <form className="max-w-2xl space-y-6" onSubmit={submit}>
      <label className="block space-y-2"><span>Название</span><input autoFocus className={resourceFieldClass} maxLength={160} required value={name} onChange={(event) => setName(event.target.value)} placeholder="Название услуги" /></label>
      <fieldset className={resourceSurfaceClass} disabled={save.isPending}>
        <legend className="mb-1 text-lg font-semibold">Длительность</legend>
        <DurationField label="По умолчанию" required value={duration} onChange={(value) => setDuration(value ?? 0)} />
        <DurationField label="Минимум" value={minimum} onChange={setMinimum} />
        <DurationField label="Максимум" value={maximum} onChange={setMaximum} />
      </fieldset>
      <ResourceFeedback error={save.error} />
      <Button className="w-full" disabled={save.isPending} type="submit">{save.isPending ? 'Сохраняем...' : initial ? 'Сохранить' : 'Добавить услугу'}</Button>
      <Button className="w-full" disabled={save.isPending} onClick={() => navigate('/settings/services')} type="button" variant="outline">Отмена</Button>
      {initial ? <button type="button" className="flex min-h-11 items-center gap-2 text-danger" onClick={() => setConfirmDelete(true)}><Trash2 className="size-5" />Удалить услугу</button> : null}
    </form>
    {confirmDelete ? <ResourceModal title="Удалить услугу?" close={() => !remove.isPending && setConfirmDelete(false)}>
      <p>Услуга «{initial?.name}» исчезнет из активного списка. Пользовательского архива нет.</p>
      <ResourceFeedback error={remove.error} />
      <div className="mt-6 flex gap-3"><Button disabled={remove.isPending} onClick={() => remove.mutate()}>Удалить</Button><Button disabled={remove.isPending} onClick={() => setConfirmDelete(false)} variant="outline">Отмена</Button></div>
    </ResourceModal> : null}
  </ResourceFrame>
}

function DurationField({ label, value, onChange, required = false }: { label: string; value: number | null; onChange: (value: number | null) => void; required?: boolean }) {
  return <label className="grid grid-cols-[minmax(0,1fr)_100px_auto] items-center gap-3 border-b border-border py-4 last:border-b-0">
    <span className="text-muted">{label}</span>
    <input aria-label={`${label}, минут`} className={`${resourceFieldClass} text-center`} min={1} max={1440} required={required} type="number" value={value ?? ''} onChange={(event) => onChange(Number.isNaN(event.target.valueAsNumber) ? null : event.target.valueAsNumber)} placeholder={required ? undefined : '—'} />
    <span className="text-sm text-muted">минут</span>
  </label>
}
