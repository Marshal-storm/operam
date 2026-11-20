import { zodResolver } from '@hookform/resolvers/zod'
import { ArrowLeft, Check, Plus, Save, Trash2 } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppFilterField } from '@/components/ui/AppFilterField'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard } from '@/components/ui/AppCard'
import { AppCurrencyInput, AppInput, AppSearchInput } from '@/components/ui/AppInput'
import {
  AppModal,
  AppModalContent,
  AppModalDescription,
  AppModalHeader,
  AppModalTitle,
} from '@/components/ui/AppModal'
import { AppSelect } from '@/components/ui/AppSelect'
import { AppSwitch } from '@/components/ui/AppSwitch'
import { PaginationBar } from '@/components/ui/PaginationBar'
import { Skeleton } from '@/components/ui/skeleton'
import { useToast } from '@/context/ToastContext'
import { usePriceListsConfig } from '@/hooks/useConfigCatalog'
import { usePermissions } from '@/hooks/usePermissions'
import { useSegmentLock } from '@/hooks/useSegmentLock'
import { paginateRows, useClientTableState } from '@/hooks/useClientTableState'
import { cn } from '@/lib/utils'
import type { VolumeMatrixSavePlan } from '@/services/catalogService'
import type { PriceList, PriceListItem } from '@/types/catalog'

/** Escalón editable (mensual + anual alineados por rango). */
type ScaleTier = {
  localKey: string
  min_quantity: number
  max_quantity: number | null
  mensualId: number | null
  anualId: number | null
  unicoId: number | null
  mensual: string
  anual: string
  unico: string
  hasMensual: boolean
  hasAnual: boolean
  hasUnico: boolean
}

type ProductGroup = {
  servicioId: number
  code: string
  name: string
  tiers: ScaleTier[]
  /** Forzar vista de escalones aunque solo haya un rango 1+. */
  forceVolumeUi: boolean
}

function countAssignedProducts(list: PriceList): number {
  return new Set(list.items.map((item) => item.service_id)).size
}

function isStandardSingleTier(tiers: ScaleTier[]): boolean {
  return (
    tiers.length === 1 &&
    tiers[0].min_quantity === 1 &&
    tiers[0].max_quantity === null
  )
}

function formatRangeLabel(min: number, max: number | null): string {
  if (min === 1 && max === null) return '1+ (Precio único)'
  if (max === null) return `${min}+ (en adelante)`
  return `${min} a ${max}`
}

function rangesOverlap(
  aMin: number,
  aMax: number | null,
  bMin: number,
  bMax: number | null,
): boolean {
  const aHi = aMax ?? Number.POSITIVE_INFINITY
  const bHi = bMax ?? Number.POSITIVE_INFINITY
  return aMin <= bHi && bMin <= aHi
}

function validateProductTiers(tiers: ScaleTier[]): string | null {
  for (const tier of tiers) {
    if (!Number.isFinite(tier.min_quantity) || tier.min_quantity < 1) {
      return 'Cada escalón debe iniciar en cantidad mínima ≥ 1.'
    }
    if (tier.max_quantity != null && tier.max_quantity < tier.min_quantity) {
      return `Máximo (${tier.max_quantity}) no puede ser menor que el mínimo (${tier.min_quantity}).`
    }
  }
  for (let i = 0; i < tiers.length; i++) {
    for (let j = i + 1; j < tiers.length; j++) {
      if (
        rangesOverlap(
          tiers[i].min_quantity,
          tiers[i].max_quantity,
          tiers[j].min_quantity,
          tiers[j].max_quantity,
        )
      ) {
        return `Los rangos ${formatRangeLabel(tiers[i].min_quantity, tiers[i].max_quantity)} y ${formatRangeLabel(tiers[j].min_quantity, tiers[j].max_quantity)} se traslapan.`
      }
    }
  }
  return null
}

function buildProductGroups(list: PriceList | undefined): ProductGroup[] {
  if (!list) return []
  const byService = new Map<
    number,
    {
      code: string
      name: string
      byRange: Map<string, ScaleTier>
    }
  >()

  for (const item of list.items) {
    let bucket = byService.get(item.service_id)
    if (!bucket) {
      bucket = {
        code: item.service_code,
        name: item.service_name,
        byRange: new Map<string, ScaleTier>(),
      }
      byService.set(item.service_id, bucket)
    }
    const rangeKey = `${item.min_quantity}:${item.max_quantity ?? '∞'}`
    let tier = bucket.byRange.get(rangeKey)
    if (!tier) {
      tier = {
        localKey: `db-${item.service_id}-${rangeKey}`,
        min_quantity: item.min_quantity,
        max_quantity: item.max_quantity,
        mensualId: null,
        anualId: null,
        unicoId: null,
        mensual: '',
        anual: '',
        unico: '',
        hasMensual: false,
        hasAnual: false,
        hasUnico: false,
      }
      bucket.byRange.set(rangeKey, tier)
    }

    if (item.frequency === 'monthly') {
      tier.mensualId = item.id
      tier.mensual = item.unit_price
      tier.hasMensual = true
    } else if (item.frequency === 'annual') {
      tier.anualId = item.id
      tier.anual = item.unit_price
      tier.hasAnual = true
    } else if (item.frequency === 'single') {
      tier.unicoId = item.id
      tier.unico = item.unit_price
      tier.hasUnico = true
    }
  }

  return [...byService.entries()]
    .map(([servicioId, data]) => {
      const tiers = [...data.byRange.values()].sort(
        (a, b) => a.min_quantity - b.min_quantity,
      )
      return {
        servicioId,
        code: data.code,
        name: data.name,
        tiers,
        forceVolumeUi: !isStandardSingleTier(tiers),
      }
    })
    .sort((a, b) => a.code.localeCompare(b.code))
}

function normalizeMoneyInput(raw: string): string | null {
  const cleaned = raw.trim().replace(/,/g, '')
  if (!/^\d+(\.\d{0,2})?$/.test(cleaned)) return null
  if (!cleaned.includes('.')) return `${cleaned}.00`
  const [w, f = ''] = cleaned.split('.')
  return `${w}.${f.padEnd(2, '0').slice(0, 2)}`
}

function snapshotItems(list: PriceList): Map<number, PriceListItem> {
  return new Map(list.items.map((item) => [item.id, item]))
}

const newListSchema = z.object({
  name: z.string().min(3),
  segment_id: z.string().min(1),
  currency: z.enum(['MXN', 'USD']),
  valid_from: z.string().min(8),
  is_default: z.boolean(),
})

const addItemSchema = z.object({
  service_id: z.string().min(1),
  frequency: z.enum(['monthly', 'annual', 'single']),
  unit_price: z.string().regex(/^\d+(\.\d{1,2})?$/),
})

type NewListForm = z.infer<typeof newListSchema>
type AddItemForm = z.infer<typeof addItemSchema>

/**
 * Listas de precios — directorio + detalle con escalones RF-2.
 */
export function PriceListsPage() {
  const toast = useToast()
  const {
    lists,
    services,
    segments,
    isLoading,
    isApiError,
    errorMessage,
    refetch,
    createPriceList,
    updatePriceList,
    createPriceListItem,
    removeProductFromPriceList,
    saveVolumeMatrix,
    isSaving,
  } = usePriceListsConfig()

  const { page, setPage, pageSize, setPageSize } = useClientTableState()
  const [search, setSearch] = useState('')
  const { canCreate, canEdit, canDelete } = usePermissions()
  const {
    segment,
    setSegment,
    segmentOptions,
    isSegmentLocked,
  } = useSegmentLock()
  const [moneda, setMoneda] = useState('Todas')
  const [vigencia, setVigencia] = useState('Todas')
  const [editingListId, setEditingListId] = useState<number | null>(null)
  const [productSearch, setProductSearch] = useState('')
  const [groups, setGroups] = useState<ProductGroup[]>([])
  const [baselineJson, setBaselineJson] = useState('')
  const [savedFlash, setSavedFlash] = useState(false)
  const [newListOpen, setNewListOpen] = useState(false)
  const [addItemOpen, setAddItemOpen] = useState(false)
  const [removeTarget, setRemoveTarget] = useState<ProductGroup | null>(null)

  const today = new Date().toISOString().slice(0, 10)
  const selected = lists.find((l) => l.id === editingListId)

  const filteredLists = useMemo(() => {
    const q = search.trim().toLowerCase()
    return lists.filter((list) => {
      if (q && !list.name.toLowerCase().includes(q)) return false
      if (segment !== 'Todos' && list.segment_name !== segment) return false
      if (moneda !== 'Todas' && list.currency !== moneda) return false
      if (vigencia === 'Vigentes') {
        const fromOk = list.valid_from.slice(0, 10) <= today
        const toOk = !list.valid_until || list.valid_until.slice(0, 10) >= today
        if (!fromOk || !toOk || !list.is_active) return false
      }
      if (vigencia === 'Inactivas' && list.is_active) return false
      return true
    })
  }, [lists, search, segment, moneda, vigencia, today])

  const pageRows = paginateRows(filteredLists, page, pageSize)

  useEffect(() => {
    const next = buildProductGroups(selected)
    setGroups(next)
    setBaselineJson(JSON.stringify(next))
    setSavedFlash(false)
  }, [selected])

  const filteredGroups = useMemo(() => {
    const q = productSearch.trim().toLowerCase()
    if (!q) return groups
    return groups.filter(
      (g) =>
        g.name.toLowerCase().includes(q) || g.code.toLowerCase().includes(q),
    )
  }, [groups, productSearch])

  const tierErrors = useMemo(() => {
    const map = new Map<number, string>()
    for (const group of groups) {
      const err = validateProductTiers(group.tiers)
      if (err) map.set(group.servicioId, err)
    }
    return map
  }, [groups])

  const hasRangeErrors = tierErrors.size > 0
  const hasChanges = JSON.stringify(groups) !== baselineJson

  const listForm = useForm<NewListForm>({
    resolver: zodResolver(newListSchema),
    defaultValues: {
      name: '',
      segment_id: '1',
      currency: 'MXN',
      valid_from: today,
      is_default: false,
    },
  })

  async function markAsDefault(list: (typeof lists)[number]) {
    if (!canEdit('config_prices') || list.is_default) return
    try {
      await updatePriceList({ id: list.id, data: { is_default: true } })
      toast.success('Lista predeterminada', `${list.name} es ahora ★ Default.`)
    } catch (error) {
      toast.apiError(error)
    }
  }

  const itemForm = useForm<AddItemForm>({
    resolver: zodResolver(addItemSchema),
    defaultValues: { service_id: '1', frequency: 'monthly', unit_price: '0.00' },
  })

  function updateTier(
    servicioId: number,
    localKey: string,
    patch: Partial<ScaleTier>,
  ) {
    setGroups((prev) =>
      prev.map((g) => {
        if (g.servicioId !== servicioId) return g
        return {
          ...g,
          tiers: g.tiers.map((t) => (t.localKey === localKey ? { ...t, ...patch } : t)),
        }
      }),
    )
  }

  function enableVolumeUi(servicioId: number) {
    setGroups((prev) =>
      prev.map((g) =>
        g.servicioId === servicioId ? { ...g, forceVolumeUi: true } : g,
      ),
    )
  }

  function addVolumeTier(servicioId: number) {
    setGroups((prev) =>
      prev.map((g) => {
        if (g.servicioId !== servicioId) return g
        const sorted = [...g.tiers].sort((a, b) => a.min_quantity - b.min_quantity)
        const last = sorted[sorted.length - 1]
        let nextMin = 1
        let patched = sorted
        if (last) {
          if (last.max_quantity == null) {
            // Cierra el “en adelante” en su mínimo para liberar el siguiente escalón.
            patched = sorted.map((tier, idx) =>
              idx === sorted.length - 1
                ? { ...tier, max_quantity: tier.min_quantity }
                : tier,
            )
            nextMin = last.min_quantity + 1
          } else {
            nextMin = last.max_quantity + 1
          }
        }

        const template = last ?? sorted[0]
        const newTier: ScaleTier = {
          localKey: `new-${servicioId}-${Date.now()}`,
          min_quantity: nextMin,
          max_quantity: null,
          mensualId: null,
          anualId: null,
          unicoId: null,
          mensual: template?.mensual || '0.00',
          anual: template?.anual || '0.00',
          unico: template?.unico || '0.00',
          hasMensual: template?.hasMensual ?? true,
          hasAnual: template?.hasAnual ?? false,
          hasUnico: template?.hasUnico ?? false,
        }
        return {
          ...g,
          forceVolumeUi: true,
          tiers: [...patched, newTier],
        }
      }),
    )
  }

  function removeTier(servicioId: number, localKey: string) {
    setGroups((prev) =>
      prev.map((g) => {
        if (g.servicioId !== servicioId) return g
        if (g.tiers.length <= 1) return g
        return {
          ...g,
          tiers: g.tiers.filter((t) => t.localKey !== localKey),
          forceVolumeUi: true,
        }
      }),
    )
  }

  function buildSavePlan(list: PriceList): VolumeMatrixSavePlan | null {
    const original = snapshotItems(list)
    const keptIds = new Set<number>()
    const updates: VolumeMatrixSavePlan['updates'] = []
    const creates: VolumeMatrixSavePlan['creates'] = []

    for (const group of groups) {
      if (validateProductTiers(group.tiers)) return null
      for (const tier of group.tiers) {
        const min = tier.min_quantity
        const max = tier.max_quantity

        const pushFreq = (
          freq: 'monthly' | 'annual' | 'single',
          id: number | null,
          priceRaw: string,
          enabled: boolean,
        ) => {
          if (!enabled) return
          const price = normalizeMoneyInput(priceRaw)
          if (!price) return
          if (id != null) {
            keptIds.add(id)
            const prev = original.get(id)
            const patch: VolumeMatrixSavePlan['updates'][number] = { id }
            let dirty = false
            if (!prev || prev.unit_price !== price) {
              patch.unit_price = price
              dirty = true
            }
            if (!prev || prev.min_quantity !== min) {
              patch.min_quantity = min
              dirty = true
            }
            if (!prev || prev.max_quantity !== max) {
              patch.max_quantity = max
              dirty = true
            }
            if (dirty) updates.push(patch)
          } else {
            creates.push({
              price_list_id: list.id,
              service_id: group.servicioId,
              frequency: freq,
              min_quantity: min,
              max_quantity: max,
              unit_price: price,
            })
          }
        }

        pushFreq('monthly', tier.mensualId, tier.mensual, tier.hasMensual)
        pushFreq('annual', tier.anualId, tier.anual, tier.hasAnual)
        pushFreq('single', tier.unicoId, tier.unico, tier.hasUnico)
      }
    }

    const deletes: number[] = []
    for (const item of list.items) {
      // Solo tocar ítems de productos presentes en groups (todos).
      if (!keptIds.has(item.id)) {
        deletes.push(item.id)
      }
    }

    return { updates, creates, deletes }
  }

  async function onSavePrices() {
    if (!selected || !hasChanges || hasRangeErrors) return
    const plan = buildSavePlan(selected)
    if (!plan) {
      toast.error('Rangos inválidos', 'Corrige los traslapes antes de guardar.')
      return
    }
    if (plan.updates.length === 0 && plan.creates.length === 0 && plan.deletes.length === 0) {
      toast.error('Sin cambios', 'No hay diferencias que guardar.')
      return
    }
    try {
      await saveVolumeMatrix(plan)
      setSavedFlash(true)
      window.setTimeout(() => setSavedFlash(false), 2500)
      const n = plan.updates.length + plan.creates.length + plan.deletes.length
      toast.success(
        'Escalones guardados',
        `${n} ${n === 1 ? 'cambio aplicado' : 'cambios aplicados'} en MySQL.`,
      )
    } catch (error) {
      toast.apiError(error)
    }
  }

  async function onConfirmRemove() {
    if (!selected || !removeTarget) return
    try {
      await removeProductFromPriceList({
        price_list_id: selected.id,
        service_id: removeTarget.servicioId,
      })
      toast.success('Producto quitado', `${removeTarget.name} ya no está en esta lista.`)
      setRemoveTarget(null)
    } catch (error) {
      toast.apiError(error)
    }
  }

  if (isLoading) return <Skeleton className="h-64 w-full rounded-2xl" />
  if (isApiError) {
    return (
      <AppCard className="border-red-500/30 p-5">
        <h2 className="font-heading font-semibold text-red-500">Error</h2>
        <p className="text-sm text-slate-500">{errorMessage}</p>
        <AppButton className="mt-4" onClick={() => void refetch()}>
          Reintentar
        </AppButton>
      </AppCard>
    )
  }

  /* ── Vista detalle de una lista ── */
  if (selected) {
    const productCount = countAssignedProducts(selected)
    return (
      <div className="space-y-5">
        <AppButton
          type="button"
          variant="ghost"
          onClick={() => {
            setEditingListId(null)
            setProductSearch('')
          }}
          className="h-auto justify-start px-2 text-sm font-normal text-[#0066FF] hover:bg-transparent dark:text-[#0A84FF]"
        >
          <ArrowLeft className="h-4 w-4" /> Volver a todas las listas
        </AppButton>

        <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <h1 className="font-heading text-2xl font-semibold">{selected.name}</h1>
            <p className="mt-1 text-sm text-slate-500">
              {selected.segment_name || '—'} · {selected.currency} · {productCount}{' '}
              {productCount === 1 ? 'producto en esta lista' : 'productos en esta lista'}
            </p>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            {savedFlash && (
              <p className="flex items-center gap-1 text-sm text-emerald-500">
                <Check className="h-4 w-4" /> Guardado
              </p>
            )}
            {canCreate('config_prices') && (
              <AppButton
                className="rounded-xl bg-[#0066FF] text-white dark:bg-[#0A84FF]"
                onClick={() => setAddItemOpen(true)}
              >
                <Plus className="mr-1 h-4 w-4" /> Agregar producto a la lista
              </AppButton>
            )}
          </div>
        </header>

        <AppSearchInput
          value={productSearch}
          onChange={(e) => setProductSearch(e.target.value)}
          onClear={() => setProductSearch('')}
          placeholder="Buscar producto en esta lista…"
          aria-label="Buscar producto en esta lista"
        />

        <AppCard className="overflow-hidden p-0">
          <div className="hidden overflow-x-auto lg:block">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200/80 text-left text-[11px] uppercase text-slate-500 dark:border-white/[0.07]">
                  <th className="px-4 py-3 font-medium">Producto</th>
                  <th className="px-4 py-3 font-medium">Rango / Escala</th>
                  <th className="px-4 py-3 font-medium">Mensual</th>
                  <th className="px-4 py-3 font-medium">Anual</th>
                  <th className="px-4 py-3 text-right font-medium">Acciones</th>
                </tr>
              </thead>
              <tbody>
                {filteredGroups.map((group) => {
                  const err = tierErrors.get(group.servicioId) ?? null
                  const showVolume =
                    group.forceVolumeUi || !isStandardSingleTier(group.tiers)
                  const single = group.tiers[0]

                  if (!showVolume && single) {
                    return (
                      <tr
                        key={group.servicioId}
                        className="border-b border-slate-100 dark:border-white/[0.04]"
                      >
                        <td className="px-4 py-3 align-top">
                          <p className="font-medium">{group.name}</p>
                          <p className="font-mono text-xs text-slate-500">{group.code}</p>
                        </td>
                        <td className="px-4 py-3 align-top">
                          <div className="space-y-2">
                            <span className="inline-flex rounded-full border border-slate-200/80 bg-slate-50 px-2.5 py-1 text-xs dark:border-white/[0.08] dark:bg-white/[0.04]">
                              {formatRangeLabel(single.min_quantity, single.max_quantity)}
                            </span>
                            {canEdit('config_prices') && (
                              <AppButton
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => addVolumeTier(group.servicioId)}
                                className="h-auto justify-start px-0 text-[11px] font-normal text-[#0066FF] hover:bg-transparent dark:text-[#0A84FF]"
                              >
                                + Agregar escalón por volumen
                              </AppButton>
                            )}
                          </div>
                        </td>
                        <td className="px-4 py-3 align-top">
                          {single.hasMensual ? (
                            <MoneyInput
                              value={single.mensual}
                              currency={selected.currency}
                              onChange={(v) =>
                                updateTier(group.servicioId, single.localKey, {
                                  mensual: v,
                                })
                              }
                            />
                          ) : single.hasUnico ? (
                            <MoneyInput
                              value={single.unico}
                              currency={selected.currency}
                              onChange={(v) =>
                                updateTier(group.servicioId, single.localKey, {
                                  unico: v,
                                })
                              }
                            />
                          ) : (
                            '—'
                          )}
                          {single.hasUnico && !single.hasMensual && (
                            <p className="mt-1 text-[10px] text-slate-400">Pago único</p>
                          )}
                        </td>
                        <td className="px-4 py-3 align-top">
                          {single.hasAnual ? (
                            <MoneyInput
                              value={single.anual}
                              currency={selected.currency}
                              onChange={(v) =>
                                updateTier(group.servicioId, single.localKey, {
                                  anual: v,
                                })
                              }
                            />
                          ) : (
                            <span className="text-xs text-slate-400">Sin precio anual</span>
                          )}
                        </td>
                        <td className="px-4 py-3 text-right align-top">
                          {canDelete('config_prices') && (
                            <AppButton
                              type="button"
                              variant="ghost"
                              size="icon"
                              title="Quitar de la lista"
                              aria-label="Quitar de la lista"
                              onClick={() => setRemoveTarget(group)}
                              className="text-slate-400 hover:bg-red-500/10 hover:text-red-500"
                            >
                              <Trash2 className="h-4 w-4" />
                            </AppButton>
                          )}
                        </td>
                      </tr>
                    )
                  }

                  return group.tiers.map((tier, idx) => (
                    <tr
                      key={`${group.servicioId}-${tier.localKey}`}
                      className={cn(
                        'border-b border-slate-100 dark:border-white/[0.04]',
                        err && 'bg-red-500/[0.03]',
                      )}
                    >
                      <td className="px-4 py-3 align-top">
                        {idx === 0 && (
                          <>
                            <p className="font-medium">{group.name}</p>
                            <p className="font-mono text-xs text-slate-500">{group.code}</p>
                            {err && (
                              <p className="mt-2 max-w-[14rem] text-[11px] text-[#EF4444]">
                                {err}
                              </p>
                            )}
                            {canEdit('config_prices') && (
                              <AppButton
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => addVolumeTier(group.servicioId)}
                                className="mt-2 h-auto justify-start px-0 text-[11px] font-normal text-[#0066FF] hover:bg-transparent dark:text-[#0A84FF]"
                              >
                                + Agregar escalón por volumen
                              </AppButton>
                            )}
                          </>
                        )}
                      </td>
                      <td className="px-4 py-3 align-top">
                        <div className="flex flex-wrap items-center gap-2">
                          <label className="text-[10px] uppercase text-slate-400">
                            Desde
                            <AppInput
                              type="number"
                              min={1}
                              mono
                              value={tier.min_quantity}
                              disabled={!canEdit('config_prices')}
                              onChange={(e) => {
                                enableVolumeUi(group.servicioId)
                                updateTier(group.servicioId, tier.localKey, {
                                  min_quantity: Math.max(1, Number(e.target.value) || 1),
                                })
                              }}
                              error={Boolean(err)}
                              className="mt-0.5 w-16 rounded-lg px-2 py-1.5 text-xs"
                            />
                          </label>
                          <label className="text-[10px] uppercase text-slate-400">
                            Hasta
                            <AppInput
                              type="number"
                              min={1}
                              mono
                              placeholder="∞"
                              value={tier.max_quantity ?? ''}
                              disabled={!canEdit('config_prices')}
                              onChange={(e) => {
                                enableVolumeUi(group.servicioId)
                                const raw = e.target.value.trim()
                                updateTier(group.servicioId, tier.localKey, {
                                  max_quantity: raw === '' ? null : Math.max(1, Number(raw) || 1),
                                })
                              }}
                              error={Boolean(err)}
                              className="mt-0.5 w-16 rounded-lg px-2 py-1.5 text-xs"
                            />
                          </label>
                          {canEdit('config_prices') && group.tiers.length > 1 && (
                            <AppButton
                              type="button"
                              variant="ghost"
                              size="icon"
                              title="Quitar escalón"
                              aria-label="Quitar escalón"
                              onClick={() => removeTier(group.servicioId, tier.localKey)}
                              className="h-8 w-8 text-slate-400 hover:bg-red-500/10 hover:text-red-500"
                            >
                              <Trash2 className="h-3.5 w-3.5" />
                            </AppButton>
                          )}
                        </div>
                        <p className="mt-1 font-mono text-[10px] text-slate-400 tabular-nums">
                          {formatRangeLabel(tier.min_quantity, tier.max_quantity)}
                        </p>
                      </td>
                      <td className="px-4 py-3 align-top">
                        {tier.hasMensual ? (
                          <MoneyInput
                            value={tier.mensual}
                            currency={selected.currency}
                            invalid={Boolean(err)}
                            onChange={(v) =>
                              updateTier(group.servicioId, tier.localKey, { mensual: v })
                            }
                          />
                        ) : tier.hasUnico ? (
                          <MoneyInput
                            value={tier.unico}
                            currency={selected.currency}
                            invalid={Boolean(err)}
                            onChange={(v) =>
                              updateTier(group.servicioId, tier.localKey, { unico: v })
                            }
                          />
                        ) : (
                          '—'
                        )}
                      </td>
                      <td className="px-4 py-3 align-top">
                        {tier.hasAnual ? (
                          <MoneyInput
                            value={tier.anual}
                            currency={selected.currency}
                            invalid={Boolean(err)}
                            onChange={(v) =>
                              updateTier(group.servicioId, tier.localKey, { anual: v })
                            }
                          />
                        ) : (
                          <span className="text-xs text-slate-400">—</span>
                        )}
                      </td>
                      <td className="px-4 py-3 text-right align-top">
                        {idx === 0 && canDelete('config_prices') && (
                          <AppButton
                            type="button"
                            variant="ghost"
                            size="icon"
                            title="Quitar producto de la lista"
                            onClick={() => setRemoveTarget(group)}
                            className="text-slate-400 hover:bg-red-500/10 hover:text-red-500"
                          >
                            <Trash2 className="h-4 w-4" />
                          </AppButton>
                        )}
                      </td>
                    </tr>
                  ))
                })}
              </tbody>
            </table>
          </div>

          {/* Móvil: tarjetas por producto */}
          <div className="space-y-3 p-4 lg:hidden">
            {filteredGroups.map((group) => {
              const err = tierErrors.get(group.servicioId) ?? null
              const showVolume =
                group.forceVolumeUi || !isStandardSingleTier(group.tiers)
              return (
                <AppCard
                  key={group.servicioId}
                  className={cn('p-3', err && 'border-[#EF4444]/50')}
                >
                  <div className="flex items-start justify-between gap-2">
                    <div>
                      <p className="font-medium">{group.name}</p>
                      <p className="font-mono text-xs text-slate-500">{group.code}</p>
                    </div>
                    {canDelete('config_prices') && (
                      <AppButton
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label="Quitar de la lista"
                        onClick={() => setRemoveTarget(group)}
                        className="text-slate-400 hover:bg-red-500/10 hover:text-red-500"
                      >
                        <Trash2 className="h-4 w-4" />
                      </AppButton>
                    )}
                  </div>
                  {err && <p className="mt-2 text-[11px] text-[#EF4444]">{err}</p>}
                  {group.tiers.map((tier) => (
                    <div
                      key={tier.localKey}
                      className="mt-3 space-y-2 rounded-xl border border-slate-200/70 p-2 dark:border-white/[0.06]"
                    >
                      {showVolume ? (
                        <div className="flex gap-2">
                          <label className="flex-1 text-[10px] uppercase text-slate-400">
                            Desde
                            <AppInput
                              type="number"
                              min={1}
                              mono
                              value={tier.min_quantity}
                              onChange={(e) =>
                                updateTier(group.servicioId, tier.localKey, {
                                  min_quantity: Math.max(1, Number(e.target.value) || 1),
                                })
                              }
                              error={Boolean(err)}
                              className="mt-0.5 rounded-lg px-2 py-1.5 text-xs"
                            />
                          </label>
                          <label className="flex-1 text-[10px] uppercase text-slate-400">
                            Hasta
                            <AppInput
                              type="number"
                              min={1}
                              mono
                              placeholder="∞"
                              value={tier.max_quantity ?? ''}
                              onChange={(e) => {
                                const raw = e.target.value.trim()
                                updateTier(group.servicioId, tier.localKey, {
                                  max_quantity:
                                    raw === '' ? null : Math.max(1, Number(raw) || 1),
                                })
                              }}
                              error={Boolean(err)}
                              className="mt-0.5 rounded-lg px-2 py-1.5 text-xs"
                            />
                          </label>
                        </div>
                      ) : (
                        <p className="text-xs text-slate-500">
                          {formatRangeLabel(tier.min_quantity, tier.max_quantity)}
                        </p>
                      )}
                      {tier.hasMensual && (
                        <label className="block text-xs text-slate-500">
                          Mensual
                          <MoneyInput
                            className="mt-1"
                            value={tier.mensual}
                            currency={selected.currency}
                            invalid={Boolean(err)}
                            onChange={(v) =>
                              updateTier(group.servicioId, tier.localKey, { mensual: v })
                            }
                          />
                        </label>
                      )}
                      {tier.hasAnual && (
                        <label className="block text-xs text-slate-500">
                          Anual
                          <MoneyInput
                            className="mt-1"
                            value={tier.anual}
                            currency={selected.currency}
                            invalid={Boolean(err)}
                            onChange={(v) =>
                              updateTier(group.servicioId, tier.localKey, { anual: v })
                            }
                          />
                        </label>
                      )}
                      {tier.hasUnico && (
                        <label className="block text-xs text-slate-500">
                          Único
                          <MoneyInput
                            className="mt-1"
                            value={tier.unico}
                            currency={selected.currency}
                            invalid={Boolean(err)}
                            onChange={(v) =>
                              updateTier(group.servicioId, tier.localKey, { unico: v })
                            }
                          />
                        </label>
                      )}
                    </div>
                  ))}
                  {canEdit('config_prices') && (
                    <AppButton
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={() => addVolumeTier(group.servicioId)}
                      className="mt-3 h-auto justify-start px-0 text-[11px] font-normal text-[#0066FF] hover:bg-transparent dark:text-[#0A84FF]"
                    >
                      + Agregar escalón por volumen
                    </AppButton>
                  )}
                </AppCard>
              )
            })}
          </div>

          <footer className="flex flex-col gap-3 border-t border-slate-200/80 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-white/[0.07]">
            <p className="text-sm text-slate-500">
              {hasRangeErrors
                ? 'Hay rangos inválidos o traslapados: corrígelos para guardar.'
                : hasChanges
                  ? 'Hay cambios de precios o escalones sin guardar.'
                  : 'Sin cambios pendientes'}
            </p>
            {canEdit('config_prices') && (
              <AppButton
                type="button"
                disabled={!hasChanges || hasRangeErrors}
                isLoading={isSaving}
                onClick={() => void onSavePrices()}
              >
                <Save className="h-4 w-4" />
                Guardar cambios
              </AppButton>
            )}
          </footer>
        </AppCard>

        <AppModal open={addItemOpen} onOpenChange={setAddItemOpen}>
          <AppModalContent>
            <AppModalHeader>
              <AppModalTitle>Agregar producto</AppModalTitle>
              <AppModalDescription>
                Nueva asignación con rango inicial 1+ (precio único). Puedes agregar escalones
                después.
              </AppModalDescription>
            </AppModalHeader>
            <form
              className="space-y-3"
              onSubmit={itemForm.handleSubmit(async (values) => {
                const precio = normalizeMoneyInput(values.unit_price)
                if (!precio) return
                try {
                  await createPriceListItem({
                    price_list_id: selected.id,
                    service_id: Number(values.service_id),
                    frequency: values.frequency,
                    min_quantity: 1,
                    max_quantity: null,
                    unit_price: precio,
                  })
                  toast.success('Producto agregado', 'Ya está disponible en esta lista.')
                  setAddItemOpen(false)
                } catch (error) {
                  toast.apiError(error)
                }
              })}
            >
              <label className="block text-sm">
                Producto
                <AppSelect
                  withLabelSpacing
                  value={itemForm.watch('service_id')}
                  onValueChange={(value) =>
                    itemForm.setValue('service_id', value, { shouldValidate: true })
                  }
                  options={services.map((service) => ({
                    value: String(service.id),
                    label: service.name,
                  }))}
                />
              </label>
              <label className="block text-sm">
                Frecuencia
                <AppSelect
                  withLabelSpacing
                  value={itemForm.watch('frequency')}
                  onValueChange={(value) =>
                    itemForm.setValue('frequency', value as AddItemForm['frequency'], {
                      shouldValidate: true,
                    })
                  }
                  options={[
                    { value: 'monthly', label: 'Mensual' },
                    { value: 'annual', label: 'Anual' },
                    { value: 'single', label: 'Único' },
                  ]}
                />
              </label>
              <label className="block text-sm">
                Precio
                <AppCurrencyInput withLabelSpacing {...itemForm.register('unit_price')} />
              </label>
              <AppButton type="submit" className="w-full rounded-xl">
                Agregar
              </AppButton>
            </form>
          </AppModalContent>
        </AppModal>

        <AppModal
          open={removeTarget != null}
          onOpenChange={(open) => {
            if (!open) setRemoveTarget(null)
          }}
        >
          <AppModalContent className="max-w-lg">
            <AppModalHeader>
              <AppModalTitle>Quitar producto de la lista</AppModalTitle>
              <AppModalDescription>
                Se eliminarán todos los escalones de este producto en la lista. Los contratos
                activos conservan su precio pactado.
              </AppModalDescription>
            </AppModalHeader>
            {removeTarget && (
              <p className="rounded-xl border border-slate-200/80 bg-slate-50 px-3 py-2 text-sm dark:border-white/[0.08] dark:bg-white/[0.04]">
                <span className="font-medium">{removeTarget.name}</span>
                <span className="ml-2 font-mono text-xs text-slate-500">
                  {removeTarget.code}
                </span>
              </p>
            )}
            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <AppButton
                type="button"
                variant="outline"
                className="rounded-xl"
                onClick={() => setRemoveTarget(null)}
                disabled={isSaving}
              >
                Cancelar
              </AppButton>
              <AppButton
                type="button"
                variant="danger"
                isLoading={isSaving}
                onClick={() => void onConfirmRemove()}
              >
                Quitar de la lista
              </AppButton>
            </div>
          </AppModalContent>
        </AppModal>
      </div>
    )
  }

  /* ── Directorio de listas ── */
  return (
    <div className="space-y-5">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="font-heading text-2xl font-semibold md:text-3xl">Listas de precios</h1>
          <p className="mt-1 text-sm text-slate-500">
            Elige una lista para ver precios y escalones por volumen (RF-2).
          </p>
        </div>
        {canCreate('config_prices') && (
          <AppButton className="rounded-xl" onClick={() => setNewListOpen(true)}>
            <Plus className="mr-1 h-4 w-4" /> Nueva lista
          </AppButton>
        )}
      </header>

      <AppCard className="space-y-3 p-4">
        <AppSearchInput
          value={search}
          onChange={(e) => {
            setSearch(e.target.value)
            setPage(1)
          }}
          onClear={() => {
            setSearch('')
            setPage(1)
          }}
          placeholder="Buscar lista por nombre…"
          aria-label="Buscar lista por nombre"
        />
        <div className="flex flex-wrap items-end gap-3">
          <PillFilter
            label="Segmento"
            value={segment}
            options={segmentOptions}
            disabled={isSegmentLocked}
            onChange={(v) => {
              if (isSegmentLocked) return
              setSegment(v)
              setPage(1)
            }}
          />
          <PillFilter
            label="Moneda"
            value={moneda}
            options={['Todas', 'MXN', 'USD']}
            onChange={(v) => {
              setMoneda(v)
              setPage(1)
            }}
          />
          <PillFilter
            label="Vigencia"
            value={vigencia}
            options={['Todas', 'Vigentes', 'Inactivas']}
            onChange={(v) => {
              setVigencia(v)
              setPage(1)
            }}
          />
        </div>
      </AppCard>

      <AppCard className="space-y-3 p-4">
        {pageRows.map((list) => {
          const products = countAssignedProducts(list)
          return (
            <AppCard
              key={list.id}
              className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
            >
              <div>
                <p className="font-heading font-semibold">{list.name}</p>
                <p className="mt-1 text-xs text-slate-500">
                  {list.segment_name || '—'} · {list.currency} · vigente desde {list.valid_from.slice(0, 10)}
                </p>
                <div className="mt-2 flex flex-wrap gap-2">
                  <AppBadge
                    label={`${products} ${
                      products === 1 ? 'producto en esta lista' : 'productos en esta lista'
                    }`}
                    tone="info"
                  />
                  <AppBadge
                    label={list.is_active ? 'Activa' : 'Inactiva'}
                    tone={list.is_active ? 'success' : 'neutral'}
                  />
                  {list.is_default && (
                    <AppBadge label="★ Default" tone="warning" />
                  )}
                </div>
              </div>
              <div className="flex flex-wrap gap-2">
                {canEdit('config_prices') && !list.is_default && (
                  <AppButton
                    type="button"
                    variant="outline"
                    className="rounded-xl"
                    onClick={() => void markAsDefault(list)}
                  >
                    Predeterminada (Default)
                  </AppButton>
                )}
                <AppButton className="rounded-xl" onClick={() => setEditingListId(list.id)}>
                  Ver / Editar precios
                </AppButton>
              </div>
            </AppCard>
          )
        })}
        {filteredLists.length === 0 && (
          <p className="text-sm text-slate-500">No hay listas con esos filtros.</p>
        )}
        <PaginationBar
          page={page}
          total={filteredLists.length}
          pageSize={pageSize}
          onPageChange={setPage}
          onPageSizeChange={setPageSize}
        />
      </AppCard>

      <AppModal open={newListOpen} onOpenChange={setNewListOpen}>
        <AppModalContent>
          <AppModalHeader>
            <AppModalTitle>Nueva lista de precios</AppModalTitle>
            <AppModalDescription>Se guarda en MySQL de inmediato.</AppModalDescription>
          </AppModalHeader>
          <form
            className="space-y-3"
            onSubmit={listForm.handleSubmit(async (values) => {
              try {
                await createPriceList({
                  name: values.name,
                  segment_id: Number(values.segment_id),
                  currency: values.currency,
                  valid_from: values.valid_from,
                  is_default: values.is_default,
                  is_active: true,
                })
                toast.success(
                  'Lista creada',
                  values.is_default
                    ? `${values.name} marcada como ★ Default.`
                    : values.name,
                )
                setNewListOpen(false)
              } catch (error) {
                toast.apiError(error)
              }
            })}
          >
            <label className="block text-sm">
              Nombre
              <AppInput withLabelSpacing {...listForm.register('name')} />
            </label>
            <label className="block text-sm">
              Segmento
              <AppSelect
                withLabelSpacing
                value={listForm.watch('segment_id')}
                onValueChange={(value) =>
                  listForm.setValue('segment_id', value, { shouldValidate: true })
                }
                options={segments.map((segmentOption) => ({
                  value: String(segmentOption.id),
                  label: segmentOption.name,
                }))}
              />
            </label>
            <label className="block text-sm">
              Moneda
              <AppSelect
                withLabelSpacing
                value={listForm.watch('currency')}
                onValueChange={(value) =>
                  listForm.setValue('currency', value as NewListForm['currency'], {
                    shouldValidate: true,
                  })
                }
                options={[
                  { value: 'MXN', label: 'MXN' },
                  { value: 'USD', label: 'USD' },
                ]}
              />
            </label>
            <label className="block text-sm">
              Vigente desde
              <AppInput type="date" withLabelSpacing {...listForm.register('valid_from')} />
            </label>
            <label className="flex items-center gap-3 text-sm">
              <AppSwitch
                checked={listForm.watch('is_default')}
                onCheckedChange={(next) =>
                  listForm.setValue('is_default', next, { shouldValidate: true })
                }
                label="Predeterminada (Default) para este segmento y moneda"
              />
              Predeterminada (Default) para este segmento y moneda
            </label>
            <AppButton type="submit" className="w-full rounded-xl">
              Crear lista
            </AppButton>
          </form>
        </AppModalContent>
      </AppModal>
    </div>
  )
}

function PillFilter({
  label,
  value,
  options,
  onChange,
  disabled = false,
}: {
  label: string
  value: string
  options: Array<string | { value: string; label: string }>
  onChange: (v: string) => void
  disabled?: boolean
}) {
  return (
    <AppFilterField label={label}>
      <div className="ios-pill-track" role="group" aria-label={label}>
        {options.map((opt) => {
          const item = typeof opt === 'string' ? { value: opt, label: opt } : opt
          const active = value === item.value
          return (
            <AppButton
              key={item.value}
              type="button"
              variant="ghost"
              size="sm"
              disabled={disabled}
              onClick={() => onChange(item.value)}
              className={cn(
                'h-auto rounded-xl px-2.5 py-1 text-xs font-normal',
                active
                  ? 'bg-[#0066FF] font-medium text-white hover:bg-[#0055DD] hover:text-white dark:bg-[#0A84FF] dark:text-white dark:hover:bg-[#0A74E0]'
                  : 'text-slate-600 dark:text-slate-300',
                disabled && 'cursor-not-allowed opacity-80',
              )}
            >
              {item.label}
            </AppButton>
          )
        })}
      </div>
    </AppFilterField>
  )
}

function MoneyInput({
  value,
  onChange,
  currency,
  className,
  invalid,
}: {
  value: string
  onChange: (v: string) => void
  currency: string
  className?: string
  invalid?: boolean
}) {
  return (
    <div className={cn('relative max-w-[9.5rem]', className)}>
      <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400">
        {currency === 'USD' ? 'US$' : '$'}
      </span>
      <AppCurrencyInput
        value={value}
        onChange={(e) => onChange(e.target.value.replace(/[^\d.]/g, ''))}
        inputMode="decimal"
        error={invalid}
        className="pl-8 text-left"
      />
    </div>
  )
}
