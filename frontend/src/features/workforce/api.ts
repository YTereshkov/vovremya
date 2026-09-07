import { useQuery } from '@tanstack/react-query'
import { useAuth } from '@/features/auth/AuthProvider'
import { apiRequest } from '@/shared/api/request'

export interface Interval { start: string; end: string }
export interface Weekday { weekday: number; enabled: boolean; work: Interval | null; lunch: Interval | null }
export interface AdditionalDay { id: string; date: string; work: Interval }
export interface Specialist {
  id: string
  name: string
  specialization: string
  administratorId: string | null
  weeklyHours: Weekday[]
  additionalDays: AdditionalDay[]
  today: string
  todayIntervals: Interval[]
}
export interface ProfileInput { name: string; specialization: string; administratorId: string | null }

export async function workforceRequest<T>(path: string, method = 'GET', data?: unknown, signal?: AbortSignal): Promise<T> {
  return apiRequest<T>(`/api/specialists${path}`, method, data, signal)
}

export function useWorkforceKey() {
  const { user } = useAuth()
  return ['workforce', user?.organization.id, user?.id] as const
}

export function useSpecialists() {
  const key = useWorkforceKey()
  return useQuery({ queryKey: [...key, 'list'], queryFn: ({ signal }) => workforceRequest<Specialist[]>('', 'GET', undefined, signal), staleTime: 0 })
}

export function useSpecialist(id: string) {
  const key = useWorkforceKey()
  return useQuery({ queryKey: [...key, id], queryFn: ({ signal }) => workforceRequest<Specialist>(`/${id}`, 'GET', undefined, signal), staleTime: 0 })
}
