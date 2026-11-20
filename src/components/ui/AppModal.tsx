import * as DialogPrimitive from '@radix-ui/react-dialog'
import { X } from 'lucide-react'
import * as React from 'react'
import { cn } from '@/lib/utils'
import { APP_CARD_CLASS } from './appTokens'

export const AppModal = DialogPrimitive.Root
export const AppModalTrigger = DialogPrimitive.Trigger
export const AppModalPortal = DialogPrimitive.Portal
export const AppModalClose = DialogPrimitive.Close

export const AppModalOverlay = React.forwardRef<
  React.ComponentRef<typeof DialogPrimitive.Overlay>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Overlay>
>(({ className, ...props }, ref) => (
  <DialogPrimitive.Overlay
    ref={ref}
    className={cn(
      'fixed inset-0 z-50 bg-black/50 backdrop-blur-sm ' +
        'data-[state=open]:animate-in data-[state=closed]:animate-out',
      className,
    )}
    {...props}
  />
))
AppModalOverlay.displayName = 'AppModalOverlay'

export type AppModalContentProps = React.ComponentPropsWithoutRef<
  typeof DialogPrimitive.Content
> & {
  /** Oculta el botón X superior derecho. */
  hideCloseButton?: boolean
}

/**
 * Contenido de modal atómico sobre Radix Dialog + Liquid Glass.
 */
export const AppModalContent = React.forwardRef<
  React.ComponentRef<typeof DialogPrimitive.Content>,
  AppModalContentProps
>(({ className, children, hideCloseButton = false, ...props }, ref) => (
  <AppModalPortal>
    <AppModalOverlay />
    <DialogPrimitive.Content
      ref={ref}
      className={cn(
        APP_CARD_CLASS,
        'fixed left-1/2 top-1/2 z-50 grid w-[calc(100%-2rem)] max-w-2xl -translate-x-1/2 -translate-y-1/2 gap-4 p-5',
        className,
      )}
      {...props}
    >
      {children}
      {!hideCloseButton ? (
        <DialogPrimitive.Close className="absolute right-4 top-4 rounded-lg p-1 opacity-70 transition hover:bg-slate-100 hover:opacity-100 dark:hover:bg-white/[0.08]">
          <X className="h-4 w-4" />
          <span className="sr-only">Cerrar</span>
        </DialogPrimitive.Close>
      ) : null}
    </DialogPrimitive.Content>
  </AppModalPortal>
))
AppModalContent.displayName = 'AppModalContent'

export function AppModalHeader({
  className,
  ...props
}: React.HTMLAttributes<HTMLDivElement>) {
  return <div className={cn('flex flex-col gap-1.5 pr-6', className)} {...props} />
}

export function AppModalTitle({
  className,
  ...props
}: React.ComponentPropsWithoutRef<typeof DialogPrimitive.Title>) {
  return (
    <DialogPrimitive.Title
      className={cn(
        'font-heading text-lg font-semibold tracking-[-0.4px] text-foreground',
        className,
      )}
      {...props}
    />
  )
}

export function AppModalDescription({
  className,
  ...props
}: React.ComponentPropsWithoutRef<typeof DialogPrimitive.Description>) {
  return (
    <DialogPrimitive.Description
      className={cn('text-sm text-muted-foreground', className)}
      {...props}
    />
  )
}

/** Alias de compatibilidad con imports históricos de dialog.tsx */
export const Dialog = AppModal
export const DialogTrigger = AppModalTrigger
export const DialogPortal = AppModalPortal
export const DialogClose = AppModalClose
export const DialogOverlay = AppModalOverlay
export const DialogContent = AppModalContent
export const DialogHeader = AppModalHeader
export const DialogTitle = AppModalTitle
export const DialogDescription = AppModalDescription
