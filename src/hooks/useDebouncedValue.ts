import { useEffect, useState } from 'react'

/** Devuelve `value` solo después de `delayMs` sin cambios (p. ej. cotizar mientras se teclea). */
export function useDebouncedValue<T>(value: T, delayMs: number): T {
  const [debounced, setDebounced] = useState(value)

  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(value), delayMs)
    return () => window.clearTimeout(timer)
  }, [value, delayMs])

  return debounced
}
