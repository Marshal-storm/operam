/**
 * Cliente HTTP aislado del Portal del Cliente (RF-7 / RNF-7 / RNF-15 / UI-11).
 *
 * Reglas de aislamiento dual de sesiones:
 * - Dedicado EXCLUSIVAMENTE a llamadas `/api/portal/*`.
 * - Jamás lee ni adjunta el token del Panel Interno (`AuthContext` / `localStorage` / `sessionStorage`).
 * - Usa únicamente el JWT `client_portal` mantenido en memoria de React (`tokenGetter`)
 *   y envía `credentials: 'include'` para la cookie HttpOnly enjaulada en `Path=/api/portal`.
 * - Nunca acepta éxitos falsos: parseo seguro de JSON + Error si `!response.ok` o `success !== true`.
 * - Un 401 del portal solo notifica al contexto del portal y jamás cierra la sesión del Panel Interno.
 */

const PORTAL_API_BASE_URL = (
  import.meta.env.VITE_API_URL ??
  import.meta.env.VITE_API_BASE_URL ??
  'http://localhost:8080/api'
).replace(/\/$/, '')

const DEFAULT_PORTAL_COMM_ERROR = 'Error de comunicación con el servidor'

type PortalApiEnvelope<T = unknown> = {
  success?: boolean
  message?: string
  data?: T
  error_id?: string
}

export class PortalApiError extends Error {
  status?: number
  errorId?: string

  constructor(message: string, status?: number, errorId?: string) {
    super(message)
    this.name = 'PortalApiError'
    this.status = status
    this.errorId = errorId
  }
}

type PortalTokenGetter = () => string | null
type PortalUnauthorizedHandler = () => void

let getPortalTokenInMemory: PortalTokenGetter = () => null
let onPortalUnauthorized: PortalUnauthorizedHandler = () => undefined

/**
 * Registra el lector del JWT en memoria de React y el manejador de 401 exclusivo del portal.
 */
export function configurePortalApiClient(
  tokenGetter: PortalTokenGetter,
  unauthorizedHandler: PortalUnauthorizedHandler,
): void {
  getPortalTokenInMemory = tokenGetter
  onPortalUnauthorized = unauthorizedHandler
}

/**
 * Parsea la respuesta del portal exigiendo JSON válido + HTTP OK + `success === true`.
 */
async function parsePortalApiResponse<T>(
  response: Response,
  hadPortalBearer: boolean,
): Promise<T> {
  const rawText = await response.text()

  if (response.status === 401 && hadPortalBearer) {
    onPortalUnauthorized()
  }

  let parsed: PortalApiEnvelope<T> | null = null
  try {
    const trimmed = rawText.trim()
    if (trimmed !== '') {
      parsed = JSON.parse(trimmed) as PortalApiEnvelope<T>
    }
  } catch {
    throw new PortalApiError(DEFAULT_PORTAL_COMM_ERROR, response.status)
  }

  if (parsed === null || typeof parsed !== 'object') {
    throw new PortalApiError(DEFAULT_PORTAL_COMM_ERROR, response.status)
  }

  // Éxito únicamente con HTTP 2xx y envelope explícito `success === true`.
  if (!response.ok || parsed.success !== true) {
    const message =
      (typeof parsed.message === 'string' && parsed.message.trim() !== ''
        ? parsed.message
        : null) ?? DEFAULT_PORTAL_COMM_ERROR
    throw new PortalApiError(
      parsed.error_id ? `${message} (Ref: ${parsed.error_id})` : message,
      response.status,
      parsed.error_id,
    )
  }

  return parsed.data as T
}

/**
 * Ejecuta una petición HTTP estrictamente bajo `/api/portal/*` con aislamiento total de sesión.
 */
export async function portalApiFetch<T>(path: string, init: RequestInit = {}): Promise<T> {
  const normalizedPath = path.startsWith('/') ? path : `/${path}`
  if (normalizedPath !== '/portal' && !normalizedPath.startsWith('/portal/')) {
    throw new PortalApiError('El cliente del portal solo puede consultar rutas /api/portal/*.', 403)
  }

  const portalToken = getPortalTokenInMemory()
  const hadPortalBearer = Boolean(portalToken)

  const headers: Record<string, string> = {
    Accept: 'application/json',
    ...(init.headers as Record<string, string> | undefined),
  }
  if (portalToken) {
    headers.Authorization = `Bearer ${portalToken}`
  }
  if (init.body && !headers['Content-Type']) {
    headers['Content-Type'] = 'application/json'
  }

  let response: Response
  try {
    response = await fetch(`${PORTAL_API_BASE_URL}${normalizedPath}`, {
      ...init,
      headers,
      credentials: 'include',
    })
  } catch {
    throw new PortalApiError('No se pudo conectar con el portal.', 0)
  }

  return parsePortalApiResponse<T>(response, hadPortalBearer)
}
