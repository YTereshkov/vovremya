import { useQuery } from '@tanstack/react-query'

import { useAuth } from '@/features/auth/AuthProvider'
import { apiRequest } from '@/shared/api/request'

export interface FreeWindow {
  id: string
  sourceAppointmentId: string
  specialistId: string
  service: { id: string; name: string }
  durationMinutes: number
  date: string
  startTime: string
  endTime: string
  startsAt: string
  endsAt: string
  status: 'OPEN'
}

export function useFreeWindows() {
  const { user } = useAuth()
  return useQuery({
    queryKey: ['free-windows', user?.organization.id],
    queryFn: ({ signal }) => apiRequest<FreeWindow[]>('/api/free-windows', 'GET', undefined, signal),
  })
}
