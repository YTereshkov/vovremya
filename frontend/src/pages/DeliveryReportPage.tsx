import { AlertTriangle, Check, CheckCheck, Clock3, Phone, RefreshCw, Send } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'

import { useDeliveryReport, useRetryOutboundMessage, type DeliveryReportItem } from '@/features/communications/api'
import { useAuth } from '@/features/auth/AuthProvider'
import { formatNumericDateTime } from '@/shared/lib/date'
import { Button } from '@/shared/ui/Button'
import { ResourceFeedback, ResourceFrame, resourceSurfaceClass } from '@/shared/ui/ResourceLayout'

export function DeliveryReportPage() {
  const { absenceId } = useParams()
  const query = useDeliveryReport(absenceId)
  const retry = useRetryOutboundMessage(absenceId)
  const { user } = useAuth()
  const timezone = user?.organization.timezone ?? 'UTC'
  const items = query.data?.items ?? []
  const errors = items.filter((item) => ['FAILED', 'NO_CHANNEL', 'NOT_QUEUED'].includes(item.status)).length
  const successful = items.filter((item) => ['SENT', 'DELIVERED', 'READ'].includes(item.status)).length

  return <ResourceFrame title={absenceId ? 'Статусы массовой отмены' : 'Не доставленные сообщения'} back="/notifications">
    <section className={`${resourceSurfaceClass} grid grid-cols-2 gap-4 text-center`}>
      <div><strong className="block text-2xl text-success">{successful}</strong><span className="text-sm text-muted">отправлено</span></div>
      <div><strong className="block text-2xl text-danger">{errors}</strong><span className="text-sm text-muted">ошибок</span></div>
    </section>
    <ResourceFeedback error={query.error ?? retry.error} />
    {query.isPending ? <p className="py-12 text-center text-muted" role="status">Проверяем доставку...</p> : null}
    <div className="space-y-3">{items.map((item) => <DeliveryCard item={item} key={item.messageId ?? `missing-${item.recipientId}`} onRetry={() => item.messageId && retry.mutate(item.messageId)} retrying={null !== item.messageId && retry.isPending && retry.variables === item.messageId} timezone={timezone} />)}</div>
    {!query.isPending && !query.error && !items.length ? <p className={`${resourceSurfaceClass} text-center text-muted`}>Сообщений для проверки нет.</p> : null}
    <Button asChild className="w-full" variant="outline"><Link to="/notifications">Готово</Link></Button>
  </ResourceFrame>
}

function DeliveryCard({ item, onRetry, retrying, timezone }: { item: DeliveryReportItem; onRetry: () => void; retrying: boolean; timezone: string }) {
  const recipient = item.contactName ? `${item.clientName} · ${item.contactName}` : item.clientName
  const unavailable = item.status === 'NO_CHANNEL' || item.status === 'NOT_QUEUED'
  const StatusIcon = item.status === 'FAILED' || unavailable ? AlertTriangle : item.status === 'READ' || item.status === 'DELIVERED' ? CheckCheck : item.status === 'SENT' ? Check : item.status === 'PROCESSING' ? Send : Clock3
  const label = item.status === 'NO_CHANNEL' ? 'Канал не подключён' : item.status === 'NOT_QUEUED' ? 'Сообщение не поставлено в очередь' : item.status === 'FAILED' ? 'Ошибка отправки' : item.status === 'READ' ? 'Прочитано' : item.status === 'DELIVERED' ? 'Доставлено' : item.status === 'SENT' ? 'Отправлено' : item.status === 'PROCESSING' ? 'Отправляется' : 'Ожидает отправки'

  return <article className={resourceSurfaceClass}>
    <div className="flex items-start gap-3"><span className={`grid size-10 shrink-0 place-items-center rounded-full ${item.status === 'FAILED' || unavailable ? 'bg-danger/10 text-danger' : 'bg-success/10 text-success'}`}><StatusIcon className="size-5" /></span><div className="min-w-0 flex-1"><strong className="block truncate">{recipient}</strong><span className="text-sm text-muted">{item.provider ?? 'Нет канала'}{item.firstStartsAt ? ` · ${formatNumericDateTime(item.firstStartsAt, timezone)}` : ''}</span><p className={`mt-2 text-sm font-medium ${item.status === 'FAILED' || unavailable ? 'text-danger' : 'text-success'}`}>{label}</p></div></div>
    {item.status === 'SENT' && !item.capabilities.supportsDeliveredStatus ? <p className="mt-3 rounded-xl bg-muted/10 p-3 text-sm text-muted">Канал не сообщает статус доставки или прочтения.</p> : null}
    <p className="mt-3 text-sm text-muted">Ответ клиента: {item.businessResponse ?? 'не запрашивался'}</p>
    {item.lastError ? <p className="mt-2 text-sm text-danger">{item.lastError}</p> : null}
    {item.status === 'FAILED' ? <div className="mt-4 flex gap-2"><Button disabled={retrying} onClick={onRetry} size="compact"><RefreshCw className="size-4" />{retrying ? 'Повторяем...' : 'Повторить'}</Button>{item.phone ? <Button asChild size="compact" variant="outline"><a href={`tel:${item.phone}`}><Phone className="size-4" />Позвонить</a></Button> : null}</div> : null}
  </article>
}
