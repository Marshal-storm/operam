import * as SelectPrimitive from '@radix-ui/react-select'
import { Check, ChevronDown, Search } from 'lucide-react'
import * as React from 'react'
import { cn } from '@/lib/utils'
import {
  APP_FIELD_CLASS,
  APP_FIELD_ERROR_CLASS,
  APP_FIELD_MT_CLASS,
  APP_POPOVER_CLASS,
} from './appTokens'

/** Semáforo opcional por opción (estado / severidad). */
export type AppSelectTone = 'success' | 'warning' | 'danger' | 'info' | 'neutral'

export type AppSelectOption = {
  value: string
  label: string
  disabled?: boolean
  tone?: AppSelectTone
  /** Color CSS libre (hex/rgb) si no se usa tone. */
  color?: string
}

const TONE_DOT: Record<AppSelectTone, string> = {
  success: 'bg-emerald-500 shadow-[0_0_6px_#10B981]',
  warning: 'bg-amber-500 shadow-[0_0_6px_#F59E0B]',
  danger: 'bg-red-500 shadow-[0_0_6px_#EF4444]',
  info: 'bg-[#0A84FF] shadow-[0_0_6px_#0A84FF]',
  neutral: 'bg-slate-400',
}

const SEARCH_THRESHOLD = 6

export type AppSelectProps = {
  value?: string
  defaultValue?: string
  onValueChange?: (value: string) => void
  options: AppSelectOption[]
  placeholder?: string
  disabled?: boolean
  /** Id del trigger (accesibilidad / label htmlFor). */
  id?: string
  name?: string
  className?: string
  triggerClassName?: string
  withLabelSpacing?: boolean
  error?: boolean
  /** Forzar buscador aunque haya ≤6 opciones. */
  searchable?: boolean
  /** Contenido vacío cuando el filtro no encuentra nada. */
  emptyMessage?: string
  /** Aria label del trigger. */
  'aria-label'?: string
}

/**
 * Select atómico Radix + Liquid Glass.
 * Buscador interno automático cuando hay más de 6 opciones.
 */
export function AppSelect({
  value,
  defaultValue,
  onValueChange,
  options,
  placeholder = 'Seleccionar…',
  disabled = false,
  id,
  name,
  className,
  triggerClassName,
  withLabelSpacing = false,
  error = false,
  searchable,
  emptyMessage = 'Sin coincidencias',
  'aria-label': ariaLabel,
}: AppSelectProps) {
  const [open, setOpen] = React.useState(false)
  const [query, setQuery] = React.useState('')
  const showSearch = searchable ?? options.length > SEARCH_THRESHOLD

  const filtered = React.useMemo(() => {
    const q = query.trim().toLowerCase()
    if (!q) return options
    return options.filter((opt) => opt.label.toLowerCase().includes(q))
  }, [options, query])

  const selected = options.find((opt) => opt.value === value)

  return (
    <div className={cn('min-w-0 w-full max-w-full', className)}>
      <SelectPrimitive.Root
        value={value === '' || value === undefined ? undefined : value}
        defaultValue={defaultValue}
        onValueChange={onValueChange}
        open={open}
        onOpenChange={(next) => {
          setOpen(next)
          if (!next) setQuery('')
        }}
        disabled={disabled}
        name={name}
      >
        <SelectPrimitive.Trigger
          id={id}
          aria-label={ariaLabel}
          className={cn(
            withLabelSpacing ? APP_FIELD_MT_CLASS : APP_FIELD_CLASS,
            'flex w-full min-w-0 max-w-full items-center justify-between gap-2 overflow-hidden text-left',
            'data-[placeholder]:text-slate-400 dark:data-[placeholder]:text-zinc-500',
            error && APP_FIELD_ERROR_CLASS,
            triggerClassName,
          )}
        >
          <span className="flex min-w-0 flex-1 items-center gap-2 overflow-hidden">
            {selected?.tone || selected?.color ? (
              <OptionDot tone={selected.tone} color={selected.color} />
            ) : null}
            <span className="block min-w-0 flex-1 truncate text-left">
              <SelectPrimitive.Value placeholder={placeholder} />
            </span>
          </span>
          <SelectPrimitive.Icon asChild>
            <ChevronDown className="h-4 w-4 shrink-0 opacity-60" />
          </SelectPrimitive.Icon>
        </SelectPrimitive.Trigger>

        <SelectPrimitive.Portal>
          <SelectPrimitive.Content
            position="popper"
            sideOffset={6}
            className={cn(
              APP_POPOVER_CLASS,
              'z-[80] max-h-[min(320px,var(--radix-select-content-available-height))] w-[var(--radix-select-trigger-width)] max-w-[min(var(--radix-select-trigger-width),calc(100vw-2rem))]',
              'data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95',
            )}
          >
            {showSearch ? (
              <div
                className="sticky top-0 z-10 border-b border-slate-200/80 bg-white/90 p-2 dark:border-white/[0.07] dark:bg-[#111113]/90"
                onPointerDown={(e) => e.preventDefault()}
              >
                <div className="relative">
                  <Search
                    className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400"
                    aria-hidden
                  />
                  <input
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder="Buscar…"
                    className="w-full rounded-lg border border-slate-200 bg-slate-50 py-1.5 pl-8 pr-2 text-xs outline-none focus:ring-2 focus:ring-[#0066FF]/25 dark:border-white/[0.08] dark:bg-white/[0.05] dark:focus:ring-[#0A84FF]/30"
                    autoFocus
                  />
                </div>
              </div>
            ) : null}

            <SelectPrimitive.Viewport className="p-1">
              {filtered.length === 0 ? (
                <p className="px-3 py-6 text-center text-xs text-slate-400">{emptyMessage}</p>
              ) : (
                filtered.map((opt) => (
                  <SelectPrimitive.Item
                    key={opt.value}
                    value={opt.value}
                    disabled={opt.disabled}
                    className={cn(
                      'relative flex w-full min-w-0 cursor-pointer select-none items-center gap-2 rounded-lg py-2 pl-2 pr-8 text-sm outline-none',
                      'data-[highlighted]:bg-slate-100 data-[disabled]:pointer-events-none data-[disabled]:opacity-40',
                      'dark:data-[highlighted]:bg-white/[0.08]',
                    )}
                  >
                    <OptionDot tone={opt.tone} color={opt.color} />
                    <span className="min-w-0 flex-1 break-words">
                      <SelectPrimitive.ItemText>{opt.label}</SelectPrimitive.ItemText>
                    </span>
                    <SelectPrimitive.ItemIndicator className="absolute right-2 inline-flex">
                      <Check className="h-4 w-4 text-[#0066FF] dark:text-[#0A84FF]" />
                    </SelectPrimitive.ItemIndicator>
                  </SelectPrimitive.Item>
                ))
              )}
            </SelectPrimitive.Viewport>
          </SelectPrimitive.Content>
        </SelectPrimitive.Portal>
      </SelectPrimitive.Root>
    </div>
  )
}

function OptionDot({
  tone,
  color,
}: {
  tone?: AppSelectTone
  color?: string
}) {
  if (!tone && !color) return null
  return (
    <span
      className={cn(
        'inline-block h-2 w-2 shrink-0 rounded-full',
        tone ? TONE_DOT[tone] : '',
      )}
      style={color && !tone ? { backgroundColor: color } : undefined}
      aria-hidden
    />
  )
}
