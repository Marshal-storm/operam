import { Check, Copy, ExternalLink, Link2 } from 'lucide-react'
import { useState } from 'react'
import { AppButton } from '@/components/ui/AppButton'
import { AppInput } from '@/components/ui/AppInput'
import {
  AppModal,
  AppModalContent,
  AppModalDescription,
  AppModalFooter,
  AppModalHeader,
  AppModalTitle,
} from '@/components/ui/AppModal'
import { useToast } from '@/context/ToastContext'
import type { PortalMagicLink } from '@/services/clientContactsService'

type PortalLinkModalProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  link: PortalMagicLink | null
}

/**
 * Modal de la acción "Generar liga mágica de portal" (RF-7.1).
 * Muestra la URL de un solo uso con vigencia dinámica, copiar y abrir en pestaña nueva.
 */
export function PortalLinkModal({ open, onOpenChange, link }: PortalLinkModalProps) {
  const toast = useToast()
  const [copied, setCopied] = useState(false)

  async function copyLink() {
    if (!link) return
    try {
      await navigator.clipboard.writeText(link.url)
      setCopied(true)
      toast.success(
        'Liga copiada',
        'Puedes abrirla en otra pestaña normal o enviársela al cliente.',
      )
      window.setTimeout(() => setCopied(false), 2200)
    } catch (error) {
      toast.apiError(error)
    }
  }

  function openInNewTab() {
    if (!link) return
    window.open(link.url, '_blank', 'noopener,noreferrer')
  }

  const minutes = link
    ? (link.ttl_minutes ?? Math.max(1, Math.round(link.expires_in_seconds / 60)))
    : null

  return (
    <AppModal open={open} onOpenChange={onOpenChange}>
      <AppModalContent>
        <AppModalHeader>
          <AppModalTitle>Liga mágica de portal</AppModalTitle>
          <AppModalDescription>
            {link
              ? `Acceso sin contraseña para ${link.contact.name} (${link.contact.email}). También se envió por correo.`
              : 'Genera una liga de acceso para el contacto.'}
          </AppModalDescription>
        </AppModalHeader>

        <div className="space-y-3">
          <div className="flex items-start gap-2 rounded-2xl border border-amber-500/25 bg-amber-500/[0.08] p-3 text-xs text-amber-800 dark:border-amber-400/25 dark:bg-amber-400/[0.08] dark:text-amber-200">
            <Link2 className="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
            <p>
              {minutes !== null ? (
                <>
                  Cada liga es de un solo uso ({minutes} min). Si la abres en otra pestaña para probar,
                  quedará consumida y deberás generar una nueva para enviársela al cliente.
                </>
              ) : (
                <>
                  Cada liga es de un solo uso. Si la abres en otra pestaña para probar,
                  quedará consumida y deberás generar una nueva para enviársela al cliente.
                </>
              )}
            </p>
          </div>

          <AppInput readOnly value={link?.url ?? ''} className="font-mono text-xs" />
        </div>

        <AppModalFooter>
          <AppButton type="button" variant="outline" onClick={openInNewTab} disabled={!link}>
            <ExternalLink className="h-4 w-4" /> Abrir en pestaña nueva
          </AppButton>
          <AppButton type="button" onClick={() => void copyLink()} disabled={!link}>
            {copied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
            {copied ? 'Copiada' : 'Copiar liga'}
          </AppButton>
        </AppModalFooter>
      </AppModalContent>
    </AppModal>
  )
}
