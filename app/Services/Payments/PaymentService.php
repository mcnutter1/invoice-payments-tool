<?php

namespace App\Services\Payments;

use App\Exceptions\DomainException;
use App\Models\Business;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentClaim;
use App\Models\Refund;
use App\Services\Accounting\CashProjection;
use App\Services\Accounting\Ledger;
use App\Services\Audit;
use App\Services\Invoicing\InvoiceService;
use App\Services\Notifications\EmailComposer;
use App\Support\Context;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public const METHODS = ['zelle' => 'Zelle', 'check' => 'Check', 'ach' => 'ACH / wire', 'venmo' => 'Venmo', 'card' => 'Card', 'cash' => 'Cash', 'other' => 'Other'];

    public function __construct(
        private readonly Ledger $ledger,
        private readonly CashProjection $projection,
        private readonly InvoiceService $invoices,
        private readonly Context $ctx,
    ) {}

    /** Default receipt account: checks/cash go to Undeposited Funds, card to the Stripe payout account, everything else straight to the bank. */
    public function defaultDepositAccount(Business $b, string $method): ChartAccount
    {
        if ($method === 'card' && $b->stripe_deposit_account_id) {
            $acct = ChartAccount::where('business_id', $b->id)->find($b->stripe_deposit_account_id);
            if ($acct) return $acct;
        }

        return in_array($method, ['check', 'cash'], true) ? $b->account('undeposited') : $b->account('bank');
    }

    /** Suggested processing fee for a manually confirmed receipt (ACH/wire fees are entered by hand). */
    public function suggestedFee(Business $b, string $method, int $amountCents): int
    {
        return $method === 'venmo' ? $b->venmoFeeCents($amountCents) : 0;
    }

    /**
     * Record an actual receipt and optional allocations.
     *
     * @param  array<int,int>  $allocations  invoice_id => cents
     */
    public function record(Business $business, array $data, array $allocations = [], ?PaymentClaim $claim = null): Payment
    {
        return DB::transaction(function () use ($business, $data, $allocations, $claim) {
            $client = Client::where('business_id', $business->id)->findOrFail($data['client_id']);
            $amount = (int) $data['amount_cents'];
            if ($amount <= 0) throw new DomainException('Payment amount must be positive.');
            $date = Carbon::parse($data['received_date']);
            $this->ledger->assertOpen($business, $date);
            $method = $data['method'];
            if (! array_key_exists($method, self::METHODS)) throw new DomainException('Unsupported method.');
            $fee = max(0, (int) ($data['fee_cents'] ?? 0));
            if ($fee >= $amount) throw new DomainException('The processing fee must be less than the payment amount.');
            if (! empty($data['external_id'])) {
                $existing = Payment::where('business_id', $business->id)->where('external_id', $data['external_id'])->first();
                if ($existing) return $existing; // processor retries / webhooks
            }
            $feeAccount = $fee > 0 ? $business->account('merchant_fees') : null;

            $deposit = ! empty($data['deposit_account_id'])
                ? ChartAccount::where('business_id', $business->id)->where('type', 'asset')->whereIn('subtype', ['bank', 'undeposited'])->findOrFail($data['deposit_account_id'])
                : $this->defaultDepositAccount($business, $method);

            $allocations = array_filter(array_map('intval', $allocations), fn ($c) => $c > 0);
            $invoices = Invoice::where('business_id', $business->id)->whereIn('id', array_keys($allocations))->lockForUpdate()->get()->keyBy('id');
            $sum = 0;
            foreach ($allocations as $invoiceId => $cents) {
                $inv = $invoices[$invoiceId] ?? throw new DomainException('Invoice not found.');
                if (! $inv->isIssued()) throw new DomainException("Invoice {$inv->displayNumber()} is not open.");
                if ($inv->client_id !== $client->id) throw new DomainException('Allocations must be to the same client.');
                if ($inv->currency !== 'USD') throw new DomainException('Cross-currency allocation is not allowed.');
                $this->invoices->refreshBalance($inv);
                if ($cents > $inv->balance_cents) {
                    throw new DomainException("Allocation to {$inv->number} exceeds its open balance of ".Money::format($inv->balance_cents).'.');
                }
                $sum += $cents;
            }
            if ($sum > $amount) throw new DomainException('Allocations exceed the payment amount.');

            $payment = new Payment([
                'client_id' => $client->id,
                'method' => $method,
                'amount_cents' => $amount,
                'received_date' => $date->toDateString(),
                'status' => 'recorded',
                'deposit_account_id' => $deposit->id,
                'reference' => $data['reference'] ?? null,
                'check_number' => $data['check_number'] ?? null,
                'memo' => $data['memo'] ?? null,
                'claim_id' => $claim?->id,
                'recorded_by' => $this->ctx->user?->id,
                'fee_cents' => $fee,
                'fee_account_id' => $feeAccount?->id,
                'processor' => $data['processor'] ?? null,
                'external_id' => $data['external_id'] ?? null,
            ]);
            $payment->business_id = $business->id;
            $payment->save();

            $lines = [['account' => $deposit, 'debit' => $amount - $fee, 'client_id' => $client->id, 'description' => 'Payment from '.$client->display_name]];
            if ($fee > 0) {
                $lines[] = ['account' => $feeAccount, 'debit' => $fee, 'client_id' => $client->id, 'description' => 'Processing fee · '.self::METHODS[$method]];
            }
            foreach ($allocations as $invoiceId => $cents) {
                $lines[] = ['account' => $business->account('ar'), 'credit' => $cents, 'client_id' => $client->id, 'invoice_id' => $invoiceId, 'description' => 'Applied to '.$invoices[$invoiceId]->number];
            }
            if ($amount - $sum > 0) {
                $lines[] = ['account' => $business->account('customer_advances'), 'credit' => $amount - $sum, 'client_id' => $client->id, 'description' => 'Unapplied payment / customer credit'];
            }
            $entry = $this->ledger->post($business, 'payment:'.$payment->id.':receipt', $date, self::METHODS[$method].' payment · '.$client->display_name, $lines, ['source_type' => 'payment', 'source_id' => $payment->id]);
            $payment->journal_entry_id = $entry->id;
            $payment->save();

            foreach ($allocations as $invoiceId => $cents) {
                PaymentAllocation::create([
                    'business_id' => $business->id,
                    'payment_id' => $payment->id,
                    'invoice_id' => $invoiceId,
                    'amount_cents' => $cents,
                    'applied_date' => $date->toDateString(),
                    'journal_entry_id' => $entry->id,
                    'created_by' => $this->ctx->user?->id,
                ]);
                $inv = $this->invoices->refreshBalance($invoices[$invoiceId]);
                $this->projection->syncInvoice($inv, $date, 'payment:'.$payment->id);
            }
            Audit::log($business, 'payment.recorded', $payment, null, ['amount_cents' => $amount, 'fee_cents' => $fee, 'allocations' => $allocations, 'claim_id' => $claim?->id, 'processor' => $payment->processor]);

            if (! empty($data['send_receipt'])) {
                DB::afterCommit(fn () => app(EmailComposer::class)->queueReceipt($payment->fresh()));
            }

            return $payment;
        });
    }

    /** Apply unapplied customer credit from a payment to an invoice. */
    public function apply(Payment $payment, Invoice $invoice, int $cents, ?string $date = null): PaymentAllocation
    {
        return DB::transaction(function () use ($payment, $invoice, $cents, $date) {
            $payment = Payment::where('business_id', $payment->business_id)->lockForUpdate()->findOrFail($payment->id);
            $invoice = Invoice::where('business_id', $payment->business_id)->lockForUpdate()->findOrFail($invoice->id);
            $business = Business::findOrFail($payment->business_id);
            if ($payment->status === 'reversed') throw new DomainException('Payment is reversed.');
            if (! $invoice->isIssued() || $invoice->client_id !== $payment->client_id) throw new DomainException('Invoice is not open for this client.');
            $this->invoices->refreshBalance($invoice);
            if ($cents <= 0 || $cents > $payment->unappliedCents() || $cents > $invoice->balance_cents) {
                throw new DomainException('Amount exceeds the available credit or the invoice balance.');
            }
            $d = $date ? Carbon::parse($date) : $business->localToday();
            $alloc = PaymentAllocation::create([
                'business_id' => $business->id,
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'amount_cents' => $cents,
                'applied_date' => $d->toDateString(),
                'created_by' => $this->ctx->user?->id,
            ]);
            $entry = $this->ledger->post($business, 'allocation:'.$alloc->id, $d, 'Apply credit to '.$invoice->number, [
                ['account' => $business->account('customer_advances'), 'debit' => $cents, 'client_id' => $invoice->client_id],
                ['account' => $business->account('ar'), 'credit' => $cents, 'client_id' => $invoice->client_id, 'invoice_id' => $invoice->id],
            ], ['source_type' => 'allocation', 'source_id' => $alloc->id]);
            $alloc->journal_entry_id = $entry->id;
            $alloc->save();
            $this->invoices->refreshBalance($invoice);
            $this->projection->syncInvoice($invoice, $d, 'allocation:'.$alloc->id);
            Audit::log($business, 'payment.applied', $payment, null, ['invoice' => $invoice->number, 'amount_cents' => $cents]);

            return $alloc;
        });
    }

    /** Move an allocation back to unapplied credit (reallocation step). History is preserved. */
    public function unapply(PaymentAllocation $alloc, string $reason): void
    {
        DB::transaction(function () use ($alloc, $reason) {
            $alloc = PaymentAllocation::where('business_id', $alloc->business_id)->lockForUpdate()->findOrFail($alloc->id);
            if ($alloc->status !== 'active' || ! $alloc->payment_id) throw new DomainException('Allocation is not active.');
            $business = Business::findOrFail($alloc->business_id);
            $invoice = Invoice::where('business_id', $business->id)->lockForUpdate()->findOrFail($alloc->invoice_id);
            $d = $this->ledger->openDate($business, $business->localToday());
            $this->ledger->post($business, 'allocation:'.$alloc->id.':unapply', $d, 'Unapply payment from '.$invoice->number, [
                ['account' => $business->account('ar'), 'debit' => $alloc->amount_cents, 'client_id' => $invoice->client_id, 'invoice_id' => $invoice->id],
                ['account' => $business->account('customer_advances'), 'credit' => $alloc->amount_cents, 'client_id' => $invoice->client_id],
            ], ['source_type' => 'allocation', 'source_id' => $alloc->id, 'reason' => $reason]);
            $alloc->status = 'reversed';
            $alloc->reversed_at = now();
            $alloc->save();
            $this->invoices->refreshBalance($invoice);
            $this->projection->syncInvoice($invoice, $d, 'allocation:'.$alloc->id.':unapply');
            Audit::log($business, 'payment.unapplied', $alloc, null, ['invoice' => $invoice->number], $reason);
        });
    }

    /** Move a check/cash receipt from Undeposited Funds to a bank account. */
    public function deposit(Payment $payment, ChartAccount $bank, string $date): Payment
    {
        return DB::transaction(function () use ($payment, $bank, $date) {
            $payment = Payment::where('business_id', $payment->business_id)->lockForUpdate()->findOrFail($payment->id);
            $business = Business::findOrFail($payment->business_id);
            if ($payment->status !== 'recorded') throw new DomainException('Only recorded (undeposited) payments can be deposited.');
            $undeposited = $business->account('undeposited');
            if ($payment->deposit_account_id !== $undeposited->id) throw new DomainException('This payment was received directly into a bank account.');
            if ($bank->business_id !== $business->id || $bank->subtype !== 'bank') throw new DomainException('Choose a bank account.');
            $entry = $this->ledger->post($business, 'payment:'.$payment->id.':deposit', $date, 'Deposit · '.self::METHODS[$payment->method].($payment->check_number ? ' #'.$payment->check_number : ''), [
                ['account' => $bank, 'debit' => $payment->netCents(), 'client_id' => $payment->client_id],
                ['account' => $undeposited, 'credit' => $payment->netCents(), 'client_id' => $payment->client_id],
            ], ['source_type' => 'payment', 'source_id' => $payment->id]);
            $payment->status = 'deposited';
            $payment->deposited_date = $date;
            $payment->deposit_journal_entry_id = $entry->id;
            $payment->save();
            Audit::log($business, 'payment.deposited', $payment);

            return $payment;
        });
    }

    public function markCleared(Payment $payment, string $date): Payment
    {
        if (! in_array($payment->status, ['recorded', 'deposited'], true)) throw new DomainException('Payment cannot be marked cleared.');
        if ($payment->status === 'recorded' && $payment->deposit_account_id === Business::find($payment->business_id)->account('undeposited')->id) {
            throw new DomainException('Deposit this payment before marking it cleared.');
        }
        $payment->status = 'cleared';
        $payment->cleared_date = $date;
        $payment->save();
        Audit::log($payment->business_id, 'payment.cleared', $payment);

        return $payment;
    }

    /** Returned/bounced check or entry error: reverse receipt and every active allocation. */
    public function reverse(Payment $payment, string $kind, string $reason, ?string $date = null): Payment
    {
        return DB::transaction(function () use ($payment, $kind, $reason, $date) {
            $payment = Payment::where('business_id', $payment->business_id)->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status === 'reversed') return $payment;
            $business = Business::findOrFail($payment->business_id);
            if ($payment->refundedCents() > 0) throw new DomainException('Reverse the refunds on this payment first.');
            $d = $date ? Carbon::parse($date) : $this->ledger->openDate($business, $business->localToday());

            $cashAccount = $payment->deposit_journal_entry_id
                ? JournalEntry::find($payment->deposit_journal_entry_id)->lines()->where('debit_cents', '>', 0)->value('account_id')
                : $payment->deposit_account_id;
            $allocs = PaymentAllocation::where('business_id', $business->id)->where('payment_id', $payment->id)->where('status', 'active')->lockForUpdate()->get();
            $lines = [['account' => $cashAccount, 'credit' => $payment->netCents(), 'client_id' => $payment->client_id, 'description' => ucfirst($kind).' payment reversal']];
            if ($payment->fee_cents > 0) {
                $lines[] = ['account' => $payment->fee_account_id ?: $business->account('merchant_fees'), 'credit' => $payment->fee_cents, 'client_id' => $payment->client_id, 'description' => 'Processing fee reversal'];
            }
            $applied = 0;
            foreach ($allocs as $a) {
                $lines[] = ['account' => $business->account('ar'), 'debit' => $a->amount_cents, 'client_id' => $payment->client_id, 'invoice_id' => $a->invoice_id];
                $applied += $a->amount_cents;
            }
            if ($payment->amount_cents - $applied > 0) {
                $lines[] = ['account' => $business->account('customer_advances'), 'debit' => $payment->amount_cents - $applied, 'client_id' => $payment->client_id];
            }
            $this->ledger->post($business, 'payment:'.$payment->id.':reverse', $d, 'Reversal ('.$kind.') of payment #'.$payment->id, $lines, ['source_type' => 'payment', 'source_id' => $payment->id, 'reason' => $reason]);
            foreach ($allocs as $a) {
                $a->status = 'reversed';
                $a->reversed_at = now();
                $a->save();
                $inv = $this->invoices->refreshBalance(Invoice::find($a->invoice_id));
                $this->projection->syncInvoice($inv, $d, 'payment:'.$payment->id.':reverse');
            }
            $payment->status = 'reversed';
            $payment->reversed_at = now();
            $payment->reversed_by = $this->ctx->user?->id;
            $payment->reversal_kind = $kind;
            $payment->reversal_reason = $reason;
            $payment->save();
            Audit::log($business, 'payment.reversed', $payment, null, ['kind' => $kind], $reason);

            return $payment;
        });
    }

    /** Refund unapplied customer credit. */
    public function refund(Payment $payment, int $cents, int $accountId, string $date, ?string $reference = null): Refund
    {
        return DB::transaction(function () use ($payment, $cents, $accountId, $date, $reference) {
            $payment = Payment::where('business_id', $payment->business_id)->lockForUpdate()->findOrFail($payment->id);
            $business = Business::findOrFail($payment->business_id);
            if ($cents <= 0 || $cents > $payment->unappliedCents()) throw new DomainException('Refund exceeds the unapplied credit of '.Money::format($payment->unappliedCents()).'.');
            $account = ChartAccount::where('business_id', $business->id)->where('subtype', 'bank')->findOrFail($accountId);
            $refund = Refund::create([
                'business_id' => $business->id,
                'client_id' => $payment->client_id,
                'payment_id' => $payment->id,
                'amount_cents' => $cents,
                'refund_date' => $date,
                'account_id' => $account->id,
                'reference' => $reference,
                'created_by' => $this->ctx->user?->id,
            ]);
            $entry = $this->ledger->post($business, 'refund:'.$refund->id, $date, 'Refund of customer credit', [
                ['account' => $business->account('customer_advances'), 'debit' => $cents, 'client_id' => $payment->client_id],
                ['account' => $account, 'credit' => $cents, 'client_id' => $payment->client_id],
            ], ['source_type' => 'refund', 'source_id' => $refund->id]);
            $refund->journal_entry_id = $entry->id;
            $refund->save();
            Audit::log($business, 'payment.refunded', $payment, null, ['amount_cents' => $cents]);

            return $refund;
        });
    }

    /** A fee discovered after recording (e.g. an incoming-wire fee on the bank statement): expense it against the account holding the funds. */
    public function addFee(Payment $payment, int $cents, string $date, ?string $note = null): Payment
    {
        return DB::transaction(function () use ($payment, $cents, $date, $note) {
            $payment = Payment::where('business_id', $payment->business_id)->lockForUpdate()->findOrFail($payment->id);
            $business = Business::findOrFail($payment->business_id);
            if ($payment->status === 'reversed') throw new DomainException('Payment is reversed.');
            if ($cents <= 0 || $payment->fee_cents + $cents >= $payment->amount_cents) throw new DomainException('Enter a fee smaller than the payment amount.');
            $cash = $payment->deposit_journal_entry_id
                ? JournalEntry::find($payment->deposit_journal_entry_id)->lines()->where('debit_cents', '>', 0)->value('account_id')
                : $payment->deposit_account_id;
            $feeAccount = $payment->fee_account_id ?: $business->account('merchant_fees')->id;
            $n = JournalEntry::where('business_id', $business->id)->where('source_key', 'like', 'payment:'.$payment->id.':fee:%')->count() + 1;
            $this->ledger->post($business, 'payment:'.$payment->id.':fee:'.$n, $date, 'Processing fee · '.self::METHODS[$payment->method].' payment #'.$payment->id, [
                ['account' => $feeAccount, 'debit' => $cents, 'client_id' => $payment->client_id, 'description' => $note ?: 'Processing fee'],
                ['account' => $cash, 'credit' => $cents, 'client_id' => $payment->client_id],
            ], ['source_type' => 'payment', 'source_id' => $payment->id, 'reason' => $note]);
            $payment->fee_cents += $cents;
            $payment->fee_account_id = $feeAccount;
            $payment->save();
            Audit::log($business, 'payment.fee_added', $payment, null, ['fee_cents' => $cents], $note);

            return $payment;
        });
    }

    // ----- Client payment claims -----

    public function confirmClaim(PaymentClaim $claim, array $data): Payment
    {
        return DB::transaction(function () use ($claim, $data) {
            $claim = PaymentClaim::where('business_id', $claim->business_id)->lockForUpdate()->findOrFail($claim->id);
            if ($claim->status === 'confirmed' && $claim->payment_id) {
                return Payment::findOrFail($claim->payment_id); // idempotent
            }
            if (! $claim->isOpen()) throw new DomainException('This claim was already reviewed.');
            $business = Business::findOrFail($claim->business_id);
            $invoice = Invoice::where('business_id', $business->id)->findOrFail($claim->invoice_id);
            $this->invoices->refreshBalance($invoice);
            $amount = (int) $data['amount_cents'];
            $apply = min($amount, $invoice->balance_cents);
            $payment = $this->record($business, [
                'client_id' => $invoice->client_id,
                'method' => $claim->method,
                'amount_cents' => $amount,
                'received_date' => $data['received_date'],
                'deposit_account_id' => $data['deposit_account_id'] ?? null,
                'reference' => $data['reference'] ?? $claim->reference,
                'check_number' => $claim->check_number,
                'memo' => 'Confirmed client payment report #'.$claim->id,
                'fee_cents' => $data['fee_cents'] ?? 0,
                'external_id' => $claim->external_id,
                'processor' => $claim->source === 'venmo_api' ? 'venmo_api' : null,
                'send_receipt' => $data['send_receipt'] ?? true,
            ], $apply > 0 ? [$invoice->id => $apply] : [], $claim);
            $claim->status = 'confirmed';
            $claim->payment_id = $payment->id;
            $claim->reviewed_by = $this->ctx->user?->id;
            $claim->reviewed_at = now();
            $claim->review_note = $data['note'] ?? null;
            $claim->save();
            Audit::log($business, 'claim.confirmed', $claim, null, ['payment_id' => $payment->id, 'amount_cents' => $amount]);

            return $payment;
        });
    }

    public function reviewClaim(PaymentClaim $claim, string $status, string $note): PaymentClaim
    {
        if (! in_array($status, ['rejected', 'clarification_requested'], true)) throw new DomainException('Invalid review.');
        return DB::transaction(function () use ($claim, $status, $note) {
            $claim = PaymentClaim::where('business_id', $claim->business_id)->lockForUpdate()->findOrFail($claim->id);
            if (! $claim->isOpen()) throw new DomainException('This claim was already reviewed.');
            $claim->update(['status' => $status, 'review_note' => $note, 'reviewed_by' => $this->ctx->user?->id, 'reviewed_at' => now()]);
            Audit::log($claim->business_id, 'claim.'.$status, $claim, null, null, $note);

            return $claim;
        });
    }
}
