import { Check, Copy, Landmark } from 'lucide-react'
import { useState } from 'react'
import { AppButton } from '@/components/ui/AppButton'
import {
  AppModal,
  AppModalContent,
  AppModalDescription,
  AppModalHeader,
  AppModalTitle,
} from '@/components/ui/AppModal'
import { useToast } from '@/context/ToastContext'
import { cn } from '@/lib/utils'
import { portalMoney } from '@/lib/portalLabels'
import type { PortalSpei } from '@/types/portal'

type PortalSpeiModalProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  spei: PortalSpei
  /** Folio del contrato: referencia por defecto si el emisor no definió una sugerida. */
  contractFolio: string
  /** Monto exigible para mostrar cuánto transferir. */
  amount: string | null
  /** Referencia obligatoria (p. ej. folio de la factura de una compra); sustituye la sugerida. */
  reference?: string
  description?: string
}

type CopyRowProps = { label: string; value: string; mono?: boolean }

/** Fila de dato con botón de copiar (≥44 px de área táctil). */
function CopyRow({ label, value, mono = false }: CopyRowProps) {
  const toast = useToast()
  const [copied, setCopied] = useState(false)

  async function copy() {
    try {
      await navigator.clipboard.writeText(value)
      setCopied(true)
      toast.success('Copiado', label)
      window.setTimeout(() => setCopied(false), 2000)
    } catch (error) {
      toast.apiError(error)
    }
  }

  return (
    <div className="flex items-center justify-between gap-3 rounded-2xl border border-slate-200/80 bg-slate-50/70 px-4 py-3 dark:border-white/[0.08] dark:bg-white/[0.04]">
      <div className="min-w-0">
        <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500 dark:text-zinc-400">
          {label}
        </p>
        <p
          className={cn(
            'mt-0.5 break-all text-base font-medium',
            mono && 'font-mono tabular-nums tracking-wide',
          )}
        >
          {value}
        </p>
      </div>
      <AppButton
        type="button"
        variant="ghost"
        size="icon"
        className="h-11 w-11 shrink-0 rounded-full"
        onClick={() => void copy()}
        aria-label={`Copiar ${label}`}
      >
        {copied ? <Check className="h-[18px] w-[18px] text-emerald-500" /> : <Copy className="h-[18px] w-[18px]" />}
      </AppButton>
    </div>
  )
}

/** Modal "Datos SPEI" con botones de copiar para banco, CLABE, beneficiario y referencia. */
export function PortalSpeiModal({
  open,
  onOpenChange,
  spei,
  contractFolio,
  amount,
  reference,
  description = 'Copia los datos desde la app de tu banco. Tu pago se refleja al conciliarse.',
}: PortalSpeiModalProps) {
  return (
    <AppModal open={open} onOpenChange={onOpenChange}>
      <AppModalContent className="max-h-[90vh] max-w-md overflow-y-auto rounded-[28px]">
        <AppModalHeader>
          <AppModalTitle>Pago por transferencia SPEI</AppModalTitle>
          <AppModalDescription>{description}</AppModalDescription>
        </AppModalHeader>

        {spei.is_configured && spei.clabe ? (
          <div className="space-y-2.5">
            {amount ? <CopyRow label="Monto a transferir" value={portalMoney(amount)} mono /> : null}
            {spei.bank_name ? <CopyRow label="Banco" value={spei.bank_name} /> : null}
            <CopyRow label="CLABE" value={spei.clabe} mono />
            {spei.account_holder ? <CopyRow label="Beneficiario" value={spei.account_holder} /> : null}
            <CopyRow label="Referencia" value={reference || spei.reference_hint || contractFolio} mono />
          </div>
        ) : (
          <div className="flex flex-col items-center gap-3 py-6 text-center">
            <span className="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-white/[0.06] dark:text-zinc-500">
              <Landmark className="h-6 w-6" aria-hidden />
            </span>
            <p className="text-sm text-slate-500 dark:text-zinc-400">
              Tu proveedor aún no publica los datos de transferencia. Contacta a tu ejecutivo de
              cuenta para recibirlos.
            </p>
          </div>
        )}
      </AppModalContent>
    </AppModal>
  )
}
