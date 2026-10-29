<?php

namespace App\Services\Notifications;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * McNutt Cloud notification service. Maps ONLY the documented fields: channel, to, from, subject, body, provider.
 * No attachments, HTML semantics, idempotency or delivery webhooks are assumed (docs/INTEGRATIONS.md).
 */
class McNuttNotificationGateway implements NotificationGateway
{
    public function __construct(private readonly array $config) {}

    public function isReal(): bool
    {
        return true;
    }

    private function endpoint(): string
    {
        $base = $this->config['base_url'];
        $host = parse_url($base, PHP_URL_HOST);
        if (parse_url($base, PHP_URL_SCHEME) !== 'https' || ! in_array($host, $this->config['allowed_hosts'], true)) {
            throw new \RuntimeException('Notification host is not allowlisted');
        }

        return $base.'/api/send.php';
    }

    public function send(OutgoingMessage $m): SendResult
    {
        if (! $this->config['enabled']) return new SendResult('permanent', null, null, 'notifications_disabled');
        if (empty($this->config['api_token'])) return new SendResult('permanent', null, null, 'notifications_unconfigured');

        $payload = [
            'channel' => 'email',
            'to' => $m->to,
            'from' => $m->from,
            'subject' => $m->subject,
            'body' => $m->textBody,
        ];
        if (! empty($this->config['provider'])) {
            $payload['provider'] = $this->config['provider'];
        }

        try {
            $res = Http::timeout($this->config['timeout'])->connectTimeout(5)
                ->withToken($this->config['api_token'])
                ->withHeaders(['X-Correlation-Id' => $m->correlationId])
                ->acceptJson()->asJson()
                ->post($this->endpoint(), $payload);
        } catch (ConnectionException $e) {
            // cURL 28 = timed out after the request may have been accepted; anything else = not delivered to service.
            $msg = $e->getMessage();
            $ambiguous = str_contains($msg, 'cURL error 28') && ! str_contains($msg, 'Connection timed out') && ! str_contains($msg, 'connect');

            return new SendResult($ambiguous ? 'ambiguous' : 'transient', null, null, $ambiguous ? 'timeout_after_send' : 'connection_failed');
        }

        $status = $res->status();
        $json = $res->json();
        $messageId = is_array($json) ? ($json['id'] ?? $json['message_id'] ?? $json['messageId'] ?? null) : null;
        if ($res->successful()) {
            if (is_array($json) && array_key_exists('ok', $json) && $json['ok'] === false) {
                return new SendResult('permanent', $status, null, mb_substr((string) ($json['error'] ?? $json['reason'] ?? 'rejected'), 0, 300));
            }

            return new SendResult('accepted', $status, $messageId ? (string) $messageId : null);
        }
        $err = is_array($json) ? (string) ($json['error'] ?? $json['reason'] ?? $json['message'] ?? 'http_'.$status) : 'http_'.$status;
        if ($status === 429 || $status >= 500) {
            return new SendResult('transient', $status, null, mb_substr($err, 0, 300));
        }

        return new SendResult('permanent', $status, null, mb_substr($err, 0, 300));
    }

    public function health(): array
    {
        if (! $this->config['enabled']) return ['status' => 'disabled', 'message' => 'NOTIFICATIONS_ENABLED=false'];
        if (empty($this->config['api_token']) || empty($this->config['default_from_email'])) {
            return ['status' => 'unconfigured', 'message' => 'NOTIFICATIONS_API_TOKEN / NOTIFICATIONS_FROM_EMAIL not set'];
        }
        try {
            $res = Http::timeout(5)->get($this->endpoint());

            return $res->status() < 500
                ? ['status' => 'healthy', 'message' => 'Send endpoint reachable (HTTP '.$res->status().' to unauthenticated GET)']
                : ['status' => 'failed', 'message' => 'Send endpoint HTTP '.$res->status()];
        } catch (\Throwable) {
            return ['status' => 'failed', 'message' => 'Send endpoint unreachable'];
        }
    }
}
