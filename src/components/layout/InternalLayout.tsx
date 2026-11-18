import {
  ChevronDown,
  ChevronLeft,
  ClipboardList,
  CreditCard,
  HelpCircle,
  LayoutDashboard,
  LogOut,
  Menu,
  Settings,
  Users,
  FileText,
  PieChart,
  UserRound,
  X,
  Tags,
  BadgePercent,
  Server,
  Building2,
  Package,
  Gift,
  SlidersHorizontal,
  Shield,
} from 'lucide-react'
import { useEffect, useMemo, useState, type ComponentType } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { ThemeToggleSwitch } from '@/components/ui/ThemeToggleSwitch'
import { useAuth } from '@/context/AuthContext'
import { usePermissions } from '@/hooks/usePermissions'
import { cn } from '@/lib/utils'
import type { ModuleKey } from '@/types/auth'

type NavItem = {
  label: string
  to: string
  icon: ComponentType<{ className?: string }>
  module: ModuleKey
  badge?: number
}

type ConfigSubItem = {
  label: string
  to: string
  icon: ComponentType<{ className?: string }>
  module: ModuleKey
}

const NAV_ITEMS: NavItem[] = [
  { label: 'Dashboard', to: '/dashboard', icon: LayoutDashboard, module: 'dashboard' },
  { label: 'Contratos', to: '/contratos', icon: FileText, module: 'contratos' },
  { label: 'Clientes', to: '/clientes', icon: Users, module: 'clientes' },
  { label: 'Solicitudes', to: '/solicitudes', icon: ClipboardList, module: 'solicitudes', badge: 3 },
  { label: 'Cobranza', to: '/cobranza', icon: CreditCard, module: 'cobranza' },
  {
    label: 'Promociones vigentes',
    to: '/promociones-vigentes',
    icon: Gift,
    module: 'promociones_ventas',
  },
  { label: 'Utilidad por segmento', to: '/utilidad', icon: PieChart, module: 'utilidad_segmento' },
]

const CONFIG_SUBITEMS: ConfigSubItem[] = [
  { label: 'Catálogo de servicios', to: '/configuracion/catalogo', icon: Package, module: 'config_catalogo' },
  { label: 'Listas de precios', to: '/configuracion/precios', icon: Tags, module: 'config_precios' },
  { label: 'Promociones', to: '/configuracion/promociones', icon: BadgePercent, module: 'config_promociones' },
  { label: 'Emisores', to: '/configuracion/emisores', icon: Building2, module: 'config_emisores' },
  { label: 'Instancias', to: '/configuracion/instancias', icon: Server, module: 'config_instancias' },
  { label: 'Usuarios', to: '/configuracion/usuarios', icon: Users, module: 'config_usuarios' },
  { label: 'Roles y permisos', to: '/configuracion/roles', icon: Shield, module: 'config_roles' },
  { label: 'Preferencias de pantalla', to: '/configuracion/preferencias', icon: SlidersHorizontal, module: 'config_preferencias' },
]

function UserAvatar({
  name,
  size = 'md',
}: {
  name?: string
  size?: 'sm' | 'md'
}) {
  const initial = name?.replace(/[\[\]]/g, '').trim().charAt(0).toUpperCase()
  const dim = size === 'sm' ? 'h-9 w-9' : 'h-10 w-10'
  const iconDim = size === 'sm' ? 'h-4 w-4' : 'h-5 w-5'

  return (
    <div
      className={cn(
        'flex shrink-0 items-center justify-center rounded-full bg-blue-600 text-white shadow-[0_0_16px_rgba(37,99,235,0.35)]',
        dim,
      )}
      aria-hidden={name ? undefined : true}
    >
      {initial ? (
        <span className="text-sm font-bold">{initial}</span>
      ) : (
        <UserRound className={iconDim} />
      )}
    </div>
  )
}

function ConfigAccordion({
  collapsed,
  onNavigate,
}: {
  collapsed?: boolean
  onNavigate?: () => void
}) {
  const location = useLocation()
  const { canView, canViewAnyConfig } = usePermissions()
  const inConfig = location.pathname.startsWith('/configuracion')
  const [open, setOpen] = useState(inConfig)

  const visibleSubitems = useMemo(
    () => CONFIG_SUBITEMS.filter((item) => canView(item.module)),
    [canView],
  )

  useEffect(() => {
    if (inConfig) setOpen(true)
  }, [inConfig])

  if (!canViewAnyConfig || visibleSubitems.length === 0) {
    return null
  }

  const firstConfigTo = visibleSubitems[0]?.to ?? '/dashboard'

  if (collapsed) {
    return (
      <NavLink
        to={firstConfigTo}
        title="Configuración"
        onClick={onNavigate}
        className={cn(
          'flex items-center justify-center rounded-xl px-2 py-2.5 text-sm transition-colors',
          inConfig
            ? 'border border-blue-500/20 bg-blue-50 text-blue-600 dark:bg-[#0A84FF]/15 dark:text-[#0A84FF]'
            : 'text-slate-500 hover:bg-slate-100 dark:text-zinc-400 dark:hover:bg-white/[0.05]',
        )}
      >
        <Settings className="h-4 w-4" />
      </NavLink>
    )
  }

  return (
    <div className="space-y-1">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className={cn(
          'flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition-colors',
          inConfig
            ? 'text-blue-600 dark:text-[#0A84FF]'
            : 'text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-zinc-400 dark:hover:bg-white/[0.05] dark:hover:text-white',
        )}
        aria-expanded={open}
      >
        <Settings className="h-4 w-4 shrink-0" />
        <span className="flex-1 truncate text-left">Configuración</span>
        <ChevronDown
          className={cn('h-4 w-4 shrink-0 transition-transform duration-200', open && 'rotate-180')}
        />
      </button>

      <div
        className={cn(
          'grid transition-[grid-template-rows] duration-200 ease-out',
          open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]',
        )}
      >
        <div className="overflow-hidden">
          <div className="relative ml-4 space-y-0.5 border-l border-slate-200 pl-3 dark:border-white/[0.09]">
            {visibleSubitems.map((item) => {
              const Icon = item.icon
              return (
                <NavLink
                  key={item.to}
                  to={item.to}
                  onClick={onNavigate}
                  className={({ isActive }) =>
                    cn(
                      'relative flex items-center gap-2.5 rounded-xl px-2.5 py-2 text-[13px] transition-colors',
                      isActive
                        ? 'bg-blue-50 font-medium text-blue-600 before:absolute before:-left-[13px] before:top-1/2 before:h-5 before:w-0.5 before:-translate-y-1/2 before:rounded-full before:bg-[#0066FF] dark:bg-[#0A84FF]/15 dark:text-[#0A84FF] dark:before:bg-[#0A84FF]'
                        : 'text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-zinc-400 dark:hover:bg-white/[0.05] dark:hover:text-white',
                    )
                  }
                >
                  <Icon className="h-3.5 w-3.5 shrink-0 opacity-80" />
                  <span className="truncate">{item.label}</span>
                </NavLink>
              )
            })}
          </div>
        </div>
      </div>
    </div>
  )
}

function NavItems({
  collapsed,
  onNavigate,
}: {
  collapsed?: boolean
  onNavigate?: () => void
}) {
  const { canView } = usePermissions()
  const visibleItems = useMemo(
    () => NAV_ITEMS.filter((item) => canView(item.module)),
    [canView],
  )

  return (
    <nav className="flex flex-1 flex-col gap-1 overflow-y-auto px-3 py-2">
      {visibleItems.map((item) => {
        const Icon = item.icon
        return (
          <NavLink
            key={item.to}
            to={item.to}
            end={item.to === '/dashboard'}
            onClick={onNavigate}
            title={item.label}
            className={({ isActive }) =>
              cn(
                'group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition-colors',
                collapsed && 'justify-center px-2',
                isActive
                  ? 'border border-blue-500/20 bg-blue-50 text-blue-600 before:absolute before:left-0 before:top-1/2 before:h-6 before:w-0.5 before:-translate-y-1/2 before:rounded-full before:bg-[#0066FF] dark:bg-[#0A84FF]/15 dark:text-[#0A84FF] dark:before:bg-[#0A84FF]'
                  : 'text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-zinc-400 dark:hover:bg-white/[0.05] dark:hover:text-white',
                isActive && collapsed && 'before:hidden',
              )
            }
          >
            <Icon className="h-4 w-4 shrink-0" />
            {!collapsed && <span className="flex-1 truncate">{item.label}</span>}
            {!collapsed && item.badge != null && (
              <span className="rounded-full bg-orange-500 px-2 py-0.5 text-[11px] font-semibold text-white">
                {item.badge}
              </span>
            )}
          </NavLink>
        )
      })}

      <ConfigAccordion collapsed={collapsed} onNavigate={onNavigate} />
    </nav>
  )
}

function SidebarChrome({
  collapsed,
  onToggle,
  onNavigate,
  showCollapse = true,
}: {
  collapsed: boolean
  onToggle: () => void
  onNavigate?: () => void
  showCollapse?: boolean
}) {
  const { logout } = useAuth()
  const navigate = useNavigate()

  return (
    <div className="flex h-full w-full flex-col">
      <div
        className={cn(
          'flex shrink-0 items-center px-3 py-3 dark:border-white/[0.07]',
          collapsed ? 'justify-center px-2' : 'justify-between gap-2',
        )}
      >
        {!collapsed && (
          <p className="px-1 text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-400">
            Main
          </p>
        )}
        {showCollapse && (
          <button
            type="button"
            onClick={onToggle}
            aria-label={collapsed ? 'Expandir menú' : 'Colapsar menú'}
            className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 shadow-sm dark:border-white/[0.09] dark:bg-white/[0.05] dark:text-zinc-300"
          >
            <ChevronLeft className={cn('h-4 w-4 transition', collapsed && 'rotate-180')} />
          </button>
        )}
      </div>

      <NavItems collapsed={collapsed} onNavigate={onNavigate} />

      <div
        className={cn(
          'mt-auto shrink-0 space-y-1 border-t border-slate-200/80 p-3 dark:border-white/[0.07]',
          collapsed && 'px-2',
        )}
      >
        <button
          type="button"
          className={cn(
            'flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm text-slate-500 hover:bg-slate-100 dark:text-zinc-400 dark:hover:bg-white/[0.05]',
            collapsed && 'justify-center px-2',
          )}
        >
          <HelpCircle className="h-4 w-4 shrink-0" />
          {!collapsed && 'Ayuda'}
        </button>

        <div
          className={cn(
            'flex items-center rounded-xl px-3 py-2',
            collapsed ? 'justify-center px-1' : 'justify-between gap-2',
          )}
        >
          {!collapsed && (
            <span className="text-sm text-slate-500 dark:text-zinc-400">Apariencia</span>
          )}
          <ThemeToggleSwitch />
        </div>

        <div className="my-1 border-t border-slate-200/80 dark:border-white/[0.07]" />

        <button
          type="button"
          className={cn(
            'flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-[#EF4444] hover:bg-red-500/10',
            collapsed && 'justify-center px-2',
          )}
          onClick={() => {
            logout()
            onNavigate?.()
            navigate('/login', { replace: true })
          }}
        >
          <LogOut className="h-4 w-4 shrink-0" />
          {!collapsed && 'Cerrar sesión'}
        </button>
      </div>
    </div>
  )
}

function HeaderUserProfile() {
  const { user } = useAuth()
  const { rolNombre, segmentBadgeLabel, isSegmentLocked } = usePermissions()
  if (!user) return null

  return (
    <div className="flex items-center gap-3">
      <div className="hidden min-w-0 text-right sm:block">
        <p className="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-400">
          {rolNombre ?? user.rol}
        </p>
        <p className="truncate text-sm font-semibold text-[#090D16] dark:text-white">
          {user.nombre}
        </p>
      </div>
      <span
        className={cn(
          'hidden max-w-[14rem] truncate rounded-full border px-2.5 py-1 text-[11px] font-medium md:inline-flex',
          isSegmentLocked
            ? 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300'
            : 'border-blue-500/20 bg-blue-50 text-blue-700 dark:border-white/[0.08] dark:bg-white/[0.05] dark:text-zinc-300',
        )}
        title={segmentBadgeLabel}
      >
        {segmentBadgeLabel}
      </span>
      <UserAvatar name={user.nombre} size="sm" />
    </div>
  )
}

/**
 * Shell: Sidebar full-height + columna derecha (Header + Main).
 * Ítems filtrados por canView; Configuración visible si hay al menos un config_*.
 */
export function InternalLayout() {
  const [mobileOpen, setMobileOpen] = useState(false)
  const [collapsed, setCollapsed] = useState(false)

  return (
    <div className="flex h-screen overflow-hidden bg-[#F8FAFC] text-[#090D16] dark:bg-[#000000] dark:text-white">
      <aside
        className={cn(
          'hidden h-screen shrink-0 border-r border-slate-200/80 bg-white transition-[width] dark:border-white/[0.07] dark:bg-[#070708] lg:flex',
          collapsed ? 'w-[4.5rem]' : 'w-64',
        )}
      >
        <SidebarChrome collapsed={collapsed} onToggle={() => setCollapsed((v) => !v)} />
      </aside>

      <div className="flex min-w-0 flex-1 flex-col overflow-hidden">
        <header className="relative flex h-14 shrink-0 items-center border-b border-slate-200/80 bg-white px-4 dark:border-white/[0.07] dark:bg-[#000000]">
          <Button
            variant="ghost"
            size="icon"
            className="mr-2 lg:hidden"
            onClick={() => setMobileOpen(true)}
            aria-label="Abrir menú"
          >
            <Menu className="h-5 w-5" />
          </Button>

          <div className="mx-auto flex items-center gap-2">
            <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-gradient-to-br from-[#0A84FF] to-[#6366F1] text-xs font-black text-white">
              O
            </span>
            <span className="font-heading text-sm font-bold">Operam</span>
          </div>

          <div className="absolute right-4 top-1/2 -translate-y-1/2">
            <HeaderUserProfile />
          </div>
        </header>

        <main className="flex-1 overflow-y-auto">
          <div className="mx-auto w-full max-w-[1400px] px-4 py-5 md:px-6 md:py-6 lg:px-8">
            <Outlet />
          </div>
        </main>
      </div>

      {mobileOpen && (
        <div className="fixed inset-0 z-50 lg:hidden">
          <button
            type="button"
            className="absolute inset-0 bg-black/60 backdrop-blur-sm"
            aria-label="Cerrar menú"
            onClick={() => setMobileOpen(false)}
          />
          <aside className="absolute inset-y-0 left-0 flex h-screen w-[min(18rem,85vw)] flex-col bg-white shadow-2xl dark:bg-[#070708]">
            <div className="flex items-center justify-between border-b border-slate-200/80 px-4 py-3 dark:border-white/[0.07]">
              <p className="font-semibold">Menú</p>
              <Button variant="ghost" size="icon" onClick={() => setMobileOpen(false)}>
                <X className="h-5 w-5" />
              </Button>
            </div>
            <SidebarChrome
              collapsed={false}
              showCollapse={false}
              onToggle={() => undefined}
              onNavigate={() => setMobileOpen(false)}
            />
          </aside>
        </div>
      )}
    </div>
  )
}
