import * as React from 'react'
import { cn } from '@/lib/utils'
import { AppCard } from './AppCard'

export type AppTableWrapperProps = {
  children: React.ReactNode
  className?: string
  /** Cabecera de cristal (título / filtros / acciones). */
  toolbar?: React.ReactNode
  /** Pie: paginación u acciones masivas. */
  footer?: React.ReactNode
  /** Estado vacío / carga / error (UI-7). */
  emptyState?: React.ReactNode
  isEmpty?: boolean
  isLoading?: boolean
  loadingState?: React.ReactNode
}

/**
 * Contenedor estándar de tablas ERP: cabecera cristal, cuerpo scroll y pie.
 */
export function AppTableWrapper({
  children,
  className,
  toolbar,
  footer,
  emptyState,
  isEmpty = false,
  isLoading = false,
  loadingState,
}: AppTableWrapperProps) {
  return (
    <AppCard className={cn('overflow-hidden p-0', className)}>
      {toolbar ? (
        <div className="border-b border-slate-200/80 bg-white/80 px-4 py-3 backdrop-blur-xl dark:border-white/[0.07] dark:bg-white/[0.03]">
          {toolbar}
        </div>
      ) : null}

      <div className="relative">
        {isLoading && loadingState ? loadingState : null}
        {!isLoading && isEmpty && emptyState ? emptyState : null}
        {!isLoading && !isEmpty ? children : null}
      </div>

      {footer ? (
        <div className="border-t border-slate-200/80 px-4 py-3 dark:border-white/[0.07]">
          {footer}
        </div>
      ) : null}
    </AppCard>
  )
}

/** Cabecera sticky tipográfica para tablas desktop. */
export function AppTableHeadRow({
  className,
  ...props
}: React.HTMLAttributes<HTMLTableRowElement>) {
  return (
    <tr
      className={cn(
        'border-b border-slate-200/80 bg-slate-50/90 text-left text-xs font-medium uppercase tracking-wide text-slate-500 ' +
          'dark:border-white/[0.07] dark:bg-white/[0.04] dark:text-zinc-400',
        className,
      )}
      {...props}
    />
  )
}
