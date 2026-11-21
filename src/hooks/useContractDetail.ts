import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useLoadingLock } from '@/context/LoadingLockContext'
import { CONTRACTS_QUERY_KEY } from '@/hooks/useContracts'
import {
  ContractsApiError,
  fetchContractDetail,
  runContractLifecycle,
} from '@/services/contractsService'
import type { ContractLifecycleInput } from '@/types/contract'

export const CONTRACT_DETAIL_QUERY_KEY = ['contract'] as const

/**
 * Detalle de contrato + mutación de ciclo de vida con candado Operam Aura.
 */
export function useContractDetail(contractId: number | null) {
  const queryClient = useQueryClient()
  const { withScreenLock } = useLoadingLock()

  const query = useQuery({
    queryKey: [...CONTRACT_DETAIL_QUERY_KEY, contractId],
    queryFn: () => fetchContractDetail(contractId as number),
    enabled: contractId != null && contractId > 0,
    staleTime: 10_000,
  })

  const lifecycle = useMutation({
    mutationFn: (input: ContractLifecycleInput) =>
      withScreenLock(
        () => runContractLifecycle(contractId as number, input),
        'Aplicando operación de ciclo de vida…',
      ),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: [...CONTRACT_DETAIL_QUERY_KEY, contractId] }),
        queryClient.invalidateQueries({ queryKey: [...CONTRACTS_QUERY_KEY] }),
        queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
      ])
    },
  })

  return {
    contract: query.data ?? null,
    isLoading: query.isLoading,
    isFetching: query.isFetching,
    isNotFound: query.error instanceof ContractsApiError && query.error.status === 404,
    isApiError: query.error instanceof ContractsApiError || query.isError,
    errorMessage:
      query.error instanceof Error ? query.error.message : 'Error al cargar el contrato',
    refetch: query.refetch,
    runLifecycle: lifecycle.mutateAsync,
    isLifecyclePending: lifecycle.isPending,
  }
}
