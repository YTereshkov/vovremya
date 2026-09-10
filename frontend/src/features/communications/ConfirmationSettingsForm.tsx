import { useEffect, useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'

import { useCommunicationsKey, useConfirmationSettings, type ConfirmationSettings } from '@/features/communications/api'
import { apiRequest } from '@/shared/api/request'
import { Button } from '@/shared/ui/Button'
import { ResourceFeedback, resourceFieldClass, resourceSurfaceClass } from '@/shared/ui/ResourceLayout'

const defaultSettings: ConfirmationSettings = {
  requestTime: '14:00',
  noResponseTime: '16:00',
  reminderEnabled: true,
  reminderLeadMinutes: 120,
  reminderNotBefore: '07:00',
  quietHoursStart: '21:00',
  quietHoursEnd: '07:00',
}

export function ConfirmationSettingsForm() {
  const query = useConfirmationSettings()
  const key = useCommunicationsKey()
  const queryClient = useQueryClient()
  const [settings, setSettings] = useState(defaultSettings)
  useEffect(() => { if (query.data) setSettings(query.data) }, [query.data])
  const save = useMutation({
    mutationFn: () => apiRequest<ConfirmationSettings>('/api/communications/confirmation-settings', 'PUT', settings),
    onSuccess: async (saved) => {
      setSettings(saved)
      await queryClient.invalidateQueries({ queryKey: [...key, 'confirmation-settings'] })
    },
  })
  function update<K extends keyof ConfirmationSettings>(field: K, value: ConfirmationSettings[K]) {
    setSettings((current) => ({ ...current, [field]: value }))
  }
  function submit(event: FormEvent) { event.preventDefault(); save.mutate() }

  if (query.isPending) return <p className="mt-3 text-sm text-muted" role="status">Загружаем настройки...</p>

  return <form className={`${resourceSurfaceClass} mt-3 max-w-xl space-y-5`} onSubmit={submit}>
    <div className="grid gap-4 sm:grid-cols-2">
      <TimeField label="Запросить подтверждение накануне в" value={settings.requestTime} onChange={(value) => update('requestTime', value)} />
      <TimeField label="Считать, что ответа нет, в" value={settings.noResponseTime} onChange={(value) => update('noResponseTime', value)} />
    </div>
    <label className="flex items-center gap-3"><input className="size-5 accent-primary" checked={settings.reminderEnabled} onChange={(event) => update('reminderEnabled', event.target.checked)} type="checkbox" /><span className="font-medium">Повторное напоминание</span></label>
    {settings.reminderEnabled ? <div className="grid gap-4 sm:grid-cols-2">
      <label><span className="mb-2 block text-sm text-muted">За сколько минут до занятия</span><input className={resourceFieldClass} min={15} max={1440} required type="number" value={settings.reminderLeadMinutes} onChange={(event) => update('reminderLeadMinutes', Number(event.target.value))} /></label>
      <TimeField label="Но не раньше" value={settings.reminderNotBefore} onChange={(value) => update('reminderNotBefore', value)} />
    </div> : null}
    <div>
      <h3 className="font-medium">Не отправлять сообщения</h3>
      <div className="mt-3 grid grid-cols-2 gap-4">
        <TimeField label="С" value={settings.quietHoursStart} onChange={(value) => update('quietHoursStart', value)} />
        <TimeField label="До" value={settings.quietHoursEnd} onChange={(value) => update('quietHoursEnd', value)} />
      </div>
    </div>
    <ResourceFeedback error={query.error ?? save.error} />
    {save.isSuccess ? <p className="text-sm text-primary" role="status">Настройки сохранены.</p> : null}
    <Button disabled={save.isPending} type="submit">{save.isPending ? 'Сохраняем...' : 'Сохранить'}</Button>
  </form>
}

function TimeField({ label, value, onChange }: { label: string; value: string; onChange: (value: string) => void }) {
  return <label><span className="mb-2 block text-sm text-muted">{label}</span><input className={resourceFieldClass} required type="time" value={value} onChange={(event) => onChange(event.target.value)} /></label>
}
