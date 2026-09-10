import { useQuery } from '@tanstack/react-query'

import { useAuth } from '@/features/auth/AuthProvider'
import { apiRequest } from '@/shared/api/request'

export type ClientAbsenceMode = 'KEEP_PERMANENT_PLACE' | 'RELEASE_PERMANENT_PLACE'
export interface SpecialistAbsenceInput { type: 'VACATION' | 'SICK_LEAVE' | 'OTHER' }

export function useAbsenceImpact(owner: 'specialists' | 'clients', id: string, startsOn: string, endsOn: string) {
  const { user } = useAuth()
  const valid = Boolean(id && startsOn && endsOn && endsOn >= startsOn)
  return useQuery({
    queryKey: ['absence-impact', user?.organization.id, owner, id, startsOn, endsOn],
    queryFn: ({ signal }) => apiRequest<{ appointments: number }>(`/api/${owner}/${id}/absence-impact?startsOn=${encodeURIComponent(startsOn)}&endsOn=${encodeURIComponent(endsOn)}`, 'GET', undefined, signal),
    enabled: valid,
  })
}

export function todayInput(): string {
  const now = new Date()
  const offset = now.getTimezoneOffset() * 60_000
  return new Date(now.getTime() - offset).toISOString().slice(0, 10)
}

export function addDays(value: string, days: number): string {
  const date = new Date(`${value}T12:00:00`)
  date.setDate(date.getDate() + days)
  const offset = date.getTimezoneOffset() * 60_000
  return new Date(date.getTime() - offset).toISOString().slice(0, 10)
}
