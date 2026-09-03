export interface HealthResponse {
  service: string
  status: 'ok'
}

export async function getHealth(): Promise<HealthResponse> {
  const response = await fetch('/health', {
    headers: {
      Accept: 'application/json',
    },
  })

  if (!response.ok) {
    throw new Error(`Health request failed with status ${response.status}`)
  }

  return (await response.json()) as HealthResponse
}
