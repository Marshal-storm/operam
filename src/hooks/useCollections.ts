import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useLoadingLock } from '@/context/LoadingLockContext'
import {
  cancelInvoice,
  fetchContractsPortfolio,
  fetchInvoiceDetail,
  fetchInvoicesPaginated,
  issueInvoice,
  issueInvoiceFromRequest,
  recordInvoicePayment,
  reversePayment,
  runSuspensionCheck,
  type CollectionsQueryParams,
} from '@/services/collectionsService'
import type { IssueInvoiceInput, RecordPaymentInput } from '@/types/collection'

export const COLLECTIONS_QUERY_KEY = ['collections'] as const

async function invalidateCollectionQueries(
  queryClient: ReturnType<typeof useQueryClient>,
): Promise<void> {
  await Promise.all([
    queryClient.invalidateQueries({ queryKey: [...COLLECTIONS_QUERY_KEY] }),
    queryClient.invalidateQueries({ queryKey: ['contracts'] }),
    queryClient.invalidateQueries({ queryKey: ['requests'] }),
    queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
  ])
}

function useLockedMutation<TArgs, TResult>(
  mutationFn: (args: TArgs) => Promise<TResult>,
  lockMessage: string,
) {
  const queryClient = useQueryClient()
  const { withScreenLock } = useLoadingLock()

  return useMutation({
    mutationFn: (args: TArgs) => withScreenLock(() => mutationFn(args), lockMessage),
    onSuccess: async () => {
      await invalidateCollectionQueries(queryClient)
    },
  })
}

/** Listado de documentos + KPIs + aging. */
export function useCollectionsInvoices(params: CollectionsQueryParams) {
  const query = useQuery({
    queryKey: [...COLLECTIONS_QUERY_KEY, 'invoices', params],
    queryFn: () => fetchInvoicesPaginated(params),
    staleTime: 15_000,
  })

  const issue = useLockedMutation(issueInvoice, 'Emitiendo documento...')
  const pay = useLockedMutation(
    ({ id, data }: { id: number; data: RecordPaymentInput }) => recordInvoicePayment(id, data),
    'Registrando pago...',
  )
  const cancel = useLockedMutation(
    ({ id, data }: { id: number; data?: { notes?: string } }) => cancelInvoice(id, data ?? {}),
    'Cancelando documento...',
  )
  const fromRequest = useLockedMutation(
    (requestId: number) => issueInvoiceFromRequest(requestId),
    'Generando factura en cobranza...',
  )
  const reverse = useLockedMutation(
    ({ paymentId, reason }: { paymentId: number; reason: string }) =>
      reversePayment(paymentId, { reason }),
    'Revirtiendo pago...',
  )
  const suspendCheck = useLockedMutation(
    () => runSuspensionCheck(),
    'Evaluando suspensiones (+90d)...',
  )

  return {
    items: query.data?.items ?? [],
    total: query.data?.total ?? 0,
    page: query.data?.page ?? 1,
    perPage: query.data?.per_page ?? 25,
    totalPages: query.data?.total_pages ?? 1,
    kpis: query.data?.kpis,
    aging: query.data?.aging ?? [],
    isLoading: query.isLoading,
    isApiError: query.isError,
    errorMessage: query.error instanceof Error ? query.error.message : null,
    refetch: query.refetch,
    issueInvoice: (data: IssueInvoiceInput) => issue.mutateAsync(data),
    issueInvoiceFromRequest: (requestId: number) => fromRequest.mutateAsync(requestId),
    recordPayment: (id: number, data: RecordPaymentInput) => pay.mutateAsync({ id, data }),
    cancelInvoice: (id: number, data?: { notes?: string }) => cancel.mutateAsync({ id, data }),
    reversePayment: (paymentId: number, reason: string) =>
      reverse.mutateAsync({ paymentId, reason }),
    runSuspensionCheck: () => suspendCheck.mutateAsync(undefined),
    isIssuing: issue.isPending,
    isPaying: pay.isPending,
    isCancelling: cancel.isPending,
    isReversing: reverse.isPending,
    isCheckingSuspension: suspendCheck.isPending,
  }
}

/** Cartera consolidada por contrato. */
export function useCollectionsContracts(
  params: Pick<CollectionsQueryParams, 'page' | 'per_page' | 'search' | 'segment_id'>,
) {
  const query = useQuery({
    queryKey: [...COLLECTIONS_QUERY_KEY, 'contracts', params],
    queryFn: () => fetchContractsPortfolio(params),
    staleTime: 15_000,
  })

  return {
    items: query.data?.items ?? [],
    total: query.data?.total ?? 0,
    page: query.data?.page ?? 1,
    perPage: query.data?.per_page ?? 25,
    totalPages: query.data?.total_pages ?? 1,
    isLoading: query.isLoading,
    isApiError: query.isError,
    errorMessage: query.error instanceof Error ? query.error.message : null,
    refetch: query.refetch,
  }
}

/** Detalle de documento con historial de pagos. */
export function useInvoiceDetail(id: number | null, enabled = true) {
  return useQuery({
    queryKey: [...COLLECTIONS_QUERY_KEY, 'invoice', id],
    queryFn: () => fetchInvoiceDetail(id!),
    enabled: enabled && id != null && id > 0,
    staleTime: 10_000,
  })
}
