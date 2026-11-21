import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useLoadingLock } from '@/context/LoadingLockContext'
import {
  ContractsApiError,
  createContract,
  fetchContractsPaginated,
  type ContractsQueryParams,
} from '@/services/contractsService'
import { fetchEmitters } from '@/services/catalogService'
import type { ContractCreateInput } from '@/types/contract'

export const CONTRACTS_QUERY_KEY = ['contracts'] as const

/**
 * Hook del listado de contratos: paginación servidor + mutación de alta con candado.
 */
export function useContracts(params: ContractsQueryParams) {
  const queryClient = useQueryClient()
  const { withScreenLock } = useLoadingLock()

  const query = useQuery({
    queryKey: [...CONTRACTS_QUERY_KEY, params],
    queryFn: () => fetchContractsPaginated(params),
    staleTime: 15_000,
    placeholderData: (prev) => prev,
  })

  const issuers = useQuery({
    queryKey: ['config', 'emitters'],
    queryFn: fetchEmitters,
    staleTime: 60_000,
  })

  const create = useMutation({
    mutationFn: (input: ContractCreateInput) =>
      withScreenLock(() => createContract(input), 'Creando contrato...'),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: [...CONTRACTS_QUERY_KEY] })
    },
  })

  return {
    items: query.data?.items ?? [],
    total: query.data?.total ?? 0,
    page: query.data?.page ?? params.page ?? 1,
    perPage: query.data?.per_page ?? params.per_page ?? 25,
    summary: query.data?.summary ?? {
      total_contracts: 0,
      active_contracts: 0,
      total_mrr_mxn: '0.00',
      suspended_contracts: 0,
    },
    issuers: issuers.data ?? [],
    isLoading: query.isLoading,
    isFetching: query.isFetching,
    isApiError: query.error instanceof ContractsApiError || query.isError,
    errorMessage:
      query.error instanceof Error ? query.error.message : 'Error al cargar contratos',
    refetch: query.refetch,
    createContract: create.mutateAsync,
    isCreating: create.isPending,
  }
}
