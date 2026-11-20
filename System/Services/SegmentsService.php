<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use System\Core\Context;
use System\Core\DataTypes;
use System\Core\Result;
use System\Models\SegmentsModel;

/**
 * Lectura/actualización de segmentos — RF-1 (`default_credit_days`, `usd_exchange_rate`).
 * Endpoints: GET /api/segments · PUT/PATCH /api/segments/{id}.
 */
final class SegmentsService extends ModelService
{
    /** Módulo RBAC que autoriza editar parámetros comerciales (Empresa). */
    private const EDIT_MODULE = 'config_company';

    protected function modelClass(): string
    {
        return SegmentsModel::class;
    }

    protected function configureFields(): void
    {
        $this->validator
            ->setField('name', DataTypes::String, false)
            ->setField('default_credit_days', DataTypes::Integer, false)
            ->setField('usd_exchange_rate', DataTypes::String, false);
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

        if (!RbacAuthHelper::can($auth, self::EDIT_MODULE, 'edit')) {
            return Result::fail('No tienes permiso para editar parámetros comerciales (config_company).', 403);
        }

        $segmentId = (int) $id;
        $existing = Capsule::table('segments')->where('id', $segmentId)->first();
        if ($existing === null) {
            return Result::fail('Segmento no encontrado.', 404);
        }
        if (!$this->isSegmentInScope($auth, $segmentId)) {
            return Result::fail('No puedes editar un segmento fuera de tu alcance.', 403);
        }

        $hasDays = array_key_exists('default_credit_days', $request);
        $hasFx = array_key_exists('usd_exchange_rate', $request);
        if (!$hasDays && !$hasFx) {
            return Result::fail('Envía default_credit_days y/o usd_exchange_rate.', 422);
        }

        $payload = ['updated_at' => gmdate('Y-m-d H:i:s')];

        if ($hasDays) {
            $days = filter_var($request['default_credit_days'], FILTER_VALIDATE_INT);
            if ($days === false || $days < 0) {
                return Result::fail('default_credit_days debe ser un entero >= 0.', 422);
            }
            $payload['default_credit_days'] = $days;
        }

        if ($hasFx) {
            $fx = $this->normalizeFx((string) $request['usd_exchange_rate']);
            if ($fx === null) {
                return Result::fail('usd_exchange_rate debe ser un decimal >= 0 con hasta 4 decimales.', 422);
            }
            $payload['usd_exchange_rate'] = $fx;
        }

        Capsule::table('segments')->where('id', $segmentId)->update($payload);

        return Result::ok($this->findMapped($segmentId), 'Parámetros comerciales actualizados');
    }

    /**
     * @param array<string, mixed> $auth
     * @return list<array{id:int,name:string,default_credit_days:int,usd_exchange_rate:string}>
     */
    private function listSegments(array $auth): array
    {
        $builder = Capsule::table('segments')->orderBy('id');
        $lockedId = $auth['segment_id'] ?? null;
        if ($lockedId !== null) {
            $builder->where('id', (int) $lockedId);
        }

        return $builder
            ->get(['id', 'name', 'default_credit_days', 'usd_exchange_rate'])
            ->map(fn (object $row): array => $this->mapRow($row))
            ->all();
    }

    /**
     * @return array{id:int,name:string,default_credit_days:int,usd_exchange_rate:string}|null
     */
    private function findMapped(int $id): ?array
    {
        $row = Capsule::table('segments')->where('id', $id)->first([
            'id',
            'name',
            'default_credit_days',
            'usd_exchange_rate',
        ]);
        if ($row === null) {
            return null;
        }

        return $this->mapRow($row);
    }

    /**
     * @return array{id:int,name:string,default_credit_days:int,usd_exchange_rate:string}
     */
    private function mapRow(object $row): array
    {
        $fx = isset($row->usd_exchange_rate) ? (string) $row->usd_exchange_rate : '19.5000';
        if (!str_contains($fx, '.')) {
            $fx .= '.0000';
        }

        return [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'default_credit_days' => (int) $row->default_credit_days,
            'usd_exchange_rate' => $this->normalizeFx($fx) ?? '19.5000',
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
        $lockedId = $auth['segment_id'] ?? null;
        if ($lockedId === null) {
            return true;
        }

        return (int) $lockedId === $segmentId;
    }
}
