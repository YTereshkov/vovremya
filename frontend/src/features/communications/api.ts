import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { useAuth } from '@/features/auth/AuthProvider'
import { apiRequest } from '@/shared/api/request'

export type MessageTemplateType = 'CONFIRMATION' | 'TRANSFER' | 'FREE_WINDOW' | 'PERMANENT_PLACE'

export interface MessageTemplate {
  type: MessageTemplateType
  body: string
  buttons: { confirm: string; cannotAttend: string; transfer: string } | null
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

export interface ConfirmationSettings {
  requestTime: string
  noResponseTime: string
  reminderEnabled: boolean
  reminderLeadMinutes: number
  reminderNotBefore: string
  quietHoursStart: string
  quietHoursEnd: string
}

export interface ConfirmationAttentionItem {
  appointmentId: string
  clientName: string
  startsAt: string
}

export interface NotificationCenterItem {
  id: string
  type: 'confirmation-no-response' | 'transfer-request' | 'transfer-declined' | 'free-window' | 'permanent-place' | 'specialist-absence' | 'delivery-failed'
  title: string
  subtitle: string
  createdAt: string
  href: string
  severity: 'info' | 'success' | 'warning' | 'danger'
  unread: boolean
  metadata: { startsAt?: string; slotCount?: number; messageCount?: number }
}

export interface NotificationCenter {
  items: NotificationCenterItem[]
  unreadCount: number
}

export interface DeliveryReportItem {
  messageId: string | null
  recipientId: string
  provider: 'MAX' | 'TELEGRAM' | 'WHATSAPP' | null
  status: 'NO_CHANNEL' | 'NOT_QUEUED' | 'PENDING' | 'PROCESSING' | 'SENT' | 'DELIVERED' | 'READ' | 'FAILED'
  clientName: string
  contactName: string | null
  phone: string | null
  appointmentCount: number
  firstStartsAt: string | null
  createdAt: string
  sentAt: string | null
  deliveredAt: string | null
  readAt: string | null
  lastError: string | null
  businessResponse: string | null
  capabilities: Pick<ChannelCapabilities, 'supportsDeliveredStatus' | 'supportsReadStatus'>
}

export interface DeliveryReport {
  absenceId: string | null
  items: DeliveryReportItem[]
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

export function useConfirmationSettings() {
  const key = useCommunicationsKey()
  return useQuery({
    queryKey: [...key, 'confirmation-settings'],
    queryFn: ({ signal }) => apiRequest<ConfirmationSettings>('/api/communications/confirmation-settings', 'GET', undefined, signal),
  })
}

export function useConfirmationAttention() {
  const key = useCommunicationsKey()
  return useQuery({
    queryKey: [...key, 'confirmation-attention'],
    queryFn: ({ signal }) => apiRequest<ConfirmationAttentionItem[]>('/api/communications/confirmation-attention', 'GET', undefined, signal),
  })
}

export function useNotificationCenter(enabled = true) {
  const key = useCommunicationsKey()
  return useQuery({
    queryKey: [...key, 'notification-center'],
    queryFn: ({ signal }) => apiRequest<NotificationCenter>('/api/notifications', 'GET', undefined, signal),
    enabled,
  })
}

export function useMarkAllNotificationsRead() {
  const key = useCommunicationsKey()
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: () => apiRequest<{ readThrough: string }>('/api/notifications/read-all', 'PUT', {}),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [...key, 'notification-center'] }),
  })
}

export function useDeliveryReport(absenceId?: string) {
  const key = useCommunicationsKey()
  return useQuery({
    queryKey: [...key, 'delivery-report', absenceId ?? 'failed'],
    queryFn: ({ signal }) => apiRequest<DeliveryReport>(`/api/notifications/delivery${absenceId ? `/${absenceId}` : ''}`, 'GET', undefined, signal),
  })
}

export function useRetryOutboundMessage(absenceId?: string) {
  const key = useCommunicationsKey()
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (messageId: string) => apiRequest<{ id: string; status: 'PENDING' }>(`/api/communications/messages/${messageId}/retry`, 'POST', {}),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: [...key, 'delivery-report', absenceId ?? 'failed'] }),
        queryClient.invalidateQueries({ queryKey: [...key, 'notification-center'] }),
      ])
    },
  })
}
