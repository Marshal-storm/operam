import { Check, Copy, Gift, Search } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { PaginationBar } from '@/components/ui/PaginationBar'
import { Skeleton } from '@/components/ui/skeleton'
import { useToast } from '@/context/ToastContext'
import { useSegmentLock } from '@/hooks/useSegmentLock'
import { fetchPromotions } from '@/services/catalogService'
import { paginateRows, useClientTableState } from '@/hooks/useClientTableState'
import { cn, formatCurrencyMx } from '@/lib/utils'
import type { Promotion } from '@/types/catalog'

function isActiveToday(promo: Promotion, today: string): boolean {
  if (!promo.activa) return false
  const from = promo.vigente_desde.slice(0, 10)
  const to = promo.vigente_hasta?.slice(0, 10)
  if (from > today) return false
  if (to && to < today) return false
  return true
}

function benefitLabel(promo: Promotion): string {
  if (promo.tipo_descuento === 'porcentaje') {
    return `${promo.valor_descuento.replace(/\.00$/, '')}% de descuento`
  }
  if (promo.tipo_descuento === 'meses_gratis') {
    const n = promo.valor_descuento.replace(/\.00$/, '')
    return `${n} mes${n === '1' ? '' : 'es'} gratis`
  }
  return `${formatCurrencyMx(promo.valor_descuento)} de descuento`
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
    segmento,
    setSegmento,
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
        !p.codigo.toLowerCase().includes(q) &&
        !p.nombre.toLowerCase().includes(q)
      ) {
        return false
      }
      if (segmento !== 'Todos' && p.segmento !== segmento && p.segmento != null) {
        return false
      }
      if (segmento !== 'Todos' && p.segmento == null) {
        // promociones globales aplican a todos
        return true
      }
      return true
    })
  }, [data, today, search, segmento])

  const pageRows = paginateRows(active, page, pageSize)

  async function copyCode(promo: Promotion) {
    try {
      await navigator.clipboard.writeText(promo.codigo)
      setCopiedId(promo.id)
      window.setTimeout(() => setCopiedId(null), 2000)
      toast.success('Código copiado', promo.codigo)
    } catch (error) {
      toast.apiError(error, 'No se pudo copiar el código')
    }
  }

  if (isLoading) return <Skeleton className="h-48 w-full rounded-2xl" />
  if (isError) {
    return (
      <section className="glass-panel border-red-500/30 p-5">
        <p className="text-sm text-red-500">
          {error instanceof Error ? error.message : 'Error al cargar'}
        </p>
        <button type="button" className="mt-3 text-sm text-[#0066FF]" onClick={() => void refetch()}>
          Reintentar
        </button>
      </section>
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

      <div className="glass-panel space-y-3 p-4">
        <div className="relative">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <input
            value={search}
            onChange={(e) => {
              setSearch(e.target.value)
              setPage(1)
            }}
            placeholder="Buscar código o beneficio…"
            className="w-full rounded-2xl border border-slate-200 bg-slate-50 py-2.5 pl-10 pr-4 text-sm dark:border-white/[0.08] dark:bg-white/[0.05]"
          />
        </div>
        <div className="ios-pill-track">
          {segmentOptions.map((opt) => (
            <button
              key={opt}
              type="button"
              disabled={isSegmentLocked}
              onClick={() => {
                if (isSegmentLocked) return
                setSegmento(opt)
                setPage(1)
              }}
              className={cn(
                'rounded-xl px-2.5 py-1 text-xs',
                segmento === opt ? 'ios-pill-active' : 'text-slate-600 dark:text-slate-300',
                isSegmentLocked && 'cursor-not-allowed opacity-80',
              )}
            >
              {opt}
            </button>
          ))}
        </div>
      </div>

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        {pageRows.map((promo) => (
          <article
            key={promo.id}
            className="rounded-3xl border border-emerald-500/40 bg-emerald-50/40 p-5 dark:bg-emerald-950/20"
          >
            <p className="font-mono text-2xl font-bold tracking-wide tabular-nums">
              {promo.codigo}
            </p>
            <p className="mt-2 text-sm font-medium">{benefitLabel(promo)}</p>
            <p className="mt-1 text-xs text-slate-500">
              {promo.segmento ?? 'Todos los segmentos'} · vigente hasta{' '}
              {promo.vigente_hasta?.slice(0, 10) ?? 'sin fecha fin'}
            </p>
            <button
              type="button"
              onClick={() => void copyCode(promo)}
              className={cn(
                'mt-4 flex w-full items-center justify-center gap-2 rounded-2xl py-3 text-sm font-semibold transition',
                copiedId === promo.id
                  ? 'bg-emerald-600 text-white'
                  : 'bg-[#0066FF] text-white hover:bg-[#0052CC] dark:bg-[#0A84FF]',
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
            </button>
          </article>
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
