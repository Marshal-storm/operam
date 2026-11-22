import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useLoadingLock } from '@/context/LoadingLockContext'
import {
  approveServiceRequest,
  cancelServiceRequest,
  createServiceRequest,
  fetchRequestDetail,
  fetchRequestsPaginated,
  fetchRequestsPendingCount,
  quoteServiceRequest,
  rejectServiceRequest,
  RequestsApiError,
  type RequestsQueryParams,
} from '@/services/requestsService'
import type {
  ServiceRequestApproveInput,
  ServiceRequestCreateInput,
  ServiceRequestQuoteInput,
  ServiceRequestRejectInput,
} from '@/types/request'

export const REQUESTS_QUERY_KEY = ['requests'] as const
export const REQUESTS_PENDING_COUNT_KEY = ['requests-pending-count'] as const

async function invalidateRequestQueries(
  queryClient: ReturnType<typeof useQueryClient>,
): Promise<void> {
  await Promise.all([
    queryClient.invalidateQueries({ queryKey: [...REQUESTS_QUERY_KEY] }),
    queryClient.invalidateQueries({ queryKey: [...REQUESTS_PENDING_COUNT_KEY] }),
    queryClient.invalidateQueries({ queryKey: ['contracts'] }),
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
      await invalidateRequestQueries(queryClient)
    },
  })
}

/**
 * Badge naranja del Sidebar — conteo vivo de pendientes por alcance de segmento.
 */
export function useRequestsPendingCount(enabled = true) {
  const query = useQuery({
    queryKey: [...REQUESTS_PENDING_COUNT_KEY],
    queryFn: fetchRequestsPendingCount,
    enabled,
    staleTime: 15_000,
    refetchInterval: 60_000,
  })

  return {
    pendingCount: query.data ?? 0,
    isLoading: query.isLoading,
    refetch: query.refetch,
  }
}

/**
 * Bandeja de solicitudes: listado paginado + mutaciones con candado Operam.
 */
export function useRequests(params: RequestsQueryParams) {
  const query = useQuery({
    queryKey: [...REQUESTS_QUERY_KEY, params],
    queryFn: () => fetchRequestsPaginated(params),
    staleTime: 15_000,
  })

  const create = useLockedMutation(createServiceRequest, 'Creando solicitud...')
  const quote = useLockedMutation(
    ({ id, data }: { id: number; data: ServiceRequestQuoteInput }) =>
      quoteServiceRequest(id, data),
    'Cotizando solicitud...',
  )
  const approve = useLockedMutation(
    ({ id, data }: { id: number; data: ServiceRequestApproveInput }) =>
      approveServiceRequest(id, data),
    'Aprobando solicitud...',
  )
  const reject = useLockedMutation(
    ({ id, data }: { id: number; data: ServiceRequestRejectInput }) =>
      rejectServiceRequest(id, data),
    'Rechazando solicitud...',
  )
  const cancel = useLockedMutation(
    ({ id, data }: { id: number; data?: { resolution_notes?: string } }) =>
      cancelServiceRequest(id, data ?? {}),
    'Cancelando solicitud...',
  )

  return {
    items: query.data?.items ?? [],
    total: query.data?.total ?? 0,
    page: query.data?.page ?? params.page ?? 1,
    perPage: query.data?.per_page ?? params.per_page ?? 25,
    kpis: query.data?.kpis ?? {
      pending_count: 0,
      quoted_count: 0,
      approved_month_count: 0,
      approved_one_time_total_mxn: '0.00',
    },
    isLoading: query.isLoading,
    isFetching: query.isFetching,
    isApiError: query.error instanceof RequestsApiError || query.isError,
    errorMessage:
      query.error instanceof Error ? query.error.message : 'Error al cargar solicitudes',
    refetch: query.refetch,
    createRequest: (input: ServiceRequestCreateInput) => create.mutateAsync(input),
    quoteRequest: (id: number, data: ServiceRequestQuoteInput) =>
      quote.mutateAsync({ id, data }),
    approveRequest: (id: number, data: ServiceRequestApproveInput = {}) =>
      approve.mutateAsync({ id, data }),
    rejectRequest: (id: number, data: ServiceRequestRejectInput) =>
      reject.mutateAsync({ id, data }),
    cancelRequest: (id: number, data: { resolution_notes?: string } = {}) =>
      cancel.mutateAsync({ id, data }),
  }
}

export function useRequestDetail(id: number | null) {
  return useQuery({
    queryKey: [...REQUESTS_QUERY_KEY, 'detail', id],
    queryFn: () => fetchRequestDetail(id as number),
    enabled: id != null && id > 0,
    staleTime: 10_000,
  })
}
