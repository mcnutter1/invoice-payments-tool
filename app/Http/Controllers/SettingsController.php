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

        return view('settings.index', [
            'b' => $b,
            'taxId' => $b->tax_id_encrypted ? '•••••'.substr(decrypt($b->tax_id_encrypted), -4) : null,
            'notifyHealth' => $notify->health(),
            'fromHeader' => EmailComposer::fromHeader($b),
            'pendingSends' => EmailOutbox::where('business_id', $b->id)->where('status', 'queued')->count(),
            'canManage' => $this->ctx()->can('business.manage'),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $b = $this->biz();
        $data = $request->validate([
            'legal_name' => ['required', 'string', 'max:200'],
            'display_name' => ['required', 'string', 'max:200'],
            'entity_type' => ['nullable', 'string', 'max:40'],
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
            'reminders_enabled' => ['nullable', 'boolean'],
            'reminder_days' => ['nullable', 'regex:/^\s*\d{1,3}(\s*,\s*\d{1,3})*\s*$/'],
        ]);
        $data['reminder_settings'] = [
            'enabled' => $request->boolean('reminders_enabled'),
            'days' => array_values(array_unique(array_map('intval', array_filter(explode(',', (string) ($data['reminder_days'] ?? '3,14,30')), 'strlen')))),
        ];
        unset($data['reminders_enabled'], $data['reminder_days']);
        $data['show_tax_id'] = $request->boolean('show_tax_id');
        if (! empty($data['tax_id'])) $data['tax_id_encrypted'] = encrypt($data['tax_id']);
        unset($data['tax_id']);
        $before = $b->only(array_keys($data));
        $b->update($data);
        [$bf, $af] = Audit::diff($before, $b->only(array_keys($data)));
        Audit::log($b, 'business.profile_updated', $b, $bf, $af);

        return back()->with('status', 'Profile saved. Issued documents keep their original snapshots.');
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

    public function updatePayments(Request $request, DocumentStore $docs)
    {
        $b = $this->biz();
        $data = $request->validate([
            'payment_methods' => ['required', 'array', 'min:1'],
            'payment_methods.*' => [Rule::in(['zelle', 'check'])],
            'zelle_recipient_name' => ['nullable', 'required_if:payment_methods.*,zelle', 'string', 'max:120'],
            'zelle_email' => ['nullable', 'email'],
            'zelle_phone' => ['nullable', 'string', 'max:40'],
            'check_payee' => ['nullable', 'string', 'max:200'],
            'check_address' => ['nullable', 'string', 'max:500'],
            'zelle_qr' => ['nullable', 'file', 'max:1024'],
            'remove_zelle_qr' => ['nullable', 'boolean'],
            'confirm_change' => ['accepted'],
        ], ['confirm_change.accepted' => 'Confirm that you verified these payment instructions.']);
        if (in_array('zelle', $data['payment_methods'], true) && empty($data['zelle_email']) && empty($data['zelle_phone'])) {
            return back()->withInput()->with('error', 'Zelle needs the email or US mobile number enrolled with your bank.');
        }
        if (in_array('check', $data['payment_methods'], true) && empty($data['check_address']) && ! $b->addressLines()) {
            return back()->withInput()->with('error', 'Checks need a mailing address.');
        }
        $fields = ['payment_methods', 'zelle_recipient_name', 'zelle_email', 'zelle_phone', 'check_payee', 'check_address'];
        $before = $b->only($fields);
        $updates = array_intersect_key($data, array_flip($fields));
        if ($request->hasFile('zelle_qr')) {
            $updates['zelle_qr_document_id'] = $docs->storeUpload($b, $request->file('zelle_qr'), 'zelle_qr', ['image/png', 'image/jpeg'])->id;
        } elseif ($request->boolean('remove_zelle_qr')) {
            $updates['zelle_qr_document_id'] = null;
        }
        $updates['payment_instructions_changed_at'] = now();
        $b->update($updates);
        [$bf, $af] = Audit::diff($before, $b->only($fields));
        Audit::log($b, 'business.payment_instructions_changed', $b, $bf, $af);
        $pending = EmailOutbox::where('business_id', $b->id)->where('status', 'queued')->whereIn('kind', ['invoice', 'reminder'])->count();
        $msg = 'Payment instructions saved. Already-issued invoices keep the instructions they were issued with.';
        if ($pending) $msg .= " Warning: {$pending} queued invoice email(s) reference invoices issued with the previous instructions.";

        return back()->with('status', $msg);
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
                'subtotal_cents' => 150000, 'total_cents' => 150000, 'balance_cents' => 150000, 'customer_note' => $b->default_customer_note, 'terms' => $b->default_terms_text, 'payment_methods' => $b->allowedMethods()]);
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
