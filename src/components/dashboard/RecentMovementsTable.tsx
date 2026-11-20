import { Link } from 'react-router-dom'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppCard } from '@/components/ui/AppCard'
import { AppTableWrapper } from '@/components/ui/AppTableWrapper'
import { formatCurrencyMx, formatDateEsMx, cn } from '@/lib/utils'
import type { MrrCategory, MrrMovement } from '@/types/mrr'

type RecentMovementsTableProps = {
  movements: MrrMovement[]
  activeCategory: MrrCategory | null
  title?: string
}

function DeltaAmount({ value }: { value: string }) {
  const negative = value.startsWith('-')
  const abs = negative ? value.slice(1) : value

  return (
    <AppBadge
      label={`${negative ? '-' : '+'}${formatCurrencyMx(abs)}`}
      tone={negative ? 'danger' : 'success'}
    />
  )
}

function ClientAvatar({ name }: { name: string }) {
  const initial = name.replace(/[\[\]]/g, '').trim().charAt(0).toUpperCase() || '?'
  return (
    <span className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-[#0A84FF] to-[#6366F1] text-xs font-bold text-white shadow-[0_0_12px_rgba(10,132,255,0.35)]">
      {initial}
    </span>
  )
}

/**
 * Movimientos recientes — tabla glass + tarjetas móvil (UI-4).
 */
export function RecentMovementsTable({
  movements,
  activeCategory,
  title = 'Movimientos recientes',
}: RecentMovementsTableProps) {
  const categoryLabels: Partial<Record<MrrCategory, string>> = {
    new: 'Nuevo',
    expansion: 'Expansión',
    price_adjustment: 'Ajuste de precio',
    frequency_change: 'Cambio de frecuencia',
    contraction: 'Contracción',
    churn: 'Churn',
    reactivation: 'Reactivación',
  }

  const heading =
    activeCategory && activeCategory !== 'start' && activeCategory !== 'end'
      ? `Movimientos · ${categoryLabels[activeCategory] ?? activeCategory}`
      : title

  return (
    <AppCard className="p-4 md:p-5">
      <header className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-base font-semibold capitalize md:text-lg">{heading}</h2>
        <Link
          to="/bitacora"
          className="text-sm font-medium text-[#0066FF] hover:underline dark:text-[#0A84FF]"
        >
          Ver bitácora completa
        </Link>
      </header>

      <div className="space-y-3 md:hidden">
        {movements.map((movement) => (
          <article
            key={movement.id}
            className="rounded-2xl border border-slate-200/80 bg-slate-50/80 p-3 dark:border-white/[0.09] dark:bg-white/[0.03]"
          >
            <div className="flex items-start justify-between gap-3">
              <div className="flex gap-3">
                <ClientAvatar name={movement.client_name} />
                <div>
                  <p className="text-sm font-medium">
                    {formatDateEsMx(movement.effective_date)} ·{' '}
                    <span className="font-mono tabular-nums">{movement.contract_folio}</span>
                  </p>
                  <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    {movement.client_name} · {movement.segment}
                  </p>
                  <p className="mt-1 text-sm">{movement.description}</p>
                  <div className="mt-2">
                    <AppBadge
                      label={categoryLabels[movement.mrr_category] ?? movement.mrr_category}
                      tone="info"
                    />
                  </div>
                </div>
              </div>
              <DeltaAmount value={movement.mrr_delta_mxn} />
            </div>
          </article>
        ))}
      </div>

      <div className="hidden md:block">
        <AppTableWrapper>
          <table className="w-full table-fixed text-left text-sm">
            <thead>
              <tr className="bg-slate-100/90 text-slate-500 dark:bg-white/[0.05] dark:text-zinc-400">
                <th className="w-[12%] px-3 py-3 font-medium">Fecha</th>
                <th className="w-[12%] px-3 py-3 font-medium">Contrato</th>
                <th className="w-[22%] px-3 py-3 font-medium">Cliente</th>
                <th className="w-[12%] px-3 py-3 font-medium">Segmento</th>
                <th className="w-[26%] px-3 py-3 font-medium">Movimiento</th>
                <th className="w-[16%] px-3 py-3 text-right font-medium">Delta MRR</th>
              </tr>
            </thead>
            <tbody>
              {movements.map((movement) => (
                <tr
                  key={movement.id}
                  className="border-t border-slate-200/70 dark:border-white/[0.06]"
                >
                  <td className="px-3 py-3 text-slate-600 dark:text-slate-300">
                    {formatDateEsMx(movement.effective_date)}
                  </td>
                  <td className="px-3 py-3 font-mono text-sm font-medium tabular-nums">
                    {movement.contract_folio}
                  </td>
                  <td className="px-3 py-3">
                    <div className="flex items-center gap-2">
                      <ClientAvatar name={movement.client_name} />
                      <span className="truncate">{movement.client_name}</span>
                    </div>
                  </td>
                  <td className="px-3 py-3">
                    <AppBadge label={movement.segment} tone="neutral" />
                  </td>
                  <td className="px-3 py-3 text-slate-600 dark:text-slate-300">
                    {movement.description}
                  </td>
                  <td className={cn('px-3 py-3 text-right')}>
                    <DeltaAmount value={movement.mrr_delta_mxn} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </AppTableWrapper>
      </div>

      {movements.length === 0 && (
        <p className="text-sm text-slate-500 dark:text-slate-400">
          No hay movimientos para mostrar con el filtro actual.
        </p>
      )}
    </AppCard>
  )
}
