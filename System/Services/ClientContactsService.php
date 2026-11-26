<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use System\Core\Context;
use System\Core\DataTypes;
use System\Core\Result;
use System\Models\ClientContactsModel;
use System\Support\AuditLogger;
use System\Support\PortalMagicLinkIssuer;

/**
 * CRUD de contactos del cliente — `/api/client-contacts` (RF-1.5).
 * RBAC vía módulo `clients` + filtro de segmento del cliente padre.
 */
final class ClientContactsService extends ModelService
{
    private const MODULE_KEY = 'clients';

    private const ROLES = ['billing', 'admin', 'technical'];

    /** @var array<string, mixed> */
    private array $authContext = [];

    protected function modelClass(): string
    {
        return ClientContactsModel::class;
    }

    protected function configureFields(): void
    {
        $this->validator
            ->setField('client_id', DataTypes::Integer, true)
            ->setField('erp_contact_id', DataTypes::String, false)
            ->setField('name', DataTypes::String, true)
            ->setField('email', DataTypes::String, true)
            ->setField('phone', DataTypes::String, false)
            ->setField('role', DataTypes::String, false, 'admin')
            ->setField('receives_portal', DataTypes::Boolean, false, true)
            ->setField('active', DataTypes::Boolean, false, true);
    }

    public function handle(Context $context): Result
    {
        $this->authContext = $context->getData('auth', []);
        $method = $context->getMethod();
        $id = $context->getData('_resource_id');
        $request = RbacAuthHelper::applySegmentFilterToQuery(
            $this->authContext,
            $context->getRequest(),
        );

        if ($method === 'GET' && $id === null) {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'view')) {
                return Result::fail('Sin permiso para consultar contactos.', 403);
            }
            $clientId = (int) ($request['client_id'] ?? 0);
            if ($clientId < 1) {
                return Result::fail('client_id es requerido.', 422);
            }
            $scopeErr = $this->assertClientInScope($clientId);
            if ($scopeErr !== null) {
                return $scopeErr;
            }

            return Result::ok($this->listByClient($clientId), 'Contactos del cliente');
        }

        if ($method === 'POST' && $id === null) {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'create')) {
                return Result::fail('Sin permiso para crear contactos.', 403);
            }
            $prepared = $this->preparePayload($request, false);
            if ($prepared instanceof Result) {
                return $prepared;
            }
            $scopeErr = $this->assertClientInScope((int) $prepared['client_id']);
            if ($scopeErr !== null) {
                return $scopeErr;
            }
            $created = $this->create($prepared, $context);
            if ($created->isSuccess()) {
                $newId = (int) (($created->getData()['id'] ?? 0));
                if ($newId > 0) {
                    $this->touchUpdatedAt($newId);
                    $this->auditContact($newId, 'create', null);
                }
            }

            return $created;
        }

        if ($id === null) {
            return Result::fail('Se requiere id de contacto.', 400);
        }

        $contactId = (int) $id;
        $existing = Capsule::table('client_contacts')->where('id', $contactId)->first();
        if ($existing === null) {
            return Result::fail('Contacto no encontrado.', 404);
        }
        $scopeErr = $this->assertClientInScope((int) $existing->client_id);
        if ($scopeErr !== null) {
            return $scopeErr;
        }

        // POST /api/client-contacts/{id}/generate-portal-link (RF-7.1, acción interna del ERP).
        $action = strtolower(explode('/', trim($context->getUri(), '/'))[2] ?? '');
        if ($action === 'generate-portal-link') {
            return $method === 'POST'
                ? $this->generatePortalLink($existing, $request)
                : Result::fail('Método no soportado.', 405);
        }

        if ($method === 'PUT' || $method === 'PATCH') {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'edit')) {
                return Result::fail('Sin permiso para editar contactos.', 403);
            }
            $before = $this->contactSnapshot($contactId);
            $payload = $request;
            $payload['client_id'] = (int) $existing->client_id;
            $prepared = $this->preparePayload($payload, $method === 'PATCH');
            if ($prepared instanceof Result) {
                return $prepared;
            }
            // No permitir mover el contacto a otro cliente.
            $prepared['client_id'] = (int) $existing->client_id;
            $result = $method === 'PUT'
                ? $this->update($contactId, $prepared, $context)
                : $this->patch($contactId, $prepared, $context);
            if ($result->isSuccess()) {
                $this->touchUpdatedAt($contactId);
                $action = isset($prepared['active']) && count($prepared) <= 2
                    ? 'status_change'
                    : 'update';
                $this->auditContact($contactId, $action, $before);
            }

            return $result;
        }

        if ($method === 'DELETE') {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'delete')) {
                return Result::fail('Sin permiso para eliminar contactos.', 403);
            }
            $before = $this->contactSnapshot($contactId);
            // Soft-delete: inactiva (conserva historial para Magic Links futuros).
            $patched = $this->patch($contactId, ['active' => false], $context);
            if ($patched->isSuccess()) {
                $this->touchUpdatedAt($contactId);
                $this->auditContact($contactId, 'status_change', $before);
            }

            return $patched;
        }

        return Result::fail('Método no soportado.', 405);
    }

    /**
     * Genera una liga mágica de un solo uso (vigencia dinámica desde `system_settings`).
     * Si `send_email === true` (default) encola el correo en `outbox`; si es `false`, solo
     * genera el token y devuelve la URL lista para copiar sin encolar correo.
     * Requiere `canEdit('clients')`.
     *
     * @param array<string, mixed> $request
     */
    private function generatePortalLink(object $contact, array $request = []): Result
    {
        if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'edit')) {
            return Result::fail('Sin permiso para generar ligas del portal.', 403);
        }
        if (!(bool) $contact->active || !(bool) $contact->receives_portal) {
            return Result::fail(
                'El contacto debe estar activo y autorizado para el Portal Cliente.',
                422,
            );
        }

        $sendEmail = true;
        if (array_key_exists('send_email', $request)) {
            $parsed = filter_var($request['send_email'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed !== null) {
                $sendEmail = $parsed;
            }
        }

        $issued = PortalMagicLinkIssuer::issueForContact($contact, null, $sendEmail);
        $ttlMinutes = (int) $issued['ttl_minutes'];

        $label = sprintf('%s <%s>', (string) $contact->name, (string) $contact->email);
        $modeLabel = $sendEmail ? 'Generar y enviar por correo' : 'Solo generar';
        AuditLogger::log(
            $this->authContext,
            self::MODULE_KEY,
            'client_contact',
            (string) $contact->id,
            'create',
            $sendEmail
                ? "Se generó y envió por correo una liga mágica de portal para {$label}"
                : "Se generó (sin envío de correo) una liga mágica de portal para {$label}",
            null,
            // Sin la liga ni claves con "token": la bitácora solo registra el hecho, modo y vigencia.
            [
                'contact_id' => (int) $contact->id,
                'link_expires_at' => $issued['expires_at'],
                'ttl_minutes' => $ttlMinutes,
                'email_queued' => $sendEmail,
                'mode' => $modeLabel,
            ],
        );

        return Result::ok([
            'url' => $issued['url'],
            'expires_at' => $issued['expires_at'],
            'ttl_minutes' => $ttlMinutes,
            'expires_in_seconds' => $ttlMinutes * 60,
            'email_queued' => $sendEmail,
            'contact' => [
                'id' => (int) $contact->id,
                'name' => (string) $contact->name,
                'email' => (string) $contact->email,
            ],
        ], $sendEmail ? 'Liga mágica generada y enviada por correo' : 'Liga mágica generada');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|Result
     */
    private function preparePayload(array $input, bool $partial): array|Result
    {
        $out = [];
        if (!$partial || array_key_exists('client_id', $input)) {
            $clientId = (int) ($input['client_id'] ?? 0);
            if ($clientId < 1) {
                return Result::fail('client_id es requerido.', 422);
            }
            $out['client_id'] = $clientId;
        }
        if (!$partial || array_key_exists('name', $input)) {
            $name = trim((string) ($input['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 140) {
                return Result::fail('El nombre del contacto es obligatorio (máx. 140).', 422);
            }
            $out['name'] = $name;
        }
        if (!$partial || array_key_exists('email', $input)) {
            $email = strtolower(trim((string) ($input['email'] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 160) {
                return Result::fail('El correo del contacto no es válido.', 422);
            }
            $out['email'] = $email;
        }
        if (array_key_exists('phone', $input)) {
            $phone = trim((string) ($input['phone'] ?? ''));
            $out['phone'] = $phone !== '' ? mb_substr($phone, 0, 30) : null;
        } elseif (!$partial) {
            $out['phone'] = null;
        }
        if (array_key_exists('erp_contact_id', $input)) {
            $erp = trim((string) ($input['erp_contact_id'] ?? ''));
            $out['erp_contact_id'] = $erp !== '' ? mb_substr($erp, 0, 64) : null;
        }
        if (!$partial || array_key_exists('role', $input)) {
            $role = strtolower(trim((string) ($input['role'] ?? 'admin')));
            if (!in_array($role, self::ROLES, true)) {
                return Result::fail('role debe ser billing, admin o technical.', 422);
            }
            $out['role'] = $role;
        }
        if (array_key_exists('receives_portal', $input)) {
            $out['receives_portal'] = filter_var($input['receives_portal'], FILTER_VALIDATE_BOOLEAN);
        } elseif (!$partial) {
            $out['receives_portal'] = true;
        }
        if (array_key_exists('active', $input)) {
            $out['active'] = filter_var($input['active'], FILTER_VALIDATE_BOOLEAN);
        } elseif (!$partial) {
            $out['active'] = true;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listByClient(int $clientId): array
    {
        $rows = Capsule::table('client_contacts')
            ->where('client_id', $clientId)
            ->orderByRaw('COALESCE(updated_at, created_at) DESC')
            ->orderByDesc('id')
            ->get();

        return $rows->map(static fn (object $row): array => self::mapRow($row))->all();
    }

    private function assertClientInScope(int $clientId): ?Result
    {
        $client = Capsule::table('clients')->where('id', $clientId)->first(['id', 'segment_id']);
        if ($client === null) {
            return Result::fail('Cliente no encontrado.', 404);
        }
        $authSegment = $this->authContext['segment_id'] ?? null;
        if ($authSegment !== null && (int) $client->segment_id !== (int) $authSegment) {
            return Result::fail('Cliente fuera de tu alcance de segmento.', 403);
        }

        return null;
    }

    private function touchUpdatedAt(int $id): void
    {
        Capsule::table('client_contacts')->where('id', $id)->update([
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function contactSnapshot(int $id): ?array
    {
        $row = Capsule::table('client_contacts')->where('id', $id)->first();

        return $row !== null ? self::mapRow($row) : null;
    }

    /**
     * @param array<string, mixed>|null $before
     */
    private function auditContact(int $id, string $action, ?array $before): void
    {
        $after = $this->contactSnapshot($id);
        $name = (string) ($after['name'] ?? $before['name'] ?? 'contacto');
        $email = (string) ($after['email'] ?? $before['email'] ?? '');
        $label = $email !== '' ? "{$name} <{$email}>" : $name;
        $active = (bool) ($after['active'] ?? false);
        $description = match ($action) {
            'create' => "Se creó el contacto {$label}",
            'status_change' => $active
                ? "Se activó el contacto {$label}"
                : "Se inactivó el contacto {$label}",
            default => "Se actualizó el contacto {$label}",
        };

        AuditLogger::log(
            $this->authContext,
            self::MODULE_KEY,
            'client_contact',
            (string) $id,
            $action,
            $description,
            $before,
            $after,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function mapRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'client_id' => (int) $row->client_id,
            'erp_contact_id' => $row->erp_contact_id !== null ? (string) $row->erp_contact_id : null,
            'name' => (string) $row->name,
            'email' => (string) $row->email,
            'phone' => $row->phone !== null ? (string) $row->phone : null,
            'role' => (string) $row->role,
            'receives_portal' => (bool) $row->receives_portal,
            'active' => (bool) $row->active,
            'created_at' => (string) $row->created_at,
            'updated_at' => (string) $row->updated_at,
        ];
    }
}
