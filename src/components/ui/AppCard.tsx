import * as React from 'react'
import { cn } from '@/lib/utils'
import { APP_CARD_CLASS } from './appTokens'

export type AppCardProps = React.HTMLAttributes<HTMLDivElement>

/**
 * Tarjeta atómica Liquid Glass — borde, radio, blur y sombra centralizados.
 */
export function AppCard({ className, ...props }: AppCardProps) {
  return <div className={cn(APP_CARD_CLASS, className)} {...props} />
}

export function AppCardHeader({
  className,
  ...props
}: React.HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={cn(
        'flex flex-col gap-1 border-b border-slate-200/80 px-5 py-4 ' +
          'dark:border-white/[0.07] dark:bg-white/[0.03]',
        className,
      )}
      {...props}
    />
  )
}

export function AppCardTitle({
  className,
  ...props
}: React.HTMLAttributes<HTMLHeadingElement>) {
  return (
    <h3
      className={cn(
        'font-heading text-base font-semibold tracking-[-0.4px] text-slate-900 dark:text-zinc-50',
        className,
      )}
      {...props}
    />
  )
}

export function AppCardDescription({
  className,
  ...props
}: React.HTMLAttributes<HTMLParagraphElement>) {
  return (
    <p className={cn('text-sm text-slate-500 dark:text-zinc-400', className)} {...props} />
  )
}

export function AppCardContent({
  className,
  ...props
}: React.HTMLAttributes<HTMLDivElement>) {
  return <div className={cn('px-5 py-4', className)} {...props} />
}

export function AppCardFooter({
  className,
  ...props
}: React.HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={cn(
        'flex items-center gap-2 border-t border-slate-200/80 px-5 py-3 dark:border-white/[0.07]',
        className,
      )}
      {...props}
    />
  )
}
