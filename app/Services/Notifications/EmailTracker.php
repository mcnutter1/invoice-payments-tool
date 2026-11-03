<?php

namespace App\Services\Notifications;

use App\Models\DocumentAccessEvent;
use App\Models\EmailOutbox;
use App\Models\PublicToken;
use App\Services\PublicAccess\ClientActivity;
use App\Services\PublicAccess\PublicLinkService;
use Illuminate\Http\Request;

/** Email open (pixel) and link-click tracking, recorded as client activity. */
class EmailTracker
{
    public const LABELS = [
        'email_opened' => 'Opened the email',
        'email_clicked' => 'Clicked a link in the email',
        'link_accessed' => 'Opened the secure link',
        'invoice_viewed' => 'Viewed the invoice',
        'pdf_downloaded' => 'Downloaded the PDF',
        'paid_pdf_downloaded' => 'Downloaded the paid invoice PDF',
        'remittance_downloaded' => 'Downloaded the remittance slip',
        'pay_clicked' => 'Clicked “Pay now”',
        'payment_details_copied' => 'Copied payment details',
        'payment_reported' => 'Reported a payment',
        'contract_viewed' => 'Opened the agreement',
        'contract_downloaded' => 'Downloaded the agreement',
    ];

    public const CLICK_ACTIONS = [
        'view' => 'View invoice',
        'pdf' => 'Download PDF',
        'pay' => 'Pay now',
        'report' => 'I’ve paid / mailed a check',
        'paid_pdf' => 'Download paid invoice',
        'sign' => 'Review & sign',
        'agreement' => 'View agreement',
    ];

    public const PIXEL_GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    /** @return array{0:string,1:string} [raw token, sha256 hash] */
    public static function newToken(): array
    {
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return [$raw, hash('sha256', $raw)];
    }

    public static function trackedUrl(string $url, string $raw, string $action): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query(['e' => $raw, 'a' => $action]);
    }

    public static function label(DocumentAccessEvent $e): string
    {
        if ($e->event === 'email_clicked' && isset(self::CLICK_ACTIONS[$e->detail])) {
            return 'Clicked “'.self::CLICK_ACTIONS[$e->detail].'” in the email';
        }
        if ($e->event === 'payment_details_copied' && $e->detail) {
            return 'Copied payment details ('.str_replace('_', ' ', $e->detail).')';
        }

        return self::LABELS[$e->event] ?? ucfirst(str_replace('_', ' ', $e->event));
    }

    public function find(mixed $raw): ?EmailOutbox
    {
        if (! is_string($raw) || ! preg_match('/^[A-Za-z0-9_-]{43}$/', $raw)) return null;

        return EmailOutbox::where('tracking_hash', hash('sha256', $raw))->first();
    }

    public function recordOpen(string $raw, Request $request): void
    {
        $o = $this->find($raw);
        if (! $o) return;
        // Image re-fetches within 10 minutes count toward the total but are not separate activity entries.
        if (! $o->last_opened_at || $o->last_opened_at->lt(now()->subMinutes(10))) {
            $this->event($o, 'email_opened', $request);
        }
        $o->forceFill([
            'opened_at' => $o->opened_at ?? now(),
            'last_opened_at' => now(),
            'open_count' => $o->open_count + 1,
        ])->save();
    }

    /** Records a click only when the tracking token belongs to the same business and document as the link. */
    public function recordClick(mixed $raw, mixed $action, PublicToken $t, Request $request): void
    {
        $o = $this->find($raw);
        if (! $o || $o->business_id !== $t->business_id) return;
        $sameDoc = ($t->invoice_id && $o->invoice_id === $t->invoice_id)
            || ($t->contract_recipient_id && $o->contract_recipient_id === $t->contract_recipient_id);
        if (! $sameDoc) return;
        $action = is_string($action) && isset(self::CLICK_ACTIONS[$action]) ? $action : 'view';
        $this->event($o, 'email_clicked', $request, $action, $t->id);
        $o->forceFill([
            'opened_at' => $o->opened_at ?? now(),
            'clicked_at' => $o->clicked_at ?? now(),
            'click_count' => $o->click_count + 1,
        ])->save();
    }

    private function event(EmailOutbox $o, string $event, Request $request, ?string $detail = null, ?int $tokenId = null): void
    {
        $ua = (string) $request->userAgent();
        // Gmail fetches images through its proxy only when the recipient opens the message.
        $automated = PublicLinkService::looksAutomated($ua) && ! ($event === 'email_opened' && stripos($ua, 'googleimageproxy') !== false);
        app(ClientActivity::class)->record($request, [
            'business_id' => $o->business_id,
            'public_token_id' => $tokenId,
            'invoice_id' => $o->invoice_id,
            'contract_version_id' => $o->contract_version_id,
            'email_outbox_id' => $o->id,
            'event' => $event,
            'detail' => $detail,
            'probable_automation' => $automated,
        ], $o->to_email);
    }
}
