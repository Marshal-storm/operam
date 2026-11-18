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
      nombre: string
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
    nombre: String(raw.nombre),
    rol: (raw.rol as AuthUser['rol']) ?? 'operador',
    rol_id: raw.rol_id ?? null,
    rol_nombre: raw.rol_nombre ?? null,
    rol_codigo: raw.rol_codigo ?? null,
    segmento_id: raw.segmento_id ?? null,
    segmento_nombre: raw.segmento_nombre ?? 'Todos',
    permisos: (raw.permisos as PermissionsMap) ?? {},
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

  const payload = (await response.json()) as ApiLoginResponse
  if (!response.ok || !payload.success || !payload.data?.token) {
    throw new AuthError(
      payload.message || 'No se pudo iniciar sesión.',
      response.status,
    )
  }

  return {
    token: payload.data.token,
    tokenType: 'Bearer',
    expiresIn: payload.data.expires_in ?? 28_800,
    user: normalizeUser(payload.data.user),
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

  let payload: ApiLoginResponse = { success: false }
  try {
    payload = (await response.json()) as ApiLoginResponse
  } catch {
    throw new AuthError('Respuesta inválida del perfil de sesión.', response.status)
  }

  if (!response.ok || !payload.success || !payload.data?.user) {
    throw new AuthError(
      payload.message || 'No se pudo sincronizar el perfil.',
      response.status,
    )
  }

  return normalizeUser(payload.data.user)
}

/** Credenciales del EnterpriseUsersSeeder (solo autocompletado de formulario). */
export const DEV_LOGIN_CREDENTIALS: LoginCredentials = {
  email: 'admin@operam.mx',
  password: 'Operam2026*',
}
