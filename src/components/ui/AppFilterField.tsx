import * as React from 'react'
import { cn } from '@/lib/utils'
import { APP_FILTER_LABEL_CLASS } from './AppFilterBar'

export type AppFilterFieldProps = {
  /** Etiqueta visible del criterio (Segmento, Tipo, Estado…). */
  label: string
  children: React.ReactNode
  className?: string
  /** Orientación: label arriba (default) o a la izquierda. */
  layout?: 'stack' | 'inline'
}

/**
 * Contenedor atómico de un criterio con label.
 * Para barras de listado completas usar `AppFilterBar` (§3.2).
 */
export function AppFilterField({
  label,
  children,
  className,
  layout = 'stack',
}: AppFilterFieldProps) {
  return (
    <div
      className={cn(
        layout === 'inline'
          ? 'flex items-center gap-2'
          : 'flex min-w-[7.5rem] flex-col gap-1.5',
        className,
      )}
    >
      <span className={APP_FILTER_LABEL_CLASS}>{label}</span>
      {children}
    </div>
  )
}
