import { CloudOff, Eye } from 'lucide-react'

import { formatDate, shiftDate, todayInTimezone } from '@/features/calendar/date'
import type { OfflineScheduleSnapshot } from '@/features/offline/storage'

export function OfflineNotice({ snapshot, timezone }: { snapshot: OfflineScheduleSnapshot | null; timezone: string }) {
  const syncedAt = snapshot ? new Date(snapshot.syncedAt) : null
  const today = todayInTimezone(timezone)
  const syncedParts = syncedAt ? new Intl.DateTimeFormat('en-US', { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: timezone }).formatToParts(syncedAt) : null
  const syncedDate = syncedParts ? `${syncedParts.find((part) => part.type === 'year')?.value}-${syncedParts.find((part) => part.type === 'month')?.value}-${syncedParts.find((part) => part.type === 'day')?.value}` : null
  const time = syncedAt ? new Intl.DateTimeFormat('ru-RU', { hour: '2-digit', minute: '2-digit', timeZone: timezone }).format(syncedAt) : null
  const when = syncedDate === today ? `сегодня в ${time}` : syncedAt ? `${new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long', timeZone: timezone }).format(syncedAt)} в ${time}` : null
  const expectedRange = snapshot?.from === shiftDate(today, -7) && snapshot.to === shiftDate(today, 30)

  return <div className="mb-6">
    <div className="flex items-center gap-4 rounded-lg border border-primary/15 bg-primary-soft/70 p-4 text-ink">
      <CloudOff aria-hidden="true" className="size-8 shrink-0 text-primary" strokeWidth={1.7} />
      <div><strong className="block font-semibold">Нет подключения к интернету</strong><p className="mt-1 text-sm text-muted">{when ? `Данные обновлены ${when}` : 'Сохранённой копии расписания пока нет'}</p></div>
    </div>
    {snapshot ? <p className="mt-4 text-sm text-muted">{expectedRange ? 'Доступно: 7 прошедших дней, сегодня и 30 дней вперёд' : `Доступно: ${formatDate(snapshot.from, { day: 'numeric', month: 'long' })} — ${formatDate(snapshot.to, { day: 'numeric', month: 'long', year: 'numeric' })}`}</p> : null}
  </div>
}

export function ReadOnlyNotice() {
  return <p className="mt-6 flex items-center gap-3 rounded-lg border border-primary/15 bg-primary-soft/70 px-4 py-3 text-sm text-muted"><Eye aria-hidden="true" className="size-5 shrink-0 text-primary" />Только просмотр. Изменения доступны после подключения.</p>
}
