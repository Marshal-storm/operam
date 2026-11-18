import { AlertCircle, AlertTriangle, CheckCircle2, Info, X } from 'lucide-react'
import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import { cn } from '@/lib/utils'

export type ToastVariant = 'success' | 'error' | 'warning' | 'info'

export type ToastInput = {
  title: string
  description?: string
  variant?: ToastVariant
  durationMs?: number
}

type ToastItem = {
  id: string
  title: string
  description?: string
  variant: ToastVariant
  exiting?: boolean
}

type ToastApi = {
  success: (title: string, description?: string) => void
  error: (title: string, description?: string) => void
  warning: (title: string, description?: string) => void
  info: (title: string, description?: string) => void
  /** Dispara error estándar de API fallida. */
  apiError: (error: unknown, fallbackTitle?: string) => void
  dismiss: (id: string) => void
}

type ToastContextValue = ToastApi

const ToastContext = createContext<ToastContextValue | null>(null)

const AUTO_CLOSE_MS = 4000
const EXIT_MS = 220

const VARIANT_STYLES: Record<
  ToastVariant,
  { panel: string; icon: string; Icon: typeof CheckCircle2 }
> = {
  success: {
    panel: 'border-emerald-500/40 bg-white/95 dark:bg-[#111318]/95',
    icon: 'text-[#10B981]',
    Icon: CheckCircle2,
  },
  error: {
    panel: 'border-red-500/40 bg-white/95 dark:bg-[#111318]/95',
    icon: 'text-[#EF4444]',
    Icon: AlertCircle,
  },
  warning: {
    panel: 'border-amber-500/40 bg-white/95 dark:bg-[#111318]/95',
    icon: 'text-[#F59E0B]',
    Icon: AlertTriangle,
  },
  info: {
    panel: 'border-[#0A84FF]/40 bg-white/95 dark:bg-[#111318]/95',
    icon: 'text-[#0A84FF]',
    Icon: Info,
  },
}

let toastIdSeq = 0

/**
 * Toasts globales iOS Liquid Glass — esquina superior derecha, auto-cierre 4s.
 */
export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<ToastItem[]>([])
  const timersRef = useRef<Map<string, ReturnType<typeof setTimeout>>>(new Map())

  const clearTimers = useCallback((id: string) => {
    const t = timersRef.current.get(id)
    if (t) {
      clearTimeout(t)
      timersRef.current.delete(id)
    }
  }, [])

  const dismiss = useCallback(
    (id: string) => {
      clearTimers(id)
      setItems((prev) => prev.map((t) => (t.id === id ? { ...t, exiting: true } : t)))
      const exitTimer = setTimeout(() => {
        setItems((prev) => prev.filter((t) => t.id !== id))
        timersRef.current.delete(id)
      }, EXIT_MS)
      timersRef.current.set(id, exitTimer)
    },
    [clearTimers],
  )

  const push = useCallback(
    (input: ToastInput) => {
      const id = `toast-${++toastIdSeq}`
      const variant = input.variant ?? 'info'
      const duration = input.durationMs ?? AUTO_CLOSE_MS
      setItems((prev) => [
        ...prev,
        {
          id,
          title: input.title,
          description: input.description,
          variant,
        },
      ])
      const auto = setTimeout(() => dismiss(id), duration)
      timersRef.current.set(id, auto)
    },
    [dismiss],
  )

  const api = useMemo<ToastApi>(
    () => ({
      success: (title, description) => push({ title, description, variant: 'success' }),
      error: (title, description) => push({ title, description, variant: 'error' }),
      warning: (title, description) => push({ title, description, variant: 'warning' }),
      info: (title, description) => push({ title, description, variant: 'info' }),
      apiError: (error, fallbackTitle = 'No se pudo completar la operación') => {
        const message =
          error instanceof Error && error.message.trim()
            ? error.message
            : 'Error inesperado en la API'
        push({ title: fallbackTitle, description: message, variant: 'error' })
      },
      dismiss,
    }),
    [dismiss, push],
  )

  return (
    <ToastContext.Provider value={api}>
      {children}
      <div
        className="pointer-events-none fixed top-16 right-5 z-[110] flex w-[min(22rem,calc(100vw-2.5rem))] flex-col gap-2"
        aria-live="polite"
        aria-relevant="additions"
      >
        {items.map((toast) => {
          const meta = VARIANT_STYLES[toast.variant]
          const Icon = meta.Icon
          return (
            <div
              key={toast.id}
              role="status"
              className={cn(
                'pointer-events-auto flex items-start gap-3 rounded-2xl border p-3.5 shadow-[0_16px_40px_rgba(0,0,0,0.18)] backdrop-blur-2xl transition-all duration-200',
                'dark:shadow-[inset_0_1px_0_0_rgba(255,255,255,0.1),0_16px_40px_rgba(0,0,0,0.55)]',
                meta.panel,
                toast.exiting ? 'translate-x-4 opacity-0' : 'translate-x-0 opacity-100',
              )}
            >
              <Icon className={cn('mt-0.5 h-5 w-5 shrink-0', meta.icon)} />
              <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold text-[#090D16] dark:text-white">
                  {toast.title}
                </p>
                {toast.description ? (
                  <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                    {toast.description}
                  </p>
                ) : null}
              </div>
              <button
                type="button"
                aria-label="Cerrar notificación"
                onClick={() => dismiss(toast.id)}
                className="rounded-lg p-1 text-slate-400 transition hover:bg-black/5 hover:text-slate-700 dark:hover:bg-white/10 dark:hover:text-white"
              >
                <X className="h-4 w-4" />
              </button>
            </div>
          )
        })}
      </div>
    </ToastContext.Provider>
  )
}

export function useToast(): ToastApi {
  const ctx = useContext(ToastContext)
  if (!ctx) {
    throw new Error('useToast debe usarse dentro de ToastProvider')
  }
  return ctx
}
