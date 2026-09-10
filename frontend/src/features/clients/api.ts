import { useQuery } from '@tanstack/react-query'

import { useAuth } from '@/features/auth/AuthProvider'
import { apiRequest } from '@/shared/api/request'

export type ClientType = 'CHILD' | 'ADULT'
export type ChannelProvider = 'MAX' | 'TELEGRAM' | 'WHATSAPP'
export type RecipientType = 'CLIENT' | 'CONTACT_PERSON'

export interface ContactPerson {
  id: string
  name: string
  phone: string | null
}

export interface ChannelConnection {
  id: string
  provider: ChannelProvider
  address: string
  recipientType: RecipientType
  recipientId: string
  recipientName: string
  primary: boolean
  status: 'PENDING' | 'ACTIVE' | 'DISABLED'
  activationExpiresAt: string | null
}

export interface ClientAbsence {
  id: string
  startsOn: string
  endsOn: string
  reason: string | null
  mode: 'KEEP_PERMANENT_PLACE' | 'RELEASE_PERMANENT_PLACE'
  createFreeWindows: boolean
  notifyClient: boolean
}

export interface ClientRecord {
  id: string
  name: string
  type: ClientType
  phone: string | null
  note: string | null
  primaryChannelId: string | null
  contacts: ContactPerson[]
  channels: ChannelConnection[]
  absences: ClientAbsence[]
}

export interface ClientInput {
  name: string
  type: ClientType
  phone: string | null
  note: string | null
}

export function useClientsKey() {
  const { user } = useAuth()
  return ['clients', user?.organization.id] as const
}

export function useClients(search: string) {
  const key = useClientsKey()
  return useQuery({
    queryKey: [...key, 'list', search],
    queryFn: ({ signal }) => apiRequest<ClientRecord[]>(`/api/clients?search=${encodeURIComponent(search)}`, 'GET', undefined, signal),
  })
}

export function useClient(id: string) {
  const key = useClientsKey()
  return useQuery({
    queryKey: [...key, id],
    queryFn: ({ signal }) => apiRequest<ClientRecord>(`/api/clients/${id}`, 'GET', undefined, signal),
    enabled: Boolean(id),
  })
}
