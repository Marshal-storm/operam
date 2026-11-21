import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import {
  fetchCurrentUserProfile,
  loginRequest,
  loadStoredSession,
  persistSession,
  type AuthError,
} from '@/services/authService'
import { configureCatalogAuth } from '@/services/catalogService'
import { configureClientsAuth } from '@/services/clientsService'
import { configureContractsAuth } from '@/services/contractsService'
import { configureDashboardAuth } from '@/services/dashboardService'
import { configureRbacAuth } from '@/services/rbacService'
import { configureSystemAuth } from '@/services/systemService'
import {
  armUnauthorizedGuard,
  disarmUnauthorizedGuard,
} from '@/services/sessionSecurity'
import type { AuthSession, AuthUser, LoginCredentials } from '@/types/auth'

export { usePermissions } from '@/hooks/usePermissions'

type AuthContextValue = {
  user: AuthUser | null
  token: string | null
  isAuthenticated: boolean
  isLoading: boolean
  login: (credentials: LoginCredentials) => Promise<void>
  logout: () => void
}

const AuthContext = createContext<AuthContextValue | null>(null)

/** Sesión hidratada de forma síncrona para que el primer fetch ya lleve Bearer. */
function readInitialSession(): AuthSession | null {
  if (typeof window === 'undefined') return null
  return loadStoredSession()
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [session, setSession] = useState<AuthSession | null>(() => readInitialSession())
  /**
   * Loading solo mientras sincroniza `/me` en frío.
   * La sesión local ya está disponible; no se compara como "permisos modificados".
   */
  const [isHydratingProfile, setIsHydratingProfile] = useState(() => Boolean(readInitialSession()?.token))
  const sessionRef = useRef<AuthSession | null>(session)
  sessionRef.current = session
  const profileSyncDone = useRef(false)

  const logout = useCallback(() => {
    disarmUnauthorizedGuard()
    profileSyncDone.current = false
    setSession(null)
    persistSession(null)
  }, [])

  const getToken = useCallback(() => sessionRef.current?.token ?? null, [])

  // Registra Bearer ANTES de que los useQuery hijos disparen fetch.
  useLayoutEffect(() => {
    configureDashboardAuth(getToken, () => logout())
    configureCatalogAuth(getToken)
    configureClientsAuth(getToken)
    configureContractsAuth(getToken)
    configureRbacAuth(getToken)
    configureSystemAuth(getToken)
  }, [getToken, logout])

  // Soft-refresh de permisos desde MySQL (config_issuers, etc.) sin cerrar sesión.
  useEffect(() => {
    const token = sessionRef.current?.token
    if (!token || profileSyncDone.current) {
      setIsHydratingProfile(false)
      return
    }

    let cancelled = false
    ;(async () => {
      try {
        const freshUser = await fetchCurrentUserProfile(token)
        if (cancelled) return
        setSession((prev) => {
          if (!prev) return prev
          const next: AuthSession = { ...prev, user: freshUser }
          persistSession(next)
          return next
        })
      } catch (error) {
        if (cancelled) return
        const status = (error as AuthError)?.status
        // Solo 401 real con Bearer = sesión revocada/expirada en sesion_activa.
        if (status === 401) {
          // El guard aún puede estar desarmado: logout directo (caso real de revocación).
          disarmUnauthorizedGuard()
          setSession(null)
          persistSession(null)
          return
        }
        // 404/500/red: conservar sesión local; jamás confundir con revocación.
      } finally {
        if (!cancelled) {
          profileSyncDone.current = true
          setIsHydratingProfile(false)
          if (sessionRef.current?.token) {
            armUnauthorizedGuard()
          }
        }
      }
    })()

    return () => {
      cancelled = true
    }
  }, [])

  useEffect(() => {
    if (isHydratingProfile || !session?.token) {
      disarmUnauthorizedGuard()
      return
    }
    armUnauthorizedGuard()
    return () => {
      disarmUnauthorizedGuard()
    }
  }, [isHydratingProfile, session?.token])

  const login = useCallback(async (credentials: LoginCredentials) => {
    try {
      const next = await loginRequest(credentials)
      profileSyncDone.current = true
      setSession(next)
      persistSession(next)
      setIsHydratingProfile(false)
      armUnauthorizedGuard()
    } catch (error) {
      const authError = error as AuthError
      throw authError
    }
  }, [])

  const value = useMemo<AuthContextValue>(
    () => ({
      user: session?.user ?? null,
      token: session?.token ?? null,
      isAuthenticated: Boolean(session?.token),
      // No bloquear la UI con skeleton en F5: sesión local ya es válida.
      isLoading: false,
      login,
      logout,
    }),
    [session, login, logout],
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
