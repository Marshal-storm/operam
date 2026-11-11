import { useIsFetching, useIsMutating } from '@tanstack/react-query'
import { useLoadingLock } from '@/context/LoadingLockContext'
import { cn } from '@/lib/utils'

/**
 * Indicador global Operam (sin modal central):
 * - Barra arcoíris fija = único feedback visual de carga.
 * - Velo limpio de candado cuando isLocked (bloquea clics).
 */
export function GlobalLoadingOverlay() {
  const fetching = useIsFetching()
  const mutating = useIsMutating()
  const { isLocked } = useLoadingLock()

  const queryBusy = fetching > 0 || mutating > 0
  const barActive = queryBusy || isLocked

  return (
    <>
      {/* Único indicador visual: barra superior arcoíris */}
      <div
        role="progressbar"
        aria-hidden={!barActive}
        aria-busy={barActive}
        className={cn(
          'pointer-events-none fixed left-0 right-0 top-0 z-[100] h-1',
          barActive ? 'operam-rainbow-bar' : 'operam-rainbow-bar-idle',
        )}
      />

      {/* Candado limpio: solo velo, sin tarjeta / spinner / texto */}
      {isLocked && (
        <div
          className="pointer-events-auto fixed inset-0 z-[90] cursor-wait select-none bg-white/40 backdrop-blur-[2px] dark:bg-black/50"
          role="presentation"
          aria-hidden
        />
      )}
    </>
  )
}
