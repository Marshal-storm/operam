import { useEffect, useState } from 'react'
import { useSegments } from '@/hooks/useSegments'

export type SegmentFilterValue = string

/**
 * Filtro de segmento dinámico (MySQL `segments`) + bloqueo de alcance JWT.
 * Sustituye el arreglo quemado Operam/Construsync.
 */
export function useSegmentLock(initial: SegmentFilterValue = 'Todos') {
  const {
    filterOptions,
    segments,
    segmentsMap,
    resolveName,
    isLoading,
    isSegmentLocked,
    lockedSegmentId,
    allowedSegment,
  } = useSegments()

  const [segment, setSegmentState] = useState<SegmentFilterValue>(
    isSegmentLocked ? allowedSegment : initial,
  )

  useEffect(() => {
    if (isSegmentLocked) {
      setSegmentState(allowedSegment)
    }
  }, [isSegmentLocked, allowedSegment])

  return {
    segment,
    setSegment: (next: SegmentFilterValue) => {
      if (isSegmentLocked) {
        setSegmentState(allowedSegment)
        return
      }
      setSegmentState(next)
    },
    /** Alias: opciones dinámicas para píldoras / AppSelect. */
    segmentOptions: filterOptions,
    filterOptions,
    segments,
    segmentsMap,
    resolveName,
    isLoading,
    isSegmentLocked,
    lockedSegmentId,
    allowedSegment,
  }
}
