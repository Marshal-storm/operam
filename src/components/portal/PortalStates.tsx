import { AlertTriangle, type LucideIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard } from '@/components/ui/AppCard'
import { Skeleton } from '@/components/ui/skeleton'
import { cn } from '@/lib/utils'
import { PORTAL_CARD_CLASS } from './portalTokens'

/** Bloque esqueleto neutro (azul-gris en claro / blanco translúcido en OLED). */
export function PortalSkeletonBlock({ className }: { className?: string }) {
  return <Skeleton className={cn('bg-slate-200/70 dark:bg-white/[0.07]', className)} />
}

/** Esqueleto genérico de pantalla (UI-7: cargando). */
export function PortalScreenSkeleton({ rows = 3 }: { rows?: number }) {
  return (
    <div className="space-y-4" aria-busy="true" aria-live="polite">
      <PortalSkeletonBlock className="h-8 w-48 rounded-xl" />
      <PortalSkeletonBlock className="h-52 w-full rounded-[28px]" />
      <div className="grid gap-4 sm:grid-cols-2">
        {Array.from({ length: rows }).map((_, index) => (
          <PortalSkeletonBlock key={index} className="h-32 w-full rounded-[28px]" />
        ))}
      </div>
    </div>
  )
}

type PortalEmptyStateProps = {
  icon: LucideIcon
  title: string
  description: string
  action?: ReactNode
  className?: string
}

/** Estado vacío amable (UI-7: sin datos). */
export function PortalEmptyState({
  icon: Icon,
  title,
  description,
  action,
  className,
}: PortalEmptyStateProps) {
  return (
    <AppCard
      className={cn(
        PORTAL_CARD_CLASS,
        'flex flex-col items-center gap-3 px-6 py-10 text-center',
        className,
      )}
    >
      <span className="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-white/[0.06] dark:text-zinc-500">
        <Icon className="h-6 w-6" aria-hidden />
      </span>
      <div className="space-y-1">
        <p className="font-heading text-base font-semibold">{title}</p>
        <p className="mx-auto max-w-sm text-sm text-slate-500 dark:text-zinc-400">{description}</p>
      </div>
      {action}
    </AppCard>
  )
}

type PortalErrorStateProps = {
  message: string
  onRetry: () => void
}

/** Estado de error con reintento (UI-7: error). */
export function PortalErrorState({ message, onRetry }: PortalErrorStateProps) {
  return (
    <AppCard
      role="alert"
      className={cn(PORTAL_CARD_CLASS, 'flex flex-col items-center gap-3 px-6 py-10 text-center')}
    >
      <span className="flex h-12 w-12 items-center justify-center rounded-full bg-red-500/10 text-red-500">
        <AlertTriangle className="h-6 w-6" aria-hidden />
      </span>
      <div className="space-y-1">
        <p className="font-heading text-base font-semibold">No pudimos cargar tu información</p>
        <p className="mx-auto max-w-sm text-sm text-slate-500 dark:text-zinc-400">{message}</p>
      </div>
      <AppButton variant="outline" onClick={onRetry} className="h-11 min-w-[140px]">
        Reintentar
      </AppButton>
    </AppCard>
  )
}
