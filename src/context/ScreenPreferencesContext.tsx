import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useState,
  type ReactNode,
} from 'react'

export type PageSizeOption = 10 | 25 | 50 | 100
export type TableDensity = 'comfortable' | 'compact'

/** Esquinas y centros donde pueden anclarse los toasts Liquid Glass. */
export type ToastPosition =
  | 'top-right'
  | 'top-center'
  | 'top-left'
  | 'bottom-right'
  | 'bottom-center'
  | 'bottom-left'

export type ScreenPreferences = {
  /** Filas por página por defecto en tablas del ERP. */
  pageSize: PageSizeOption
  /** Densidad visual de filas. */
  density: TableDensity
  /** Ancla del contenedor de notificaciones Toast. */
  toastPosition: ToastPosition
}

type ScreenPreferencesContextValue = ScreenPreferences & {
  setPageSize: (size: PageSizeOption) => void
  setDensity: (density: TableDensity) => void
  setToastPosition: (position: ToastPosition) => void
  setPreferences: (next: Partial<ScreenPreferences>) => void
  /** Clases de padding según densidad. */
  rowPaddingClass: string
}

const STORAGE_KEY = 'operam.screen.preferences'

const TOAST_POSITIONS: readonly ToastPosition[] = [
  'top-right',
  'top-center',
  'top-left',
  'bottom-right',
  'bottom-center',
  'bottom-left',
] as const

const DEFAULTS: ScreenPreferences = {
  pageSize: 25,
  density: 'comfortable',
  toastPosition: 'top-right',
}

function isToastPosition(value: unknown): value is ToastPosition {
  return typeof value === 'string' && (TOAST_POSITIONS as readonly string[]).includes(value)
}

function loadStored(): ScreenPreferences {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return DEFAULTS
    const parsed = JSON.parse(raw) as Partial<ScreenPreferences>
    const pageSize = [10, 25, 50, 100].includes(Number(parsed.pageSize))
      ? (Number(parsed.pageSize) as PageSizeOption)
      : DEFAULTS.pageSize
    const density =
      parsed.density === 'compact' || parsed.density === 'comfortable'
        ? parsed.density
        : DEFAULTS.density
    const toastPosition = isToastPosition(parsed.toastPosition)
      ? parsed.toastPosition
      : DEFAULTS.toastPosition
    return { pageSize, density, toastPosition }
  } catch {
    return DEFAULTS
  }
}

const ScreenPreferencesContext = createContext<ScreenPreferencesContextValue | null>(
  null,
)

/**
 * Preferencias de pantalla globales (pageSize + densidad + toastPosition).
 * Persistidas en localStorage; las tablas y ToastProvider leen vía `useScreenPreferences()`.
 */
export function ScreenPreferencesProvider({ children }: { children: ReactNode }) {
  const [prefs, setPrefs] = useState<ScreenPreferences>(() => loadStored())

  const persist = useCallback((next: ScreenPreferences) => {
    setPrefs(next)
    localStorage.setItem(STORAGE_KEY, JSON.stringify(next))
  }, [])

  const setPageSize = useCallback(
    (pageSize: PageSizeOption) => {
      persist({ ...prefs, pageSize })
    },
    [persist, prefs],
  )

  const setDensity = useCallback(
    (density: TableDensity) => {
      persist({ ...prefs, density })
    },
    [persist, prefs],
  )

  const setToastPosition = useCallback(
    (toastPosition: ToastPosition) => {
      persist({ ...prefs, toastPosition })
    },
    [persist, prefs],
  )

  const setPreferences = useCallback(
    (partial: Partial<ScreenPreferences>) => {
      persist({ ...prefs, ...partial })
    },
    [persist, prefs],
  )

  const value = useMemo<ScreenPreferencesContextValue>(
    () => ({
      ...prefs,
      setPageSize,
      setDensity,
      setToastPosition,
      setPreferences,
      rowPaddingClass: prefs.density === 'compact' ? 'py-1.5 px-3' : 'py-3 px-4',
    }),
    [prefs, setPageSize, setDensity, setToastPosition, setPreferences],
  )

  return (
    <ScreenPreferencesContext.Provider value={value}>
      {children}
    </ScreenPreferencesContext.Provider>
  )
}

export function useScreenPreferences(): ScreenPreferencesContextValue {
  const ctx = useContext(ScreenPreferencesContext)
  if (!ctx) {
    throw new Error('useScreenPreferences debe usarse dentro de ScreenPreferencesProvider')
  }
  return ctx
}

export const PAGE_SIZE_OPTIONS: PageSizeOption[] = [10, 25, 50, 100]

export const TOAST_POSITION_OPTIONS: readonly ToastPosition[] = TOAST_POSITIONS

/** Etiquetas UI (es-MX) para el selector de posición de toasts. */
export const TOAST_POSITION_LABELS: Record<ToastPosition, string> = {
  'top-left': 'Superior izquierda',
  'top-center': 'Superior centro',
  'top-right': 'Superior derecha',
  'bottom-left': 'Inferior izquierda',
  'bottom-center': 'Inferior centro',
  'bottom-right': 'Inferior derecha',
}
