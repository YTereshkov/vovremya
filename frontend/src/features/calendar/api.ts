import { useQuery } from '@tanstack/react-query'

import { useAuth } from '@/features/auth/AuthProvider'
import { apiRequest } from '@/shared/api/request'

export interface CalendarAppointment {
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
  durationMinutes: number
  date: string
  startTime: string
  endTime: string
  startsAt: string
  endsAt: string
  confirmationStatus: ConfirmationStatus
}

export type ConfirmationStatus = 'NOT_REQUESTED' | 'PENDING' | 'CONFIRMED' | 'CANNOT_ATTEND' | 'NO_RESPONSE'

export interface AppointmentConfirmation {
  status: ConfirmationStatus
  requestId: string | null
}

export interface CalendarResponse {
  timezone: string
  from: string
  to: string
  appointments: CalendarAppointment[]
}

export function useCalendar(from: string, to: string, specialistId = '') {
  const { user } = useAuth()
  return useQuery({
    queryKey: ['calendar', user?.organization.id, from, to, specialistId],
    queryFn: ({ signal }) => {
      const query = new URLSearchParams({ from, to })
      if (specialistId) query.set('specialistId', specialistId)
      return apiRequest<CalendarResponse>(`/api/calendar?${query}`, 'GET', undefined, signal)
    },
  })
}

export function useAppointment(id: string) {
  const { user } = useAuth()
  return useQuery({
    queryKey: ['calendar', user?.organization.id, 'appointment', id],
    queryFn: ({ signal }) => apiRequest<CalendarAppointment>(`/api/appointments/${id}`, 'GET', undefined, signal),
    enabled: Boolean(id),
  })
}

export function useAppointmentConfirmation(id: string) {
  const { user } = useAuth()
  return useQuery({
    queryKey: ['calendar', user?.organization.id, 'appointment', id, 'confirmation'],
    queryFn: ({ signal }) => apiRequest<AppointmentConfirmation>(`/api/appointments/${id}/confirmation`, 'GET', undefined, signal),
    enabled: Boolean(id),
  })
}
