/** Roles empresariales internos. */
export type UserRole = 'admin' | 'operador' | 'lectura'

/** Usuario autenticado expuesto al frontend (sin password_hash). */
export type AuthUser = {
  id: number
  email: string
  nombre: string
  rol: UserRole
  scope: 'enterprise'
}

export type AuthSession = {
  token: string
  tokenType: 'Bearer'
  expiresIn: number
  user: AuthUser
}

export type LoginCredentials = {
  email: string
  password: string
}

export type AuthDataSourceMode = 'mock' | 'api'
