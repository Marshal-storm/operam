import { cn } from '@/lib/utils'

export type AppSwitchProps = {
  checked: boolean
  onCheckedChange: (next: boolean) => void
  disabled?: boolean
  /** Aria-label del control. */
  label?: string
  /**
   * Texto visible a la derecha del switch (columna ESTADO §3.3).
   * Ej. `Activo` / `Inactivo` / `Disponible`.
   */
  caption?: string
  id?: string
  className?: string
  captionClassName?: string
}

/**
 * Switch atómico estilo iOS — única implementación del interruptor.
 * Con `caption` muestra la etiqueta de estado a la derecha (ESTADO de tablas).
 */
export function AppSwitch({
  checked,
  onCheckedChange,
  disabled = false,
  label,
  caption,
  id,
  className,
  captionClassName,
}: AppSwitchProps) {
  const control = (
    <button
      id={id}
      type="button"
      role="switch"
      aria-checked={checked}
      aria-label={label ?? caption}
      disabled={disabled}
      onClick={() => onCheckedChange(!checked)}
      className={cn(
        'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors',
        checked ? 'bg-[#0066FF] dark:bg-[#0A84FF]' : 'bg-slate-300 dark:bg-white/20',
        disabled && 'cursor-not-allowed opacity-50',
        !caption && className,
      )}
    >
      <span
        className={cn(
          'inline-block h-5 w-5 transform rounded-full bg-white shadow transition',
          checked ? 'translate-x-[1.35rem]' : 'translate-x-0.5',
        )}
      />
    </button>
  )

  if (!caption) return control

  return (
    <div className={cn('inline-flex items-center gap-2', className)}>
      {control}
      <span
        className={cn(
          'whitespace-nowrap text-sm text-slate-600 dark:text-zinc-300',
          captionClassName,
        )}
      >
        {caption}
      </span>
    </div>
  )
}

/** Alias de compatibilidad. */
export const IosSwitch = AppSwitch
