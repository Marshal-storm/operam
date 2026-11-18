/**
 * Cierre de sesión diferido tras un cambio crítico de rol/segmento/permisos propios.
 */
export function scheduleCriticalRelogin(options: {
  warning: (title: string, description?: string) => void
  logout: () => void
  navigateToLogin: () => void
  delayMs?: number
}): void {
  const delay = options.delayMs ?? 1200
  options.warning(
    'Cambio crítico de seguridad aplicado. Cerrando sesión para actualizar tus permisos y alcance...',
  )
  window.setTimeout(() => {
    options.logout()
    options.navigateToLogin()
  }, delay)
}
