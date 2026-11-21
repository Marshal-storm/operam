import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useLoadingLock } from '@/context/LoadingLockContext'
import {
  ClientsApiError,
  createClient,
  fetchClientsPaginated,
  toggleClientActive,
  updateClient,
  type ClientsQueryParams,
} from '@/services/clientsService'
import { fetchEmitters } from '@/services/catalogService'
import type { ClientWriteInput } from '@/types/client'

export const CLIENTS_QUERY_KEY = ['clients'] as const

function useLockedMutation<TArgs, TResult>(
  mutationFn: (args: TArgs) => Promise<TResult>,
  lockMessage = 'Guardando cliente...',
) {
  const queryClient = useQueryClient()
  const { withScreenLock } = useLoadingLock()

  return useMutation({
    mutationFn: (args: TArgs) => withScreenLock(() => mutationFn(args), lockMessage),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: [...CLIENTS_QUERY_KEY] })
    },
  })
}

/**
 * Hook del directorio de clientes: listado paginado + mutaciones con candado Operam.
 */
export function useClients(params: ClientsQueryParams) {
  const query = useQuery({
    queryKey: [...CLIENTS_QUERY_KEY, params],
    queryFn: () => fetchClientsPaginated(params),
    staleTime: 15_000,
    // Sin placeholderData: evita mostrar el orden viejo mientras refresca tras create/edit/toggle (§3.4).
  })

  const issuers = useQuery({
    queryKey: ['config', 'emitters'],
    queryFn: fetchEmitters,
    staleTime: 60_000,
  })

  const create = useLockedMutation(createClient, 'Creando cliente...')
  const update = useLockedMutation(
    ({ id, data }: { id: number; data: Partial<ClientWriteInput> }) =>
      updateClient(id, data),
    'Actualizando cliente...',
  )
  const toggle = useLockedMutation(
    ({ id, active }: { id: number; active: boolean }) => toggleClientActive(id, active),
    'Actualizando estado del cliente...',
  )

  return {
    items: query.data?.items ?? [],
    total: query.data?.total ?? 0,
    page: query.data?.page ?? params.page ?? 1,
    perPage: query.data?.per_page ?? params.per_page ?? 25,
    summary: query.data?.summary ?? {
      total: 0,
      active_count: 0,
      auto_stamp_count: 0,
      excluded_suspension_count: 0,
    },
    issuers: issuers.data ?? [],
    isLoading: query.isLoading,
    isFetching: query.isFetching,
    isApiError: query.error instanceof ClientsApiError || query.isError,
    errorMessage:
      query.error instanceof Error ? query.error.message : 'Error al cargar clientes',
    refetch: query.refetch,
    createClient: create.mutateAsync,
    updateClient: update.mutateAsync,
    toggleClient: toggle.mutateAsync,
  }
}
