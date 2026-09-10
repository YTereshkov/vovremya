import { CalendarX2, ChevronRight } from 'lucide-react'
import { Link } from 'react-router-dom'

import type { CalendarAppointment } from '@/features/calendar/api'
import { ConfirmationStatusBadge } from '@/features/calendar/ConfirmationStatus'
import { formatDate } from '@/features/calendar/date'
import { cn } from '@/shared/lib/cn'

const gridStartHour = 8
const gridEndHour = 20
const hourHeight = 64

export function AppointmentAgendaItem({ appointment, showSpecialist = false }: { appointment: CalendarAppointment; showSpecialist?: boolean }) {
  return <Link
    aria-label={`${appointment.startTime}, ${appointment.client.name}, ${appointment.service.name}`}
    className="grid min-h-16 grid-cols-[60px_minmax(0,1fr)_20px] items-center gap-3 border-b border-border px-3 py-2.5 last:border-b-0 hover:bg-white focus-visible:relative focus-visible:z-10 focus-visible:outline-2 focus-visible:outline-primary sm:grid-cols-[72px_minmax(0,1fr)_20px]"
    to={`/appointments/${appointment.id}`}
  >
    <span className="border-r border-border pr-3 text-sm font-semibold tabular-nums">{appointment.startTime}</span>
    <span className="min-w-0">
      <span className="block truncate text-sm font-semibold">{appointment.client.name}</span>
      <span className="mt-0.5 block truncate text-xs text-muted">{appointment.service.name}{showSpecialist ? ` · ${appointment.specialist.name}` : ''}</span>
      <ConfirmationStatusBadge compact status={appointment.confirmationStatus} />
    </span>
    <ChevronRight aria-hidden="true" className="size-5 text-muted" />
  </Link>
}

export function EmptySchedule({ compact = false }: { compact?: boolean }) {
  return <div className={cn('grid place-items-center text-center text-muted', compact ? 'min-h-28 p-4' : 'min-h-52 p-8')}>
    <div>
      <CalendarX2 aria-hidden="true" className="mx-auto size-8" strokeWidth={1.6} />
      <p className="mt-3 text-sm">Занятий нет</p>
    </div>
  </div>
}

export function DayAgenda({ appointments, showSpecialist = false }: { appointments: CalendarAppointment[]; showSpecialist?: boolean }) {
  return <section aria-label="Список занятий" className="overflow-hidden rounded-lg border border-border bg-white/75 shadow-surface">
    {appointments.length ? appointments.map((appointment) => <AppointmentAgendaItem appointment={appointment} key={appointment.id} showSpecialist={showSpecialist} />) : <EmptySchedule />}
  </section>
}

export function WeekAgenda({ appointments, dates, showSpecialist = false }: { appointments: CalendarAppointment[]; dates: string[]; showSpecialist?: boolean }) {
  return <div className="space-y-5">
    {dates.map((date) => {
      const dayAppointments = appointments.filter((appointment) => appointment.date === date)
      return <section key={date}>
        <h2 className="mb-2 px-1 text-sm font-semibold capitalize">{formatDate(date, { weekday: 'long', day: 'numeric', month: 'long' })}</h2>
        <div className="overflow-hidden rounded-lg border border-border bg-white/75 shadow-surface">
          {dayAppointments.length ? dayAppointments.map((appointment) => <AppointmentAgendaItem appointment={appointment} key={appointment.id} showSpecialist={showSpecialist} />) : <EmptySchedule compact />}
        </div>
      </section>
    })}
  </div>
}

function minutesFromStart(time: string): number {
  const [hour, minute] = time.split(':').map(Number)
  return (hour - gridStartHour) * 60 + minute
}

function TimedAppointment({ appointment, showSpecialist }: { appointment: CalendarAppointment; showSpecialist: boolean }) {
  const top = Math.max(0, minutesFromStart(appointment.startTime) / 60 * hourHeight)
  const end = Math.min((gridEndHour - gridStartHour) * hourHeight, minutesFromStart(appointment.endTime) / 60 * hourHeight)
  const height = Math.max(38, end - top)
  return <Link
    aria-label={`${appointment.startTime}, ${appointment.client.name}, ${appointment.service.name}`}
    className="absolute inset-x-1 z-10 overflow-hidden rounded-md border border-primary/25 bg-primary-soft px-2 py-1.5 text-xs text-ink shadow-sm hover:border-primary/50 focus-visible:outline-2 focus-visible:outline-primary"
    style={{ height, top }}
    to={`/appointments/${appointment.id}`}
  >
    <strong className="block truncate tabular-nums">{appointment.startTime} {appointment.client.name}</strong>
    <span className="mt-0.5 block truncate text-muted">{appointment.service.name}</span>
    {showSpecialist ? <span className="mt-0.5 block truncate text-primary">{appointment.specialist.name}</span> : null}
  </Link>
}

function TimeRuler() {
  return <div className="border-r border-border bg-white/50">
    <div className="h-14 border-b border-border" />
    <div className="relative" style={{ height: (gridEndHour - gridStartHour) * hourHeight }}>
      {Array.from({ length: gridEndHour - gridStartHour }, (_, index) => <span className="absolute right-2 -translate-y-1/2 text-[11px] tabular-nums text-muted" key={index} style={{ top: index * hourHeight }}>{String(gridStartHour + index).padStart(2, '0')}:00</span>)}
    </div>
  </div>
}

function GridColumn({ appointments, label, showSpecialist }: { appointments: CalendarAppointment[]; label: string; showSpecialist: boolean }) {
  return <div className="min-w-0 border-r border-border last:border-r-0">
    <div className="flex h-14 items-center justify-center border-b border-border px-2 text-center text-xs font-semibold">{label}</div>
    <div className="relative bg-white/65" style={{ height: (gridEndHour - gridStartHour) * hourHeight }}>
      {Array.from({ length: gridEndHour - gridStartHour }, (_, index) => <span aria-hidden="true" className="absolute inset-x-0 border-t border-border/70" key={index} style={{ top: index * hourHeight }} />)}
      {appointments.map((appointment) => <TimedAppointment appointment={appointment} key={appointment.id} showSpecialist={showSpecialist} />)}
    </div>
  </div>
}

export function DesktopDayGrid({ appointments, specialists }: { appointments: CalendarAppointment[]; specialists: Array<{ id: string; name: string }> }) {
  const visibleSpecialists = specialists.length ? specialists : Array.from(new Map(appointments.map(({ specialist }) => [specialist.id, specialist])).values())
  return <section aria-label="Календарь дня" className="overflow-auto rounded-lg border border-border shadow-surface">
    <div className="grid min-w-[720px]" style={{ gridTemplateColumns: `72px repeat(${Math.max(visibleSpecialists.length, 1)}, minmax(180px, 1fr))` }}>
      <TimeRuler />
      {visibleSpecialists.length ? visibleSpecialists.map((specialist) => <GridColumn appointments={appointments.filter((appointment) => appointment.specialist.id === specialist.id)} key={specialist.id} label={specialist.name} showSpecialist={false} />) : <GridColumn appointments={[]} label="Расписание" showSpecialist={false} />}
    </div>
  </section>
}

export function DesktopWeekGrid({ appointments, dates, showSpecialist }: { appointments: CalendarAppointment[]; dates: string[]; showSpecialist: boolean }) {
  return <section aria-label="Календарь недели" className="overflow-auto rounded-lg border border-border shadow-surface">
    <div className="grid min-w-[1120px]" style={{ gridTemplateColumns: '72px repeat(7, minmax(145px, 1fr))' }}>
      <TimeRuler />
      {dates.map((date) => <GridColumn appointments={appointments.filter((appointment) => appointment.date === date)} key={date} label={formatDate(date, { weekday: 'short', day: 'numeric' })} showSpecialist={showSpecialist} />)}
    </div>
  </section>
}
