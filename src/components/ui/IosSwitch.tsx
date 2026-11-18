import { cn } from '@/lib/utils'

type IosSwitchProps = {
  checked: boolean
  onCheckedChange: (next: boolean) => void
  disabled?: boolean
  label?: string
  id?: string
}

/**
 * Switch tipo iOS para la matriz CRUD de permisos.
 */
export function IosSwitch({
  checked,
  onCheckedChange,
  disabled = false,
  label,
  id,
}: IosSwitchProps) {
  return (
    <button
      id={id}
      type="button"
      role="switch"
      aria-checked={checked}
      aria-label={label}
      disabled={disabled}
      onClick={() => onCheckedChange(!checked)}
      className={cn(
        'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors',
        checked ? 'bg-[#0066FF] dark:bg-[#0A84FF]' : 'bg-slate-300 dark:bg-white/20',
        disabled && 'cursor-not-allowed opacity-50',
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
}
