import { AlertCircle, CheckCircle2, CircleHelp, Clock3, XCircle } from 'lucide-react'

import type { ConfirmationStatus } from '@/features/calendar/api'
import { cn } from '@/shared/lib/cn'

const labels: Record<ConfirmationStatus, string> = {
  NOT_REQUESTED: 'Не запрашивалось',
  PENDING: 'Ожидает ответа',
  CONFIRMED: 'Подтверждено',
  CANNOT_ATTEND: 'Клиент не сможет',
  NO_RESPONSE: 'Нет ответа',
}

const icons = {
  NOT_REQUESTED: CircleHelp,
  PENDING: Clock3,
  CONFIRMED: CheckCircle2,
  CANNOT_ATTEND: XCircle,
  NO_RESPONSE: AlertCircle,
} satisfies Record<ConfirmationStatus, typeof CircleHelp>

export function confirmationStatusLabel(status: ConfirmationStatus) { return labels[status] }

export function ConfirmationStatusBadge({ status, compact = false }: { status: ConfirmationStatus; compact?: boolean }) {
  const Icon = icons[status]
  return <span className={cn('inline-flex items-center gap-1.5 font-medium', compact ? 'text-xs' : 'text-sm', status === 'CONFIRMED' ? 'text-primary' : null, status === 'CANNOT_ATTEND' || status === 'NO_RESPONSE' ? 'text-danger' : 'text-muted')}>
    <Icon aria-hidden="true" className={compact ? 'size-3.5' : 'size-4'} />{labels[status]}
  </span>
}
