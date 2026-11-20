import * as React from 'react'
import { cn } from '@/lib/utils'

export type AppFilterFieldProps = {
  /** Etiqueta visible del criterio (Segmento, Tipo, Estado…). */
  label: string
  children: React.ReactNode
  className?: string
  /** Orientación: label arriba (default) o a la izquierda. */
  layout?: 'stack' | 'inline'
}

/**
 * Contenedor atómico para filtros de toolbar.
 * Obliga a mostrar un label claro del criterio aplicado.
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
          : 'flex min-w-[7.5rem] flex-col gap-1',
        className,
      )}
    >
      <span className="text-[11px] font-medium uppercase tracking-wide text-slate-500 dark:text-zinc-400">
        {label}
      </span>
      {children}
    </div>
  )
}
