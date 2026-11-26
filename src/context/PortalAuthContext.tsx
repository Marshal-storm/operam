import { useQueryClient } from '@tanstack/react-query'
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import { useToast } from '@/context/ToastContext'
import { configurePortalApiClient } from '@/lib/portalApiClient'
import {
  exchangePortalToken,
  fetchPortalSession,
  logoutPortal,
} from '@/services/portalService'
import type { PortalProfile, PortalSessionPayload } from '@/types/portal'

export type PortalAuthStatus = 'loading' | 'authenticated' | 'anonymous'

type PortalAuthContextValue = {
  status: PortalAuthStatus
  profile: PortalProfile | null
  /** Canjea el token de la liga mágica por la sesión del portal (un solo uso). */
  exchange: (token: string) => Promise<PortalProfile>
  logout: () => Promise<void>
}

const PortalAuthContext = createContext<PortalAuthContextValue | null>(null)

/**
 * Canjes en vuelo por token: React StrictMode ejecuta los efectos dos veces en desarrollo y un
 * Magic Link es de UN solo uso, así que ambas ejecuciones comparten la misma promesa.
 */
const inflightExchanges = new Map<string, Promise<PortalSessionPayload>>()

/**
 * Sesión sin contraseña del Portal del Cliente (RNF-7 / UI-11).
 *
 * El JWT se guarda únicamente en memoria de React (`useRef`): NO se usa localStorage ni
 * sessionStorage. Tras un F5 la cookie HttpOnly emitida por la API restaura la sesión con
 * `GET portal/auth/me`.
 */
export function PortalAuthProvider({ children }: { children: ReactNode }) {
  const toast = useToast()
  const queryClient = useQueryClient()
  const tokenRef = useRef<string | null>(null)
  // Se evalúa antes de los efectos hijos porque PortalAccessPage limpia `?t=` con replaceState.
  const hadInitialLinkTokenRef = useRef<boolean>(
    typeof window !== 'undefined' && new URLSearchParams(window.location.search).has('t'),
  )
  const [status, setStatus] = useState<PortalAuthStatus>('loading')
  const [profile, setProfile] = useState<PortalProfile | null>(null)

  const applySession = useCallback((payload: PortalSessionPayload) => {
    tokenRef.current = payload.token
    setProfile(payload.profile)
    setStatus('authenticated')
    return payload.profile
  }, [])

  const clearSession = useCallback(() => {
    tokenRef.current = null
    setProfile(null)
    setStatus('anonymous')
    // El caché contiene datos financieros del cliente: se descarta solo el namespace `portal`.
    queryClient.removeQueries({ queryKey: ['portal'] })
  }, [queryClient])

  // Lector del token en memoria + manejo de 401 aislado (jamás toca la sesión del Panel Interno).
  useEffect(() => {
    configurePortalApiClient(
      () => tokenRef.current,
      () => {
        clearSession()
        toast.warning('Tu sesión terminó', 'Solicita una nueva liga de acceso para continuar.')
      },
    )
  }, [clearSession, toast])

  // Restauración por cookie HttpOnly enjaulada en `/api/portal` (F5). No corre si la URL trae liga.
  useEffect(() => {
    if (hadInitialLinkTokenRef.current || new URLSearchParams(window.location.search).has('t')) {
      setStatus('anonymous')
      return
    }
    let cancelled = false
    fetchPortalSession()
      .then((payload) => {
        if (!cancelled) applySession(payload)
      })
      .catch(() => {
        if (!cancelled) setStatus('anonymous')
      })
    return () => {
      cancelled = true
    }
  }, [applySession])

  const exchange = useCallback(
    async (token: string): Promise<PortalProfile> => {
      let pending = inflightExchanges.get(token)
      if (!pending) {
        pending = exchangePortalToken(token)
        inflightExchanges.set(token, pending)
      }
      const payload = await pending
      return applySession(payload)
    },
    [applySession],
  )

  const logout = useCallback(async () => {
    try {
      await logoutPortal()
    } catch {
      // Aun si la API falla, la sesión local se descarta.
    } finally {
      clearSession()
    }
  }, [clearSession])

  const value = useMemo<PortalAuthContextValue>(
    () => ({ status, profile, exchange, logout }),
    [status, profile, exchange, logout],
  )

  return <PortalAuthContext.Provider value={value}>{children}</PortalAuthContext.Provider>
}

export function usePortalAuth(): PortalAuthContextValue {
  const ctx = useContext(PortalAuthContext)
  if (!ctx) {
    throw new Error('usePortalAuth debe usarse dentro de PortalAuthProvider')
  }
  return ctx
}
