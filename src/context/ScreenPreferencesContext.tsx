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

export type ScreenPreferences = {
  /** Filas por página por defecto en tablas del ERP. */
  pageSize: PageSizeOption
  /** Densidad visual de filas. */
  density: TableDensity
}

type ScreenPreferencesContextValue = ScreenPreferences & {
  setPageSize: (size: PageSizeOption) => void
  setDensity: (density: TableDensity) => void
  setPreferences: (next: Partial<ScreenPreferences>) => void
  /** Clases de padding según densidad. */
  rowPaddingClass: string
}

const STORAGE_KEY = 'operam.screen.preferences'

const DEFAULTS: ScreenPreferences = {
  pageSize: 25,
  density: 'comfortable',
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
    return { pageSize, density }
  } catch {
    return DEFAULTS
  }
}

const ScreenPreferencesContext = createContext<ScreenPreferencesContextValue | null>(
  null,
)

/**
 * Preferencias de pantalla globales (pageSize + densidad).
 * Persistidas en localStorage; las tablas leen `pageSize` vía `useScreenPreferences()`.
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
      setPreferences,
      rowPaddingClass: prefs.density === 'compact' ? 'py-1.5 px-3' : 'py-3 px-4',
    }),
    [prefs, setPageSize, setDensity, setPreferences],
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
