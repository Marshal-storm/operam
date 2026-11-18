import { ChevronLeft, ChevronRight } from 'lucide-react'
import { useScreenPreferences, type PageSizeOption } from '@/context/ScreenPreferencesContext'
import { cn } from '@/lib/utils'

type PaginationBarProps = {
  page: number
  total: number
  pageSize: number
  onPageChange: (page: number) => void
  onPageSizeChange?: (size: PageSizeOption) => void
  className?: string
}

/**
 * Barra de paginación Operam: total, selector pageSize y páginas.
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
        {onPageSizeChange && (
          <label className="flex items-center gap-1.5 text-xs text-slate-500">
            Por página
            <select
              className="rounded-lg border border-slate-200 bg-white px-2 py-1 font-mono text-xs tabular-nums dark:border-white/[0.08] dark:bg-white/[0.05]"
              value={size}
              onChange={(e) => {
                onPageSizeChange(Number(e.target.value) as PageSizeOption)
                onPageChange(1)
              }}
            >
              {[10, 25, 50, 100].map((n) => (
                <option key={n} value={n}>
                  {n}
                </option>
              ))}
            </select>
          </label>
        )}

        <button
          type="button"
          disabled={safePage <= 1}
          onClick={() => onPageChange(safePage - 1)}
          className="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 disabled:opacity-40 dark:border-white/[0.08]"
          aria-label="Página anterior"
        >
          <ChevronLeft className="h-4 w-4" />
        </button>

        {pages.map((p, idx) =>
          p === '…' ? (
            <span key={`e-${idx}`} className="px-1 text-xs text-slate-400">
              …
            </span>
          ) : (
            <button
              key={p}
              type="button"
              onClick={() => onPageChange(p)}
              className={cn(
                'flex h-8 min-w-8 items-center justify-center rounded-lg px-2 font-mono text-xs tabular-nums',
                p === safePage
                  ? 'bg-[#0066FF] text-white dark:bg-[#0A84FF]'
                  : 'border border-slate-200 hover:bg-slate-50 dark:border-white/[0.08] dark:hover:bg-white/[0.05]',
              )}
            >
              {p}
            </button>
          ),
        )}

        <button
          type="button"
          disabled={safePage >= totalPages}
          onClick={() => onPageChange(safePage + 1)}
          className="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 disabled:opacity-40 dark:border-white/[0.08]"
          aria-label="Página siguiente"
        >
          <ChevronRight className="h-4 w-4" />
        </button>
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
