import * as DialogPrimitive from '@radix-ui/react-dialog'
import { X } from 'lucide-react'
import * as React from 'react'
import { cn } from '@/lib/utils'

/** Tamaños estándar homologados del AppDrawer (§3.1). */
export type AppDrawerSize = 'md' | 'lg' | 'xl' | '2xl'

const DRAWER_SIZE_CLASSES: Record<AppDrawerSize, string> = {
  md: 'md:max-w-lg',
  lg: 'md:max-w-2xl',
  xl: 'md:max-w-4xl',
  '2xl': 'md:max-w-5xl',
}

export type AppDrawerProps = {
  /** Controla si el Drawer está abierto. */
  open: boolean
  /** Callback al cerrar (Escape, clic en velo o botón X). */
  onClose: () => void
  /** Título principal del Drawer (Header fijo). */
  title: React.ReactNode
  /** Subtítulo o descripción breve opcional debajo del título. */
  description?: React.ReactNode
  /** Ancho máximo del panel en pantallas >= md (default: 'lg'). */
  size?: AppDrawerSize
  /** Footer fijo opcional para acciones primarias/secundarias siempre visibles. */
  footer?: React.ReactNode
  /** Contenido adicional opcional en el Header (ej. badges de estado). */
  headerExtra?: React.ReactNode
  /** Contenido principal con scroll vertical propio. */
  children: React.ReactNode
  /** Clases adicionales para el panel del Drawer. */
  className?: string
  /** Clases adicionales para el contenedor scrollable del cuerpo. */
  bodyClassName?: string
}

/**
 * Panel lateral derecho atómico iOS Liquid Glass sobre Radix Dialog (§3.1).
 *
 * Geometría oficial:
 * - Anclado al borde derecho (`right-0`), iniciando justo debajo del Header (`top-14`, 56px).
 * - Altura exacta `h-[calc(100vh-3.5rem)]`, ancho completo en móvil (`< md`).
 * - Capa `z-50`: por debajo del Header (`z-[55]`) y de `AppModal` (`z-[70]`).
 * - Estructura fija de 3 zonas: Header (`shrink-0`), Body (`flex-1 overflow-y-auto`), Footer opcional (`shrink-0`).
 */
export function AppDrawer({
  open,
  onClose,
  title,
  description,
  size = 'lg',
  footer,
  headerExtra,
  children,
  className,
  bodyClassName,
}: AppDrawerProps) {
  return (
    <DialogPrimitive.Root
      open={open}
      onOpenChange={(nextOpen) => {
        if (!nextOpen) onClose()
      }}
    >
      <DialogPrimitive.Portal>
        {/* Velo translúcido debajo del Header (z-50) */}
        <DialogPrimitive.Overlay
          className={cn(
            'fixed inset-x-0 bottom-0 top-14 z-50 bg-slate-900/25 backdrop-blur-[2px] dark:bg-black/60',
            'data-[state=open]:animate-[operam-fade-in_200ms_ease-out_forwards]',
            'data-[state=closed]:animate-[operam-fade-out_180ms_ease-in_forwards]',
          )}
        />

        {/* Panel lateral derecho Liquid Glass (z-50) */}
        <DialogPrimitive.Content
          className={cn(
            'fixed bottom-0 right-0 top-14 z-50 flex h-[calc(100vh-3.5rem)] w-full flex-col outline-none',
            'border-l border-slate-200/80 bg-white/95 shadow-[-16px_0_40px_-12px_rgba(15,23,42,0.16)] backdrop-blur-2xl',
            'dark:border-l dark:border-white/[0.1] dark:bg-[#0D0D10]/95 dark:shadow-[-20px_0_50px_rgba(0,0,0,0.85)]',
            'data-[state=open]:animate-[operam-drawer-in_240ms_cubic-bezier(0.16,1,0.3,1)_forwards]',
            'data-[state=closed]:animate-[operam-drawer-out_200ms_ease-in_forwards]',
            DRAWER_SIZE_CLASSES[size],
            className,
          )}
        >
          {/* Zona 1: Header fijo */}
          <div className="flex shrink-0 items-start justify-between gap-4 border-b border-slate-200/80 px-6 py-4 dark:border-white/[0.08]">
            <div className="min-w-0 flex-1 space-y-1">
              <div className="flex flex-wrap items-center gap-2.5">
                <DialogPrimitive.Title className="font-heading text-lg font-semibold tracking-[-0.4px] text-slate-900 dark:text-white">
                  {title}
                </DialogPrimitive.Title>
                {headerExtra ? (
                  <div className="flex flex-wrap items-center gap-1.5">{headerExtra}</div>
                ) : null}
              </div>
              {description ? (
                <DialogPrimitive.Description className="text-sm text-slate-500 dark:text-zinc-400">
                  {description}
                </DialogPrimitive.Description>
              ) : (
                <DialogPrimitive.Description className="sr-only">
                  Panel lateral de detalle y edición
                </DialogPrimitive.Description>
              )}
            </div>

            <DialogPrimitive.Close
              type="button"
              aria-label="Cerrar panel"
              className={cn(
                'flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-slate-200/80 bg-slate-100/80 text-slate-500 transition',
                'hover:bg-slate-200/80 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-[#0066FF]/30',
                'dark:border-white/[0.1] dark:bg-white/[0.06] dark:text-zinc-400 dark:hover:bg-white/[0.12] dark:hover:text-white dark:focus:ring-[#0A84FF]/35',
              )}
            >
              <X className="h-4 w-4" />
            </DialogPrimitive.Close>
          </div>

          {/* Zona 2: Body con scroll vertical propio */}
          <div className={cn('min-w-0 flex-1 overflow-y-auto px-6 py-5', bodyClassName)}>
            {children}
          </div>

          {/* Zona 3: Footer fijo opcional */}
          {footer ? (
            <div className="flex shrink-0 flex-col-reverse gap-2 border-t border-slate-200/80 bg-white/80 px-6 py-3.5 backdrop-blur-xl sm:flex-row sm:items-center sm:justify-end sm:gap-3 dark:border-white/[0.08] dark:bg-[#0D0D10]/85">
              {footer}
            </div>
          ) : null}
        </DialogPrimitive.Content>
      </DialogPrimitive.Portal>
    </DialogPrimitive.Root>
  )
}
