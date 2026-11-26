import {
  Check,
  Copy,
  ExternalLink,
  KeyRound,
  Link2,
  Send,
  UserRound,
} from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
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
import { AppSelect } from '@/components/ui/AppSelect'
import { Skeleton } from '@/components/ui/skeleton'
import { useToast } from '@/context/ToastContext'
import { useClientContacts } from '@/hooks/useClientContacts'
import type { PortalMagicLink } from '@/services/clientContactsService'
import { fetchPortalPublicConfig } from '@/services/portalService'
import type { Client } from '@/types/client'

const ROLE_LABELS: Record<'billing' | 'admin' | 'technical', string> = {
  billing: 'Facturación',
  admin: 'Administración',
  technical: 'Técnico',
}

type ClientMagicLinkModalProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  client: Client | null
  /** Abre el Drawer xl del cliente directamente en la pestaña de Contactos. */
  onManageContacts: (client: Client) => void
}

/**
 * Modal rápido para generar y enviar una liga mágica de Portal Cliente
 * directamente desde el listado de Clientes (RF-7.1).
 */
export function ClientMagicLinkModal({
  open,
  onOpenChange,
  client,
  onManageContacts,
}: ClientMagicLinkModalProps) {
  const toast = useToast()
  const { contacts, isLoading, generatePortalLink } = useClientContacts(
    open && client ? client.id : null,
  )

  const authorizedContacts = useMemo(
    () => contacts.filter((contact) => contact.active && contact.receives_portal),
    [contacts],
  )

  const [selectedContactId, setSelectedContactId] = useState('')
  const [generatedLink, setGeneratedLink] = useState<PortalMagicLink | null>(null)
  const [configuredTtlMinutes, setConfiguredTtlMinutes] = useState<number | null>(null)
  const [copied, setCopied] = useState(false)
  const [generatingMode, setGeneratingMode] = useState<'copy' | 'email' | null>(null)

  // Al abrir el modal consulta la vigencia vigente en MySQL para mostrarla antes de generar.
  useEffect(() => {
    if (!open) return
    let cancelled = false
    fetchPortalPublicConfig()
      .then((config) => {
        if (!cancelled && Number.isFinite(config.magic_link_ttl_minutes) && config.magic_link_ttl_minutes >= 1) {
          setConfiguredTtlMinutes(Math.trunc(config.magic_link_ttl_minutes))
        }
      })
      .catch(() => {
        // Si falla, el texto usa el fallback neutro sin quemar un número fijo.
      })
    return () => {
      cancelled = true
    }
  }, [open])

  // Resetea estado al abrir/cerrar o cambiar de cliente.
  useEffect(() => {
    if (!open) {
      setGeneratedLink(null)
      setCopied(false)
      setSelectedContactId('')
      setGeneratingMode(null)
    }
  }, [open, client?.id])

  // Preselecciona automáticamente el primer contacto autorizado disponible.
  useEffect(() => {
    if (!open) return
    if (authorizedContacts.length === 0) {
      setSelectedContactId('')
      return
    }
    const stillExists = authorizedContacts.some(
      (contact) => String(contact.id) === selectedContactId,
    )
    if (!stillExists) {
      setSelectedContactId(String(authorizedContacts[0].id))
    }
  }, [open, authorizedContacts, selectedContactId])

  const contactOptions = useMemo(
    () =>
      authorizedContacts.map((contact) => ({
        value: String(contact.id),
        label: `${contact.name} (${contact.email}) · ${ROLE_LABELS[contact.role]}`,
      })),
    [authorizedContacts],
  )

  async function handleGenerate(sendEmail: boolean) {
    if (!selectedContactId || generatingMode !== null) return
    setGeneratingMode(sendEmail ? 'email' : 'copy')
    try {
      const link = await generatePortalLink({
        id: Number(selectedContactId),
        sendEmail,
      })
      setGeneratedLink(link)

      let copiedAutomatically = false
      try {
        await navigator.clipboard.writeText(link.url)
        copiedAutomatically = true
        setCopied(true)
        window.setTimeout(() => setCopied(false), 2400)
      } catch {
        copiedAutomatically = false
      }

      if (sendEmail) {
        toast.success(
          'Liga generada y enviada al correo del contacto',
          copiedAutomatically
            ? `Enviada a ${link.contact.email} y copiada al portapapeles (${link.ttl_minutes} min).`
            : `Enviada a ${link.contact.email} (${link.ttl_minutes} min).`,
        )
      } else {
        toast.success(
          'Liga generada y copiada (no se envió correo)',
          copiedAutomatically
            ? `Lista para compartir manualmente con ${link.contact.name} (${link.ttl_minutes} min).`
            : `Generada para ${link.contact.name} (${link.ttl_minutes} min). Puedes copiarla desde aquí.`,
        )
      }
    } catch (error) {
      toast.apiError(error)
    } finally {
      setGeneratingMode(null)
    }
  }

  async function handleCopyUrl() {
    if (!generatedLink) return
    try {
      await navigator.clipboard.writeText(generatedLink.url)
      setCopied(true)
      toast.success(
        'Liga copiada',
        'Lista para pegarla por WhatsApp o abrirla en otra pestaña.',
      )
      window.setTimeout(() => setCopied(false), 2400)
    } catch (error) {
      toast.apiError(error)
    }
  }

  function handleOpenNewTab() {
    if (!generatedLink) return
    window.open(generatedLink.url, '_blank', 'noopener,noreferrer')
  }

  const ttlMinutes = generatedLink?.ttl_minutes ?? configuredTtlMinutes

  return (
    <AppModal open={open} onOpenChange={onOpenChange}>
      <AppModalContent className="sm:max-w-lg">
        <AppModalHeader>
          <AppModalTitle className="flex min-w-0 items-start gap-2">
            <KeyRound className="mt-1 h-4 w-4 shrink-0 text-[#0066FF] dark:text-[#0A84FF]" />
            <span className="min-w-0 break-words">
              Generar liga mágica — {client?.name ?? 'Cliente'}
            </span>
          </AppModalTitle>
          <AppModalDescription>
            Envía un acceso sin contraseña al Portal Cliente y obtén la URL para compartirla al instante.
          </AppModalDescription>
        </AppModalHeader>

        {isLoading ? (
          <div className="min-w-0 w-full space-y-3 py-2">
            <Skeleton className="h-10 w-full rounded-xl" />
            <Skeleton className="h-16 w-full rounded-2xl" />
          </div>
        ) : authorizedContacts.length === 0 ? (
          <div className="min-w-0 w-full space-y-4 py-1">
            <div className="flex min-w-0 w-full flex-col items-center gap-2 rounded-2xl border border-slate-200/80 bg-slate-50/80 px-5 py-6 text-center dark:border-white/[0.08] dark:bg-white/[0.03]">
              <UserRound className="h-9 w-9 text-slate-300 dark:text-zinc-600" />
              <p className="font-heading text-sm font-semibold text-slate-900 dark:text-white">
                Sin contactos autorizados para Portal Cliente
              </p>
              <p className="max-w-sm break-words text-xs text-slate-500 dark:text-zinc-400">
                Registra o habilita al menos un contacto activo con acceso al portal para poder generar ligas mágicas.
              </p>
            </div>

            <AppModalFooter className="flex-wrap justify-end gap-2">
              <AppButton
                type="button"
                variant="outline"
                onClick={() => onOpenChange(false)}
              >
                Cerrar
              </AppButton>
              {client ? (
                <AppButton
                  type="button"
                  onClick={() => {
                    onOpenChange(false)
                    onManageContacts(client)
                  }}
                >
                  Administrar contactos
                </AppButton>
              ) : null}
            </AppModalFooter>
          </div>
        ) : (
          <div className="min-w-0 w-full space-y-4">
            <div className="min-w-0 w-full">
              <label className="mb-1.5 block text-xs font-medium text-slate-600 dark:text-zinc-300">
                Contacto autorizado
              </label>
              <AppSelect
                value={selectedContactId}
                onValueChange={(value) => {
                  setSelectedContactId(value)
                  setGeneratedLink(null)
                  setCopied(false)
                }}
                options={contactOptions}
                placeholder="Selecciona un contacto"
              />
            </div>

            <div className="flex min-w-0 w-full items-start gap-2 rounded-2xl border border-amber-500/25 bg-amber-500/[0.08] p-3 text-xs text-amber-800 dark:border-amber-400/25 dark:bg-amber-400/[0.08] dark:text-amber-200">
              <Link2 className="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
              <p className="min-w-0 flex-1 break-words">
                {ttlMinutes != null ? (
                  <>
                    Cada liga es de <strong>un solo uso ({ttlMinutes} min)</strong>. Elige si deseas copiarla sin correo o enviarla automáticamente al contacto.
                  </>
                ) : (
                  <>
                    Cada liga es de <strong>un solo uso</strong> (vigencia configurada en Sistema). Elige si deseas solo copiarla o enviarla por correo.
                  </>
                )}
              </p>
            </div>

            {generatedLink ? (
              <div className="min-w-0 w-full space-y-2 rounded-2xl border border-emerald-500/25 bg-emerald-500/[0.06] p-3.5 dark:border-emerald-400/20 dark:bg-emerald-400/[0.06]">
                <p className="break-words text-xs font-medium text-emerald-700 dark:text-emerald-300">
                  URL generada para {generatedLink.contact.name} ·{' '}
                  {generatedLink.email_queued ? 'Correo encolado' : 'Sin envío de correo'}
                </p>
                <div className="flex min-w-0 w-full items-center gap-2">
                  <AppInput
                    readOnly
                    value={generatedLink.url}
                    className="min-w-0 flex-1 font-mono text-xs"
                  />
                  <AppButton
                    type="button"
                    size="sm"
                    variant="secondary"
                    className="shrink-0"
                    onClick={() => void handleCopyUrl()}
                    aria-label="Copiar liga"
                  >
                    {copied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
                    {copied ? 'Copiada' : 'Copiar'}
                  </AppButton>
                </div>
              </div>
            ) : null}

            <AppModalFooter className="flex-wrap justify-end gap-2">
              <AppButton
                type="button"
                variant="outline"
                onClick={() => onOpenChange(false)}
              >
                Cerrar
              </AppButton>
              {generatedLink ? (
                <AppButton
                  type="button"
                  variant="ghost"
                  onClick={handleOpenNewTab}
                >
                  <ExternalLink className="h-4 w-4" /> Abrir en pestaña
                </AppButton>
              ) : null}
              <AppButton
                type="button"
                variant="secondary"
                disabled={!selectedContactId || generatingMode !== null}
                isLoading={generatingMode === 'copy'}
                onClick={() => void handleGenerate(false)}
              >
                <Link2 className="h-4 w-4" />
                Solo generar
              </AppButton>
              <AppButton
                type="button"
                disabled={!selectedContactId || generatingMode !== null}
                isLoading={generatingMode === 'email'}
                onClick={() => void handleGenerate(true)}
              >
                <Send className="h-4 w-4" />
                Generar y enviar por correo
              </AppButton>
            </AppModalFooter>
          </div>
        )}
      </AppModalContent>
    </AppModal>
  )
}
