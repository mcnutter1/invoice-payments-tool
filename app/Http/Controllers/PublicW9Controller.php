<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Models\Business;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PublicToken;
use App\Services\Audit;
use App\Services\PublicAccess\ClientActivity;
use App\Services\PublicAccess\PublicLinkService;
use App\Services\Tax\W9Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/** Client-side W-9: expiring download links, and requesting one from an invoice page. */
class PublicW9Controller extends Controller
{
    public function __construct(private readonly PublicLinkService $links, private readonly W9Service $w9) {}

    private function token(string $raw): ?PublicToken
    {
        $t = $this->links->find($raw, 'w9');

        return $t && (! $t->max_uses || $t->use_count < $t->max_uses) ? $t : null;
    }

    private function unavailable(?PublicToken $t = null)
    {
        $business = $t ? Business::find($t->business_id) : null;

        return response()->view('public.w9-unavailable', ['business' => $business], 404);
    }

    /** Landing page only: link scanners that open the email link never receive the PDF. */
    public function show(string $raw)
    {
        $t = $this->token($raw) ?? PublicToken::where('token_hash', hash('sha256', $raw))->where('purpose', 'w9')->first();
        if (! $t || ! $t->isValid() || ($t->max_uses && $t->use_count >= $t->max_uses)) return $this->unavailable($t);
        $business = Business::findOrFail($t->business_id);

        return view('public.w9', ['business' => $business, 'token' => $t, 'raw' => $raw, 'remaining' => $this->w9->remaining($t),
            'logo' => app(\App\Services\Documents\DocumentStore::class)->dataUri($business->logo), 'accent' => $business->brand('accent')]);
    }

    public function download(Request $request, string $raw)
    {
        $t = $this->token($raw);
        if (! $t) return $this->unavailable(PublicToken::where('token_hash', hash('sha256', $raw))->first());
        $business = Business::findOrFail($t->business_id);
        $client = Client::where('business_id', $business->id)->find($t->client_id);
        $bytes = $this->w9->render($business, $client);
        $t->forceFill(['use_count' => $t->use_count + 1, 'first_used_at' => $t->first_used_at ?? now(), 'last_used_at' => now()])->save();
        $this->record($request, $t, $business, $client);

        return $this->pdfResponse($bytes, $business);
    }

    /** "Request W-9" on the invoice page: emails an expiring link to the invoice recipient and offers it right away. */
    public function requestFromInvoice(Request $request, string $ulid)
    {
        [$invoice, $t, $business] = $this->invoiceContext($request, $ulid);
        $key = 'w9-request:'.$invoice->id;
        if (RateLimiter::tooManyAttempts($key, 3)) throw new DomainException('A W-9 was already requested several times today. Please check your email or contact '.$business->name().'.');
        RateLimiter::hit($key, 86400);
        $to = $t->recipient_email ?: ($invoice->deliveryRecipients()[0] ?? null);
        if (! $to) throw new DomainException('We couldn’t find an email address for this invoice. Please contact '.$business->name().'.');
        $client = Client::where('business_id', $business->id)->findOrFail($invoice->client_id);
        $this->w9->send($business, $client, [$to], 'client', $invoice);

        return redirect('/i/v/'.$invoice->ulid.'#w9')->with('status', 'We emailed '.$business->name().'’s W-9 to '.$to.'. You can also download it now.')->with('w9_ready', true);
    }

    public function downloadFromInvoice(Request $request, string $ulid)
    {
        [$invoice, $t, $business] = $this->invoiceContext($request, $ulid);
        $client = Client::where('business_id', $business->id)->find($invoice->client_id);
        $bytes = $this->w9->render($business, $client);
        $this->record($request, $t, $business, $client, $invoice);

        return $this->pdfResponse($bytes, $business);
    }

    private function invoiceContext(Request $request, string $ulid): array
    {
        $invoice = Invoice::where('ulid', strtolower($ulid))->first() ?? abort(404);
        $t = $this->links->granted($request, 'invoice_view', fn ($t) => $t->invoice_id === $invoice->id) ?? abort(404);
        $business = Business::findOrFail($invoice->business_id);
        $s = $this->w9->settings($business);
        abort_unless($invoice->status === 'issued' && $s['allow_client_requests'] && $this->w9->ready($business), 404);

        return [$invoice, $t, $business];
    }

    private function record(Request $request, PublicToken $t, Business $business, ?Client $client, ?Invoice $invoice = null): void
    {
        $e = app(ClientActivity::class)->record($request, [
            'business_id' => $business->id, 'public_token_id' => $t->id, 'invoice_id' => $invoice?->id ?? $t->invoice_id, 'event' => 'w9_downloaded',
        ], $t->recipient_email);
        if ($client) {
            Audit::log($business, 'w9.downloaded', $client, null, ['email' => $t->recipient_email, 'source' => ClientActivity::source($request->userAgent(), $e->probable_automation, $e->automation_reason)], null, 'client');
        }
    }

    private function pdfResponse(string $bytes, Business $business)
    {
        $name = 'W-9 '.preg_replace('/[^A-Za-z0-9 .-]+/', '', $business->name()).'.pdf';

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
