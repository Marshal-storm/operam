import { Check, LayoutList, Rows3 } from 'lucide-react'
import {
  PAGE_SIZE_OPTIONS,
  useScreenPreferences,
  type PageSizeOption,
  type TableDensity,
} from '@/context/ScreenPreferencesContext'
import { useToast } from '@/context/ToastContext'
import { cn } from '@/lib/utils'

/**
 * Preferencias de pantalla — pageSize y densidad para todas las tablas del ERP.
 */
export function ScreenPreferencesPage() {
  const { pageSize, density, setPageSize, setDensity } = useScreenPreferences()
  const toast = useToast()

  function savePageSize(n: PageSizeOption) {
    setPageSize(n)
    toast.success('Preferencia guardada', `${n} filas por página.`)
  }

  function saveDensity(value: TableDensity) {
    setDensity(value)
    toast.success(
      'Densidad actualizada',
      value === 'compact' ? 'Tablas compactas.' : 'Tablas cómodas.',
    )
  }

  return (
    <div className="space-y-6">
      <header>
        <h1 className="font-heading text-2xl font-semibold md:text-3xl">
          Preferencias de pantalla
        </h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
          Define cuántos registros ves por página y qué tan compactas lucen las tablas. Se guarda
          en este navegador.
        </p>
      </header>

      <section className="glass-panel space-y-4 p-5 md:p-6">
        <div className="flex items-start gap-3">
          <span className="flex h-10 w-10 items-center justify-center rounded-2xl bg-[#0066FF]/10 text-[#0066FF] dark:bg-[#0A84FF]/15 dark:text-[#0A84FF]">
            <LayoutList className="h-5 w-5" />
          </span>
          <div>
            <h2 className="font-heading text-base font-semibold">Resultados por página</h2>
            <p className="text-xs text-slate-500">
              Valor por defecto para Catálogo, Instancias, Listas y demás tablas paginadas.
            </p>
          </div>
        </div>

        <div className="flex flex-wrap gap-2">
          {PAGE_SIZE_OPTIONS.map((n) => (
            <button
              key={n}
              type="button"
              onClick={() => savePageSize(n as PageSizeOption)}
              className={cn(
                'relative min-w-[4.5rem] rounded-2xl border px-4 py-3 text-center transition',
                pageSize === n
                  ? 'border-[#0066FF]/40 bg-blue-50 dark:border-[#0A84FF]/40 dark:bg-[#0A84FF]/15'
                  : 'border-slate-200/80 bg-white hover:bg-slate-50 dark:border-white/[0.09] dark:bg-white/[0.03]',
              )}
            >
              {pageSize === n && (
                <Check className="absolute right-2 top-2 h-3.5 w-3.5 text-[#0066FF] dark:text-[#0A84FF]" />
              )}
              <p className="font-mono text-xl font-bold tabular-nums">{n}</p>
              <p className="text-[11px] text-slate-500">filas</p>
            </button>
          ))}
        </div>
      </section>

      <section className="glass-panel space-y-4 p-5 md:p-6">
        <div className="flex items-start gap-3">
          <span className="flex h-10 w-10 items-center justify-center rounded-2xl bg-slate-100 text-slate-600 dark:bg-white/[0.06] dark:text-slate-300">
            <Rows3 className="h-5 w-5" />
          </span>
          <div>
            <h2 className="font-heading text-base font-semibold">Densidad de tablas</h2>
            <p className="text-xs text-slate-500">
              Cómoda para lectura diaria; Compacta cuando trabajas con muchos registros.
            </p>
          </div>
        </div>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          {(
            [
              {
                value: 'comfortable' as TableDensity,
                title: 'Cómoda',
                hint: 'Más aire entre filas, ideal en pantallas grandes',
              },
              {
                value: 'compact' as TableDensity,
                title: 'Compacta',
                hint: 'Más filas visibles sin hacer scroll',
              },
            ] as const
          ).map((opt) => (
            <button
              key={opt.value}
              type="button"
              onClick={() => saveDensity(opt.value)}
              className={cn(
                'rounded-2xl border p-4 text-left transition',
                density === opt.value
                  ? 'border-[#0066FF]/40 bg-blue-50 dark:border-[#0A84FF]/40 dark:bg-[#0A84FF]/15'
                  : 'border-slate-200/80 hover:bg-slate-50 dark:border-white/[0.09] dark:hover:bg-white/[0.04]',
              )}
            >
              <p className="font-heading font-semibold">{opt.title}</p>
              <p className="mt-1 text-xs text-slate-500">{opt.hint}</p>
              <div className="mt-3 space-y-1">
                {[1, 2, 3].map((i) => (
                  <div
                    key={i}
                    className={cn(
                      'rounded-md bg-slate-200/80 dark:bg-white/10',
                      opt.value === 'compact' ? 'h-2' : 'h-3.5',
                    )}
                  />
                ))}
              </div>
            </button>
          ))}
        </div>
      </section>
    </div>
  )
}
