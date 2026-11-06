<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Membership;
use App\Services\Audit;
use App\Services\Tax\W9Service;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Staff side of the business's W-9: settings, preview, and sending to a client. */
class W9Controller extends Controller
{
    public function __construct(private readonly W9Service $w9) {}

    public function update(Request $request)
    {
        $b = $this->biz();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'business_name' => ['nullable', 'string', 'max:200'],
            'classification' => ['required', Rule::in(array_keys(W9Service::CLASSIFICATIONS))],
            'llc_code' => ['nullable', 'required_if:classification,llc', Rule::in(['C', 'S', 'P'])],
            'other_text' => ['nullable', 'required_if:classification,other', 'string', 'max:80'],            'foreign_partners' => ['nullable', 'boolean'],
            'exempt_payee_code' => ['nullable', 'string', 'max:4', 'regex:/^[0-9]{0,2}$/'],
            'fatca_code' => ['nullable', 'string', 'max:2', 'regex:/^[A-Ma-m]?$/'],
            'address' => ['required', 'string', 'max:200'],
            'city_state_zip' => ['required', 'string', 'max:120'],
            'account_numbers' => ['nullable', 'string', 'max:120'],
            'tin_type' => ['required', Rule::in(['ein', 'ssn'])],
            'tin' => ['nullable', 'string', 'regex:/^\s*\d{2,3}-?\d{2,7}-?\d{0,4}\s*$/'],
            'backup_withholding' => ['nullable', 'boolean'],
            'signer_name' => ['required', 'string', 'max:120'],
            'signer_membership_id' => ['nullable', 'integer'],
            'link_ttl_days' => ['required', 'integer', 'min:1', 'max:60'],
            'max_downloads' => ['required', 'integer', 'min:1', 'max:50'],
            'allow_client_requests' => ['nullable', 'boolean'],
        ], ['llc_code.required_if' => 'Choose how the LLC is taxed (C corporation, S corporation or partnership).']);
        if ($data['classification'] === 'disregarded' && trim((string) ($data['business_name'] ?? '')) === '') {
            return back()->withErrors(['business_name' => 'Enter the LLC’s name on line 2 (line 1 is the owner’s name).'])->withInput();
        }
        if (! empty($data['tin']) && strlen(preg_replace('/\D/', '', $data['tin'])) !== 9) {
            return back()->withErrors(['tin' => 'A TIN has exactly 9 digits.'])->withInput();
        }
        if (! empty($data['signer_membership_id']) && ! Membership::where('business_id', $b->id)->whereKey($data['signer_membership_id'])->exists()) {
            $data['signer_membership_id'] = null;
        }
        $class = $data['classification'];
        $settings = array_merge(collect($data)->except('tin')->all(), [
            // Fields that don't apply to the chosen classification are cleared so they never print.
            'foreign_partners' => in_array($class, W9Service::FOREIGN_PARTNER_CLASSES, true) && $request->boolean('foreign_partners'),
            'backup_withholding' => $request->boolean('backup_withholding'),
            'allow_client_requests' => $request->boolean('allow_client_requests'),
            'llc_code' => $class === 'llc' ? strtoupper((string) $data['llc_code']) : '',
            'other_text' => $class === 'other' ? (string) $data['other_text'] : '',
            'exempt_payee_code' => in_array($class, W9Service::NO_EXEMPTION_CLASSES, true) ? '' : (string) ($data['exempt_payee_code'] ?? ''),
            'fatca_code' => in_array($class, W9Service::NO_EXEMPTION_CLASSES, true) ? '' : strtoupper((string) ($data['fatca_code'] ?? '')),
        ]);
        $before = $b->w9_settings;
        $b->update(['w9_settings' => $settings]);
        if (! empty($data['tin'])) $this->w9->saveTin($b, $data['tin']);
        Audit::log($b, 'w9.settings_updated', $b, $before, $settings + ['tin_changed' => ! empty($data['tin'])]);

        return redirect('/b/'.$b->ulid.'/settings#w9')->with('status', 'W-9 details saved.'.($this->w9->ready($b) ? '' : ' Still needed: '.implode(', ', $this->w9->missing($b)).'.'));
    }

    public function preview()
    {
        $b = $this->biz();

        return response($this->w9->render($b, null, true), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="W-9 preview.pdf"', 'Cache-Control' => 'private, no-store']);
    }

    public function download(int $id)
    {
        $b = $this->biz();
        $client = Client::where('business_id', $b->id)->findOrFail($id);
        Audit::log($b, 'w9.downloaded_by_staff', $client);

        return response($this->w9->render($b, $client), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="W-9 for '.preg_replace('/[^A-Za-z0-9 .-]+/', '', $client->display_name).'.pdf"', 'Cache-Control' => 'private, no-store']);
    }

    public function send(Request $request, int $id)
    {
        $b = $this->biz();
        $client = Client::with('contacts')->where('business_id', $b->id)->findOrFail($id);
        $this->mergeRecipients($request);
        $data = $request->validate(['recipients' => ['required', 'string', 'max:1000'], 'message' => ['nullable', 'string', 'max:1000']]);
        $emails = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $data['recipients']))));
        foreach ($emails as $e) {
            if (! filter_var($e, FILTER_VALIDATE_EMAIL)) return back()->withErrors(['recipients' => 'Invalid email: '.$e])->withInput();
        }
        $n = $this->w9->send($b, $client, $emails, 'staff', null, $data['message'] ?? null);

        return back()->with('status', "W-9 sent to {$n} recipient(s). The link expires in ".$this->w9->settings($b)['link_ttl_days'].' days.');
    }
}
