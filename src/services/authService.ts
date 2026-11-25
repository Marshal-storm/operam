import { ApiClientError, parseOperamApiResponse } from '@/lib/apiClient'
import type {
  AuthSession,
  AuthUser,
  LoginCredentials,
  PermissionsMap,
} from '@/types/auth'

const API_BASE_URL = (
  import.meta.env.VITE_API_URL ??
  import.meta.env.VITE_API_BASE_URL ??
  'http://localhost:8080/api'
).replace(/\/$/, '')

const STORAGE_KEY = 'operam.auth.session'

export class AuthError extends Error {
  status?: number

  constructor(message: string, status?: number) {
    super(message)
    this.name = 'AuthError'
    this.status = status
  }
}

type ApiLoginResponse = {
  success: boolean
  message?: string
  data?: {
    token: string
    token_type?: string
    expires_in?: number
    user: Partial<AuthUser> & {
      id: number
      email: string
      name: string
    }
  }
}

function normalizeUser(raw: ApiLoginResponse['data'] extends infer D
  ? D extends { user: infer U }
    ? U
    : never
  : never): AuthUser {
  return {
    id: Number(raw.id),
    email: String(raw.email),
    name: String(raw.name ?? ''),
    role_id: raw.role_id ?? null,
    role_name: raw.role_name ?? null,
    role_code: raw.role_code ?? null,
    segment_id: raw.segment_id ?? null,
    segment_name: raw.segment_name ?? 'Todos',
    permissions: (raw.permissions as PermissionsMap) ?? {},
    can_authorize_exceptions: Boolean(
      (raw as { can_authorize_exceptions?: boolean }).can_authorize_exceptions,
    ),
    scope: 'enterprise',
  }
}

/**
 * Login 100% API real → LoginService (usuario, auth_intento, sesion_activa, JWT HS256 + RBAC).
 */
export async function loginRequest(
  credentials: LoginCredentials,
): Promise<AuthSession> {
  let response: Response
  try {
    response = await fetch(`${API_BASE_URL}/login`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        email: credentials.email.trim().toLowerCase(),
        password: credentials.password,
      }),
    })
  } catch {
    throw new AuthError('No se pudo conectar con el servicio de login.', 0)
  }

  try {
    const data = await parseOperamApiResponse<NonNullable<ApiLoginResponse['data']>>(
      response,
    )
    if (!data?.token || !data.user) {
      throw new AuthError('No se pudo iniciar sesión.', response.status)
    }
    return {
      token: data.token,
      tokenType: 'Bearer',
      expiresIn: data.expires_in ?? 28_800,
      user: normalizeUser(data.user),
    }
  } catch (error) {
    if (error instanceof AuthError) throw error
    if (error instanceof ApiClientError) {
      throw new AuthError(error.message, error.status)
    }
    throw new AuthError('No se pudo iniciar sesión.', response.status)
  }
}

export function loadStoredSession(): AuthSession | null {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw) as AuthSession
    if (!parsed?.user) return null
    return {
      ...parsed,
      user: normalizeUser(parsed.user),
    }
  } catch {
    return null
  }
}

export function persistSession(session: AuthSession | null): void {
  if (!session) {
    localStorage.removeItem(STORAGE_KEY)
    return
  }
  localStorage.setItem(STORAGE_KEY, JSON.stringify(session))
}

/**
 * Refresca el perfil RBAC desde MySQL (`GET /api/me`) sin emitir nuevo JWT.
 * No dispara logout: el caller decide qué hacer ante 401.
 */
export async function fetchCurrentUserProfile(token: string): Promise<AuthUser> {
  let response: Response
  try {
    response = await fetch(`${API_BASE_URL}/me`, {
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
    })
  } catch {
    throw new AuthError('No se pudo sincronizar el perfil de sesión.', 0)
  }

  try {
    const data = await parseOperamApiResponse<{
      user: NonNullable<ApiLoginResponse['data']>['user']
    }>(response)
    if (!data?.user) {
      throw new AuthError('No se pudo sincronizar el perfil.', response.status)
    }
    return normalizeUser(data.user)
  } catch (error) {
    if (error instanceof AuthError) throw error
    if (error instanceof ApiClientError) {
      throw new AuthError(error.message, error.status)
    }
    throw new AuthError('No se pudo sincronizar el perfil.', response.status)
  }
}

/** Credenciales del EnterpriseUsersSeeder (solo autocompletado de formulario). */
export const DEV_LOGIN_CREDENTIALS: LoginCredentials = {
  email: 'admin@operam.mx',
  password: 'Operam2026*',
}
