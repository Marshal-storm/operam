import { Slot } from '@radix-ui/react-slot'
import { cva, type VariantProps } from 'class-variance-authority'
import { Loader2 } from 'lucide-react'
import * as React from 'react'
import { cn } from '@/lib/utils'

const appButtonVariants = cva(
  'inline-flex items-center justify-center gap-2 rounded-xl text-sm font-medium transition ' +
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0066FF]/40 ' +
    'disabled:pointer-events-none disabled:opacity-50 dark:focus-visible:ring-[#0A84FF]/40',
  {
    variants: {
      variant: {
        primary:
          'bg-[#0066FF] text-white hover:bg-[#0055DD] dark:bg-[#0A84FF] dark:hover:bg-[#0A74E0]',
        secondary:
          'bg-slate-100 text-slate-800 hover:bg-slate-200 dark:bg-white/[0.08] dark:text-zinc-100 dark:hover:bg-white/[0.12]',
        outline:
          'border border-slate-200 bg-transparent text-slate-800 hover:bg-slate-50 ' +
          'dark:border-white/[0.12] dark:text-zinc-100 dark:hover:bg-white/[0.05]',
        ghost:
          'bg-transparent text-slate-700 hover:bg-slate-100 dark:text-zinc-200 dark:hover:bg-white/[0.06]',
        danger:
          'bg-[#EF4444] text-white hover:bg-[#DC2626] dark:bg-[#EF4444] dark:hover:bg-[#F87171]',
      },
      size: {
        default: 'h-10 px-4 py-2',
        sm: 'h-8 rounded-lg px-3 text-xs',
        lg: 'h-11 px-6',
        icon: 'h-9 w-9 p-0',
      },
    },
    defaultVariants: {
      variant: 'primary',
      size: 'default',
    },
  },
)

export type AppButtonProps = React.ButtonHTMLAttributes<HTMLButtonElement> &
  VariantProps<typeof appButtonVariants> & {
    asChild?: boolean
    /** Muestra spinner y bloquea interacción. */
    isLoading?: boolean
  }

/**
 * Botón atómico Operam — única fuente de variantes de acción.
 */
export const AppButton = React.forwardRef<HTMLButtonElement, AppButtonProps>(
  (
    {
      className,
      variant,
      size,
      asChild = false,
      isLoading = false,
      disabled,
      children,
      ...props
    },
    ref,
  ) => {
    const Comp = asChild ? Slot : 'button'
    const isDisabled = disabled || isLoading

    return (
      <Comp
        className={cn(appButtonVariants({ variant, size, className }))}
        ref={ref}
        disabled={isDisabled}
        aria-busy={isLoading || undefined}
        {...props}
      >
        {isLoading ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden /> : null}
        {children}
      </Comp>
    )
  },
)
AppButton.displayName = 'AppButton'

export { appButtonVariants }
