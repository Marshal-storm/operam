<?php

declare(strict_types=1);

namespace System\Support\Jobs;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use RuntimeException;
use System\Services\CollectionsService;
use System\Support\AuditLogger;
use System\Support\BenefitRulesEngine;
use System\Support\CollectionRules;
use System\Support\Integrations\ExternalServiceRegistry;
use System\Support\Integrations\OutboxDispatcher;
use System\Support\Integrations\OutboxPublisher;
use Throwable;

/**
 * Motor de los 4 Jobs oficiales para Google Cloud (Pág. 24 del PDF).
 *
 * Doble entrada: HTTP para Cloud Scheduler (`POST /api/jobs/run/{jobName}`) y CLI para
 * Cloud Run Jobs (`php scripts/jobs/run_job.php`). Cada corrida queda en `job_runs`.
 * Las fechas de corte/vencimiento se evalúan en la zona configurada en Servicios externos
 * (`jobs_outbox.timezone`, por defecto America/Mexico_City); los timestamps se guardan en UTC.
 */
final class JobsRunner
{
    public const JOB_NAMES = ['daily_billing', 'reset_included_balances', 'daily_collections', 'process_outbox'];

    public const TRIGGER_SOURCES = ['cloud_scheduler', 'cli', 'manual_ui'];

    public const STATUSES = ['running', 'completed', 'failed'];

    /** Etiquetas es-MX para auditoría y respuestas. */
    public const JOB_LABELS = [
        'daily_billing' => 'Facturación diaria por aniversario',
        'reset_included_balances' => 'Reinicio de bolsas incluidas',
        'daily_collections' => 'Cobranza y suspensión 90d',
        'process_outbox' => 'Worker de Outbox',
    ];

    private const MODULE_KEY = 'config_external_services';

    private const DEFAULT_TIMEZONE = 'America/Mexico_City';

    /** Corridas `running` más viejas que esto se consideran caídas (contenedor terminado). */
    private const STALE_RUNNING_MINUTES = 60;

    private const OUTBOX_BATCH = 50;

    private const MONTHS_ES = [1 => 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    /**
     * @param string|null $todayOverride Fecha de negocio fija `Y-m-d` (pruebas / re-ejecuciones controladas).
     */
    public function __construct(
        private readonly ?string $todayOverride = null,
        private readonly ?OutboxDispatcher $dispatcher = null,
    ) {
    }

    public static function isKnownJob(string $jobName): bool
    {
        return in_array($jobName, self::JOB_NAMES, true);
    }

    /**
     * Ejecuta un job y registra la corrida en `job_runs`.
     *
     * @param array<string, mixed> $authContext Contexto JWT (manual) o identidad de sistema (scheduler/CLI).
     * @return array<string, mixed> Corrida resultante (completed / failed).
     */
    public function run(string $jobName, string $triggerSource, array $authContext = []): array
    {
        if (!self::isKnownJob($jobName)) {
            throw new InvalidArgumentException('Job no soportado: ' . $jobName . '. Usa: ' . implode(', ', self::JOB_NAMES) . '.');
        }
        if (!in_array($triggerSource, self::TRIGGER_SOURCES, true)) {
            throw new InvalidArgumentException('Origen de disparo no soportado: ' . $triggerSource);
        }

        ExternalServiceRegistry::clearCache();
        $this->recoverStaleRuns();
        if (Capsule::table('job_runs')->where('job_name', $jobName)->where('status', 'running')->exists()) {
            throw new RuntimeException(sprintf('El job "%s" ya está en ejecución; espera a que termine.', self::JOB_LABELS[$jobName]));
        }

        $userId = $triggerSource === 'manual_ui' ? (int) ($authContext['user_id'] ?? 0) : 0;
        $runId = (int) Capsule::table('job_runs')->insertGetId([
            'job_name' => $jobName,
            'trigger_source' => $triggerSource,
            'status' => 'running',
            'started_at' => gmdate('Y-m-d H:i:s'),
            'finished_at' => null,
            'processed_count' => 0,
            'summary_json' => null,
            'error_message' => null,
            'triggered_by_user_id' => $userId > 0 ? $userId : null,
        ]);

        try {
            $outcome = match ($jobName) {
                'daily_billing' => $this->dailyBilling($authContext),
                'reset_included_balances' => $this->resetIncludedBalances($authContext),
                'daily_collections' => $this->dailyCollections($authContext),
                'process_outbox' => $this->processOutbox(),
            };
            Capsule::table('job_runs')->where('id', $runId)->update([
                'status' => 'completed',
                'finished_at' => gmdate('Y-m-d H:i:s'),
                'processed_count' => max(0, $outcome['processed_count']),
                'summary_json' => json_encode($outcome['summary'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable $e) {
            Capsule::table('job_runs')->where('id', $runId)->update([
                'status' => 'failed',
                'finished_at' => gmdate('Y-m-d H:i:s'),
                'error_message' => mb_substr($e->getMessage() !== '' ? $e->getMessage() : get_class($e), 0, 2000),
            ]);
            error_log(sprintf('[jobs] %s #%d falló: %s', $jobName, $runId, $e->getMessage()));
        }

        $run = self::mapRow(Capsule::table('job_runs')->where('id', $runId)->first());
        AuditLogger::log(
            $authContext,
            self::MODULE_KEY,
            'job_runs',
            (string) $runId,
            'create',
            sprintf(
                'Job "%s" (%s): %s · %d procesado(s)',
                self::JOB_LABELS[$jobName],
                $triggerSource,
                $run['status'] === 'completed' ? 'completado' : 'fallido',
                $run['processed_count'],
            ),
            null,
            [
                'job_name' => $jobName,
                'trigger_source' => $triggerSource,
                'status' => $run['status'],
                'processed_count' => $run['processed_count'],
                'error_message' => $run['error_message'],
            ],
        );

        return $run;
    }

    /**
     * Ejecuta los 4 jobs en el orden operativo: facturar → reiniciar bolsas → cobranza → despachar outbox.
     *
     * @param array<string, mixed> $authContext
     * @return list<array<string, mixed>>
     */
    public function runAll(string $triggerSource, array $authContext = []): array
    {
        $runs = [];
        foreach (self::JOB_NAMES as $jobName) {
            try {
                $runs[] = $this->run($jobName, $triggerSource, $authContext);
            } catch (RuntimeException $e) {
                // Un job ya en ejecución no detiene a los demás.
                $runs[] = ['job_name' => $jobName, 'status' => 'skipped', 'error_message' => $e->getMessage()];
            }
        }

        return $runs;
    }

    /** Fecha de negocio (`Y-m-d`) en la zona horaria configurada para los jobs. */
    public function today(): string
    {
        if ($this->todayOverride !== null) {
            return $this->todayOverride;
        }

        return (new DateTimeImmutable('now', $this->timezone()))->format('Y-m-d');
    }

    /**
     * @return array<string, mixed>
     */
    public static function mapRow(?object $row): array
    {
        if ($row === null) {
            throw new RuntimeException('Corrida de job no encontrada.');
        }
        $startedAt = (string) $row->started_at;
        $finishedAt = $row->finished_at !== null ? (string) $row->finished_at : null;
        $summary = $row->summary_json !== null ? json_decode((string) $row->summary_json, true) : null;

        return [
            'id' => (int) $row->id,
            'job_name' => (string) $row->job_name,
            'job_label' => self::JOB_LABELS[(string) $row->job_name] ?? (string) $row->job_name,
            'trigger_source' => (string) $row->trigger_source,
            'status' => (string) $row->status,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_seconds' => $finishedAt !== null
                ? max(0, (int) strtotime($finishedAt . ' UTC') - (int) strtotime($startedAt . ' UTC'))
                : null,
            'processed_count' => (int) $row->processed_count,
            'summary' => is_array($summary) ? $summary : null,
            'error_message' => $row->error_message !== null ? (string) $row->error_message : null,
            'triggered_by_user_id' => $row->triggered_by_user_id !== null ? (int) $row->triggered_by_user_id : null,
            'triggered_by_name' => isset($row->triggered_by_name) && $row->triggered_by_name !== null
                ? (string) $row->triggered_by_name
                : null,
        ];
    }

    // ── Job 1: daily_billing (RF-5.1 / RF-5.2 / RF-5.3) ────────────────────────

    /**
     * @param array<string, mixed> $authContext
     * @return array{processed_count: int, summary: array<string, mixed>}
     */
    private function dailyBilling(array $authContext): array
    {
        $today = $this->today();
        $collections = (new CollectionsService())->withAuthContext($authContext);
        $contractIds = Capsule::table('contracts')
            ->where('status', 'active')
            ->whereNotNull('next_cutoff_date')
            ->where('next_cutoff_date', '<=', $today)
            ->orderBy('next_cutoff_date')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $summary = [
            'as_of' => $today,
            'contracts_evaluated' => count($contractIds),
            'invoices_created' => 0,
            'duplicates_skipped' => 0,
            'skipped_without_amount' => 0,
            'invoices' => [],
            'errors' => [],
        ];

        foreach ($contractIds as $contractId) {
            try {
                $result = Capsule::connection()->transaction(
                    fn (): array => $this->billContract($contractId, $today, $collections, $authContext),
                );
                $summary['invoices_created'] += count($result['invoices']);
                $summary['duplicates_skipped'] += $result['duplicates'];
                $summary['skipped_without_amount'] += $result['without_amount'];
                array_push($summary['invoices'], ...$result['invoices']);
            } catch (Throwable $e) {
                $summary['errors'][] = ['contract_id' => $contractId, 'message' => mb_substr($e->getMessage(), 0, 255)];
            }
        }

        return ['processed_count' => $summary['invoices_created'], 'summary' => $summary];
    }

    /**
     * Factura todos los periodos vencidos del contrato (normalmente uno) y avanza `next_cutoff_date`.
     *
     * @param array<string, mixed> $authContext
     * @return array{invoices: list<array<string, mixed>>, duplicates: int, without_amount: int}
     */
    private function billContract(int $contractId, string $today, CollectionsService $collections, array $authContext): array
    {
        $result = ['invoices' => [], 'duplicates' => 0, 'without_amount' => 0];
        $contract = Capsule::table('contracts')->where('id', $contractId)->lockForUpdate()->first();
        if ($contract === null || (string) $contract->status !== 'active' || $contract->next_cutoff_date === null) {
            return $result;
        }
        $frequency = (string) $contract->frequency;
        if (!BillingCycleRules::isRecurringFrequency($frequency)) {
            return $result;
        }

        $anniversaryDay = BillingCycleRules::anniversaryDay((string) $contract->anniversary_date);
        $lines = Capsule::table('contract_lines')
            ->where('contract_id', $contractId)
            ->where('active', 1)
            ->where('service_type', 'recurring')
            ->get(['subtotal', 'billing_frequency'])
            ->map(static fn (object $line): array => [
                'subtotal' => (string) $line->subtotal,
                'billing_frequency' => (string) $line->billing_frequency,
            ])
            ->all();
        $periodSubtotal = BillingCycleRules::periodSubtotal($lines, $frequency);
        $userId = (int) ($authContext['user_id'] ?? 0);
        $nowUtc = gmdate('Y-m-d H:i:s');

        $periodStart = (string) $contract->next_cutoff_date;
        $iterations = 0;
        while ($periodStart <= $today && $iterations < BillingCycleRules::MAX_CATCH_UP_PERIODS) {
            $nextStart = BillingCycleRules::nextBillingDate($periodStart, $frequency, $anniversaryDay);
            $periodEnd = BillingCycleRules::periodEnd($periodStart, $frequency, $anniversaryDay);
            $iterations++;

            // RNF-2: una sola factura recurrente por (contrato, inicio de periodo).
            $alreadyBilled = Capsule::table('invoices')
                ->where('contract_id', $contractId)
                ->where('period_start', $periodStart)
                ->exists();
            if ($alreadyBilled) {
                $result['duplicates']++;
                $periodStart = $nextStart;
                continue;
            }
            if (bccomp($periodSubtotal, '0.00', 2) <= 0) {
                $result['without_amount']++;
                $periodStart = $nextStart;
                continue;
            }

            $amounts = CollectionRules::buildInvoiceAmounts($periodSubtotal);
            $dueDate = CollectionRules::computeDueDate($periodStart, (int) ($contract->credit_days ?? 0));
            $status = CollectionRules::resolveOpenInvoiceStatus('0.00', $amounts['balance'], $dueDate, $today);
            $folio = $collections->nextInvoiceFolio();
            $invoiceId = (int) Capsule::table('invoices')->insertGetId([
                'folio' => $folio,
                'contract_id' => $contractId,
                'client_id' => (int) $contract->client_id,
                'segment_id' => (int) $contract->segment_id,
                'issuer_id' => (int) $contract->issuer_id,
                'request_id' => null,
                'invoice_type' => 'recurring',
                'concept' => mb_substr(sprintf(
                    'Facturación recurrente %s – %s · %s',
                    self::formatDateEs($periodStart),
                    self::formatDateEs($periodEnd),
                    (string) $contract->folio,
                ), 0, 255),
                'currency' => in_array((string) $contract->currency, ['MXN', 'USD'], true) ? (string) $contract->currency : 'MXN',
                'subtotal' => $amounts['subtotal'],
                'tax_amount' => $amounts['tax_amount'],
                'total' => $amounts['total'],
                'paid_amount' => $amounts['paid_amount'],
                'balance' => $amounts['balance'],
                'issue_date' => $periodStart,
                'due_date' => $dueDate,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'status' => $status === 'overdue' ? 'overdue' : 'pending',
                'stamped_uuid' => null,
                'notes' => null,
                'created_by_user_id' => $userId > 0 ? $userId : null,
                'created_at' => $nowUtc,
                'updated_at' => $nowUtc,
            ]);

            // Prefactura al ERP del Emisor (timbrado inmediato según `stamp_on_issue`).
            OutboxPublisher::invoicePreinvoice($invoiceId, sprintf('preinvoice:%d:%s', $contractId, $periodStart));

            $result['invoices'][] = [
                'invoice_id' => $invoiceId,
                'folio' => $folio,
                'contract_id' => $contractId,
                'contract_folio' => (string) $contract->folio,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'total' => $amounts['total'],
            ];
            $periodStart = $nextStart;
        }

        Capsule::table('contracts')->where('id', $contractId)->update([
            'next_cutoff_date' => $periodStart,
            'updated_at' => $nowUtc,
        ]);

        if ($result['invoices'] !== []) {
            $collections->recalculateContractCollectionStatus($contractId);
        }

        return $result;
    }

    // ── Job 2: reset_included_balances (RF-4.2) ───────────────────────────────

    /**
     * @param array<string, mixed> $authContext
     * @return array{processed_count: int, summary: array<string, mixed>}
     */
    private function resetIncludedBalances(array $authContext): array
    {
        $today = $this->today();
        $nowMx = DateTimeImmutable::createFromFormat('!Y-m-d', $today, $this->timezone());
        if ($nowMx === false) {
            throw new RuntimeException('Fecha de negocio inválida para reiniciar bolsas.');
        }
        $bagIds = Capsule::table('balances as b')
            ->join('contracts as c', 'c.id', '=', 'b.contract_id')
            ->where('b.bucket', 'included')
            ->whereNotNull('b.period_end')
            ->where('b.period_end', '<', $today)
            ->where('c.status', 'active')
            ->orderBy('b.id')
            ->pluck('b.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $summary = ['as_of' => $today, 'balances_evaluated' => count($bagIds), 'balances_reset' => 0, 'resets' => [], 'errors' => []];
        $userId = (int) ($authContext['user_id'] ?? 0);

        foreach ($bagIds as $bagId) {
            try {
                $reset = Capsule::connection()->transaction(
                    fn (): ?array => $this->resetBalance($bagId, $today, $nowMx, $userId),
                );
                if ($reset !== null) {
                    $summary['balances_reset']++;
                    $summary['resets'][] = $reset;
                }
            } catch (Throwable $e) {
                $summary['errors'][] = ['balance_id' => $bagId, 'message' => mb_substr($e->getMessage(), 0, 255)];
            }
        }

        return ['processed_count' => $summary['balances_reset'], 'summary' => $summary];
    }

    /**
     * Reinicia una bolsa `included` vencida: recalcula unidades con las reglas vigentes,
     * `consumed_units = 0`, nuevo periodo y movimiento `reset` en `balance_movements`.
     *
     * @return array<string, mixed>|null
     */
    private function resetBalance(int $bagId, string $today, DateTimeImmutable $nowMx, int $userId): ?array
    {
        $bag = Capsule::table('balances')->where('id', $bagId)->lockForUpdate()->first();
        if ($bag === null || $bag->period_end === null || (string) $bag->period_end >= $today) {
            return null;
        }
        $contract = Capsule::table('contracts')->where('id', (int) $bag->contract_id)->first(['id', 'anniversary_date']);
        if ($contract === null) {
            return null;
        }

        $rules = Capsule::table('benefit_rules')
            ->where('active', 1)
            ->where('benefit_service_id', (int) $bag->service_id)
            ->orderBy('id')
            ->get(['source_service_id', 'units_per_unit', 'reset_period', 'is_cumulative']);

        $previousGranted = (int) $bag->granted_units;
        $previousConsumed = (int) $bag->consumed_units;
        $target = $previousGranted;
        $resetPeriod = 'monthly';
        $isCumulative = false;

        if ($rules->isNotEmpty()) {
            $equivalentsBySource = [];
            $lines = Capsule::table('contract_lines as cl')
                ->join('services as s', 's.id', '=', 'cl.service_id')
                ->where('cl.contract_id', (int) $bag->contract_id)
                ->where('cl.active', 1)
                ->get(['cl.service_id', 'cl.quantity', 's.package_factor']);
            foreach ($lines as $line) {
                $sourceId = (int) $line->service_id;
                $equivalentsBySource[$sourceId] = ($equivalentsBySource[$sourceId] ?? 0)
                    + BenefitRulesEngine::equivalentUnits((int) $line->quantity, (int) ($line->package_factor ?? 0));
            }
            $target = 0;
            foreach ($rules as $rule) {
                $target += BenefitRulesEngine::benefitUnits(
                    (int) ($equivalentsBySource[(int) $rule->source_service_id] ?? 0),
                    (int) $rule->units_per_unit,
                );
                $isCumulative = $isCumulative || (bool) $rule->is_cumulative;
            }
            $resetPeriod = (string) $rules->first()->reset_period;
        }

        // Reglas acumulables arrastran el remanente no consumido al nuevo ciclo.
        $carryOver = $isCumulative ? max(0, $previousGranted - $previousConsumed) : 0;
        $newGranted = $target + $carryOver;
        $period = BenefitRulesEngine::resolvePeriod(
            $resetPeriod,
            $contract->anniversary_date !== null ? (string) $contract->anniversary_date : null,
            $nowMx,
        );
        $nowUtc = gmdate('Y-m-d H:i:s');

        Capsule::table('balances')->where('id', $bagId)->update([
            'granted_units' => $newGranted,
            'consumed_units' => 0,
            'period_start' => $period['period_start'],
            'period_end' => $period['period_end'],
            'updated_at' => $nowUtc,
        ]);
        Capsule::table('balance_movements')->insert([
            'balance_id' => $bagId,
            'movement_type' => 'reset',
            'units' => $newGranted,
            'request_id' => null,
            'promotion_id' => null,
            'notes' => mb_substr(sprintf(
                'Reinicio de ciclo RF-4.2: consumidas %d → 0; otorgadas %d → %d%s (periodo %s a %s)',
                $previousConsumed,
                $previousGranted,
                $newGranted,
                $carryOver > 0 ? sprintf(' incl. %d acumuladas', $carryOver) : '',
                $period['period_start'],
                $period['period_end'],
            ), 0, 255),
            'created_by' => $userId > 0 ? $userId : null,
            'created_at' => $nowUtc,
        ]);

        return [
            'balance_id' => $bagId,
            'contract_id' => (int) $bag->contract_id,
            'service_id' => (int) $bag->service_id,
            'previous_granted_units' => $previousGranted,
            'previous_consumed_units' => $previousConsumed,
            'granted_units' => $newGranted,
            'period_start' => $period['period_start'],
            'period_end' => $period['period_end'],
        ];
    }

    // ── Job 3: daily_collections (RF-6.1 a RF-6.3) ────────────────────────────

    /**
     * @param array<string, mixed> $authContext
     * @return array{processed_count: int, summary: array<string, mixed>}
     */
    private function dailyCollections(array $authContext): array
    {
        $summary = (new CollectionsService())->withAuthContext($authContext)->runDailyCollectionsCycle();

        return ['processed_count' => (int) $summary['evaluated_contracts'], 'summary' => $summary];
    }

    // ── Job 4: process_outbox (RNF-12) ────────────────────────────────────────

    /**
     * @return array{processed_count: int, summary: array<string, mixed>}
     */
    private function processOutbox(): array
    {
        $result = ($this->dispatcher ?? new OutboxDispatcher())->processPending(self::OUTBOX_BATCH);

        return [
            'processed_count' => $result['processed'],
            'summary' => [
                'batch_size' => self::OUTBOX_BATCH,
                'processed' => $result['processed'],
                'sent' => $result['sent'],
                'failed' => $result['failed'],
                'slack_alerts' => $result['slack_alerts'],
            ],
        ];
    }

    // ── Utilidades ────────────────────────────────────────────────────────────

    /** Marca como fallidas las corridas `running` huérfanas (contenedor terminado a mitad). */
    private function recoverStaleRuns(): void
    {
        Capsule::table('job_runs')
            ->where('status', 'running')
            ->where('started_at', '<', gmdate('Y-m-d H:i:s', time() - self::STALE_RUNNING_MINUTES * 60))
            ->update([
                'status' => 'failed',
                'finished_at' => gmdate('Y-m-d H:i:s'),
                'error_message' => 'La corrida no reportó término (contenedor interrumpido).',
            ]);
    }

    private function timezone(): DateTimeZone
    {
        $name = (string) ExternalServiceRegistry::configValue('jobs_outbox', 'timezone', self::DEFAULT_TIMEZONE);

        return in_array($name, DateTimeZone::listIdentifiers(), true)
            ? new DateTimeZone($name)
            : new DateTimeZone(self::DEFAULT_TIMEZONE);
    }

    /** `2026-09-15` → `15 sep 2026` (formato oficial es-MX). */
    private static function formatDateEs(string $ymd): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
        if ($date === false) {
            return $ymd;
        }

        return sprintf('%d %s %s', (int) $date->format('j'), self::MONTHS_ES[(int) $date->format('n')], $date->format('Y'));
    }
}
