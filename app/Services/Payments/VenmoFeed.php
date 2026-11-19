<?php

namespace App\Services\Payments;

use App\Models\Business;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentClaim;
use App\Services\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Venmo has no public payments API for business profiles, so verified receipts are pushed in by an
 * automation the business controls (e.g. a parser for Venmo "paid you" emails or a bank/PayPal feed).
 * Each receipt is matched to an invoice by the invoice number in the Venmo note, or by amount against an
 * open Venmo payment report; then it is auto-recorded or queued for manual confirmation.
 */
class VenmoFeed
{
    public function __construct(private readonly PaymentService $payments) {}

    public static function newToken(): string
    {
        return 'vnm_'.\Illuminate\Support\Str::random(48);
    }

    public static function findBusiness(?string $token): ?Business
    {
        if (! $token || ! str_starts_with($token, 'vnm_')) return null;

        return Business::where('venmo_api_token_hash', hash('sha256', $token))->where('status', 'active')->first();
    }

    /**
     * @param array{transaction_id:string, amount_cents:int, date?:?string, note?:?string, sender?:?string, fee_cents?:?int} $tx
     * @return array{status:string, invoice?:string, payment_id?:int, claim_id?:int}
     */
    public function ingest(Business $b, array $tx): array
    {
        $ext = 'venmo:'.$tx['transaction_id'];

        return DB::transaction(function () use ($b, $tx, $ext) {
            if (Payment::where('business_id', $b->id)->where('external_id', $ext)->exists()
                || PaymentClaim::where('business_id', $b->id)->where('external_id', $ext)->exists()) {
                return ['status' => 'duplicate'];
            }
            $amount = (int) $tx['amount_cents'];
            $date = Carbon::parse($tx['date'] ?? $b->localToday())->toDateString();
            [$invoice, $claim] = $this->match($b, (string) ($tx['note'] ?? ''), $amount);
            if (! $invoice) {
                Audit::log($b, 'venmo.unmatched', null, null, ['transaction_id' => $tx['transaction_id'], 'amount_cents' => $amount, 'sender' => $tx['sender'] ?? null, 'note' => mb_substr((string) ($tx['note'] ?? ''), 0, 200)], null, 'system');

                return ['status' => 'unmatched'];
            }
            $fee = array_key_exists('fee_cents', $tx) && $tx['fee_cents'] !== null ? (int) $tx['fee_cents'] : $b->venmoFeeCents($amount);
            $who = trim(($tx['sender'] ?? '') !== '' ? 'from '.$tx['sender'] : '');
            $summary = trim('Venmo '.$who.' · txn '.$tx['transaction_id'].(($tx['note'] ?? '') !== '' ? ' · “'.mb_substr($tx['note'], 0, 200).'”' : ''));

            if (! $claim) {
                $claim = new PaymentClaim([
                    'invoice_id' => $invoice->id, 'method' => 'venmo', 'reported_amount_cents' => $amount, 'reported_date' => $date,
                    'reference' => mb_substr($tx['transaction_id'], 0, 120), 'note' => $summary,
                ]);
                $claim->business_id = $b->id;
            } else {
                $claim->note = trim(($claim->note ? $claim->note."\n" : '').'Matched: '.$summary);
                $claim->reference = $claim->reference ?: mb_substr($tx['transaction_id'], 0, 120);
            }
            $claim->source = 'venmo_api';
            $claim->external_id = $ext;
            $claim->save();
            Audit::log($b, 'venmo.verified', $claim, null, ['invoice' => $invoice->number, 'amount_cents' => $amount, 'transaction_id' => $tx['transaction_id']], null, 'system');

            if ($b->venmo_auto_confirm && $amount <= $invoice->balance_cents) {
                $payment = $this->payments->confirmClaim($claim, [
                    'amount_cents' => $amount, 'received_date' => $date, 'fee_cents' => $fee,
                    'reference' => $tx['transaction_id'], 'note' => 'Auto-confirmed from the Venmo feed', 'send_receipt' => true,
                ]);

                return ['status' => 'recorded', 'invoice' => $invoice->number, 'payment_id' => $payment->id, 'claim_id' => $claim->id];
            }

            return ['status' => 'pending_review', 'invoice' => $invoice->number, 'claim_id' => $claim->id];
        });
    }

    /** @return array{0:?Invoice, 1:?PaymentClaim} */
    private function match(Business $b, string $note, int $amount): array
    {
        $open = Invoice::where('business_id', $b->id)->where('status', 'issued')->where('balance_cents', '>', 0)->whereNotNull('number')->get();
        $byNote = $note !== '' ? $open->filter(fn ($i) => preg_match('/(?<![A-Za-z0-9])'.preg_quote($i->number, '/').'(?![A-Za-z0-9])/i', $note))
            ->sortByDesc(fn ($i) => strlen($i->number))->first() : null;
        $claims = PaymentClaim::where('business_id', $b->id)->where('method', 'venmo')->whereIn('status', ['reported', 'clarification_requested'])->whereNull('external_id')
            ->when($byNote, fn ($q) => $q->where('invoice_id', $byNote->id), fn ($q) => $q->where('reported_amount_cents', $amount))->oldest()->get();
        if ($byNote) return [$byNote, $claims->first()];
        $claim = $claims->count() === 1 ? $claims->first() : null;
        $invoice = $claim ? $open->firstWhere('id', $claim->invoice_id) : null;

        return [$invoice, $invoice ? $claim : null];
    }
}
