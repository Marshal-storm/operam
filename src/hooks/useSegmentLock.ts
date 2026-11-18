import { useEffect, useMemo, useState } from 'react'
import { usePermissions } from '@/hooks/usePermissions'
import type { AllowedSegment } from '@/types/auth'

const ALL_SEGMENTS: AllowedSegment[] = ['Todos', 'Operam', 'Construsync']

/**
 * Fija el filtro de segmento cuando isSegmentLocked y limita las píldoras visibles.
 */
export function useSegmentLock(initial: AllowedSegment = 'Todos') {
  const { allowedSegment, isSegmentLocked, lockedSegmentId } = usePermissions()
  const [segmento, setSegmento] = useState<AllowedSegment>(
    isSegmentLocked ? allowedSegment : initial,
  )

  useEffect(() => {
    if (isSegmentLocked) {
      setSegmento(allowedSegment)
    }
  }, [isSegmentLocked, allowedSegment])

  const segmentOptions = useMemo(
    () => (isSegmentLocked ? [allowedSegment] : ALL_SEGMENTS),
    [isSegmentLocked, allowedSegment],
  )

  return {
    segmento,
    setSegmento: (next: AllowedSegment | string) => {
      if (isSegmentLocked) {
        setSegmento(allowedSegment)
        return
      }
      if (next === 'Operam' || next === 'Construsync' || next === 'Todos') {
        setSegmento(next)
      }
    },
    segmentOptions,
    isSegmentLocked,
    lockedSegmentId,
    allowedSegment,
  }
}
