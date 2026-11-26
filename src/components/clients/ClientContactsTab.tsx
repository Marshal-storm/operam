import { zodResolver } from '@hookform/resolvers/zod'
import {
  Check,
  Copy,
  Link2,
  Mail,
  Pencil,
  Phone,
  Plus,
  Send,
  ShieldCheck,
  Trash2,
  UserRound,
} from 'lucide-react'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard } from '@/components/ui/AppCard'
import { AppDrawer } from '@/components/ui/AppDrawer'
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
import {
  AppActionsColumnHeader,
  AppRowActionsMenu,
} from '@/components/ui/AppRowActionsMenu'
import { AppSwitch } from '@/components/ui/AppSwitch'
import { AppTableHeadRow, AppTableWrapper } from '@/components/ui/AppTableWrapper'
import { Skeleton } from '@/components/ui/skeleton'
import { useToast } from '@/context/ToastContext'
import { useClientContacts } from '@/hooks/useClientContacts'
import { usePermissions } from '@/hooks/usePermissions'
import { cn } from '@/lib/utils'
import type {
  ClientContact,
  ClientContactRole,
  PortalMagicLink,
} from '@/services/clientContactsService'

const ROLE_LABELS: Record<ClientContactRole, string> = {
  billing: 'Facturación',
  admin: 'Administración',
  technical: 'Técnico',
}

const ROLE_TONES: Record<ClientContactRole, 'info' | 'neutral' | 'warning'> = {
  billing: 'info',
  admin: 'neutral',
  technical: 'warning',
}

const contactFormSchema = z.object({
  name: z.string().trim().min(2, 'Indica el nombre del contacto').max(140),
  email: z.string().trim().email('Correo electrónico inválido').max(160),
  phone: z.string().trim().max(30).optional(),
  role: z.enum(['billing', 'admin', 'technical']),
  receives_portal: z.boolean(),
  active: z.boolean(),
})

type ContactFormValues = z.infer<typeof contactFormSchema>

type ClientContactsTabProps = {
  clientId: number
  clientName: string
}

/**
 * Pestaña de Contactos dentro del AppDrawer xl de detalle del cliente (RF-1.5).
 * Incluye botón directo "Enviar liga" + copiado rápido para WhatsApp y acciones
 * administrativas limpias en AppRowActionsMenu.
 */
export function ClientContactsTab({ clientId, clientName }: ClientContactsTabProps) {
  const toast = useToast()
  const { canCreate, canEdit, canDelete } = usePermissions()
  const {
    contacts,
    isLoading,
    isApiError,
    errorMessage,
    refetch,
    createContact,
    updateContact,
    patchContact,
    generatePortalLink,
    deleteContact,
  } = useClientContacts(clientId)

  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<ClientContact | null>(null)
  const [deletingContact, setDeletingContact] = useState<ClientContact | null>(null)
  const [generatedLinks, setGeneratedLinks] = useState<Record<number, PortalMagicLink>>({})
  const [copiedContactId, setCopiedContactId] = useState<number | null>(null)

  const form = useForm<ContactFormValues>({
    resolver: zodResolver(contactFormSchema),
    defaultValues: {
      name: '',
      email: '',
      phone: '',
      role: 'admin',
      receives_portal: true,
      active: true,
    },
  })

  function openCreate() {
    if (!canCreate('clients')) return
    setEditing(null)
    form.reset({
      name: '',
      email: '',
      phone: '',
      role: 'admin',
      receives_portal: true,
      active: true,
    })
    setFormOpen(true)
  }

  function openEdit(contact: ClientContact) {
    if (!canEdit('clients')) return
    setEditing(contact)
    form.reset({
      name: contact.name,
      email: contact.email,
      phone: contact.phone ?? '',
      role: contact.role,
      receives_portal: contact.receives_portal,
      active: contact.active,
    })
    setFormOpen(true)
  }

  /** Acciones administrativas únicamente: Editar y Eliminar (con confirmación en AppModal). */
  function rowActions(contact: ClientContact) {
    return [
      {
        label: 'Editar',
        icon: <Pencil className="h-4 w-4" />,
        onClick: () => openEdit(contact),
        hidden: !canEdit('clients'),
      },
      {
        label: 'Eliminar',
        icon: <Trash2 className="h-4 w-4" />,
        variant: 'danger' as const,
        separatorBefore: true,
        onClick: () => setDeletingContact(contact),
        hidden: !canDelete('clients') || !contact.active,
      },
    ]
  }

  async function onSendPortalLink(contact: ClientContact, sendEmail = true) {
    if (!canEdit('clients')) return
    try {
      const link = await generatePortalLink({ id: contact.id, sendEmail })
      setGeneratedLinks((prev) => ({ ...prev, [contact.id]: link }))
      if (!sendEmail) {
        try {
          await navigator.clipboard.writeText(link.url)
          setCopiedContactId(contact.id)
          window.setTimeout(() => {
            setCopiedContactId((current) => (current === contact.id ? null : current))
          }, 2200)
        } catch {
          // Si falla el portapapeles, queda el botón Copiar disponible.
        }
        toast.success(
          'Liga generada y copiada (no se envió correo)',
          `${contact.name} · Vigente ${link.ttl_minutes} min`,
        )
        return
      }
      toast.success(
        'Liga de acceso enviada al correo del contacto',
        `${contact.name} (${contact.email}) · Vigente ${link.ttl_minutes} min`,
      )
    } catch (error) {
      toast.apiError(error)
    }
  }

  async function onCopyGeneratedLink(contactId: number) {
    const link = generatedLinks[contactId]
    if (!link) return
    try {
      await navigator.clipboard.writeText(link.url)
      setCopiedContactId(contactId)
      toast.success('Enlace copiado', 'Listo para compartir por WhatsApp.')
      window.setTimeout(() => {
        setCopiedContactId((current) => (current === contactId ? null : current))
      }, 2200)
    } catch (error) {
      toast.apiError(error)
    }
  }

  async function onToggleActive(contact: ClientContact, next: boolean) {
    if (!canEdit('clients')) return
    try {
      await patchContact({ id: contact.id, data: { active: next } })
      toast.success(
        next ? 'Contacto activado' : 'Contacto desactivado',
        contact.name,
      )
    } catch (error) {
      toast.apiError(error)
    }
  }

  async function onConfirmDelete() {
    if (!deletingContact || !canDelete('clients')) return
    try {
      await deleteContact(deletingContact.id)
      toast.success('Contacto eliminado', `${deletingContact.name} quedó inactivo.`)
      setDeletingContact(null)
    } catch (error) {
      toast.apiError(error)
    }
  }

  async function onSubmit(values: ContactFormValues) {
    const payload = {
      client_id: clientId,
      name: values.name.trim(),
      email: values.email.trim().toLowerCase(),
      phone: values.phone?.trim() || null,
      role: values.role,
      receives_portal: values.receives_portal,
      active: values.active,
    }
    try {
      if (editing) {
        await updateContact({ id: editing.id, data: payload })
        toast.success('Contacto actualizado', payload.name)
      } else {
        await createContact(payload)
        toast.success('Contacto agregado', payload.name)
      }
      setFormOpen(false)
    } catch (error) {
      toast.apiError(error)
    }
  }

  if (isApiError && !contacts.length) {
    return (
      <AppCard className="border-red-500/30 p-4">
        <p className="text-sm font-medium text-red-500">{errorMessage}</p>
        <AppButton size="sm" className="mt-3" onClick={() => void refetch()}>
          Reintentar
        </AppButton>
      </AppCard>
    )
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-xs text-slate-500 dark:text-zinc-400">
          Contactos autorizados para notificaciones, facturación y acceso directo al Portal Cliente.
        </p>
        {canCreate('clients') ? (
          <AppButton type="button" size="sm" onClick={openCreate}>
            <Plus className="h-3.5 w-3.5" /> Nuevo contacto
          </AppButton>
        ) : null}
      </div>

      <AppTableWrapper
        isLoading={isLoading}
        loadingState={
          <div className="space-y-2 p-3">
            <Skeleton className="h-12 w-full rounded-xl" />
            <Skeleton className="h-12 w-full rounded-xl" />
          </div>
        }
        isEmpty={!isLoading && contacts.length === 0}
        emptyState={
          <div className="flex flex-col items-center justify-center gap-2 px-4 py-12 text-center">
            <UserRound className="h-8 w-8 text-slate-300 dark:text-zinc-600" />
            <p className="font-heading text-sm font-semibold">
              Sin contactos registrados
            </p>
            <p className="max-w-sm text-xs text-slate-500 dark:text-zinc-400">
              Agrega contactos de {clientName} para gestionar áreas y acceso por Magic Links.
            </p>
          </div>
        }
      >
        {/* Vista amplia en tabla para Drawer xl (sin scroll horizontal ni textos aplastados) */}
        <div className="hidden md:block">
          <table className="w-full text-sm">
            <thead>
              <AppTableHeadRow>
                <th className="px-4 py-3 text-left font-medium">CONTACTO</th>
                <th className="px-3 py-3 text-left font-medium">ÁREA / ROL</th>
                <th className="px-3 py-3 text-left font-medium">PORTAL CLIENTE</th>
                <th className="px-3 py-3 text-left font-medium">ESTADO</th>
                <th className="px-4 py-3 text-right font-medium">
                  <AppActionsColumnHeader />
                </th>
              </AppTableHeadRow>
            </thead>
            <tbody>
              {contacts.map((contact) => {
                const canSendLink =
                  canEdit('clients') && contact.receives_portal && contact.active
                const hasGeneratedLink = Boolean(generatedLinks[contact.id])

                return (
                  <tr
                    key={contact.id}
                    className={cn(
                      'border-b border-slate-100 align-middle dark:border-white/[0.04]',
                      !contact.active && 'opacity-60',
                    )}
                  >
                    <td className="px-4 py-3">
                      <p className="font-medium text-slate-900 dark:text-white">
                        {contact.name}
                      </p>
                      <div className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-slate-500 dark:text-zinc-400">
                        <span className="inline-flex items-center gap-1 break-all">
                          <Mail className="h-3 w-3 shrink-0 opacity-75" />
                          {contact.email}
                        </span>
                        {contact.phone ? (
                          <span className="inline-flex items-center gap-1 font-mono tabular-nums">
                            <Phone className="h-3 w-3 shrink-0 opacity-75" />
                            {contact.phone}
                          </span>
                        ) : null}
                      </div>
                    </td>

                    <td className="px-3 py-3">
                      <AppBadge
                        label={ROLE_LABELS[contact.role]}
                        tone={ROLE_TONES[contact.role]}
                      />
                    </td>

                    <td className="px-3 py-3">
                      <AppBadge
                        label={contact.receives_portal ? 'Autorizado' : 'Sin portal'}
                        tone={contact.receives_portal ? 'success' : 'neutral'}
                      />
                    </td>

                    <td className="px-3 py-3">
                      <AppSwitch
                        checked={contact.active}
                        caption={contact.active ? 'Activo' : 'Inactivo'}
                        disabled={!canEdit('clients')}
                        onCheckedChange={(next) => void onToggleActive(contact, next)}
                      />
                    </td>

                    <td className="px-4 py-3 text-right">
                      <div className="flex items-center justify-end gap-1.5">
                        {canSendLink ? (
                          <>
                            <AppButton
                              type="button"
                              variant="secondary"
                              size="sm"
                              className="h-8 rounded-full px-3 text-xs"
                              onClick={() => void onSendPortalLink(contact, true)}
                            >
                              <Send className="h-3.5 w-3.5" />
                              Enviar liga
                            </AppButton>
                            <AppButton
                              type="button"
                              variant="ghost"
                              size="icon"
                              className="h-8 w-8 rounded-full"
                              title="Solo generar y copiar liga (sin correo)"
                              aria-label="Solo generar y copiar liga"
                              onClick={() => void onSendPortalLink(contact, false)}
                            >
                              <Link2 className="h-3.5 w-3.5" />
                            </AppButton>
                          </>
                        ) : null}

                        {hasGeneratedLink ? (
                          <AppButton
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="h-8 w-8 rounded-full"
                            title="Copiar enlace para WhatsApp"
                            aria-label="Copiar enlace para WhatsApp"
                            onClick={() => void onCopyGeneratedLink(contact.id)}
                          >
                            {copiedContactId === contact.id ? (
                              <Check className="h-3.5 w-3.5 text-emerald-500" />
                            ) : (
                              <Copy className="h-3.5 w-3.5" />
                            )}
                          </AppButton>
                        ) : null}

                        <AppRowActionsMenu
                          actions={rowActions(contact)}
                          contentClassName="z-[60]"
                        />
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>

        {/* Tarjetas responsivas para móvil (< md) */}
        <div className="space-y-2.5 p-3 md:hidden">
          {contacts.map((contact) => {
            const canSendLink =
              canEdit('clients') && contact.receives_portal && contact.active
            const hasGeneratedLink = Boolean(generatedLinks[contact.id])

            return (
              <AppCard
                key={contact.id}
                className={cn('p-3.5', !contact.active && 'opacity-65')}
              >
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="font-heading text-sm font-semibold">{contact.name}</p>
                    <p className="mt-0.5 flex items-center gap-1.5 break-all text-xs text-slate-500 dark:text-zinc-400">
                      <Mail className="h-3 w-3 shrink-0" /> {contact.email}
                    </p>
                    {contact.phone ? (
                      <p className="mt-0.5 flex items-center gap-1.5 font-mono text-xs tabular-nums text-slate-500 dark:text-zinc-400">
                        <Phone className="h-3 w-3 shrink-0" /> {contact.phone}
                      </p>
                    ) : null}
                  </div>
                  <AppRowActionsMenu
                    actions={rowActions(contact)}
                    contentClassName="z-[60]"
                  />
                </div>

                <div className="mt-2.5 flex flex-wrap gap-1.5">
                  <AppBadge
                    label={ROLE_LABELS[contact.role]}
                    tone={ROLE_TONES[contact.role]}
                  />
                  <AppBadge
                    label={contact.receives_portal ? 'Portal autorizado' : 'Sin portal'}
                    tone={contact.receives_portal ? 'success' : 'neutral'}
                  />
                </div>

                <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-slate-200/70 pt-2.5 dark:border-white/[0.06]">
                  <AppSwitch
                    checked={contact.active}
                    caption={contact.active ? 'Activo' : 'Inactivo'}
                    disabled={!canEdit('clients')}
                    onCheckedChange={(next) => void onToggleActive(contact, next)}
                  />

                  <div className="flex items-center gap-1.5">
                    {canSendLink ? (
                      <>
                        <AppButton
                          type="button"
                          variant="secondary"
                          size="sm"
                          className="h-8 rounded-full px-3 text-xs"
                          onClick={() => void onSendPortalLink(contact, true)}
                        >
                          <Send className="h-3.5 w-3.5" />
                          Enviar liga
                        </AppButton>
                        <AppButton
                          type="button"
                          variant="outline"
                          size="icon"
                          className="h-8 w-8 rounded-full"
                          title="Solo generar y copiar liga (sin correo)"
                          aria-label="Solo generar y copiar liga"
                          onClick={() => void onSendPortalLink(contact, false)}
                        >
                          <Link2 className="h-3.5 w-3.5" />
                        </AppButton>
                      </>
                    ) : null}

                    {hasGeneratedLink ? (
                      <AppButton
                        type="button"
                        variant="outline"
                        size="icon"
                        className="h-8 w-8 rounded-full"
                        title="Copiar enlace para WhatsApp"
                        aria-label="Copiar enlace para WhatsApp"
                        onClick={() => void onCopyGeneratedLink(contact.id)}
                      >
                        {copiedContactId === contact.id ? (
                          <Check className="h-3.5 w-3.5 text-emerald-500" />
                        ) : (
                          <Copy className="h-3.5 w-3.5" />
                        )}
                      </AppButton>
                    ) : null}
                  </div>
                </div>
              </AppCard>
            )
          })}
        </div>
      </AppTableWrapper>

      {/* Formulario de crear / editar contacto en AppDrawer md (§3.1) */}
      <AppDrawer
        open={formOpen}
        onClose={() => setFormOpen(false)}
        size="md"
        title={editing ? 'Editar contacto' : 'Nuevo contacto'}
        description={`Contacto de ${clientName} con permisos de área y Portal Cliente.`}
        footer={
          <>
            <AppButton
              type="button"
              variant="outline"
              onClick={() => setFormOpen(false)}
            >
              Cancelar
            </AppButton>
            <AppButton type="submit" form="client-contact-form">
              {editing ? 'Guardar cambios' : 'Agregar contacto'}
            </AppButton>
          </>
        }
      >
        <form
          id="client-contact-form"
          className="space-y-4"
          onSubmit={form.handleSubmit(onSubmit)}
        >
          <label className="block text-sm">
            Nombre completo
            <AppInput
              withLabelSpacing
              error={Boolean(form.formState.errors.name)}
              {...form.register('name')}
            />
            {form.formState.errors.name ? (
              <span className="mt-1 block text-xs text-red-500">
                {form.formState.errors.name.message}
              </span>
            ) : null}
          </label>

          <label className="block text-sm">
            Correo electrónico
            <AppInput
              type="email"
              withLabelSpacing
              error={Boolean(form.formState.errors.email)}
              {...form.register('email')}
            />
            {form.formState.errors.email ? (
              <span className="mt-1 block text-xs text-red-500">
                {form.formState.errors.email.message}
              </span>
            ) : null}
          </label>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label className="block text-sm">
              Teléfono (opcional)
              <AppInput withLabelSpacing {...form.register('phone')} />
            </label>

            <label className="block text-sm">
              Área / Rol
              <AppSelect
                withLabelSpacing
                value={form.watch('role')}
                onValueChange={(v) =>
                  form.setValue('role', v as ClientContactRole, {
                    shouldValidate: true,
                  })
                }
                options={[
                  { value: 'billing', label: 'Facturación' },
                  { value: 'admin', label: 'Administración' },
                  { value: 'technical', label: 'Técnico' },
                ]}
              />
            </label>
          </div>

          <div className="space-y-3 rounded-2xl border border-slate-200/80 p-3.5 dark:border-white/[0.08]">
            <div className="flex items-center justify-between gap-3">
              <div className="flex items-start gap-2">
                <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0 text-[#0066FF] dark:text-[#0A84FF]" />
                <div>
                  <p className="text-sm font-medium">
                    Autorizado para Portal Cliente / Magic Links
                  </p>
                  <p className="text-xs text-slate-500 dark:text-zinc-400">
                    Podrá recibir enlaces de acceso y gestionar compras o facturas.
                  </p>
                </div>
              </div>
              <AppSwitch
                checked={form.watch('receives_portal')}
                onCheckedChange={(next) =>
                  form.setValue('receives_portal', next, { shouldValidate: true })
                }
                label="Autorizado para Portal Cliente / Magic Links"
              />
            </div>

            <div className="flex items-center justify-between gap-3 border-t border-slate-200/70 pt-3 dark:border-white/[0.06]">
              <p className="text-sm font-medium">Contacto activo</p>
              <AppSwitch
                checked={form.watch('active')}
                onCheckedChange={(next) =>
                  form.setValue('active', next, { shouldValidate: true })
                }
                label="Contacto activo"
              />
            </div>
          </div>
        </form>
      </AppDrawer>

      {/* Confirmación destructiva de eliminación en AppModal (z-[70], encima del Drawer) */}
      <AppModal
        open={Boolean(deletingContact)}
        onOpenChange={(open) => {
          if (!open) setDeletingContact(null)
        }}
      >
        <AppModalContent className="sm:max-w-md">
          <AppModalHeader>
            <AppModalTitle>Eliminar contacto</AppModalTitle>
            <AppModalDescription>
              ¿Deseas desactivar a{' '}
              <span className="font-medium text-slate-900 dark:text-white">
                {deletingContact?.name}
              </span>{' '}
              ({deletingContact?.email})? Ya no podrá recibir ni usar ligas mágicas del portal.
            </AppModalDescription>
          </AppModalHeader>
          <AppModalFooter>
            <AppButton
              type="button"
              variant="outline"
              onClick={() => setDeletingContact(null)}
            >
              Cancelar
            </AppButton>
            <AppButton
              type="button"
              variant="danger"
              onClick={() => void onConfirmDelete()}
            >
              Eliminar contacto
            </AppButton>
          </AppModalFooter>
        </AppModalContent>
      </AppModal>
    </div>
  )
}
