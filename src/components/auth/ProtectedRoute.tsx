import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '@/context/AuthContext'
import { Skeleton } from '@/components/ui/skeleton'

/**
 * Barrera de autenticación: sin sesión válida no hay Dashboard ni módulos.
 */
export function ProtectedRoute() {
  const { isAuthenticated, isLoading } = useAuth()
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

  return <Outlet />
}
