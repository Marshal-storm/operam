import { Check, Copy, Gift } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard } from '@/components/ui/AppCard'
import { AppFilterBar } from '@/components/ui/AppFilterBar'
import { PaginationBar } from '@/components/ui/PaginationBar'
import { Skeleton } from '@/components/ui/skeleton'
import { useToast } from '@/context/ToastContext'
import { useSegmentLock } from '@/hooks/useSegmentLock'
import { fetchPromotions } from '@/services/catalogService'
import { paginateRows, useClientTableState } from '@/hooks/useClientTableState'
import { cn, formatCurrencyMx } from '@/lib/utils'
import type { Promotion } from '@/types/catalog'

function isActiveToday(promo: Promotion, today: string): boolean {
  if (!promo.is_active) return false
  const from = promo.valid_from.slice(0, 10)
  const to = promo.valid_until?.slice(0, 10)
  if (from > today) return false
  if (to && to < today) return false
  return true
}

function benefitLabel(promo: Promotion): string {
  if (promo.discount_type === 'percentage') {
    return `${promo.discount_value.replace(/\.00$/, '')}% de descuento`
  }
  if (promo.discount_type === 'free_months') {
    const n = promo.discount_value.replace(/\.00$/, '')
    return `${n} mes${n === '1' ? '' : 'es'} gratis`
  }
  return `${formatCurrencyMx(promo.discount_value)} de descuento`
}

/**
 * Vista solo lectura para vendedores — promociones vigentes hoy.
 */
export function SalesPromotionsPage() {
  const toast = useToast()
  const today = new Date().toISOString().slice(0, 10)
  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['sales', 'promotions'],
    queryFn: fetchPromotions,
    staleTime: 30_000,
  })

  const { page, setPage, pageSize, setPageSize } = useClientTableState()
  const [search, setSearch] = useState('')
  const {
    segment,
    setSegment,
    segmentOptions,
    isSegmentLocked,
  } = useSegmentLock()
  const [copiedId, setCopiedId] = useState<number | null>(null)

  const active = useMemo(() => {
    const list = (data ?? []).filter((p) => isActiveToday(p, today))
    const q = search.trim().toLowerCase()
    return list.filter((p) => {
      if (
        q &&
        !p.code.toLowerCase().includes(q) &&
        !p.name.toLowerCase().includes(q)
      ) {
        return false
      }
      // Sin segmento = promoción global; sigue visible en cualquier filtro.
      if (segment !== 'Todos' && p.segment_name && p.segment_name !== segment) {
        return false
      }
      return true
    })
  }, [data, today, search, segment])

  const pageRows = paginateRows(active, page, pageSize)

  const hasActiveFilters =
    search.trim() !== '' || (!isSegmentLocked && segment !== 'Todos')

  function clearFilters() {
    setSearch('')
    if (!isSegmentLocked) setSegment('Todos')
    setPage(1)
  }

  async function copyCode(promo: Promotion) {
    try {
      await navigator.clipboard.writeText(promo.code)
      setCopiedId(promo.id)
      window.setTimeout(() => setCopiedId(null), 2000)
      toast.success('Código copiado', promo.code)
    } catch (error) {
      toast.apiError(error, 'No se pudo copiar el código')
    }
  }

  if (isLoading) return <Skeleton className="h-48 w-full rounded-2xl" />
  if (isError) {
    return (
      <AppCard className="border-red-500/30 p-5">
        <p className="text-sm text-red-500">
          {error instanceof Error ? error.message : 'Error al cargar'}
        </p>
        <AppButton
          type="button"
          variant="ghost"
          className="mt-3 h-auto px-3 text-sm text-[#0066FF] hover:bg-slate-100 dark:text-[#0A84FF]"
          onClick={() => void refetch()}
        >
          Reintentar
        </AppButton>
      </AppCard>
    )
  }

  return (
    <div className="space-y-5">
      <header>
        <div className="flex items-center gap-3">
          <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-emerald-500/15 text-emerald-600">
            <Gift className="h-5 w-5" />
          </span>
          <div>
            <h1 className="font-heading text-2xl font-semibold md:text-3xl">
              Promociones vigentes
            </h1>
            <p className="text-sm text-slate-500">
              Solo lectura para ventas — copia el código y pásaselo al cliente
            </p>
          </div>
        </div>
      </header>

      <AppFilterBar
        searchValue={search}
        onSearchChange={(value) => {
          setSearch(value)
          setPage(1)
        }}
        onSearchClear={() => {
          setSearch('')
          setPage(1)
        }}
        searchPlaceholder="Buscar código o beneficio…"
        searchLabel="BUSCAR"
        filters={[
          {
            id: 'segment',
            label: 'SEGMENTO',
            value: segment,
            onValueChange: (value) => {
              if (isSegmentLocked) return
              setSegment(value)
              setPage(1)
            },
            options: segmentOptions,
            disabled: isSegmentLocked,
          },
        ]}
        hasActiveFilters={hasActiveFilters}
        onClearFilters={clearFilters}
      />

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        {pageRows.map((promo) => (
          <AppCard
            key={promo.id}
            className="rounded-3xl border-emerald-500/40 bg-emerald-50/40 p-5 dark:bg-emerald-950/20"
          >
            <div className="flex flex-wrap items-center justify-between gap-2">
              <p className="font-mono text-2xl font-bold tracking-wide tabular-nums">
                {promo.code}
              </p>
              <AppBadge
                label={promo.benefit_label || benefitLabel(promo)}
                tone={promo.promo_mode === 'extra_units' ? 'info' : 'success'}
              />
            </div>
            <p className="mt-2 text-sm font-medium">{promo.name}</p>
            {promo.promo_mode === 'extra_units' && promo.service_name ? (
              <p className="mt-1 text-xs text-slate-600 dark:text-zinc-300">
                Consumible: {promo.service_name}
              </p>
            ) : null}
            <p className="mt-1 text-xs text-slate-500">
              {promo.segment_name || 'Todos los segmentos'} · vigente hasta{' '}
              {promo.valid_until?.slice(0, 10) ?? 'sin fecha fin'}
            </p>
            <AppButton
              type="button"
              onClick={() => void copyCode(promo)}
              className={cn(
                'mt-4 w-full rounded-2xl py-3',
                copiedId === promo.id &&
                  'bg-emerald-600 hover:bg-emerald-600 dark:bg-emerald-600 dark:hover:bg-emerald-600',
              )}
            >
              {copiedId === promo.id ? (
                <>
                  <Check className="h-4 w-4" /> ¡Copiado!
                </>
              ) : (
                <>
                  <Copy className="h-4 w-4" /> Copiar código
                </>
              )}
            </AppButton>
          </AppCard>
        ))}
      </div>

      {active.length === 0 && (
        <p className="text-sm text-slate-500">No hay promociones vigentes hoy.</p>
      )}

      <PaginationBar
        page={page}
        total={active.length}
        pageSize={pageSize}
        onPageChange={setPage}
        onPageSizeChange={setPageSize}
      />
    </div>
  )
}
