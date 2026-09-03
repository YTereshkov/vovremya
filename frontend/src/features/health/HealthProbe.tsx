import { useQuery } from '@tanstack/react-query'

import { getHealth } from '@/features/health/api/getHealth'

export function HealthProbe() {
  const health = useQuery({
    queryKey: ['backend-health'],
    queryFn: getHealth,
  })

  const state = health.isSuccess ? 'ok' : health.isError ? 'error' : 'loading'

  return (
    <span className="sr-only" data-state={state} data-testid="backend-status" role="status">
      {state === 'ok' ? 'Backend доступен' : state === 'error' ? 'Backend недоступен' : 'Проверка backend'}
    </span>
  )
}
