import { zodResolver } from '@hookform/resolvers/zod'
import { ShieldCheck } from 'lucide-react'
import { useEffect, useMemo } from 'react'
import { Controller, useForm, type Resolver } from 'react-hook-form'
import { AppButton } from '@/components/ui/AppButton'
import { AppDrawer } from '@/components/ui/AppDrawer'
import { AppInput } from '@/components/ui/AppInput'
import { AppSelect } from '@/components/ui/AppSelect'
import { AppSwitch } from '@/components/ui/AppSwitch'
import { cn } from '@/lib/utils'
import type { ExternalService, ExternalServiceWriteInput } from '@/types/externalServices'
import {
  buildExternalServiceSchema,
  EXTERNAL_SERVICE_META,
  type ConfigFieldDef,
  type ExternalServiceFormValues,
} from './externalServiceMeta'

const FIELD_LABEL =
  'mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-slate-500'

const MODE_OPTIONS = [
  { value: 'sandbox', label: 'Sandbox (simulación)' },
  { value: 'production', label: 'Producción' },
]

type Props = {
  open: boolean
  onOpenChange: (open: boolean) => void
  service: ExternalService | null
  isSaving: boolean
  onSubmit: (input: ExternalServiceWriteInput) => Promise<void> | void
}

function toFormValues(service: ExternalService): ExternalServiceFormValues {
  const meta = EXTERNAL_SERVICE_META[service.service_key]
  const config: Record<string, string> = {}
  for (const field of meta.fields) {
    const raw = service.config[field.key]
    config[field.key] = raw === null || raw === undefined ? '' : String(raw)
  }
  const secretRefs: Record<string, string> = {}
  for (const secret of meta.secrets) {
    secretRefs[secret.key] = service.secret_refs.find((r) => r.field === secret.key)?.ref ?? ''
  }
  return {
    display_name: service.display_name,
    enabled: service.enabled,
    mode: service.mode,
    config,
    secret_refs: secretRefs,
  }
}

/**
 * Modal de configuración de un servicio externo (RHF + Zod).
 * RNF-8: los secretos se capturan solo como nombre de variable del cofre / `.env`.
 */
export function ExternalServiceConfigModal({
  open,
  onOpenChange,
  service,
  isSaving,
  onSubmit,
}: Props) {
  const serviceKey = service?.service_key ?? 'smtp'
  const meta = EXTERNAL_SERVICE_META[serviceKey]
  const schema = useMemo(() => buildExternalServiceSchema(serviceKey), [serviceKey])

  const form = useForm<ExternalServiceFormValues>({
    resolver: zodResolver(schema) as Resolver<ExternalServiceFormValues>,
    defaultValues: {
      display_name: '',
      enabled: false,
      mode: 'sandbox',
      config: {},
      secret_refs: {},
    },
  })

  useEffect(() => {
    if (open && service) form.reset(toFormValues(service))
  }, [open, service, form])

  const errors = form.formState.errors

  const handleSubmit = form.handleSubmit(async (values) => {
    const config: Record<string, string | number> = {}
    for (const field of meta.fields) {
      const value = (values.config[field.key] ?? '').trim()
      config[field.key] = field.kind === 'int' ? Number(value) : value
    }
    const secretRefs: Record<string, string> = {}
    for (const secret of meta.secrets) {
      secretRefs[secret.key] = (values.secret_refs[secret.key] ?? '').trim()
    }
    await onSubmit({
      display_name: values.display_name.trim(),
      enabled: values.enabled,
      mode: values.mode,
      config,
      secret_refs: secretRefs,
    })
  })

  const renderField = (field: ConfigFieldDef) => {
    const name = `config.${field.key}` as const
    const message = errors.config?.[field.key]?.message
    return (
      <div key={field.key} className={cn('min-w-0', field.wide && 'sm:col-span-2')}>
        <span className={FIELD_LABEL}>{field.label}</span>
        {field.kind === 'select' ? (
          <Controller
            control={form.control}
            name={name}
            render={({ field: ctrl }) => (
              <AppSelect
                value={ctrl.value || undefined}
                onValueChange={ctrl.onChange}
                options={field.options ?? []}
                error={Boolean(message)}
                aria-label={field.label}
              />
            )}
          />
        ) : (
          <AppInput
            withLabelSpacing
            mono={field.kind === 'int' || field.kind === 'time' || field.kind === 'url'}
            numericMode={field.kind === 'int' ? 'integer' : undefined}
            type={field.kind === 'email' ? 'email' : 'text'}
            placeholder={field.placeholder}
            error={Boolean(message)}
            {...form.register(name)}
          />
        )}
        {message ? <p className="mt-1 text-xs text-red-500">{message}</p> : null}
      </div>
    )
  }

  return (
    <AppDrawer
      open={open}
      onClose={() => onOpenChange(false)}
      size="lg"
      title={`Configurar ${meta.shortName}`}
      description={meta.description}
      footer={
        <>
          <AppButton
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={isSaving}
          >
            Cancelar
          </AppButton>
          <AppButton type="submit" form="external-service-config-form" isLoading={isSaving}>
            Guardar cambios
          </AppButton>
        </>
      }
    >
      <form
        id="external-service-config-form"
        className="min-w-0 space-y-4"
        onSubmit={handleSubmit}
      >
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="min-w-0 sm:col-span-2">
            <span className={FIELD_LABEL}>Nombre visible</span>
            <AppInput
              withLabelSpacing
              error={Boolean(errors.display_name)}
              {...form.register('display_name')}
            />
            {errors.display_name ? (
              <p className="mt-1 text-xs text-red-500">{errors.display_name.message}</p>
            ) : null}
          </div>

          <div className="min-w-0">
            <span className={FIELD_LABEL}>Modo</span>
            <Controller
              control={form.control}
              name="mode"
              render={({ field }) => (
                <AppSelect
                  value={field.value}
                  onValueChange={field.onChange}
                  options={MODE_OPTIONS}
                  aria-label="Modo"
                />
              )}
            />
          </div>

          <div className="min-w-0">
            <span className={FIELD_LABEL}>Estado</span>
            <Controller
              control={form.control}
              name="enabled"
              render={({ field }) => (
                <div className="flex h-10 items-center">
                  <AppSwitch
                    checked={field.value}
                    onCheckedChange={field.onChange}
                    caption={field.value ? 'Activo' : 'Inactivo'}
                    label="Servicio activo"
                  />
                </div>
              )}
            />
          </div>

          {meta.fields.map(renderField)}
        </div>

        <div className="min-w-0 space-y-3 rounded-2xl border border-slate-200/80 bg-slate-50/60 p-4 dark:border-white/[0.08] dark:bg-white/[0.03]">
          <div className="flex items-start gap-2">
            <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0 text-[#10B981]" />
            <p className="text-xs text-slate-600 dark:text-zinc-300">
              {meta.secrets.length > 0
                ? 'Por seguridad, aquí solo se guarda el nombre de la variable del cofre de secretos (Secret Manager / .env), nunca la contraseña o token en texto plano.'
                : 'Este servicio no tiene secretos globales: sus credenciales se configuran como referencias del cofre en cada Emisor.'}
            </p>
          </div>
          {meta.secrets.map((secret) => {
            const message = errors.secret_refs?.[secret.key]?.message
            return (
              <div key={secret.key} className="min-w-0">
                <span className={FIELD_LABEL}>{secret.label}</span>
                <AppInput
                  withLabelSpacing
                  mono
                  autoComplete="off"
                  spellCheck={false}
                  placeholder={secret.placeholder}
                  error={Boolean(message)}
                  {...form.register(`secret_refs.${secret.key}`)}
                />
                {message ? <p className="mt-1 text-xs text-red-500">{message}</p> : null}
              </div>
            )
          })}
        </div>
      </form>
    </AppDrawer>
  )
}
