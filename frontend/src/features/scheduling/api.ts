import { useQuery } from '@tanstack/react-query'

import { useAuth } from '@/features/auth/AuthProvider'
import { apiRequest } from '@/shared/api/request'

export interface SchedulingSettings {
  lateCancellationHours: number
}

export function useSchedulingSettings() {
  const { user } = useAuth()
  return useQuery({
    queryKey: ['scheduling-settings', user?.organization.id],
    queryFn: ({ signal }) => apiRequest<SchedulingSettings>('/api/scheduling/settings', 'GET', undefined, signal),
  })
}
