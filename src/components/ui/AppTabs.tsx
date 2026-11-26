import * as React from 'react'
import { cn } from '@/lib/utils'

export type AppTabItem = {
  id: string
  label: string
  /** Contador o etiqueta corta (p. ej. costos del mes). */
  badge?: number | string
  /** Semáforo del contador (p. ej. fallidos del outbox); `neutral` por defecto. */
  badgeTone?: 'neutral' | 'warning' | 'danger'
  icon?: React.ReactNode
  disabled?: boolean
}

export type AppTabsProps = {
  items: AppTabItem[]
  value: string
  onChange: (id: string) => void
  /**
   * Ancho completo alineado con tarjetas / AppFilterBar (default `true`).
   * Usa `false` para selectores compactos (p. ej. Unit Economics).
   */
  fullWidth?: boolean
  className?: string
  /** `aria-label` del tablist. */
  'aria-label'?: string
}

/**
 * Barra de pestañas atómica Operam — cápsula Liquid Glass (claro + OLED).
 * Única fuente de verdad para tabs de producto (§3.1 `.cursorrules`).
 */
export function AppTabs({
  items,
  value,
  onChange,
  fullWidth = true,
  className,
  'aria-label': ariaLabel = 'Pestañas',
}: AppTabsProps) {
  return (
    <div
      role="tablist"
      aria-label={ariaLabel}
      className={cn(
        'flex flex-wrap items-center gap-1.5 p-1.5',
        // Cápsula limpia; en móvil muy estrecho permite wrap con radio suave.
        'rounded-2xl sm:rounded-full',
        'bg-white border border-slate-200/80 shadow-[0_4px_20px_rgba(15,23,42,0.04)]',
        'dark:bg-white/[0.04] dark:backdrop-blur-2xl dark:border-white/[0.09]',
        'dark:shadow-[inset_0_1px_0_0_rgba(255,255,255,0.08),0_8px_24px_rgba(0,0,0,0.45)]',
        fullWidth ? 'w-full' : 'inline-flex w-auto',
        className,
      )}
    >
      {items.map((item) => {
        const selected = item.id === value
        const showBadge =
          item.badge !== undefined &&
          item.badge !== null &&
          item.badge !== '' &&
          !(typeof item.badge === 'number' && item.badge <= 0)

        return (
          <button
            key={item.id}
            type="button"
            role="tab"
            aria-selected={selected}
            disabled={item.disabled}
            tabIndex={selected ? 0 : -1}
            onClick={() => {
              if (!item.disabled) onChange(item.id)
            }}
            className={cn(
              'inline-flex items-center justify-center gap-2 rounded-full px-3.5 py-2 text-sm transition-all',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0066FF]/40',
              'dark:focus-visible:ring-[#0A84FF]/40',
              'disabled:pointer-events-none disabled:opacity-40',
              selected
                ? 'bg-[#0066FF] font-semibold text-white shadow-[0_4px_14px_rgba(0,102,255,0.30)] dark:bg-[#0A84FF] dark:shadow-[0_4px_14px_rgba(10,132,255,0.35)]'
                : 'font-medium text-slate-600 hover:bg-slate-100/80 hover:text-slate-900 dark:text-zinc-400 dark:hover:bg-white/[0.06] dark:hover:text-white',
            )}
          >
            {item.icon ? (
              <span className="flex h-4 w-4 shrink-0 items-center justify-center opacity-90">
                {item.icon}
              </span>
            ) : null}
            <span className="whitespace-nowrap">{item.label}</span>
            {showBadge ? (
              <span
                className={cn(
                  'inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-[11px] font-semibold tabular-nums',
                  item.badgeTone === 'danger'
                    ? 'bg-[#EF4444] text-white'
                    : item.badgeTone === 'warning'
                      ? 'bg-[#F59E0B] text-white'
                      : selected
                        ? 'bg-white/20 text-white'
                        : 'bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-zinc-300',
                )}
              >
                {item.badge}
              </span>
            ) : null}
          </button>
        )
      })}
    </div>
  )
}
