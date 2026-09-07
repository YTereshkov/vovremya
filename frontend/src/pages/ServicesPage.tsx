import { ChevronRight, ClipboardList, Plus } from 'lucide-react'
import { Link } from 'react-router-dom'

import { useServices, type ServiceDefinition } from '@/features/catalog/api'
import { ResourceFeedback, ResourceFrame, resourceSurfaceClass } from '@/shared/ui/ResourceLayout'

export function ServicesPage() {
  const query = useServices()

  return <ResourceFrame
    title="Услуги"
    back="/settings"
    action={<Link className="flex min-h-11 items-center gap-1 text-primary" to="/settings/services/new"><Plus className="size-5" />Услуга</Link>}
  >
    <ResourceFeedback error={query.error} />
    {query.isPending ? <p role="status" className="text-muted">Загружаем услуги...</p> : null}
    {!query.isPending && !query.error && query.data?.length === 0 ? <p className="text-muted">Пока нет услуг. Добавьте первую.</p> : null}
    <div className="space-y-3">
      {query.data?.map((service) => <Link className={`${resourceSurfaceClass} flex items-center gap-4 transition-colors hover:bg-white`} key={service.id} to={`/settings/services/${service.id}`}>
        <span className="grid size-12 shrink-0 place-items-center rounded-lg bg-primary-soft text-primary"><ClipboardList className="size-6" /></span>
        <span className="min-w-0 flex-1">
          <strong className="block break-words">{service.name}</strong>
          <span className="mt-1 block text-sm text-muted">{durationSummary(service)}</span>
        </span>
        <ChevronRight aria-hidden="true" className="size-5 shrink-0 text-muted" />
      </Link>)}
    </div>
  </ResourceFrame>
}

function durationSummary(service: ServiceDefinition): string {
  const base = `${service.defaultDurationMinutes} минут`
  if (service.minimumDurationMinutes !== null && service.maximumDurationMinutes !== null) return `${base} · диапазон ${service.minimumDurationMinutes}–${service.maximumDurationMinutes}`
  if (service.minimumDurationMinutes !== null) return `${base} · минимум ${service.minimumDurationMinutes}`
  if (service.maximumDurationMinutes !== null) return `${base} · максимум ${service.maximumDurationMinutes}`
  return base
}
