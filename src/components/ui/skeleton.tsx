import type { HTMLAttributes } from 'react'
import { cn } from '@/lib/utils'

/**
 * Esqueleto de carga (UI-7) — bloque animado reutilizable.
 */
export function Skeleton({
  className,
  ...props
}: HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={cn('animate-pulse rounded-md bg-[#E8E4DC]', className)}
      {...props}
    />
  )
}
