import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import {
  loginRequest,
  loadStoredSession,
  persistSession,
  type AuthError,
} from '@/services/authService'
import { configureDashboardAuth } from '@/services/dashboardService'
import type {
  AuthDataSourceMode,
  AuthSession,
  AuthUser,
  LoginCredentials,
} from '@/types/auth'

type AuthContextValue = {
  user: AuthUser | null
  token: string | null
  isAuthenticated: boolean
  isLoading: boolean
  mode: AuthDataSourceMode
  setMode: (mode: AuthDataSourceMode) => void
  login: (credentials: LoginCredentials) => Promise<void>
  logout: () => void
}

const AuthContext = createContext<AuthContextValue | null>(null)

const MODE_STORAGE_KEY = 'operam.auth.dataSource'

export function AuthProvider({ children }: { children: ReactNode }) {
  const [session, setSession] = useState<AuthSession | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [mode, setModeState] = useState<AuthDataSourceMode>(() => {
    const stored = localStorage.getItem(MODE_STORAGE_KEY)
    return stored === 'api' ? 'api' : 'mock'
  })

  const logout = useCallback(() => {
    setSession(null)
    persistSession(null)
  }, [])

  useEffect(() => {
    const stored = loadStoredSession()
    setSession(stored)
    setIsLoading(false)
  }, [])

  useEffect(() => {
    configureDashboardAuth(
      () => session?.token ?? null,
      () => logout(),
    )
  }, [session, logout])

  const setMode = useCallback((next: AuthDataSourceMode) => {
    setModeState(next)
    localStorage.setItem(MODE_STORAGE_KEY, next)
  }, [])

  const login = useCallback(
    async (credentials: LoginCredentials) => {
      try {
        const next = await loginRequest(credentials, mode)
        setSession(next)
        persistSession(next)
      } catch (error) {
        const authError = error as AuthError
        throw authError
      }
    },
    [mode],
  )

  const value = useMemo<AuthContextValue>(
    () => ({
      user: session?.user ?? null,
      token: session?.token ?? null,
      isAuthenticated: Boolean(session?.token),
      isLoading,
      mode,
      setMode,
      login,
      logout,
    }),
    [session, isLoading, mode, setMode, login, logout],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext)
  if (!ctx) {
    throw new Error('useAuth debe usarse dentro de AuthProvider')
  }
  return ctx
}
