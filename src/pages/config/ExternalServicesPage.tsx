import { Info, Plug } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { ExternalServiceCard } from '@/components/external-services/ExternalServiceCard'
import { ExternalServiceConfigModal } from '@/components/external-services/ExternalServiceConfigModal'
import { EXTERNAL_SERVICE_META } from '@/components/external-services/externalServiceMeta'
import { IncomingEventsTab } from '@/components/external-services/IncomingEventsTab'
import { OutboxMonitorTab } from '@/components/external-services/OutboxMonitorTab'
import { ScheduledJobsTab } from '@/components/external-services/ScheduledJobsTab'
import { AppCard } from '@/components/ui/AppCard'
import { AppTabs } from '@/components/ui/AppTabs'
import { Skeleton } from '@/components/ui/skeleton'
import { useToast } from '@/context/ToastContext'
import {
  useExternalServiceMutations,
  useExternalServicesList,
  useOutboxMessages,
} from '@/hooks/useExternalServices'
import { usePermissions } from '@/hooks/usePermissions'
import type { ExternalService, ExternalServiceWriteInput } from '@/types/externalServices'

type TabId = 'services' | 'jobs' | 'outbox' | 'webhooks'

const MODULE_KEY = 'config_external_services'

/**
 * Configuración → Servicios externos (RNF-8): servicios globales, monitor del outbox y webhooks.
 * Las escrituras exigen `config_external_services.edit` y alcance de segmento "Todos".
 */
export function ExternalServicesPage() {
  const toast = useToast()
  const { canEdit, isSegmentLocked, segmentBadgeLabel } = usePermissions()
  const canOperate = canEdit(MODULE_KEY) && !isSegmentLocked

  const [tab, setTab] = useState<TabId>('services')
  const [editing, setEditing] = useState<ExternalService | null>(null)
  const [testingKey, setTestingKey] = useState<string | null>(null)

  const servicesQuery = useExternalServicesList()
  const outboxSummary = useOutboxMessages({
    page: 1,
    perPage: 1,
    status: '',
    destination: '',
    search: '',
  })
  const { updateMutation, testMutation } = useExternalServiceMutations()

  const services = servicesQuery.data ?? []
  const failedCount = outboxSummary.data?.kpis.failed ?? 0
  const exhaustedCount = outboxSummary.data?.kpis.exhausted ?? 0

  async function handleSave(input: ExternalServiceWriteInput) {
    if (!editing) return
    try {
      await updateMutation.mutateAsync({ key: editing.service_key, input })
      toast.success(
        'Servicio actualizado',
        `${EXTERNAL_SERVICE_META[editing.service_key].shortName} se guardó correctamente.`,
      )
      setEditing(null)
    } catch (error) {
      toast.apiError(error)
    }
  }

  async function handleTest(service: ExternalService) {
    setTestingKey(service.service_key)
    try {
      const result = await testMutation.mutateAsync(service.service_key)
      const name = EXTERNAL_SERVICE_META[service.service_key].shortName
      if (result.test.status === 'ok') {
        toast.success(`${name}: conexión verificada`, result.test.message)
      } else {
        toast.warning(`${name}: la prueba falló`, result.test.message)
      }
    } catch (error) {
      toast.apiError(error)
    } finally {
      setTestingKey(null)
    }
  }

  return (
    <div className="space-y-5">
      <header className="flex items-start gap-3">
        <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-blue-500/10 text-[#0066FF] dark:text-[#0A84FF]">
          <Plug className="h-5 w-5" />
        </span>
        <div>
          <h1 className="font-heading text-2xl font-semibold md:text-3xl">Servicios externos</h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Integraciones globales, jobs programados de Google Cloud, cola de envíos (outbox) y
            webhooks recibidos. Sin secretos en texto plano.
          </p>
        </div>
      </header>

      <AppTabs
        aria-label="Secciones de servicios externos"
        value={tab}
        onChange={(id) => setTab(id as TabId)}
        items={[
          { id: 'services', label: 'Servicios externos' },
          { id: 'jobs', label: 'Jobs programados' },
          {
            id: 'outbox',
            label: 'Monitor Outbox',
            badge: failedCount > 0 ? failedCount : undefined,
            badgeTone: exhaustedCount > 0 ? 'danger' : 'warning',
          },
          {
            id: 'webhooks',
            label: 'Eventos entrantes (Webhooks)',
            disabled: isSegmentLocked,
          },
        ]}
      />

      {isSegmentLocked ? (
        <AppCard className="flex items-start gap-2 p-4 text-sm text-slate-600 dark:text-zinc-300">
          <Info className="mt-0.5 h-4 w-4 shrink-0 text-[#0066FF] dark:text-[#0A84FF]" />
          <span>
            {segmentBadgeLabel}. La configuración, el procesamiento de la cola y los webhooks se
            administran con alcance &quot;Todos&quot;; aquí solo ves los mensajes de tu segmento.
          </span>
        </AppCard>
      ) : null}

      {tab === 'services' ? (
        <div className="space-y-3">
          <AppCard className="flex items-center gap-2 px-3.5 py-2.5 text-xs text-slate-600 dark:text-zinc-300">
            <Info className="h-4 w-4 shrink-0 text-[#0066FF] dark:text-[#0A84FF]" />
            <span>
              El ERP de cada Emisor y la pasarela IntegroPAY se administran por emisor en{' '}
              <Link
                to="/configuracion/emisores"
                className="font-medium text-[#0066FF] hover:underline dark:text-[#0A84FF]"
              >
                Configuración → Emisores
              </Link>
              . Aquí solo viven los servicios globales.
            </span>
          </AppCard>

          {servicesQuery.isLoading ? (
            <AppCard className="divide-y divide-slate-200/70 overflow-hidden p-0 dark:divide-white/[0.06]">
              {Array.from({ length: 6 }).map((_, index) => (
                <div key={index} className="px-4 py-3.5">
                  <Skeleton className="h-10 w-full rounded-xl" />
                </div>
              ))}
            </AppCard>
          ) : servicesQuery.isError ? (
            <AppCard className="p-6 text-center text-sm text-slate-500">
              No se pudo cargar el catálogo de servicios externos.
            </AppCard>
          ) : services.length === 0 ? (
            <AppCard className="p-6 text-center text-sm text-slate-500">
              No hay servicios registrados. Ejecuta el seeder ExternalServicesSeeder.
            </AppCard>
          ) : (
            <AppCard className="divide-y divide-slate-200/70 overflow-hidden p-0 dark:divide-white/[0.06]">
              {services.map((service) => (
                <ExternalServiceCard
                  key={service.service_key}
                  service={service}
                  canEdit={canOperate}
                  isTesting={testingKey === service.service_key}
                  onConfigure={setEditing}
                  onTest={(target) => void handleTest(target)}
                />
              ))}
            </AppCard>
          )}
        </div>
      ) : null}

      {tab === 'jobs' ? <ScheduledJobsTab canOperate={canOperate} /> : null}

      {tab === 'outbox' ? <OutboxMonitorTab canOperate={canOperate} /> : null}

      {tab === 'webhooks' && !isSegmentLocked ? <IncomingEventsTab /> : null}

      <ExternalServiceConfigModal
        open={editing != null}
        onOpenChange={(open) => {
          if (!open) setEditing(null)
        }}
        service={editing}
        isSaving={updateMutation.isPending}
        onSubmit={handleSave}
      />
    </div>
  )
}
