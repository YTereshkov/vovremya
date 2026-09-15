import { useQuery, useQueryClient } from '@tanstack/react-query'

import { useAuth } from '@/features/auth/AuthProvider'
import type { AuthUser } from '@/features/auth/api/auth'
import { type CalendarResponse } from '@/features/calendar/api'
import { shiftDate, todayInTimezone } from '@/features/calendar/date'
import { type Specialist } from '@/features/workforce/api'
import { apiRequest } from '@/shared/api/request'
import { useConnectivity } from '@/shared/lib/connectivity'
import { readOfflineSnapshot, saveOfflineSnapshot, type OfflineScheduleSnapshot } from '@/features/offline/storage'

async function fetchRange(from: string, to: string, signal: AbortSignal): Promise<CalendarResponse> {
  return apiRequest<CalendarResponse>(`/api/calendar?${new URLSearchParams({ from, to })}`, 'GET', undefined, signal)
}

async function synchronize(identity: AuthUser, today: string, signal: AbortSignal): Promise<OfflineScheduleSnapshot> {
  const from = shiftDate(today, -7)
  const to = shiftDate(today, 30)
  const nextDay = shiftDate(today, 1)
  const [past, future, specialists] = await Promise.all([
    fetchRange(from, today, signal),
    fetchRange(nextDay, to, signal),
    apiRequest<Specialist[]>('/api/specialists', 'GET', undefined, signal),
  ])
  const currentUser = await apiRequest<AuthUser>('/api/me', 'GET', undefined, signal)
  if (currentUser.id !== identity.id || currentUser.organization.id !== identity.organization.id) {
    throw new Error('Кабинет изменился. Обновите расписание после повторного входа.')
  }
  if (past.timezone !== identity.organization.timezone || future.timezone !== identity.organization.timezone
    || past.from !== from || past.to !== today || future.from !== nextDay || future.to !== to) {
    throw new Error('Не удалось проверить копию расписания.')
  }
  const snapshot: OfflineScheduleSnapshot = {
    administratorId: identity.id,
    organizationId: identity.organization.id,
    timezone: identity.organization.timezone,
    from,
    to,
    syncedAt: new Date().toISOString(),
    appointments: [...past.appointments, ...future.appointments],
    specialists,
  }
  await saveOfflineSnapshot(snapshot)
  return snapshot
}

export function OfflineSnapshotSync() {
  const { user } = useAuth()
  const connected = useConnectivity()
  const queryClient = useQueryClient()
  const today = todayInTimezone(user?.organization.timezone ?? 'UTC')
  useQuery({
    queryKey: ['calendar', user?.organization.id, 'offline-snapshot', today],
    queryFn: async ({ signal }) => {
      const snapshot = await synchronize(user!, today, signal)
      await queryClient.invalidateQueries({ queryKey: ['offline-snapshot', user!.organization.id, user!.id] })
      return snapshot
    },
    enabled: connected && Boolean(user),
    staleTime: 0,
    refetchInterval: 5 * 60 * 1000,
  })
  return null
}

export function useOfflineSnapshot() {
  const { user } = useAuth()
  const connected = useConnectivity()
  return useQuery({
    queryKey: ['offline-snapshot', user?.organization.id, user?.id],
    queryFn: () => readOfflineSnapshot(user!),
    enabled: !connected && Boolean(user),
    networkMode: 'always',
    staleTime: Infinity,
  })
}
