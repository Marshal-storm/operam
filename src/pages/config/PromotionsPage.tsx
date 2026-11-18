import { zodResolver } from '@hookform/resolvers/zod'
import { Pause, Pencil, Play, Plus, Search, Ticket } from 'lucide-react'
import { useMemo, useState } from 'react'
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
import { useToast } from '@/context/ToastContext'
import { usePromotionsConfig } from '@/hooks/useConfigCatalog'
import { usePermissions } from '@/hooks/usePermissions'
import { useSegmentLock } from '@/hooks/useSegmentLock'
import { paginateRows, useClientTableState } from '@/hooks/useClientTableState'
import { cn, formatCurrencyMx } from '@/lib/utils'
import type { DiscountType, Promotion } from '@/types/catalog'

function benefitLabel(promo: Promotion): string {
  if (promo.tipo_descuento === 'porcentaje') {
    return `${promo.valor_descuento.replace(/\.00$/, '')}% de descuento`
  }
  if (promo.tipo_descuento === 'meses_gratis') {
    const n = promo.valor_descuento.replace(/\.00$/, '')
    return `${n} mes${n === '1' ? '' : 'es'} gratis`
  }
  return `${formatCurrencyMx(promo.valor_descuento)} de descuento fijo`
}

function appliesLabel(aplica: Promotion['aplica_a']): string {
  if (aplica === 'recurrente') return 'planes de suscripción'
  if (aplica === 'unico') return 'pagos únicos'
  return 'suscripciones y pagos únicos'
}

const formSchema = z.object({
  codigo: z.string().min(2).max(40),
  nombre: z.string().min(3).max(120),
  segmento_id: z.string().optional(),
  tipo_descuento: z.enum(['porcentaje', 'monto_fijo', 'meses_gratis']),
  valor_descuento: z.string().regex(/^\d+(\.\d{1,2})?$/),
  aplica_a: z.enum(['recurrente', 'unico', 'ambos']),
  vigente_desde: z.string().min(8),
  vigente_hasta: z.string().optional(),
  usos_maximos: z.string().optional(),
  activa: z.boolean(),
})

type FormValues = z.infer<typeof formSchema>

const inputClass =
  'mt-1 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-[#0066FF]/30 dark:border-white/[0.08] dark:bg-white/[0.05]'

/**
 * Promociones admin — filtros + colores semánticos Activa/Pausada.
 */
export function PromotionsPage() {
  const toast = useToast()
  const {
    promotions,
    segments,
    isLoading,
    isApiError,
    errorMessage,
    refetch,
    createPromotion,
    updatePromotion,
    togglePromotion,
  } = usePromotionsConfig()

  const { page, setPage, pageSize, setPageSize } = useClientTableState()
  const [search, setSearch] = useState('')
  const [estado, setEstado] = useState('Todas')
  const { canCreate, canEdit, canDelete } = usePermissions()
  const {
    segmento,
    setSegmento,
    segmentOptions,
    isSegmentLocked,
  } = useSegmentLock()
  const [modalOpen, setModalOpen] = useState(false)
  const [editing, setEditing] = useState<Promotion | null>(null)

  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: {
      codigo: '',
      nombre: '',
      segmento_id: '',
      tipo_descuento: 'porcentaje',
      valor_descuento: '10.00',
      aplica_a: 'recurrente',
      vigente_desde: new Date().toISOString().slice(0, 10),
      vigente_hasta: '',
      usos_maximos: '',
      activa: true,
    },
  })

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase()
    return promotions.filter((p) => {
      if (
        q &&
        !p.codigo.toLowerCase().includes(q) &&
        !p.nombre.toLowerCase().includes(q)
      ) {
        return false
      }
      if (estado === 'Activas' && !p.activa) return false
      if (estado === 'Pausadas' && p.activa) return false
      if (segmento !== 'Todos' && p.segmento !== segmento && p.segmento != null) {
        return false
      }
      return true
    })
  }, [promotions, search, estado, segmento])

  const pageRows = paginateRows(filtered, page, pageSize)

  function openCreate() {
    setEditing(null)
    form.reset({
      codigo: '',
      nombre: '',
      segmento_id: '',
      tipo_descuento: 'porcentaje',
      valor_descuento: '10.00',
      aplica_a: 'recurrente',
      vigente_desde: new Date().toISOString().slice(0, 10),
      vigente_hasta: '',
      usos_maximos: '',
      activa: true,
    })
    setModalOpen(true)
  }

  function openEdit(promo: Promotion) {
    setEditing(promo)
    form.reset({
      codigo: promo.codigo,
      nombre: promo.nombre,
      segmento_id: promo.segmento_id != null ? String(promo.segmento_id) : '',
      tipo_descuento: promo.tipo_descuento,
      valor_descuento: promo.valor_descuento,
      aplica_a: promo.aplica_a,
      vigente_desde: promo.vigente_desde.slice(0, 10),
      vigente_hasta: promo.vigente_hasta?.slice(0, 10) ?? '',
      usos_maximos: promo.usos_maximos != null ? String(promo.usos_maximos) : '',
      activa: promo.activa,
    })
    setModalOpen(true)
  }

  async function onSubmit(values: FormValues) {
    let valor = values.valor_descuento
    if (!valor.includes('.')) valor = `${valor}.00`
    const payload = {
      codigo: values.codigo.trim().toUpperCase(),
      nombre: values.nombre.trim(),
      segmento_id: values.segmento_id ? Number(values.segmento_id) : null,
      tipo_descuento: values.tipo_descuento as DiscountType,
      valor_descuento: valor,
      aplica_a: values.aplica_a,
      vigente_desde: values.vigente_desde,
      vigente_hasta: values.vigente_hasta || null,
      usos_maximos: values.usos_maximos ? Number(values.usos_maximos) : null,
      activa: values.activa,
    }
    try {
      if (editing) {
        await updatePromotion({ id: editing.id, data: payload })
        toast.success('Promoción actualizada', payload.codigo)
      } else {
        await createPromotion({ ...payload, usos_actuales: 0 })
        toast.success('Promoción creada', payload.codigo)
      }
      setModalOpen(false)
    } catch (error) {
      toast.apiError(error)
    }
  }

  async function onTogglePromotion(promo: Promotion) {
    try {
      const next = !promo.activa
      await togglePromotion({ id: promo.id, activa: next })
      toast.success(next ? 'Promoción activada' : 'Promoción pausada', promo.codigo)
    } catch (error) {
      toast.apiError(error)
    }
  }

  if (isLoading) return <Skeleton className="h-48 w-full rounded-2xl" />
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

  return (
    <div className="space-y-5">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="font-heading text-2xl font-semibold md:text-3xl">Promociones</h1>
          <p className="mt-1 text-sm text-slate-500">
            Administra cupones: verde = activa, ámbar = pausada
          </p>
        </div>
        {canCreate('config_promociones') && (
          <Button className="rounded-xl" onClick={openCreate}>
            <Plus className="mr-1 h-4 w-4" /> Nueva promoción
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
            placeholder="Buscar por código o nombre…"
            className="w-full rounded-2xl border border-slate-200 bg-slate-50 py-2.5 pl-10 pr-4 text-sm dark:border-white/[0.08] dark:bg-white/[0.05]"
          />
        </div>
        <div className="flex flex-wrap gap-2">
          <div className="ios-pill-track">
            {['Todas', 'Activas', 'Pausadas'].map((opt) => (
              <button
                key={opt}
                type="button"
                onClick={() => {
                  setEstado(opt)
                  setPage(1)
                }}
                className={cn(
                  'rounded-xl px-2.5 py-1 text-xs',
                  estado === opt ? 'ios-pill-active' : 'text-slate-600 dark:text-slate-300',
                )}
              >
                {opt}
              </button>
            ))}
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
      </div>

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        {pageRows.map((promo) => {
          const max = promo.usos_maximos
          const used = promo.usos_actuales
          const pct =
            max != null && max > 0 ? Math.min(100, Math.round((used / max) * 100)) : null

          return (
            <article
              key={promo.id}
              className={cn(
                'relative overflow-hidden rounded-3xl border p-5',
                promo.activa
                  ? 'border-emerald-500/40 bg-emerald-50/40 dark:bg-emerald-950/20'
                  : 'border-amber-500/50 bg-amber-50/50 dark:bg-amber-950/25',
              )}
            >
              <div className="flex items-start gap-3">
                <span
                  className={cn(
                    'flex h-10 w-10 items-center justify-center rounded-2xl',
                    promo.activa
                      ? 'bg-emerald-500/15 text-emerald-600'
                      : 'bg-amber-500/15 text-amber-600',
                  )}
                >
                  <Ticket className="h-5 w-5" />
                </span>
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <p className="font-mono text-xl font-bold tracking-wide tabular-nums">
                      {promo.codigo}
                    </p>
                    <span
                      className={cn(
                        'rounded-full px-2 py-0.5 text-[11px] font-semibold',
                        promo.activa
                          ? 'bg-emerald-500/20 text-emerald-700 dark:text-emerald-300'
                          : 'bg-amber-500/25 text-amber-800 dark:text-amber-200',
                      )}
                    >
                      ● {promo.activa ? 'Activa' : 'Pausada'}
                    </span>
                  </div>
                  <p className="mt-1 text-sm font-medium">
                    {benefitLabel(promo)} en {appliesLabel(promo.aplica_a)}
                  </p>
                  <p className="mt-1 text-xs text-slate-500">{promo.nombre}</p>
                </div>
              </div>

              <div className="mt-4 space-y-1">
                <div className="flex justify-between text-xs text-slate-500">
                  <span>Usos</span>
                  <span className="font-mono tabular-nums">
                    {used}
                    {max != null ? ` / ${max}` : ' · sin límite'}
                  </span>
                </div>
                <div className="h-2 overflow-hidden rounded-full bg-black/5 dark:bg-white/10">
                  <div
                    className={cn(
                      'h-full rounded-full',
                      promo.activa ? 'bg-emerald-500' : 'bg-amber-500',
                    )}
                    style={{ width: `${pct ?? Math.min(100, used * 2)}%` }}
                  />
                </div>
              </div>

              {/* Botones siempre vivos — nunca disabled por estado pausado */}
              <div className="mt-4 flex gap-2">
                {canEdit('config_promociones') && (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="flex-1 rounded-xl border-slate-300 bg-white font-medium text-slate-800 hover:bg-slate-50 dark:border-white/20 dark:bg-white/10 dark:text-white dark:hover:bg-white/15"
                  onClick={() => openEdit(promo)}
                >
                  <Pencil className="mr-1 h-3.5 w-3.5" /> Editar
                </Button>
                )}
                {(canEdit('config_promociones') || canDelete('config_promociones')) && (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="flex-1 rounded-xl border-slate-300 bg-white font-medium text-slate-800 hover:bg-slate-50 dark:border-white/20 dark:bg-white/10 dark:text-white dark:hover:bg-white/15"
                  onClick={() => void onTogglePromotion(promo)}
                >
                  {promo.activa ? (
                    <>
                      <Pause className="mr-1 h-3.5 w-3.5" /> Pausar
                    </>
                  ) : (
                    <>
                      <Play className="mr-1 h-3.5 w-3.5" /> Reactivar
                    </>
                  )}
                </Button>
                )}
              </div>
            </article>
          )
        })}
      </div>

      <PaginationBar
        page={page}
        total={filtered.length}
        pageSize={pageSize}
        onPageChange={setPage}
        onPageSizeChange={setPageSize}
      />

      <Dialog open={modalOpen} onOpenChange={setModalOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{editing ? 'Editar promoción' : 'Nueva promoción'}</DialogTitle>
            <DialogDescription>Código, beneficio y vigencia.</DialogDescription>
          </DialogHeader>
          <form className="space-y-3" onSubmit={form.handleSubmit(onSubmit)}>
            <label className="block text-sm">
              Código
              <input className={cn(inputClass, 'font-mono uppercase')} {...form.register('codigo')} />
            </label>
            <label className="block text-sm">
              Descripción
              <input className={inputClass} {...form.register('nombre')} />
            </label>
            <div className="grid grid-cols-2 gap-3">
              <label className="block text-sm">
                Tipo
                <select className={inputClass} {...form.register('tipo_descuento')}>
                  <option value="porcentaje">Porcentaje</option>
                  <option value="monto_fijo">Monto fijo</option>
                  <option value="meses_gratis">Meses gratis</option>
                </select>
              </label>
              <label className="block text-sm">
                Valor
                <input className={cn(inputClass, 'font-mono')} {...form.register('valor_descuento')} />
              </label>
            </div>
            <label className="block text-sm">
              Aplica a
              <select className={inputClass} {...form.register('aplica_a')}>
                <option value="recurrente">Suscripciones</option>
                <option value="unico">Pagos únicos</option>
                <option value="ambos">Ambos</option>
              </select>
            </label>
            <label className="block text-sm">
              Segmento
              <select className={inputClass} {...form.register('segmento_id')}>
                <option value="">Todos</option>
                {segments.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.nombre}
                  </option>
                ))}
              </select>
            </label>
            <div className="grid grid-cols-2 gap-3">
              <label className="block text-sm">
                Desde
                <input type="date" className={inputClass} {...form.register('vigente_desde')} />
              </label>
              <label className="block text-sm">
                Hasta
                <input type="date" className={inputClass} {...form.register('vigente_hasta')} />
              </label>
            </div>
            <label className="block text-sm">
              Usos máximos
              <input className={inputClass} {...form.register('usos_maximos')} />
            </label>
            <label className="flex items-center gap-2 text-sm">
              <input type="checkbox" {...form.register('activa')} /> Activa
            </label>
            <Button type="submit" className="w-full rounded-xl">
              {editing ? 'Guardar' : 'Crear'}
            </Button>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  )
}
