import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useLoadingLock } from '@/context/LoadingLockContext'
import {
  ClientContactsApiError,
  createClientContact,
  deleteClientContact,
  fetchClientContacts,
  generateContactPortalLink,
  patchClientContact,
  updateClientContact,
  type ClientContactWriteInput,
} from '@/services/clientContactsService'

/** Clave de consulta React Query para contactos de un cliente. */
export function clientContactsQueryKey(clientId: number | null) {
  return ['clients', clientId ?? 0, 'contacts'] as const
}

/**
 * Hook CRUD de contactos por cliente (RF-1.5) con candado global Operam.
 */
export function useClientContacts(clientId: number | null) {
  const queryClient = useQueryClient()
  const { withScreenLock } = useLoadingLock()
  const enabled = typeof clientId === 'number' && clientId > 0
  const queryKey = clientContactsQueryKey(clientId)

  const query = useQuery({
    queryKey,
    queryFn: () => fetchClientContacts(clientId as number),
    enabled,
    staleTime: 15_000,
  })

  const create = useMutation({
    mutationFn: (input: ClientContactWriteInput) =>
      withScreenLock(() => createClientContact(input), 'Guardando contacto...'),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey })
    },
  })

  const update = useMutation({
    mutationFn: ({
      id,
      data,
    }: {
      id: number
      data: Partial<ClientContactWriteInput>
    }) => withScreenLock(() => updateClientContact(id, data), 'Actualizando contacto...'),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey })
    },
  })

  const patch = useMutation({
    mutationFn: ({
      id,
      data,
    }: {
      id: number
      data: Partial<ClientContactWriteInput>
    }) =>
      withScreenLock(
        () => patchClientContact(id, data),
        'Actualizando estado del contacto...',
      ),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey })
    },
  })

  // No invalida la lista: generar una liga no modifica el contacto.
  const portalLink = useMutation({
    mutationFn: (input: number | { id: number; sendEmail?: boolean }) => {
      const id = typeof input === 'number' ? input : input.id
      const sendEmail = typeof input === 'number' ? true : (input.sendEmail ?? true)
      return withScreenLock(
        () => generateContactPortalLink(id, { sendEmail }),
        sendEmail ? 'Generando y enviando liga mágica...' : 'Generando liga mágica...',
      )
    },
  })

  const remove = useMutation({
    mutationFn: (id: number) =>
      withScreenLock(() => deleteClientContact(id), 'Desactivando contacto...'),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey })
    },
  })

  return {
    contacts: query.data ?? [],
    isLoading: query.isLoading,
    isFetching: query.isFetching,
    isApiError: query.error instanceof ClientContactsApiError || query.isError,
    errorMessage:
      query.error instanceof Error ? query.error.message : 'Error al cargar contactos',
    refetch: query.refetch,
    createContact: create.mutateAsync,
    updateContact: update.mutateAsync,
    patchContact: patch.mutateAsync,
    generatePortalLink: portalLink.mutateAsync,
    deleteContact: remove.mutateAsync,
  }
}
