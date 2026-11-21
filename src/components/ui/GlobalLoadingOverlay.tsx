import { useIsFetching, useIsMutating } from '@tanstack/react-query'
import { useLoadingLock } from '@/context/LoadingLockContext'
import { cn } from '@/lib/utils'

/**
 * Indicador global Operam:
 * - Barra arcoíris fija (fetching / mutating / lock).
 * - Velo + Spinner Aura Operam (misma paleta que la barra) en mutaciones/candado.
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
          {/* Spinner Aura Operam — limpio, sin tarjeta/modal */}
          <div className="relative flex h-14 w-14 items-center justify-center">
            {/* Halo difuso (capa trasera) */}
            <span
              aria-hidden
              className="operam-aura-halo absolute inset-0 rounded-full opacity-45 blur-md dark:opacity-60"
            />
            {/* Anillo nítido perforado (capa frontal) */}
            <span
              aria-hidden
              className="operam-aura-ring absolute inset-[4px] h-12 w-12 rounded-full"
            />
          </div>
        </div>
      )}
    </>
  )
}
