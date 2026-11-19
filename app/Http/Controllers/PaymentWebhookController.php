<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Services\Payments\StripeError;
use App\Services\Payments\StripeGateway;
use App\Services\Payments\StripePayments;
use App\Services\Payments\VenmoFeed;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/** Machine-to-machine payment endpoints: Stripe webhooks and the Venmo receipt feed. No session, no CSRF. */
class PaymentWebhookController extends Controller
{
    public function stripe(Request $request, string $ulid, StripePayments $stripe)
    {
        $business = Business::where('ulid', strtolower($ulid))->first();
        $secret = $business?->stripeWebhookSecret();
        $payload = $request->getContent();
        if (! $secret || ! StripeGateway::verifySignature($payload, $request->header('Stripe-Signature'), $secret, (int) config('finance.stripe.webhook_tolerance_seconds', 300))) {
            return response()->json(['error' => 'invalid signature'], 400);
        }
        $event = json_decode($payload, true);
        if (! is_array($event) || ($event['object'] ?? null) !== 'event') return response()->json(['error' => 'invalid payload'], 400);
        try {
            $result = $stripe->handleEvent($business, $event);
        } catch (StripeError $e) {
            Log::warning('stripe.webhook_failed', ['business' => $business->id, 'event' => $event['id'] ?? null, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'retry'], 503); // Stripe retries with backoff
        }

        return response()->json(['received' => true, 'result' => $result]);
    }

    /**
     * POST /api/v1/venmo/payments  (Authorization: Bearer vnm_…)
     * {transaction_id, amount, date?, note?, sender?, fee?}
     */
    public function venmo(Request $request, VenmoFeed $feed)
    {
        $business = VenmoFeed::findBusiness($request->bearerToken());
        if (! $business) return response()->json(['error' => 'unauthorized'], 401);
        $v = Validator::make($request->all(), [
            'transaction_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'date' => ['nullable', 'date', 'before_or_equal:tomorrow', 'after:-1 year'],
            'note' => ['nullable', 'string', 'max:500'],
            'sender' => ['nullable', 'string', 'max:120'],
            'fee' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ]);
        if ($v->fails()) return response()->json(['error' => 'invalid', 'fields' => $v->errors()], 422);
        $d = $v->validated();
        $result = $feed->ingest($business, [
            'transaction_id' => $d['transaction_id'],
            'amount_cents' => Money::parse((string) $d['amount']),
            'date' => $d['date'] ?? null,
            'note' => $d['note'] ?? null,
            'sender' => $d['sender'] ?? null,
            'fee_cents' => isset($d['fee']) ? Money::parse((string) $d['fee']) : null,
        ]);
        $code = match ($result['status']) { 'recorded', 'pending_review' => 201, 'unmatched' => 202, default => 200 };

        return response()->json($result, $code);
    }
}
