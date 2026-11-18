import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useLoadingLock } from '@/context/LoadingLockContext'
import {
  CatalogApiError,
  createInstance,
  createIssuer,
  createPriceList,
  createPriceListItem,
  createPromotion,
  createService,
  fetchEmitters,
  fetchInstances,
  fetchIssuersPaginated,
  fetchPriceLists,
  fetchPromotions,
  fetchSegments,
  fetchServices,
  removeProductFromPriceList,
  savePriceEdits,
  saveVolumeMatrix,
  toggleIssuerActive,
  togglePromotionActive,
  toggleServiceActive,
  updateInstance,
  updateIssuer,
  updatePromotion,
  updateService,
  type InstancesQueryParams,
  type InstanceWriteInput,
  type IssuersQueryParams,
  type IssuerWriteInput,
  type PriceListItemWriteInput,
  type PriceListWriteInput,
  type PromotionWriteInput,
  type ServicesQueryParams,
  type ServiceWriteInput,
  type VolumeMatrixSavePlan,
} from '@/services/catalogService'

const KEYS = {
  services: ['config', 'services'] as const,
  priceLists: ['config', 'price-lists'] as const,
  promotions: ['config', 'promotions'] as const,
  instances: ['config', 'instances'] as const,
  segments: ['config', 'segments'] as const,
  emitters: ['config', 'emitters'] as const,
}

function useLockedMutation<TArgs, TResult>(
  mutationFn: (args: TArgs) => Promise<TResult>,
  invalidateKeys: ReadonlyArray<readonly string[]>,
  lockMessage = 'Guardando cambios en el ERP...',
) {
  const queryClient = useQueryClient()
  const { withScreenLock } = useLoadingLock()

  return useMutation({
    mutationFn: (args: TArgs) => withScreenLock(() => mutationFn(args), lockMessage),
    onSuccess: async () => {
      await Promise.all(
        invalidateKeys.map((key) => queryClient.invalidateQueries({ queryKey: [...key] })),
      )
    },
  })
}

export function useServicesConfig(params: ServicesQueryParams) {
  const query = useQuery({
    queryKey: [...KEYS.services, params],
    queryFn: () => fetchServices(params),
    staleTime: 15_000,
    placeholderData: (prev) => prev,
  })
  const segments = useQuery({ queryKey: KEYS.segments, queryFn: fetchSegments, staleTime: 60_000 })

  const create = useLockedMutation(createService, [KEYS.services])
  const update = useLockedMutation(
    ({ id, data }: { id: number; data: Partial<ServiceWriteInput> }) =>
      updateService(id, data),
    [KEYS.services],
  )
  const toggle = useLockedMutation(
    ({ id, activo }: { id: number; activo: boolean }) => toggleServiceActive(id, activo),
    [KEYS.services],
    'Actualizando disponibilidad...',
  )

  return {
    items: query.data?.items ?? [],
    total: query.data?.total ?? 0,
    page: query.data?.page ?? params.page ?? 1,
    perPage: query.data?.per_page ?? params.per_page ?? 25,
    isLoading: query.isLoading,
    isFetching: query.isFetching,
    segments: segments.data ?? [],
    isApiError: query.error instanceof CatalogApiError || query.isError,
    errorMessage: query.error instanceof Error ? query.error.message : 'Error al cargar servicios',
    refetch: query.refetch,
    createService: create.mutateAsync,
    updateService: update.mutateAsync,
    toggleService: toggle.mutateAsync,
  }
}

export function usePriceListsConfig() {
  const lists = useQuery({
    queryKey: KEYS.priceLists,
    queryFn: fetchPriceLists,
    staleTime: 15_000,
  })
  const services = useQuery({
    queryKey: [...KEYS.services, { page: 1, per_page: 100 }],
    queryFn: () => fetchServices({ page: 1, per_page: 100 }),
    staleTime: 30_000,
  })
  const segments = useQuery({ queryKey: KEYS.segments, queryFn: fetchSegments, staleTime: 60_000 })

  const createList = useLockedMutation(createPriceList, [KEYS.priceLists])
  const createItem = useLockedMutation(createPriceListItem, [KEYS.priceLists])
  const saveEdits = useLockedMutation(
    savePriceEdits,
    [KEYS.priceLists],
    'Guardando precios en MySQL...',
  )
  const saveVolume = useLockedMutation(
    saveVolumeMatrix,
    [KEYS.priceLists],
    'Guardando escalones de precio...',
  )
  const removeProduct = useLockedMutation(
    removeProductFromPriceList,
    [KEYS.priceLists],
    'Quitando producto de la lista...',
  )

  return {
    lists: lists.data ?? [],
    services: services.data?.items ?? [],
    segments: segments.data ?? [],
    isLoading: lists.isLoading,
    isFetching: lists.isFetching,
    isApiError: lists.error instanceof CatalogApiError || lists.isError,
    errorMessage: lists.error instanceof Error ? lists.error.message : 'Error al cargar precios',
    refetch: lists.refetch,
    createPriceList: (input: PriceListWriteInput) => createList.mutateAsync(input),
    createPriceListItem: (input: PriceListItemWriteInput) => createItem.mutateAsync(input),
    removeProductFromPriceList: (input: { lista_precio_id: number; servicio_id: number }) =>
      removeProduct.mutateAsync(input),
    savePriceEdits: saveEdits.mutateAsync,
    saveVolumeMatrix: (plan: VolumeMatrixSavePlan) => saveVolume.mutateAsync(plan),
    isSaving: saveEdits.isPending || saveVolume.isPending || removeProduct.isPending,
  }
}

export function usePromotionsConfig() {
  const query = useQuery({
    queryKey: KEYS.promotions,
    queryFn: fetchPromotions,
    staleTime: 20_000,
  })
  const segments = useQuery({ queryKey: KEYS.segments, queryFn: fetchSegments, staleTime: 60_000 })

  const create = useLockedMutation(createPromotion, [KEYS.promotions])
  const update = useLockedMutation(
    ({ id, data }: { id: number; data: Partial<PromotionWriteInput> }) =>
      updatePromotion(id, data),
    [KEYS.promotions],
  )
  const toggle = useLockedMutation(
    ({ id, activa }: { id: number; activa: boolean }) =>
      togglePromotionActive(id, activa),
    [KEYS.promotions],
    'Actualizando promoción...',
  )

  return {
    ...query,
    promotions: query.data ?? [],
    segments: segments.data ?? [],
    isApiError: query.error instanceof CatalogApiError || query.isError,
    errorMessage:
      query.error instanceof Error ? query.error.message : 'Error al cargar promociones',
    createPromotion: create.mutateAsync,
    updatePromotion: update.mutateAsync,
    togglePromotion: toggle.mutateAsync,
  }
}

export function useIssuersConfig(params: IssuersQueryParams) {
  const query = useQuery({
    queryKey: [...KEYS.emitters, 'paginated', params],
    queryFn: () => fetchIssuersPaginated(params),
    staleTime: 15_000,
    placeholderData: (prev) => prev,
  })

  const create = useLockedMutation(
    createIssuer,
    [KEYS.emitters, KEYS.instances],
    'Guardando emisor...',
  )
  const update = useLockedMutation(
    ({ id, data }: { id: number; data: Partial<IssuerWriteInput> }) =>
      updateIssuer(id, data),
    [KEYS.emitters, KEYS.instances],
    'Actualizando emisor...',
  )
  const toggle = useLockedMutation(
    ({ id, activo }: { id: number; activo: boolean }) => toggleIssuerActive(id, activo),
    [KEYS.emitters, KEYS.instances],
    'Actualizando estado del emisor...',
  )

  return {
    items: query.data?.items ?? [],
    total: query.data?.total ?? 0,
    page: query.data?.page ?? params.page ?? 1,
    perPage: query.data?.per_page ?? params.per_page ?? 25,
    isLoading: query.isLoading,
    isFetching: query.isFetching,
    isApiError: query.error instanceof CatalogApiError || query.isError,
    errorMessage:
      query.error instanceof Error ? query.error.message : 'Error al cargar emisores',
    refetch: query.refetch,
    createIssuer: create.mutateAsync,
    updateIssuer: update.mutateAsync,
    toggleIssuer: toggle.mutateAsync,
  }
}

export function useInstancesConfig(params: InstancesQueryParams) {
  const query = useQuery({
    queryKey: [...KEYS.instances, params],
    queryFn: () => fetchInstances(params),
    staleTime: 15_000,
    placeholderData: (prev) => prev,
  })
  const emitters = useQuery({ queryKey: KEYS.emitters, queryFn: fetchEmitters, staleTime: 60_000 })
  const segments = useQuery({ queryKey: KEYS.segments, queryFn: fetchSegments, staleTime: 60_000 })

  const create = useLockedMutation(createInstance, [KEYS.instances, KEYS.emitters])
  const update = useLockedMutation(
    ({ id, data }: { id: number; data: Partial<InstanceWriteInput> }) =>
      updateInstance(id, data),
    [KEYS.instances, KEYS.emitters],
  )

  return {
    items: query.data?.items ?? [],
    total: query.data?.total ?? 0,
    byEmitter: query.data?.by_emitter ?? [],
    isLoading: query.isLoading,
    isFetching: query.isFetching,
    emitters: emitters.data ?? [],
    segments: segments.data ?? [],
    isApiError: query.error instanceof CatalogApiError || query.isError,
    errorMessage:
      query.error instanceof Error ? query.error.message : 'Error al cargar instancias',
    refetch: query.refetch,
    createInstance: create.mutateAsync,
    updateInstance: update.mutateAsync,
  }
}
