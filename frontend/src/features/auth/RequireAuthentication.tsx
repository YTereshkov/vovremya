import { Navigate, Outlet, useLocation } from 'react-router-dom'

import { useAuth } from '@/features/auth/AuthProvider'
import { useConnectivity } from '@/shared/lib/connectivity'

export function RequireAuthentication() {
  const location = useLocation()
  const { isLoading, user } = useAuth()
  const connected = useConnectivity()

  if (isLoading) {
    return <main className="grid min-h-screen place-items-center text-muted">Проверяем вход...</main>
  }

  if (!user) {
    return <Navigate replace state={{ from: location.pathname }} to="/login" />
  }

  if (!connected && location.pathname !== '/' && location.pathname !== '/calendar') {
    return <Navigate replace to="/calendar" />
  }

  return <Outlet />
}
