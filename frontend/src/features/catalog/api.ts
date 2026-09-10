import { useQuery } from '@tanstack/react-query'

import { useAuth } from '@/features/auth/AuthProvider'
import { apiRequest } from '@/shared/api/request'

export interface ServiceDefinition {
  id: string
  name: string
  defaultDurationMinutes: number
  minimumDurationMinutes: number | null
  maximumDurationMinutes: number | null
  confirmationTemplate: string | null
}

export type ServiceInput = Omit<ServiceDefinition, 'id'>

export function useCatalogKey() {
  const { user } = useAuth()
  return ['catalog', user?.organization.id] as const
}

export function useServices() {
  const key = useCatalogKey()
  return useQuery({
    queryKey: [...key, 'services'],
    queryFn: ({ signal }) => apiRequest<ServiceDefinition[]>('/api/services', 'GET', undefined, signal),
  })
}

export function useService(id: string) {
  const key = useCatalogKey()
  return useQuery({
    queryKey: [...key, 'services', id],
    queryFn: ({ signal }) => apiRequest<ServiceDefinition>(`/api/services/${id}`, 'GET', undefined, signal),
    enabled: Boolean(id),
  })
}
