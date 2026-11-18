/**
 * Puente global de seguridad de sesión (401 real + auto-logout crítico).
 * Lo configura SessionSecurityBridge dentro del árbol React.
 *
 * Reglas:
 * - NUNCA disparar logout por 404/500/422/red.
 * - NUNCA disparar durante hidratación (guard desarmado).
 * - Solo 401 con Bearer enviado (sesión revocada/expirada), no "faltó token" por race.
 */

type UnauthorizedHandler = (detail?: { reason?: string }) => void

let unauthorizedHandler: UnauthorizedHandler | null = null
let unauthorizedArmed = false
/** Si false, se ignoran 401 (bootstrap / sin sesión). */
let guardEnabled = false

export function configureUnauthorizedHandler(handler: UnauthorizedHandler | null): void {
  unauthorizedHandler = handler
}

/** Activa escucha de 401 reales tras hidratar sesión. */
export function armUnauthorizedGuard(): void {
  guardEnabled = true
}

/** Desactiva escucha (logout, bootstrap, sin token). */
export function disarmUnauthorizedGuard(): void {
  guardEnabled = false
}

export function isUnauthorizedGuardArmed(): boolean {
  return guardEnabled
}

/**
 * Notifica sesión inválida/revocada.
 * @param hadBearer - true solo si la petición SÍ envió Authorization: Bearer …
 */
export function notifyUnauthorized(hadBearer = true): void {
  if (!guardEnabled || !hadBearer || unauthorizedArmed || !unauthorizedHandler) {
    return
  }
  unauthorizedArmed = true
  try {
    unauthorizedHandler({ reason: 'session_revoked_or_expired' })
  } finally {
    window.setTimeout(() => {
      unauthorizedArmed = false
    }, 1500)
  }
}

export function resetUnauthorizedArm(): void {
  unauthorizedArmed = false
}
