import { AlertTriangle, ArrowLeft, CheckCircle2, CreditCard, FlaskConical, Lock } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { PortalEmptyState, PortalScreenSkeleton } from '@/components/portal/PortalStates'
import { PORTAL_CARD_CLASS, PORTAL_HERO_DUE_CLASS } from '@/components/portal/portalTokens'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard } from '@/components/ui/AppCard'
import { useToast } from '@/context/ToastContext'
import { useConfirmPortalSandboxPayment, usePortalSandboxLink } from '@/hooks/usePortalData'
import { portalDate, portalMoney } from '@/lib/portalLabels'
import { cn } from '@/lib/utils'

/** Pausa antes de regresar al portal tras el pago (deja leer la confirmación). */
const REDIRECT_DELAY_MS = 1800

/**
 * Simulador de pasarela IntegroPAY (solo sandbox): hoja estilo Apple Pay que aprueba la liga
 * con el MISMO flujo del webhook verificado. Nunca disponible en producción (el backend responde
 * 403/404 si el emisor ya opera con credenciales productivas).
 */
export function PortalSandboxPayPage() {
  const { externalLinkId = '' } = useParams<{ externalLinkId: string }>()
  const navigate = useNavigate()
  const toast = useToast()
  const link = usePortalSandboxLink(externalLinkId)
  const confirm = useConfirmPortalSandboxPayment()
  const [approved, setApproved] = useState(false)
  const redirectTimer = useRef<number | null>(null)

  useEffect(
    () => () => {
      if (redirectTimer.current !== null) window.clearTimeout(redirectTimer.current)
    },
    [],
  )

  const returnPath = link.data?.purpose === 'purchase' ? '/portal' : '/portal/pagos'

  async function onApprove() {
    try {
      const result = await confirm.mutateAsync(externalLinkId)
      setApproved(true)
      toast.success(
        result.idempotent_replay ? 'Este pago ya estaba aplicado' : 'Pago aprobado',
        result.contract_reactivated
          ? 'Tu servicio se reactivó. ¡Gracias por ponerte al corriente!'
          : 'Aplicamos tu pago y actualizamos tu estado de cuenta.',
      )
      redirectTimer.current = window.setTimeout(() => navigate(returnPath, { replace: true }), REDIRECT_DELAY_MS)
    } catch (error) {
      toast.apiError(error)
    }
  }

  if (link.isLoading) return <PortalScreenSkeleton rows={0} />

  if (link.isError || !link.data) {
    return (
      <PortalEmptyState
        icon={AlertTriangle}
        title="Liga de pago no disponible"
        description={link.error instanceof Error ? link.error.message : 'La liga no existe o ya no es válida.'}
        action={
          <AppButton variant="outline" className="h-11 rounded-full px-5" onClick={() => navigate('/portal/pagos')}>
            <ArrowLeft className="h-4 w-4" /> Volver a Pagos
          </AppButton>
        }
      />
    )
  }

  const data = link.data
  const isPaid = approved || data.status === 'paid'
  const isPayable = data.status === 'created' && !approved

  return (
    <div className="mx-auto w-full max-w-md space-y-4">
      <div className="flex items-center justify-center gap-2">
        <FlaskConical className="h-4 w-4 text-amber-500" aria-hidden />
        <AppBadge tone="warning" className="whitespace-nowrap shrink-0" label="Modo sandbox · sin cargos reales" />
      </div>

      <AppCard className={cn(PORTAL_CARD_CLASS, 'overflow-hidden p-0')}>
        <div className={cn(PORTAL_HERO_DUE_CLASS, 'space-y-5 px-6 pb-7 pt-6')}>
          <div className="flex items-center justify-between">
            <span className="flex items-center gap-2 text-sm font-semibold">
              <CreditCard className="h-5 w-5" aria-hidden /> IntegroPAY
            </span>
            <span className="flex items-center gap-1 text-xs text-white/80">
              <Lock className="h-3.5 w-3.5" aria-hidden /> Pago seguro
            </span>
          </div>
          <div>
            <p className="text-xs uppercase tracking-[0.12em] text-white/75">Total a pagar</p>
            <p className="mt-1 font-mono text-4xl font-semibold tabular-nums">{portalMoney(data.amount)}</p>
            <p className="mt-1 text-xs text-white/75">{data.currency}</p>
          </div>
        </div>

        <dl className="divide-y divide-slate-200/70 px-6 dark:divide-white/[0.07]">
          <div className="flex items-start justify-between gap-4 py-3.5">
            <dt className="text-sm text-slate-500 dark:text-zinc-400">Concepto</dt>
            <dd className="text-right text-sm font-medium">{data.concept}</dd>
          </div>
          <div className="flex items-start justify-between gap-4 py-3.5">
            <dt className="text-sm text-slate-500 dark:text-zinc-400">Emisor</dt>
            <dd className="text-right text-sm font-medium">{data.issuer_name}</dd>
          </div>
          {data.contract_folio ? (
            <div className="flex items-start justify-between gap-4 py-3.5">
              <dt className="text-sm text-slate-500 dark:text-zinc-400">Contrato</dt>
              <dd className="font-mono text-sm tabular-nums">{data.contract_folio}</dd>
            </div>
          ) : null}
          {data.documents.length > 0 ? (
            <div className="space-y-1.5 py-3.5">
              <dt className="text-sm text-slate-500 dark:text-zinc-400">Documentos</dt>
              {data.documents.map((document) => (
                <dd key={document.folio} className="flex items-center justify-between gap-3 text-sm">
                  <span className="font-mono tabular-nums">{document.folio}</span>
                  <span className="font-mono tabular-nums">{portalMoney(document.amount)}</span>
                </dd>
              ))}
            </div>
          ) : null}
          <div className="flex items-start justify-between gap-4 py-3.5">
            <dt className="text-sm text-slate-500 dark:text-zinc-400">
              {isPaid ? 'Pagada' : 'Vigente hasta'}
            </dt>
            <dd className="text-right text-sm">{portalDate(isPaid ? (data.paid_at ?? new Date().toISOString()) : data.expires_at)}</dd>
          </div>
        </dl>

        <div className="space-y-3 px-6 pb-6 pt-2">
          {isPaid ? (
            <div role="status" className="flex flex-col items-center gap-2 py-3 text-center">
              <CheckCircle2 className="h-12 w-12 text-emerald-500" aria-hidden />
              <p className="font-heading text-lg font-semibold">Pago aprobado</p>
              <p className="text-sm text-slate-500 dark:text-zinc-400">
                {approved ? 'Te regresamos a tu portal en un momento…' : 'Esta liga ya fue pagada.'}
              </p>
            </div>
          ) : null}

          {!isPaid && !isPayable ? (
            <p role="alert" className="rounded-2xl bg-amber-500/10 px-4 py-3 text-center text-sm text-amber-700 dark:text-amber-300">
              {data.status === 'expired'
                ? 'Esta liga de pago venció. Genera una nueva desde Pagos.'
                : 'Esta liga de pago fue cancelada.'}
            </p>
          ) : null}

          {isPayable ? (
            <AppButton
              type="button"
              className="h-12 w-full rounded-2xl bg-slate-950 text-base font-semibold text-white hover:bg-slate-800 dark:bg-white dark:text-black dark:hover:bg-zinc-200"
              isLoading={confirm.isPending}
              onClick={() => void onApprove()}
            >
              <CreditCard className="h-5 w-5" aria-hidden /> Simular pago aprobado con tarjeta
            </AppButton>
          ) : null}

          <AppButton
            type="button"
            variant="ghost"
            className="h-11 w-full rounded-2xl"
            disabled={confirm.isPending}
            onClick={() => navigate(isPaid ? returnPath : data.purpose === 'purchase' ? '/portal/tienda' : '/portal/pagos')}
          >
            <ArrowLeft className="h-4 w-4" /> {isPaid ? 'Volver al portal' : 'Cancelar y volver'}
          </AppButton>
        </div>
      </AppCard>
    </div>
  )
}
