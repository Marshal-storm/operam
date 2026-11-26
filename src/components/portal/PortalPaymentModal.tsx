import { zodResolver } from '@hookform/resolvers/zod'
import { CheckCircle2, CreditCard, ShieldCheck } from 'lucide-react'
import { useEffect, useMemo } from 'react'
import { Controller, useForm, useWatch } from 'react-hook-form'
import { useNavigate } from 'react-router-dom'
import {
  PORTAL_OPTION_CLASS,
  PORTAL_OPTION_SELECTED_CLASS,
  PORTAL_SHEET_CLASS,
} from '@/components/portal/portalTokens'
import { AppButton } from '@/components/ui/AppButton'
import { AppInput } from '@/components/ui/AppInput'
import {
  AppModal,
  AppModalContent,
  AppModalDescription,
  AppModalHeader,
  AppModalTitle,
} from '@/components/ui/AppModal'
import { useToast } from '@/context/ToastContext'
import { useCreatePortalPaymentLink } from '@/hooks/usePortalData'
import { compareMoney, minMoney } from '@/lib/moneyCents'
import { portalMoney } from '@/lib/portalLabels'
import { cn } from '@/lib/utils'
import {
  buildPortalPaymentSchema,
  cleanNumericInput,
  type PortalPaymentValues,
} from '@/lib/validationSchemas'
import type { PortalContract, PortalPaymentMode } from '@/types/portal'

type PortalPaymentModalProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  contract: PortalContract
}

type PaymentOption = {
  mode: PortalPaymentMode
  title: string
  hint: string
  amount: string | null
  disabled: boolean
}

const FIELD_LABEL =
  'text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500 dark:text-zinc-400'

/**
 * Hoja iOS "Pagar con tarjeta" (IntegroPAY): monto exigible, saldo total u otro monto parcial.
 * El monto final y los documentos los decide el servidor; aquí solo se valida con Zod antes del
 * HTTP y se redirige a la liga real (o al simulador sandbox `/portal/sandbox-pay/:id`).
 */
export function PortalPaymentModal({ open, onOpenChange, contract }: PortalPaymentModalProps) {
  const toast = useToast()
  const navigate = useNavigate()
  const createLink = useCreatePortalPaymentLink()

  const totalOpen = contract.open_balance_mxn

  const hasBalance = compareMoney(totalOpen, '0.00') > 0
  const exigibleAmount = minMoney(contract.exigible_amount_mxn, totalOpen)
  const hasExigible = compareMoney(exigibleAmount, '0.00') > 0
  const defaultMode: PortalPaymentMode = !contract.is_current && hasExigible ? 'exigible' : 'total'

  const schema = useMemo(() => buildPortalPaymentSchema(totalOpen), [totalOpen])
  const form = useForm<PortalPaymentValues>({
    resolver: zodResolver(schema),
    defaultValues: { mode: defaultMode, amount: '' },
  })
  const mode = useWatch({ control: form.control, name: 'mode' })
  const customAmount = useWatch({ control: form.control, name: 'amount' })

  useEffect(() => {
    if (open) form.reset({ mode: defaultMode, amount: '' })
  }, [open, defaultMode, form])

  const options: PaymentOption[] = [
    {
      mode: 'exigible',
      title: 'Pagar monto exigible',
      hint: contract.is_current ? 'Estás al corriente' : 'Lo que necesitas para estar al corriente',
      amount: hasExigible ? exigibleAmount : null,
      disabled: !hasExigible,
    },
    {
      mode: 'total',
      title: 'Pagar saldo total',
      hint: 'Liquida todos tus documentos pendientes',
      amount: totalOpen,
      disabled: !hasBalance,
    },
    {
      mode: 'custom',
      title: 'Otro monto (pago parcial)',
      hint: 'Se aplica primero a lo más antiguo',
      amount: null,
      disabled: !hasBalance,
    },
  ]

  const selectedAmount =
    mode === 'exigible'
      ? exigibleAmount
      : mode === 'total'
        ? totalOpen
        : cleanNumericInput(customAmount ?? '') || null

  async function onSubmit(values: PortalPaymentValues) {
    try {
      const link = await createLink.mutateAsync({
        contract_id: contract.id,
        mode: values.mode,
        amount: values.mode === 'custom' ? cleanNumericInput(values.amount) : undefined,
      })
      toast.success(
        'Liga de pago generada',
        link.is_sandbox
          ? 'Modo sandbox: simula el pago con tarjeta en un toque.'
          : 'Te llevamos a la pasarela segura de IntegroPAY.',
      )
      onOpenChange(false)
      if (link.is_sandbox) {
        navigate(`/portal/sandbox-pay/${encodeURIComponent(link.external_link_id)}`)
      } else {
        window.location.assign(link.url)
      }
    } catch (error) {
      toast.apiError(error)
    }
  }

  const amountError = form.formState.errors.amount?.message

  return (
    <AppModal open={open} onOpenChange={onOpenChange}>
      <AppModalContent className={PORTAL_SHEET_CLASS}>
        <div aria-hidden className="mx-auto -mt-1 h-1.5 w-10 rounded-full bg-slate-300 sm:hidden dark:bg-white/20" />
        <AppModalHeader>
          <AppModalTitle className="flex items-center gap-2">
            <CreditCard className="h-5 w-5 text-[#0066FF] dark:text-[#0A84FF]" aria-hidden />
            Pagar con tarjeta
          </AppModalTitle>
          <AppModalDescription>
            Contrato <span className="font-mono tabular-nums">{contract.folio}</span> · elige cuánto deseas pagar.
          </AppModalDescription>
        </AppModalHeader>

        {!hasBalance ? (
          <div className="flex flex-col items-center gap-3 py-6 text-center">
            <CheckCircle2 className="h-10 w-10 text-emerald-500" aria-hidden />
            <p className="text-sm text-slate-500 dark:text-zinc-400">
              No tienes documentos pendientes de pago en este contrato.
            </p>
          </div>
        ) : (
          <form className="space-y-4" onSubmit={form.handleSubmit(onSubmit)} noValidate>
            <Controller
              control={form.control}
              name="mode"
              render={({ field }) => (
                <div role="radiogroup" aria-label="Monto a pagar" className="space-y-2.5">
                  {options.map((option) => {
                    const selected = field.value === option.mode
                    return (
                      <AppButton
                        key={option.mode}
                        type="button"
                        variant="outline"
                        role="radio"
                        aria-checked={selected}
                        disabled={option.disabled}
                        onClick={() => field.onChange(option.mode)}
                        className={cn(PORTAL_OPTION_CLASS, selected && PORTAL_OPTION_SELECTED_CLASS)}
                      >
                        <span className="min-w-0">
                          <span className="block text-sm font-semibold">{option.title}</span>
                          <span className="block text-xs text-slate-500 dark:text-zinc-400">{option.hint}</span>
                        </span>
                        {option.amount ? (
                          <span className="shrink-0 font-mono text-base font-semibold tabular-nums">
                            {portalMoney(option.amount)}
                          </span>
                        ) : null}
                      </AppButton>
                    )
                  })}
                </div>
              )}
            />

            {mode === 'custom' ? (
              <div>
                <label htmlFor="portal-custom-amount" className={FIELD_LABEL}>
                  Monto a pagar (MXN)
                </label>
                <AppInput
                  id="portal-custom-amount"
                  withLabelSpacing
                  mono
                  numericMode="decimal"
                  autoComplete="off"
                  placeholder="0.00"
                  error={Boolean(amountError)}
                  className="h-12 rounded-2xl text-lg"
                  {...form.register('amount')}
                />
                <p className={cn('mt-1.5 text-xs', amountError ? 'text-red-500' : 'text-slate-500 dark:text-zinc-400')}>
                  {amountError ?? `Máximo ${portalMoney(totalOpen)}`}
                </p>
              </div>
            ) : null}

            <div className="space-y-2 rounded-2xl border border-slate-200/80 bg-slate-50/70 p-4 dark:border-white/[0.08] dark:bg-white/[0.04]">
              <div className="flex items-center justify-between text-sm">
                <span className="text-slate-500 dark:text-zinc-400">Saldo pendiente</span>
                <span className="font-mono tabular-nums">{portalMoney(totalOpen)}</span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-sm font-semibold">Total a pagar</span>
                <span className="font-mono text-2xl font-semibold tabular-nums">
                  {selectedAmount ? portalMoney(selectedAmount) : '—'}
                </span>
              </div>
            </div>

            <p className="flex items-start gap-2 text-xs text-slate-500 dark:text-zinc-400">
              <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0 text-emerald-500" aria-hidden />
              Pago seguro con IntegroPAY. Tu pago se confirma directamente con la pasarela y se
              aplica a tus documentos más antiguos primero.
            </p>

            <AppButton
              type="submit"
              isLoading={createLink.isPending}
              className="h-12 w-full rounded-2xl text-base font-semibold"
            >
              <CreditCard className="h-5 w-5" aria-hidden />
              Continuar al pago
            </AppButton>
          </form>
        )}
      </AppModalContent>
    </AppModal>
  )
}
