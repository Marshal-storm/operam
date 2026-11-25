import { zodResolver } from '@hookform/resolvers/zod'
import { Mail, Pencil, Phone, Plus, ShieldCheck, UserRound } from 'lucide-react'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard } from '@/components/ui/AppCard'
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
import type { ClientContact, ClientContactRole } from '@/services/clientContactsService'

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
 * Pestaña de Contactos dentro del modal de detalle del cliente (RF-1.5).
 */
export function ClientContactsTab({ clientId, clientName }: ClientContactsTabProps) {
  const toast = useToast()
  const { canCreate, canEdit } = usePermissions()
  const {
    contacts,
    isLoading,
    isApiError,
    errorMessage,
    refetch,
    createContact,
    updateContact,
    patchContact,
  } = useClientContacts(clientId)

  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<ClientContact | null>(null)

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
          Contactos autorizados para notificaciones, facturación y Portal Cliente.
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
            <Skeleton className="h-10 w-full rounded-xl" />
            <Skeleton className="h-10 w-full rounded-xl" />
          </div>
        }
        isEmpty={!isLoading && contacts.length === 0}
        emptyState={
          <div className="flex flex-col items-center justify-center gap-2 px-4 py-10 text-center">
            <UserRound className="h-8 w-8 text-slate-300 dark:text-zinc-600" />
            <p className="font-heading text-sm font-semibold">
              Sin contactos registrados
            </p>
            <p className="max-w-sm text-xs text-slate-500 dark:text-zinc-400">
              Agrega contactos de {clientName} para gestionar roles y acceso por Magic Links.
            </p>
          </div>
        }
      >
        {/* Desktop table */}
        <div className="hidden overflow-x-auto sm:block">
          <table className="w-full text-sm">
            <thead>
              <AppTableHeadRow>
                <th className="px-3 py-2.5 font-medium">CONTACTO</th>
                <th className="px-3 py-2.5 font-medium">ROL</th>
                <th className="px-3 py-2.5 font-medium">PORTAL</th>
                <th className="px-3 py-2.5 font-medium">ESTADO</th>
                <th className="px-3 py-2.5 text-right font-medium">
                  <AppActionsColumnHeader />
                </th>
              </AppTableHeadRow>
            </thead>
            <tbody>
              {contacts.map((contact) => (
                <tr
                  key={contact.id}
                  className={cn(
                    'border-b border-slate-100 dark:border-white/[0.04]',
                    !contact.active && 'opacity-60',
                  )}
                >
                  <td className="px-3 py-2.5">
                    <p className="font-medium">{contact.name}</p>
                    <p className="text-xs text-slate-500 dark:text-zinc-400">
                      {contact.email}
                      {contact.phone ? ` · ${contact.phone}` : ''}
                    </p>
                  </td>
                  <td className="px-3 py-2.5">
                    <AppBadge
                      label={ROLE_LABELS[contact.role]}
                      tone={ROLE_TONES[contact.role]}
                    />
                  </td>
                  <td className="px-3 py-2.5">
                    <AppBadge
                      label={contact.receives_portal ? 'Portal / Magic Link' : 'Sin portal'}
                      tone={contact.receives_portal ? 'success' : 'neutral'}
                    />
                  </td>
                  <td className="px-3 py-2.5">
                    <AppSwitch
                      checked={contact.active}
                      caption={contact.active ? 'Activo' : 'Inactivo'}
                      disabled={!canEdit('clients')}
                      onCheckedChange={(next) => void onToggleActive(contact, next)}
                    />
                  </td>
                  <td className="px-3 py-2.5 text-right">
                    <div className="flex justify-end">
                      <AppRowActionsMenu
                        actions={[
                          {
                            label: 'Editar',
                            icon: <Pencil className="h-4 w-4" />,
                            onClick: () => openEdit(contact),
                            hidden: !canEdit('clients'),
                          },
                        ]}
                      />
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* Mobile cards UI-4 */}
        <div className="space-y-2.5 p-3 sm:hidden">
          {contacts.map((contact) => (
            <AppCard key={contact.id} className="p-3.5">
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <p className="font-heading text-sm font-semibold">{contact.name}</p>
                  <p className="mt-0.5 flex items-center gap-1.5 text-xs text-slate-500">
                    <Mail className="h-3 w-3 shrink-0" /> {contact.email}
                  </p>
                  {contact.phone ? (
                    <p className="mt-0.5 flex items-center gap-1.5 text-xs text-slate-500">
                      <Phone className="h-3 w-3 shrink-0" /> {contact.phone}
                    </p>
                  ) : null}
                </div>
                <AppRowActionsMenu
                  actions={[
                    {
                      label: 'Editar',
                      icon: <Pencil className="h-4 w-4" />,
                      onClick: () => openEdit(contact),
                      hidden: !canEdit('clients'),
                    },
                  ]}
                />
              </div>
              <div className="mt-2.5 flex flex-wrap gap-1.5">
                <AppBadge
                  label={ROLE_LABELS[contact.role]}
                  tone={ROLE_TONES[contact.role]}
                />
                <AppBadge
                  label={contact.receives_portal ? 'Portal / Magic Link' : 'Sin portal'}
                  tone={contact.receives_portal ? 'success' : 'neutral'}
                />
              </div>
              <div className="mt-2.5">
                <AppSwitch
                  checked={contact.active}
                  caption={contact.active ? 'Activo' : 'Inactivo'}
                  disabled={!canEdit('clients')}
                  onCheckedChange={(next) => void onToggleActive(contact, next)}
                />
              </div>
            </AppCard>
          ))}
        </div>
      </AppTableWrapper>

      <AppModal open={formOpen} onOpenChange={setFormOpen}>
        <AppModalContent>
          <AppModalHeader>
            <AppModalTitle>
              {editing ? 'Editar contacto' : 'Nuevo contacto'}
            </AppModalTitle>
            <AppModalDescription>
              Contacto de {clientName} con permisos de rol y Portal Cliente.
            </AppModalDescription>
          </AppModalHeader>

          <form className="space-y-3.5" onSubmit={form.handleSubmit(onSubmit)}>
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
                Rol
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

            <AppModalFooter>
              <AppButton
                type="button"
                variant="outline"
                onClick={() => setFormOpen(false)}
              >
                Cancelar
              </AppButton>
              <AppButton type="submit">
                {editing ? 'Guardar cambios' : 'Agregar contacto'}
              </AppButton>
            </AppModalFooter>
          </form>
        </AppModalContent>
      </AppModal>
    </div>
  )
}
