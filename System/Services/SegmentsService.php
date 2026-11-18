<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use System\Core\Context;
use System\Core\DataTypes;
use System\Core\Result;
use System\Models\SegmentsModel;

/**
 * Lectura/actualización de segmentos — RF-1 (`dias_credito_default`, `tipo_cambio_usd`).
 * Endpoints: GET /api/segments · PUT/PATCH /api/segments/{id}.
 */
final class SegmentsService extends ModelService
{
    /** Módulo RBAC que autoriza editar parámetros comerciales. */
    private const EDIT_MODULE = 'config_preferencias';

    protected function modelClass(): string
    {
        return SegmentsModel::class;
    }

    protected function configureFields(): void
    {
        $this->validator
            ->setField('nombre', DataTypes::String, false)
            ->setField('dias_credito_default', DataTypes::Integer, false)
            ->setField('tipo_cambio_usd', DataTypes::String, false);
    }

    public function handle(Context $context): Result
    {
        $method = $context->getMethod();
        $id = $context->getData('_resource_id');
        $auth = $context->getData('auth', []);
        $request = $context->getRequest();

        if ($method === 'GET' && $id === null) {
            return Result::ok($this->listSegments($auth), 'Segmentos');
        }

        if ($method === 'GET' && $id !== null) {
            $row = $this->findMapped((int) $id);
            if ($row === null) {
                return Result::fail('Segmento no encontrado.', 404);
            }
            if (!$this->isSegmentInScope($auth, (int) $row['id'])) {
                return Result::fail('Segmento fuera de tu alcance.', 403);
            }

            return Result::ok($row, 'Segmento');
        }

        if ($id === null) {
            return Result::fail('Se requiere id de segmento.', 400);
        }

        if (!in_array($method, ['PUT', 'PATCH'], true)) {
            return Result::fail('Método no soportado.', 405);
        }

        if (!RbacAuthHelper::can($auth, self::EDIT_MODULE, 'editar')) {
            return Result::fail('No tienes permiso para editar parámetros comerciales (config_preferencias).', 403);
        }

        $segmentId = (int) $id;
        $existing = Capsule::table('segmento')->where('id', $segmentId)->first();
        if ($existing === null) {
            return Result::fail('Segmento no encontrado.', 404);
        }
        if (!$this->isSegmentInScope($auth, $segmentId)) {
            return Result::fail('No puedes editar un segmento fuera de tu alcance.', 403);
        }

        $hasDays = array_key_exists('dias_credito_default', $request);
        $hasFx = array_key_exists('tipo_cambio_usd', $request);
        if (!$hasDays && !$hasFx) {
            return Result::fail('Envía dias_credito_default y/o tipo_cambio_usd.', 422);
        }

        $payload = ['updated_at' => gmdate('Y-m-d H:i:s')];

        if ($hasDays) {
            $days = filter_var($request['dias_credito_default'], FILTER_VALIDATE_INT);
            if ($days === false || $days < 0) {
                return Result::fail('dias_credito_default debe ser un entero >= 0.', 422);
            }
            $payload['dias_credito_default'] = $days;
        }

        if ($hasFx) {
            $fx = $this->normalizeFx((string) $request['tipo_cambio_usd']);
            if ($fx === null) {
                return Result::fail('tipo_cambio_usd debe ser un decimal >= 0 con hasta 4 decimales.', 422);
            }
            $payload['tipo_cambio_usd'] = $fx;
        }

        Capsule::table('segmento')->where('id', $segmentId)->update($payload);

        return Result::ok($this->findMapped($segmentId), 'Parámetros comerciales actualizados');
    }

    /**
     * @param array<string, mixed> $auth
     * @return list<array{id:int,nombre:string,dias_credito_default:int,tipo_cambio_usd:string}>
     */
    private function listSegments(array $auth): array
    {
        $builder = Capsule::table('segmento')->orderBy('id');
        $lockedId = $auth['segmento_id'] ?? null;
        if ($lockedId !== null) {
            $builder->where('id', (int) $lockedId);
        }

        return $builder
            ->get(['id', 'nombre', 'dias_credito_default', 'tipo_cambio_usd'])
            ->map(fn (object $row): array => $this->mapRow($row))
            ->all();
    }

    /**
     * @return array{id:int,nombre:string,dias_credito_default:int,tipo_cambio_usd:string}|null
     */
    private function findMapped(int $id): ?array
    {
        $row = Capsule::table('segmento')->where('id', $id)->first([
            'id',
            'nombre',
            'dias_credito_default',
            'tipo_cambio_usd',
        ]);
        if ($row === null) {
            return null;
        }

        return $this->mapRow($row);
    }

    /**
     * @return array{id:int,nombre:string,dias_credito_default:int,tipo_cambio_usd:string}
     */
    private function mapRow(object $row): array
    {
        $fx = isset($row->tipo_cambio_usd) ? (string) $row->tipo_cambio_usd : '19.5000';
        if (!str_contains($fx, '.')) {
            $fx .= '.0000';
        }

        return [
            'id' => (int) $row->id,
            'nombre' => (string) $row->nombre,
            'dias_credito_default' => (int) $row->dias_credito_default,
            'tipo_cambio_usd' => $this->normalizeFx($fx) ?? '19.5000',
        ];
    }

    /** Normaliza a exactamente 4 decimales vía BCMath. */
    private function normalizeFx(string $amount): ?string
    {
        $trimmed = trim(str_replace(',', '', $amount));
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return null;
        }
        if (bccomp($trimmed, '0', 4) < 0) {
            return null;
        }

        return bcadd($trimmed, '0', 4);
    }

    /**
     * @param array<string, mixed> $auth
     */
    private function isSegmentInScope(array $auth, int $segmentId): bool
    {
        $lockedId = $auth['segmento_id'] ?? null;
        if ($lockedId === null) {
            return true;
        }

        return (int) $lockedId === $segmentId;
    }
}
