import {
  tableFeatures,
  useTable,
  type ColumnDef,
} from '@tanstack/react-table'
import { useQuery } from '@tanstack/react-query'
import {
  ArrowLeft,
  ChevronDown,
  MinusCircle,
  PlusCircle,
  RefreshCw,
  Repeat,
  Ban,
  DollarSign,
  Building2,
  RotateCcw,
} from 'lucide-react'
import { useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ContractBalancesPanel } from '@/components/contracts/ContractBalancesPanel'
import { GrantBalanceModal } from '@/components/contracts/GrantBalanceModal'
import { LifecycleOperationModal } from '@/components/contracts/LifecycleOperationModal'
import { RegisterConsumptionModal } from '@/components/contracts/RegisterConsumptionModal'
import { ReverseMovementModal } from '@/components/contracts/ReverseMovementModal'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard } from '@/components/ui/AppCard'
import {
  AppActionsColumnHeader,
  AppRowActionsMenu,
} from '@/components/ui/AppRowActionsMenu'
import { AppTableHeadRow, AppTableWrapper } from '@/components/ui/AppTableWrapper'
import { AppTabs } from '@/components/ui/AppTabs'
import { Skeleton } from '@/components/ui/skeleton'
import { useToast } from '@/context/ToastContext'
import { useBalanceMutations, useContractBalances } from '@/hooks/useBalances'
import { useContractDetail } from '@/hooks/useContractDetail'
import { usePermissions } from '@/hooks/usePermissions'
import {
  useScreenPreferences,
  type PageSizeOption,
} from '@/context/ScreenPreferencesContext'
import { cn, formatCurrencyMx, formatDateEsMx } from '@/lib/utils'
import { fetchEmitters, fetchPriceLists, fetchServices } from '@/services/catalogService'
import type {
  ContractLifecycleInput,
  ContractLifecycleOperation,
  ContractLine,
  ContractMovement,
  ContractStatus,
} from '@/types/contract'
import * as DropdownMenu from '@radix-ui/react-dropdown-menu'

const linesTableFeatures = tableFeatures({})
const movementsTableFeatures = tableFeatures({})

const STATUS_TONE: Record<ContractStatus, 'success' | 'warning' | 'danger' | 'neutral'> = {
  active: 'success',
  suspended: 'warning',
  cancelled: 'danger',
  draft: 'neutral',
}

const STATUS_LABEL: Record<ContractStatus, string> = {
  active: 'Activo',
  suspended: 'Suspendido',
  cancelled: 'Cancelado',
  draft: 'Borrador',
}

const CATEGORY_LABEL: Record<string, string> = {
  new: 'Alta',
  expansion: 'Expansión',
  contraction: 'Contracción',
  price_adjustment: 'Ajuste de precio',
  frequency_change: 'Cambio de frecuencia',
  transfer: 'Transferencia',
  churn: 'Churn',
  reactivation: 'Reactivación',
}

const CATEGORY_TONE: Record<string, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  new: 'success',
  expansion: 'info',
  contraction: 'warning',
  price_adjustment: 'info',
  frequency_change: 'info',
  transfer: 'neutral',
  churn: 'danger',
  reactivation: 'success',
}

const LIFECYCLE_MENU_SURFACE =
  'z-40 min-w-[260px] rounded-[20px] p-1.5 ' +
  'bg-white/80 backdrop-blur-2xl backdrop-saturate-150 ' +
  'border border-white/85 ring-1 ring-slate-900/[0.07] ' +
  'shadow-[inset_0_1px_1px_rgba(255,255,255,0.95),0_18px_40px_-10px_rgba(15,23,42,0.18)] ' +
  'dark:bg-[linear-gradient(145deg,rgba(36,36,42,0.86)_0%,rgba(16,16,20,0.92)_100%)] ' +
  'dark:border-white/[0.13] ' +
  'dark:shadow-[inset_0_1px_0_rgba(255,255,255,0.18),0_20px_48px_-10px_rgba(0,0,0,0.85)]'

/**
 * Detalle de contrato + Motor de ciclo de vida — Página 27 (`/contratos/:id`).
 */
export function ContractDetailPage() {
  const { id: idParam } = useParams<{ id: string }>()
  const contractId = Number(idParam)
  const navigate = useNavigate()
  const toast = useToast()
  const { canEdit } = usePermissions()
  const { rowPaddingClass } = useScreenPreferences()
  const {
    contract,
    isLoading,
    isNotFound,
    isApiError,
    errorMessage,
    refetch,
    runLifecycle,
    isLifecyclePending,
    runReversal,
    isReversalPending,
  } = useContractDetail(Number.isFinite(contractId) && contractId > 0 ? contractId : null)

  const [lifecycleOp, setLifecycleOp] = useState<ContractLifecycleOperation | null>(null)
  const [preselectedLineId, setPreselectedLineId] = useState<number | null>(null)
  const [modalOpen, setModalOpen] = useState(false)
  const [reversalMovement, setReversalMovement] = useState<ContractMovement | null>(null)
  const [reversalOpen, setReversalOpen] = useState(false)
  const [detailTab, setDetailTab] = useState<'lines' | 'consumptions' | 'movements'>('lines')
  const [consumeOpen, setConsumeOpen] = useState(false)
  const [grantOpen, setGrantOpen] = useState(false)
  const [consumptionsPage, setConsumptionsPage] = useState(1)
  const [consumptionsPageSize, setConsumptionsPageSize] =
    useState<PageSizeOption>(25)

  const balancesQuery = useContractBalances(contractId, {
    page: consumptionsPage,
    perPage: consumptionsPageSize,
    enabled: Number.isFinite(contractId) && contractId > 0,
  })
  const { consumeMutation, grantMutation } = useBalanceMutations(contractId)

  const servicesQuery = useQuery({
    queryKey: ['catalog', 'services', 'active', contract?.segment_id],
    queryFn: () =>
      fetchServices({
        page: 1,
        per_page: 100,
        status: 'active',
        segment: contract?.segment_name,
      }),
    enabled: Boolean(contract?.segment_id),
    staleTime: 60_000,
  })

  const priceListsQuery = useQuery({
    queryKey: ['price-lists'],
    queryFn: fetchPriceLists,
    staleTime: 60_000,
  })

  const issuersQuery = useQuery({
    queryKey: ['config', 'emitters'],
    queryFn: fetchEmitters,
    staleTime: 60_000,
  })

  const contractPriceList = useMemo(() => {
    if (!contract?.price_list_id) return null
    return (priceListsQuery.data ?? []).find((pl) => pl.id === contract.price_list_id) ?? null
  }, [priceListsQuery.data, contract?.price_list_id])

  const priceListItems = useMemo(
    () => contractPriceList?.items ?? [],
    [contractPriceList],
  )

  const servicesOnPriceList = useMemo(() => {
    const ids = new Set(priceListItems.map((i) => i.service_id))
    return (servicesQuery.data?.items ?? []).filter((s) => s.is_active && ids.has(s.id))
  }, [servicesQuery.data, priceListItems])

  const recurringServiceOptions = useMemo(
    () =>
      servicesOnPriceList
        .filter((s) => s.service_type === 'recurring')
        .map((s) => ({
          value: String(s.id),
          label: `${s.code} — ${s.name}`,
          service_type: s.service_type,
        })),
    [servicesOnPriceList],
  )

  const balanceServiceOptions = useMemo(() => {
    const fromBags = (balancesQuery.data?.services ?? []).map((s) => ({
      value: String(s.service_id),
      label: `${s.service_code || s.service_id} — ${s.service_name}`,
    }))
    if (fromBags.length > 0) return fromBags
    return (servicesQuery.data?.items ?? [])
      .filter((s) => s.is_active)
      .map((s) => ({
        value: String(s.id),
        label: `${s.code} — ${s.name}`,
      }))
  }, [balancesQuery.data?.services, servicesQuery.data?.items])

  const issuerOptions = useMemo(
    () =>
      (issuersQuery.data ?? [])
        .filter((i) => i.is_active && i.id !== contract?.issuer_id)
        .map((i) => ({ value: String(i.id), label: i.name })),
    [issuersQuery.data, contract?.issuer_id],
  )

  function openLifecycle(op: ContractLifecycleOperation, lineId?: number) {
    setLifecycleOp(op)
    setPreselectedLineId(lineId ?? null)
    setModalOpen(true)
  }

  async function handleConfirm(input: ContractLifecycleInput) {
    try {
      const updated = await runLifecycle(input)
      toast.success(
        'Operación aplicada',
        `${updated.folio}: ${CATEGORY_LABEL[input.operation] ?? input.operation} registrada.`,
      )
      setModalOpen(false)
    } catch (error) {
      toast.apiError(error)
      throw error
    }
  }

  function isMovementReversible(mov: ContractMovement): boolean {
    return (
      !mov.is_reversed &&
      mov.reversed_movement_id == null &&
      Number(mov.mrr_delta_mxn) !== 0
    )
  }

  function openReversal(mov: ContractMovement) {
    setReversalMovement(mov)
    setReversalOpen(true)
  }

  async function handleConfirmReversal(input: { reason: string; effective_date: string }) {
    if (!reversalMovement) return
    try {
      const updated = await runReversal({
        movementId: reversalMovement.id,
        reason: input.reason,
        effective_date: input.effective_date,
      })
      toast.success(
        'Reversión contable registrada',
        `${updated.folio}: se agregó el asiento inverso del movimiento #${reversalMovement.id}.`,
      )
      setReversalOpen(false)
      setReversalMovement(null)
    } catch (error) {
      toast.apiError(error)
      throw error
    }
  }

  const lineColumns = useMemo<Array<ColumnDef<typeof linesTableFeatures, ContractLine>>>(
    () => [
      {
        accessorKey: 'description',
        header: 'SERVICIO',
        cell: ({ row }) => (
          <span className="font-medium">{row.original.description}</span>
        ),
      },
      {
        accessorKey: 'service_type',
        header: 'TIPO',
        cell: ({ row }) => (
          <AppBadge
            label={
              row.original.service_type === 'recurring'
                ? 'Recurrente'
                : row.original.service_type === 'consumption'
                  ? 'Consumo'
                  : 'Único'
            }
            tone={row.original.service_type === 'recurring' ? 'info' : 'neutral'}
          />
        ),
      },
      {
        accessorKey: 'quantity',
        header: 'CANTIDAD',
        cell: ({ row }) => (
          <span className="font-mono tabular-nums">{row.original.quantity}</span>
        ),
      },
      {
        accessorKey: 'unit_price',
        header: 'PRECIO PACTADO',
        cell: ({ row }) => (
          <span className="font-mono tabular-nums">
            {formatCurrencyMx(row.original.unit_price, { fractionDigits: 2 })}
          </span>
        ),
      },
      {
        accessorKey: 'subtotal',
        header: 'SUBTOTAL',
        cell: ({ row }) => {
          const discount = row.original.discount_amount
          const hasDiscount = Number(discount) > 0
          return (
            <div className="min-w-0">
              <span className="font-mono tabular-nums">
                {formatCurrencyMx(row.original.subtotal, { fractionDigits: 2 })}
              </span>
              {hasDiscount ? (
                <p className="mt-0.5 text-[11px] text-amber-600 dark:text-amber-400 whitespace-nowrap">
                  Desc. alta −{formatCurrencyMx(discount, { fractionDigits: 2 })}
                </p>
              ) : null}
            </div>
          )
        },
      },
      {
        accessorKey: 'mrr_mxn',
        header: 'MRR (MXN)',
        cell: ({ row }) => (
          <span className="block text-right font-mono font-medium tabular-nums">
            {formatCurrencyMx(row.original.mrr_mxn, { fractionDigits: 2 })}
          </span>
        ),
      },
      {
        accessorKey: 'active',
        header: 'ESTADO',
        cell: ({ row }) => (
          <AppBadge
            label={row.original.active ? 'Activa' : 'Inactiva'}
            tone={row.original.active ? 'success' : 'neutral'}
          />
        ),
      },
      {
        id: 'actions',
        header: () => <AppActionsColumnHeader />,
        cell: ({ row }) => {
          const line = row.original
          const canOps =
            canEdit('contracts') &&
            contract?.status === 'active' &&
            line.active &&
            line.service_type === 'recurring'
          return (
            <div className="flex justify-end">
              <AppRowActionsMenu
                actions={[
                  {
                    label: 'Expandir cantidad',
                    icon: <PlusCircle className="h-4 w-4" />,
                    hidden: !canOps,
                    onClick: () => openLifecycle('expansion', line.id),
                  },
                  {
                    label: 'Contraer / Quitar línea',
                    icon: <MinusCircle className="h-4 w-4" />,
                    hidden: !canOps,
                    onClick: () => openLifecycle('contraction', line.id),
                  },
                  {
                    label: 'Ajustar precio',
                    icon: <DollarSign className="h-4 w-4" />,
                    hidden: !canOps,
                    onClick: () => openLifecycle('price_adjustment', line.id),
                  },
                ]}
              />
            </div>
          )
        },
      },
    ],
    [canEdit, contract?.status],
  )

  const movementColumns = useMemo<
    Array<ColumnDef<typeof movementsTableFeatures, ContractMovement>>
  >(
    () => [
      {
        accessorKey: 'effective_date',
        header: 'FECHA EFECTIVA',
        cell: ({ row }) => (
          <span className="whitespace-nowrap text-sm">
            {formatDateEsMx(row.original.effective_date)}
          </span>
        ),
      },
      {
        accessorKey: 'mrr_category',
        header: 'CATEGORÍA',
        cell: ({ row }) => {
          const mov = row.original
          return (
            <div className="flex flex-wrap items-center gap-1.5">
              <AppBadge
                label={CATEGORY_LABEL[mov.mrr_category] ?? mov.mrr_category}
                tone={CATEGORY_TONE[mov.mrr_category] ?? 'neutral'}
              />
              {mov.request_folio ? (
                <AppBadge label={`Origen: ${mov.request_folio}`} variant="info" />
              ) : null}
              {mov.is_reversed ? (
                <AppBadge label="Revertido" variant="neutral" />
              ) : null}
              {mov.reversed_movement_id != null ? (
                <AppBadge
                  label={`Reversión #${mov.reversed_movement_id}`}
                  variant="warning"
                />
              ) : null}
            </div>
          )
        },
      },
      {
        accessorKey: 'reason',
        header: 'DETALLE / MOTIVO',
        cell: ({ row }) => (
          <span className="line-clamp-2 text-sm text-slate-600 dark:text-zinc-300">
            {row.original.reason || '—'}
          </span>
        ),
      },
      {
        id: 'mrr_range',
        header: 'MRR ANTES → DESPUÉS',
        cell: ({ row }) => (
          <span className="font-mono text-xs tabular-nums whitespace-nowrap">
            {formatCurrencyMx(row.original.mrr_before, { fractionDigits: 2 })}
            {' → '}
            {formatCurrencyMx(row.original.mrr_after, { fractionDigits: 2 })}
          </span>
        ),
      },
      {
        accessorKey: 'mrr_delta_mxn',
        header: 'DELTA MRR (MXN)',
        cell: ({ row }) => {
          const delta = row.original.mrr_delta_mxn
          const n = Number(delta)
          return (
            <span
              className={cn(
                'block text-right font-mono text-sm font-medium tabular-nums',
                n > 0 && 'text-emerald-600 dark:text-emerald-400',
                n < 0 && 'text-red-600 dark:text-red-400',
              )}
            >
              {n > 0 ? '+' : ''}
              {formatCurrencyMx(delta, { fractionDigits: 2 })}
            </span>
          )
        },
      },
      {
        accessorKey: 'user_name',
        header: 'USUARIO',
        cell: ({ row }) => (
          <span className="text-sm">{row.original.user_name || '—'}</span>
        ),
      },
      {
        id: 'actions',
        header: () => <AppActionsColumnHeader />,
        cell: ({ row }) => {
          if (!canEdit('contracts')) {
            return <span className="block text-right text-slate-400">—</span>
          }
          const mov = row.original
          const eligible = isMovementReversible(mov)
          if (!eligible) {
            return <span className="block text-right text-slate-400">—</span>
          }
          return (
            <div className="flex justify-end">
              <AppRowActionsMenu
                actions={[
                  {
                    label: 'Revertir movimiento',
                    icon: <RotateCcw className="h-4 w-4" />,
                    variant: 'danger',
                    onClick: () => openReversal(mov),
                  },
                ]}
              />
            </div>
          )
        },
      },
    ],
    [canEdit],
  )

  const linesTable = useTable({
    features: linesTableFeatures,
    data: contract?.lines ?? [],
    columns: lineColumns,
  })

  const movementsTable = useTable({
    features: movementsTableFeatures,
    data: contract?.movements ?? [],
    columns: movementColumns,
  })

  if (isLoading) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-8 w-64 rounded-xl" />
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-24 rounded-2xl" />
          ))}
        </div>
        <Skeleton className="h-64 rounded-2xl" />
      </div>
    )
  }

  if (isNotFound) {
    return (
      <AppCard className="p-6">
        <h2 className="font-heading text-xl font-semibold">Contrato no encontrado</h2>
        <p className="mt-1 text-sm text-slate-500">
          El folio solicitado no existe o está fuera de tu alcance de segmento.
        </p>
        <AppButton className="mt-4" variant="outline" onClick={() => navigate('/contratos')}>
          <ArrowLeft className="mr-1 h-4 w-4" /> Volver a contratos
        </AppButton>
      </AppCard>
    )
  }

  if (isApiError || !contract) {
    return (
      <AppCard className="border-red-500/30 p-5">
        <h2 className="font-heading font-semibold text-red-500">Error al cargar el contrato</h2>
        <p className="mt-1 text-sm text-slate-500">{errorMessage}</p>
        <AppButton className="mt-4" onClick={() => void refetch()}>
          Reintentar
        </AppButton>
      </AppCard>
    )
  }

  const canOperate = canEdit('contracts')
  const isActive = contract.status === 'active'
  const canReactivate =
    canOperate && (contract.status === 'cancelled' || contract.status === 'suspended')

  const kpiCards = [
    {
      label: 'MRR actual (MXN)',
      value: formatCurrencyMx(contract.mrr_mxn, { fractionDigits: 2 }),
      mono: true,
    },
    {
      label: 'Frecuencia y próximo corte',
      value: `${contract.billing_frequency === 'annual' ? 'Anual' : 'Mensual'}${
        contract.next_billing_date ? ` · ${formatDateEsMx(contract.next_billing_date)}` : ''
      }`,
    },
    {
      label: 'Líneas activas',
      value: `${contract.active_recurring_count ?? 0} recurrentes · ${contract.active_one_time_count ?? 0} únicos`,
    },
    {
      label: 'Condiciones comerciales',
      value: `${contract.credit_days} días crédito · ${contract.issuer_name}${
        contract.instance_name ? ` · ${contract.instance_name}` : ''
      }${contract.stamp_on_issue ? ' · Timbrado auto' : ''}`,
    },
  ]

  return (
    <div className="space-y-5">
      <nav className="text-sm text-slate-500 dark:text-zinc-400">
        <Link to="/contratos" className="hover:text-[#0066FF] dark:hover:text-[#0A84FF]">
          Contratos
        </Link>
        <span className="mx-1.5">/</span>
        <span className="font-mono tabular-nums text-slate-800 dark:text-zinc-100">
          {contract.folio}
        </span>
      </nav>

      <header className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="min-w-0 space-y-2">
          <div className="flex flex-wrap items-center gap-2">
            <AppButton
              variant="ghost"
              size="sm"
              className="rounded-full px-2"
              onClick={() => navigate('/contratos')}
              aria-label="Volver"
            >
              <ArrowLeft className="h-4 w-4" />
            </AppButton>
            <h1 className="font-mono text-2xl font-semibold tabular-nums tracking-[-0.4px] md:text-3xl">
              {contract.folio}
            </h1>
          </div>
          <p className="font-heading text-lg font-medium">{contract.client_name}</p>
          <p className="font-mono text-sm tabular-nums text-slate-500 dark:text-zinc-400">
            RFC {contract.client_rfc}
            {contract.client_legal_name ? ` · ${contract.client_legal_name}` : ''}
          </p>
          <div className="flex flex-wrap gap-1.5">
            <AppBadge
              label={STATUS_LABEL[contract.status]}
              tone={STATUS_TONE[contract.status]}
            />
            <AppBadge label={contract.segment_name || '—'} tone="neutral" />
            <AppBadge label={contract.issuer_name || '—'} tone="info" />
            <AppBadge
              label={contract.billing_frequency === 'annual' ? 'Anual' : 'Mensual'}
              tone="info"
            />
            {contract.promotion_code ? (
              <AppBadge label={contract.promotion_code} tone="warning" />
            ) : null}
          </div>
        </div>

        <div className="flex flex-wrap items-center justify-end gap-2">
          {canEdit('contracts') ? (
            <AppButton variant="secondary" onClick={() => setConsumeOpen(true)}>
              Registrar consumo
            </AppButton>
          ) : null}
          {canEdit('contracts') ? (
            <AppButton variant="outline" onClick={() => setGrantOpen(true)}>
              Acreditar bolsa
            </AppButton>
          ) : null}
          {canOperate ? (
            <DropdownMenu.Root>
              <DropdownMenu.Trigger asChild>
                <AppButton className="rounded-xl">
                  Operaciones de ciclo de vida
                  <ChevronDown className="ml-1 h-4 w-4" />
                </AppButton>
              </DropdownMenu.Trigger>
              <DropdownMenu.Portal>
                <DropdownMenu.Content align="end" sideOffset={6} className={LIFECYCLE_MENU_SURFACE}>
                  {isActive ? (
                    <>
                      <LifecycleMenuItem
                        icon={<PlusCircle className="h-4 w-4" />}
                        label="Expandir contrato (+servicio / +cantidad)"
                        onClick={() => openLifecycle('expansion')}
                      />
                      <LifecycleMenuItem
                        icon={<MinusCircle className="h-4 w-4" />}
                        label="Contraer contrato"
                        onClick={() => openLifecycle('contraction')}
                      />
                      <LifecycleMenuItem
                        icon={<DollarSign className="h-4 w-4" />}
                        label="Ajustar precio"
                        onClick={() => openLifecycle('price_adjustment')}
                      />
                      <LifecycleMenuItem
                        icon={<Repeat className="h-4 w-4" />}
                        label="Cambiar frecuencia (Mensual ↔ Anual)"
                        onClick={() => openLifecycle('frequency_change')}
                      />
                      <LifecycleMenuItem
                        icon={<Building2 className="h-4 w-4" />}
                        label="Transferir de emisor"
                        onClick={() => openLifecycle('issuer_transfer')}
                      />
                      <DropdownMenu.Separator className="my-1 h-px bg-slate-200/70 dark:bg-white/[0.08]" />
                      <LifecycleMenuItem
                        icon={<Ban className="h-4 w-4" />}
                        label="Cancelar contrato (Churn)"
                        danger
                        onClick={() => openLifecycle('churn')}
                      />
                    </>
                  ) : null}
                  {canReactivate ? (
                    <LifecycleMenuItem
                      icon={<RefreshCw className="h-4 w-4" />}
                      label="Reactivar contrato"
                      onClick={() => openLifecycle('reactivation')}
                    />
                  ) : null}
                </DropdownMenu.Content>
              </DropdownMenu.Portal>
            </DropdownMenu.Root>
          ) : null}
        </div>
      </header>

      <AppTabs
        aria-label="Detalle del contrato"
        value={detailTab}
        onChange={(id) => setDetailTab(id as 'lines' | 'consumptions' | 'movements')}
        items={[
          { id: 'lines', label: 'Líneas y saldos' },
          { id: 'consumptions', label: 'Consumos e historial' },
          { id: 'movements', label: 'Bitácora de movimientos' },
        ]}
      />

      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {kpiCards.map((kpi) => (
          <AppCard key={kpi.label} className="pointer-events-none p-4">
            <p className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-zinc-400">
              {kpi.label}
            </p>
            <p
              className={cn(
                'mt-2 font-heading text-lg font-semibold tracking-[-0.4px] leading-snug',
                kpi.mono && 'font-mono tabular-nums text-xl',
              )}
            >
              {kpi.value}
            </p>
          </AppCard>
        ))}
      </div>

      {detailTab === 'lines' ? (
      <section className="space-y-5">
        <div className="space-y-3">
        <h2 className="font-heading text-lg font-semibold tracking-[-0.4px]">
          Líneas vigentes del contrato
        </h2>
        <AppTableWrapper
          isEmpty={contract.lines.length === 0}
          emptyState={
            <p className="p-6 text-center text-sm text-slate-500">Sin líneas registradas</p>
          }
        >
          <div className="hidden overflow-x-auto lg:block">
            <table className="w-full min-w-[900px] text-left text-sm">
              <thead>
                {linesTable.getHeaderGroups().map((hg) => (
                  <AppTableHeadRow key={hg.id}>
                    {hg.headers.map((h) => (
                      <th
                        key={h.id}
                        className={cn(
                          rowPaddingClass,
                          'font-medium',
                          (h.column.id === 'actions' || h.column.id === 'mrr_mxn') && 'text-right',
                        )}
                      >
                        {h.isPlaceholder ? null : <linesTable.FlexRender header={h} />}
                      </th>
                    ))}
                  </AppTableHeadRow>
                ))}
              </thead>
              <tbody>
                {linesTable.getRowModel().rows.map((row) => (
                  <tr
                    key={row.id}
                    className="border-t border-slate-100 dark:border-white/[0.06]"
                  >
                    {row.getAllCells().map((cell) => (
                      <td
                        key={cell.id}
                        className={cn(
                          rowPaddingClass,
                          (cell.column.id === 'actions' || cell.column.id === 'mrr_mxn') &&
                            'text-right',
                        )}
                      >
                        <linesTable.FlexRender cell={cell} />
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="space-y-3 p-4 lg:hidden">
            {contract.lines.map((line) => (
              <AppCard key={line.id} className="p-4">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="font-medium">{line.description}</p>
                    <p className="mt-1 font-mono text-xs tabular-nums text-slate-500">
                      ×{line.quantity} · {formatCurrencyMx(line.unit_price, { fractionDigits: 2 })}
                    </p>
                  </div>
                  <AppBadge
                    label={line.active ? 'Activa' : 'Inactiva'}
                    tone={line.active ? 'success' : 'neutral'}
                  />
                </div>
                <div className="mt-3 flex items-end justify-between">
                  <div>
                    <AppBadge
                      label={line.service_type === 'recurring' ? 'Recurrente' : 'Único'}
                      tone="info"
                    />
                    {Number(line.discount_amount) > 0 ? (
                      <p className="mt-1 text-[11px] text-amber-600 dark:text-amber-400">
                        Desc. alta −
                        {formatCurrencyMx(line.discount_amount, { fractionDigits: 2 })}
                      </p>
                    ) : null}
                  </div>
                  <span className="font-mono text-sm font-semibold tabular-nums">
                    {formatCurrencyMx(line.mrr_mxn, { fractionDigits: 2 })}
                  </span>
                </div>
              </AppCard>
            ))}
          </div>
        </AppTableWrapper>
        </div>

        <ContractBalancesPanel
          data={balancesQuery.data}
          isLoading={balancesQuery.isLoading}
          isError={balancesQuery.isError}
          onRetry={() => void balancesQuery.refetch()}
          consumptionsPage={consumptionsPage}
          consumptionsPageSize={consumptionsPageSize}
          onConsumptionsPageChange={setConsumptionsPage}
          onConsumptionsPageSizeChange={(size) => {
            setConsumptionsPageSize(size)
            setConsumptionsPage(1)
          }}
          showBags
          showConsumptions={false}
          showBalanceMovements={false}
        />
      </section>
      ) : null}

      {detailTab === 'consumptions' ? (
        <ContractBalancesPanel
          data={balancesQuery.data}
          isLoading={balancesQuery.isLoading}
          isError={balancesQuery.isError}
          onRetry={() => void balancesQuery.refetch()}
          consumptionsPage={consumptionsPage}
          consumptionsPageSize={consumptionsPageSize}
          onConsumptionsPageChange={setConsumptionsPage}
          onConsumptionsPageSizeChange={(size) => {
            setConsumptionsPageSize(size)
            setConsumptionsPage(1)
          }}
          showBags={false}
          showConsumptions
          showBalanceMovements
        />
      ) : null}

      {detailTab === 'movements' ? (
      <section className="space-y-3">
        <div>
          <h2 className="font-heading text-lg font-semibold tracking-[-0.4px]">
            Bitácora inmutable de movimientos
          </h2>
          <p className="mt-1 text-xs text-slate-500 dark:text-zinc-400">
            Registros append-only (RNF-3). Solo se permiten INSERT; las correcciones se hacen con
            reversiones contables.
          </p>
        </div>
        <AppTableWrapper
          isEmpty={contract.movements.length === 0}
          emptyState={
            <p className="p-6 text-center text-sm text-slate-500">Sin movimientos registrados</p>
          }
        >
          <div className="hidden overflow-x-auto lg:block">
            <table className="w-full min-w-[960px] text-left text-sm">
              <thead>
                {movementsTable.getHeaderGroups().map((hg) => (
                  <AppTableHeadRow key={hg.id}>
                    {hg.headers.map((h) => (
                      <th
                        key={h.id}
                        className={cn(
                          rowPaddingClass,
                          'font-medium',
                          (h.column.id === 'mrr_delta_mxn' || h.column.id === 'actions') &&
                            'text-right',
                        )}
                      >
                        {h.isPlaceholder ? null : <movementsTable.FlexRender header={h} />}
                      </th>
                    ))}
                  </AppTableHeadRow>
                ))}
              </thead>
              <tbody>
                {movementsTable.getRowModel().rows.map((row) => (
                  <tr
                    key={row.id}
                    className="border-t border-slate-100 dark:border-white/[0.06]"
                  >
                    {row.getAllCells().map((cell) => (
                      <td
                        key={cell.id}
                        className={cn(
                          rowPaddingClass,
                          (cell.column.id === 'mrr_delta_mxn' || cell.column.id === 'actions') &&
                            'text-right',
                        )}
                      >
                        <movementsTable.FlexRender cell={cell} />
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="space-y-3 p-4 lg:hidden">
            {contract.movements.map((mov) => (
              <AppCard key={mov.id} className="p-4">
                <div className="flex items-start justify-between gap-2">
                  <div className="flex flex-wrap items-center gap-1.5">
                    <AppBadge
                      label={CATEGORY_LABEL[mov.mrr_category] ?? mov.mrr_category}
                      tone={CATEGORY_TONE[mov.mrr_category] ?? 'neutral'}
                    />
                    {mov.request_folio ? (
                      <AppBadge label={`Origen: ${mov.request_folio}`} variant="info" />
                    ) : null}
                    {mov.is_reversed ? (
                      <AppBadge label="Revertido" variant="neutral" />
                    ) : null}
                    {mov.reversed_movement_id != null ? (
                      <AppBadge
                        label={`Reversión #${mov.reversed_movement_id}`}
                        variant="warning"
                      />
                    ) : null}
                  </div>
                  <span className="text-xs text-slate-500">
                    {formatDateEsMx(mov.effective_date)}
                  </span>
                </div>
                <p className="mt-2 text-sm">{mov.reason || '—'}</p>
                <div className="mt-2 flex items-end justify-between font-mono text-xs tabular-nums">
                  <span>
                    {formatCurrencyMx(mov.mrr_before, { fractionDigits: 2 })} →{' '}
                    {formatCurrencyMx(mov.mrr_after, { fractionDigits: 2 })}
                  </span>
                  <span
                    className={cn(
                      'font-semibold',
                      Number(mov.mrr_delta_mxn) > 0 && 'text-emerald-600',
                      Number(mov.mrr_delta_mxn) < 0 && 'text-red-600',
                    )}
                  >
                    {Number(mov.mrr_delta_mxn) > 0 ? '+' : ''}
                    {formatCurrencyMx(mov.mrr_delta_mxn, { fractionDigits: 2 })}
                  </span>
                </div>
                <div className="mt-2 flex items-center justify-between gap-2">
                  <p className="text-xs text-slate-400">{mov.user_name || '—'}</p>
                  {canOperate && isMovementReversible(mov) ? (
                    <AppRowActionsMenu
                      actions={[
                        {
                          label: 'Revertir movimiento',
                          icon: <RotateCcw className="h-4 w-4" />,
                          variant: 'danger',
                          onClick: () => openReversal(mov),
                        },
                      ]}
                    />
                  ) : (
                    <span className="text-xs text-slate-400">—</span>
                  )}
                </div>
              </AppCard>
            ))}
          </div>
        </AppTableWrapper>
      </section>
      ) : null}

      <LifecycleOperationModal
        open={modalOpen}
        onOpenChange={setModalOpen}
        operation={lifecycleOp}
        contract={contract}
        recurringServiceOptions={recurringServiceOptions}
        issuerOptions={issuerOptions}
        preselectedLineId={preselectedLineId}
        isSubmitting={isLifecyclePending}
        onConfirm={handleConfirm}
      />

      <ReverseMovementModal
        open={reversalOpen}
        onOpenChange={(open) => {
          setReversalOpen(open)
          if (!open) setReversalMovement(null)
        }}
        contract={contract}
        movement={reversalMovement}
        isSubmitting={isReversalPending}
        onConfirm={handleConfirmReversal}
      />

      <RegisterConsumptionModal
        open={consumeOpen}
        onOpenChange={setConsumeOpen}
        contractId={contractId}
        serviceOptions={balanceServiceOptions}
        isSubmitting={consumeMutation.isPending}
        onSubmit={async (input) => {
          try {
            await consumeMutation.mutateAsync(input)
            toast.success('Consumo registrado')
          } catch (error) {
            toast.apiError(error)
            throw error
          }
        }}
      />

      <GrantBalanceModal
        open={grantOpen}
        onOpenChange={setGrantOpen}
        contractId={contractId}
        serviceOptions={balanceServiceOptions}
        isSubmitting={grantMutation.isPending}
        onSubmit={async (input) => {
          try {
            await grantMutation.mutateAsync(input)
            toast.success('Bolsa acreditada')
          } catch (error) {
            toast.apiError(error)
            throw error
          }
        }}
      />
    </div>
  )
}

function LifecycleMenuItem({
  label,
  icon,
  onClick,
  danger = false,
}: {
  label: string
  icon: React.ReactNode
  onClick: () => void
  danger?: boolean
}) {
  return (
    <DropdownMenu.Item
      onSelect={(e) => {
        e.preventDefault()
        requestAnimationFrame(onClick)
      }}
      className={cn(
        'flex cursor-pointer select-none items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-medium outline-none',
        'text-slate-700 hover:bg-blue-500/10 hover:text-blue-600',
        'data-[highlighted]:bg-blue-500/10 data-[highlighted]:text-blue-600',
        'dark:text-zinc-100 dark:hover:bg-blue-500/15 dark:hover:text-blue-400',
        danger &&
          'text-red-600 hover:bg-red-500/10 hover:text-red-600 data-[highlighted]:bg-red-500/10 data-[highlighted]:text-red-600 dark:text-red-400',
      )}
    >
      <span className="opacity-80">{icon}</span>
      <span className="whitespace-nowrap">{label}</span>
    </DropdownMenu.Item>
  )
}
