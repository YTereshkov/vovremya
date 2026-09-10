import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'

import { useSchedulingSettings, type SchedulingSettings } from '@/features/scheduling/api'
import { apiRequest } from '@/shared/api/request'
import { Button } from '@/shared/ui/Button'
import { ResourceFeedback, resourceFieldClass, resourceSurfaceClass } from '@/shared/ui/ResourceLayout'

export function SchedulingSettingsForm() {
  const settings = useSchedulingSettings()
  const queryClient = useQueryClient()
  const [hours, setHours] = useState('12')
  useEffect(() => {
    if (settings.data) setHours(String(settings.data.lateCancellationHours))
  }, [settings.data])
  const save = useMutation({
    mutationFn: () => apiRequest<SchedulingSettings>('/api/scheduling/settings', 'PUT', { lateCancellationHours: Number(hours) }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['scheduling-settings'] }),
  })

  return <form className={`${resourceSurfaceClass} mt-3 max-w-xl`} onSubmit={(event) => { event.preventDefault(); save.mutate() }}>
    <label className="block text-sm font-medium">Поздняя отмена — менее чем за
      <span className="mt-2 flex items-center gap-3"><input className={`${resourceFieldClass} w-24`} max={168} min={1} onChange={(event) => setHours(event.target.value)} required type="number" value={hours} /><span className="text-sm text-muted">часов до занятия</span></span>
    </label>
    <Button className="mt-4" disabled={save.isPending} size="compact" type="submit">{save.isPending ? 'Сохраняем...' : 'Сохранить'}</Button>
    <ResourceFeedback error={settings.error ?? save.error} />
  </form>
}
