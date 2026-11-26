import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useLoadingLock } from '@/context/LoadingLockContext'
import {
  EXTERNAL_SERVICES_QUERY_KEY,
  fetchExternalServices,
  fetchIncomingEvents,
  fetchJobRuns,
  fetchOutbox,
  INCOMING_EVENTS_QUERY_KEY,
  JOB_RUNS_QUERY_KEY,
  OUTBOX_QUERY_KEY,
  processOutboxNow,
  retryOutboxMessage,
  runJobNow,
  testExternalService,
  updateExternalService,
} from '@/services/externalServicesService'
import type { ExternalServiceKey, ExternalServiceWriteInput, JobName } from '@/types/externalServices'

/** Catálogo de servicios externos (orden COALESCE(updated_at, created_at) DESC del servidor). */
export function useExternalServicesList() {
  return useQuery({
    queryKey: EXTERNAL_SERVICES_QUERY_KEY,
    queryFn: fetchExternalServices,
  })
}

/** Monitor del outbox: paginación + filtros en servidor; KPIs vienen en la misma respuesta. */
export function useOutboxMessages(params: {
  page: number
  perPage: number
  status: string
  destination: string
  search: string
}) {
  return useQuery({
    queryKey: [
      ...OUTBOX_QUERY_KEY,
      params.page,
      params.perPage,
      params.status,
      params.destination,
      params.search,
    ],
    queryFn: () =>
      fetchOutbox({
        page: params.page,
        per_page: params.perPage,
        status: params.status,
        destination: params.destination,
        search: params.search,
      }),
    placeholderData: keepPreviousData,
  })
}

/** Bitácora de webhooks entrantes (solo lectura). */
export function useIncomingEvents(params: {
  page: number
  perPage: number
  origin: string
  processed: string
  search: string
  enabled?: boolean
}) {
  return useQuery({
    queryKey: [
      ...INCOMING_EVENTS_QUERY_KEY,
      params.page,
      params.perPage,
      params.origin,
      params.processed,
      params.search,
    ],
    queryFn: () =>
      fetchIncomingEvents({
        page: params.page,
        per_page: params.perPage,
        origin: params.origin,
        processed: params.processed,
        search: params.search,
      }),
    placeholderData: keepPreviousData,
    enabled: params.enabled !== false,
  })
}

/** Historial de corridas de jobs (orden started_at DESC del servidor) + última corrida por job. */
export function useJobRuns(params: { page: number; perPage: number; enabled?: boolean }) {
  return useQuery({
    queryKey: [...JOB_RUNS_QUERY_KEY, params.page, params.perPage],
    queryFn: () => fetchJobRuns({ page: params.page, per_page: params.perPage }),
    placeholderData: keepPreviousData,
    enabled: params.enabled !== false,
  })
}

/** Disparo manual de un job: bloquea pantalla y refresca historial + cola del outbox. */
export function useRunJobMutation() {
  const queryClient = useQueryClient()
  const { withScreenLock } = useLoadingLock()

  return useMutation({
    mutationFn: (jobName: JobName) => withScreenLock(() => runJobNow(jobName)),
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: JOB_RUNS_QUERY_KEY })
      void queryClient.invalidateQueries({ queryKey: OUTBOX_QUERY_KEY })
    },
  })
}

export function useExternalServiceMutations() {
  const queryClient = useQueryClient()
  const { withScreenLock } = useLoadingLock()

  const invalidateServices = () =>
    queryClient.invalidateQueries({ queryKey: EXTERNAL_SERVICES_QUERY_KEY })
  const invalidateOutbox = () => queryClient.invalidateQueries({ queryKey: OUTBOX_QUERY_KEY })

  const updateMutation = useMutation({
    mutationFn: ({ key, input }: { key: ExternalServiceKey; input: ExternalServiceWriteInput }) =>
      withScreenLock(() => updateExternalService(key, input)),
    onSuccess: invalidateServices,
  })

  const testMutation = useMutation({
    mutationFn: (key: ExternalServiceKey) => withScreenLock(() => testExternalService(key)),
    onSettled: invalidateServices,
  })

  const retryMutation = useMutation({
    mutationFn: (id: number) => withScreenLock(() => retryOutboxMessage(id)),
    onSettled: invalidateOutbox,
  })

  const processNowMutation = useMutation({
    mutationFn: () => withScreenLock(() => processOutboxNow()),
    onSettled: invalidateOutbox,
  })

  return { updateMutation, testMutation, retryMutation, processNowMutation }
}
