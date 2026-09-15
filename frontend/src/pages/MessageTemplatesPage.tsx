import { useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'

import { useCommunicationsKey, useMessageTemplates, type MessageTemplate, type MessageTemplateType } from '@/features/communications/api'
import { renderTemplatePreview } from '@/features/communications/templatePreview'
import { apiRequest } from '@/shared/api/request'
import { Button } from '@/shared/ui/Button'
import { ResourceFeedback, ResourceFrame, resourceFieldClass, resourceSurfaceClass } from '@/shared/ui/ResourceLayout'

const tabs: Array<{ type: MessageTemplateType; label: string; title: string }> = [
  { type: 'CONFIRMATION', label: 'Подтверждение', title: 'Запрос подтверждения' },
  { type: 'TRANSFER', label: 'Перенос', title: 'Предложение переноса' },
  { type: 'FREE_WINDOW', label: 'Свободное окно', title: 'Предложение свободного окна' },
  { type: 'PERMANENT_PLACE', label: 'Постоянное место', title: 'Предложение постоянного места' },
]

export function MessageTemplatesPage() {
  const query = useMessageTemplates()
  const [activeType, setActiveType] = useState<MessageTemplateType>('CONFIRMATION')
  const selected = query.data?.find((template) => template.type === activeType)

  return <ResourceFrame title="Общие шаблоны сообщений" back="/settings">
    <p className="-mt-3 mb-6 text-sm text-muted">Используются для услуг без собственного шаблона</p>
    <div className="mb-6 grid grid-cols-2 overflow-hidden rounded-xl border border-border bg-surface sm:grid-cols-4">
      {tabs.map((tab) => <button aria-pressed={tab.type === activeType} className={`min-h-14 px-2 text-sm sm:text-base ${tab.type === activeType ? 'bg-accent-soft text-accent' : 'text-muted'}`} key={tab.type} onClick={() => setActiveType(tab.type)} type="button">{tab.label}</button>)}
    </div>
    <ResourceFeedback error={query.error} />
    {selected ? <TemplateForm key={`${selected.type}-${selected.body}-${JSON.stringify(selected.buttons)}`} template={selected} title={tabs.find((tab) => tab.type === selected.type)?.title ?? ''} /> : query.isPending ? <p role="status">Загружаем шаблоны...</p> : null}
  </ResourceFrame>
}

function TemplateForm({ template, title }: { template: MessageTemplate; title: string }) {
  const [body, setBody] = useState(template.body)
  const [buttons, setButtons] = useState(template.buttons)
  const queryClient = useQueryClient()
  const key = useCommunicationsKey()
  const save = useMutation({
    mutationFn: () => apiRequest(`/api/communications/templates/${template.type.toLowerCase()}`, 'PUT', { body, buttons }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [...key, 'templates'] }),
  })
  const restore = useMutation({
    mutationFn: () => apiRequest(`/api/communications/templates/${template.type.toLowerCase()}`, 'DELETE'),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [...key, 'templates'] }),
  })
  function submit(event: FormEvent) { event.preventDefault(); save.mutate() }

  return <form className="space-y-5" onSubmit={submit}>
    <section className={resourceSurfaceClass}>
      <h2 className="text-lg font-semibold">{title}</h2>
      <textarea aria-label={title} className={`${resourceFieldClass} mt-5 min-h-48 py-4`} maxLength={4000} required value={body} onChange={(event) => setBody(event.target.value)} />
      <p className="mt-4 text-sm text-muted">Это общий шаблон. В карточке услуги можно включить отдельный текст только для её подтверждений.</p>
      <p className="mt-4 text-sm text-muted">Доступные переменные: {'{date}'}, {'{time}'}, {'{service}'}, {'{client_name}'}, {'{contact_name}'}</p>
      {buttons ? <fieldset className="mt-5 border-t border-border pt-5"><legend className="font-semibold">Названия кнопок</legend><div className="mt-3 grid gap-3 sm:grid-cols-3"><ButtonLabel label="Подтверждение" value={buttons.confirm} onChange={(value) => setButtons({ ...buttons, confirm: value })} /><ButtonLabel label="Не сможет прийти" value={buttons.cannotAttend} onChange={(value) => setButtons({ ...buttons, cannotAttend: value })} /><ButtonLabel label="Перенос" value={buttons.transfer} onChange={(value) => setButtons({ ...buttons, transfer: value })} /></div></fieldset> : null}
    </section>
    <section className={resourceSurfaceClass}>
      <h2 className="text-lg font-semibold">Предпросмотр в MAX</h2>
      <div className="mt-4 rounded-[28px] bg-primary-soft p-5 leading-relaxed">{renderTemplatePreview(body)}</div>
      {buttons ? <div className="mt-4 grid grid-cols-1 gap-2 text-sm text-primary sm:grid-cols-3"><span className="rounded-lg border border-primary p-3 text-center">{buttons.confirm}</span><span className="rounded-lg border border-primary p-3 text-center">{buttons.cannotAttend}</span><span className="rounded-lg border border-primary p-3 text-center">{buttons.transfer}</span></div> : null}
    </section>
    <ResourceFeedback error={save.error ?? restore.error} />
    <Button className="w-full" disabled={save.isPending || restore.isPending} type="submit">{save.isPending ? 'Сохраняем...' : 'Сохранить шаблон'}</Button>
    <Button className="w-full" disabled={save.isPending || restore.isPending || template.isDefault} onClick={() => restore.mutate()} type="button" variant="outline">Восстановить исходный</Button>
  </form>
}

function ButtonLabel({ label, value, onChange }: { label: string; value: string; onChange: (value: string) => void }) {
  return <label><span className="mb-2 block text-sm text-muted">{label}</span><input className={resourceFieldClass} maxLength={20} required value={value} onChange={(event) => onChange(event.target.value)} /></label>
}
