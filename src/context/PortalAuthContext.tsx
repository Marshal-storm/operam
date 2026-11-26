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
  keepalivePortalSession,
  logoutPortal,
} from '@/services/portalService'
import type { PortalProfile, PortalSessionPayload } from '@/types/portal'

export type PortalAuthStatus = 'loading' | 'authenticated' | 'anonymous'

type PortalAuthContextValue = {
  status: PortalAuthStatus
  profile: PortalProfile | null
  /** Segundos restantes cuando entra en aviso preventivo (últimos 45s si timeout >= 2 min). */
  inactivityWarningSeconds: number | null
  /** Renueva manualmente la sesión (`POST /api/portal/auth/keepalive`) y reinicia el reloj. */
  stayConnected: () => Promise<void>
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

const DEFAULT_IDLE_TIMEOUT_MINUTES = 5
const WARNING_THRESHOLD_SECONDS = 45
const ACTIVITY_THROTTLE_MS = 1000
const MAX_KEEPALIVE_INTERVAL_MS = 60_000

/**
 * Sesión sin contraseña del Portal del Cliente (RNF-7 / UI-11) con inactividad deslizante.
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
  const [inactivityWarningSeconds, setInactivityWarningSeconds] = useState<number | null>(null)

  const idleMinutesRef = useRef<number>(DEFAULT_IDLE_TIMEOUT_MINUTES)
  const expiresAtMsRef = useRef<number>(0)
  const lastActivityMsRef = useRef<number>(Date.now())
  const lastKeepaliveMsRef = useRef<number>(Date.now())
  const keepaliveInFlightRef = useRef<boolean>(false)

  const syncExpirationWindow = useCallback((idleMinutes?: number, expiresAtIso?: string) => {
    const minutes =
      typeof idleMinutes === 'number' && Number.isFinite(idleMinutes) && idleMinutes >= 1
        ? Math.trunc(idleMinutes)
        : idleMinutesRef.current
    idleMinutesRef.current = minutes

    const parsedExpires = expiresAtIso ? Date.parse(expiresAtIso) : Number.NaN
    const now = Date.now()
    expiresAtMsRef.current =
      Number.isFinite(parsedExpires) && parsedExpires > now
        ? parsedExpires
        : now + minutes * 60_000
    lastKeepaliveMsRef.current = now
    setInactivityWarningSeconds(null)
  }, [])

  const applySession = useCallback(
    (payload: PortalSessionPayload) => {
      tokenRef.current = payload.token
      setProfile(payload.profile)
      setStatus('authenticated')
      const minutes =
        payload.idle_timeout_minutes ??
        (payload.expires_in > 0 ? Math.max(1, Math.round(payload.expires_in / 60)) : DEFAULT_IDLE_TIMEOUT_MINUTES)
      lastActivityMsRef.current = Date.now()
      syncExpirationWindow(minutes, payload.expires_at)
      return payload.profile
    },
    [syncExpirationWindow],
  )

  const clearSession = useCallback(() => {
    tokenRef.current = null
    expiresAtMsRef.current = 0
    setInactivityWarningSeconds(null)
    setProfile(null)
    setStatus('anonymous')
    // El caché contiene datos financieros del cliente: se descarta solo el namespace `portal`.
    queryClient.removeQueries({ queryKey: ['portal'] })
  }, [queryClient])

  const sendSilentKeepalive = useCallback(async () => {
    if (!tokenRef.current || keepaliveInFlightRef.current) return
    keepaliveInFlightRef.current = true
    try {
      const renewed = await keepalivePortalSession()
      syncExpirationWindow(renewed.idle_timeout_minutes, renewed.expires_at)
    } catch {
      // Si el servidor devolvió 401, `portalApiClient` ya dispara `onPortalUnauthorized`.
    } finally {
      keepaliveInFlightRef.current = false
    }
  }, [syncExpirationWindow])

  const stayConnected = useCallback(async () => {
    lastActivityMsRef.current = Date.now()
    await sendSilentKeepalive()
  }, [sendSilentKeepalive])

  // Lector del token en memoria + manejo de 401 aislado (jamás toca la sesión del Panel Interno).
  useEffect(() => {
    configurePortalApiClient(
      () => tokenRef.current,
      (code) => {
        clearSession()
        if (code === 'PORTAL_SESSION_EXPIRED') {
          toast.info(
            'Tu sesión se cerró por inactividad',
            'Solicita o abre una nueva liga de acceso para continuar.',
          )
        } else {
          toast.warning('Tu sesión terminó', 'Solicita una nueva liga de acceso para continuar.')
        }
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

  // Monitor de interacción real + keepalive silencioso + reloj de aviso preventivo.
  useEffect(() => {
    if (status !== 'authenticated') return

    let lastEventTs = 0
    const onUserActivity = () => {
      const now = Date.now()
      if (now - lastEventTs < ACTIVITY_THROTTLE_MS) return
      lastEventTs = now
      lastActivityMsRef.current = now

      const halfWindowMs = Math.floor((idleMinutesRef.current * 60_000) / 2)
      const keepaliveIntervalMs = Math.min(MAX_KEEPALIVE_INTERVAL_MS, Math.max(15_000, halfWindowMs))
      if (now - lastKeepaliveMsRef.current >= keepaliveIntervalMs) {
        void sendSilentKeepalive()
      }
    }

    const events: Array<keyof WindowEventMap> = ['pointerdown', 'keydown', 'scroll', 'touchstart']
    for (const eventName of events) {
      window.addEventListener(eventName, onUserActivity, { passive: true })
    }

    const timer = window.setInterval(() => {
      if (!tokenRef.current || expiresAtMsRef.current <= 0) return
      const now = Date.now()
      const secondsRemaining = Math.max(0, Math.ceil((expiresAtMsRef.current - now) / 1000))

      if (secondsRemaining <= 0) {
        clearSession()
        toast.info(
          'Tu sesión se cerró por inactividad',
          'Solicita o abre una nueva liga de acceso para continuar.',
        )
        return
      }

      if (idleMinutesRef.current >= 2 && secondsRemaining <= WARNING_THRESHOLD_SECONDS) {
        setInactivityWarningSeconds(secondsRemaining)
      } else {
        setInactivityWarningSeconds((prev) => (prev !== null ? null : prev))
      }
    }, 1000)

    return () => {
      for (const eventName of events) {
        window.removeEventListener(eventName, onUserActivity)
      }
      window.clearInterval(timer)
    }
  }, [status, sendSilentKeepalive, clearSession, toast])

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
    () => ({
      status,
      profile,
      inactivityWarningSeconds,
      stayConnected,
      exchange,
      logout,
    }),
    [status, profile, inactivityWarningSeconds, stayConnected, exchange, logout],
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
