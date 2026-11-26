import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useLoadingLock } from '@/context/LoadingLockContext'
import {
  checkoutPortalStore,
  confirmPortalSandboxPayment,
  createPortalPaymentLink,
  createPortalRequest,
  fetchPortalContract,
  fetchPortalInvoices,
  fetchPortalOverview,
  fetchPortalRequests,
  fetchPortalSandboxLink,
  fetchPortalStore,
  fetchPortalStoreQuote,
} from '@/services/portalService'
import type {
  PortalCheckoutInput,
  PortalCreatePaymentLinkInput,
  PortalCreateRequestInput,
  PortalInvoicesQuery,
  PortalStoreQuoteQuery,
} from '@/types/portal'

/** Todas las claves cuelgan de `['portal']` para descartarlas en bloque al cerrar sesión. */
export const portalKeys = {
  all: ['portal'] as const,
  overview: () => ['portal', 'overview'] as const,
  contract: (id: number) => ['portal', 'contract', id] as const,
  invoices: (query: PortalInvoicesQuery) => ['portal', 'invoices', query] as const,
  requests: () => ['portal', 'requests'] as const,
  store: (contractId: number | null) => ['portal', 'store', contractId] as const,
  storeQuote: (query: PortalStoreQuoteQuery | null) => ['portal', 'store-quote', query] as const,
  sandboxLink: (id: string) => ['portal', 'sandbox-link', id] as const,
}

const STALE_MS = 30_000

/** Tablero completo (contratos, exigible, SPEI, facturas) en una sola carga. */
export function usePortalOverview() {
  return useQuery({
    queryKey: portalKeys.overview(),
    queryFn: fetchPortalOverview,
    staleTime: STALE_MS,
  })
}

export function usePortalContract(id: number | null) {
  return useQuery({
    queryKey: portalKeys.contract(id ?? 0),
    queryFn: () => fetchPortalContract(id as number),
    enabled: typeof id === 'number' && id > 0,
    staleTime: STALE_MS,
  })
}

export function usePortalInvoices(query: PortalInvoicesQuery) {
  return useQuery({
    queryKey: portalKeys.invoices(query),
    queryFn: () => fetchPortalInvoices(query),
    staleTime: STALE_MS,
    placeholderData: (previous) => previous,
  })
}

export function usePortalRequests() {
  return useQuery({
    queryKey: portalKeys.requests(),
    queryFn: fetchPortalRequests,
    staleTime: STALE_MS,
  })
}

export function usePortalStore(contractId: number | null) {
  return useQuery({
    queryKey: portalKeys.store(contractId),
    queryFn: () => fetchPortalStore(contractId ?? undefined),
    staleTime: STALE_MS,
    placeholderData: keepPreviousData,
  })
}

/** Cotización en vivo: el precio, IVA y unidades de regalo siempre los decide el servidor. */
export function usePortalStoreQuote(query: PortalStoreQuoteQuery | null) {
  return useQuery({
    queryKey: portalKeys.storeQuote(query),
    queryFn: () => fetchPortalStoreQuote(query as PortalStoreQuoteQuery),
    enabled: query !== null,
    staleTime: 15_000,
    placeholderData: keepPreviousData,
    retry: false,
  })
}

export function usePortalSandboxLink(externalLinkId: string) {
  return useQuery({
    queryKey: portalKeys.sandboxLink(externalLinkId),
    queryFn: () => fetchPortalSandboxLink(externalLinkId),
    enabled: externalLinkId !== '',
    retry: false,
  })
}

/**
 * Mutaciones del portal: candado de pantalla + spinner Operam Aura y, al terminar, se refrescan
 * todas las lecturas `['portal', …]` (saldo, facturas, bolsas y solicitudes).
 */
function usePortalMutation<TInput, TResult>(
  mutationFn: (input: TInput) => Promise<TResult>,
  lockMessage: string,
) {
  const queryClient = useQueryClient()
  const { withScreenLock } = useLoadingLock()

  return useMutation({
    mutationFn: (input: TInput) => withScreenLock(() => mutationFn(input), lockMessage),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: portalKeys.all })
    },
  })
}

export function useCreatePortalPaymentLink() {
  return usePortalMutation(
    (input: PortalCreatePaymentLinkInput) => createPortalPaymentLink(input),
    'Generando liga de pago...',
  )
}

export function usePortalCheckout() {
  return usePortalMutation((input: PortalCheckoutInput) => checkoutPortalStore(input), 'Procesando compra...')
}

export function useConfirmPortalSandboxPayment() {
  return usePortalMutation(
    (externalLinkId: string) => confirmPortalSandboxPayment(externalLinkId),
    'Procesando pago...',
  )
}

export function useCreatePortalRequest() {
  return usePortalMutation(
    (input: PortalCreateRequestInput) => createPortalRequest(input),
    'Enviando solicitud...',
  )
}
