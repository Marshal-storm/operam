import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'

const DEFAULT_LOCK_MESSAGE = 'Procesando transacción...'

type LoadingLockContextValue = {
  /** Candado de pantalla activo (anti doble-envío). */
  isLocked: boolean
  /** Mensaje interno del candado (auditoría / futuro a11y). */
  lockMessage: string
  /** Activa el candado (soporta anidación por contador). */
  startLock: (message?: string) => void
  /** Libera un nivel del candado. */
  stopLock: () => void
  /**
   * Envuelve una promesa/operación con candado de pantalla.
   * Garantiza stopLock en finally (incluso si falla).
   */
  withScreenLock: <T>(
    operation: Promise<T> | (() => Promise<T>),
    message?: string,
  ) => Promise<T>
}

const LoadingLockContext = createContext<LoadingLockContextValue | null>(null)

/**
 * Estado global de candado de pantalla (transacciones / login / sync).
 * La barra arcoíris también observa TanStack Query en GlobalLoadingOverlay.
 */
export function LoadingLockProvider({ children }: { children: ReactNode }) {
  const [lockDepth, setLockDepth] = useState(0)
  const [lockMessage, setLockMessage] = useState(DEFAULT_LOCK_MESSAGE)
  const messageStackRef = useRef<string[]>([])

  const startLock = useCallback((message?: string) => {
    const nextMessage = message?.trim() || DEFAULT_LOCK_MESSAGE
    messageStackRef.current.push(nextMessage)
    setLockMessage(nextMessage)
    setLockDepth((depth) => depth + 1)
  }, [])

  const stopLock = useCallback(() => {
    messageStackRef.current.pop()
    const previous = messageStackRef.current[messageStackRef.current.length - 1]
    setLockMessage(previous ?? DEFAULT_LOCK_MESSAGE)
    setLockDepth((depth) => Math.max(0, depth - 1))
  }, [])

  const withScreenLock = useCallback(
    async <T,>(
      operation: Promise<T> | (() => Promise<T>),
      message?: string,
    ): Promise<T> => {
      startLock(message)
      try {
        const promise =
          typeof operation === 'function' ? operation() : operation
        return await promise
      } finally {
        stopLock()
      }
    },
    [startLock, stopLock],
  )

  const value = useMemo<LoadingLockContextValue>(
    () => ({
      isLocked: lockDepth > 0,
      lockMessage,
      startLock,
      stopLock,
      withScreenLock,
    }),
    [lockDepth, lockMessage, startLock, stopLock, withScreenLock],
  )

  return (
    <LoadingLockContext.Provider value={value}>
      {children}
    </LoadingLockContext.Provider>
  )
}

export function useLoadingLock(): LoadingLockContextValue {
  const context = useContext(LoadingLockContext)
  if (!context) {
    throw new Error('useLoadingLock debe usarse dentro de LoadingLockProvider')
  }
  return context
}
