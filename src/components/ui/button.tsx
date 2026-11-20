/**
 * Compatibilidad shadcn: `Button` delega a AppButton.
 * Preferir `import { AppButton } from '@/components/ui/AppButton'`.
 */
import * as React from 'react'
import { AppButton, type AppButtonProps } from './AppButton'

type LegacyVariant = 'default' | 'secondary' | 'outline' | 'ghost' | 'link' | 'danger' | 'primary'

export type ButtonProps = Omit<AppButtonProps, 'variant'> & {
  variant?: LegacyVariant
}

function mapVariant(
  variant: LegacyVariant | null | undefined,
): AppButtonProps['variant'] {
  if (variant === 'default' || variant === 'link' || !variant) return 'primary'
  if (variant === 'primary') return 'primary'
  return variant
}

export const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(
  ({ variant, ...props }, ref) => (
    <AppButton ref={ref} variant={mapVariant(variant)} {...props} />
  ),
)
Button.displayName = 'Button'

export { AppButton }
