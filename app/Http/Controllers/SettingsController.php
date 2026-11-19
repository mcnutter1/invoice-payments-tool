<?php

namespace App\Http\Controllers;

use App\Models\EmailOutbox;
use App\Models\Invoice;
use App\Services\Audit;
use App\Services\Documents\DocumentStore;
use App\Services\Documents\PdfRenderer;
use App\Services\Invoicing\InvoicePresenter;
use App\Services\Notifications\EmailComposer;
use App\Services\Notifications\NotificationGateway;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function index(NotificationGateway $notify)
    {
        $b = $this->biz();
        $w9 = app(\App\Services\Tax\W9Service::class);
        $w9Tin = $w9->tin($b);

        return view('settings.index', [
            'b' => $b,
            'w9' => $w9->settings($b),
            'w9Missing' => $w9->missing($b),
            'w9Tin' => $w9Tin ? '•••••'.substr($w9Tin, -4) : null,
            'signers' => \App\Models\Membership::with('user')->where('business_id', $b->id)->where('status', 'active')->get(),
            'taxId' => $b->tax_id_encrypted ? '•••••'.substr(decrypt($b->tax_id_encrypted), -4) : null,
            'notifyHealth' => $notify->health(),
            'fromHeader' => EmailComposer::fromHeader($b),
            'pendingSends' => EmailOutbox::where('business_id', $b->id)->where('status', 'queued')->count(),
            'canManage' => $this->ctx()->can('business.manage'),
            'zelleQr' => $b->zelle_qr_document_id
                ? app(DocumentStore::class)->dataUri(\App\Models\PrivateDocument::where('business_id', $b->id)->find($b->zelle_qr_document_id))
                : null,
            'achLast4' => $b->ach_account_number_encrypted ? substr((string) $b->achAccountNumber(), -4) : null,
            'cashAccounts' => \App\Models\ChartAccount::where('business_id', $b->id)->where('type', 'asset')->whereIn('subtype', ['bank', 'undeposited'])->where('is_active', true)->orderBy('code')->get(),
            'stripeWebhookUrl' => url('/webhooks/stripe/'.$b->ulid),
            'venmoApiUrl' => url('/api/v1/venmo/payments'),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $b = $this->biz();
        $data = $request->validate([
            'legal_name' => ['required', 'string', 'max:200'],
            'display_name' => ['required', 'string', 'max:200'],
            // Older businesses may hold a free-text value; keep accepting it unchanged.
            'entity_type' => ['nullable', Rule::in(array_filter(array_merge(array_keys(\App\Models\Business::ENTITY_TYPES), [$b->entity_type])))],
            'address_line1' => ['nullable', 'string', 'max:200'], 'address_line2' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:100'], 'state' => ['nullable', 'string', 'max:60'], 'postal_code' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:40'], 'public_email' => ['nullable', 'email'], 'website' => ['nullable', 'url', 'max:200'],
            'timezone' => ['required', 'timezone:all'],
            'fiscal_year_start_month' => ['required', 'integer', 'min:1', 'max:12'],
            'reporting_basis' => ['required', Rule::in(['accrual', 'cash'])],
            'invoice_prefix' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9-]*$/'],
            'contract_prefix' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9-]*$/'],
            'default_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'tax_id' => ['nullable', 'string', 'max:40'],
            'show_tax_id' => ['nullable', 'boolean'],
            'invoice_link_ttl_days' => ['required', 'integer', 'min:1', 'max:365'],
            'contract_link_ttl_days' => ['required', 'integer', 'min:1', 'max:90'],
            'claim_review_days' => ['required', 'integer', 'min:1', 'max:30'],
            'default_customer_note' => ['nullable', 'string', 'max:2000'],
            'default_terms_text' => ['nullable', 'string', 'max:5000'],
        ]);
        $data['show_tax_id'] = $request->boolean('show_tax_id');
        if (! empty($data['tax_id'])) $data['tax_id_encrypted'] = encrypt($data['tax_id']);
        unset($data['tax_id']);
        $before = $b->only(array_keys($data));
        $b->update($data);
        [$bf, $af] = Audit::diff($before, $b->only(array_keys($data)));
        Audit::log($b, 'business.profile_updated', $b, $bf, $af);

        return back()->with('status', 'Profile saved. Issued documents keep their original snapshots.');
    }

    public function updateReminders(Request $request)
    {
        $b = $this->biz();
        $rules = [];
        foreach (array_keys(\App\Models\Business::REMINDERS) as $k) {
            $rules[$k.'.enabled'] = ['nullable', 'boolean'];
            $rules[$k.'.every_days'] = ['required', 'integer', 'min:1', 'max:90'];
        }
        $data = $request->validate($rules);
        $settings = [];
        foreach (array_keys(\App\Models\Business::REMINDERS) as $k) {
            $settings[$k] = ['enabled' => $request->boolean($k.'.enabled'), 'every_days' => (int) $data[$k]['every_days']];
        }
        $before = $b->reminder_settings;
        $b->update(['reminder_settings' => $settings]);
        Audit::log($b, 'business.reminders_updated', $b, $before, $settings);

        return back()->with('status', 'Reminder settings saved.');
    }

    public function updateBranding(Request $request, DocumentStore $docs)
    {
        $b = $this->biz();
        $data = $request->validate([
            'template' => ['required', Rule::in(['classic', 'modern'])],
            'accent' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'font' => ['required', Rule::in(PdfRenderer::FONTS)],
            'header_text' => ['nullable', 'string', 'max:300'],
            'footer_text' => ['nullable', 'string', 'max:500'],
            'show_sku' => ['nullable', 'boolean'],
            'show_quantity' => ['nullable', 'boolean'],
            'due_date_prominent' => ['nullable', 'boolean'],
            'email_intro' => ['nullable', 'string', 'max:1000'],
            'logo' => ['nullable', 'file', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
        ]);
        $branding = [
            'template' => $data['template'], 'accent' => $data['accent'], 'font' => $data['font'],
            'header_text' => $data['header_text'] ?? '', 'footer_text' => $data['footer_text'] ?? '',
            'show_sku' => $request->boolean('show_sku'), 'show_quantity' => $request->boolean('show_quantity'),
            'due_date_prominent' => $request->boolean('due_date_prominent'),
            'email_intro' => $data['email_intro'] ?? $b->brand('email_intro'),
        ];
        $updates = ['branding' => $branding];
        if ($request->hasFile('logo')) {
            $updates['logo_document_id'] = $docs->storeUpload($b, $request->file('logo'), 'logo', ['image/png', 'image/jpeg'])->id;
        } elseif ($request->boolean('remove_logo')) {
            $updates['logo_document_id'] = null;
        }
        $b->update($updates);
        Audit::log($b, 'business.branding_updated', $b, null, $branding);

        return back()->with('status', 'Branding saved. Preview the PDF to check it.');
    }

    public function updatePayments(Request $request, DocumentStore $docs, \App\Services\Payments\BankDirectory $banks)
    {
        $b = $this->biz();
        $request->merge(array_map(fn ($v) => is_string($v) ? preg_replace('/[\s-]+/', '', $v) : $v,
            $request->only(['ach_routing_number', 'ach_wire_routing_number', 'ach_account_number'])));
        if ($request->filled('ach_swift_code')) $request->merge(['ach_swift_code' => strtoupper(preg_replace('/\s+/', '', (string) $request->input('ach_swift_code')))]);
        $routing = fn ($attr, $value, $fail) => \App\Services\Payments\BankDirectory::validRouting($value) ? null : $fail('That routing number is not valid (9 digits, ABA checksum).');
        $data = $request->validate([
            'payment_methods' => ['required', 'array', 'min:1'],
            'payment_methods.*' => [Rule::in(\App\Support\PaymentMethods::INVOICE)],
            'zelle_recipient_name' => ['nullable', 'required_if:payment_methods.*,zelle', 'string', 'max:120'],
            'zelle_email' => ['nullable', 'email'],
            'zelle_phone' => ['nullable', 'string', 'max:40'],
            'check_payee' => ['nullable', 'string', 'max:200'],
            'check_address' => ['nullable', 'string', 'max:500'],
            'zelle_qr' => ['nullable', 'file', 'max:1024'],
            'remove_zelle_qr' => ['nullable', 'boolean'],
            'ach_account_name' => ['nullable', 'string', 'max:200'],
            'ach_bank_name' => ['nullable', 'string', 'max:200'],
            'ach_bank_address' => ['nullable', 'string', 'max:500'],
            'ach_routing_number' => ['nullable', 'string', $routing],
            'ach_wire_routing_number' => ['nullable', 'string', $routing],
            'ach_account_number' => ['nullable', 'regex:/^\d{4,17}$/'],
            'ach_account_type' => ['nullable', Rule::in(['checking', 'savings'])],
            'ach_swift_code' => ['nullable', 'regex:/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/'],
            'ach_instructions' => ['nullable', 'string', 'max:1000'],
            'venmo_handle' => ['nullable', 'regex:/^@?[A-Za-z0-9_-]{5,30}$/'],
            'venmo_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'venmo_fee_fixed' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'venmo_auto_confirm' => ['nullable', 'boolean'],
            'confirm_change' => ['accepted'],
        ], [
            'confirm_change.accepted' => 'Confirm that you verified these payment instructions.',
            'ach_account_number.regex' => 'Account numbers are 4–17 digits.',
            'ach_swift_code.regex' => 'SWIFT/BIC codes are 8 or 11 characters (e.g. CHASUS33).',
            'venmo_handle.regex' => 'Venmo usernames are 5–30 letters, numbers, dashes or underscores.',
        ]);
        $methods = $data['payment_methods'];
        if (in_array('zelle', $methods, true) && empty($data['zelle_email']) && empty($data['zelle_phone'])) {
            return back()->withInput()->with('error', 'Zelle needs the email or US mobile number enrolled with your bank.');
        }
        if (in_array('check', $methods, true) && empty($data['check_address']) && ! $b->addressLines()) {
            return back()->withInput()->with('error', 'Checks need a mailing address.');
        }
        if (in_array('ach', $methods, true) && (empty($data['ach_routing_number']) || (empty($data['ach_account_number']) && ! $b->ach_account_number_encrypted))) {
            return back()->withInput()->with('error', 'ACH / wire needs the routing number and account number for the receiving account.');
        }
        if (in_array('venmo', $methods, true) && empty($data['venmo_handle'])) {
            return back()->withInput()->with('error', 'Venmo needs your Venmo business username.');
        }
        if (in_array('card', $methods, true) && ! $b->stripe_secret_key_encrypted) {
            return back()->withInput()->with('error', 'Connect Stripe below before offering card payments.');
        }
        $fields = ['payment_methods', 'zelle_recipient_name', 'zelle_email', 'zelle_phone', 'check_payee', 'check_address',
            'ach_account_name', 'ach_bank_name', 'ach_bank_location', 'ach_bank_address', 'ach_routing_number', 'ach_wire_routing_number', 'ach_account_type', 'ach_swift_code', 'ach_instructions',
            'venmo_handle', 'venmo_fee_percent', 'venmo_fee_fixed_cents', 'venmo_auto_confirm'];
        $before = $b->only($fields);
        $updates = array_intersect_key($data, array_flip($fields));
        $updates['payment_methods'] = \App\Support\PaymentMethods::ordered($methods);
        if (array_key_exists('venmo_handle', $data)) $updates['venmo_handle'] = $data['venmo_handle'] ? ltrim($data['venmo_handle'], '@') : null;
        if ($request->has('venmo_fee_percent')) $updates['venmo_fee_percent'] = (float) ($data['venmo_fee_percent'] ?? 0);
        if ($request->has('venmo_fee_fixed')) $updates['venmo_fee_fixed_cents'] = $this->cents($data['venmo_fee_fixed'] ?? 0);
        if ($request->has('venmo_auto_confirm')) $updates['venmo_auto_confirm'] = $request->boolean('venmo_auto_confirm');
        $acctChanged = ! empty($data['ach_account_number']);
        if ($acctChanged) $updates['ach_account_number_encrypted'] = encrypt($data['ach_account_number']);
        if ($request->boolean('remove_ach_account')) {
            $updates['ach_account_number_encrypted'] = null;
            $acctChanged = true;
        }
        // Fill the bank name from the routing (or SWIFT) directory unless the user typed their own.
        $rn = $data['ach_routing_number'] ?? null;
        $typed = $data['ach_bank_name'] ?? null;
        $untouched = ! $typed || $typed === $b->ach_bank_name;
        if ($rn && $untouched && (! $typed || $rn !== $b->ach_routing_number)) {
            $hit = $banks->routing($rn) ?? (! empty($data['ach_swift_code']) ? $banks->swift($data['ach_swift_code']) : null);
            $updates['ach_bank_name'] = $hit['bank_name'] ?? $typed;
            $updates['ach_bank_location'] = $hit['location'] ?? null;
        } elseif (array_key_exists('ach_routing_number', $data) && ! $rn) {
            $updates['ach_bank_location'] = null;
        }
        if ($request->hasFile('zelle_qr')) {
            $updates['zelle_qr_document_id'] = $docs->storeUpload($b, $request->file('zelle_qr'), 'zelle_qr', ['image/png', 'image/jpeg'])->id;
        } elseif ($request->boolean('remove_zelle_qr')) {
            $updates['zelle_qr_document_id'] = null;
        }
        $updates['payment_instructions_changed_at'] = now();
        $b->update($updates);
        [$bf, $af] = Audit::diff($before, $b->only($fields));
        if ($acctChanged) $af = ($af ?? []) + ['ach_account_number' => 'changed (ends '.substr((string) $b->achAccountNumber(), -4).')'];
        Audit::log($b, 'business.payment_instructions_changed', $b, $bf, $af);
        $pending = EmailOutbox::where('business_id', $b->id)->where('status', 'queued')->whereIn('kind', ['invoice', 'reminder'])->count();
        $msg = 'Payment instructions saved. Already-issued invoices keep the instructions they were issued with.';
        if ($pending) $msg .= " Warning: {$pending} queued invoice email(s) reference invoices issued with the previous instructions.";

        return back()->with('status', $msg);
    }

    public function updateStripe(Request $request, \App\Services\Payments\StripePayments $stripe)
    {
        $b = $this->biz();
        if ($request->boolean('disconnect')) {
            $b->update(['stripe_secret_key_encrypted' => null, 'stripe_webhook_secret_encrypted' => null, 'stripe_account_label' => null, 'stripe_live' => false,
                'payment_methods' => array_values(array_diff($b->payment_methods ?? [], ['card']))]);
            Audit::log($b, 'business.stripe_disconnected', $b);

            return back()->with('status', 'Stripe disconnected. Card payments are no longer offered; existing payments are unchanged.');
        }
        $data = $request->validate([
            'stripe_secret_key' => ['nullable', 'string', 'max:255'],
            'stripe_webhook_secret' => ['nullable', 'string', 'max:255', 'regex:/^whsec_[A-Za-z0-9]+$/'],
            'stripe_deposit_account_id' => ['nullable', 'integer', Rule::exists('chart_accounts', 'id')->where('business_id', $b->id)->where('type', 'asset')],
        ], ['stripe_webhook_secret.regex' => 'Webhook signing secrets start with whsec_.']);
        $updates = ['stripe_deposit_account_id' => $data['stripe_deposit_account_id'] ?? null];
        $key = trim((string) ($data['stripe_secret_key'] ?? ''));
        if ($key !== '') {
            if (! \App\Services\Payments\StripeGateway::looksLikeSecret($key)) {
                return back()->with('error', 'Use a Stripe secret key (sk_live_… / sk_test_…) or a restricted key (rk_…).');
            }
            $updates['stripe_account_label'] = $stripe->verifyKey($key);
            $updates['stripe_secret_key_encrypted'] = encrypt($key);
            $updates['stripe_live'] = str_contains($key, '_live_');
        } elseif (! $b->stripe_secret_key_encrypted) {
            return back()->with('error', 'Enter your Stripe secret key.');
        }
        if (! empty($data['stripe_webhook_secret'])) $updates['stripe_webhook_secret_encrypted'] = encrypt($data['stripe_webhook_secret']);
        $b->update($updates);
        Audit::log($b, 'business.stripe_updated', $b, null, ['key_changed' => $key !== '', 'webhook_secret_changed' => ! empty($data['stripe_webhook_secret']), 'live' => $b->stripe_live, 'deposit_account_id' => $b->stripe_deposit_account_id]);

        return back()->with('status', 'Stripe connected'.($b->stripe_account_label ? ' ('.$b->stripe_account_label.')' : '').'. Turn on “Credit / debit card” under accepted methods to offer it.');
    }

    public function venmoToken(Request $request)
    {
        $b = $this->biz();
        if ($request->boolean('revoke')) {
            $b->update(['venmo_api_token_hash' => null, 'venmo_api_token_created_at' => null]);
            Audit::log($b, 'business.venmo_token_revoked', $b);

            return back()->with('status', 'Venmo feed token revoked.');
        }
        $token = \App\Services\Payments\VenmoFeed::newToken();
        $b->update(['venmo_api_token_hash' => hash('sha256', $token), 'venmo_api_token_created_at' => now()]);
        Audit::log($b, 'business.venmo_token_created', $b);

        return redirect('/b/'.$b->ulid.'/settings#venmo-feed')->with('venmo_token', $token)->with('status', 'New Venmo feed token created. Copy it now — it is shown only once.');
    }

    /** Settings helper: bank name for a routing number or SWIFT code. */
    public function bankLookup(Request $request, \App\Services\Payments\BankDirectory $banks)
    {
        $rn = preg_replace('/\D/', '', (string) $request->query('routing'));
        $swift = strtoupper(preg_replace('/\s+/', '', (string) $request->query('swift')));
        if ($rn !== '') {
            if (! \App\Services\Payments\BankDirectory::validRouting($rn)) return response()->json(['valid' => false, 'message' => 'Not a valid routing number.']);
            $hit = $banks->routing($rn);

            return response()->json(['valid' => true] + ($hit ?? ['bank_name' => null, 'location' => null, 'message' => 'Valid routing number; bank name not found.']));
        }
        if ($swift !== '') {
            if (! \App\Services\Payments\BankDirectory::validSwift($swift)) return response()->json(['valid' => false, 'message' => 'Not a valid SWIFT/BIC code.']);
            $hit = $banks->swift($swift);

            return response()->json(['valid' => true] + ($hit ?? ['bank_name' => null, 'location' => null, 'message' => 'Valid format; bank name not found.']));
        }

        return response()->json(['valid' => false, 'message' => 'Enter a routing number or SWIFT code.'], 422);
    }

    public function updateEmail(Request $request)
    {
        $b = $this->biz();
        $data = $request->validate(['sender_name' => ['required', 'string', 'max:120', 'not_regex:/[\r\n<>"]/'], 'sender_email' => ['nullable', 'email']]);
        if (! empty($data['sender_email']) && ! in_array(strtolower($data['sender_email']), array_map('strtolower', config('finance.notifications.approved_senders')), true)) {
            return back()->withInput()->with('error', 'That sender address is not approved on the notification service. Ask the platform operator to add it to NOTIFICATIONS_APPROVED_SENDERS.');
        }
        $b->update($data);
        Audit::log($b, 'business.sender_updated', $b, null, $data);

        return back()->with('status', 'Sender identity saved.');
    }

    public function testEmail(Request $request, EmailComposer $composer)
    {
        $to = $request->validate(['to' => ['required', 'email']])['to'];
        $composer->queueTest($this->biz(), $to);

        return back()->with('status', 'Test email queued. Check the automation monitor for the result.');
    }

    public function previewPdf(InvoicePresenter $presenter, PdfRenderer $pdf)
    {
        $b = $this->biz();
        $sample = Invoice::where('business_id', $b->id)->where('status', 'draft')->latest('id')->first();
        if (! $sample) {
            $client = new \App\Models\Client(['display_name' => 'Sample Client LLC', 'billing_line1' => '100 Main Street', 'billing_city' => 'Springfield', 'billing_state' => 'MA', 'billing_postal_code' => '01101']);
            $sample = new Invoice(['status' => 'draft', 'issue_date' => $b->localToday(), 'due_date' => $b->localToday()->addDays($b->default_terms_days),
                'subtotal_cents' => 150000, 'total_cents' => 150000, 'balance_cents' => 150000, 'customer_note' => $b->default_customer_note, 'terms' => \App\Support\InvoiceTerms::standard($b), 'payment_methods' => $b->allowedMethods()]);
            $sample->setRelation('business', $b);
            $sample->setRelation('client', $client->setRelation('contacts', collect()));
            $sample->setRelation('lines', collect([
                new \App\Models\InvoiceLine(['name' => 'Consulting services', 'description' => 'Strategy and implementation support', 'quantity' => '10', 'unit_price' => '150', 'amount_cents' => 150000, 'net_cents' => 150000, 'tax_cents' => 0]),
            ]));
            $sample->setRelation('taxLines', collect());
            $sample->setRelation('contractVersion', null);
        }
        $vm = $presenter->viewModel($sample);

        return response($pdf->render('pdf.invoice', $vm + ['watermark' => 'PREVIEW']), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="preview.pdf"']);
    }

    public function logo(DocumentStore $docs)
    {
        $b = $this->biz();
        abort_unless($b->logo, 404);

        return $docs->download($b->logo, 'logo', true);
    }
}
