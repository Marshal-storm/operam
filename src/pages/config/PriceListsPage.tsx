import { zodResolver } from '@hookform/resolvers/zod'
import { ArrowLeft, Check, Plus, Save, Search, Trash2 } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { PaginationBar } from '@/components/ui/PaginationBar'
import { Skeleton } from '@/components/ui/skeleton'
import { StatusPill } from '@/components/ui/StatusPill'
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
  cantidad_min: number
  cantidad_max: number | null
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
  codigo: string
  nombre: string
  tiers: ScaleTier[]
  /** Forzar vista de escalones aunque solo haya un rango 1+. */
  forceVolumeUi: boolean
}

function countAssignedProducts(list: PriceList): number {
  return new Set(list.items.map((item) => item.servicio_id)).size
}

function isStandardSingleTier(tiers: ScaleTier[]): boolean {
  return (
    tiers.length === 1 &&
    tiers[0].cantidad_min === 1 &&
    tiers[0].cantidad_max === null
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
    if (!Number.isFinite(tier.cantidad_min) || tier.cantidad_min < 1) {
      return 'Cada escalón debe iniciar en cantidad mínima ≥ 1.'
    }
    if (tier.cantidad_max != null && tier.cantidad_max < tier.cantidad_min) {
      return `Máximo (${tier.cantidad_max}) no puede ser menor que el mínimo (${tier.cantidad_min}).`
    }
  }
  for (let i = 0; i < tiers.length; i++) {
    for (let j = i + 1; j < tiers.length; j++) {
      if (
        rangesOverlap(
          tiers[i].cantidad_min,
          tiers[i].cantidad_max,
          tiers[j].cantidad_min,
          tiers[j].cantidad_max,
        )
      ) {
        return `Los rangos ${formatRangeLabel(tiers[i].cantidad_min, tiers[i].cantidad_max)} y ${formatRangeLabel(tiers[j].cantidad_min, tiers[j].cantidad_max)} se traslapan.`
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
      codigo: string
      nombre: string
      byRange: Map<string, ScaleTier>
    }
  >()

  for (const item of list.items) {
    let bucket = byService.get(item.servicio_id)
    if (!bucket) {
      bucket = {
        codigo: item.servicio_codigo,
        nombre: item.servicio_nombre,
        byRange: new Map<string, ScaleTier>(),
      }
      byService.set(item.servicio_id, bucket)
    }
    const rangeKey = `${item.cantidad_min}:${item.cantidad_max ?? '∞'}`
    let tier = bucket.byRange.get(rangeKey)
    if (!tier) {
      tier = {
        localKey: `db-${item.servicio_id}-${rangeKey}`,
        cantidad_min: item.cantidad_min,
        cantidad_max: item.cantidad_max,
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

    if (item.frecuencia === 'mensual') {
      tier.mensualId = item.id
      tier.mensual = item.precio_unitario
      tier.hasMensual = true
    } else if (item.frecuencia === 'anual') {
      tier.anualId = item.id
      tier.anual = item.precio_unitario
      tier.hasAnual = true
    } else if (item.frecuencia === 'unico') {
      tier.unicoId = item.id
      tier.unico = item.precio_unitario
      tier.hasUnico = true
    }
  }

  return [...byService.entries()]
    .map(([servicioId, data]) => {
      const tiers = [...data.byRange.values()].sort(
        (a, b) => a.cantidad_min - b.cantidad_min,
      )
      return {
        servicioId,
        codigo: data.codigo,
        nombre: data.nombre,
        tiers,
        forceVolumeUi: !isStandardSingleTier(tiers),
      }
    })
    .sort((a, b) => a.codigo.localeCompare(b.codigo))
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
  nombre: z.string().min(3),
  segmento_id: z.string().min(1),
  moneda: z.enum(['MXN', 'USD']),
  vigente_desde: z.string().min(8),
  es_default: z.boolean(),
})

const addItemSchema = z.object({
  servicio_id: z.string().min(1),
  frecuencia: z.enum(['mensual', 'anual', 'unico']),
  precio_unitario: z.string().regex(/^\d+(\.\d{1,2})?$/),
})

type NewListForm = z.infer<typeof newListSchema>
type AddItemForm = z.infer<typeof addItemSchema>

const inputClass =
  'mt-1 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-[#0066FF]/30 dark:border-white/[0.08] dark:bg-white/[0.05]'

const coralBorder = 'border-[#EF4444] ring-1 ring-[#EF4444]/40'

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
    segmento,
    setSegmento,
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
      if (q && !list.nombre.toLowerCase().includes(q)) return false
      if (segmento !== 'Todos' && list.segmento !== segmento) return false
      if (moneda !== 'Todas' && list.moneda !== moneda) return false
      if (vigencia === 'Vigentes') {
        const fromOk = list.vigente_desde.slice(0, 10) <= today
        const toOk = !list.vigente_hasta || list.vigente_hasta.slice(0, 10) >= today
        if (!fromOk || !toOk || !list.activa) return false
      }
      if (vigencia === 'Inactivas' && list.activa) return false
      return true
    })
  }, [lists, search, segmento, moneda, vigencia, today])

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
        g.nombre.toLowerCase().includes(q) || g.codigo.toLowerCase().includes(q),
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
      nombre: '',
      segmento_id: '1',
      moneda: 'MXN',
      vigente_desde: today,
      es_default: false,
    },
  })

  async function markAsDefault(list: (typeof lists)[number]) {
    if (!canEdit('config_precios') || list.es_default) return
    try {
      await updatePriceList({ id: list.id, data: { es_default: true } })
      toast.success('Lista predeterminada', `${list.nombre} es ahora ★ Default.`)
    } catch (error) {
      toast.apiError(error)
    }
  }

  const itemForm = useForm<AddItemForm>({
    resolver: zodResolver(addItemSchema),
    defaultValues: { servicio_id: '1', frecuencia: 'mensual', precio_unitario: '0.00' },
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
        const sorted = [...g.tiers].sort((a, b) => a.cantidad_min - b.cantidad_min)
        const last = sorted[sorted.length - 1]
        let nextMin = 1
        let patched = sorted
        if (last) {
          if (last.cantidad_max == null) {
            // Cierra el “en adelante” en su mínimo para liberar el siguiente escalón.
            patched = sorted.map((tier, idx) =>
              idx === sorted.length - 1
                ? { ...tier, cantidad_max: tier.cantidad_min }
                : tier,
            )
            nextMin = last.cantidad_min + 1
          } else {
            nextMin = last.cantidad_max + 1
          }
        }

        const template = last ?? sorted[0]
        const newTier: ScaleTier = {
          localKey: `new-${servicioId}-${Date.now()}`,
          cantidad_min: nextMin,
          cantidad_max: null,
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
        const min = tier.cantidad_min
        const max = tier.cantidad_max

        const pushFreq = (
          freq: 'mensual' | 'anual' | 'unico',
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
            if (!prev || prev.precio_unitario !== price) {
              patch.precio_unitario = price
              dirty = true
            }
            if (!prev || prev.cantidad_min !== min) {
              patch.cantidad_min = min
              dirty = true
            }
            if (!prev || prev.cantidad_max !== max) {
              patch.cantidad_max = max
              dirty = true
            }
            if (dirty) updates.push(patch)
          } else {
            creates.push({
              lista_precio_id: list.id,
              servicio_id: group.servicioId,
              frecuencia: freq,
              cantidad_min: min,
              cantidad_max: max,
              precio_unitario: price,
            })
          }
        }

        pushFreq('mensual', tier.mensualId, tier.mensual, tier.hasMensual)
        pushFreq('anual', tier.anualId, tier.anual, tier.hasAnual)
        pushFreq('unico', tier.unicoId, tier.unico, tier.hasUnico)
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
        lista_precio_id: selected.id,
        servicio_id: removeTarget.servicioId,
      })
      toast.success('Producto quitado', `${removeTarget.nombre} ya no está en esta lista.`)
      setRemoveTarget(null)
    } catch (error) {
      toast.apiError(error)
    }
  }

  if (isLoading) return <Skeleton className="h-64 w-full rounded-2xl" />
  if (isApiError) {
    return (
      <section className="glass-panel border-red-500/30 p-5">
        <h2 className="font-heading font-semibold text-red-500">Error</h2>
        <p className="text-sm text-slate-500">{errorMessage}</p>
        <Button className="mt-4 rounded-xl" onClick={() => void refetch()}>
          Reintentar
        </Button>
      </section>
    )
  }

  /* ── Vista detalle de una lista ── */
  if (selected) {
    const productCount = countAssignedProducts(selected)
    return (
      <div className="space-y-5">
        <button
          type="button"
          onClick={() => {
            setEditingListId(null)
            setProductSearch('')
          }}
          className="inline-flex items-center gap-1.5 text-sm text-[#0066FF] dark:text-[#0A84FF]"
        >
          <ArrowLeft className="h-4 w-4" /> Volver a todas las listas
        </button>

        <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <h1 className="font-heading text-2xl font-semibold">{selected.nombre}</h1>
            <p className="mt-1 text-sm text-slate-500">
              {selected.segmento} · {selected.moneda} · {productCount}{' '}
              {productCount === 1 ? 'producto en esta lista' : 'productos en esta lista'}
            </p>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            {savedFlash && (
              <p className="flex items-center gap-1 text-sm text-emerald-500">
                <Check className="h-4 w-4" /> Guardado
              </p>
            )}
            {canCreate('config_precios') && (
              <Button
                className="rounded-xl bg-[#0066FF] text-white dark:bg-[#0A84FF]"
                onClick={() => setAddItemOpen(true)}
              >
                <Plus className="mr-1 h-4 w-4" /> Agregar producto a la lista
              </Button>
            )}
          </div>
        </header>

        <div className="relative">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <input
            value={productSearch}
            onChange={(e) => setProductSearch(e.target.value)}
            placeholder="Buscar producto en esta lista…"
            className="w-full rounded-2xl border border-slate-200 bg-white py-2.5 pl-10 pr-4 text-sm dark:border-white/[0.08] dark:bg-white/[0.05]"
          />
        </div>

        <section className="glass-panel overflow-hidden p-0">
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
                          <p className="font-medium">{group.nombre}</p>
                          <p className="font-mono text-xs text-slate-500">{group.codigo}</p>
                        </td>
                        <td className="px-4 py-3 align-top">
                          <div className="space-y-2">
                            <span className="inline-flex rounded-full border border-slate-200/80 bg-slate-50 px-2.5 py-1 text-xs dark:border-white/[0.08] dark:bg-white/[0.04]">
                              {formatRangeLabel(single.cantidad_min, single.cantidad_max)}
                            </span>
                            {canEdit('config_precios') && (
                              <button
                                type="button"
                                onClick={() => addVolumeTier(group.servicioId)}
                                className="block text-left text-[11px] text-[#0066FF] dark:text-[#0A84FF]"
                              >
                                + Agregar escalón por volumen
                              </button>
                            )}
                          </div>
                        </td>
                        <td className="px-4 py-3 align-top">
                          {single.hasMensual ? (
                            <MoneyInput
                              value={single.mensual}
                              currency={selected.moneda}
                              onChange={(v) =>
                                updateTier(group.servicioId, single.localKey, {
                                  mensual: v,
                                })
                              }
                            />
                          ) : single.hasUnico ? (
                            <MoneyInput
                              value={single.unico}
                              currency={selected.moneda}
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
                              currency={selected.moneda}
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
                          {canDelete('config_precios') && (
                            <button
                              type="button"
                              title="Quitar de la lista"
                              aria-label="Quitar de la lista"
                              onClick={() => setRemoveTarget(group)}
                              className="inline-flex h-9 w-9 items-center justify-center rounded-xl text-slate-400 transition hover:bg-red-500/10 hover:text-red-500"
                            >
                              <Trash2 className="h-4 w-4" />
                            </button>
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
                            <p className="font-medium">{group.nombre}</p>
                            <p className="font-mono text-xs text-slate-500">{group.codigo}</p>
                            {err && (
                              <p className="mt-2 max-w-[14rem] text-[11px] text-[#EF4444]">
                                {err}
                              </p>
                            )}
                            {canEdit('config_precios') && (
                              <button
                                type="button"
                                onClick={() => addVolumeTier(group.servicioId)}
                                className="mt-2 text-[11px] text-[#0066FF] dark:text-[#0A84FF]"
                              >
                                + Agregar escalón por volumen
                              </button>
                            )}
                          </>
                        )}
                      </td>
                      <td className="px-4 py-3 align-top">
                        <div className="flex flex-wrap items-center gap-2">
                          <label className="text-[10px] uppercase text-slate-400">
                            Desde
                            <input
                              type="number"
                              min={1}
                              value={tier.cantidad_min}
                              disabled={!canEdit('config_precios')}
                              onChange={(e) => {
                                enableVolumeUi(group.servicioId)
                                updateTier(group.servicioId, tier.localKey, {
                                  cantidad_min: Math.max(1, Number(e.target.value) || 1),
                                })
                              }}
                              className={cn(
                                'mt-0.5 w-16 rounded-lg border bg-white px-2 py-1.5 font-mono text-xs tabular-nums dark:bg-white/[0.05]',
                                err ? coralBorder : 'border-slate-200 dark:border-white/[0.08]',
                              )}
                            />
                          </label>
                          <label className="text-[10px] uppercase text-slate-400">
                            Hasta
                            <input
                              type="number"
                              min={1}
                              placeholder="∞"
                              value={tier.cantidad_max ?? ''}
                              disabled={!canEdit('config_precios')}
                              onChange={(e) => {
                                enableVolumeUi(group.servicioId)
                                const raw = e.target.value.trim()
                                updateTier(group.servicioId, tier.localKey, {
                                  cantidad_max: raw === '' ? null : Math.max(1, Number(raw) || 1),
                                })
                              }}
                              className={cn(
                                'mt-0.5 w-16 rounded-lg border bg-white px-2 py-1.5 font-mono text-xs tabular-nums dark:bg-white/[0.05]',
                                err ? coralBorder : 'border-slate-200 dark:border-white/[0.08]',
                              )}
                            />
                          </label>
                          {canEdit('config_precios') && group.tiers.length > 1 && (
                            <button
                              type="button"
                              title="Quitar escalón"
                              onClick={() => removeTier(group.servicioId, tier.localKey)}
                              className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-red-500/10 hover:text-red-500"
                            >
                              <Trash2 className="h-3.5 w-3.5" />
                            </button>
                          )}
                        </div>
                        <p className="mt-1 font-mono text-[10px] text-slate-400 tabular-nums">
                          {formatRangeLabel(tier.cantidad_min, tier.cantidad_max)}
                        </p>
                      </td>
                      <td className="px-4 py-3 align-top">
                        {tier.hasMensual ? (
                          <MoneyInput
                            value={tier.mensual}
                            currency={selected.moneda}
                            invalid={Boolean(err)}
                            onChange={(v) =>
                              updateTier(group.servicioId, tier.localKey, { mensual: v })
                            }
                          />
                        ) : tier.hasUnico ? (
                          <MoneyInput
                            value={tier.unico}
                            currency={selected.moneda}
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
                            currency={selected.moneda}
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
                        {idx === 0 && canDelete('config_precios') && (
                          <button
                            type="button"
                            title="Quitar producto de la lista"
                            onClick={() => setRemoveTarget(group)}
                            className="inline-flex h-9 w-9 items-center justify-center rounded-xl text-slate-400 transition hover:bg-red-500/10 hover:text-red-500"
                          >
                            <Trash2 className="h-4 w-4" />
                          </button>
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
                <article
                  key={group.servicioId}
                  className={cn(
                    'rounded-2xl border p-3 dark:border-white/[0.08]',
                    err ? 'border-[#EF4444]/50' : 'border-slate-200/80',
                  )}
                >
                  <div className="flex items-start justify-between gap-2">
                    <div>
                      <p className="font-medium">{group.nombre}</p>
                      <p className="font-mono text-xs text-slate-500">{group.codigo}</p>
                    </div>
                    {canDelete('config_precios') && (
                      <button
                        type="button"
                        onClick={() => setRemoveTarget(group)}
                        className="inline-flex h-9 w-9 items-center justify-center rounded-xl text-slate-400 hover:bg-red-500/10 hover:text-red-500"
                      >
                        <Trash2 className="h-4 w-4" />
                      </button>
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
                            <input
                              type="number"
                              min={1}
                              value={tier.cantidad_min}
                              onChange={(e) =>
                                updateTier(group.servicioId, tier.localKey, {
                                  cantidad_min: Math.max(1, Number(e.target.value) || 1),
                                })
                              }
                              className={cn(
                                'mt-0.5 w-full rounded-lg border px-2 py-1.5 font-mono text-xs tabular-nums',
                                err ? coralBorder : 'border-slate-200 dark:border-white/[0.08]',
                              )}
                            />
                          </label>
                          <label className="flex-1 text-[10px] uppercase text-slate-400">
                            Hasta
                            <input
                              type="number"
                              min={1}
                              placeholder="∞"
                              value={tier.cantidad_max ?? ''}
                              onChange={(e) => {
                                const raw = e.target.value.trim()
                                updateTier(group.servicioId, tier.localKey, {
                                  cantidad_max:
                                    raw === '' ? null : Math.max(1, Number(raw) || 1),
                                })
                              }}
                              className={cn(
                                'mt-0.5 w-full rounded-lg border px-2 py-1.5 font-mono text-xs tabular-nums',
                                err ? coralBorder : 'border-slate-200 dark:border-white/[0.08]',
                              )}
                            />
                          </label>
                        </div>
                      ) : (
                        <p className="text-xs text-slate-500">
                          {formatRangeLabel(tier.cantidad_min, tier.cantidad_max)}
                        </p>
                      )}
                      {tier.hasMensual && (
                        <label className="block text-xs text-slate-500">
                          Mensual
                          <MoneyInput
                            className="mt-1"
                            value={tier.mensual}
                            currency={selected.moneda}
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
                            currency={selected.moneda}
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
                            currency={selected.moneda}
                            invalid={Boolean(err)}
                            onChange={(v) =>
                              updateTier(group.servicioId, tier.localKey, { unico: v })
                            }
                          />
                        </label>
                      )}
                    </div>
                  ))}
                  {canEdit('config_precios') && (
                    <button
                      type="button"
                      onClick={() => addVolumeTier(group.servicioId)}
                      className="mt-3 text-[11px] text-[#0066FF] dark:text-[#0A84FF]"
                    >
                      + Agregar escalón por volumen
                    </button>
                  )}
                </article>
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
            {canEdit('config_precios') && (
              <Button
                type="button"
                disabled={!hasChanges || hasRangeErrors || isSaving}
                onClick={() => void onSavePrices()}
                className={cn(
                  'rounded-xl',
                  hasChanges && !hasRangeErrors
                    ? 'bg-blue-600 text-white hover:bg-blue-700 dark:bg-blue-600'
                    : 'cursor-not-allowed bg-slate-200 text-slate-500 opacity-60 dark:bg-white/[0.06] dark:text-slate-400',
                )}
              >
                <Save className="mr-1 h-4 w-4" />
                {isSaving ? 'Guardando…' : 'Guardar cambios'}
              </Button>
            )}
          </footer>
        </section>

        <Dialog open={addItemOpen} onOpenChange={setAddItemOpen}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Agregar producto</DialogTitle>
              <DialogDescription>
                Nueva asignación con rango inicial 1+ (precio único). Puedes agregar escalones
                después.
              </DialogDescription>
            </DialogHeader>
            <form
              className="space-y-3"
              onSubmit={itemForm.handleSubmit(async (values) => {
                const precio = normalizeMoneyInput(values.precio_unitario)
                if (!precio) return
                try {
                  await createPriceListItem({
                    lista_precio_id: selected.id,
                    servicio_id: Number(values.servicio_id),
                    frecuencia: values.frecuencia,
                    cantidad_min: 1,
                    cantidad_max: null,
                    precio_unitario: precio,
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
                <select className={inputClass} {...itemForm.register('servicio_id')}>
                  {services.map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.nombre}
                    </option>
                  ))}
                </select>
              </label>
              <label className="block text-sm">
                Frecuencia
                <select className={inputClass} {...itemForm.register('frecuencia')}>
                  <option value="mensual">Mensual</option>
                  <option value="anual">Anual</option>
                  <option value="unico">Único</option>
                </select>
              </label>
              <label className="block text-sm">
                Precio
                <input
                  className={cn(inputClass, 'font-mono')}
                  {...itemForm.register('precio_unitario')}
                />
              </label>
              <Button type="submit" className="w-full rounded-xl">
                Agregar
              </Button>
            </form>
          </DialogContent>
        </Dialog>

        <Dialog
          open={removeTarget != null}
          onOpenChange={(open) => {
            if (!open) setRemoveTarget(null)
          }}
        >
          <DialogContent className="max-w-lg">
            <DialogHeader>
              <DialogTitle>Quitar producto de la lista</DialogTitle>
              <DialogDescription>
                Se eliminarán todos los escalones de este producto en la lista. Los contratos
                activos conservan su precio pactado.
              </DialogDescription>
            </DialogHeader>
            {removeTarget && (
              <p className="rounded-xl border border-slate-200/80 bg-slate-50 px-3 py-2 text-sm dark:border-white/[0.08] dark:bg-white/[0.04]">
                <span className="font-medium">{removeTarget.nombre}</span>
                <span className="ml-2 font-mono text-xs text-slate-500">
                  {removeTarget.codigo}
                </span>
              </p>
            )}
            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                type="button"
                variant="outline"
                className="rounded-xl"
                onClick={() => setRemoveTarget(null)}
                disabled={isSaving}
              >
                Cancelar
              </Button>
              <Button
                type="button"
                className="rounded-xl bg-red-600 text-white hover:bg-red-700"
                onClick={() => void onConfirmRemove()}
                disabled={isSaving}
              >
                {isSaving ? 'Quitando…' : 'Quitar de la lista'}
              </Button>
            </div>
          </DialogContent>
        </Dialog>
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
        {canCreate('config_precios') && (
          <Button className="rounded-xl" onClick={() => setNewListOpen(true)}>
            <Plus className="mr-1 h-4 w-4" /> Nueva lista
          </Button>
        )}
      </header>

      <div className="glass-panel space-y-3 p-4">
        <div className="relative">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <input
            value={search}
            onChange={(e) => {
              setSearch(e.target.value)
              setPage(1)
            }}
            placeholder="Buscar lista por nombre…"
            className="w-full rounded-2xl border border-slate-200 bg-slate-50 py-2.5 pl-10 pr-4 text-sm dark:border-white/[0.08] dark:bg-white/[0.05]"
          />
        </div>
        <div className="flex flex-wrap gap-2">
          <PillFilter
            value={segmento}
            options={segmentOptions}
            onChange={(v) => {
              if (isSegmentLocked) return
              setSegmento(v)
              setPage(1)
            }}
          />
          <PillFilter
            value={moneda}
            options={['Todas', 'MXN', 'USD']}
            onChange={(v) => {
              setMoneda(v)
              setPage(1)
            }}
          />
          <PillFilter
            value={vigencia}
            options={['Todas', 'Vigentes', 'Inactivas']}
            onChange={(v) => {
              setVigencia(v)
              setPage(1)
            }}
          />
        </div>
      </div>

      <section className="glass-panel space-y-3 p-4">
        {pageRows.map((list) => {
          const products = countAssignedProducts(list)
          return (
            <article
              key={list.id}
              className="flex flex-col gap-3 rounded-2xl border border-slate-200/80 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-white/[0.08]"
            >
              <div>
                <p className="font-heading font-semibold">{list.nombre}</p>
                <p className="mt-1 text-xs text-slate-500">
                  {list.segmento} · {list.moneda} · vigente desde {list.vigente_desde.slice(0, 10)}
                </p>
                <div className="mt-2 flex flex-wrap gap-2">
                  <StatusPill
                    label={`${products} ${
                      products === 1 ? 'producto en esta lista' : 'productos en esta lista'
                    }`}
                    tone="info"
                  />
                  <StatusPill
                    label={list.activa ? 'Activa' : 'Inactiva'}
                    tone={list.activa ? 'positive' : 'neutral'}
                  />
                  {list.es_default && (
                    <StatusPill label="★ Default" tone="warning" />
                  )}
                </div>
              </div>
              <div className="flex flex-wrap gap-2">
                {canEdit('config_precios') && !list.es_default && (
                  <Button
                    type="button"
                    variant="outline"
                    className="rounded-xl"
                    onClick={() => void markAsDefault(list)}
                  >
                    Predeterminada (Default)
                  </Button>
                )}
                <Button className="rounded-xl" onClick={() => setEditingListId(list.id)}>
                  Ver / Editar precios
                </Button>
              </div>
            </article>
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
      </section>

      <Dialog open={newListOpen} onOpenChange={setNewListOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Nueva lista de precios</DialogTitle>
            <DialogDescription>Se guarda en MySQL de inmediato.</DialogDescription>
          </DialogHeader>
          <form
            className="space-y-3"
            onSubmit={listForm.handleSubmit(async (values) => {
              try {
                await createPriceList({
                  nombre: values.nombre,
                  segmento_id: Number(values.segmento_id),
                  moneda: values.moneda,
                  vigente_desde: values.vigente_desde,
                  es_default: values.es_default,
                  activa: true,
                })
                toast.success(
                  'Lista creada',
                  values.es_default
                    ? `${values.nombre} marcada como ★ Default.`
                    : values.nombre,
                )
                setNewListOpen(false)
              } catch (error) {
                toast.apiError(error)
              }
            })}
          >
            <label className="block text-sm">
              Nombre
              <input className={inputClass} {...listForm.register('nombre')} />
            </label>
            <label className="block text-sm">
              Segmento
              <select className={inputClass} {...listForm.register('segmento_id')}>
                {segments.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.nombre}
                  </option>
                ))}
              </select>
            </label>
            <label className="block text-sm">
              Moneda
              <select className={inputClass} {...listForm.register('moneda')}>
                <option value="MXN">MXN</option>
                <option value="USD">USD</option>
              </select>
            </label>
            <label className="block text-sm">
              Vigente desde
              <input type="date" className={inputClass} {...listForm.register('vigente_desde')} />
            </label>
            <label className="flex items-center gap-3 text-sm">
              <input type="checkbox" {...listForm.register('es_default')} />
              Predeterminada (Default) para este segmento y moneda
            </label>
            <Button type="submit" className="w-full rounded-xl">
              Crear lista
            </Button>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  )
}

function PillFilter({
  value,
  options,
  onChange,
}: {
  value: string
  options: string[]
  onChange: (v: string) => void
}) {
  return (
    <div className="ios-pill-track">
      {options.map((opt) => (
        <button
          key={opt}
          type="button"
          onClick={() => onChange(opt)}
          className={cn(
            'rounded-xl px-2.5 py-1 text-xs',
            value === opt ? 'ios-pill-active' : 'text-slate-600 dark:text-slate-300',
          )}
        >
          {opt}
        </button>
      ))}
    </div>
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
      <input
        value={value}
        onChange={(e) => onChange(e.target.value.replace(/[^\d.]/g, ''))}
        className={cn(
          'w-full rounded-xl border bg-white py-2 pl-8 pr-3 font-mono text-sm tabular-nums dark:bg-white/[0.05]',
          invalid ? coralBorder : 'border-slate-200 dark:border-white/[0.08]',
        )}
        inputMode="decimal"
      />
    </div>
  )
}
