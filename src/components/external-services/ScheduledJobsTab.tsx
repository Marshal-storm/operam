import { Clock, KeyRound, PlayCircle } from 'lucide-react'
import { useState } from 'react'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard } from '@/components/ui/AppCard'
import {
  AppModal,
  AppModalContent,
  AppModalDescription,
  AppModalFooter,
  AppModalHeader,
  AppModalTitle,
} from '@/components/ui/AppModal'
import { AppRowActionsMenu, type RowActionItem } from '@/components/ui/AppRowActionsMenu'
import { AppTableHeadRow, AppTableWrapper } from '@/components/ui/AppTableWrapper'
import { PaginationBar } from '@/components/ui/PaginationBar'
import { Skeleton } from '@/components/ui/skeleton'
import { useScreenPreferences } from '@/context/ScreenPreferencesContext'
import { useToast } from '@/context/ToastContext'
import { useExternalServicesList, useJobRuns, useRunJobMutation } from '@/hooks/useExternalServices'
import { formatDateTimeEsMx } from '@/lib/utils'
import type { JobName, JobRun } from '@/types/externalServices'
import { JsonPayloadModal } from './JsonPayloadModal'
import {
  describeJobRun,
  formatDuration,
  JOB_META,
  JOB_ORDER,
  JOB_STATUS_META,
  JOB_TRIGGER_LABELS,
} from './jobMeta'

type Props = {
  /** `config_external_services.edit` + alcance de segmento "Todos". */
  canOperate: boolean
}

function formatWhen(value: string | null): string {
  if (!value) return '—'
  try {
    return formatDateTimeEsMx(value)
  } catch {
    return value
  }
}

function StatusBadge({ run }: { run: JobRun }) {
  const meta = JOB_STATUS_META[run.status]
  return <AppBadge label={meta.label} tone={meta.tone} />
}

/**
 * Jobs programados de Google Cloud: última corrida por job, disparo manual y bitácora `job_runs`.
 */
export function ScheduledJobsTab({ canOperate }: Props) {
  const toast = useToast()
  const { pageSize: preferredSize, rowPaddingClass, setPageSize } = useScreenPreferences()
  const [page, setPage] = useState(1)
  const [pageSize, setLocalPageSize] = useState(preferredSize)
  const [confirmJob, setConfirmJob] = useState<JobName | null>(null)
  const [detail, setDetail] = useState<JobRun | null>(null)

  const runsQuery = useJobRuns({ page, perPage: pageSize })
  const servicesQuery = useExternalServicesList()
  const runMutation = useRunJobMutation()

  const items = runsQuery.data?.items ?? []
  const latest = runsQuery.data?.latest
  const jobsConfig = servicesQuery.data?.find((service) => service.service_key === 'jobs_outbox')?.config
  const timezone = String(jobsConfig?.timezone ?? 'America/Mexico_City')

  async function handleRun(jobName: JobName) {
    setConfirmJob(null)
    try {
      const run = await runMutation.mutateAsync(jobName)
      toast.success(`${JOB_META[jobName].title}: completado`, describeJobRun(run))
      setPage(1)
    } catch (error) {
      toast.apiError(error)
    }
  }

  function scheduleHint(jobName: JobName): string {
    const field = JOB_META[jobName].scheduleField
    const hour = field ? jobsConfig?.[field] : null
    return hour ? `Diario ${String(hour)} (${timezone})` : 'Ejecución programada / manual'
  }

  function rowActions(row: JobRun): RowActionItem[] {
    return [{ label: 'Ver detalle JSON', onClick: () => setDetail(row) }]
  }

  return (
    <div className="space-y-4">
      <AppCard className="flex flex-wrap items-center justify-between gap-2.5 px-3.5 py-2.5 text-xs text-slate-600 dark:text-zinc-300">
        <span className="flex items-center gap-2">
          <KeyRound className="h-4 w-4 shrink-0 text-[#0066FF] dark:text-[#0A84FF]" />
          <span>
            Cloud Scheduler invoca <span className="font-mono text-[11px]">POST /api/jobs/run/&#123;job&#125;</span>{' '}
            con <span className="font-mono text-[11px]">X-Job-Secret</span>; CLI usa{' '}
            <span className="font-mono text-[11px]">php scripts/jobs/run_job.php</span>.
          </span>
        </span>
        {runsQuery.data ? (
          <AppBadge
            label={runsQuery.data.secret_configured ? 'Secreto .env OK' : 'Secreto pendiente en .env'}
            tone={runsQuery.data.secret_configured ? 'success' : 'warning'}
          />
        ) : null}
      </AppCard>

      <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
        {JOB_ORDER.map((jobName) => {
          const meta = JOB_META[jobName]
          const Icon = meta.icon
          const last = latest?.[jobName] ?? null
          const hint = scheduleHint(jobName)
          const lastMeta = last ? JOB_STATUS_META[last.status] : null
          const lastTooltip = last
            ? `Última corrida: ${formatWhen(last.started_at)} · ${last.processed_count.toLocaleString('es-MX')} procesado(s)${last.error_message ? ` · Error: ${last.error_message}` : ''}`
            : 'Sin corridas registradas'

          return (
            <AppCard key={jobName} className="flex flex-col justify-between gap-2.5 p-3.5">
              <div className="space-y-2">
                <div className="flex items-start gap-2.5">
                  <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-blue-500/10 text-[#0066FF] dark:text-[#0A84FF]">
                    <Icon className="h-4 w-4" />
                  </span>
                  <div className="min-w-0 flex-1">
                    <p
                      className="truncate font-heading text-sm font-semibold leading-tight text-slate-900 dark:text-white"
                      title={meta.title}
                    >
                      {meta.title}
                    </p>
                    <p className="mt-0.5 flex items-center gap-1 truncate text-[11px] text-slate-500 dark:text-zinc-400">
                      <Clock className="h-3 w-3 shrink-0 opacity-75" />
                      <span className="truncate">{hint}</span>
                    </p>
                  </div>
                </div>

                <p
                  className="line-clamp-2 text-xs text-slate-500 dark:text-zinc-400"
                  title={meta.description}
                >
                  {meta.description}
                </p>
              </div>

              <div className="space-y-2.5 pt-0.5">
                <div className="flex items-center justify-between gap-2" title={lastTooltip}>
                  {runsQuery.isLoading ? (
                    <Skeleton className="h-5 w-28 rounded-full" />
                  ) : last && lastMeta ? (
                    <AppBadge
                      label={`${lastMeta.label} · ${formatDuration(last.duration_seconds)}`}
                      tone={lastMeta.tone}
                    />
                  ) : (
                    <AppBadge label="Sin corridas" tone="neutral" />
                  )}
                  {last ? (
                    <span className="truncate font-mono text-[11px] tabular-nums text-slate-400 dark:text-zinc-500">
                      {last.processed_count.toLocaleString('es-MX')} proc.
                    </span>
                  ) : null}
                </div>

                {canOperate ? (
                  <AppButton
                    variant="secondary"
                    size="sm"
                    className="w-full"
                    onClick={() => setConfirmJob(jobName)}
                    disabled={runMutation.isPending || last?.status === 'running'}
                    isLoading={runMutation.isPending && runMutation.variables === jobName}
                  >
                    {!(runMutation.isPending && runMutation.variables === jobName) ? (
                      <PlayCircle className="h-3.5 w-3.5" />
                    ) : null}
                    Ejecutar ahora
                  </AppButton>
                ) : null}
              </div>
            </AppCard>
          )
        })}
      </div>

      <AppTableWrapper
        isLoading={runsQuery.isLoading}
        loadingState={<Skeleton className="h-56 w-full rounded-none" />}
        isEmpty={!runsQuery.isLoading && (runsQuery.isError || items.length === 0)}
        emptyState={
          <p className="px-4 py-10 text-center text-sm text-slate-500">
            {runsQuery.isError
              ? 'No se pudo cargar el historial de jobs.'
              : 'Aún no hay corridas de jobs registradas.'}
          </p>
        }
        footer={
          <PaginationBar
            page={page}
            total={runsQuery.data?.total ?? 0}
            pageSize={pageSize}
            onPageChange={setPage}
            onPageSizeChange={(size) => {
              setLocalPageSize(size)
              setPageSize(size)
              setPage(1)
            }}
          />
        }
      >
        <div className="hidden overflow-x-auto md:block">
          <table className="w-full text-sm">
            <thead>
              <AppTableHeadRow>
                <th className="px-4 py-3 font-medium">JOB</th>
                <th className="px-4 py-3 font-medium">ORIGEN</th>
                <th className="px-4 py-3 font-medium">PROCESADOS</th>
                <th className="px-4 py-3 font-medium">ESTADO</th>
                <th className="px-4 py-3 font-medium">INICIO</th>
                <th className="px-4 py-3 font-medium">DURACIÓN</th>
                <th className="px-4 py-3 text-right font-medium">ACCIONES</th>
              </AppTableHeadRow>
            </thead>
            <tbody>
              {items.map((row) => (
                <tr key={row.id} className="border-b border-slate-100 dark:border-white/[0.04]">
                  <td className={rowPaddingClass}>
                    <p className="font-medium">{row.job_label}</p>
                    <p className="font-mono text-xs tabular-nums text-slate-500">
                      #{row.id}
                      {row.triggered_by_name ? ` · ${row.triggered_by_name}` : ''}
                    </p>
                  </td>
                  <td className={rowPaddingClass}>
                    <AppBadge label={JOB_TRIGGER_LABELS[row.trigger_source]} tone="neutral" />
                  </td>
                  <td className={`${rowPaddingClass} font-mono tabular-nums`}>
                    {row.processed_count.toLocaleString('es-MX')}
                  </td>
                  <td className={rowPaddingClass}>
                    <StatusBadge run={row} />
                  </td>
                  <td className={`${rowPaddingClass} whitespace-nowrap font-mono text-xs tabular-nums`}>
                    {formatWhen(row.started_at)}
                  </td>
                  <td className={`${rowPaddingClass} whitespace-nowrap font-mono text-xs tabular-nums`}>
                    {formatDuration(row.duration_seconds)}
                  </td>
                  <td className={`${rowPaddingClass} text-right`}>
                    <AppRowActionsMenu actions={rowActions(row)} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="space-y-3 p-4 md:hidden">
          {items.map((row) => (
            <AppCard key={row.id} className="space-y-2 p-3">
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <p className="font-medium">{row.job_label}</p>
                  <p className="font-mono text-xs tabular-nums text-slate-500">
                    #{row.id} · {formatWhen(row.started_at)}
                  </p>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                  <StatusBadge run={row} />
                  <AppRowActionsMenu actions={rowActions(row)} />
                </div>
              </div>
              <div className="flex flex-wrap items-center gap-1.5">
                <AppBadge label={JOB_TRIGGER_LABELS[row.trigger_source]} tone="neutral" />
                <span className="font-mono text-xs tabular-nums text-slate-500">
                  {row.processed_count.toLocaleString('es-MX')} procesado(s) · {formatDuration(row.duration_seconds)}
                </span>
              </div>
            </AppCard>
          ))}
        </div>
      </AppTableWrapper>

      <AppModal open={confirmJob != null} onOpenChange={(open) => (!open ? setConfirmJob(null) : undefined)}>
        <AppModalContent className="max-w-md">
          <AppModalHeader>
            <AppModalTitle>¿Ejecutar ahora?</AppModalTitle>
            <AppModalDescription>
              {confirmJob
                ? `Se ejecutará "${JOB_META[confirmJob].title}" con la fecha de negocio de hoy. La corrida queda registrada como manual en la bitácora.`
                : ''}
            </AppModalDescription>
          </AppModalHeader>
          <AppModalFooter>
            <AppButton variant="ghost" onClick={() => setConfirmJob(null)}>
              Cancelar
            </AppButton>
            <AppButton onClick={() => (confirmJob ? void handleRun(confirmJob) : undefined)}>
              <PlayCircle className="h-4 w-4" />
              Ejecutar ahora
            </AppButton>
          </AppModalFooter>
        </AppModalContent>
      </AppModal>

      <JsonPayloadModal
        open={detail != null}
        onOpenChange={(open) => {
          if (!open) setDetail(null)
        }}
        title={detail ? `Corrida #${detail.id} · ${detail.job_label}` : ''}
        description={
          detail ? (
            <div className="flex flex-wrap items-center gap-1.5">
              <StatusBadge run={detail} />
              <AppBadge label={JOB_TRIGGER_LABELS[detail.trigger_source]} tone="neutral" />
              <span className="font-mono text-xs tabular-nums">
                {formatWhen(detail.started_at)} · {formatDuration(detail.duration_seconds)}
              </span>
            </div>
          ) : undefined
        }
        sections={
          detail ? [{ label: 'Resumen de la corrida', value: detail.summary, errorText: detail.error_message }] : []
        }
      />
    </div>
  )
}
