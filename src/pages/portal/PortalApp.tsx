import { Navigate, Route, Routes } from 'react-router-dom'
import { PortalProtectedRoute } from '@/components/portal/PortalLayout'
import { PortalAuthProvider } from '@/context/PortalAuthContext'
import { PortalAccessPage } from '@/pages/portal/PortalAccessPage'
import { PortalContractsPage } from '@/pages/portal/PortalContractsPage'
import { PortalHomePage } from '@/pages/portal/PortalHomePage'
import { PortalPaymentsPage } from '@/pages/portal/PortalPaymentsPage'
import { PortalRequestsPage } from '@/pages/portal/PortalRequestsPage'
import { PortalSandboxPayPage } from '@/pages/portal/PortalSandboxPayPage'
import { PortalStorePage } from '@/pages/portal/PortalStorePage'

/**
 * Raíz del Portal del Cliente (`/portal/*`).
 *
 * Se carga con `React.lazy` desde `App.tsx`: todo el portal (contexto, layout y páginas) vive
 * en un chunk de Vite separado del bundle del panel interno (UI-10). Su sesión es independiente
 * de `AuthContext`: nunca comparten token ni almacenamiento (UI-11).
 */
export default function PortalApp() {
  return (
    <PortalAuthProvider>
      <Routes>
        <Route path="acceso" element={<PortalAccessPage />} />
        <Route path="login" element={<PortalAccessPage />} />

        <Route element={<PortalProtectedRoute />}>
          <Route index element={<PortalHomePage />} />
          <Route path="contratos" element={<PortalContractsPage />} />
          <Route path="pagos" element={<PortalPaymentsPage />} />
          <Route path="tienda" element={<PortalStorePage />} />
          <Route path="solicitudes" element={<PortalRequestsPage />} />
          <Route path="sandbox-pay/:externalLinkId" element={<PortalSandboxPayPage />} />
        </Route>

        <Route path="*" element={<Navigate to="/portal" replace />} />
      </Routes>
    </PortalAuthProvider>
  )
}
