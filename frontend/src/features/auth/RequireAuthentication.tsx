import { Navigate, Outlet, useLocation } from 'react-router-dom'

import { useAuth } from '@/features/auth/AuthProvider'

export function RequireAuthentication() {
  const location = useLocation()
  const { isLoading, user } = useAuth()

  if (isLoading) {
    return <main className="grid min-h-screen place-items-center text-muted">Проверяем вход...</main>
  }

  if (!user) {
    return <Navigate replace state={{ from: location.pathname }} to="/login" />
  }

  return <Outlet />
}
