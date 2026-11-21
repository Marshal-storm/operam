import { AlertCircle, AlertTriangle, CheckCircle2, Info, X } from 'lucide-react'
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import {
  useScreenPreferences,
  type ToastPosition,
} from '@/context/ScreenPreferencesContext'
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
  durationMs: number
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
const EXIT_MS = 280
const MAX_COLLAPSED_VISIBLE = 3
const PEEK_PX = 14
const EXPANDED_GAP_PX = 12
const FALLBACK_HEIGHT = 72
const STACK_EASE = 'transform 320ms cubic-bezier(0.22, 1, 0.36, 1), opacity 250ms ease'

/**
 * Skin semántica Liquid Glass: orbe/halo (no borde plano de caja).
 * Colores alineados a acentos iOS Operam.
 */
const VARIANT_META: Record<
  ToastVariant,
  {
    Icon: typeof CheckCircle2
    orb: string
    progress: string
    ambient: string
  }
> = {
  success: {
    Icon: CheckCircle2,
    orb: 'bg-emerald-500/15 dark:bg-emerald-400/15 border border-emerald-500/30 text-emerald-600 dark:text-emerald-400 shadow-[0_0_16px_rgba(16,185,129,0.25)]',
    progress: 'bg-emerald-500/55 dark:bg-emerald-400/50',
    ambient:
      'bg-[radial-gradient(ellipse_at_left_center,rgba(16,185,129,0.18)_0%,transparent_65%)] dark:bg-[radial-gradient(ellipse_at_left_center,rgba(52,211,153,0.16)_0%,transparent_65%)]',
  },
  error: {
    Icon: AlertCircle,
    orb: 'bg-red-500/15 dark:bg-red-400/15 border border-red-500/30 text-red-600 dark:text-red-400 shadow-[0_0_16px_rgba(239,68,68,0.25)]',
    progress: 'bg-red-500/55 dark:bg-red-400/50',
    ambient:
      'bg-[radial-gradient(ellipse_at_left_center,rgba(239,68,68,0.18)_0%,transparent_65%)] dark:bg-[radial-gradient(ellipse_at_left_center,rgba(248,113,113,0.16)_0%,transparent_65%)]',
  },
  warning: {
    Icon: AlertTriangle,
    orb: 'bg-amber-500/15 dark:bg-amber-400/15 border border-amber-500/30 text-amber-600 dark:text-amber-400 shadow-[0_0_16px_rgba(245,158,11,0.25)]',
    progress: 'bg-amber-500/55 dark:bg-amber-400/50',
    ambient:
      'bg-[radial-gradient(ellipse_at_left_center,rgba(245,158,11,0.18)_0%,transparent_65%)] dark:bg-[radial-gradient(ellipse_at_left_center,rgba(251,191,36,0.16)_0%,transparent_65%)]',
  },
  info: {
    Icon: Info,
    orb: 'bg-blue-500/15 dark:bg-blue-400/15 border border-blue-500/30 text-blue-600 dark:text-blue-400 shadow-[0_0_16px_rgba(10,132,255,0.25)]',
    progress: 'bg-[#0A84FF]/55 dark:bg-[#0A84FF]/50',
    ambient:
      'bg-[radial-gradient(ellipse_at_left_center,rgba(10,132,255,0.16)_0%,transparent_65%)] dark:bg-[radial-gradient(ellipse_at_left_center,rgba(10,132,255,0.18)_0%,transparent_65%)]',
  },
}

/** Contenedor de cristal — Crisp Light + Dark OLED (sin fondos opacos). */
const GLASS_SHELL = cn(
  'relative overflow-hidden rounded-[22px]',
  'backdrop-blur-2xl backdrop-saturate-150',
  'bg-white/65',
  'bg-[linear-gradient(135deg,rgba(255,255,255,0.82)_0%,rgba(255,255,255,0.52)_100%)]',
  'border border-white/80 ring-1 ring-slate-900/[0.06]',
  'shadow-[inset_0_1px_1px_rgba(255,255,255,0.95),0_18px_40px_-12px_rgba(15,23,42,0.18)]',
  'dark:bg-[linear-gradient(135deg,rgba(36,36,42,0.72)_0%,rgba(16,16,20,0.78)_100%)]',
  'dark:border-white/[0.14] dark:ring-1 dark:ring-white/[0.04]',
  'dark:shadow-[inset_0_1px_0_0_rgba(255,255,255,0.22),inset_0_0_24px_rgba(255,255,255,0.03),0_20px_48px_-12px_rgba(0,0,0,0.85)]',
)

/** Anclas fijas del stack según preferencia de pantalla. */
const POSITION_CONTAINER: Record<ToastPosition, string> = {
  'top-right': 'top-16 right-5',
  'top-center': 'top-16 left-1/2 -translate-x-1/2',
  'top-left': 'top-16 left-5',
  'bottom-right': 'bottom-5 right-5',
  'bottom-center': 'bottom-5 left-1/2 -translate-x-1/2',
  'bottom-left': 'bottom-5 left-5',
}

let toastIdSeq = 0

function heightOf(map: Record<string, number>, id: string): number {
  return map[id] ?? FALLBACK_HEIGHT
}

/** Offset vertical acumulado en modo expandido (columna sin solape). */
function expandedOffsetY(
  index: number,
  ordered: ToastItem[],
  heights: Record<string, number>,
  isBottom: boolean,
): number {
  let offset = 0
  for (let i = 0; i < index; i += 1) {
    offset += heightOf(heights, ordered[i].id) + EXPANDED_GAP_PX
  }
  return isBottom ? -offset : offset
}

type ToastCardProps = {
  item: ToastItem
  isBottom: boolean
  isExpanded: boolean
  stackPaused: boolean
  layoutTransform: string
  layoutOpacity: number
  zIndex: number
  pointerEvents: 'auto' | 'none'
  dimContent: boolean
  onDismiss: (id: string) => void
  onMeasure: (id: string, height: number) => void
}

/**
 * Tarjeta Liquid Glass individual dentro de la baraja.
 * Auto-cierre y barra de progreso se pausan cuando el stack está expandido.
 */
function ToastCard({
  item,
  isBottom,
  isExpanded,
  stackPaused,
  layoutTransform,
  layoutOpacity,
  zIndex,
  pointerEvents,
  dimContent,
  onDismiss,
  onMeasure,
}: ToastCardProps) {
  const meta = VARIANT_META[item.variant]
  const Icon = meta.Icon
  const rootRef = useRef<HTMLDivElement>(null)
  const remainingRef = useRef(item.durationMs)
  const startedAtRef = useRef<number | null>(null)
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const [entered, setEntered] = useState(false)

  const clearTimer = useCallback(() => {
    if (timerRef.current) {
      clearTimeout(timerRef.current)
      timerRef.current = null
    }
  }, [])

  const armTimer = useCallback(() => {
    if (item.exiting) return
    clearTimer()
    startedAtRef.current = Date.now()
    timerRef.current = setTimeout(() => onDismiss(item.id), remainingRef.current)
  }, [clearTimer, item.exiting, item.id, onDismiss])

  // Entrada visual
  useEffect(() => {
    const raf = requestAnimationFrame(() => setEntered(true))
    return () => cancelAnimationFrame(raf)
  }, [])

  // Medición de altura de layout (offsetHeight ignora scale/translate de la baraja)
  useLayoutEffect(() => {
    const node = rootRef.current
    if (!node) return
    const publish = () => onMeasure(item.id, node.offsetHeight)
    publish()
    const ro = new ResizeObserver(publish)
    ro.observe(node)
    return () => ro.disconnect()
  }, [item.id, onMeasure, item.title, item.description])

  // Pausa / reanudación sincronizada con hover del stack
  useEffect(() => {
    if (item.exiting) {
      clearTimer()
      return
    }

    if (stackPaused) {
      if (startedAtRef.current !== null) {
        const elapsed = Date.now() - startedAtRef.current
        remainingRef.current = Math.max(0, remainingRef.current - elapsed)
        startedAtRef.current = null
      }
      clearTimer()
      return
    }

    armTimer()
    return () => clearTimer()
  }, [armTimer, clearTimer, item.exiting, stackPaused])

  return (
    <div
      ref={rootRef}
      role="status"
      className={cn(
        'absolute left-0 right-0 w-full will-change-transform',
        !entered && !item.exiting && 'opacity-0',
        item.exiting && 'pointer-events-none opacity-0',
      )}
      style={{
        ...(isBottom ? { bottom: 0 } : { top: 0 }),
        zIndex,
        pointerEvents,
        transformOrigin: isBottom ? 'bottom center' : 'top center',
        opacity: item.exiting ? 0 : layoutOpacity,
        transform: item.exiting
          ? `${layoutTransform} scale(0.92)`
          : entered
            ? layoutTransform
            : `${layoutTransform} scale(0.96)`,
        transition: STACK_EASE,
      }}
    >
      <div className={GLASS_SHELL}>
        <div
          aria-hidden
          className={cn('pointer-events-none absolute inset-0 opacity-90', meta.ambient)}
        />

        <div
          className={cn(
            'relative flex items-start gap-3 px-3.5 pb-3.5 pt-3.5 transition-opacity duration-250',
            dimContent && !isExpanded ? 'opacity-0' : 'opacity-100',
          )}
        >
          <div
            className={cn(
              'flex h-9 w-9 shrink-0 items-center justify-center rounded-full',
              meta.orb,
            )}
          >
            <Icon className="h-[18px] w-[18px]" strokeWidth={2.25} aria-hidden />
          </div>

          <div className="min-w-0 flex-1 pt-0.5">
            <p className="font-heading text-sm font-semibold tracking-tight text-slate-900 dark:text-white">
              {item.title}
            </p>
            {item.description ? (
              <p className="mt-0.5 text-xs leading-relaxed text-slate-600 dark:text-zinc-300">
                {item.description}
              </p>
            ) : null}
          </div>

          <button
            type="button"
            aria-label="Cerrar notificación"
            onClick={() => onDismiss(item.id)}
            className="shrink-0 rounded-full p-1 text-slate-400 transition-colors hover:bg-black/[0.06] hover:text-slate-700 dark:hover:bg-white/[0.1] dark:hover:text-white"
          >
            <X className="h-4 w-4" />
          </button>
        </div>

        <div
          aria-hidden
          className="absolute inset-x-0 bottom-0 h-[2px] overflow-hidden rounded-b-[22px] bg-black/[0.04] dark:bg-white/[0.06]"
        >
          <div
            className={cn(
              'operam-toast-progress h-full origin-left rounded-full',
              meta.progress,
              stackPaused && 'operam-toast-progress-paused',
            )}
            style={{ animationDuration: `${item.durationMs}ms` }}
          />
        </div>
      </div>
    </div>
  )
}

type ToastStackProps = {
  items: ToastItem[]
  position: ToastPosition
  onDismiss: (id: string) => void
}

/**
 * Baraja iOS: hasta 3 tarjetas visibles colapsadas; al hover se expanden en columna
 * y se pausan todos los auto-cierres. Sin librerías externas.
 */
function ToastStack({ items, position, onDismiss }: ToastStackProps) {
  const isBottom = position.startsWith('bottom')
  const [isExpanded, setIsExpanded] = useState(false)
  const [heights, setHeights] = useState<Record<string, number>>({})

  const onMeasure = useCallback((id: string, height: number) => {
    setHeights((prev) => {
      if (prev[id] === height) return prev
      return { ...prev, [id]: height }
    })
  }, [])

  // Limpia alturas de toasts ya removidos
  useEffect(() => {
    const live = new Set(items.map((t) => t.id))
    setHeights((prev) => {
      const next: Record<string, number> = {}
      let changed = false
      for (const [id, h] of Object.entries(prev)) {
        if (live.has(id)) next[id] = h
        else changed = true
      }
      return changed ? next : prev
    })
  }, [items])

  const frontHeight = items[0] ? heightOf(heights, items[0].id) : 0

  const collapsedHeight = useMemo(() => {
    if (items.length === 0) return 0
    const peekCount = Math.min(MAX_COLLAPSED_VISIBLE, items.length) - 1
    // Altura del frontal + pestañas asomadas (área de hover estable)
    return frontHeight + Math.max(0, peekCount) * PEEK_PX
  }, [frontHeight, items.length])

  const expandedHeight = useMemo(() => {
    if (items.length === 0) return 0
    const sum = items.reduce((acc, t) => acc + heightOf(heights, t.id), 0)
    return sum + Math.max(0, items.length - 1) * EXPANDED_GAP_PX
  }, [heights, items])

  const stackHeight = isExpanded ? expandedHeight : collapsedHeight

  if (items.length === 0) return null

  return (
    <div
      className="pointer-events-auto relative w-full"
      style={{
        height: stackHeight,
        // Puente de hover: padding mínimo evita parpadeo en gaps expandido
        paddingTop: isExpanded ? 2 : 0,
        paddingBottom: isExpanded ? 2 : 0,
        transition: 'height 320ms cubic-bezier(0.22, 1, 0.36, 1)',
      }}
      onMouseEnter={() => setIsExpanded(true)}
      onMouseLeave={() => setIsExpanded(false)}
    >
      {items.map((item, index) => {
        const buried = !isExpanded && index >= MAX_COLLAPSED_VISIBLE
        const scale = isExpanded
          ? 1
          : buried
            ? 0.85
            : 1 - index * 0.05
        const peekY = isBottom ? -index * PEEK_PX : index * PEEK_PX
        const y = isExpanded
          ? expandedOffsetY(index, items, heights, isBottom)
          : peekY
        const layoutTransform = `translateY(${y}px) scale(${scale})`
        const layoutOpacity = buried ? 0 : 1
        // Colapsado: solo el frontal recibe clics; expandido: todos legibles/cerrables
        const interactive: 'auto' | 'none' =
          item.exiting || buried ? 'none' : isExpanded || index === 0 ? 'auto' : 'none'

        return (
          <ToastCard
            key={item.id}
            item={item}
            isBottom={isBottom}
            isExpanded={isExpanded}
            stackPaused={isExpanded}
            layoutTransform={layoutTransform}
            layoutOpacity={layoutOpacity}
            zIndex={50 - index}
            pointerEvents={interactive}
            dimContent={index > 0}
            onDismiss={onDismiss}
            onMeasure={onMeasure}
          />
        )
      })}
    </div>
  )
}

/**
 * Toasts globales iOS Liquid Glass — baraja apilada, posición desde ScreenPreferences.
 */
export function ToastProvider({ children }: { children: ReactNode }) {
  const { toastPosition } = useScreenPreferences()
  const [items, setItems] = useState<ToastItem[]>([])
  const exitTimersRef = useRef<Map<string, ReturnType<typeof setTimeout>>>(new Map())

  const clearExitTimer = useCallback((id: string) => {
    const t = exitTimersRef.current.get(id)
    if (t) {
      clearTimeout(t)
      exitTimersRef.current.delete(id)
    }
  }, [])

  const dismiss = useCallback(
    (id: string) => {
      clearExitTimer(id)
      setItems((prev) => prev.map((t) => (t.id === id ? { ...t, exiting: true } : t)))
      const exitTimer = setTimeout(() => {
        setItems((prev) => prev.filter((t) => t.id !== id))
        exitTimersRef.current.delete(id)
      }, EXIT_MS)
      exitTimersRef.current.set(id, exitTimer)
    },
    [clearExitTimer],
  )

  const push = useCallback((input: ToastInput) => {
    const id = `toast-${++toastIdSeq}`
    const variant = input.variant ?? 'info'
    const durationMs = input.durationMs ?? AUTO_CLOSE_MS
    // Más reciente al frente (índice 0)
    setItems((prev) => [
      {
        id,
        title: input.title,
        description: input.description,
        variant,
        durationMs,
      },
      ...prev,
    ])
  }, [])

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
        className={cn(
          'pointer-events-none fixed z-[110] w-[360px] max-w-[calc(100vw-2.5rem)]',
          POSITION_CONTAINER[toastPosition],
        )}
        aria-live="polite"
        aria-relevant="additions"
      >
        <ToastStack items={items} position={toastPosition} onDismiss={dismiss} />
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
