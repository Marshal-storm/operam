import { useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { fetchSegments, type SegmentOption } from '@/services/catalogService'
import { usePermissions } from '@/hooks/usePermissions'

export const SEGMENTS_QUERY_KEY = ['segments'] as const

/**
 * Hook centralizado — segmentos reales desde GET /api/segments (TanStack Query).
 * Respeta alcance RBAC del backend; en UI también filtra si isSegmentLocked.
 */
export function useSegments(options?: { enabled?: boolean }) {
  const { isSegmentLocked, lockedSegmentId, allowedSegment } = usePermissions()
  const enabled = options?.enabled ?? true

  const query = useQuery({
    queryKey: SEGMENTS_QUERY_KEY,
    queryFn: fetchSegments,
    staleTime: 60_000,
    enabled,
  })

  const segments: SegmentOption[] = useMemo(() => {
    const rows = query.data ?? []
    if (!isSegmentLocked) return rows
    return rows.filter(
      (s) =>
        (lockedSegmentId !== null && s.id === lockedSegmentId) ||
        s.name === allowedSegment,
    )
  }, [query.data, isSegmentLocked, lockedSegmentId, allowedSegment])

  /** Mapa id → nombre para columnas de tablas. */
  const segmentsMap = useMemo(() => {
    const map = new Map<number, string>()
    for (const s of segments) {
      map.set(s.id, s.name)
    }
    return map
  }, [segments])

  /** Opciones de filtro: "Todos" + nombres reales (o solo el bloqueado). */
  const filterOptions = useMemo(() => {
    if (isSegmentLocked) {
      return [{ value: allowedSegment, label: allowedSegment }]
    }
    return [
      { value: 'Todos', label: 'Todos' },
      ...segments.map((s) => ({ value: s.name, label: s.name })),
    ]
  }, [segments, isSegmentLocked, allowedSegment])

  function resolveName(segmentId: number | null | undefined): string {
    if (segmentId == null) return 'Todos'
    return segmentsMap.get(segmentId) ?? '—'
  }

  return {
    segments,
    segmentsMap,
    filterOptions,
    resolveName,
    isLoading: query.isLoading,
    isError: query.isError,
    error: query.error,
    refetch: query.refetch,
    isSegmentLocked,
    lockedSegmentId,
    allowedSegment,
  }
}
