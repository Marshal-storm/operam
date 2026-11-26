<?php

declare(strict_types=1);

namespace System\Support\Integrations;

/**
 * Puerto de salida hacia proveedores externos (modo producción).
 * El dispatcher depende de esta interfaz para poder sustituirla en pruebas.
 */
interface IntegrationTransport
{
    /**
     * POST JSON. Lanza RuntimeException ante error de red o HTTP ≥ 400.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed> $payload
     * @return array{status: int, body: mixed}
     */
    public function postJson(string $url, array $headers, array $payload, int $timeoutSeconds): array;

    /**
     * GET JSON. Lanza RuntimeException ante error de red o HTTP ≥ 400.
     *
     * @param array<string, string> $headers
     * @return array{status: int, body: mixed}
     */
    public function getJson(string $url, array $headers, int $timeoutSeconds): array;

    /**
     * Envía un correo vía SMTP.
     *
     * @param array{host: string, port: int, encryption: string, username: string, password: string|null, from_email: string, from_name: string} $smtp
     * @param array{to: list<string>, subject: string, text?: string, html?: string} $message
     * @return array{message_id: string|null}
     */
    public function sendEmail(array $smtp, array $message): array;

    /**
     * Abre y cierra una sesión SMTP (EHLO + AUTH) sin enviar correo.
     *
     * @param array{host: string, port: int, encryption: string, username: string, password: string|null, from_email: string, from_name: string} $smtp
     */
    public function probeSmtp(array $smtp): void;
}
