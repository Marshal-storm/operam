import { ChevronLeft, ChevronRight } from 'lucide-react'
import { useScreenPreferences, type PageSizeOption } from '@/context/ScreenPreferencesContext'
import { cn } from '@/lib/utils'
import { AppButton } from './AppButton'
import { AppSelect } from './AppSelect'

type PaginationBarProps = {
  page: number
  total: number
  pageSize: number
  onPageChange: (page: number) => void
  onPageSizeChange?: (size: PageSizeOption) => void
  className?: string
}

const PAGE_SIZE_OPTIONS = [
  { value: '10', label: '10' },
  { value: '25', label: '25' },
  { value: '50', label: '50' },
  { value: '100', label: '100' },
]

/**
 * Barra de paginación Operam: total, selector pageSize (AppSelect) y páginas.
 */
export function PaginationBar({
  page,
  total,
  pageSize,
  onPageChange,
  onPageSizeChange,
  className,
}: PaginationBarProps) {
  const { pageSize: preferredSize } = useScreenPreferences()
  const size = pageSize || preferredSize
  const totalPages = Math.max(1, Math.ceil(total / size))
  const safePage = Math.min(Math.max(1, page), totalPages)
  const from = total === 0 ? 0 : (safePage - 1) * size + 1
  const to = Math.min(total, safePage * size)

  const pages = buildPageList(safePage, totalPages)

  return (
    <div
      className={cn(
        'flex flex-col gap-3 border-t border-slate-200/80 pt-3 sm:flex-row sm:items-center sm:justify-between dark:border-white/[0.07]',
        className,
      )}
    >
      <p className="text-xs text-slate-500 dark:text-slate-400">
        Mostrando{' '}
        <span className="font-mono tabular-nums font-medium text-slate-700 dark:text-slate-200">
          {from}–{to}
        </span>{' '}
        de <span className="font-mono tabular-nums">{total}</span> registros
      </p>

      <div className="flex flex-wrap items-center gap-2">
        {onPageSizeChange ? (
          <label className="flex items-center gap-1.5 text-xs text-slate-500">
            Por página
            <AppSelect
              aria-label="Registros por página"
              value={String(size)}
              onValueChange={(v) => {
                onPageSizeChange(Number(v) as PageSizeOption)
                onPageChange(1)
              }}
              options={PAGE_SIZE_OPTIONS}
              triggerClassName="mt-0 h-8 w-[4.5rem] py-1 text-xs font-mono tabular-nums"
              searchable={false}
            />
          </label>
        ) : null}

        <AppButton
          type="button"
          variant="outline"
          size="icon"
          className="h-8 w-8"
          disabled={safePage <= 1}
          onClick={() => onPageChange(safePage - 1)}
          aria-label="Página anterior"
        >
          <ChevronLeft className="h-4 w-4" />
        </AppButton>

        {pages.map((p, idx) =>
          p === '…' ? (
            <span key={`e-${idx}`} className="px-1 text-xs text-slate-400">
              …
            </span>
          ) : (
            <AppButton
              key={p}
              type="button"
              variant={p === safePage ? 'primary' : 'outline'}
              size="sm"
              className="h-8 min-w-8 px-2 font-mono tabular-nums"
              onClick={() => onPageChange(p)}
            >
              {p}
            </AppButton>
          ),
        )}

        <AppButton
          type="button"
          variant="outline"
          size="icon"
          className="h-8 w-8"
          disabled={safePage >= totalPages}
          onClick={() => onPageChange(safePage + 1)}
          aria-label="Página siguiente"
        >
          <ChevronRight className="h-4 w-4" />
        </AppButton>
      </div>
    </div>
  )
}

function buildPageList(current: number, totalPages: number): Array<number | '…'> {
  if (totalPages <= 7) {
    return Array.from({ length: totalPages }, (_, i) => i + 1)
  }
  const pages: Array<number | '…'> = [1]
  const start = Math.max(2, current - 1)
  const end = Math.min(totalPages - 1, current + 1)
  if (start > 2) pages.push('…')
  for (let i = start; i <= end; i++) pages.push(i)
  if (end < totalPages - 1) pages.push('…')
  pages.push(totalPages)
  return pages
}
