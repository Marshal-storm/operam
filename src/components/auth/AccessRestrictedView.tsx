import { ShieldOff } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/button'

/**
 * Vista elegante cuando el rol no tiene puede_ver en el módulo.
 */
export function AccessRestrictedView() {
  const navigate = useNavigate()

  return (
    <section className="glass-panel flex min-h-[60vh] flex-col items-center justify-center gap-4 p-8 text-center">
      <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-red-500/10 text-[#EF4444]">
        <ShieldOff className="h-7 w-7" />
      </div>
      <h1 className="font-heading text-2xl font-semibold text-[#090D16] dark:text-white">
        Acceso restringido por tu rol
      </h1>
      <p className="max-w-md text-sm text-slate-500 dark:text-zinc-400">
        No tienes permiso para ver este módulo. Si crees que es un error, solicita acceso a un
        administrador en Configuración → Usuarios, roles y permisos.
      </p>
      <Button
        type="button"
        className="mt-2 rounded-xl bg-[#0066FF] text-white dark:bg-[#0A84FF]"
        onClick={() => navigate('/dashboard')}
      >
        Volver al inicio
      </Button>
    </section>
  )
}
