import { createContext, useContext, useEffect, useRef, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import {
  getCurrentUser,
  login as loginRequest,
  logout as logoutRequest,
  type AuthUser,
} from '@/features/auth/api/auth'
import { useConnectivity } from '@/shared/lib/connectivity'

interface AuthContextValue {
  user: AuthUser | null
  isLoading: boolean
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()
  const connected = useConnectivity()
  const wasConnected = useRef(connected)
  const userQuery = useQuery({
    queryKey: ['auth', 'me'],
    queryFn: ({ signal }) => getCurrentUser(signal),
    networkMode: 'always',
    retry: false,
    staleTime: 60_000,
  })
  useEffect(() => {
    if (connected && !wasConnected.current) void queryClient.invalidateQueries({ queryKey: ['auth', 'me'] })
    wasConnected.current = connected
  }, [connected, queryClient])
  const loginMutation = useMutation({
    mutationFn: ({ email, password }: { email: string; password: string }) => loginRequest(email, password),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['auth', 'me'] }),
  })
  const logoutMutation = useMutation({
    mutationFn: logoutRequest,
    onSuccess: async () => {
      await Promise.all([
        queryClient.cancelQueries({ queryKey: ['auth', 'me'] }),
        queryClient.cancelQueries({ queryKey: ['calendar'] }),
        queryClient.cancelQueries({ queryKey: ['communications'] }),
      ])
      queryClient.removeQueries({ queryKey: ['calendar'] })
      queryClient.removeQueries({ queryKey: ['offline-snapshot'] })
      queryClient.removeQueries({ queryKey: ['communications'] })
      queryClient.setQueryData(['auth', 'me'], null)
    },
  })

  return (
    <AuthContext.Provider
      value={{
        user: userQuery.data ?? null,
        isLoading: userQuery.isLoading,
        login: (email, password) => loginMutation.mutateAsync({ email, password }),
        logout: async () => {
          await Promise.all([
            queryClient.cancelQueries({ queryKey: ['auth', 'me'] }),
            queryClient.cancelQueries({ queryKey: ['calendar'] }),
            queryClient.cancelQueries({ queryKey: ['communications'] }),
          ])
          await logoutMutation.mutateAsync()
        },
      }}
    >
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext)

  if (!context) {
    throw new Error('useAuth must be used inside AuthProvider')
  }

  return context
}
