import type { ReactNode } from 'react'
import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { AccessRestrictedView } from '@/components/auth/AccessRestrictedView'
import { Skeleton } from '@/components/ui/skeleton'
import { useAuth } from '@/context/AuthContext'
import { usePermissions } from '@/hooks/usePermissions'
import type { ModuleKey } from '@/types/auth'

type ProtectedRouteProps = {
  /** Si se indica, exige el permiso view en ese módulo. */
  requiredModule?: ModuleKey
  children?: ReactNode
}

/**
 * Barrera de autenticación + opcionalmente RBAC por módulo.
 */
export function ProtectedRoute({ requiredModule, children }: ProtectedRouteProps) {
  const { isAuthenticated, isLoading } = useAuth()
  const { canView } = usePermissions()
  const location = useLocation()

  if (isLoading) {
    return (
      <div className="flex min-h-svh items-center justify-center bg-[#F8FAFC] p-6 dark:bg-[#000000]">
        <Skeleton className="h-24 w-full max-w-md rounded-2xl bg-slate-200/80 dark:bg-white/[0.05]" />
      </div>
    )
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" replace state={{ from: location }} />
  }

  if (requiredModule && !canView(requiredModule)) {
    return <AccessRestrictedView />
  }

  if (children) {
    return <>{children}</>
  }

  return <Outlet />
}
