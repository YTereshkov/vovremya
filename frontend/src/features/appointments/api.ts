import { apiRequest } from '@/shared/api/request'

export type AppointmentWarningCode = 'LUNCH_OVERLAP' | 'SHORT_BREAK'

export interface AppointmentWarning {
  code: AppointmentWarningCode
  title: string
  message: string
  details: Record<string, string | number>
}

export interface AppointmentErrorPayload {
  kind?: 'SOFT_WARNING' | 'HARD_CONFLICT'
  message?: string
  warnings?: AppointmentWarning[]
  conflict?: { code: string; message: string }
}

export interface AppointmentInput {
  specialistId: string
  clientId: string
  serviceId: string
  date: string
  startTime: string
  durationMinutes: number
  acceptedWarnings: AppointmentWarningCode[]
}

export interface AppointmentRecord {
  id: string
  specialistId: string
  clientId: string
  serviceId: string
  service: {
    name: string
    defaultDurationMinutes: number
    minimumDurationMinutes: number | null
    maximumDurationMinutes: number | null
  }
  durationMinutes: number
  startsAt: string
  endsAt: string
}

export function createAppointment(input: AppointmentInput): Promise<AppointmentRecord> {
  return apiRequest<AppointmentRecord>('/api/appointments', 'POST', input)
}
