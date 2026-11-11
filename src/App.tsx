import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { ProtectedRoute } from '@/components/auth/ProtectedRoute'
import { InternalLayout } from '@/components/layout/InternalLayout'
import { GlobalLoadingOverlay } from '@/components/ui/GlobalLoadingOverlay'
import { AuthProvider } from '@/context/AuthContext'
import { LoadingLockProvider } from '@/context/LoadingLockContext'
import { ThemeProvider } from '@/context/ThemeContext'
import { DashboardPage } from '@/pages/DashboardPage'
import { LoginPage } from '@/pages/LoginPage'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      refetchOnWindowFocus: false,
    },
  },
})

/**
 * Enrutado: `/login` es la única ruta pública.
 * Overlay global de barra arcoíris + candado montado a nivel app.
 */
function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <ThemeProvider>
        <LoadingLockProvider>
          <AuthProvider>
            <BrowserRouter>
              <GlobalLoadingOverlay />
              <Routes>
                <Route path="/login" element={<LoginPage />} />

                <Route element={<ProtectedRoute />}>
                  <Route element={<InternalLayout />}>
                    <Route index element={<Navigate to="/dashboard" replace />} />
                    <Route path="dashboard" element={<DashboardPage />} />
                    <Route path="contratos" element={<Placeholder title="Contratos" />} />
                    <Route path="clientes" element={<Placeholder title="Clientes" />} />
                    <Route path="solicitudes" element={<Placeholder title="Solicitudes" />} />
                    <Route path="cobranza" element={<Placeholder title="Cobranza" />} />
                    <Route path="catalogo" element={<Placeholder title="Catálogo y precios" />} />
                    <Route path="utilidad" element={<Placeholder title="Utilidad por segmento" />} />
                    <Route path="configuracion" element={<Placeholder title="Configuración" />} />
                    <Route path="bitacora" element={<Placeholder title="Bitácora completa" />} />
                  </Route>
                </Route>

                <Route path="*" element={<Navigate to="/dashboard" replace />} />
              </Routes>
            </BrowserRouter>
          </AuthProvider>
        </LoadingLockProvider>
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
