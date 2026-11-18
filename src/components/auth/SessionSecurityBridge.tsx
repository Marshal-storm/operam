import { useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '@/context/AuthContext'
import { useToast } from '@/context/ToastContext'
import {
  configureUnauthorizedHandler,
  resetUnauthorizedArm,
} from '@/services/sessionSecurity'

/**
 * Escucha 401 reales (sesión revocada/expirada en sesion_activa) y fuerza re-login.
 * No actúa durante hidratación ni ante 404/500/errores de ruta.
 */
export function SessionSecurityBridge() {
  const toast = useToast()
  const { logout, isAuthenticated, isLoading } = useAuth()
  const navigate = useNavigate()

  useEffect(() => {
    if (isLoading || !isAuthenticated) {
      configureUnauthorizedHandler(null)
      resetUnauthorizedArm()
      return
    }

    configureUnauthorizedHandler(() => {
      toast.warning(
        'Tus permisos o alcance de segmento fueron actualizados. Inicia sesión nuevamente.',
      )
      logout()
      navigate('/login', { replace: true })
    })

    return () => {
      configureUnauthorizedHandler(null)
    }
  }, [isAuthenticated, isLoading, logout, navigate, toast])

  return null
}
