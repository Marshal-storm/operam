import { Search, X } from 'lucide-react'
import * as React from 'react'
import { cn } from '@/lib/utils'
import { APP_FIELD_CLASS, APP_FIELD_ERROR_CLASS, APP_FIELD_MT_CLASS } from './appTokens'

export type AppInputProps = Omit<React.InputHTMLAttributes<HTMLInputElement>, 'size'> & {
  /** Agrega margen superior típico bajo label. */
  withLabelSpacing?: boolean
  /** Marca visual de error de validación. */
  error?: boolean
  /** Fuerza tipografía mono tabular (folios, RFC, montos). */
  mono?: boolean
}

/**
 * Input atómico — texto / número / date / password.
 * Las clases de borde, fondo y focus viven solo en appTokens.
 */
export const AppInput = React.forwardRef<HTMLInputElement, AppInputProps>(
  (
    {
      className,
      withLabelSpacing = false,
      error = false,
      mono = false,
      type = 'text',
      ...props
    },
    ref,
  ) => (
    <input
      ref={ref}
      type={type}
      className={cn(
        withLabelSpacing ? APP_FIELD_MT_CLASS : APP_FIELD_CLASS,
        mono && 'font-mono tabular-nums',
        error && APP_FIELD_ERROR_CLASS,
        className,
      )}
      {...props}
    />
  ),
)
AppInput.displayName = 'AppInput'

export type AppCurrencyInputProps = AppInputProps

/** Input de moneda con DM Mono + tabular-nums (RNF-1 / UI). */
export const AppCurrencyInput = React.forwardRef<HTMLInputElement, AppCurrencyInputProps>(
  ({ className, inputMode = 'decimal', ...props }, ref) => (
    <AppInput
      ref={ref}
      mono
      inputMode={inputMode}
      className={cn('text-right', className)}
      {...props}
    />
  ),
)
AppCurrencyInput.displayName = 'AppCurrencyInput'

export type AppSearchInputProps = Omit<AppInputProps, 'type'> & {
  onClear?: () => void
  containerClassName?: string
}

/**
 * Buscador con ícono Search y botón limpiar opcional.
 */
export const AppSearchInput = React.forwardRef<HTMLInputElement, AppSearchInputProps>(
  ({ className, containerClassName, onClear, value, onChange, ...props }, ref) => {
    const hasValue = String(value ?? '').length > 0

    return (
      <div className={cn('relative', containerClassName)}>
        <Search
          className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
          aria-hidden
        />
        <AppInput
          ref={ref}
          type="search"
          value={value}
          onChange={onChange}
          className={cn('rounded-2xl py-2.5 pl-10 pr-10', className)}
          {...props}
        />
        {hasValue && onClear ? (
          <button
            type="button"
            onClick={onClear}
            className="absolute right-2 top-1/2 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-white/[0.08] dark:hover:text-zinc-200"
            aria-label="Limpiar búsqueda"
          >
            <X className="h-4 w-4" />
          </button>
        ) : null}
      </div>
    )
  },
)
AppSearchInput.displayName = 'AppSearchInput'

export type AppTextareaProps = React.TextareaHTMLAttributes<HTMLTextAreaElement> & {
  withLabelSpacing?: boolean
  error?: boolean
}

/** Textarea atómico con los mismos tokens de campo. */
export const AppTextarea = React.forwardRef<HTMLTextAreaElement, AppTextareaProps>(
  ({ className, withLabelSpacing = false, error = false, ...props }, ref) => (
    <textarea
      ref={ref}
      className={cn(
        withLabelSpacing ? APP_FIELD_MT_CLASS : APP_FIELD_CLASS,
        'min-h-[88px] resize-y',
        error && APP_FIELD_ERROR_CLASS,
        className,
      )}
      {...props}
    />
  ),
)
AppTextarea.displayName = 'AppTextarea'
