import { useQuery } from '@tanstack/react-query'

import { useAuth } from '@/features/auth/AuthProvider'
import type { AppointmentWarningCode } from '@/features/appointments/api'
import { apiRequest } from '@/shared/api/request'

export interface TransferRequestRecord {
  id: string
  status: 'AWAITING_OPTIONS' | 'OPTIONS_SENT' | 'COMPLETED' | 'DECLINED' | 'CANCELLED'
  newAppointmentId: string | null
  createdAt: string
}

export interface TransferOptionRecord {
  id: string
  startsAt: string
  endsAt: string
  selectedAt: string | null
}

export interface TransferState {
  request: TransferRequestRecord | null
  options: TransferOptionRecord[]
}

export interface TransferOptionInput {
  date: string
  startTime: string
}

export function useTransferState(appointmentId: string) {
  const { user } = useAuth()
  return useQuery({
    queryKey: ['transfers', user?.organization.id, appointmentId],
    queryFn: ({ signal }) => apiRequest<TransferState>(`/api/appointments/${appointmentId}/transfer`, 'GET', undefined, signal),
    enabled: Boolean(appointmentId),
  })
}

export function offerTransferOptions(appointmentId: string, options: TransferOptionInput[], acceptedWarnings: AppointmentWarningCode[]) {
  return apiRequest<TransferState>(`/api/appointments/${appointmentId}/transfer`, 'POST', { options, acceptedWarnings })
}

export function cancelTransfer(requestId: string) {
  return apiRequest<void>(`/api/transfer-requests/${requestId}`, 'DELETE')
}
