<?php

declare(strict_types=1);

namespace System\Support\Integrations;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Adaptador productivo: Guzzle para HTTP (ERP Emisor, ERP Master, Slack, Banxico)
 * y Symfony Mailer para SMTP (Pág. 24 del PDF).
 */
final class DefaultIntegrationTransport implements IntegrationTransport
{
    /** Límite de cuerpo de respuesta persistido en `outbox.response_json`. */
    private const MAX_BODY_CHARS = 4000;

    public function postJson(string $url, array $headers, array $payload, int $timeoutSeconds): array
    {
        return $this->request('POST', $url, [
            'headers' => array_merge(['Accept' => 'application/json'], $headers),
            'json' => $payload,
        ], $timeoutSeconds);
    }

    public function getJson(string $url, array $headers, int $timeoutSeconds): array
    {
        return $this->request('GET', $url, [
            'headers' => array_merge(['Accept' => 'application/json'], $headers),
        ], $timeoutSeconds);
    }

    public function sendEmail(array $smtp, array $message): array
    {
        $transport = $this->buildSmtpTransport($smtp);
        $email = (new Email())
            ->from(new Address($smtp['from_email'], $smtp['from_name']))
            ->subject($message['subject']);
        foreach ($message['to'] as $recipient) {
            $email->addTo($recipient);
        }
        if (!empty($message['html'])) {
            $email->html((string) $message['html']);
        }
        $email->text((string) ($message['text'] ?? strip_tags((string) ($message['html'] ?? ''))));

        try {
            $sent = $transport->send($email);
        } catch (Throwable $e) {
            throw new RuntimeException('SMTP: ' . $e->getMessage(), 0, $e);
        } finally {
            $transport->stop();
        }

        return ['message_id' => $sent?->getMessageId()];
    }

    public function probeSmtp(array $smtp): void
    {
        $transport = $this->buildSmtpTransport($smtp);
        try {
            $transport->start();
        } catch (Throwable $e) {
            throw new RuntimeException('SMTP: ' . $e->getMessage(), 0, $e);
        } finally {
            $transport->stop();
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array{status: int, body: mixed}
     */
    private function request(string $method, string $url, array $options, int $timeoutSeconds): array
    {
        $client = new Client([
            'timeout' => max(1, $timeoutSeconds),
            'connect_timeout' => min(10, max(1, $timeoutSeconds)),
            'http_errors' => false,
        ]);

        try {
            $response = $client->request($method, $url, $options);
        } catch (GuzzleException $e) {
            throw new RuntimeException('HTTP: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);
        $body = is_array($decoded) ? $decoded : mb_substr($raw, 0, self::MAX_BODY_CHARS);

        if ($status >= 400) {
            throw new RuntimeException(sprintf(
                'HTTP %d: %s',
                $status,
                mb_substr(is_string($body) ? $body : (string) json_encode($body, JSON_UNESCAPED_UNICODE), 0, 300),
            ));
        }

        return ['status' => $status, 'body' => $body];
    }

    /**
     * `ssl` = TLS implícito (465); `tls` = STARTTLS; `none` = sin cifrado.
     *
     * @param array{host: string, port: int, encryption: string, username: string, password: string|null} $smtp
     */
    private function buildSmtpTransport(array $smtp): EsmtpTransport
    {
        $encryption = $smtp['encryption'];
        $transport = new EsmtpTransport($smtp['host'], $smtp['port'], $encryption === 'ssl');
        if ($encryption === 'none') {
            $transport->setAutoTls(false);
        }
        if ($smtp['username'] !== '') {
            $transport->setUsername($smtp['username']);
            $transport->setPassword((string) ($smtp['password'] ?? ''));
        }

        return $transport;
    }
}
