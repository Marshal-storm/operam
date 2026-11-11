import { useQuery } from '@tanstack/react-query'
import {
  DashboardApiError,
  DashboardEmptyError,
  fetchDashboardSnapshot,
  type DataSourceMode,
  type DashboardQueryParams,
} from '@/services/dashboardService'
import type { ProductSegment } from '@/types/mrr'

export type UseDashboardDataParams = {
  mode: DataSourceMode
  segment: ProductSegment
  month: string
}

/**
 * Hook del Dashboard con TanStack Query.
 * Soporta modo mock (datos locales) y API real con estados UI-7.
 */
export function useDashboardData({ mode, segment, month }: UseDashboardDataParams) {
  const params: DashboardQueryParams = { mode, segment, month }

  const query = useQuery({
    queryKey: ['dashboard', mode, segment, month],
    queryFn: () => fetchDashboardSnapshot(params),
    // En mock no hace falta refetch agresivo; en API sí queremos control manual.
    staleTime: mode === 'mock' ? Number.POSITIVE_INFINITY : 30_000,
    retry: mode === 'api' ? 1 : false,
  })

  const isEmpty =
    query.error instanceof DashboardEmptyError ||
    (query.isSuccess &&
      query.data.aging.length === 0 &&
      query.data.allMovements.length === 0 &&
      query.data.kpis.mrrMxn === 0)

  const isApiError = query.error instanceof DashboardApiError ||
    (query.isError && !(query.error instanceof DashboardEmptyError))

  return {
    ...query,
    isEmpty,
    isApiError,
    errorMessage:
      query.error instanceof Error
        ? query.error.message
        : 'Ocurrió un error inesperado al cargar el dashboard.',
  }
}
