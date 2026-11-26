import { portalApiFetch } from '@/lib/portalApiClient'
import type {
  PortalCheckoutInput,
  PortalCheckoutResult,
  PortalContractDetail,
  PortalCreatePaymentLinkInput,
  PortalCreateRequestInput,
  PortalInvoicesPage,
  PortalInvoicesQuery,
  PortalOverview,
  PortalPaymentLinkResult,
  PortalRequest,
  PortalRequestLinkResult,
  PortalSandboxConfirmResult,
  PortalSandboxLink,
  PortalSessionPayload,
  PortalStoreCatalog,
  PortalStoreQuote,
  PortalStoreQuoteQuery,
} from '@/types/portal'

/**
 * Servicios HTTP del Portal del Cliente (RF-7 / RNF-7).
 *
 * Todas las llamadas pasan exclusivamente por `portalApiFetch` (`src/lib/portalApiClient.ts`),
 * aislado del Panel Interno (jamás toca `AuthContext` ni `localStorage`).
 */

/** Mensaje genérico oficial (idéntico al del backend; evita enumerar correos). */
export const PORTAL_REQUEST_LINK_MESSAGE =
  'Si tu correo está registrado y autorizado, recibirás una liga de acceso válida por 15 minutos.'

function toQuery(params: Record<string, string | number | undefined>): string {
  const entries = Object.entries(params).filter(
    (entry): entry is [string, string | number] => entry[1] !== undefined && entry[1] !== '',
  )
  if (entries.length === 0) return ''
  return `?${new URLSearchParams(entries.map(([key, value]) => [key, String(value)])).toString()}`
}

/* ── Autenticación sin contraseña ─────────────────────────────────────────────────────── */

export async function requestPortalLink(email: string): Promise<PortalRequestLinkResult> {
  const data = await portalApiFetch<{ sandbox_preview_url?: string } | null>(
    '/portal/auth/request-link',
    { method: 'POST', body: JSON.stringify({ email }) },
  )
  return {
    message: PORTAL_REQUEST_LINK_MESSAGE,
    sandbox_preview_url: data?.sandbox_preview_url ?? null,
  }
}

export async function exchangePortalToken(token: string): Promise<PortalSessionPayload> {
  return portalApiFetch<PortalSessionPayload>('/portal/auth/exchange', {
    method: 'POST',
    body: JSON.stringify({ token }),
  })
}

/** Restaura la sesión con la cookie HttpOnly enjaulada en `/api/portal` (sin Bearer tras F5). */
export async function fetchPortalSession(): Promise<PortalSessionPayload> {
  return portalApiFetch<PortalSessionPayload>('/portal/auth/me')
}

export async function logoutPortal(): Promise<void> {
  await portalApiFetch<unknown>('/portal/auth/logout', { method: 'POST' })
}

/* ── Lecturas ─────────────────────────────────────────────────────────────────────────── */

export async function fetchPortalOverview(): Promise<PortalOverview> {
  return portalApiFetch<PortalOverview>('/portal/overview')
}

export async function fetchPortalContract(id: number): Promise<PortalContractDetail> {
  return portalApiFetch<PortalContractDetail>(`/portal/contracts/${id}`)
}

export async function fetchPortalInvoices(
  params: PortalInvoicesQuery = {},
): Promise<PortalInvoicesPage> {
  return portalApiFetch<PortalInvoicesPage>(
    `/portal/invoices${toQuery({
      page: params.page,
      per_page: params.per_page,
      status: params.status,
      contract_id: params.contract_id,
    })}`,
  )
}

export async function fetchPortalRequests(): Promise<PortalRequest[]> {
  return portalApiFetch<PortalRequest[]>('/portal/requests')
}

export async function createPortalRequest(input: PortalCreateRequestInput): Promise<PortalRequest> {
  return portalApiFetch<PortalRequest>('/portal/requests', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

/* ── Tienda ───────────────────────────────────────────────────────────────────────────── */

export async function fetchPortalStore(contractId?: number): Promise<PortalStoreCatalog> {
  return portalApiFetch<PortalStoreCatalog>(`/portal/store${toQuery({ contract_id: contractId })}`)
}

export async function fetchPortalStoreQuote(query: PortalStoreQuoteQuery): Promise<PortalStoreQuote> {
  return portalApiFetch<PortalStoreQuote>(
    `/portal/store/quote${toQuery({
      contract_id: query.contract_id,
      service_id: query.service_id,
      quantity: query.quantity,
    })}`,
  )
}

export async function checkoutPortalStore(input: PortalCheckoutInput): Promise<PortalCheckoutResult> {
  return portalApiFetch<PortalCheckoutResult>('/portal/store/checkout', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

/* ── Pagos con tarjeta (IntegroPAY) ───────────────────────────────────────────────────── */

export async function createPortalPaymentLink(
  input: PortalCreatePaymentLinkInput,
): Promise<PortalPaymentLinkResult> {
  return portalApiFetch<PortalPaymentLinkResult>('/portal/payments/create-link', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export async function fetchPortalSandboxLink(externalLinkId: string): Promise<PortalSandboxLink> {
  return portalApiFetch<PortalSandboxLink>(`/portal/sandbox-pay/${encodeURIComponent(externalLinkId)}`)
}

export async function confirmPortalSandboxPayment(
  externalLinkId: string,
): Promise<PortalSandboxConfirmResult> {
  return portalApiFetch<PortalSandboxConfirmResult>(
    `/portal/sandbox-pay/${encodeURIComponent(externalLinkId)}/confirm`,
    { method: 'POST' },
  )
}
