import {
  Clock,
  ClipboardList,
  CreditCard,
  FileText,
  Home,
  LogOut,
  Moon,
  Store,
  Sun,
  type LucideIcon,
} from 'lucide-react'
import { NavLink, Navigate, Outlet, useLocation } from 'react-router-dom'
import { AppButton } from '@/components/ui/AppButton'
import {
  AppModal,
  AppModalContent,
  AppModalDescription,
  AppModalFooter,
  AppModalHeader,
  AppModalTitle,
} from '@/components/ui/AppModal'
import { PortalAmbientBackground } from '@/components/portal/PortalAmbientBackground'
import { PortalScreenSkeleton } from '@/components/portal/PortalStates'
import { PORTAL_TOUCH_TARGET_CLASS } from '@/components/portal/portalTokens'
import { useTheme } from '@/context/ThemeContext'
import { usePortalAuth } from '@/context/PortalAuthContext'
import { initialsOf } from '@/lib/portalLabels'
import { cn } from '@/lib/utils'

type PortalNavItem = { to: string; label: string; icon: LucideIcon; end?: boolean }

/** Navegación oficial del portal: Inicio · Contratos · Pagos · Tienda · Solicitudes. */
const NAV_ITEMS: PortalNavItem[] = [
  { to: '/portal', label: 'Inicio', icon: Home, end: true },
  { to: '/portal/contratos', label: 'Contratos', icon: FileText },
  { to: '/portal/pagos', label: 'Pagos', icon: CreditCard },
  { to: '/portal/tienda', label: 'Tienda', icon: Store },
  { to: '/portal/solicitudes', label: 'Solicitudes', icon: ClipboardList },
]

function ThemeButton() {
  const { resolvedTheme, toggleLightDark } = useTheme()
  const isDark = resolvedTheme === 'dark'
  return (
    <AppButton
      type="button"
      variant="ghost"
      size="icon"
      className={cn('rounded-full', PORTAL_TOUCH_TARGET_CLASS)}
      onClick={toggleLightDark}
      aria-label={isDark ? 'Cambiar a tema claro' : 'Cambiar a tema oscuro'}
    >
      {isDark ? <Sun className="h-[18px] w-[18px]" /> : <Moon className="h-[18px] w-[18px]" />}
    </AppButton>
  )
}

function Avatar({ name }: { name: string }) {
  return (
    <span
      aria-hidden
      className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[linear-gradient(135deg,#0066FF,#7A3FC4)] text-xs font-semibold text-white shadow-[0_4px_12px_rgba(0,102,255,0.35)]"
    >
      {initialsOf(name)}
    </span>
  )
}

/**
 * Layout del Portal del Cliente: píldora flotante en escritorio y dock estilo iOS en móvil.
 */
export function PortalLayout() {
  const { profile, logout, inactivityWarningSeconds, stayConnected } = usePortalAuth()
  const contactName = profile?.contact_name || profile?.client_name || 'Cliente'

  return (
    <div className="relative min-h-screen bg-[#F5F7FB] font-sans text-[#090D16] dark:bg-black dark:text-zinc-50">
      <PortalAmbientBackground />

      {/* Escritorio: píldora flotante iOS Liquid Glass */}
      <header className="sticky top-3 z-40 mx-auto mt-3 hidden max-w-5xl items-center justify-between gap-4 rounded-full px-3 py-2 ios-liquid-glass md:flex">
        <p className="pl-3 font-heading text-sm font-semibold tracking-[-0.4px]">
          Operam <span className="text-slate-400 dark:text-zinc-500">·</span>{' '}
          <span className="text-slate-500 dark:text-zinc-400">Mi suscripción</span>
        </p>

        <nav aria-label="Navegación del portal" className="flex items-center gap-1">
          {NAV_ITEMS.map(({ to, label, end }) => (
            <NavLink
              key={to}
              to={to}
              end={end}
              className={({ isActive }) =>
                cn(
                  'rounded-full px-4 py-2 text-sm font-medium transition',
                  isActive
                    ? 'bg-[#0066FF] text-white shadow-[0_4px_14px_rgba(0,102,255,0.35)] dark:bg-[#0A84FF]'
                    : 'text-slate-600 hover:bg-slate-900/[0.05] dark:text-zinc-300 dark:hover:bg-white/[0.08]',
                )
              }
            >
              {label}
            </NavLink>
          ))}
        </nav>

        <div className="flex items-center gap-1.5">
          <ThemeButton />
          <Avatar name={contactName} />
          <AppButton
            type="button"
            variant="ghost"
            size="sm"
            className="h-9 rounded-full px-3"
            onClick={() => void logout()}
          >
            <LogOut className="h-4 w-4" /> Salir
          </AppButton>
        </div>
      </header>

      {/* Móvil: píldora flotante superior iOS Liquid Glass */}
      <header className="sticky top-3 z-40 mx-3 mt-3 flex h-14 items-center justify-between gap-3 rounded-full px-4 ios-liquid-glass md:hidden">
        <p className="font-heading text-sm font-semibold tracking-[-0.4px]">Operam</p>
        <div className="flex items-center gap-1">
          <ThemeButton />
          <Avatar name={contactName} />
          <AppButton
            type="button"
            variant="ghost"
            size="icon"
            className={cn('rounded-full', PORTAL_TOUCH_TARGET_CLASS)}
            onClick={() => void logout()}
            aria-label="Salir del portal"
          >
            <LogOut className="h-[18px] w-[18px]" />
          </AppButton>
        </div>
      </header>

      <main className="relative z-10 mx-auto w-full max-w-5xl px-4 pb-28 pt-5 md:px-6 md:pb-14 md:pt-8">
        <Outlet />
      </main>

      {/* Móvil: dock flotante iOS Liquid Glass */}
      <nav
        aria-label="Navegación del portal"
        className="fixed inset-x-3 bottom-3 z-40 grid grid-cols-5 gap-1 rounded-[28px] p-1.5 pb-[max(0.375rem,env(safe-area-inset-bottom))] ios-liquid-glass md:hidden"
      >
        {NAV_ITEMS.map(({ to, label, icon: Icon, end }) => (
          <NavLink
            key={to}
            to={to}
            end={end}
            className={({ isActive }) =>
              cn(
                'flex min-h-[52px] flex-col items-center justify-center gap-0.5 rounded-[20px] text-[10px] font-medium transition',
                isActive
                  ? 'bg-[#0066FF]/12 text-[#0066FF] dark:bg-[#0A84FF]/20 dark:text-[#0A84FF]'
                  : 'text-slate-500 dark:text-zinc-400',
              )
            }
          >
            <Icon className="h-[22px] w-[22px]" aria-hidden />
            {label}
          </NavLink>
        ))}
      </nav>

      {/* Aviso preventivo de inactividad (últimos 45s cuando timeout >= 2 min) */}
      <AppModal
        open={inactivityWarningSeconds !== null}
        onOpenChange={(open) => {
          if (!open) void stayConnected()
        }}
      >
        <AppModalContent className="max-w-sm">
          <AppModalHeader>
            <AppModalTitle className="flex items-center gap-2">
              <Clock className="h-4 w-4 text-amber-500" />
              Sesión por expirar
            </AppModalTitle>
            <AppModalDescription>
              Tu sesión se cerrará en{' '}
              <strong className="font-mono tabular-nums">
                {inactivityWarningSeconds ?? 0} s
              </strong>{' '}
              por inactividad.
            </AppModalDescription>
          </AppModalHeader>
          <AppModalFooter className="flex-wrap justify-end gap-2">
            <AppButton type="button" variant="outline" onClick={() => void logout()}>
              Cerrar sesión
            </AppButton>
            <AppButton type="button" onClick={() => void stayConnected()}>
              Seguir conectado
            </AppButton>
          </AppModalFooter>
        </AppModalContent>
      </AppModal>
    </div>
  )
}

/**
 * Guardia de rutas del portal: sin sesión redirige a `/portal/acceso` (nunca a `/login` del ERP).
 */
export function PortalProtectedRoute() {
  const { status } = usePortalAuth()
  const location = useLocation()

  if (status === 'loading') {
    return (
      <div className="min-h-screen bg-[#F5F7FB] px-4 py-8 dark:bg-black">
        <div className="mx-auto max-w-5xl">
          <PortalScreenSkeleton />
        </div>
      </div>
    )
  }
  if (status === 'anonymous') {
    return <Navigate to="/portal/acceso" replace state={{ from: location.pathname }} />
  }
  return <PortalLayout />
}
