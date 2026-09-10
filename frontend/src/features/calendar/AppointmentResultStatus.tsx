import type { AppointmentResultStatus } from '@/features/calendar/api'
import { cn } from '@/shared/lib/cn'

const labels: Record<AppointmentResultStatus, string> = {
  CONDUCTED: 'Проведено',
  CANCELLED_BY_CLIENT: 'Отменено клиентом',
  CANCELLED_BY_SPECIALIST: 'Отменено специалистом',
  NO_SHOW: 'Неявка',
  RESCHEDULED: 'Перенесено',
}

export function appointmentResultLabel(status: AppointmentResultStatus): string {
  return labels[status]
}

export function AppointmentResultBadge({ status, compact = false }: { status: AppointmentResultStatus; compact?: boolean }) {
  const negative = status !== 'CONDUCTED'
  return <span className={cn('inline-flex w-fit items-center rounded-full font-medium', compact ? 'mt-1 px-2 py-0.5 text-[11px]' : 'px-3 py-1 text-sm', negative ? 'bg-danger/10 text-danger' : 'bg-primary-soft text-primary')}>{labels[status]}</span>
}
