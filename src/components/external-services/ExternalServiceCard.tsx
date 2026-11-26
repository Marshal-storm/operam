import { PlugZap, Settings2 } from 'lucide-react'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard, AppCardContent, AppCardFooter } from '@/components/ui/AppCard'
import { formatDateTimeEsMx } from '@/lib/utils'
import type { ExternalService } from '@/types/externalServices'
import { EXTERNAL_SERVICE_META } from './externalServiceMeta'

type Props = {
  service: ExternalService
  canEdit: boolean
  isTesting: boolean
  onConfigure: (service: ExternalService) => void
  onTest: (service: ExternalService) => void
}

const LABEL_CLASS =
  'text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-zinc-400'

function formatWhen(value: string | null): string {
  if (!value) return 'Nunca'
  try {
    return formatDateTimeEsMx(value)
  } catch {
    return value
  }
}

/**
 * Tarjeta de un servicio externo: estado, modo, referencias de entorno y última prueba.
 */
export function ExternalServiceCard({ service, canEdit, isTesting, onConfigure, onTest }: Props) {
  const meta = EXTERNAL_SERVICE_META[service.service_key]
  const Icon = meta.icon
  const refsConfigured =
    service.secret_refs.length > 0 && service.secret_refs.every((ref) => ref.is_configured)
  const testTone =
    service.last_test_status === 'ok'
      ? 'success'
      : service.last_test_status === 'error'
        ? 'danger'
        : 'neutral'
  const testLabel =
    service.last_test_status === 'ok'
      ? 'Prueba correcta'
      : service.last_test_status === 'error'
        ? 'Prueba con error'
        : 'Sin probar'

  return (
    <AppCard className="flex h-full flex-col">
      <AppCardContent className="flex-1 space-y-4">
        <div className="flex items-start gap-3">
          <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-blue-500/10 text-[#0066FF] dark:text-[#0A84FF]">
            <Icon className="h-5 w-5" />
          </span>
          <div className="min-w-0">
            <h3 className="font-heading text-base font-semibold tracking-[-0.4px]">
              {meta.shortName}
            </h3>
            <p className="truncate text-xs text-slate-500 dark:text-zinc-400">{service.display_name}</p>
          </div>
        </div>

        <p className="text-sm text-slate-600 dark:text-zinc-300">{meta.description}</p>

        <div className="flex flex-wrap gap-1.5">
          <AppBadge
            label={service.enabled ? 'Activo' : 'Inactivo'}
            tone={service.enabled ? 'success' : 'neutral'}
          />
          <AppBadge
            label={service.mode === 'production' ? 'Producción' : 'Sandbox'}
            tone={service.mode === 'production' ? 'info' : 'warning'}
          />
          <AppBadge
            label={refsConfigured ? 'Referencia configurada' : 'Pendiente en .env'}
            tone={refsConfigured ? 'success' : 'warning'}
          />
        </div>

        <div className="space-y-1.5">
          <span className={LABEL_CLASS}>Referencias del cofre</span>
          <ul className="space-y-1">
            {service.secret_refs.map((ref) => (
              <li key={ref.field} className="flex items-center justify-between gap-2 text-xs">
                <span className="truncate font-mono text-slate-600 dark:text-zinc-300">
                  {ref.ref || '—'}
                </span>
                <AppBadge
                  label={ref.is_configured ? 'Configurada' : 'Sin definir en entorno'}
                  tone={ref.is_configured ? 'success' : 'neutral'}
                />
              </li>
            ))}
          </ul>
        </div>

        <div className="space-y-1.5">
          <span className={LABEL_CLASS}>Última prueba</span>
          <div className="flex flex-wrap items-center gap-2">
            <AppBadge label={testLabel} tone={testTone} />
            <span className="font-mono text-xs tabular-nums text-slate-500">
              {formatWhen(service.last_tested_at)}
            </span>
          </div>
          {service.last_test_message ? (
            <p className="line-clamp-2 text-xs text-slate-500 dark:text-zinc-400">
              {service.last_test_message}
            </p>
          ) : null}
        </div>
      </AppCardContent>

      <AppCardFooter className="flex-wrap justify-end">
        <AppButton
          variant="outline"
          size="sm"
          disabled={!canEdit || isTesting}
          isLoading={isTesting}
          onClick={() => onTest(service)}
        >
          {!isTesting ? <PlugZap className="h-3.5 w-3.5" /> : null}
          Probar conexión
        </AppButton>
        <AppButton size="sm" disabled={!canEdit} onClick={() => onConfigure(service)}>
          <Settings2 className="h-3.5 w-3.5" />
          Configurar
        </AppButton>
      </AppCardFooter>
    </AppCard>
  )
}
