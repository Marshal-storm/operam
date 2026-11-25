import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useLoadingLock } from '@/context/LoadingLockContext'
import {
  consumeBalance,
  fetchContractBalances,
  grantBalance,
} from '@/services/balancesService'
import type { ConsumeBalanceInput, GrantBalanceInput } from '@/types/balance'

export function useContractBalances(
  contractId: number,
  params?: { page?: number; perPage?: number; enabled?: boolean },
) {
  return useQuery({
    queryKey: [
      'balances',
      'contract',
      contractId,
      params?.page ?? 1,
      params?.perPage ?? 25,
    ],
    queryFn: () =>
      fetchContractBalances(contractId, {
        page: params?.page,
        per_page: params?.perPage,
      }),
    enabled: Number.isFinite(contractId) && contractId > 0 && params?.enabled !== false,
  })
}

export function useBalanceMutations(contractId: number) {
  const queryClient = useQueryClient()
  const { withScreenLock } = useLoadingLock()

  const invalidate = async () => {
    await queryClient.invalidateQueries({ queryKey: ['balances', 'contract', contractId] })
    await queryClient.invalidateQueries({ queryKey: ['contract', contractId] })
  }

  const consumeMutation = useMutation({
    mutationFn: (input: ConsumeBalanceInput) =>
      withScreenLock(() => consumeBalance(input)),
    onSuccess: invalidate,
  })

  const grantMutation = useMutation({
    mutationFn: (input: GrantBalanceInput) => withScreenLock(() => grantBalance(input)),
    onSuccess: invalidate,
  })

  return { consumeMutation, grantMutation }
}
