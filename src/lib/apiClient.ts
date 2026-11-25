import { notifyUnauthorized } from '@/services/sessionSecurity'

/**
 * Cliente HTTP central Operam — RNF-15.
 * Nunca interpreta HTML / texto vacío / success≠true como éxito.
 */

export type ApiEnvelope<T = unknown> = {
  success?: boolean
  message?: string
  data?: T
  error_id?: string
  meta?: unknown
}

export class ApiClientError extends Error {
  status?: number
  errorId?: string

  constructor(message: string, status?: number, errorId?: string) {
    super(message)
    this.name = 'ApiClientError'
    this.status = status
    this.errorId = errorId
  }
}

const DEFAULT_COMM_ERROR = 'Error de comunicación con el servidor'

/**
 * Parsea el cuerpo de una respuesta API y exige envelope exitoso.
 * Lanza siempre si: HTML/texto inválido, !response.ok o success !== true.
 */
export async function parseOperamApiResponse<T>(
  response: Response,
  options?: {
    hadBearer?: boolean
    onUnauthorized?: (hadBearer: boolean) => void
  },
): Promise<T> {
  const rawText = await response.text()

  if (response.status === 401) {
    options?.onUnauthorized?.(Boolean(options.hadBearer))
  }

  let parsed: ApiEnvelope<T> | null = null
  try {
    const trimmed = rawText.trim()
    if (trimmed !== '') {
      parsed = JSON.parse(trimmed) as ApiEnvelope<T>
    }
  } catch {
    throw new ApiClientError(DEFAULT_COMM_ERROR, response.status)
  }

  if (parsed === null || typeof parsed !== 'object') {
    throw new ApiClientError(DEFAULT_COMM_ERROR, response.status)
  }

  // Éxito solo con HTTP OK + success === true (nunca asumir por omisión).
  if (!response.ok || parsed.success !== true) {
    const message =
      (typeof parsed.message === 'string' && parsed.message.trim() !== ''
        ? parsed.message
        : null) ?? DEFAULT_COMM_ERROR
    throw new ApiClientError(
      parsed.error_id ? `${message} (Ref: ${parsed.error_id})` : message,
      response.status,
      parsed.error_id,
    )
  }

  return parsed.data as T
}

type TokenGetter = () => string | null

export type ApiFetchFactoryOptions = {
  getBaseUrl: () => string
  getToken: TokenGetter
  /** Si true (default), notifica 401 vía sessionSecurity. */
  notifyOnUnauthorized?: boolean
}

/**
 * Fábrica de `apiFetch` tipado para servicios de dominio.
 * Cada módulo puede envolver el Error en su propia clase si lo necesita.
 */
export function createApiFetch(options: ApiFetchFactoryOptions) {
  return async function apiFetch<T>(
    path: string,
    init?: RequestInit,
  ): Promise<T> {
    const token = options.getToken()
    const hadBearer = Boolean(token)
    const base = options.getBaseUrl().replace(/\/$/, '')
    const normalizedPath = path.startsWith('/') ? path : `/${path}`

    const headers: Record<string, string> = {
      Accept: 'application/json',
      ...(init?.headers as Record<string, string> | undefined),
    }
    if (token) headers.Authorization = `Bearer ${token}`
    if (init?.body && !headers['Content-Type']) {
      headers['Content-Type'] = 'application/json'
    }

    let response: Response
    try {
      response = await fetch(`${base}${normalizedPath}`, { ...init, headers })
    } catch {
      throw new ApiClientError('No se pudo conectar con la API.', 0)
    }

    return parseOperamApiResponse<T>(response, {
      hadBearer,
      onUnauthorized:
        options.notifyOnUnauthorized === false
          ? undefined
          : (had) => notifyUnauthorized(had),
    })
  }
}
