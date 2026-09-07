import { useDeferredValue, useState } from 'react'
import { ChevronRight, Plus, Search } from 'lucide-react'
import { Link } from 'react-router-dom'

import { useClients } from '@/features/clients/api'
import { ResourceAvatar, ResourceFeedback, ResourceFrame, resourceFieldClass, resourceSurfaceClass } from '@/shared/ui/ResourceLayout'

export function ClientsPage() {
  const [search, setSearch] = useState('')
  const deferredSearch = useDeferredValue(search.trim())
  const query = useClients(deferredSearch)

  return <ResourceFrame title="Клиенты" action={<Link className="flex min-h-11 items-center gap-1 text-primary" to="/clients/new"><Plus className="size-5" />Клиент</Link>}>
    <label className="relative block">
      <Search aria-hidden="true" className="absolute left-3 top-3 size-5 text-muted" />
      <input className={`${resourceFieldClass} pl-11`} value={search} onChange={(event) => setSearch(event.target.value)} aria-label="Поиск клиента" placeholder="Поиск клиента" />
    </label>
    <ResourceFeedback error={query.error} />
    {query.isPending ? <p role="status" className="mt-7 text-muted">Загружаем клиентов...</p> : null}
    {!query.isPending && !query.error && query.data?.length === 0 ? <p className="mt-7 text-muted">{deferredSearch ? 'Клиенты не найдены.' : 'Пока нет клиентов. Добавьте первого.'}</p> : null}
    <div className="mt-7 space-y-3">
      {query.data?.map((client) => {
        const primary = client.channels.find((channel) => channel.primary)
        return <Link className={`${resourceSurfaceClass} flex items-center gap-4 transition-colors hover:bg-white`} key={client.id} to={`/clients/${client.id}`}>
          <ResourceAvatar name={client.name} />
          <span className="min-w-0 flex-1">
            <strong className="block break-words">{client.name}</strong>
            <span className="mt-1 block text-sm text-muted">{client.type === 'CHILD' ? 'Ребёнок' : 'Взрослый'}</span>
            {client.contacts[0] ? <span className="mt-1 block truncate text-sm text-muted">Контакт: {client.contacts[0].name}</span> : null}
            {primary ? <span className="mt-2 inline-block rounded bg-primary-soft px-2 py-1 text-xs text-primary">{primary.provider} · {primary.recipientName}</span> : null}
          </span>
          <ChevronRight aria-hidden="true" className="size-5 shrink-0 text-muted" />
        </Link>
      })}
    </div>
  </ResourceFrame>
}
