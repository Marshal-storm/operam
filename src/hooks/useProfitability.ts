import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useLoadingLock } from '@/context/LoadingLockContext'
import {
  createSegmentCost,
  deleteSegmentCost,
  fetchExceptionsReport,
  fetchNonRecurringRevenue,
  fetchProfitabilitySummary,
  fetchSegmentCosts,
  updateSegmentCost,
} from '@/services/profitabilityService'
import type { SegmentCostWriteInput } from '@/types/profitability'

export function useProfitabilitySummary(params: {
  month: string
  segmentId: number | null
}) {
  return useQuery({
    queryKey: ['segment-profitability', 'summary', params.month, params.segmentId],
    queryFn: () =>
      fetchProfitabilitySummary({
        month: params.month,
        segment_id: params.segmentId,
      }),
  })
}

export function useSegmentCosts(params: {
  page: number
  perPage: number
  month: string
  segmentId: number | null
  search?: string
  category?: string
}) {
  return useQuery({
    queryKey: [
      'segment-profitability',
      'costs',
      params.page,
      params.perPage,
      params.month,
      params.segmentId,
      params.search ?? '',
      params.category ?? '',
    ],
    queryFn: () =>
      fetchSegmentCosts({
        page: params.page,
        per_page: params.perPage,
        month: params.month,
        segment_id: params.segmentId,
        search: params.search,
        category: params.category,
      }),
  })
}

export function useNonRecurringRevenue(params: {
  page: number
  perPage: number
  month: string
  segmentId: number | null
  enabled?: boolean
}) {
  return useQuery({
    queryKey: [
      'segment-profitability',
      'non-recurring',
      params.page,
      params.perPage,
      params.month,
      params.segmentId,
    ],
    queryFn: () =>
      fetchNonRecurringRevenue({
        page: params.page,
        per_page: params.perPage,
        month: params.month,
        segment_id: params.segmentId,
      }),
    enabled: params.enabled !== false,
  })
}

export function useExceptionsReport(params: {
  page: number
  perPage: number
  from: string
  to: string
  segmentId: number | null
  enabled?: boolean
}) {
  return useQuery({
    queryKey: [
      'segment-profitability',
      'exceptions',
      params.page,
      params.perPage,
      params.from,
      params.to,
      params.segmentId,
    ],
    queryFn: () =>
      fetchExceptionsReport({
        page: params.page,
        per_page: params.perPage,
        from: params.from,
        to: params.to,
        segment_id: params.segmentId,
      }),
    enabled: params.enabled !== false,
  })
}

export function useSegmentCostMutations() {
  const queryClient = useQueryClient()
  const { withScreenLock } = useLoadingLock()

  const invalidate = async () => {
    await queryClient.invalidateQueries({ queryKey: ['segment-profitability'] })
  }

  const createMutation = useMutation({
    mutationFn: (input: SegmentCostWriteInput) =>
      withScreenLock(() => createSegmentCost(input)),
    onSuccess: invalidate,
  })

  const updateMutation = useMutation({
    mutationFn: ({ id, input }: { id: number; input: Partial<SegmentCostWriteInput> }) =>
      withScreenLock(() => updateSegmentCost(id, input)),
    onSuccess: invalidate,
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => withScreenLock(() => deleteSegmentCost(id)),
    onSuccess: invalidate,
  })

  return { createMutation, updateMutation, deleteMutation }
}
