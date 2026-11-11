import type {
  AuthDataSourceMode,
  AuthSession,
  AuthUser,
  LoginCredentials,
} from '@/types/auth'

const API_BASE_URL = (
  import.meta.env.VITE_API_URL ??
  import.meta.env.VITE_API_BASE_URL ??
  'http://localhost:8080/api'
).replace(/\/$/, '')

const STORAGE_KEY = 'operam.auth.session'

/** Credenciales del EnterpriseUsersSeeder (modo Mockup). */
const MOCK_USERS: Array<AuthUser & { password: string }> = [
  {
    id: 1,
    email: 'admin@operam.mx',
    nombre: 'Admin General',
    rol: 'admin',
    scope: 'enterprise',
    password: 'Operam2026*',
  },
  {
    id: 2,
    email: 'operador@operam.mx',
    nombre: 'Operador Operam',
    rol: 'operador',
    scope: 'enterprise',
    password: 'Operam2026*',
  },
  {
    id: 3,
    email: 'supervisor@construsync.mx',
    nombre: 'Supervisor Construsync',
    rol: 'operador',
    scope: 'enterprise',
    password: 'Constru2026*',
  },
]

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
    user: AuthUser
  }
}

function mockLogin(credentials: LoginCredentials): AuthSession {
  const email = credentials.email.trim().toLowerCase()
  const user = MOCK_USERS.find(
    (row) => row.email === email && row.password === credentials.password,
  )

  if (!user) {
    throw new AuthError('Credenciales inválidas.', 401)
  }

  const { password: _password, ...safeUser } = user
  const tokenPayload = btoa(
    JSON.stringify({
      jti: {
        session_uid: crypto.randomUUID(),
        user_id: safeUser.id,
        email: safeUser.email,
        nombre: safeUser.nombre,
        rol: safeUser.rol,
        scope: 'enterprise',
      },
      exp: Math.floor(Date.now() / 1000) + 28_800,
    }),
  )

  return {
    token: `mock.${tokenPayload}.signature`,
    tokenType: 'Bearer',
    expiresIn: 28_800,
    user: safeUser,
  }
}

async function apiLogin(credentials: LoginCredentials): Promise<AuthSession> {
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
    user: payload.data.user,
  }
}

export async function loginRequest(
  credentials: LoginCredentials,
  mode: AuthDataSourceMode,
): Promise<AuthSession> {
  if (mode === 'mock') {
    return mockLogin(credentials)
  }

  return apiLogin(credentials)
}

export function loadStoredSession(): AuthSession | null {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return null
    return JSON.parse(raw) as AuthSession
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

export const DEV_LOGIN_CREDENTIALS: LoginCredentials = {
  email: 'admin@operam.mx',
  password: 'Operam2026*',
}
