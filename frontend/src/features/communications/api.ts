import { useQuery } from '@tanstack/react-query'

import { useAuth } from '@/features/auth/AuthProvider'
import { apiRequest } from '@/shared/api/request'

export type MessageTemplateType = 'CONFIRMATION' | 'TRANSFER' | 'FREE_WINDOW'

export interface MessageTemplate {
  type: MessageTemplateType
  body: string
  isDefault: boolean
}

export interface ChannelCapabilities {
  supportsButtons: boolean
  supportsMessageEdit: boolean
  supportsDeliveredStatus: boolean
  supportsReadStatus: boolean
  supportsDeepLink: boolean
}

export interface ChannelSettings {
  defaultProvider: 'MAX' | 'TELEGRAM' | 'WHATSAPP'
  providers: Array<{
    provider: 'MAX' | 'TELEGRAM' | 'WHATSAPP'
    capabilities: ChannelCapabilities
  }>
}

export function useCommunicationsKey() {
  const { user } = useAuth()
  return ['communications', user?.organization.id] as const
}

export function useMessageTemplates() {
  const key = useCommunicationsKey()
  return useQuery({
    queryKey: [...key, 'templates'],
    queryFn: ({ signal }) => apiRequest<MessageTemplate[]>('/api/communications/templates', 'GET', undefined, signal),
  })
}

export function useChannelSettings() {
  const key = useCommunicationsKey()
  return useQuery({
    queryKey: [...key, 'channels'],
    queryFn: ({ signal }) => apiRequest<ChannelSettings>('/api/communications/channels', 'GET', undefined, signal),
  })
}
