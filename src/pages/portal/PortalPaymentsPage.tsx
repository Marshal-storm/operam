import { CreditCard, Landmark, Wallet } from 'lucide-react'
import { useMemo, useState } from 'react'
import { PortalInvoiceList } from '@/components/portal/PortalInvoiceList'
import { PortalPaymentModal } from '@/components/portal/PortalPaymentModal'
import { PortalSpeiModal } from '@/components/portal/PortalSpeiModal'
import {
  PortalEmptyState,
  PortalErrorState,
  PortalScreenSkeleton,
} from '@/components/portal/PortalStates'
import { PORTAL_CARD_CLASS } from '@/components/portal/portalTokens'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard, AppCardTitle } from '@/components/ui/AppCard'
import { AppFilterBar } from '@/components/ui/AppFilterBar'
import { PaginationBar } from '@/components/ui/PaginationBar'
import { usePortalInvoices, usePortalOverview } from '@/hooks/usePortalData'
import { compareMoney } from '@/lib/moneyCents'
import { paymentMethodLabel, portalDate, portalMoney } from '@/lib/portalLabels'
import { cn } from '@/lib/utils'
import type { PortalInvoicesQuery } from '@/types/portal'

const PAGE_SIZE = 10

const STATUS_OPTIONS = [
  { value: 'all', label: 'Todas' },
  { value: 'open', label: 'Por pagar', tone: 'warning' as const },
  { value: 'paid', label: 'Pagadas', tone: 'success' as const },
]

/** Pagos: facturas paginadas con filtros, pagos aplicados y acceso a los datos SPEI. */
export function PortalPaymentsPage() {
  const [page, setPage] = useState(1)
  const [status, setStatus] = useState('all')
  const [contractId, setContractId] = useState('all')
  const [speiOpen, setSpeiOpen] = useState(false)
  const [payOpen, setPayOpen] = useState(false)

  const overview = usePortalOverview()

  const query = useMemo<PortalInvoicesQuery>(
    () => ({
      page,
      per_page: PAGE_SIZE,
      status: status === 'open' || status === 'paid' ? status : undefined,
      contract_id: contractId === 'all' ? undefined : Number(contractId),
    }),
    [page, status, contractId],
  )
  const invoices = usePortalInvoices(query)

  const contracts = overview.data?.contracts ?? []
  const speiContract =
    contracts.find((contract) => String(contract.id) === contractId) ??
    contracts.find((contract) => !contract.is_current) ??
    contracts[0] ??
    null

  // Contrato a pagar con tarjeta: el filtrado, o el primero con adeudo (atrasados primero).
  const payContract =
    contracts.find((contract) => String(contract.id) === contractId) ??
    contracts.find((contract) => !contract.is_current && compareMoney(contract.open_balance_mxn, '0.00') > 0) ??
    contracts.find((contract) => compareMoney(contract.open_balance_mxn, '0.00') > 0) ??
    null
  const canPayWithCard = payContract !== null && compareMoney(payContract.open_balance_mxn, '0.00') > 0

  const hasActiveFilters = status !== 'all' || contractId !== 'all'
  function clearFilters() {
    setStatus('all')
    setContractId('all')
    setPage(1)
  }

  if (invoices.isLoading && !invoices.data) return <PortalScreenSkeleton rows={2} />
  if (invoices.isError || !invoices.data) {
    return (
      <PortalErrorState
        message={invoices.error instanceof Error ? invoices.error.message : 'Intenta de nuevo.'}
        onRetry={() => void invoices.refetch()}
      />
    )
  }

  const { items, total, payments } = invoices.data

  return (
    <div className="space-y-5">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="font-heading text-2xl font-bold tracking-[-0.4px] sm:text-3xl">Pagos</h1>
          <p className="mt-0.5 text-sm text-slate-500 dark:text-zinc-400">
            Tus facturas y los pagos que hemos aplicado.
          </p>
        </div>
        <div className="flex flex-col gap-2 sm:flex-row">
          {canPayWithCard ? (
            <AppButton type="button" className="h-11 rounded-full px-5" onClick={() => setPayOpen(true)}>
              <CreditCard className="h-4 w-4" /> Pagar con tarjeta
            </AppButton>
          ) : null}
          {speiContract ? (
            <AppButton
              type="button"
              variant="outline"
              className="h-11 rounded-full px-5"
              onClick={() => setSpeiOpen(true)}
            >
              <Landmark className="h-4 w-4" /> Datos SPEI
            </AppButton>
          ) : null}
        </div>
      </header>

      <AppFilterBar
        filters={[
          {
            id: 'status',
            label: 'ESTADO',
            value: status,
            onValueChange: (value) => {
              setStatus(value)
              setPage(1)
            },
            options: STATUS_OPTIONS,
          },
          ...(contracts.length > 1
            ? [
                {
                  id: 'contract',
                  label: 'CONTRATO',
                  value: contractId,
                  onValueChange: (value: string) => {
                    setContractId(value)
                    setPage(1)
                  },
                  options: [
                    { value: 'all', label: 'Todos' },
                    ...contracts.map((contract) => ({
                      value: String(contract.id),
                      label: contract.folio,
                    })),
                  ],
                },
              ]
            : []),
        ]}
        hasActiveFilters={hasActiveFilters}
        onClearFilters={clearFilters}
      />

      <AppCard className={cn(PORTAL_CARD_CLASS, 'space-y-4 p-5')}>
        <AppCardTitle>Facturas</AppCardTitle>
        <PortalInvoiceList
          invoices={items}
          emptyTitle="Sin facturas con esos filtros"
          emptyDescription="Prueba con otro estado o contrato."
        />
        {total > PAGE_SIZE ? (
          <PaginationBar page={page} total={total} pageSize={PAGE_SIZE} onPageChange={setPage} />
        ) : null}
      </AppCard>

      <AppCard className={cn(PORTAL_CARD_CLASS, 'space-y-4 p-5')}>
        <AppCardTitle>Pagos aplicados</AppCardTitle>
        {payments.length === 0 ? (
          <PortalEmptyState
            icon={Wallet}
            title="Aún no hay pagos registrados"
            description="Cuando se aplique un pago lo verás aquí."
            className="border-0 bg-transparent p-0 py-6 shadow-none dark:bg-transparent"
          />
        ) : (
          <ul className="divide-y divide-slate-200/70 dark:divide-white/[0.07]">
            {payments.map((payment) => (
              <li
                key={payment.id}
                className="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
              >
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium">{paymentMethodLabel(payment.payment_method)}</p>
                  <p className="text-xs text-slate-500 dark:text-zinc-400">
                    <span className="font-mono tabular-nums">{payment.invoice_folio}</span> ·{' '}
                    {portalDate(payment.payment_date)}
                    {payment.reference ? ` · Ref. ${payment.reference}` : ''}
                  </p>
                </div>
                <p className="shrink-0 font-mono text-base font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">
                  {portalMoney(payment.amount)}
                </p>
              </li>
            ))}
          </ul>
        )}
      </AppCard>

      {speiContract ? (
        <PortalSpeiModal
          open={speiOpen}
          onOpenChange={setSpeiOpen}
          spei={speiContract.spei}
          contractFolio={speiContract.folio}
          amount={speiContract.is_current ? null : speiContract.exigible_amount_mxn}
        />
      ) : null}

      {payContract ? (
        <PortalPaymentModal open={payOpen} onOpenChange={setPayOpen} contract={payContract} />
      ) : null}
    </div>
  )
}
