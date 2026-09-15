import { useQuery } from '@tanstack/react-query'

import { useAuth } from '@/features/auth/AuthProvider'
import { apiRequest } from '@/shared/api/request'

export interface StatisticsSummary {
  month: string
  timezone: string
  selectedSpecialistId: string | null
  specialists: Array<{ id: string; name: string }>
  appointments: {
    planned: number
    conducted: number
    cancelledByClientEarly: number
    cancelledByClientLate: number
    cancelledBySpecialist: number
    noShows: number
  }
  scheduleChanges: { transferRequests: number; successfulTransfers: number }
  confirmations: {
    confirmed: number
    noResponse: number
    noShowsAfterConfirmation: number
    noShowsWithoutConfirmation: number
  }
  waitingList: { freeWindows: number; filledFromWaiting: number }
}

export function useStatistics(month: string, specialistId: string | null) {
  const { user } = useAuth()
  return useQuery({
    queryKey: ['statistics', user?.organization.id, month, specialistId],
    queryFn: ({ signal }) => {
      const search = new URLSearchParams({ month })
      if (specialistId) search.set('specialistId', specialistId)
      return apiRequest<StatisticsSummary>(`/api/statistics?${search}`, 'GET', undefined, signal)
    },
  })
}
