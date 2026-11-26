import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { z } from 'zod'
import { RequestImpactPreview } from '@/components/requests/RequestImpactPreview'
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
import { AppSwitch } from '@/components/ui/AppSwitch'
import { Skeleton } from '@/components/ui/skeleton'
import { useRequestDetail } from '@/hooks/useRequests'
import { PORTAL_REQUEST_INTENT_LABEL, SERVICE_REQUEST_TYPE_LABEL } from '@/lib/requestLabels'
import { cn, formatCurrencyMx, formatDateEsMx } from '@/lib/utils'
import type {
  ServiceRequestApproveInput,
  ServiceRequestListItem,
  ServiceRequestRejectInput,
  ServiceRequestStatus,
  ServiceRequestType,
} from '@/types/request'

const FIELD_LABEL =
  'mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-zinc-400'

const TYPE_LABEL: Record<ServiceRequestType, string> = SERVICE_REQUEST_TYPE_LABEL

const STATUS_TONE: Record<
  ServiceRequestStatus,
  'success' | 'warning' | 'danger' | 'info' | 'neutral'
> = {
  pending: 'warning',
  quoted: 'info',
  approved: 'success',
  rejected: 'danger',
  cancelled: 'neutral',
}

const STATUS_LABEL: Record<ServiceRequestStatus, string> = {
  pending: 'Pendiente',
  quoted: 'Cotizada',
  approved: 'Aprobada',
  rejected: 'Rechazada',
  cancelled: 'Cancelada',
}

const approveSchema = z.object({
  with_exception: z.boolean(),
  resolution_notes: z.string().max(2000).optional(),
})

const rejectSchema = z.object({
  resolution_notes: z
    .string()
    .trim()
    .min(5, 'Indica el motivo (mínimo 5 caracteres)')
    .max(2000),
})

type ApproveValues = z.infer<typeof approveSchema>
type RejectValues = z.infer<typeof rejectSchema>

export type RequestDetailMode = 'view' | 'approve' | 'reject' | 'quote'

export type RequestDetailModalProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  requestId: number | null
  mode: RequestDetailMode
  canEdit: boolean
  onApprove: (id: number, input: ServiceRequestApproveInput) => Promise<void>
  onReject: (id: number, input: ServiceRequestRejectInput) => Promise<void>
  onQuote?: (request: ServiceRequestListItem) => void
}

/**
 * Modal de detalle / aprobación / rechazo con caja UI-9 e excepción de cartera.
 */
export function RequestDetailModal({
  open,
  onOpenChange,
  requestId,
  mode,
  canEdit,
  onApprove,
  onReject,
  onQuote,
}: RequestDetailModalProps) {
  const detailQuery = useRequestDetail(open ? requestId : null)
  const request = detailQuery.data

  const hasOverdue =
    request != null &&
    (Number(request.contract_overdue_balance) > 0 || request.contract_days_overdue > 0)
  const noMrr = request?.request_type !== 'recurring_expansion'
  const actionable =
    request != null && (request.status === 'pending' || request.status === 'quoted')

  const approveForm = useForm<ApproveValues>({
    resolver: zodResolver(approveSchema),
    defaultValues: { with_exception: false, resolution_notes: '' },
  })
  const rejectForm = useForm<RejectValues>({
    resolver: zodResolver(rejectSchema),
    defaultValues: { resolution_notes: '' },
  })

  const withException = approveForm.watch('with_exception')

  useEffect(() => {
    if (!open) {
      approveForm.reset({ with_exception: false, resolution_notes: '' })
      rejectForm.reset({ resolution_notes: '' })
    }
  }, [open, approveForm, rejectForm])

  const handleApprove = approveForm.handleSubmit(async (values) => {
    if (!request) return
    if (hasOverdue) {
      if (!values.with_exception) {
        approveForm.setError('with_exception', {
          message: 'Activa la excepción de cartera para continuar',
        })
        return
      }
      if ((values.resolution_notes ?? '').trim().length < 5) {
        approveForm.setError('resolution_notes', {
          message: 'El motivo de excepción es obligatorio (mín. 5 caracteres)',
        })
        return
      }
    }
    await onApprove(request.id, {
      with_exception: hasOverdue ? values.with_exception : false,
      resolution_notes: values.resolution_notes?.trim() || undefined,
    })
    onOpenChange(false)
  })

  const handleReject = rejectForm.handleSubmit(async (values) => {
    if (!request) return
    await onReject(request.id, { resolution_notes: values.resolution_notes.trim() })
    onOpenChange(false)
  })

  const title =
    mode === 'approve'
      ? 'Aprobar solicitud'
      : mode === 'reject'
        ? 'Rechazar solicitud'
        : mode === 'quote'
          ? 'Cotizar solicitud'
          : 'Detalle de solicitud'

  return (
    <AppModal open={open} onOpenChange={onOpenChange}>
      <AppModalContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
        <AppModalHeader>
          <AppModalTitle>{title}</AppModalTitle>
          <AppModalDescription>
            {request
              ? `${request.folio} · ${request.client_name}`
              : 'Cargando detalle…'}
          </AppModalDescription>
        </AppModalHeader>

        {detailQuery.isLoading || !request ? (
          <div className="space-y-3 py-2">
            <Skeleton className="h-8 w-1/2" />
            <Skeleton className="h-24 w-full" />
            <Skeleton className="h-16 w-full" />
          </div>
        ) : (
          <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-2">
              <span className="font-mono text-lg font-semibold tabular-nums">
                {request.folio}
              </span>
              <AppBadge label={STATUS_LABEL[request.status]} tone={STATUS_TONE[request.status]} />
              <AppBadge label={TYPE_LABEL[request.request_type]} tone="info" />
              <AppBadge
                label={request.origin === 'portal' ? 'Portal Cliente' : 'Interna'}
                tone="neutral"
              />
              {request.portal_request_type ? (
                <AppBadge
                  label={PORTAL_REQUEST_INTENT_LABEL[request.portal_request_type]}
                  tone="neutral"
                />
              ) : null}
            </div>

            <div className="grid gap-3 rounded-2xl border border-slate-200/80 p-3 text-sm dark:border-white/[0.09] sm:grid-cols-2">
              <div>
                <p className="text-[10px] uppercase tracking-wide text-slate-400">Cliente</p>
                <p className="font-medium">{request.client_name}</p>
                <p className="font-mono text-xs tabular-nums text-slate-500">
                  {request.client_rfc || '—'}
                </p>
              </div>
              <div>
                <p className="text-[10px] uppercase tracking-wide text-slate-400">Contrato</p>
                <p className="font-mono font-medium tabular-nums">{request.contract_folio}</p>
                {request.contract_is_current ? (
                  <AppBadge label="Al corriente" tone="success" className="mt-1" />
                ) : (
                  <AppBadge
                    label={`Adeudo ${formatCurrencyMx(request.contract_overdue_balance, { fractionDigits: 2 })} · ${request.contract_days_overdue}d`}
                    tone="warning"
                    className="mt-1"
                  />
                )}
              </div>
              <div>
                <p className="text-[10px] uppercase tracking-wide text-slate-400">Segmento</p>
                <p>{request.segment_name || '—'}</p>
              </div>
              <div>
                <p className="text-[10px] uppercase tracking-wide text-slate-400">Solicitó</p>
                <p>{request.requested_by_name || '—'}</p>
                <p className="text-xs text-slate-500">
                  {formatDateEsMx(request.created_at)}
                </p>
              </div>
            </div>

            {request.notes ? (
              <div>
                <p className={FIELD_LABEL}>Descripción del pedido</p>
                <p className="text-sm text-slate-700 dark:text-zinc-200">{request.notes}</p>
              </div>
            ) : null}

            <div>
              <p className={FIELD_LABEL}>Desglose de servicios</p>
              <div className="overflow-hidden rounded-2xl border border-slate-200/80 dark:border-white/[0.09]">
                <table className="w-full text-sm">
                  <thead className="bg-slate-50/80 text-left text-[11px] uppercase tracking-wide text-slate-500 dark:bg-white/[0.04] dark:text-zinc-400">
                    <tr>
                      <th className="px-3 py-2">Servicio</th>
                      <th className="px-3 py-2">Tipo</th>
                      <th className="px-3 py-2 text-right">Cant.</th>
                      <th className="px-3 py-2 text-right">P. unit.</th>
                      <th className="px-3 py-2 text-right">Subtotal</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(request.items ?? []).map((item) => (
                      <tr
                        key={item.id}
                        className="border-t border-slate-100 dark:border-white/[0.05]"
                      >
                        <td className="px-3 py-2 font-medium">{item.service_name}</td>
                        <td className="px-3 py-2">
                          <AppBadge
                            label={
                              item.service_type === 'recurring'
                                ? 'Recurrente'
                                : item.service_type === 'consumption'
                                  ? 'Consumo'
                                  : 'Pago único'
                            }
                            tone="neutral"
                          />
                        </td>
                        <td className="px-3 py-2 text-right font-mono tabular-nums">
                          {item.quantity}
                        </td>
                        <td className="px-3 py-2 text-right font-mono tabular-nums">
                          {formatCurrencyMx(item.unit_price, { fractionDigits: 2 })}
                        </td>
                        <td className="px-3 py-2 text-right font-mono tabular-nums">
                          {formatCurrencyMx(item.subtotal, { fractionDigits: 2 })}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>

            {(mode === 'approve' || mode === 'view') && actionable ? (
              <RequestImpactPreview
                requestTotal={request.subtotal}
                mrrImpact={request.mrr_impact_mxn}
                noMrrImpact={noMrr}
              />
            ) : (
              <div className="flex flex-wrap gap-4 text-sm">
                <div>
                  <p className="text-[10px] uppercase text-slate-400">Total</p>
                  <p className="font-mono font-semibold tabular-nums">
                    {formatCurrencyMx(request.subtotal, { fractionDigits: 2 })}
                  </p>
                </div>
                <div>
                  <p className="text-[10px] uppercase text-slate-400">Impacto MRR</p>
                  <p className="font-mono font-semibold tabular-nums">
                    {noMrr
                      ? 'No suma al MRR'
                      : `+${formatCurrencyMx(request.mrr_impact_mxn, { fractionDigits: 2 })} / mes`}
                  </p>
                </div>
              </div>
            )}

            {mode === 'approve' && canEdit && actionable ? (
              <form className="space-y-3" onSubmit={handleApprove}>
                {hasOverdue ? (
                  <div
                    className={cn(
                      'space-y-3 rounded-2xl border p-3',
                      'border-amber-500/30 bg-amber-500/10',
                    )}
                  >
                    <p className="text-sm font-medium text-amber-800 dark:text-amber-300">
                      El contrato presenta saldo vencido. Para aprobar requiere excepción
                      autorizada.
                    </p>
                    <Controller
                      control={approveForm.control}
                      name="with_exception"
                      render={({ field }) => (
                        <div className="flex items-center gap-3">
                          <AppSwitch
                            checked={field.value}
                            onCheckedChange={field.onChange}
                          />
                          <span className="text-sm">Aprobar con excepción de cartera</span>
                        </div>
                      )}
                    />
                    {withException ? (
                      <Controller
                        control={approveForm.control}
                        name="resolution_notes"
                        render={({ field, fieldState }) => (
                          <div>
                            <label className={FIELD_LABEL}>Motivo de la excepción</label>
                            <AppTextarea
                              value={field.value ?? ''}
                              onChange={field.onChange}
                              error={Boolean(fieldState.error)}
                              placeholder="Describe la autorización (mín. 5 caracteres)"
                            />
                            {fieldState.error ? (
                              <p className="mt-1 text-xs text-red-500">
                                {fieldState.error.message}
                              </p>
                            ) : null}
                          </div>
                        )}
                      />
                    ) : null}
                  </div>
                ) : (
                  <Controller
                    control={approveForm.control}
                    name="resolution_notes"
                    render={({ field }) => (
                      <div>
                        <label className={FIELD_LABEL}>Notas de resolución (opcional)</label>
                        <AppTextarea
                          value={field.value ?? ''}
                          onChange={field.onChange}
                          placeholder="Comentario de aprobación"
                        />
                      </div>
                    )}
                  />
                )}
                <AppModalFooter>
                  <AppButton
                    type="button"
                    variant="ghost"
                    onClick={() => onOpenChange(false)}
                  >
                    Cancelar
                  </AppButton>
                  <AppButton
                    type="submit"
                    variant="primary"
                    isLoading={approveForm.formState.isSubmitting}
                  >
                    Confirmar aprobación
                  </AppButton>
                </AppModalFooter>
              </form>
            ) : null}

            {mode === 'reject' && canEdit && actionable ? (
              <form className="space-y-3" onSubmit={handleReject}>
                <Controller
                  control={rejectForm.control}
                  name="resolution_notes"
                  render={({ field, fieldState }) => (
                    <div>
                      <label className={FIELD_LABEL}>Motivo del rechazo</label>
                      <AppTextarea
                        value={field.value}
                        onChange={field.onChange}
                        error={Boolean(fieldState.error)}
                        placeholder="Explica por qué se rechaza (mín. 5 caracteres)"
                      />
                      {fieldState.error ? (
                        <p className="mt-1 text-xs text-red-500">
                          {fieldState.error.message}
                        </p>
                      ) : null}
                    </div>
                  )}
                />
                <AppModalFooter>
                  <AppButton
                    type="button"
                    variant="ghost"
                    onClick={() => onOpenChange(false)}
                  >
                    Cancelar
                  </AppButton>
                  <AppButton
                    type="submit"
                    variant="danger"
                    isLoading={rejectForm.formState.isSubmitting}
                  >
                    Confirmar rechazo
                  </AppButton>
                </AppModalFooter>
              </form>
            ) : null}

            {(mode === 'view' || mode === 'quote') && (
              <AppModalFooter>
                <AppButton type="button" variant="ghost" onClick={() => onOpenChange(false)}>
                  Cerrar
                </AppButton>
                {mode === 'quote' && canEdit && actionable && onQuote ? (
                  <AppButton
                    type="button"
                    variant="primary"
                    onClick={() => onQuote(request)}
                  >
                    Marcar como cotizada
                  </AppButton>
                ) : null}
              </AppModalFooter>
            )}

            {request.resolution_notes && mode === 'view' ? (
              <div>
                <p className={FIELD_LABEL}>Notas de resolución</p>
                <p className="text-sm text-slate-700 dark:text-zinc-200">
                  {request.resolution_notes}
                </p>
              </div>
            ) : null}
          </div>
        )}
      </AppModalContent>
    </AppModal>
  )
}
