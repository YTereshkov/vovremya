import { useQuery } from '@tanstack/react-query'

import { getHealth } from '@/features/health/api/getHealth'
import { markConnected, useConnectivity } from '@/shared/lib/connectivity'

export function HealthProbe() {
  const connected = useConnectivity()
  const health = useQuery({
    queryKey: ['backend-health'],
    queryFn: async () => {
      const result = await getHealth()
      markConnected()
      return result
    },
    networkMode: 'always',
    refetchInterval: connected ? false : 5_000,
    retry: false,
  })

  const state = health.isSuccess ? 'ok' : health.isError ? 'error' : 'loading'

  return (
    <span className="sr-only" data-state={state} data-testid="backend-status" role="status">
      {state === 'ok' ? 'Backend доступен' : state === 'error' ? 'Backend недоступен' : 'Проверка backend'}
    </span>
  )
}
