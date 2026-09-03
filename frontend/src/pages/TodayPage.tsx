import { Bell, CheckCircle2, ChevronRight, Clock3, MinusCircle, RefreshCcw } from 'lucide-react'

type AppointmentStatus = 'confirmed' | 'awaiting' | 'transfer' | 'unrequested'

interface Appointment {
  client: string
  service: string
  status: AppointmentStatus
  time: string
}

const appointments: Appointment[] = [
  { time: '09:00', client: 'Иван Петров', service: 'Логопедическое занятие', status: 'confirmed' },
  { time: '10:00', client: 'Мария Сидорова', service: 'Диагностика', status: 'awaiting' },
  { time: '11:00', client: 'Алексей Петров', service: 'Логопедическое занятие', status: 'confirmed' },
  { time: '12:30', client: 'Анна Сидорова', service: 'Логопедическое занятие', status: 'confirmed' },
  { time: '14:00', client: 'Коля Иванов', service: 'Диагностика', status: 'confirmed' },
  { time: '15:00', client: 'Петя Иванов', service: 'Логопедическое занятие', status: 'transfer' },
  { time: '16:30', client: 'Маша Петрова', service: 'Логопедическое занятие', status: 'awaiting' },
  { time: '18:00', client: 'Антон Сидоров', service: 'Логопедическое занятие', status: 'unrequested' },
]

const statusView = {
  confirmed: { label: 'Подтверждено', icon: CheckCircle2, className: 'text-success' },
  awaiting: { label: 'Ожидает ответа', icon: Clock3, className: 'text-warning' },
  transfer: { label: 'Запрос переноса', icon: RefreshCcw, className: 'text-danger' },
  unrequested: { label: 'Не запрашивалось', icon: MinusCircle, className: 'text-muted' },
} satisfies Record<AppointmentStatus, { label: string; icon: typeof CheckCircle2; className: string }>

function AppointmentRow({ appointment }: { appointment: Appointment }) {
  const status = statusView[appointment.status]
  const StatusIcon = status.icon

  return (
    <button
      className="grid min-h-[61px] w-full grid-cols-[64px_minmax(0,1fr)_auto_20px] items-center gap-2 rounded-xl border border-border bg-white/60 px-2.5 text-left transition-colors hover:bg-white focus-visible:relative focus-visible:z-10 focus-visible:outline-2 focus-visible:outline-primary sm:grid-cols-[72px_minmax(0,1fr)_auto_20px] sm:px-3 lg:min-h-[82px] lg:grid-cols-[88px_minmax(0,1fr)_auto_24px] lg:gap-3 lg:rounded-none lg:border-0 lg:border-b lg:px-5 lg:last:border-b-0"
      type="button"
    >
      <span className="border-r border-border py-1 text-[12px] font-medium sm:text-[14px] lg:text-[18px]">{appointment.time}</span>
      <span className="min-w-0">
        <span className="block truncate text-[12px] font-semibold sm:text-[14px] lg:text-[17px]">{appointment.client}</span>
        <span className="mt-0.5 block truncate text-[10px] text-muted sm:text-[12px] lg:mt-1 lg:text-[15px]">{appointment.service}</span>
      </span>
      <span aria-label={status.label} className={`flex items-center gap-1.5 text-[10px] font-medium sm:text-[12px] lg:gap-2 lg:text-[14px] ${status.className}`}>
        <StatusIcon aria-hidden="true" className="size-[18px] shrink-0 lg:size-6" strokeWidth={1.8} />
        <span className="hidden whitespace-nowrap min-[390px]:inline">{status.label}</span>
      </span>
      <ChevronRight aria-hidden="true" className="size-5 text-muted lg:size-6" strokeWidth={1.8} />
    </button>
  )
}

function SummaryCard() {
  return (
    <section aria-label="Сводка занятий" className="rounded-xl border border-border bg-white/62 px-4 py-3 shadow-surface sm:px-5 lg:rounded-2xl lg:px-7 lg:py-5">
      <div className="flex items-baseline gap-3">
        <strong className="text-[29px] font-semibold leading-none tracking-[-0.04em] lg:text-[42px]">8</strong>
        <span className="text-[16px] font-semibold lg:text-[21px]">занятий</span>
      </div>
      <div className="mt-3 grid grid-cols-3 divide-x divide-border lg:mt-6">
        <div className="pr-3 text-success">
          <div className="flex items-center gap-1.5 lg:gap-2">
            <CheckCircle2 aria-hidden="true" className="size-[18px] lg:size-6" strokeWidth={1.8} />
            <strong className="text-[15px] lg:text-xl">5</strong>
          </div>
          <div className="mt-0.5 text-[10px] text-muted lg:mt-1 lg:text-sm">подтверждены</div>
        </div>
        <div className="px-3 text-warning">
          <div className="flex items-center gap-1.5 lg:gap-2">
            <Clock3 aria-hidden="true" className="size-[18px] lg:size-6" strokeWidth={1.8} />
            <strong className="text-[15px] lg:text-xl">2</strong>
          </div>
          <div className="mt-0.5 text-[10px] text-muted lg:mt-1 lg:text-sm">ожидает ответа</div>
        </div>
        <div className="pl-3 text-muted">
          <div className="flex items-center gap-1.5 lg:gap-2">
            <MinusCircle aria-hidden="true" className="size-[18px] lg:size-6" strokeWidth={1.8} />
            <strong className="text-[15px] lg:text-xl">1</strong>
          </div>
          <div className="mt-0.5 text-[10px] text-muted lg:mt-1 lg:text-sm">не запрашивалось</div>
        </div>
      </div>
    </section>
  )
}

function AttentionPanel() {
  return (
    <aside className="hidden rounded-2xl border border-border bg-white/50 p-4 xl:block">
      <h2 className="px-1 text-[18px] font-semibold">Требуют внимания</h2>
      <div className="mt-4 overflow-hidden rounded-xl border border-border bg-white/65">
        {appointments
          .filter(({ status }) => status === 'transfer' || status === 'awaiting')
          .map((appointment) => (
            <AppointmentRow appointment={appointment} key={`${appointment.time}-${appointment.client}`} />
          ))}
      </div>
    </aside>
  )
}

export function TodayPage() {
  return (
    <div className="mx-auto w-full max-w-[1306px] px-[18px] pb-8 pt-[calc(env(safe-area-inset-top)+18px)] sm:px-7 lg:px-7 lg:py-8">
      <header className="flex items-start justify-between px-1 lg:hidden">
        <div>
          <h1 className="text-[21px] font-semibold leading-tight tracking-[-0.035em]">Vovremya</h1>
          <p className="mt-1 text-[14px] text-muted">Сегодня, 3 сентября</p>
        </div>
        <button
          aria-label="Уведомления: 3"
          className="relative mt-1 flex size-11 items-center justify-center rounded-full text-muted focus-visible:outline-2 focus-visible:outline-primary"
          type="button"
        >
          <Bell aria-hidden="true" className="size-7" strokeWidth={1.8} />
          <span className="absolute right-0 top-0 flex size-5 items-center justify-center rounded-full bg-primary text-[11px] font-semibold text-white">3</span>
        </button>
      </header>

      <div className="hidden lg:block">
        <h1 className="text-[36px] font-semibold leading-tight tracking-[-0.025em]">Сегодня</h1>
        <p className="mt-1 text-[17px] text-muted">3 сентября</p>
      </div>

      <div className="mt-5 grid gap-6 lg:mt-7 xl:grid-cols-[minmax(0,1fr)_385px]">
        <div className="min-w-0">
          <SummaryCard />
          <h2 className="mb-2 mt-6 px-1 text-[18px] font-semibold tracking-[-0.02em] lg:mb-4 lg:mt-7 lg:text-[25px]">Сегодня</h2>
          <section aria-label="Занятия на сегодня" className="flex flex-col gap-px lg:block lg:overflow-hidden lg:rounded-2xl lg:border lg:border-border lg:shadow-surface">
            {appointments.slice(0, 4).map((appointment) => (
              <AppointmentRow appointment={appointment} key={`${appointment.time}-${appointment.client}`} />
            ))}
            <div className="grid min-h-[61px] grid-cols-[84px_minmax(0,1fr)_20px] items-center gap-2 rounded-xl border border-border bg-white/50 px-2.5 sm:px-3 lg:min-h-[82px] lg:grid-cols-[112px_minmax(0,1fr)_24px] lg:gap-3 lg:rounded-none lg:border-0 lg:border-b lg:px-5">
              <span className="text-[12px] font-medium sm:text-[14px] lg:text-[16px]">13:00–14:00</span>
              <span className="text-[12px] font-medium lg:text-[15px]">Обед</span>
              <ChevronRight aria-hidden="true" className="size-5 text-muted lg:size-6" strokeWidth={1.8} />
            </div>
            {appointments.slice(4).map((appointment) => (
              <AppointmentRow appointment={appointment} key={`${appointment.time}-${appointment.client}`} />
            ))}
          </section>
        </div>
        <AttentionPanel />
      </div>
    </div>
  )
}
