import { useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router-dom'

import { useClient, useClientsKey, type ChannelProvider, type ClientInput, type ClientRecord, type ClientType } from '@/features/clients/api'
import { useAuth } from '@/features/auth/AuthProvider'
import { useChannelSettings } from '@/features/communications/api'
import { apiRequest } from '@/shared/api/request'
import { Button } from '@/shared/ui/Button'
import { ResourceFeedback, ResourceFrame, resourceFieldClass, resourceSurfaceClass } from '@/shared/ui/ResourceLayout'

export function ClientFormPage() {
  const { id } = useParams()
  const query = useClient(id ?? '')
  if (id && !query.data) {
    return <ResourceFrame title="Клиент" back="/clients"><ResourceFeedback error={query.error} />{query.isPending ? <p role="status">Загружаем...</p> : null}</ResourceFrame>
  }

  return <ClientForm key={id ?? 'new'} initial={query.data} />
}

function ClientForm({ initial }: { initial?: ClientRecord }) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const clientsKey = useClientsKey()
  const { user } = useAuth()
  const channelSettings = useChannelSettings()
  const [name, setName] = useState(initial?.name ?? '')
  const [type, setType] = useState<ClientType>(initial?.type ?? 'CHILD')
  const [phone, setPhone] = useState(initial?.phone ?? '')
  const [note, setNote] = useState(initial?.note ?? '')
  const [contactName, setContactName] = useState('')
  const [contactPhone, setContactPhone] = useState('')
  const [provider, setProvider] = useState<ChannelProvider | null>(null)
  const selectedProvider = provider ?? channelSettings.data?.defaultProvider ?? user?.organization.defaultChannel ?? 'MAX'
  const [address, setAddress] = useState('')
  const save = useMutation({
    mutationFn: () => {
      const core: ClientInput = { name, type, phone: type === 'ADULT' ? phone || null : null, note: note || null }
      if (initial) return apiRequest<ClientRecord>(`/api/clients/${initial.id}`, 'PUT', core)
      return apiRequest<ClientRecord>('/api/clients', 'POST', {
        ...core,
        contactPerson: type === 'CHILD' ? { name: contactName, phone: contactPhone } : null,
        primaryChannel: { recipient: type === 'CHILD' ? 'CONTACT_PERSON' : 'CLIENT', provider: selectedProvider, address },
      })
    },
    onSuccess: async (client) => {
      await queryClient.invalidateQueries({ queryKey: clientsKey })
      navigate(`/clients/${client.id}`)
    },
  })

  function submit(event: FormEvent) {
    event.preventDefault()
    save.mutate()
  }

  return <ResourceFrame title={initial ? 'Редактировать клиента' : 'Новый клиент'} back={initial ? `/clients/${initial.id}` : '/clients'}>
    <form className="space-y-6" onSubmit={submit}>
      <label className="block space-y-2"><span>Имя клиента</span><input autoFocus className={resourceFieldClass} maxLength={160} required value={name} onChange={(event) => setName(event.target.value)} /></label>
      <fieldset>
        <legend className="mb-2">Клиент</legend>
        <div className="grid grid-cols-2 overflow-hidden rounded-lg border border-border bg-surface">
          <button aria-pressed={type === 'CHILD'} className={`h-12 ${type === 'CHILD' ? 'bg-accent-soft text-accent' : 'text-muted'}`} type="button" onClick={() => setType('CHILD')}>Ребёнок</button>
          <button aria-pressed={type === 'ADULT'} className={`h-12 border-l border-border ${type === 'ADULT' ? 'bg-accent-soft text-accent' : 'text-muted'}`} type="button" onClick={() => setType('ADULT')}>Взрослый</button>
        </div>
      </fieldset>
      {type === 'ADULT' ? <label className="block space-y-2"><span>Телефон клиента</span><input className={resourceFieldClass} maxLength={32} required value={phone} onChange={(event) => setPhone(event.target.value)} /></label> : null}
      <label className="block space-y-2"><span>Организационная заметка</span><textarea className={`${resourceFieldClass} h-28 py-3`} maxLength={300} value={note} onChange={(event) => setNote(event.target.value)} placeholder="Добавьте заметку..." /></label>

      {!initial ? <>
        {type === 'CHILD' ? <section className={resourceSurfaceClass}>
          <h2 className="font-medium">Родитель</h2>
          <p className="mt-1 text-sm text-muted">Основной взрослый, с которым будет связываться специалист.</p>
          <div className="mt-5 space-y-4">
            <label className="block space-y-2"><span>Имя родителя</span><input className={resourceFieldClass} maxLength={160} required value={contactName} onChange={(event) => setContactName(event.target.value)} /></label>
            <label className="block space-y-2"><span>Телефон родителя</span><input className={resourceFieldClass} maxLength={32} required value={contactPhone} onChange={(event) => setContactPhone(event.target.value)} /></label>
          </div>
        </section> : null}

        <section className={resourceSurfaceClass}>
          <h2 className="font-medium">Основной канал связи</h2>
          <p className="mt-1 text-sm text-muted">Обязателен для уведомлений. Получатель: {type === 'CHILD' ? 'родитель' : 'сам клиент'}.</p>
          <div className="mt-5 space-y-4">
            <fieldset><legend className="mb-2">Канал</legend><div className="grid overflow-hidden rounded-lg border border-border bg-surface" style={{ gridTemplateColumns: `repeat(${channelSettings.data?.providers.length || 1}, minmax(0, 1fr))` }}>{(channelSettings.data?.providers.map(({ provider: value }) => value) ?? ['MAX']).map((value) => <button aria-pressed={selectedProvider === value} className={`min-h-12 border-l border-border px-2 text-sm first:border-l-0 ${selectedProvider === value ? 'bg-accent-soft text-accent' : 'text-muted'}`} key={value} type="button" onClick={() => setProvider(value)}>{value === 'WHATSAPP' ? 'WhatsApp' : value === 'TELEGRAM' ? 'Telegram' : value}</button>)}</div></fieldset>
            <label className="block space-y-2"><span>Телефон или адрес канала</span><input className={resourceFieldClass} maxLength={254} required value={address} onChange={(event) => setAddress(event.target.value)} /></label>
          </div>
        </section>
      </> : null}

      <ResourceFeedback error={save.error} />
      <Button className="w-full" disabled={save.isPending} type="submit">{save.isPending ? 'Сохраняем...' : 'Сохранить клиента'}</Button>
      <Button className="w-full" disabled={save.isPending} onClick={() => navigate(initial ? `/clients/${initial.id}` : '/clients')} type="button" variant="outline">Отмена</Button>
    </form>
  </ResourceFrame>
}
