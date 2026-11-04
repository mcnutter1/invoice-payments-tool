<?php

namespace App\Services\PublicAccess;

use App\Models\ContractVersion;
use App\Models\DocumentAccessEvent;
use App\Models\EmailOutbox;
use App\Models\Invoice;
use App\Services\Audit;
use Illuminate\Http\Request;

/** Records client-side interactions (opens, views, clicks) as activity and in the audit log, with IP, location and network. */
class ClientActivity
{
    private const SCANNER_AGENTS = [
        'safelinks' => 'Microsoft Defender Safe Links pre-check',
        'proofpoint' => 'Proofpoint link scan', 'mimecast' => 'Mimecast link scan', 'barracuda' => 'Barracuda link scan',
        'googleimageproxy' => 'Google image proxy fetch',
        'facebookexternalhit' => 'Link preview', 'slackbot' => 'Link preview', 'preview' => 'Link preview',
        'bot' => 'Automated bot', 'crawler' => 'Automated crawler', 'spider' => 'Automated crawler', 'scanner' => 'Security scanner',
        'headless' => 'Headless browser scan', 'curl' => 'Script request', 'wget' => 'Script request', 'python' => 'Script request',
    ];

    /** Email-security vendors and clouds that pre-check links; matched against the IP's network owner. */
    private const SCANNER_NETWORKS = [
        'proofpoint' => 'Proofpoint', 'mimecast' => 'Mimecast', 'barracuda' => 'Barracuda', 'cisco' => 'Cisco email security',
        'trend micro' => 'Trend Micro', 'forcepoint' => 'Forcepoint', 'symantec' => 'Symantec', 'broadcom' => 'Symantec',
        'fortinet' => 'Fortinet', 'sophos' => 'Sophos', 'zix' => 'Zix', 'amazon' => 'Cloud-hosted scanner (AWS)',
        'digitalocean' => 'Cloud-hosted scanner', 'ovh' => 'Cloud-hosted scanner', 'hetzner' => 'Cloud-hosted scanner', 'linode' => 'Cloud-hosted scanner',
    ];

    public function __construct(private readonly GeoIp $geo) {}

    /**
     * Decide whether a hit is an email-security pre-check rather than a person.
     *
     * @param array<string,mixed> $attrs event, email_outbox_id, invoice_id, contract_version_id, detail
     * @return array{0: bool, 1: ?string} [automated, reason]
     */
    public function classify(Request $request, array $attrs): array
    {
        $ua = (string) $request->userAgent();
        $lua = strtolower($ua);
        $event = $attrs['event'];
        $isOpen = $event === 'email_opened';
        // Gmail fetches images through its proxy only when the recipient opens the message.
        if ($isOpen && str_contains($lua, 'googleimageproxy')) return [false, null];
        if (trim($ua) === '') return [true, 'No browser identity (automated)'];
        foreach (self::SCANNER_AGENTS as $needle => $reason) {
            if (str_contains($lua, $needle)) return [true, $reason];
        }
        $net = strtolower((string) $this->geo->lookup($request->ip())['network']);
        foreach (self::SCANNER_NETWORKS as $needle => $name) {
            if ($net !== '' && str_contains($net, $needle)) return [true, $name.' pre-check'];
        }
        // Outlook.com/Gmail load images via their own servers when a person opens; link and page hits from them are scans.
        if (! $isOpen && (str_contains($net, 'microsoft') || str_contains($net, 'google'))) {
            return [true, (str_contains($net, 'microsoft') ? 'Microsoft' : 'Google').' email security pre-check'];
        }
        if ($isOpen && (str_contains($net, 'apple') || $ua === 'Mozilla/5.0')) {
            return [true, 'Apple Mail Privacy Protection preload'];
        }
        $window = (int) config('finance.tracking.prefetch_seconds', 30);
        if ($window > 0 && ($sent = $this->deliveredAt($attrs)) && $sent->gt(now()->subSeconds($window))) {
            return [true, 'Within '.max(0, (int) $sent->diffInSeconds(now())).'s of delivery (automatic pre-check)'];
        }
        if ($event === 'email_clicked' && ! empty($attrs['email_outbox_id'])
            && DocumentAccessEvent::where('email_outbox_id', $attrs['email_outbox_id'])->where('event', 'email_clicked')
                ->where('detail', '!=', (string) ($attrs['detail'] ?? ''))->where('created_at', '>', now()->subSeconds(5))->exists()) {
            return [true, 'Several links opened at once'];
        }

        return [false, null];
    }

    private function deliveredAt(array $attrs): ?\Illuminate\Support\Carbon
    {
        $q = EmailOutbox::whereNotNull('accepted_at');
        if (! empty($attrs['email_outbox_id'])) $q->whereKey($attrs['email_outbox_id']);
        elseif (! empty($attrs['invoice_id'])) $q->where('invoice_id', $attrs['invoice_id']);
        elseif (! empty($attrs['contract_version_id'])) $q->where('contract_version_id', $attrs['contract_version_id']);
        else return null;
        $at = $q->max('accepted_at');

        return $at ? \Illuminate\Support\Carbon::parse($at) : null;
    }

    /** @param array<string,mixed> $attrs DocumentAccessEvent attributes (business_id, event, invoice_id, ...) */
    public function record(Request $request, array $attrs, ?string $email = null): DocumentAccessEvent
    {
        $ua = (string) $request->userAgent();
        if (! array_key_exists('probable_automation', $attrs)) {
            [$attrs['probable_automation'], $attrs['automation_reason']] = $this->classify($request, $attrs);
        }
        $e = DocumentAccessEvent::create($attrs + [
            'ip' => $request->ip(),
            'user_agent' => mb_substr($ua, 0, 255),
        ] + $this->geo->lookup($request->ip()));

        $object = match (true) {
            (bool) $e->invoice_id => Invoice::where('business_id', $e->business_id)->find($e->invoice_id),
            (bool) $e->contract_version_id => ContractVersion::where('business_id', $e->business_id)->find($e->contract_version_id),
            default => null,
        };
        Audit::log($e->business_id, 'client.'.$e->event.($e->probable_automation ? '_prescan' : ''), $object, null, array_filter([
            'detail' => $e->detail,
            'email' => $email,
            'source' => self::source($ua, $e->probable_automation, $e->automation_reason),
        ]), null, 'client');

        return $e;
    }

    /** Distinguishes a real client from mail-provider proxies and scanners. */
    public static function source(?string $ua, bool $automated, ?string $reason = null): string
    {
        return match (true) {
            $automated && $reason => 'Pre-scan: '.$reason,
            stripos((string) $ua, 'googleimageproxy') !== false => 'Gmail image proxy (opened in Gmail; IP and location are Google’s)',
            $automated => 'Automated scanner or link preview',
            default => 'Client device',
        };
    }
}
