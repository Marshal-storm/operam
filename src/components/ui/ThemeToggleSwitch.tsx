import { Moon, Sun } from 'lucide-react'
import { useTheme } from '@/context/ThemeContext'
import { cn } from '@/lib/utils'

type ThemeToggleSwitchProps = {
  className?: string
}

/**
 * Interruptor tipo píldora iOS (luna / sol) para override light/dark.
 */
export function ThemeToggleSwitch({ className }: ThemeToggleSwitchProps) {
  const { resolvedTheme, toggleLightDark } = useTheme()
  const isDark = resolvedTheme === 'dark'

  return (
    <button
      type="button"
      role="switch"
      aria-checked={isDark}
      aria-label={isDark ? 'Cambiar a tema claro' : 'Cambiar a tema oscuro'}
      onClick={toggleLightDark}
      className={cn(
        'relative inline-flex h-8 w-[3.35rem] shrink-0 items-center rounded-full border transition-colors duration-300',
        isDark
          ? 'border-white/[0.08] bg-white/[0.05] shadow-[inset_0_0_0_1px_rgba(255,255,255,0.06)]'
          : 'border-slate-200 bg-slate-200/90',
        className,
      )}
    >
      <span
        className={cn(
          'absolute left-1 flex h-6 w-6 items-center justify-center rounded-full shadow-md transition-all duration-300',
          isDark
            ? 'translate-x-[1.45rem] bg-gradient-to-br from-[#0A84FF] to-[#0066FF] text-white shadow-[0_0_16px_rgba(10,132,255,0.45)]'
            : 'translate-x-0 bg-white text-amber-500',
        )}
      >
        {isDark ? <Moon className="h-3.5 w-3.5" /> : <Sun className="h-3.5 w-3.5" />}
      </span>
    </button>
  )
}
