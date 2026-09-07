import { useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ChevronRight, MessageCircle, Pencil, Phone, Plus, Star, Trash2, UserRound } from 'lucide-react'

import { useClient, useClientsKey, type ChannelProvider, type ClientRecord } from '@/features/clients/api'
import { apiRequest } from '@/shared/api/request'
import { Button } from '@/shared/ui/Button'
import { ResourceAvatar, ResourceFeedback, ResourceFrame, ResourceModal, resourceFieldClass, resourceSurfaceClass } from '@/shared/ui/ResourceLayout'

export function ClientPage() {
  const { id = '' } = useParams()
  const query = useClient(id)
  const queryClient = useQueryClient()
  const clientsKey = useClientsKey()
  const navigate = useNavigate()
  const [contactOpen, setContactOpen] = useState(false)
  const [channelOpen, setChannelOpen] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const refresh = async () => queryClient.invalidateQueries({ queryKey: clientsKey })
  const removeClient = useMutation({
    mutationFn: () => apiRequest(`/api/clients/${id}`, 'DELETE'),
    onSuccess: async () => { await refresh(); navigate('/clients') },
  })
  const removeContact = useMutation({ mutationFn: (contactId: string) => apiRequest<ClientRecord>(`/api/clients/${id}/contacts/${contactId}`, 'DELETE'), onSuccess: refresh })
  const removeChannel = useMutation({ mutationFn: (channelId: string) => apiRequest<ClientRecord>(`/api/clients/${id}/channels/${channelId}`, 'DELETE'), onSuccess: refresh })
  const selectPrimary = useMutation({ mutationFn: (connectionId: string) => apiRequest<ClientRecord>(`/api/clients/${id}/primary-channel`, 'PUT', { connectionId }), onSuccess: refresh })
  const client = query.data

  if (!client) {
    return <ResourceFrame title="Клиент" back="/clients"><ResourceFeedback error={query.error} />{query.isPending ? <p role="status">Загружаем...</p> : null}</ResourceFrame>
  }

  return <ResourceFrame title="Клиент" back="/clients" action={<Link aria-label="Редактировать клиента" title="Редактировать клиента" className="grid size-11 place-items-center text-primary" to={`/clients/${id}/edit`}><Pencil className="size-5" /></Link>}>
    <div className="space-y-4">
      <section className={`${resourceSurfaceClass} flex items-center gap-4`}>
        <ResourceAvatar name={client.name} />
        <div className="min-w-0"><h2 className="break-words text-xl font-semibold">{client.name}</h2><p className="mt-1 text-muted">{client.type === 'CHILD' ? 'Ребёнок' : 'Взрослый'}</p>{client.phone ? <p className="mt-2 flex items-center gap-2"><Phone className="size-4 text-success" />{client.phone}</p> : null}</div>
      </section>

      {client.note ? <section className={resourceSurfaceClass}><h2 className="font-semibold">Организационная заметка</h2><p className="mt-2 whitespace-pre-wrap text-muted">{client.note}</p></section> : null}

      <section className={resourceSurfaceClass}>
        <div className="flex items-center justify-between gap-3"><h2 className="text-lg font-semibold">Контактные лица</h2><button aria-label="Добавить контактное лицо" title="Добавить контактное лицо" className="grid size-10 place-items-center text-primary" onClick={() => setContactOpen(true)}><Plus className="size-5" /></button></div>
        {client.contacts.length === 0 ? <p className="mt-3 text-muted">Контактных лиц нет. Получателем может быть сам клиент.</p> : <div className="mt-2 divide-y divide-border">{client.contacts.map((contact) => <div className="flex items-center gap-3 py-4" key={contact.id}><UserRound className="size-5 shrink-0 text-primary" /><div className="min-w-0 flex-1"><p className="break-words font-medium">{contact.name}</p>{contact.phone ? <p className="text-sm text-muted">{contact.phone}</p> : null}</div><button aria-label={`Удалить контактное лицо ${contact.name}`} title="Удалить контактное лицо" className="grid size-10 place-items-center text-danger" disabled={removeContact.isPending} onClick={() => removeContact.mutate(contact.id)}><Trash2 className="size-4" /></button></div>)}</div>}
        <ResourceFeedback error={removeContact.error} />
      </section>

      <section className={resourceSurfaceClass}>
        <div className="flex items-center justify-between gap-3"><h2 className="text-lg font-semibold">Каналы связи</h2><button aria-label="Добавить канал" title="Добавить канал" className="grid size-10 place-items-center text-primary" onClick={() => setChannelOpen(true)}><Plus className="size-5" /></button></div>
        {client.channels.length === 0 ? <p className="mt-3 text-muted">Каналы не добавлены.</p> : <div className="mt-2 divide-y divide-border">{client.channels.map((channel) => <div className="py-4" key={channel.id}>
          <div className="flex items-start gap-3"><MessageCircle className="mt-1 size-5 shrink-0 text-primary" /><div className="min-w-0 flex-1"><div className="flex flex-wrap items-center gap-2"><strong>{providerLabel(channel.provider)}</strong>{channel.primary ? <span className="rounded bg-primary-soft px-2 py-1 text-xs text-primary">Основной</span> : null}</div><p className="mt-1 break-words text-sm text-muted">{channel.address} · {channel.recipientName}</p></div>
            {!channel.primary ? <button aria-label={`Сделать ${providerLabel(channel.provider)} основным`} title="Сделать основным" className="grid size-10 place-items-center text-primary" disabled={selectPrimary.isPending} onClick={() => selectPrimary.mutate(channel.id)}><Star className="size-4" /></button> : null}
            <button aria-label={`Удалить канал ${providerLabel(channel.provider)}`} title="Удалить канал" className="grid size-10 place-items-center text-danger" disabled={removeChannel.isPending} onClick={() => removeChannel.mutate(channel.id)}><Trash2 className="size-4" /></button>
          </div>
        </div>)}</div>}
        <ResourceFeedback error={removeChannel.error ?? selectPrimary.error} />
      </section>

      <Link className={`${resourceSurfaceClass} flex min-h-14 items-center gap-3`} to={`/clients/${id}/edit`}><Pencil className="size-5 text-primary" /><span className="flex-1">Редактировать клиента</span><ChevronRight className="size-5 text-muted" /></Link>
      <button className="flex min-h-11 items-center gap-2 px-2 text-danger" onClick={() => setConfirmDelete(true)}><Trash2 className="size-5" />Удалить клиента</button>
    </div>

    {contactOpen ? <ContactForm client={client} close={() => setContactOpen(false)} /> : null}
    {channelOpen ? <ChannelForm client={client} close={() => setChannelOpen(false)} /> : null}
    {confirmDelete ? <ResourceModal title="Удалить клиента?" close={() => !removeClient.isPending && setConfirmDelete(false)}><p>Клиент «{client.name}», контактные лица и метаданные каналов будут удалены.</p><ResourceFeedback error={removeClient.error} /><div className="mt-6 flex gap-3"><Button disabled={removeClient.isPending} onClick={() => removeClient.mutate()}>Удалить</Button><Button disabled={removeClient.isPending} onClick={() => setConfirmDelete(false)} variant="outline">Отмена</Button></div></ResourceModal> : null}
  </ResourceFrame>
}

function ContactForm({ client, close }: { client: ClientRecord; close: () => void }) {
  const [name, setName] = useState('')
  const [phone, setPhone] = useState('')
  const queryClient = useQueryClient()
  const clientsKey = useClientsKey()
  const save = useMutation({ mutationFn: () => apiRequest<ClientRecord>(`/api/clients/${client.id}/contacts`, 'POST', { name, phone: phone || null }), onSuccess: async () => { await queryClient.invalidateQueries({ queryKey: clientsKey }); close() } })
  function submit(event: FormEvent) { event.preventDefault(); save.mutate() }
  return <ResourceModal title="Новое контактное лицо" close={() => !save.isPending && close()}><form className="space-y-5" onSubmit={submit}><label className="block space-y-2"><span>Имя</span><input autoFocus className={resourceFieldClass} maxLength={160} required value={name} onChange={(event) => setName(event.target.value)} /></label><label className="block space-y-2"><span>Телефон</span><input className={resourceFieldClass} maxLength={32} value={phone} onChange={(event) => setPhone(event.target.value)} /></label><ResourceFeedback error={save.error} /><Button className="w-full" disabled={save.isPending}>Добавить</Button><Button className="w-full" disabled={save.isPending} onClick={close} type="button" variant="outline">Отмена</Button></form></ResourceModal>
}

function ChannelForm({ client, close }: { client: ClientRecord; close: () => void }) {
  const [contactPersonId, setContactPersonId] = useState('')
  const [provider, setProvider] = useState<ChannelProvider>('MAX')
  const [address, setAddress] = useState('')
  const [primary, setPrimary] = useState(client.primaryChannelId === null)
  const queryClient = useQueryClient()
  const clientsKey = useClientsKey()
  const save = useMutation({ mutationFn: () => apiRequest<ClientRecord>(`/api/clients/${client.id}/channels`, 'POST', { contactPersonId: contactPersonId || null, provider, address, primary }), onSuccess: async () => { await queryClient.invalidateQueries({ queryKey: clientsKey }); close() } })
  function submit(event: FormEvent) { event.preventDefault(); save.mutate() }
  return <ResourceModal title="Новый канал" close={() => !save.isPending && close()}><form className="space-y-5" onSubmit={submit}>
    <label className="block space-y-2"><span>Получатель</span><select className={resourceFieldClass} value={contactPersonId} onChange={(event) => setContactPersonId(event.target.value)}><option value="">{client.name} · клиент</option>{client.contacts.map((contact) => <option key={contact.id} value={contact.id}>{contact.name} · контактное лицо</option>)}</select></label>
    <label className="block space-y-2"><span>Канал</span><select className={resourceFieldClass} value={provider} onChange={(event) => setProvider(event.target.value as ChannelProvider)}><option value="MAX">MAX</option><option value="TELEGRAM">Telegram</option><option value="WHATSAPP">WhatsApp</option></select></label>
    <label className="block space-y-2"><span>Телефон или адрес</span><input className={resourceFieldClass} maxLength={254} required value={address} onChange={(event) => setAddress(event.target.value)} /></label>
    <label className="flex items-center gap-3"><input className="size-5 accent-primary" checked={primary} onChange={(event) => setPrimary(event.target.checked)} type="checkbox" /><span>Использовать как основной канал</span></label>
    <ResourceFeedback error={save.error} /><Button className="w-full" disabled={save.isPending}>Добавить канал</Button><Button className="w-full" disabled={save.isPending} onClick={close} type="button" variant="outline">Отмена</Button>
  </form></ResourceModal>
}

function providerLabel(provider: ChannelProvider): string {
  if (provider === 'TELEGRAM') return 'Telegram'
  if (provider === 'WHATSAPP') return 'WhatsApp'
  return 'MAX'
}
