<?php

namespace App\Services\PublicAccess;

use App\Models\ContractVersion;
use App\Models\DocumentAccessEvent;
use App\Models\Invoice;
use App\Services\Audit;
use Illuminate\Http\Request;

/** Records client-side interactions (opens, views, clicks) as activity and in the audit log, with IP, location and network. */
class ClientActivity
{
    public function __construct(private readonly GeoIp $geo) {}

    /** @param array<string,mixed> $attrs DocumentAccessEvent attributes (business_id, event, invoice_id, ...) */
    public function record(Request $request, array $attrs, ?string $email = null): DocumentAccessEvent
    {
        $ua = (string) $request->userAgent();
        $e = DocumentAccessEvent::create($attrs + [
            'probable_automation' => PublicLinkService::looksAutomated($ua),
            'ip' => $request->ip(),
            'user_agent' => mb_substr($ua, 0, 255),
        ] + $this->geo->lookup($request->ip()));

        $object = match (true) {
            (bool) $e->invoice_id => Invoice::where('business_id', $e->business_id)->find($e->invoice_id),
            (bool) $e->contract_version_id => ContractVersion::where('business_id', $e->business_id)->find($e->contract_version_id),
            default => null,
        };
        Audit::log($e->business_id, 'client.'.$e->event, $object, null, array_filter([
            'detail' => $e->detail,
            'email' => $email,
            'source' => self::source($ua, $e->probable_automation),
        ]), null, 'client');

        return $e;
    }

    /** Distinguishes a real client from mail-provider proxies and scanners. */
    public static function source(?string $ua, bool $automated): string
    {
        return match (true) {
            stripos((string) $ua, 'googleimageproxy') !== false => 'Gmail image proxy (opened in Gmail; IP and location are Google’s)',
            $automated => 'Automated scanner or link preview',
            default => 'Client device',
        };
    }
}
