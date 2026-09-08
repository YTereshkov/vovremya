import { useQuery } from '@tanstack/react-query'

import { useAuth } from '@/features/auth/AuthProvider'
import { apiRequest } from '@/shared/api/request'

export interface RegularScheduleDay {
  id: string
  weekday: number
  startTime: string
  durationMinutes: number
}

export interface ScheduleGenerationIssue {
  id: string
  scheduleId: string
  dayId: string
  date: string
  code: string
  message: string
  status: 'OPEN' | 'RESOLVED'
}

export interface RegularScheduleRecord {
  id: string
  specialist: { id: string; name: string; specialization: string }
  client: { id: string; name: string }
  service: {
    id: string
    name: string
    defaultDurationMinutes: number
    minimumDurationMinutes: number | null
    maximumDurationMinutes: number | null
  }
  startsOn: string
  endsOn: string | null
  active: boolean
  days: RegularScheduleDay[]
  issues: ScheduleGenerationIssue[]
}

export interface RegularScheduleInput {
  specialistId: string
  clientId: string
  serviceId: string
  startsOn: string
  endsOn: string | null
  days: Array<{ weekday: number; startTime: string; durationMinutes: number }>
}

export interface RegularScheduleConflictPayload {
  kind?: 'HARD_CONFLICT'
  message?: string
  conflicts?: Array<{ date: string; time: string; code: string; message: string }>
}

export function createRegularSchedule(input: RegularScheduleInput): Promise<RegularScheduleRecord> {
  return apiRequest<RegularScheduleRecord>('/api/regular-schedules', 'POST', input)
}

export function endRegularSchedule(id: string, fromDate: string): Promise<RegularScheduleRecord> {
  return apiRequest<RegularScheduleRecord>(`/api/regular-schedules/${id}/end`, 'POST', { fromDate })
}

export function endRegularScheduleDay(scheduleId: string, dayId: string, fromDate: string): Promise<RegularScheduleRecord> {
  return apiRequest<RegularScheduleRecord>(`/api/regular-schedules/${scheduleId}/days/${dayId}/end`, 'POST', { fromDate })
}

export function replaceRegularScheduleDay(scheduleId: string, dayId: string, input: { fromDate: string; startTime: string; durationMinutes: number }): Promise<RegularScheduleRecord> {
  return apiRequest<RegularScheduleRecord>(`/api/regular-schedules/${scheduleId}/days/${dayId}`, 'PATCH', input)
}

export function retryScheduleGenerationIssue(id: string): Promise<ScheduleGenerationIssue> {
  return apiRequest<ScheduleGenerationIssue>(`/api/schedule-generation-issues/${id}/retry`, 'POST', {})
}

export function useRegularSchedule(id: string) {
  const { user } = useAuth()
  return useQuery({
    queryKey: ['regular-schedules', user?.organization.id, id],
    queryFn: ({ signal }) => apiRequest<RegularScheduleRecord>(`/api/regular-schedules/${id}`, 'GET', undefined, signal),
    enabled: Boolean(id),
  })
}
