import * as DropdownMenu from '@radix-ui/react-dropdown-menu'
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
  Landmark,
  Package,
  Gift,
  SlidersHorizontal,
  Shield,
  Cpu,
  History,
  Mail,
  Plug,
} from 'lucide-react'
import { useEffect, useMemo, useState, type ComponentType } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { AppButton } from '@/components/ui/AppButton'
import { useAuth } from '@/context/AuthContext'
import { usePermissions } from '@/hooks/usePermissions'
import { useRequestsPendingCount } from '@/hooks/useRequests'
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
  { label: 'Contratos', to: '/contratos', icon: FileText, module: 'contracts' },
  { label: 'Clientes', to: '/clientes', icon: Users, module: 'clients' },
  { label: 'Solicitudes', to: '/solicitudes', icon: ClipboardList, module: 'requests' },
  { label: 'Cobranza', to: '/cobranza', icon: CreditCard, module: 'collections' },
  {
    label: 'Promociones vigentes',
    to: '/promociones-vigentes',
    icon: Gift,
    module: 'sales_promotions',
  },
  { label: 'Utilidad por segmento', to: '/utilidad-segmento', icon: PieChart, module: 'segment_profitability' },
]

type ConfigCategory = {
  id: string
  label: string
  items: ConfigSubItem[]
}

const CONFIG_CATEGORIES: ConfigCategory[] = [
  {
    id: 'commercial',
    label: 'COMERCIAL',
    items: [
      { label: 'Catálogo de servicios', to: '/configuracion/catalogo', icon: Package, module: 'config_catalog' },
      { label: 'Listas de precios', to: '/configuracion/precios', icon: Tags, module: 'config_prices' },
      { label: 'Promociones', to: '/configuracion/promociones', icon: BadgePercent, module: 'config_promotions' },
    ],
  },
  {
    id: 'infrastructure',
    label: 'INFRAESTRUCTURA',
    items: [
      { label: 'Emisores', to: '/configuracion/emisores', icon: Landmark, module: 'config_issuers' },
      { label: 'Instancias', to: '/configuracion/instancias', icon: Server, module: 'config_instances' },
      {
        label: 'Servicios externos',
        to: '/configuracion/servicios',
        icon: Plug,
        module: 'config_external_services',
      },
    ],
  },
  {
    id: 'security',
    label: 'SEGURIDAD Y ACCESO',
    items: [
      { label: 'Usuarios', to: '/configuracion/usuarios', icon: Users, module: 'config_users' },
      { label: 'Roles y permisos', to: '/configuracion/roles', icon: Shield, module: 'config_roles' },
      {
        label: 'Bitácora de auditoría',
        to: '/configuracion/auditoria',
        icon: History,
        module: 'config_audit_logs',
      },
    ],
  },
  {
    id: 'system',
    label: 'SISTEMA',
    items: [
      { label: 'Empresa', to: '/configuracion/empresa', icon: Building2, module: 'config_company' },
      { label: 'Sistema', to: '/configuracion/sistema', icon: Cpu, module: 'config_system' },
      {
        label: 'Preferencias de pantalla',
        to: '/configuracion/preferencias',
        icon: SlidersHorizontal,
        module: 'config_preferences',
      },
    ],
  },
]

/** Superficie iOS Liquid Glass para menús flotantes y flyouts del Sidebar. */
const MENU_SURFACE_CLASS =
  'z-50 min-w-[220px] rounded-2xl p-1.5 ios-liquid-glass ' +
  'animate-in fade-in-0 zoom-in-95 duration-150'

const MENU_ITEM_CLASS =
  'flex cursor-pointer select-none items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-medium outline-none transition-all duration-150 ' +
  'text-slate-700 hover:bg-blue-500/10 hover:text-blue-600 ' +
  'data-[highlighted]:bg-blue-500/10 data-[highlighted]:text-blue-600 ' +
  'dark:text-zinc-100 dark:hover:bg-blue-500/15 dark:hover:text-blue-400 ' +
  'dark:data-[highlighted]:bg-blue-500/15 dark:data-[highlighted]:text-blue-400'

const MENU_ITEM_ACTIVE_CLASS =
  'bg-blue-500/10 text-blue-600 dark:bg-blue-500/15 dark:text-[#0A84FF]'

const MENU_ITEM_DANGER_CLASS =
  'text-red-600 hover:bg-red-500/10 data-[highlighted]:bg-red-500/10 data-[highlighted]:text-red-600 ' +
  'dark:text-red-400 dark:hover:bg-red-500/10 dark:data-[highlighted]:bg-red-500/10'

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

/**
 * Accordion expandido o flyout Liquid Glass cuando el sidebar está colapsado.
 */
function ConfigAccordion({
  collapsed,
  onNavigate,
}: {
  collapsed?: boolean
  onNavigate?: () => void
}) {
  const location = useLocation()
  const navigate = useNavigate()
  const { canView, canViewAnyConfig } = usePermissions()
  const inConfig = location.pathname.startsWith('/configuracion')
  const [open, setOpen] = useState(inConfig)
  const [flyoutOpen, setFlyoutOpen] = useState(false)

  const visibleCategories = useMemo(
    () =>
      CONFIG_CATEGORIES.map((category) => ({
        ...category,
        items: category.items.filter((item) => canView(item.module)),
      })).filter((category) => category.items.length > 0),
    [canView],
  )

  const totalVisibleItems = useMemo(
    () => visibleCategories.reduce((acc, category) => acc + category.items.length, 0),
    [visibleCategories],
  )

  useEffect(() => {
    if (inConfig) setOpen(true)
  }, [inConfig])

  if (!canViewAnyConfig || visibleCategories.length === 0) {
    return null
  }

  if (collapsed) {
    const useTwoColumns = visibleCategories.length >= 2 && totalVisibleItems > 6
    return (
      <DropdownMenu.Root open={flyoutOpen} onOpenChange={setFlyoutOpen}>
        <DropdownMenu.Trigger asChild>
          <AppButton
            type="button"
            variant="ghost"
            title="Configuración"
            aria-label="Abrir configuración"
            className={cn(
              'relative h-auto w-full justify-center rounded-xl px-2 py-2.5 font-normal',
              inConfig || flyoutOpen
                ? 'border border-blue-500/20 bg-blue-50/90 text-blue-600 shadow-[inset_0_1px_0_rgba(255,255,255,0.22)] dark:bg-[#0A84FF]/15 dark:text-[#0A84FF]'
                : 'text-slate-500 hover:bg-slate-900/[0.04] dark:text-zinc-400 dark:hover:bg-white/[0.06]',
            )}
          >
            <Settings className="h-4 w-4" />
          </AppButton>
        </DropdownMenu.Trigger>
        <DropdownMenu.Portal>
          <DropdownMenu.Content
            side="right"
            align="start"
            sideOffset={12}
            collisionPadding={12}
            className={cn(
              MENU_SURFACE_CLASS,
              'max-h-[min(80vh,34rem)] overflow-y-auto p-2',
              useTwoColumns ? 'w-[460px] max-w-[calc(100vw-5.5rem)]' : 'w-64',
            )}
            onCloseAutoFocus={(event) => event.preventDefault()}
          >
            <p className="px-3 pb-1 pt-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-400 dark:text-zinc-500">
              Configuración
            </p>
            <div
              className={cn(
                useTwoColumns ? 'grid grid-cols-2 gap-x-2 gap-y-1' : 'space-y-1',
              )}
            >
              {visibleCategories.map((category) => (
                <div key={category.id} className="min-w-0">
                  <p className="px-3 pb-1 pt-2.5 text-[10px] font-semibold uppercase tracking-wider text-slate-400 dark:text-zinc-500">
                    {category.label}
                  </p>
                  <div className="space-y-0.5">
                    {category.items.map((item) => {
                      const Icon = item.icon
                      const active = location.pathname === item.to
                      return (
                        <DropdownMenu.Item
                          key={item.to}
                          className={cn(
                            MENU_ITEM_CLASS,
                            'py-1.5 text-[13px]',
                            active && MENU_ITEM_ACTIVE_CLASS,
                          )}
                          onSelect={(event) => {
                            event.preventDefault()
                            setFlyoutOpen(false)
                            navigate(item.to)
                            onNavigate?.()
                          }}
                        >
                          <Icon className="h-3.5 w-3.5 shrink-0 opacity-80" />
                          <span className="truncate">{item.label}</span>
                        </DropdownMenu.Item>
                      )
                    })}
                  </div>
                </div>
              ))}
            </div>
          </DropdownMenu.Content>
        </DropdownMenu.Portal>
      </DropdownMenu.Root>
    )
  }

  return (
    <div className="space-y-1">
      <AppButton
        type="button"
        variant="ghost"
        onClick={() => setOpen((v) => !v)}
        className={cn(
          'h-auto w-full justify-start gap-3 rounded-xl px-3 py-2.5 text-sm font-normal',
          inConfig
            ? 'text-blue-600 hover:bg-transparent dark:text-[#0A84FF] dark:hover:bg-transparent'
            : 'text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-zinc-400 dark:hover:bg-white/[0.05] dark:hover:text-white',
        )}
        aria-expanded={open}
      >
        <Settings className="h-4 w-4 shrink-0" />
        <span className="flex-1 truncate text-left">Configuración</span>
        <ChevronDown
          className={cn('h-4 w-4 shrink-0 transition-transform duration-200', open && 'rotate-180')}
        />
      </AppButton>

      <div
        className={cn(
          'grid transition-[grid-template-rows] duration-200 ease-out',
          open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]',
        )}
      >
        <div className="overflow-hidden">
          <div className="relative ml-4 border-l border-slate-900/[0.07] pl-2.5 dark:border-white/[0.10]">
            {visibleCategories.map((category, index) => (
              <div key={category.id} className={cn(index === 0 ? 'pt-0.5' : 'pt-1')}>
                <p
                  className={cn(
                    'px-3 pb-1 text-[10px] font-semibold uppercase tracking-wider text-slate-400 dark:text-zinc-500',
                    index === 0 ? 'pt-1' : 'pt-2.5',
                  )}
                >
                  {category.label}
                </p>
                <div className="space-y-0.5">
                  {category.items.map((item) => {
                    const Icon = item.icon
                    return (
                      <NavLink
                        key={item.to}
                        to={item.to}
                        onClick={onNavigate}
                        className={({ isActive }) =>
                          cn(
                            'relative flex items-center gap-2.5 rounded-xl px-2.5 py-1.5 text-[13px] transition-colors',
                            isActive
                              ? 'bg-blue-50/90 font-medium text-blue-600 shadow-[inset_0_1px_0_rgba(255,255,255,0.22)] before:absolute before:-left-[11px] before:top-1/2 before:h-4 before:w-0.5 before:-translate-y-1/2 before:rounded-full before:bg-[#0066FF] dark:bg-[#0A84FF]/15 dark:text-[#0A84FF] dark:before:bg-[#0A84FF]'
                              : 'text-slate-500 hover:bg-slate-900/[0.04] hover:text-slate-900 dark:text-zinc-400 dark:hover:bg-white/[0.06] dark:hover:text-white',
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
            ))}
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
  const canViewRequests = canView('requests')
  const { pendingCount } = useRequestsPendingCount(canViewRequests)

  const visibleItems = useMemo(
    () =>
      NAV_ITEMS.filter((item) => canView(item.module)).map((item) =>
        item.module === 'requests'
          ? { ...item, badge: pendingCount > 0 ? pendingCount : undefined }
          : item,
      ),
    [canView, pendingCount],
  )

  return (
    <nav className="flex flex-1 flex-col gap-1 overflow-y-auto overflow-x-hidden px-3 py-2">
      {visibleItems.map((item) => {
        const Icon = item.icon
        const badge = item.badge
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
                  ? 'border border-blue-500/20 bg-blue-50/90 text-blue-600 shadow-[inset_0_1px_0_rgba(255,255,255,0.22)] before:absolute before:left-0 before:top-1/2 before:h-6 before:w-0.5 before:-translate-y-1/2 before:rounded-full before:bg-[#0066FF] dark:bg-[#0A84FF]/15 dark:text-[#0A84FF] dark:before:bg-[#0A84FF]'
                  : 'text-slate-500 hover:bg-slate-900/[0.04] hover:text-slate-900 dark:text-zinc-400 dark:hover:bg-white/[0.06] dark:hover:text-white',
                isActive && collapsed && 'before:hidden',
              )
            }
          >
            <span className="relative shrink-0">
              <Icon className="h-4 w-4" />
              {collapsed && badge != null && badge > 0 ? (
                <span className="absolute -right-1.5 -top-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-orange-500 px-1 text-[9px] font-bold leading-none text-white">
                  {badge > 9 ? '9+' : badge}
                </span>
              ) : null}
            </span>
            {!collapsed && <span className="flex-1 truncate">{item.label}</span>}
            {!collapsed && badge != null && badge > 0 ? (
              <span className="rounded-full bg-orange-500 px-2 py-0.5 text-[11px] font-semibold text-white">
                {badge}
              </span>
            ) : null}
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
          'flex shrink-0 items-center px-3 py-3',
          collapsed ? 'justify-center px-2' : 'justify-between gap-2',
        )}
      >
        {!collapsed && (
          <p className="px-1 text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-400">
            Main
          </p>
        )}
        {showCollapse && (
          <AppButton
            type="button"
            variant="outline"
            size="icon"
            onClick={onToggle}
            aria-label={collapsed ? 'Expandir menú' : 'Colapsar menú'}
            className="h-7 w-7 shrink-0 rounded-full border-slate-200 bg-white text-slate-500 shadow-sm dark:border-white/[0.09] dark:bg-white/[0.05] dark:text-zinc-300"
          >
            <ChevronLeft className={cn('h-4 w-4 transition', collapsed && 'rotate-180')} />
          </AppButton>
        )}
      </div>

      <NavItems collapsed={collapsed} onNavigate={onNavigate} />

      <div
        className={cn(
          'mt-auto shrink-0 space-y-1 border-t border-slate-900/[0.07] p-3 dark:border-white/[0.10]',
          collapsed && 'px-2',
        )}
      >
        <AppButton
          type="button"
          variant="ghost"
          title="Ayuda"
          className={cn(
            'h-auto w-full justify-start gap-3 rounded-xl px-3 py-2.5 text-sm font-normal text-slate-500 hover:bg-slate-100 dark:text-zinc-400 dark:hover:bg-white/[0.05]',
            collapsed && 'justify-center px-2',
          )}
        >
          <HelpCircle className="h-4 w-4 shrink-0" />
          {!collapsed && 'Ayuda'}
        </AppButton>

        <AppButton
          type="button"
          variant="ghost"
          title="Cerrar sesión"
          className={cn(
            'h-auto w-full justify-start gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-[#EF4444] hover:bg-red-500/10 hover:text-[#EF4444]',
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
        </AppButton>
      </div>
    </div>
  )
}

/**
 * Perfil compacto en header: nombre + rol/alcance + menú Liquid Glass en el avatar.
 */
function HeaderUserProfile() {
  const { user, logout } = useAuth()
  const { roleName, isSegmentLocked, canView } = usePermissions()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)

  if (!user) return null

  const roleLabel = roleName ?? user.role_code ?? 'Usuario'
  const scopeLabel = isSegmentLocked
    ? (user.segment_name || 'Segmento')
    : 'Todos los segmentos'

  return (
    <div className="flex items-center gap-2.5 sm:gap-3">
      <div className="hidden min-w-0 text-right sm:block">
        <p className="truncate text-sm font-semibold leading-tight text-slate-900 dark:text-white">
          {user.name}
        </p>
        <p className="mt-0.5 truncate text-[11px] font-medium text-slate-500 dark:text-zinc-400">
          {roleLabel}
          <span className="mx-1 opacity-50">·</span>
          {isSegmentLocked ? (
            <span className="inline-flex items-center gap-1">
              <span
                className="inline-block h-1.5 w-1.5 rounded-full bg-[#0066FF] dark:bg-[#0A84FF]"
                aria-hidden
              />
              <span className="text-slate-600 dark:text-zinc-300">{scopeLabel}</span>
            </span>
          ) : (
            scopeLabel
          )}
        </p>
      </div>

      <DropdownMenu.Root open={open} onOpenChange={setOpen}>
        <DropdownMenu.Trigger asChild>
          <AppButton
            type="button"
            variant="ghost"
            aria-label="Menú de cuenta"
            className="h-auto rounded-full p-0 hover:bg-transparent"
          >
            <UserAvatar name={user.name} size="sm" />
          </AppButton>
        </DropdownMenu.Trigger>
        <DropdownMenu.Portal>
          <DropdownMenu.Content
            align="end"
            sideOffset={8}
            collisionPadding={8}
            className={cn(MENU_SURFACE_CLASS, 'z-[60] w-[260px]')}
            onCloseAutoFocus={(event) => event.preventDefault()}
          >
            <div className="border-b border-slate-200/70 px-3 py-2.5 dark:border-white/[0.08]">
              <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">
                {user.name}
              </p>
              <p className="mt-1 flex items-center gap-1.5 truncate text-[11px] text-slate-500 dark:text-zinc-400">
                <Mail className="h-3 w-3 shrink-0 opacity-70" />
                {user.email}
              </p>
              <p className="mt-1 text-[11px] text-slate-500 dark:text-zinc-400">
                Rol: <span className="font-medium text-slate-700 dark:text-zinc-200">{roleLabel}</span>
              </p>
              <p className="mt-0.5 text-[11px] text-slate-500 dark:text-zinc-400">
                Alcance:{' '}
                {isSegmentLocked ? (
                  <span className="inline-flex items-center gap-1 font-medium text-slate-700 dark:text-zinc-200">
                    <span className="inline-block h-1.5 w-1.5 rounded-full bg-[#0066FF] dark:bg-[#0A84FF]" />
                    {scopeLabel}
                  </span>
                ) : (
                  <span className="font-medium text-slate-700 dark:text-zinc-200">{scopeLabel}</span>
                )}
              </p>
            </div>

            {canView('config_preferences') ? (
              <DropdownMenu.Item
                className={cn(MENU_ITEM_CLASS, 'mt-1')}
                onSelect={(event) => {
                  event.preventDefault()
                  setOpen(false)
                  navigate('/configuracion/preferencias')
                }}
              >
                <SlidersHorizontal className="h-4 w-4 shrink-0 opacity-80" />
                Preferencias de pantalla
              </DropdownMenu.Item>
            ) : null}

            <DropdownMenu.Separator className="my-1 h-px bg-slate-200/70 dark:bg-white/[0.08]" />

            <DropdownMenu.Item
              className={cn(MENU_ITEM_CLASS, MENU_ITEM_DANGER_CLASS)}
              onSelect={(event) => {
                event.preventDefault()
                setOpen(false)
                logout()
                navigate('/login', { replace: true })
              }}
            >
              <LogOut className="h-4 w-4 shrink-0" />
              Cerrar sesión
            </DropdownMenu.Item>
          </DropdownMenu.Content>
        </DropdownMenu.Portal>
      </DropdownMenu.Root>
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
    <div className="relative flex h-screen overflow-hidden bg-[#F8FAFC] text-[#090D16] dark:bg-[#000000] dark:text-white">
      {/* Resplandor ambiental fijo muy sutil y uniforme detrás del cristal del Sidebar */}
      <div
        aria-hidden
        className="pointer-events-none fixed inset-0 z-0 overflow-hidden bg-[radial-gradient(circle_at_12%_30%,rgba(0,102,255,0.06),transparent_52%),radial-gradient(circle_at_14%_78%,rgba(122,63,196,0.05),transparent_50%)] dark:bg-[radial-gradient(circle_at_12%_30%,rgba(10,132,255,0.11),transparent_52%),radial-gradient(circle_at_14%_78%,rgba(122,63,196,0.08),transparent_50%)]"
      />

      <aside
        className={cn(
          'relative left-0 top-0 z-20 hidden h-screen shrink-0 rounded-none border-y-0 border-l-0 border-r border-slate-200/80 transition-[width] ios-liquid-glass-sidebar dark:border-white/[0.10] lg:flex',
          collapsed ? 'w-[4.5rem]' : 'w-64',
        )}
      >
        <SidebarChrome collapsed={collapsed} onToggle={() => setCollapsed((v) => !v)} />
      </aside>

      <div className="relative z-10 flex min-w-0 flex-1 flex-col overflow-hidden">
        <header className="relative z-[55] flex h-14 shrink-0 items-center border-b border-slate-200/80 bg-white px-4 dark:border-white/[0.07] dark:bg-[#000000]">
          <AppButton
            type="button"
            variant="ghost"
            size="icon"
            className="mr-2 lg:hidden"
            onClick={() => setMobileOpen(true)}
            aria-label="Abrir menú"
          >
            <Menu className="h-5 w-5" />
          </AppButton>

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
          <AppButton
            type="button"
            variant="ghost"
            className="absolute inset-0 h-full w-full rounded-none bg-black/60 p-0 backdrop-blur-sm hover:bg-black/60"
            aria-label="Cerrar menú"
            onClick={() => setMobileOpen(false)}
          />
          <aside className="absolute inset-y-0 left-0 flex h-screen w-[min(18rem,85vw)] flex-col rounded-none border-y-0 border-l-0 border-r border-slate-200/80 ios-liquid-glass-sidebar dark:border-white/[0.10]">
            <div className="flex items-center justify-between border-b border-slate-900/[0.07] px-4 py-3 dark:border-white/[0.10]">
              <p className="font-semibold">Menú</p>
              <AppButton
                type="button"
                variant="ghost"
                size="icon"
                onClick={() => setMobileOpen(false)}
                aria-label="Cerrar menú"
              >
                <X className="h-5 w-5" />
              </AppButton>
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
