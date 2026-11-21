import { BellRing, Check, LayoutList, Monitor, Moon, Rows3, Sun } from 'lucide-react'
import { useRef, type ComponentType } from 'react'
import {
  PAGE_SIZE_OPTIONS,
  TOAST_POSITION_LABELS,
  useScreenPreferences,
  type PageSizeOption,
  type TableDensity,
  type ToastPosition,
} from '@/context/ScreenPreferencesContext'
import { useTheme, type ThemePreference } from '@/context/ThemeContext'
import { useToast, type ToastVariant } from '@/context/ToastContext'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard } from '@/components/ui/AppCard'
import { cn } from '@/lib/utils'

/** Opciones visuales del selector de tema (Sistema / Claro / Oscuro OLED). */
const THEME_OPTIONS: ReadonlyArray<{
  value: ThemePreference
  title: string
  hint: string
  icon: ComponentType<{ className?: string }>
}> = [
  {
    value: 'system',
    title: 'Automático (Sistema)',
    hint: 'Sigue la preferencia de color del sistema operativo',
    icon: Monitor,
  },
  {
    value: 'light',
    title: 'Claro',
    hint: 'Tema Crisp Light siempre activo',
    icon: Sun,
  },
  {
    value: 'dark',
    title: 'Oscuro OLED',
    hint: 'True Dark OLED neutro, sin tintes azulados',
    icon: Moon,
  },
]

const THEME_TOAST_COPY: Record<ThemePreference, { title: string; description: string }> = {
  system: {
    title: 'Tema automático',
    description: 'Operam seguirá la apariencia del sistema.',
  },
  light: {
    title: 'Tema claro',
    description: 'Se aplicó el tema Crisp Light.',
  },
  dark: {
    title: 'Tema Oscuro OLED',
    description: 'Se aplicó el True Dark OLED neutro.',
  },
}

/** Orden visual de la mini-pantalla 3×2 (arriba → abajo). */
const TOAST_GRID: ToastPosition[] = [
  'top-left',
  'top-center',
  'top-right',
  'bottom-left',
  'bottom-center',
  'bottom-right',
]

/** Secuencia de demo para probar la baraja apilada (clics sucesivos). */
const PREVIEW_TOASTS: ReadonlyArray<{
  variant: ToastVariant
  title: string
  description: string
}> = [
  {
    variant: 'success',
    title: 'Operación exitosa',
    description: 'El cristal Liquid Glass quedó anclado en la posición elegida.',
  },
  {
    variant: 'info',
    title: 'Dato actualizado',
    description: 'Pasa el cursor sobre la pila para expandir y leer cada tarjeta.',
  },
  {
    variant: 'warning',
    title: 'Revisa este detalle',
    description: 'Hasta 3 toasts se ven apilados; el resto queda detrás.',
  },
  {
    variant: 'error',
    title: 'Algo no cuadra',
    description: 'Cierra individualmente o espera el auto-cierre al salir del hover.',
  },
]

/**
 * Preferencias de pantalla — tema, pageSize, densidad y posición de toasts.
 * Parámetros comerciales viven en `/configuracion/empresa`.
 */
export function ScreenPreferencesPage() {
  const {
    pageSize,
    density,
    toastPosition,
    setPageSize,
    setDensity,
    setToastPosition,
  } = useScreenPreferences()
  const { preference, setPreference } = useTheme()
  const toast = useToast()
  const previewSeqRef = useRef(0)

  function saveTheme(next: ThemePreference) {
    setPreference(next)
    const copy = THEME_TOAST_COPY[next]
    toast.success(copy.title, copy.description)
  }

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

  function saveToastPosition(position: ToastPosition) {
    setToastPosition(position)
    toast.success(
      'Posición de notificaciones',
      TOAST_POSITION_LABELS[position],
    )
  }

  /** Rota success → info → warning → error para demostrar el apilamiento. */
  function previewToast() {
    const sample = PREVIEW_TOASTS[previewSeqRef.current % PREVIEW_TOASTS.length]
    previewSeqRef.current += 1
    toast[sample.variant](sample.title, sample.description)
  }

  return (
    <div className="space-y-6">
      <header>
        <h1 className="font-heading text-2xl font-semibold md:text-3xl">
          Preferencias de pantalla
        </h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
          Define el tema visual, cuántos registros ves por página, la densidad de tablas y dónde
          aparecen las notificaciones. Se guarda en este navegador.
        </p>
      </header>

      <AppCard className="space-y-4 p-5 md:p-6">
        <div className="flex items-start gap-3">
          <span className="flex h-10 w-10 items-center justify-center rounded-2xl bg-[#0066FF]/10 text-[#0066FF] dark:bg-[#0A84FF]/15 dark:text-[#0A84FF]">
            <Sun className="h-5 w-5" />
          </span>
          <div>
            <h2 className="font-heading text-base font-semibold">Apariencia y tema</h2>
            <p className="text-xs text-slate-500">
              Elige Automático, Claro u Oscuro OLED. El cambio se aplica al instante.
            </p>
          </div>
        </div>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          {THEME_OPTIONS.map((opt) => {
            const Icon = opt.icon
            const selected = preference === opt.value
            return (
              <AppButton
                key={opt.value}
                type="button"
                variant="ghost"
                onClick={() => saveTheme(opt.value)}
                className={cn(
                  'relative h-auto flex-col items-start gap-0 rounded-2xl border p-4 text-left font-normal',
                  selected
                    ? 'border-[#0066FF]/40 bg-blue-50 hover:bg-blue-50 dark:border-[#0A84FF]/40 dark:bg-[#0A84FF]/15 dark:hover:bg-[#0A84FF]/15'
                    : 'border-slate-200/80 hover:bg-slate-50 dark:border-white/[0.09] dark:hover:bg-white/[0.04]',
                )}
              >
                {selected && (
                  <Check className="absolute right-2 top-2 h-3.5 w-3.5 text-[#0066FF] dark:text-[#0A84FF]" />
                )}
                <span
                  className={cn(
                    'mb-3 flex h-10 w-10 items-center justify-center rounded-xl',
                    selected
                      ? 'bg-[#0066FF]/15 text-[#0066FF] dark:bg-[#0A84FF]/20 dark:text-[#0A84FF]'
                      : 'bg-slate-100 text-slate-600 dark:bg-white/[0.06] dark:text-zinc-300',
                  )}
                >
                  <Icon className="h-5 w-5" />
                </span>
                <p className="font-heading font-semibold">{opt.title}</p>
                <p className="mt-1 text-xs text-slate-500">{opt.hint}</p>
              </AppButton>
            )
          })}
        </div>
      </AppCard>

      <AppCard className="space-y-4 p-5 md:p-6">
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
            <AppButton
              key={n}
              type="button"
              variant="ghost"
              onClick={() => savePageSize(n as PageSizeOption)}
              className={cn(
                'relative h-auto min-w-[4.5rem] flex-col gap-0 rounded-2xl border px-4 py-3 text-center font-normal',
                pageSize === n
                  ? 'border-[#0066FF]/40 bg-blue-50 hover:bg-blue-50 dark:border-[#0A84FF]/40 dark:bg-[#0A84FF]/15 dark:hover:bg-[#0A84FF]/15'
                  : 'border-slate-200/80 bg-white hover:bg-slate-50 dark:border-white/[0.09] dark:bg-white/[0.03] dark:hover:bg-white/[0.05]',
              )}
            >
              {pageSize === n && (
                <Check className="absolute right-2 top-2 h-3.5 w-3.5 text-[#0066FF] dark:text-[#0A84FF]" />
              )}
              <p className="font-mono text-xl font-bold tabular-nums">{n}</p>
              <p className="text-[11px] text-slate-500">filas</p>
            </AppButton>
          ))}
        </div>
      </AppCard>

      <AppCard className="space-y-4 p-5 md:p-6">
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
            <AppButton
              key={opt.value}
              type="button"
              variant="ghost"
              onClick={() => saveDensity(opt.value)}
              className={cn(
                'h-auto flex-col items-start gap-0 rounded-2xl border p-4 text-left font-normal',
                density === opt.value
                  ? 'border-[#0066FF]/40 bg-blue-50 hover:bg-blue-50 dark:border-[#0A84FF]/40 dark:bg-[#0A84FF]/15 dark:hover:bg-[#0A84FF]/15'
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
            </AppButton>
          ))}
        </div>
      </AppCard>

      <AppCard className="space-y-4 p-5 md:p-6">
        <div className="flex items-start gap-3">
          <span className="flex h-10 w-10 items-center justify-center rounded-2xl bg-[#0066FF]/10 text-[#0066FF] dark:bg-[#0A84FF]/15 dark:text-[#0A84FF]">
            <BellRing className="h-5 w-5" />
          </span>
          <div>
            <h2 className="font-heading text-base font-semibold">
              Posición de notificaciones (Toast)
            </h2>
            <p className="text-xs text-slate-500">
              Elige dónde cae el cristal líquido. La selección se guarda al instante en este
              navegador.
            </p>
          </div>
        </div>

        <div className="space-y-2">
          <label
            htmlFor="toast-position-grid"
            className="block text-sm font-medium text-slate-700 dark:text-slate-200"
          >
            Ancla en pantalla
          </label>

          {/* Mini-pantalla 3×2 interactiva */}
          <div
            id="toast-position-grid"
            role="radiogroup"
            aria-label="Posición de notificaciones Toast"
            className="mx-auto grid w-full max-w-sm grid-cols-3 gap-2 rounded-[22px] border border-slate-200/80 bg-slate-50/80 p-3 dark:border-white/[0.09] dark:bg-white/[0.03]"
          >
            {TOAST_GRID.map((position) => {
              const selected = toastPosition === position
              return (
                <AppButton
                  key={position}
                  type="button"
                  role="radio"
                  aria-checked={selected}
                  aria-label={TOAST_POSITION_LABELS[position]}
                  variant="ghost"
                  onClick={() => saveToastPosition(position)}
                  className={cn(
                    'h-14 rounded-xl border font-normal transition-all',
                    selected
                      ? 'border-[#0066FF]/50 bg-[#0066FF] text-white shadow-[0_0_18px_rgba(0,102,255,0.35)] hover:bg-[#0066FF] dark:border-[#0A84FF]/50 dark:bg-[#0A84FF] dark:shadow-[0_0_20px_rgba(10,132,255,0.4)] dark:hover:bg-[#0A84FF]'
                      : 'border-slate-200/90 bg-white/90 text-slate-500 hover:border-[#0066FF]/30 hover:bg-white dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-zinc-400 dark:hover:border-[#0A84FF]/35 dark:hover:bg-white/[0.07]',
                  )}
                >
                  <span
                    className={cn(
                      'block h-2.5 w-2.5 rounded-full',
                      selected
                        ? 'bg-white'
                        : 'bg-slate-300 dark:bg-white/25',
                    )}
                  />
                </AppButton>
              )
            })}
          </div>

          <p className="text-center text-xs text-slate-500 dark:text-zinc-400">
            Seleccionada: <span className="font-medium text-slate-700 dark:text-zinc-200">{TOAST_POSITION_LABELS[toastPosition]}</span>
          </p>
        </div>

        <div className="flex justify-center pt-1">
          <AppButton type="button" variant="secondary" onClick={previewToast}>
            Probar notificación
          </AppButton>
        </div>
      </AppCard>
    </div>
  )
}
