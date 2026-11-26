import { Receipt } from 'lucide-react'
import { PortalEmptyState } from '@/components/portal/PortalStates'
import { AppBadge } from '@/components/ui/AppBadge'
import { invoiceStatusBadge, portalDate, portalMoney } from '@/lib/portalLabels'
import { cn } from '@/lib/utils'
import type { PortalInvoice } from '@/types/portal'

type PortalInvoiceListProps = {
  invoices: PortalInvoice[]
  emptyTitle?: string
  emptyDescription?: string
  className?: string
}

/**
 * Lista de facturas/documentos de cobro: tarjetas apiladas (móvil) y filas amplias (escritorio).
 * Monto = saldo pendiente si lo hay; si está pagada, el total.
 */
export function PortalInvoiceList({
  invoices,
  emptyTitle = 'Sin facturas todavía',
  emptyDescription = 'Cuando se emita un documento de cobro aparecerá aquí.',
  className,
}: PortalInvoiceListProps) {
  if (invoices.length === 0) {
    return <PortalEmptyState icon={Receipt} title={emptyTitle} description={emptyDescription} />
  }

  return (
    <ul className={cn('divide-y divide-slate-200/70 dark:divide-white/[0.07]', className)}>
      {invoices.map((invoice) => {
        const badge = invoiceStatusBadge(invoice.status)
        const hasBalance = invoice.balance !== '0.00' && invoice.status !== 'paid'
        return (
          <li
            key={invoice.id}
            className="flex flex-col gap-2 py-3.5 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
          >
            <div className="min-w-0">
              <p className="truncate text-sm font-medium">{invoice.concept}</p>
              <p className="mt-0.5 text-xs text-slate-500 dark:text-zinc-400">
                <span className="font-mono tabular-nums">{invoice.folio}</span>
                {invoice.contract_folio ? ` · ${invoice.contract_folio}` : ''} · vence{' '}
                {portalDate(invoice.due_date)}
              </p>
            </div>
            <div className="flex items-center justify-between gap-3 sm:justify-end">
              <p className="font-mono text-base font-semibold tabular-nums">
                {portalMoney(hasBalance ? invoice.balance : invoice.total)}
              </p>
              <AppBadge label={badge.label} tone={badge.tone} />
            </div>
          </li>
        )
      })}
    </ul>
  )
}
