import { PlugZap, Settings2 } from 'lucide-react'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppButton } from '@/components/ui/AppButton'
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

function formatCompactWhen(value: string | null): string {
  if (!value) return ''
  try {
    return formatDateTimeEsMx(value)
  } catch {
    return value
  }
}

/**
 * Fila compacta de servicio externo (estilo iOS Settings / Vercel Integrations).
 * El detalle completo de referencias del cofre, URLs y prueba vive en el `<AppDrawer />`.
 */
export function ExternalServiceCard({ service, canEdit, isTesting, onConfigure, onTest }: Props) {
  const meta = EXTERNAL_SERVICE_META[service.service_key]
  const Icon = meta.icon

  const hasSecrets = service.secret_refs.length > 0
  const allRefsConfigured = hasSecrets && service.secret_refs.every((ref) => ref.is_configured)
  const secretTooltip = hasSecrets
    ? service.secret_refs
        .map((ref) => `${ref.ref || ref.field}: ${ref.is_configured ? 'configurada' : 'pendiente en .env'}`)
        .join(' · ')
    : 'Credenciales administradas por Emisor en Configuración → Emisores'

  const testDotClass =
    service.last_test_status === 'ok'
      ? 'bg-emerald-500'
      : service.last_test_status === 'error'
        ? 'bg-red-500'
        : 'bg-slate-400 dark:bg-zinc-500'

  const testSummary =
    service.last_test_status === 'ok'
      ? `OK · ${formatCompactWhen(service.last_tested_at)}`
      : service.last_test_status === 'error'
        ? `Error · ${formatCompactWhen(service.last_tested_at)}`
        : 'Sin probar'

  return (
    <div className="flex flex-col gap-3 px-4 py-3.5 transition-colors hover:bg-slate-50/70 dark:hover:bg-white/[0.02] lg:flex-row lg:items-center lg:justify-between lg:gap-4">
      {/* 1. Identidad: ícono + nombre + descripción en 1 línea */}
      <div className="flex min-w-0 flex-1 items-center gap-3">
        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-blue-500/10 text-[#0066FF] dark:text-[#0A84FF]">
          <Icon className="h-4 w-4" />
        </span>
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <h3 className="truncate font-heading text-sm font-semibold tracking-[-0.2px] text-slate-900 dark:text-white">
              {meta.shortName}
            </h3>
            <span className="hidden truncate font-mono text-[11px] text-slate-400 dark:text-zinc-500 sm:inline">
              {service.service_key}
            </span>
          </div>
          <p
            className="truncate text-xs text-slate-500 dark:text-zinc-400"
            title={meta.description}
          >
            {meta.description}
          </p>
        </div>
      </div>

      {/* 2. Estado, modo y secretos + 3. Última prueba + 4. Acciones */}
      <div className="flex flex-wrap items-center justify-between gap-2.5 lg:shrink-0 lg:justify-end lg:gap-4">
        <div className="flex flex-wrap items-center gap-1.5">
          <AppBadge
            label={service.enabled ? 'Activo' : 'Inactivo'}
            tone={service.enabled ? 'success' : 'neutral'}
          />
          <AppBadge
            label={service.mode === 'production' ? 'Producción' : 'Sandbox'}
            tone={service.mode === 'production' ? 'info' : 'warning'}
          />
          <span title={secretTooltip} className="inline-flex">
            {hasSecrets ? (
              <AppBadge
                label={allRefsConfigured ? '.env OK' : 'Pendiente en .env'}
                tone={allRefsConfigured ? 'success' : 'warning'}
              />
            ) : (
              <AppBadge label="Por emisor" tone="neutral" />
            )}
          </span>
        </div>

        <div
          className="flex min-w-[148px] items-center gap-1.5 text-xs text-slate-500 dark:text-zinc-400"
          title={service.last_test_message ?? testSummary}
        >
          <span className={`h-1.5 w-1.5 shrink-0 rounded-full ${testDotClass}`} aria-hidden />
          <span className="truncate font-mono text-[11px] tabular-nums">{testSummary}</span>
        </div>

        <div className="flex items-center gap-2">
          <AppButton
            variant="outline"
            size="sm"
            disabled={!canEdit || isTesting}
            isLoading={isTesting}
            onClick={() => onTest(service)}
          >
            {!isTesting ? <PlugZap className="h-3.5 w-3.5" /> : null}
            Probar
          </AppButton>
          <AppButton size="sm" disabled={!canEdit} onClick={() => onConfigure(service)}>
            <Settings2 className="h-3.5 w-3.5" />
            Configurar
          </AppButton>
        </div>
      </div>
    </div>
  )
}
