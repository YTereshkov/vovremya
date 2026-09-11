import { useQuery } from '@tanstack/react-query'

import { useAuth } from '@/features/auth/AuthProvider'
import { apiRequest } from '@/shared/api/request'

export interface WaitingAvailability {
  weekday: number
  startTime: string
  endTime: string | null
}

export interface WaitingListEntry {
  id: string
  clientId: string
  service: { id: string; name: string; durationMinutes: number; active: boolean }
  specialist: { id: string; name: string } | null
  requiredFrequency: number
  readyForOneOff: boolean
  effectiveFrom: string
  comment: string | null
  availability: WaitingAvailability[]
}

export interface WaitingListInput {
  serviceId: string
  specialistId: string | null
  requiredFrequency: number
  readyForOneOff: boolean
  comment: string | null
  availability: WaitingAvailability[]
}

export interface FreeWindowCandidates {
  moveEarlier: Array<{
    appointmentId: string
    client: { id: string; name: string }
    currentStartsAt: string
    proposedStartsAt: string
  }>
  waitingClients: Array<{
    waitingListEntryId: string
    client: { id: string; name: string }
    availability: string
  }>
}

export interface FreeWindowOffer {
  id: string
  client: { id: string; name: string }
  targetType: 'MOVE_EARLIER' | 'WAITING_LIST'
  status: 'ACTIVE'
  createdAt: string
}

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
  activeOffer: FreeWindowOffer | null
}

export function useFreeWindows() {
  const { user } = useAuth()
  return useQuery({
    queryKey: ['free-windows', user?.organization.id],
    queryFn: ({ signal }) => apiRequest<FreeWindow[]>('/api/free-windows', 'GET', undefined, signal),
  })
}

export function useWaitingList(clientId: string) {
  const { user } = useAuth()
  return useQuery({
    queryKey: ['waiting-list', user?.organization.id, clientId],
    queryFn: ({ signal }) => apiRequest<WaitingListEntry | null>(`/api/clients/${clientId}/waiting-list`, 'GET', undefined, signal),
    enabled: Boolean(clientId),
  })
}

export function saveWaitingList(clientId: string, input: WaitingListInput) {
  return apiRequest<WaitingListEntry>(`/api/clients/${clientId}/waiting-list`, 'PUT', input)
}

export function endWaitingList(clientId: string) {
  return apiRequest<void>(`/api/clients/${clientId}/waiting-list`, 'DELETE')
}

export function useFreeWindowCandidates(windowId: string, enabled: boolean) {
  const { user } = useAuth()
  return useQuery({
    queryKey: ['free-window-candidates', user?.organization.id, windowId],
    queryFn: ({ signal }) => apiRequest<FreeWindowCandidates>(`/api/free-windows/${windowId}/candidates`, 'GET', undefined, signal),
    enabled: Boolean(windowId) && enabled,
  })
}

export function createFreeWindowOffer(windowId: string, targetType: FreeWindowOffer['targetType'], candidateId: string) {
  return apiRequest<FreeWindowOffer>(`/api/free-windows/${windowId}/offers`, 'POST', { targetType, candidateId })
}

export function cancelFreeWindowOffer(offerId: string) {
  return apiRequest<void>(`/api/free-window-offers/${offerId}`, 'DELETE')
}
