<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use RuntimeException;
use System\Core\Context;
use System\Core\Result;
use System\Support\Integrations\ExternalServiceRegistry;
use System\Support\Integrations\SecretResolver;
use System\Support\Jobs\JobsRunner;

/**
 * Jobs de Google Cloud (Pág. 24 del PDF) — `/api/jobs`.
 *
 * POST /api/jobs/run/{jobName}  — Cloud Scheduler con `X-Job-Secret` (sin JWT) o disparo manual
 *                                 desde la UI con JWT + `config_external_services.edit` y alcance "Todos".
 * GET  /api/jobs/runs           — historial paginado + última corrida por job (`config_external_services.view`).
 *
 * `{jobName}` acepta los 4 jobs oficiales o `all`.
 */
final class JobsService extends CustomService
{
    private const MODULE_KEY = 'config_external_services';

    private const ALLOWED_PER_PAGE = [10, 25, 50, 100];

    /** Referencia por defecto si el panel aún no define `jobs_outbox.job_secret_ref`. */
    private const DEFAULT_SECRET_REF = 'GCP_JOB_SECRET_REF';

    public const SECRET_HEADER = 'X-Job-Secret';

    /** @var array<string, mixed> */
    private array $authContext = [];

    /** ¿La petición es un disparo de Cloud Scheduler? (se enruta antes del guardia JWT). */
    public static function isSchedulerRequest(Context $context): bool
    {
        return str_starts_with(strtolower(trim($context->getUri(), '/')), 'jobs/run/')
            && $context->getHeader(self::SECRET_HEADER) !== null;
    }

    /**
     * Entrada de Cloud Scheduler: valida `X-Job-Secret` contra el cofre (RNF-8) con `hash_equals`.
     */
    public function handleSchedulerRequest(Context $context): Result
    {
        if ($context->getMethod() !== 'POST') {
            return Result::fail('Método no soportado.', 405);
        }

        $expected = self::resolveJobSecret();
        if ($expected === null || $expected === '') {
            return Result::fail('El secreto de jobs no está configurado en el entorno.', 503);
        }
        $provided = (string) $context->getHeader(self::SECRET_HEADER);
        if (!hash_equals($expected, $provided)) {
            return Result::fail('Credencial de job inválida.', 401);
        }

        return $this->runFromUri($context->getUri(), 'cloud_scheduler', ['name' => 'Cloud Scheduler']);
    }

    public function handle(Context $context): Result
    {
        $this->authContext = $context->getData('auth', []);
        $method = $context->getMethod();
        $parts = explode('/', strtolower(trim($context->getUri(), '/')));
        $section = $parts[1] ?? '';

        if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'view')) {
            return Result::fail('No tienes permiso para consultar los jobs programados.', 403);
        }

        if ($method === 'GET' && $section === 'runs') {
            return Result::ok($this->listRuns($context->getRequest()), 'Historial de jobs');
        }

        if ($method === 'POST' && $section === 'run') {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'edit')) {
                return Result::fail('No tienes permiso para ejecutar jobs programados.', 403);
            }
            if (($this->authContext['segment_id'] ?? null) !== null) {
                return Result::fail('Los jobs son globales; requieren alcance de segmento "Todos".', 403);
            }

            return $this->runFromUri($context->getUri(), 'manual_ui', $this->authContext);
        }

        return Result::fail('Ruta de jobs no reconocida.', 404);
    }

    /** Valor del secreto compartido con Cloud Scheduler (nunca se expone ni se registra). */
    public static function resolveJobSecret(): ?string
    {
        $refName = ExternalServiceRegistry::secretRef('jobs_outbox', 'job_secret_ref') ?? self::DEFAULT_SECRET_REF;

        return SecretResolver::get($refName);
    }

    /**
     * @param array<string, mixed> $authContext
     */
    private function runFromUri(string $uri, string $triggerSource, array $authContext): Result
    {
        $parts = explode('/', strtolower(trim($uri, '/')));
        $jobName = (string) ($parts[2] ?? '');
        $runner = new JobsRunner();

        try {
            if ($jobName === 'all') {
                $runs = $runner->runAll($triggerSource, $authContext);
                $failed = array_filter($runs, static fn (array $run): bool => ($run['status'] ?? '') !== 'completed');

                return $failed === []
                    ? Result::ok(['runs' => $runs], 'Jobs ejecutados')
                    : Result::fail(sprintf('%d job(s) no completaron su ejecución.', count($failed)), 500, ['runs' => $runs]);
            }
            if (!JobsRunner::isKnownJob($jobName)) {
                return Result::fail('Job no reconocido. Usa: ' . implode(', ', JobsRunner::JOB_NAMES) . ' o all.', 404);
            }

            $run = $runner->run($jobName, $triggerSource, $authContext);
        } catch (InvalidArgumentException $e) {
            return Result::fail($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return Result::fail($e->getMessage(), 409);
        }

        return $run['status'] === 'completed'
            ? Result::ok($run, sprintf('%s completado: %d procesado(s)', $run['job_label'], $run['processed_count']))
            : Result::fail(sprintf('%s falló: %s', $run['job_label'], (string) $run['error_message']), 500, $run);
    }

    /**
     * Historial paginado (más reciente primero, ordenado en SQL) + última corrida de cada job.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function listRuns(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = (int) ($query['per_page'] ?? 25);
        if (!in_array($perPage, self::ALLOWED_PER_PAGE, true)) {
            $perPage = 25;
        }

        $builder = Capsule::table('job_runs as jr')->leftJoin('users as u', 'u.id', '=', 'jr.triggered_by_user_id');
        $jobName = (string) ($query['job_name'] ?? '');
        if (JobsRunner::isKnownJob($jobName)) {
            $builder->where('jr.job_name', $jobName);
        }
        $status = (string) ($query['status'] ?? '');
        if (in_array($status, JobsRunner::STATUSES, true)) {
            $builder->where('jr.status', $status);
        }

        $total = (clone $builder)->count('jr.id');
        $rows = $builder
            ->orderByDesc('jr.started_at')
            ->orderByDesc('jr.id')
            ->forPage($page, $perPage)
            ->get(['jr.*', 'u.name as triggered_by_name']);

        $latest = [];
        foreach (JobsRunner::JOB_NAMES as $name) {
            $row = Capsule::table('job_runs as jr')
                ->leftJoin('users as u', 'u.id', '=', 'jr.triggered_by_user_id')
                ->where('jr.job_name', $name)
                ->orderByDesc('jr.started_at')
                ->orderByDesc('jr.id')
                ->first(['jr.*', 'u.name as triggered_by_name']);
            $latest[$name] = $row !== null ? JobsRunner::mapRow($row) : null;
        }

        return [
            'items' => $rows->map(static fn (object $row): array => JobsRunner::mapRow($row))->all(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => max(1, (int) ceil($total / $perPage)),
            'latest' => $latest,
            'secret_configured' => self::resolveJobSecret() !== null,
        ];
    }
}
