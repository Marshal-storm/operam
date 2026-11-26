import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { lazy, Suspense } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { ProtectedRoute } from '@/components/auth/ProtectedRoute'
import { SessionSecurityBridge } from '@/components/auth/SessionSecurityBridge'
import { InternalLayout } from '@/components/layout/InternalLayout'
import { GlobalLoadingOverlay } from '@/components/ui/GlobalLoadingOverlay'
import { AuthProvider } from '@/context/AuthContext'
import { LoadingLockProvider } from '@/context/LoadingLockContext'
import { ScreenPreferencesProvider } from '@/context/ScreenPreferencesContext'
import { ThemeProvider } from '@/context/ThemeContext'
import { ToastProvider } from '@/context/ToastContext'
import { usePermissions } from '@/hooks/usePermissions'
import { AuditLogsPage } from '@/pages/config/AuditLogsPage'
import { CatalogServicesPage } from '@/pages/config/CatalogServicesPage'
import { ExternalServicesPage } from '@/pages/config/ExternalServicesPage'
import { InstancesPage } from '@/pages/config/InstancesPage'
import { IssuersPage } from '@/pages/config/IssuersPage'
import { PriceListsPage } from '@/pages/config/PriceListsPage'
import { PromotionsPage } from '@/pages/config/PromotionsPage'
import { RolesPermissionsPage } from '@/pages/config/RolesPermissionsPage'
import { CompanySettingsPage } from '@/pages/config/CompanySettingsPage'
import { ScreenPreferencesPage } from '@/pages/config/ScreenPreferencesPage'
import { SystemSettingsPage } from '@/pages/config/SystemSettingsPage'
import { UsersManagementPage } from '@/pages/config/UsersManagementPage'
import { ClientsPage } from '@/pages/ClientsPage'
import { CollectionsPage } from '@/pages/CollectionsPage'
import { ContractDetailPage } from '@/pages/ContractDetailPage'
import { ContractsPage } from '@/pages/ContractsPage'
import { DashboardPage } from '@/pages/DashboardPage'
import { LoginPage } from '@/pages/LoginPage'
import { NewContractPage } from '@/pages/NewContractPage'
import { RequestsPage } from '@/pages/RequestsPage'
import { SalesPromotionsPage } from '@/pages/SalesPromotionsPage'
import { SegmentProfitabilityPage } from '@/pages/SegmentProfitabilityPage'
import { Skeleton } from '@/components/ui/skeleton'
import type { ModuleKey } from '@/types/auth'

/**
 * Portal del Cliente (RF-7): `React.lazy` → chunk de Vite independiente del panel interno
 * (UI-10). El cliente final jamás descarga el código de administración.
 */
const PortalApp = lazy(() => import('@/pages/portal/PortalApp'))

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      refetchOnWindowFocus: false,
    },
  },
})

const CONFIG_HOME_ROUTES: Array<{ module: ModuleKey; to: string }> = [
  { module: 'config_catalog', to: '/configuracion/catalogo' },
  { module: 'config_prices', to: '/configuracion/precios' },
  { module: 'config_promotions', to: '/configuracion/promociones' },
  { module: 'config_issuers', to: '/configuracion/emisores' },
  { module: 'config_instances', to: '/configuracion/instancias' },
  { module: 'config_users', to: '/configuracion/usuarios' },
  { module: 'config_roles', to: '/configuracion/roles' },
  { module: 'config_company', to: '/configuracion/empresa' },
  { module: 'config_system', to: '/configuracion/sistema' },
  { module: 'config_external_services', to: '/configuracion/servicios' },
  { module: 'config_audit_logs', to: '/configuracion/auditoria' },
  { module: 'config_preferences', to: '/configuracion/preferencias' },
]

function ModulePage({
  module,
  children,
}: {
  module: ModuleKey
  children: React.ReactNode
}) {
  return <ProtectedRoute requiredModule={module}>{children}</ProtectedRoute>
}

function ConfigHomeRedirect() {
  const { canView } = usePermissions()
  const first = CONFIG_HOME_ROUTES.find((route) => canView(route.module))
  return <Navigate to={first?.to ?? '/dashboard'} replace />
}

/** Alta de contrato: exige can_create además de can_view del módulo. */
function NewContractGate() {
  const { canCreate } = usePermissions()
  if (!canCreate('contracts')) {
    return <Navigate to="/contratos" replace />
  }
  return <NewContractPage />
}

/**
 * Enrutado: `/login` es la única ruta pública.
 * Configuración con permisos granulares config_*.
 */
function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <ThemeProvider>
        <ScreenPreferencesProvider>
          <LoadingLockProvider>
            <ToastProvider>
              <AuthProvider>
                <BrowserRouter>
                  <GlobalLoadingOverlay />
                  <SessionSecurityBridge />
                  <Routes>
                    <Route path="/login" element={<LoginPage />} />

                    {/* Portal Cliente: sesión propia (sin contraseña), aislada del ERP. */}
                    <Route
                      path="/portal/*"
                      element={
                        <Suspense
                          fallback={
                            <div className="min-h-screen bg-[#F5F7FB] px-4 py-8 dark:bg-black">
                              <div className="mx-auto max-w-5xl space-y-4">
                                <Skeleton className="h-8 w-48 rounded-xl bg-slate-200/70 dark:bg-white/[0.07]" />
                                <Skeleton className="h-52 w-full rounded-[28px] bg-slate-200/70 dark:bg-white/[0.07]" />
                              </div>
                            </div>
                          }
                        >
                          <PortalApp />
                        </Suspense>
                      }
                    />

                    <Route element={<ProtectedRoute />}>
                      <Route element={<InternalLayout />}>
                        <Route index element={<Navigate to="/dashboard" replace />} />
                        <Route
                          path="dashboard"
                          element={
                            <ModulePage module="dashboard">
                              <DashboardPage />
                            </ModulePage>
                          }
                        />
                        <Route
                          path="contratos"
                          element={
                            <ModulePage module="contracts">
                              <ContractsPage />
                            </ModulePage>
                          }
                        />
                        <Route
                          path="contratos/nuevo"
                          element={
                            <ModulePage module="contracts">
                              <NewContractGate />
                            </ModulePage>
                          }
                        />
                        <Route
                          path="contratos/:id"
                          element={
                            <ModulePage module="contracts">
                              <ContractDetailPage />
                            </ModulePage>
                          }
                        />
                        <Route
                          path="clientes"
                          element={
                            <ModulePage module="clients">
                              <ClientsPage />
                            </ModulePage>
                          }
                        />
                        <Route
                          path="solicitudes"
                          element={
                            <ModulePage module="requests">
                              <RequestsPage />
                            </ModulePage>
                          }
                        />
                        <Route
                          path="cobranza"
                          element={
                            <ModulePage module="collections">
                              <CollectionsPage />
                            </ModulePage>
                          }
                        />
                        <Route
                          path="promociones-vigentes"
                          element={
                            <ModulePage module="sales_promotions">
                              <SalesPromotionsPage />
                            </ModulePage>
                          }
                        />
                        <Route
                          path="utilidad-segmento"
                          element={
                            <ModulePage module="segment_profitability">
                              <SegmentProfitabilityPage />
                            </ModulePage>
                          }
                        />
                        <Route
                          path="utilidad"
                          element={<Navigate to="/utilidad-segmento" replace />}
                        />
                        <Route path="bitacora" element={<Placeholder title="Bitácora completa" />} />

                        <Route path="catalogo" element={<Navigate to="/configuracion" replace />} />
                        <Route path="configuracion" element={<ConfigHomeRedirect />} />
                        <Route
                          path="configuracion/catalogo"
                          element={
                            <ModulePage module="config_catalog">
                              <CatalogServicesPage />
                            </ModulePage>
                          }
                        />
                        <Route
                          path="configuracion/precios"
                          element={
                            <ModulePage module="config_prices">
                              <PriceListsPage />
                            </ModulePage>
                          }
                        />
                        <Route
                          path="configuracion/promociones"
                          element={
                            <ModulePage module="config_promotions">
                              <PromotionsPage />
                            </ModulePage>
                          }
                        />
                        <Route
                          path="configuracion/emisores"
                          element={
                            <ModulePage module="config_issuers">
                              <IssuersPage />
                            </ModulePage>
                          }
                        />
                        <Route
                          path="configuracion/instancias"
                          element={
                            <ModulePage module="config_instances">
                              <InstancesPage />
                            </ModulePage>
                          }
                        />
                        <Route
                          path="configuracion/usuarios"
                          element={
                            <ModulePage module="config_users">
                              <UsersManagementPage />
                            </ModulePage>
                          }
                        />
                        <Route
                          path="configuracion/roles"
                          element={
                            <ModulePage module="config_roles">
                              <RolesPermissionsPage />
                            </ModulePage>
                          }
                        />
                        <Route
                          path="configuracion/roles-permisos"
                          element={<Navigate to="/configuracion/roles" replace />}
                        />
                        <Route
                          path="configuracion/empresa"
                          element={
                            <ModulePage module="config_company">
                              <CompanySettingsPage />
                            </ModulePage>
                          }
                        />
                        <Route
                          path="configuracion/sistema"
                          element={
                            <ModulePage module="config_system">
                              <SystemSettingsPage />
                            </ModulePage>
                          }
                        />
                        <Route
                          path="configuracion/servicios"
                          element={
                            <ModulePage module="config_external_services">
                              <ExternalServicesPage />
                            </ModulePage>
                          }
                        />
                        <Route
                          path="configuracion/auditoria"
                          element={
                            <ModulePage module="config_audit_logs">
                              <AuditLogsPage />
                            </ModulePage>
                          }
                        />
                        <Route
                          path="configuracion/preferencias"
                          element={
                            <ModulePage module="config_preferences">
                              <ScreenPreferencesPage />
                            </ModulePage>
                          }
                        />
                      </Route>
                    </Route>

                    <Route path="*" element={<Navigate to="/dashboard" replace />} />
                  </Routes>
                </BrowserRouter>
              </AuthProvider>
            </ToastProvider>
          </LoadingLockProvider>
        </ScreenPreferencesProvider>
      </ThemeProvider>
    </QueryClientProvider>
  )
}

function Placeholder({ title }: { title: string }) {
  return (
    <section className="glass-panel p-6">
      <h1 className="text-xl font-semibold">{title}</h1>
      <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
        Módulo pendiente de implementación.
      </p>
    </section>
  )
}

export default App
