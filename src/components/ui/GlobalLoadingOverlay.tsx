import { useIsFetching, useIsMutating } from '@tanstack/react-query'
import { useLoadingLock } from '@/context/LoadingLockContext'
import { cn } from '@/lib/utils'

/**
 * Indicador global Operam:
 * - Barra arcoíris fija (fetching / mutating / lock).
 * - Velo + spinner central limpio en mutaciones y candado (sin modal).
 */
export function GlobalLoadingOverlay() {
  const fetching = useIsFetching()
  const mutating = useIsMutating()
  const { isLocked } = useLoadingLock()

  const transactionBusy = mutating > 0 || isLocked
  const barActive = fetching > 0 || transactionBusy

  return (
    <>
      <div
        role="progressbar"
        aria-hidden={!barActive}
        aria-busy={barActive}
        className={cn(
          'pointer-events-none fixed left-0 right-0 top-0 z-[100] h-1',
          barActive ? 'operam-rainbow-bar' : 'operam-rainbow-bar-idle',
        )}
      />

      {transactionBusy && (
        <div
          className="pointer-events-auto fixed inset-0 z-[90] flex cursor-wait select-none items-center justify-center bg-white/40 backdrop-blur-[2px] dark:bg-black/50"
          role="status"
          aria-live="polite"
          aria-label="Procesando petición"
        >
          {/* Spinner circular limpio — sin tarjeta/modal de fondo */}
          <div className="relative h-11 w-11 drop-shadow-[0_0_18px_rgba(10,132,255,0.45)]">
            <span className="absolute inset-0 rounded-full border-[3px] border-slate-300/50 dark:border-white/15" />
            <span className="absolute inset-0 animate-spin rounded-full border-[3px] border-transparent border-t-[#0A84FF] border-r-[#0066FF]/70" />
            <span className="operam-spinner-inner absolute inset-[5px] rounded-full border-[2px] border-transparent border-b-[#20A050] border-l-[#7A3FC4]/80" />
          </div>
        </div>
      )}
    </>
  )
}
