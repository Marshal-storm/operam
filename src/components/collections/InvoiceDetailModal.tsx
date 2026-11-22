import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppButton } from '@/components/ui/AppButton'
import { AppTextarea } from '@/components/ui/AppInput'
import {
  AppModal,
  AppModalContent,
  AppModalDescription,
  AppModalFooter,
  AppModalHeader,
  AppModalTitle,
} from '@/components/ui/AppModal'
import { Skeleton } from '@/components/ui/skeleton'
import { useLoadingLock } from '@/context/LoadingLockContext'
import { useToast } from '@/context/ToastContext'
import { COLLECTIONS_QUERY_KEY, useInvoiceDetail } from '@/hooks/useCollections'
import { usePermissions } from '@/hooks/usePermissions'
import { formatCurrencyMx, formatDateEsMx } from '@/lib/utils'
import { reversePayment } from '@/services/collectionsService'
import type { InvoiceStatus, PaymentMethod, PaymentRecord } from '@/types/collection'

const STATUS_TONE: Record<
  InvoiceStatus,
  'success' | 'warning' | 'danger' | 'info' | 'neutral'
> = {
  pending: 'warning',
  partial: 'info',
  paid: 'success',
  overdue: 'danger',
  cancelled: 'neutral',
}

const STATUS_LABEL: Record<InvoiceStatus, string> = {
  pending: 'Pendiente',
  partial: 'Parcial',
  paid: 'Pagado',
  overdue: 'Vencido',
  cancelled: 'Cancelado',
}

const METHOD_LABEL: Record<PaymentMethod, string> = {
  transfer: 'Transferencia',
  card_integropay: 'Tarjeta IntegroPAY',
  cash: 'Efectivo',
  check: 'Cheque',
  other: 'Otro',
}

type InvoiceDetailModalProps = {
  open: boolean
  invoiceId: number | null
  onOpenChange: (open: boolean) => void
  onRecordPayment?: () => void
  canRecordPayment?: boolean
}

/**
 * Detalle del documento con historial de pagos y reversión contable.
 */
export function InvoiceDetailModal({
  open,
  invoiceId,
  onOpenChange,
  onRecordPayment,
  canRecordPayment = false,
}: InvoiceDetailModalProps) {
  const toast = useToast()
  const queryClient = useQueryClient()
  const { withScreenLock } = useLoadingLock()
  const { canEdit } = usePermissions()
  const canReverse = canEdit('collections')
  const { data, isLoading, isError, refetch } = useInvoiceDetail(invoiceId, open)

  const [reverseTarget, setReverseTarget] = useState<PaymentRecord | null>(null)
  const [reverseReason, setReverseReason] = useState('')

  const reverseMutation = useMutation({
    mutationFn: ({ paymentId, reason }: { paymentId: number; reason: string }) =>
      withScreenLock(
        () => reversePayment(paymentId, { reason }),
        'Revirtiendo pago...',
      ),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: [...COLLECTIONS_QUERY_KEY] }),
        queryClient.invalidateQueries({ queryKey: ['contracts'] }),
        queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
      ])
    },
  })

  return (
    <>
      <AppModal open={open} onOpenChange={onOpenChange}>
        <AppModalContent className="max-w-xl">
          <AppModalHeader>
            <AppModalTitle>
              {data ? data.folio : 'Detalle del documento'}
            </AppModalTitle>
            <AppModalDescription>
              {data
                ? `${data.client_name} · ${data.contract_folio}`
                : 'Factura e historial de pagos'}
            </AppModalDescription>
          </AppModalHeader>

          {isLoading ? (
            <div className="space-y-3 py-2">
              <Skeleton className="h-6 w-40" />
              <Skeleton className="h-20 w-full" />
              <Skeleton className="h-24 w-full" />
            </div>
          ) : null}

          {isError ? (
            <div className="space-y-3 py-4 text-center">
              <p className="text-sm text-red-500">No se pudo cargar el documento.</p>
              <AppButton variant="outline" onClick={() => void refetch()}>
                Reintentar
              </AppButton>
            </div>
          ) : null}

          {data && !isLoading ? (
            <div className="space-y-4">
              <div className="flex flex-wrap items-center gap-2">
                <AppBadge
                  label={STATUS_LABEL[data.status]}
                  tone={STATUS_TONE[data.status]}
                  className="whitespace-nowrap shrink-0"
                />
                {data.request_folio ? (
                  <AppBadge
                    label={data.request_folio}
                    tone="info"
                    className="whitespace-nowrap shrink-0"
                  />
                ) : null}
              </div>

              <p className="text-sm text-slate-600 dark:text-zinc-300">{data.concept}</p>

              <div className="space-y-2 rounded-2xl border border-slate-200/80 p-3 dark:border-white/[0.08]">
                <div className="flex justify-between gap-3 text-sm">
                  <span className="text-slate-500">Subtotal</span>
                  <span className="font-mono tabular-nums">
                    {formatCurrencyMx(Number(data.subtotal), { fractionDigits: 2 })}
                  </span>
                </div>
                <div className="flex justify-between gap-3 text-sm">
                  <span className="text-slate-500">IVA (16%)</span>
                  <span className="font-mono tabular-nums">
                    {formatCurrencyMx(Number(data.tax_amount), { fractionDigits: 2 })}
                  </span>
                </div>
                <div className="flex justify-between gap-3 border-t border-slate-200/70 pt-2 text-sm dark:border-white/[0.07]">
                  <span className="font-semibold">Total</span>
                  <span className="font-mono font-semibold tabular-nums">
                    {formatCurrencyMx(Number(data.total), { fractionDigits: 2 })}
                  </span>
                </div>
                <div className="grid grid-cols-2 gap-3 pt-1 sm:grid-cols-3">
                  <div>
                    <p className="text-[10px] font-semibold uppercase text-slate-500">Pagado</p>
                    <p className="font-mono text-sm tabular-nums">
                      {formatCurrencyMx(Number(data.paid_amount), { fractionDigits: 2 })}
                    </p>
                  </div>
                  <div>
                    <p className="text-[10px] font-semibold uppercase text-slate-500">
                      Saldo pendiente
                    </p>
                    <p className="font-mono text-sm tabular-nums">
                      {data.status === 'cancelled'
                        ? '$0.00'
                        : formatCurrencyMx(Number(data.balance), { fractionDigits: 2 })}
                    </p>
                  </div>
                  <div>
                    <p className="text-[10px] font-semibold uppercase text-slate-500">Vence</p>
                    <p className="text-sm">{formatDateEsMx(data.due_date)}</p>
                    {data.days_overdue > 0 ? (
                      <p className="text-xs font-medium text-red-500">
                        +{data.days_overdue}d vencido
                      </p>
                    ) : null}
                  </div>
                </div>
              </div>

              <div>
                <h3 className="mb-2 text-sm font-semibold">Historial de pagos</h3>
                {data.payments.length === 0 ? (
                  <p className="text-sm text-slate-500 dark:text-zinc-400">
                    Aún no hay pagos registrados.
                  </p>
                ) : (
                  <ul className="space-y-2">
                    {data.payments.map((p) => (
                      <li
                        key={p.id}
                        className="flex items-start justify-between gap-3 rounded-xl border border-slate-200/70 px-3 py-2 dark:border-white/[0.07]"
                      >
                        <div>
                          <div className="flex flex-wrap items-center gap-1.5">
                            <p
                              className={`font-mono text-sm tabular-nums ${p.is_reversed ? 'text-slate-400 line-through' : ''}`}
                            >
                              {formatCurrencyMx(Number(p.amount), { fractionDigits: 2 })}
                            </p>
                            {p.is_reversed ? (
                              <AppBadge
                                label="Revertido"
                                variant="neutral"
                                className="whitespace-nowrap shrink-0"
                              />
                            ) : null}
                          </div>
                          <p className="text-xs text-slate-500 dark:text-zinc-400">
                            {METHOD_LABEL[p.payment_method]} · {formatDateEsMx(p.payment_date)}
                            {p.reference ? ` · ${p.reference}` : ''}
                          </p>
                          {p.is_reversed && p.reversal_reason ? (
                            <p className="mt-0.5 text-xs text-slate-400">
                              Motivo: {p.reversal_reason}
                            </p>
                          ) : null}
                        </div>
                        {!p.is_reversed && canReverse && data.status !== 'cancelled' ? (
                          <AppButton
                            variant="ghost"
                            className="h-8 shrink-0 px-2 text-xs text-red-500"
                            onClick={() => {
                              setReverseTarget(p)
                              setReverseReason('')
                            }}
                          >
                            Revertir pago
                          </AppButton>
                        ) : null}
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            </div>
          ) : null}

          <AppModalFooter>
            <AppButton variant="ghost" onClick={() => onOpenChange(false)}>
              Cerrar
            </AppButton>
            {canRecordPayment && data && Number(data.balance) > 0 && data.status !== 'cancelled' ? (
              <AppButton
                variant="primary"
                onClick={() => {
                  onOpenChange(false)
                  onRecordPayment?.()
                }}
              >
                Registrar pago
              </AppButton>
            ) : null}
          </AppModalFooter>
        </AppModalContent>
      </AppModal>

      <AppModal
        open={reverseTarget !== null}
        onOpenChange={(next) => {
          if (!next) setReverseTarget(null)
        }}
      >
        <AppModalContent className="max-w-md">
          <AppModalHeader>
            <AppModalTitle>Revertir pago</AppModalTitle>
            <AppModalDescription>
              El pago no se elimina: se marca como revertido y se reabre el saldo del documento
              {reverseTarget
                ? ` (${formatCurrencyMx(Number(reverseTarget.amount), { fractionDigits: 2 })})`
                : ''}
              .
            </AppModalDescription>
          </AppModalHeader>
          <div className="space-y-2">
            <span className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-zinc-400">
              Motivo (obligatorio)
            </span>
            <AppTextarea
              value={reverseReason}
              onChange={(e) => setReverseReason(e.target.value)}
              rows={3}
              placeholder="Ej. Pago duplicado / error de captura"
            />
          </div>
          <AppModalFooter>
            <AppButton
              variant="ghost"
              disabled={reverseMutation.isPending}
              onClick={() => setReverseTarget(null)}
            >
              Cancelar
            </AppButton>
            <AppButton
              variant="danger"
              isLoading={reverseMutation.isPending}
              disabled={reverseReason.trim().length < 5}
              onClick={async () => {
                if (!reverseTarget) return
                try {
                  await reverseMutation.mutateAsync({
                    paymentId: reverseTarget.id,
                    reason: reverseReason.trim(),
                  })
                  toast.success('Pago revertido')
                  setReverseTarget(null)
                  void refetch()
                } catch (error) {
                  toast.apiError(error)
                }
              }}
            >
              Confirmar reversión
            </AppButton>
          </AppModalFooter>
        </AppModalContent>
      </AppModal>
    </>
  )
}
