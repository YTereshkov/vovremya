import { ArrowLeftRight, CalendarDays, ChevronLeft, ChevronRight, MessageCircleMore, UsersRound } from 'lucide-react'
import { useState, type ReactNode } from 'react'

import { useAuth } from '@/features/auth/AuthProvider'
import { useStatistics } from '@/features/reporting/api'
import { resourceFieldClass, ResourceFeedback, resourceSurfaceClass } from '@/shared/ui/ResourceLayout'

export function StatisticsPage() {
  const { user } = useAuth()
  const [month, setMonth] = useState(() => currentMonth(user?.organization.timezone ?? 'UTC'))
  const [specialistId, setSpecialistId] = useState<string | null>(null)
  const query = useStatistics(month, specialistId)
  const data = query.data

  return <section className="mx-auto min-h-screen w-full max-w-[1306px] px-5 pb-28 pt-[calc(env(safe-area-inset-top)+36px)] sm:px-8 lg:px-7 lg:py-8">
    <h1 className="text-[32px] font-semibold leading-tight lg:text-[36px]">Статистика</h1>
    <div className="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <MonthPicker month={month} onChange={setMonth} />
      {data && data.specialists.length > 1 ? <label className="sm:w-64"><span className="sr-only">Специалист</span><select className={resourceFieldClass} onChange={(event) => setSpecialistId(event.target.value || null)} value={specialistId ?? ''}><option value="">Все специалисты</option>{data.specialists.map((specialist) => <option key={specialist.id} value={specialist.id}>{specialist.name}</option>)}</select></label> : null}
    </div>

    <ResourceFeedback error={query.error} />
    {query.isPending ? <p className="py-16 text-center text-muted" role="status">Считаем статистику...</p> : null}
    {data ? <div className="mt-5 grid gap-5 lg:grid-cols-2">
      <StatisticsCard className="lg:col-span-2" icon={<CalendarDays />} title="Занятия">
        <div className="grid gap-5 xl:grid-cols-[280px_minmax(0,1fr)]">
          <div className="grid grid-cols-2 gap-3">
            <PrimaryMetric label="Запланировано" value={data.appointments.planned} />
            <PrimaryMetric label="Проведено" tone="success" value={data.appointments.conducted} />
          </div>
          <div className="grid content-start divide-y divide-border sm:grid-cols-2 sm:gap-x-8 sm:divide-y-0 xl:grid-cols-4">
            <Metric label="Отменено клиентами заранее" value={data.appointments.cancelledByClientEarly} />
            <Metric label="Поздние отмены клиентов" tone="warning" value={data.appointments.cancelledByClientLate} />
            <Metric label="Отменено специалистами" value={data.appointments.cancelledBySpecialist} />
            <Metric label="Неявки" tone="danger" value={data.appointments.noShows} />
          </div>
        </div>
      </StatisticsCard>

      <StatisticsCard icon={<ArrowLeftRight />} title="Изменения расписания">
        <MetricList><Metric label="Запросов переноса" value={data.scheduleChanges.transferRequests} /><Metric label="Успешно перенесено" tone="success" value={data.scheduleChanges.successfulTransfers} /></MetricList>
      </StatisticsCard>

      <StatisticsCard icon={<MessageCircleMore />} title="Подтверждения">
        <MetricList><Metric label="Подтверждено через мессенджер" tone="success" value={data.confirmations.confirmed} /><Metric label="Ответа нет" tone="warning" value={data.confirmations.noResponse} /><Metric label="Неявки после подтверждения" tone="danger" value={data.confirmations.noShowsAfterConfirmation} /><Metric label="Неявки без подтверждения" value={data.confirmations.noShowsWithoutConfirmation} /></MetricList>
      </StatisticsCard>

      <StatisticsCard className="lg:col-span-2" icon={<UsersRound />} title="Лист ожидания">
        <div className="grid gap-5 lg:grid-cols-[1fr_1fr_2fr] lg:items-center">
          <Metric label="Освободилось окон" value={data.waitingList.freeWindows} />
          <Metric label="Заполнено из ожидания" tone="success" value={data.waitingList.filledFromWaiting} />
          <WaitingProgress filled={data.waitingList.filledFromWaiting} total={data.waitingList.freeWindows} />
        </div>
      </StatisticsCard>
    </div> : null}
  </section>
}

function MonthPicker({ month, onChange }: { month: string; onChange: (month: string) => void }) {
  return <div className="flex h-11 items-center overflow-hidden rounded-lg border border-border bg-surface-raised shadow-surface">
    <button aria-label="Предыдущий месяц" className="grid h-full w-11 place-items-center text-muted hover:bg-primary-soft hover:text-primary" onClick={() => onChange(shiftMonth(month, -1))} type="button"><ChevronLeft className="size-5" /></button>
    <div className="flex min-w-0 flex-1 items-center justify-center gap-2 border-x border-border px-4 text-sm font-medium sm:min-w-48"><CalendarDays className="size-4 text-primary" /><span>{monthLabel(month)}</span></div>
    <button aria-label="Следующий месяц" className="grid h-full w-11 place-items-center text-muted hover:bg-primary-soft hover:text-primary" onClick={() => onChange(shiftMonth(month, 1))} type="button"><ChevronRight className="size-5" /></button>
  </div>
}

function StatisticsCard({ title, icon, className = '', children }: { title: string; icon: ReactNode; className?: string; children: ReactNode }) {
  return <article className={`${resourceSurfaceClass} ${className}`}><h2 className="flex items-center gap-3 text-xl font-semibold"><span className="grid size-10 place-items-center rounded-lg bg-primary-soft text-primary [&>svg]:size-5">{icon}</span>{title}</h2><div className="mt-5">{children}</div></article>
}

function PrimaryMetric({ label, value, tone = 'primary' }: { label: string; value: number; tone?: 'primary' | 'success' }) {
  return <div className={`rounded-lg p-4 ${tone === 'success' ? 'bg-success/10 text-success' : 'bg-primary-soft text-primary'}`}><strong className="block text-[32px] font-semibold leading-none tabular-nums">{value}</strong><span className="mt-2 block text-sm text-ink">{label}</span></div>
}

function MetricList({ children }: { children: ReactNode }) {
  return <div className="divide-y divide-border">{children}</div>
}

function Metric({ label, value, tone = 'default' }: { label: string; value: number; tone?: 'default' | 'success' | 'warning' | 'danger' }) {
  const color = tone === 'success' ? 'text-success' : tone === 'warning' ? 'text-warning' : tone === 'danger' ? 'text-danger' : 'text-ink'
  return <div className="flex min-h-16 items-center justify-between gap-4 py-3 sm:block"><span className="text-sm text-muted">{label}</span><strong className={`block text-2xl font-semibold tabular-nums sm:mt-1 ${color}`}>{value}</strong></div>
}

function WaitingProgress({ filled, total }: { filled: number; total: number }) {
  const percentage = total > 0 ? Math.min(100, Math.round(filled / total * 100)) : 0
  return <div><div className="mb-2 flex items-center justify-between gap-4 text-sm"><span className="text-muted">Заполнено окон</span><strong>{filled} из {total}</strong></div><div aria-label={`Заполнено ${filled} из ${total}`} aria-valuemax={total} aria-valuemin={0} aria-valuenow={filled} className="h-2.5 overflow-hidden rounded-full bg-border" role="progressbar"><div className="h-full rounded-full bg-success" style={{ width: `${percentage}%` }} /></div></div>
}

function currentMonth(timezone: string): string {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit' }).formatToParts(new Date())
  const value = Object.fromEntries(parts.map((part) => [part.type, part.value]))
  return `${value.year}-${value.month}`
}

function shiftMonth(month: string, delta: number): string {
  const [year, value] = month.split('-').map(Number)
  const date = new Date(Date.UTC(year, value - 1 + delta, 1))
  return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`
}

function monthLabel(month: string): string {
  const value = new Intl.DateTimeFormat('ru-RU', { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${month}-01T00:00:00Z`)).replace(' г.', '')
  return value[0].toUpperCase() + value.slice(1)
}
