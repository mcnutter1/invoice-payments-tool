<?php

namespace App\Services\Payments;

use App\Exceptions\DomainException;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\RecurringSchedule;
use App\Services\Audit;
use App\Services\Invoicing\InvoiceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/** Card payments through Stripe Checkout, saved-card autopay for recurring invoices, and webhook reconciliation. */
class StripePayments
{
    private const EXPAND_PI = ['latest_charge.balance_transaction', 'payment_method'];

    public function __construct(private readonly PaymentService $payments, private readonly InvoiceService $invoices) {}

    /** Verify a key before saving it; returns a label for the connected account. */
    public function verifyKey(string $secret): string
    {
        try {
            $acct = (new StripeGateway($secret))->get('account');

            return $acct['settings']['dashboard']['display_name'] ?? $acct['business_profile']['name'] ?? $acct['email'] ?? ($acct['id'] ?? 'Stripe account');
        } catch (StripeError $e) {
            if ($e->status === 403) return 'Stripe account (restricted key)';
            throw new DomainException('Stripe rejected that key: '.$e->getMessage());
        }
    }

    public function offersAutopay(Invoice $invoice): bool
    {
        $s = $invoice->schedule;

        return (bool) ($s && $s->card_autopay && $s->status === 'active' && ! $s->stripe_payment_method_id);
    }

    /** Hosted Stripe Checkout for the open balance. Card data never touches this app. */
    public function checkoutUrl(Invoice $invoice, ?string $payerEmail, bool $saveCard): string
    {
        $business = Business::findOrFail($invoice->business_id);
        $this->invoices->refreshBalance($invoice);
        if (! $invoice->isIssued() || $invoice->balance_cents <= 0) throw new DomainException('This invoice has no balance to pay.');
        if ($invoice->balance_cents < 50) throw new DomainException('Card payments must be at least $0.50.');
        $saveCard = $saveCard && $this->offersAutopay($invoice);
        $gw = StripeGateway::for($business);
        $meta = ['invoice_id' => (string) $invoice->id, 'invoice_number' => (string) $invoice->number, 'business' => $business->ulid, 'autopay' => $saveCard ? '1' : '0', 'payer_email' => (string) $payerEmail];
        $params = [
            'mode' => 'payment',
            'client_reference_id' => $invoice->ulid,
            'success_url' => url('/i/v/'.$invoice->ulid.'/card/return').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => url('/i/v/'.$invoice->ulid).'?card=cancelled#howtopay',
            'payment_method_types' => ['card'],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => ['currency' => 'usd', 'unit_amount' => $invoice->balance_cents, 'product_data' => ['name' => 'Invoice '.$invoice->number, 'description' => $business->name()]],
            ]],
            'metadata' => $meta,
            'payment_intent_data' => ['metadata' => $meta, 'description' => 'Invoice '.$invoice->number.' · '.$business->name()],
        ];
        if ($saveCard) {
            $params['customer'] = $this->customerId($gw, $invoice, $payerEmail);
            $params['payment_intent_data']['setup_future_usage'] = 'off_session';
        } elseif ($payerEmail) {
            $params['customer_email'] = $payerEmail;
        }
        $session = $gw->post('checkout/sessions', $params);
        Audit::log($business, 'card.checkout_started', $invoice, null, ['amount_cents' => $invoice->balance_cents, 'autopay' => $saveCard], null, 'public');

        return (string) $session['url'];
    }

    /** Return from Checkout (or its webhook): verify with Stripe, then record exactly once. */
    public function completeCheckout(Invoice $invoice, string $sessionId): ?Payment
    {
        if (! preg_match('/^cs_[A-Za-z0-9_]+$/', $sessionId)) throw new DomainException('Invalid checkout session.');
        $business = Business::findOrFail($invoice->business_id);
        $s = StripeGateway::for($business)->get('checkout/sessions/'.$sessionId, ['expand' => array_map(fn ($e) => 'payment_intent.'.$e, self::EXPAND_PI)]);
        if ((string) ($s['metadata']['invoice_id'] ?? '') !== (string) $invoice->id) throw new DomainException('This payment belongs to a different invoice.');
        if (($s['payment_status'] ?? null) !== 'paid' || ! is_array($s['payment_intent'] ?? null)) return null;
        $payment = $this->recordIntent($business, $invoice, $s['payment_intent']);
        if (($s['metadata']['autopay'] ?? '0') === '1') {
            $this->saveAutopay($invoice, $s['payment_intent'], $s['metadata']['payer_email'] ?? null);
        }

        return $payment;
    }

    /** Charge the saved card for a recurring invoice. Failures are audited and leave the invoice open. */
    public function chargeAutopay(Invoice $invoice): ?Payment
    {
        $invoice->loadMissing('schedule', 'client');
        $s = $invoice->schedule;
        $this->invoices->refreshBalance($invoice);
        if (! $s?->hasAutopay() || ! $invoice->isIssued() || $invoice->balance_cents < 50 || ! $invoice->client->stripe_customer_id) return null;
        $business = Business::findOrFail($invoice->business_id);
        try {
            $pi = StripeGateway::for($business)->post('payment_intents', [
                'amount' => $invoice->balance_cents,
                'currency' => 'usd',
                'customer' => $invoice->client->stripe_customer_id,
                'payment_method' => $s->stripe_payment_method_id,
                'off_session' => 'true',
                'confirm' => 'true',
                'description' => 'Invoice '.$invoice->number.' · '.$business->name().' (autopay)',
                'metadata' => ['invoice_id' => (string) $invoice->id, 'invoice_number' => (string) $invoice->number, 'business' => $business->ulid, 'autopay' => '1'],
                'expand' => self::EXPAND_PI,
            ], 'autopay-'.$invoice->ulid.'-'.$invoice->balance_cents);
        } catch (StripeError $e) {
            Audit::log($business, 'card.autopay_failed', $invoice, null, ['code' => $e->declineCode ?? $e->stripeCode, 'schedule_id' => $s->id], mb_substr($e->getMessage(), 0, 300), 'system');

            return null;
        }
        if (($pi['status'] ?? null) !== 'succeeded') {
            Audit::log($business, 'card.autopay_failed', $invoice, null, ['status' => $pi['status'] ?? null, 'schedule_id' => $s->id], 'Payment needs customer action', 'system');

            return null;
        }

        return $this->recordIntent($business, $invoice, $pi);
    }

    public function handleEvent(Business $business, array $event): string
    {
        $obj = $event['data']['object'] ?? [];
        $invoiceId = (int) ($obj['metadata']['invoice_id'] ?? 0);
        $invoice = $invoiceId ? Invoice::where('business_id', $business->id)->find($invoiceId) : null;
        if (! $invoice) return 'ignored';

        switch ($event['type'] ?? '') {
            case 'checkout.session.completed':
            case 'checkout.session.async_payment_succeeded':
                return $this->completeCheckout($invoice, (string) $obj['id']) ? 'recorded' : 'pending';
            case 'payment_intent.succeeded':
                if (Payment::where('business_id', $business->id)->where('external_id', 'stripe:'.$obj['id'])->exists()) return 'duplicate';
                $pi = StripeGateway::for($business)->get('payment_intents/'.$obj['id'], ['expand' => self::EXPAND_PI]);
                if (($pi['status'] ?? null) !== 'succeeded') return 'pending';
                $this->recordIntent($business, $invoice, $pi);

                return 'recorded';
            case 'payment_intent.payment_failed':
                Audit::log($business, 'card.payment_failed', $invoice, null, ['code' => $obj['last_payment_error']['decline_code'] ?? $obj['last_payment_error']['code'] ?? null], mb_substr((string) ($obj['last_payment_error']['message'] ?? ''), 0, 300), 'system');

                return 'logged';
        }

        return 'ignored';
    }

    /** Gross amount to AR, Stripe's fee to Bank & Merchant Fees, net to the payout account. */
    public function recordIntent(Business $business, Invoice $invoice, array $pi): Payment
    {
        $existing = Payment::where('business_id', $business->id)->where('external_id', 'stripe:'.$pi['id'])->first();
        if ($existing) return $existing;
        $amount = (int) ($pi['amount_received'] ?? 0);
        if ($amount <= 0 || strtolower((string) ($pi['currency'] ?? 'usd')) !== 'usd') throw new DomainException('Unexpected Stripe payment amount or currency.');
        $charge = is_array($pi['latest_charge'] ?? null) ? $pi['latest_charge'] : [];
        $bt = $charge['balance_transaction'] ?? null;
        if (is_string($bt)) {
            try {
                $bt = StripeGateway::for($business)->get('balance_transactions/'.$bt);
            } catch (StripeError $e) {
                Log::warning('stripe.fee_lookup_failed', ['pi' => $pi['id'], 'error' => $e->getMessage()]);
                $bt = null;
            }
        }
        $fee = is_array($bt) ? max(0, (int) ($bt['fee'] ?? 0)) : 0;
        $card = $charge['payment_method_details']['card'] ?? (is_array($pi['payment_method'] ?? null) ? ($pi['payment_method']['card'] ?? null) : null);
        $this->invoices->refreshBalance($invoice);
        $apply = $invoice->isIssued() ? min($amount, max(0, $invoice->balance_cents)) : 0;
        $date = Carbon::createFromTimestamp((int) ($pi['created'] ?? time()))->setTimezone($business->timezone ?: 'America/New_York')->toDateString();

        return $this->payments->record($business, [
            'client_id' => $invoice->client_id,
            'method' => 'card',
            'amount_cents' => $amount,
            'fee_cents' => $fee,
            'received_date' => $date,
            'reference' => $card ? trim(ucfirst((string) ($card['brand'] ?? 'card')).' •••• '.($card['last4'] ?? '')) : null,
            'memo' => 'Stripe '.$pi['id'].(! empty($pi['metadata']['autopay']) && $pi['metadata']['autopay'] === '1' ? ' (autopay)' : ''),
            'processor' => 'stripe',
            'external_id' => 'stripe:'.$pi['id'],
            'send_receipt' => true,
        ], $apply > 0 ? [$invoice->id => $apply] : []);
    }

    private function customerId(StripeGateway $gw, Invoice $invoice, ?string $email): string
    {
        $client = $invoice->client()->first();
        if ($client->stripe_customer_id) return $client->stripe_customer_id;
        $c = $gw->post('customers', ['name' => $client->display_name, 'email' => $email ?: $client->billingEmail(), 'metadata' => ['client_id' => (string) $client->id]], 'customer-client-'.$client->id);
        $client->forceFill(['stripe_customer_id' => $c['id']])->save();

        return $c['id'];
    }

    private function saveAutopay(Invoice $invoice, array $pi, ?string $email): void
    {
        $s = RecurringSchedule::where('business_id', $invoice->business_id)->find($invoice->recurring_schedule_id);
        $pm = $pi['payment_method'] ?? null;
        $pmId = is_array($pm) ? ($pm['id'] ?? null) : $pm;
        if (! $s || ! $s->card_autopay || ! $pmId) return;
        $card = is_array($pm) ? ($pm['card'] ?? []) : ($pi['latest_charge']['payment_method_details']['card'] ?? []);
        $s->forceFill([
            'stripe_payment_method_id' => $pmId,
            'autopay_card_label' => $card ? ucfirst((string) ($card['brand'] ?? 'Card')).' •••• '.($card['last4'] ?? '') : 'Saved card',
            'autopay_authorized_at' => now(),
            'autopay_authorized_email' => $email ?: null,
        ])->save();
        Audit::log($invoice->business_id, 'schedule.autopay_authorized', $s, null, ['card' => $s->autopay_card_label, 'email' => $email], null, 'public');
    }
}
