import { useEffect, useState } from 'react'
import { useScreenPreferences, type PageSizeOption } from '@/context/ScreenPreferencesContext'

/**
 * Estado de tabla cliente: page + pageSize anclado a Preferencias de pantalla.
 */
export function useClientTableState() {
  const { pageSize: preferredPageSize } = useScreenPreferences()
  const [page, setPage] = useState(1)
  const [pageSize, setPageSizeLocal] = useState<PageSizeOption>(preferredPageSize)

  useEffect(() => {
    setPageSizeLocal(preferredPageSize)
    setPage(1)
  }, [preferredPageSize])

  return {
    page,
    setPage,
    pageSize,
    setPageSize: (size: PageSizeOption) => {
      setPageSizeLocal(size)
      setPage(1)
    },
  }
}

/** Aplica slice de paginación sobre un arreglo ya filtrado. */
export function paginateRows<T>(rows: T[], page: number, pageSize: number): T[] {
  const start = (page - 1) * pageSize
  return rows.slice(start, start + pageSize)
}
