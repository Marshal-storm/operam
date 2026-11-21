import * as React from 'react'
import { cn } from '@/lib/utils'
import { AppButton } from './AppButton'
import { AppCard } from './AppCard'
import { AppSearchInput } from './AppInput'
import { AppSelect, type AppSelectOption } from './AppSelect'

/** Estilo oficial de etiqueta superior de filtro (mayúsculas discretas). */
export const APP_FILTER_LABEL_CLASS =
  'text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-zinc-400'

/** Trigger cápsula para selects de la fila 2. */
export const APP_FILTER_PILL_TRIGGER_CLASS = 'rounded-full min-w-[200px]'

export type AppFilterBarSelectField = {
  id: string
  /** Texto de etiqueta; se muestra en mayúsculas (p. ej. Segmento → SEGMENTO). */
  label: string
  value: string
  onValueChange: (value: string) => void
  options: AppSelectOption[]
  disabled?: boolean
  placeholder?: string
  className?: string
  'aria-label'?: string
}

export type AppFilterBarCustomField = {
  id: string
  label: string
  /** Contenido libre (fechas, etc.) con la misma geometría de label + control. */
  content: React.ReactNode
  className?: string
}

export type AppFilterBarField = AppFilterBarSelectField | AppFilterBarCustomField

function isSelectField(field: AppFilterBarField): field is AppFilterBarSelectField {
  return 'options' in field && 'onValueChange' in field
}

export type AppFilterBarProps = {
  /** Valor controlado del buscador (omitir para ocultar la fila 1). */
  searchValue?: string
  onSearchChange?: (value: string) => void
  onSearchClear?: () => void
  searchPlaceholder?: string
  searchLabel?: string
  searchAriaLabel?: string
  /** Selectores / campos de la fila 2. */
  filters?: AppFilterBarField[]
  /** Campos adicionales en la fila 2 (después de `filters`). */
  children?: React.ReactNode
  hasActiveFilters?: boolean
  onClearFilters?: () => void
  clearLabel?: string
  className?: string
}

/**
 * Barra de filtros homologada Operam — buscador cápsula + selects píldora.
 * Única geometría permitida en listados/directorios (§3.2 `.cursorrules`).
 */
export function AppFilterBar({
  searchValue,
  onSearchChange,
  onSearchClear,
  searchPlaceholder = 'Buscar…',
  searchLabel = 'BUSCAR',
  searchAriaLabel,
  filters = [],
  children,
  hasActiveFilters = false,
  onClearFilters,
  clearLabel = 'Limpiar filtros',
  className,
}: AppFilterBarProps) {
  const showSearch = onSearchChange !== undefined
  const hasFilterRow = filters.length > 0 || children != null || (hasActiveFilters && onClearFilters)

  return (
    <AppCard className={cn('space-y-4 p-4', className)}>
      {showSearch ? (
        <div className="flex w-full flex-col gap-1.5">
          <span className={APP_FILTER_LABEL_CLASS}>{searchLabel}</span>
          <AppSearchInput
            value={searchValue ?? ''}
            onChange={(e) => onSearchChange?.(e.target.value)}
            onClear={
              onSearchClear ??
              (() => {
                onSearchChange?.('')
              })
            }
            placeholder={searchPlaceholder}
            aria-label={searchAriaLabel ?? searchLabel}
            className="rounded-full"
            containerClassName="w-full"
          />
        </div>
      ) : null}

      {hasFilterRow ? (
        <div className="flex w-full flex-wrap items-end gap-4">
          {filters.map((field) =>
            isSelectField(field) ? (
              <div
                key={field.id}
                className={cn('flex min-w-[200px] flex-1 flex-col gap-1.5 sm:flex-none', field.className)}
              >
                <span className={APP_FILTER_LABEL_CLASS}>{field.label}</span>
                <AppSelect
                  value={field.value}
                  onValueChange={field.onValueChange}
                  options={field.options}
                  disabled={field.disabled}
                  placeholder={field.placeholder}
                  aria-label={field['aria-label'] ?? field.label}
                  triggerClassName={APP_FILTER_PILL_TRIGGER_CLASS}
                  className="w-full min-w-[200px] sm:w-auto"
                />
              </div>
            ) : (
              <div
                key={field.id}
                className={cn('flex min-w-[200px] flex-1 flex-col gap-1.5 sm:flex-none', field.className)}
              >
                <span className={APP_FILTER_LABEL_CLASS}>{field.label}</span>
                {field.content}
              </div>
            ),
          )}

          {children}

          {hasActiveFilters && onClearFilters ? (
            <div className="ml-auto flex min-w-[140px] flex-col justify-end">
              <AppButton
                type="button"
                variant="ghost"
                className="rounded-full"
                onClick={onClearFilters}
              >
                {clearLabel}
              </AppButton>
            </div>
          ) : null}
        </div>
      ) : null}
    </AppCard>
  )
}

/**
 * Campo suelto homologado (label mayúsculas + control) para `children` de AppFilterBar.
 */
export function AppFilterBarFieldSlot({
  label,
  children,
  className,
}: {
  label: string
  children: React.ReactNode
  className?: string
}) {
  return (
    <div className={cn('flex min-w-[200px] flex-1 flex-col gap-1.5 sm:flex-none', className)}>
      <span className={APP_FILTER_LABEL_CLASS}>{label}</span>
      {children}
    </div>
  )
}
