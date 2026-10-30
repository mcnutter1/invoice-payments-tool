<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\Audit;
use App\Services\Documents\DocumentStore;
use App\Services\Documents\PdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    private function client(int $id): Client
    {
        return Client::where('business_id', $this->biz()->id)->findOrFail($id);
    }

    public function index(Request $request)
    {
        $b = $this->biz();
        $q = Client::where('business_id', $b->id);
        if ($s = trim((string) $request->query('q'))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $s).'%';
            $q->where(fn ($w) => $w->where('display_name', 'like', $like)->orWhere('legal_name', 'like', $like)->orWhere('email', 'like', $like));
        }
        $status = $request->query('status', 'active');
        if ($status !== 'all') $q->where('status', $status);
        $sort = in_array($request->query('sort'), ['display_name', 'created_at'], true) ? $request->query('sort') : 'display_name';
        $clients = $q->orderBy($sort, $sort === 'created_at' ? 'desc' : 'asc')->paginate(25)->withQueryString();
        $balances = Invoice::where('business_id', $b->id)->where('status', 'issued')->whereIn('client_id', $clients->pluck('id'))
            ->selectRaw('client_id, SUM(balance_cents) as bal')->groupBy('client_id')->pluck('bal', 'client_id');

        return view('clients.index', compact('clients', 'balances', 'status'));
    }

    public function create()
    {
        return view('clients.form', ['client' => new Client(['type' => 'company'])]);
    }

    private function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['company', 'individual'])],
            'display_name' => ['required', 'string', 'max:200'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'email' => ['nullable', 'email', 'max:200'],
            'phone' => ['nullable', 'string', 'max:40'],
            'billing_line1' => ['nullable', 'string', 'max:200'], 'billing_line2' => ['nullable', 'string', 'max:200'],
            'billing_city' => ['nullable', 'string', 'max:100'], 'billing_state' => ['nullable', 'string', 'max:60'], 'billing_postal_code' => ['nullable', 'string', 'max:20'],
            'service_line1' => ['nullable', 'string', 'max:200'], 'service_line2' => ['nullable', 'string', 'max:200'],
            'service_city' => ['nullable', 'string', 'max:100'], 'service_state' => ['nullable', 'string', 'max:60'], 'service_postal_code' => ['nullable', 'string', 'max:20'],
            'default_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'tax_exempt' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'external_id' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $data['tax_exempt'] = $request->boolean('tax_exempt');
        $dupes = Client::where('business_id', $this->biz()->id)
            ->where(fn ($q) => $q->where('display_name', $data['display_name'])->when($data['email'] ?? null, fn ($q, $e) => $q->orWhere('email', $e)))
            ->pluck('display_name');
        if ($dupes->isNotEmpty() && ! $request->boolean('confirm_duplicate')) {
            return back()->withInput()->with('duplicate', 'Possible duplicate of: '.$dupes->implode(', ').'. Submit again with “Create anyway” checked to continue.');
        }
        $client = new Client($data);
        $client->business_id = $this->biz()->id;
        $client->save();
        if ($request->filled('contact_name')) {
            Contact::create(['business_id' => $client->business_id, 'client_id' => $client->id, 'name' => $request->input('contact_name'), 'email' => $request->input('email'), 'is_primary_billing' => true, 'is_signer' => true]);
        }
        Audit::log($this->biz(), 'client.created', $client);

        return $this->to('/clients/'.$client->id, 'Client created.');
    }

    public function show(int $id)
    {
        $client = $this->client($id)->load('contacts');
        $b = $this->biz();
        $ctx = $this->ctx();
        $schedules = $ctx->can('recurring.view') ? $client->schedules()->with('lines')->get() : collect();
        $issued = Invoice::where('business_id', $b->id)->where('client_id', $id)->where('status', 'issued');
        $canMoney = $ctx->can('invoices.view');

        return view('clients.show', [
            'client' => $client,
            'invoices' => $ctx->can('invoices.view') ? Invoice::where('business_id', $b->id)->where('client_id', $id)->latest('id')->limit(50)->get() : collect(),
            'payments' => $ctx->can('payments.view') ? Payment::where('business_id', $b->id)->where('client_id', $id)->latest('received_date')->limit(50)->get() : collect(),
            'contracts' => $ctx->can('contracts.view') ? $client->contracts()->latest()->get() : collect(),
            'schedules' => $schedules,
            'revenue' => $ctx->can('recurring.view') ? app(\App\Services\Recurring\RecurringRevenue::class)->summary($b, $id, $schedules) : null,
            'values' => $canMoney ? [
                'billed' => (int) (clone $issued)->sum('total_cents'),
                'collected' => $ctx->can('payments.view') ? (int) Payment::where('business_id', $b->id)->where('client_id', $id)->where('status', '!=', 'reversed')->sum('amount_cents') : null,
                'overdue' => (int) (clone $issued)->where('balance_cents', '>', 0)->whereDate('due_date', '<', $b->localToday())->sum('balance_cents'),
            ] : null,
            'balance' => (int) Invoice::where('business_id', $b->id)->where('client_id', $id)->where('status', 'issued')->sum('balance_cents'),
            'credit' => $ctx->can('payments.view') ? -(int) JournalLine::where('business_id', $b->id)->where('client_id', $id)->where('account_id', $b->account('customer_advances')->id)->sum(\Illuminate\Support\Facades\DB::raw('debit_cents - credit_cents')) : 0,
            'activity' => \App\Models\AuditEvent::where('business_id', $b->id)->where('object_type', 'Client')->where('object_id', $id)->latest('id')->limit(20)->get(),
        ]);
    }

    public function edit(int $id)
    {
        return view('clients.form', ['client' => $this->client($id)]);
    }

    public function update(Request $request, int $id)
    {
        $client = $this->client($id);
        $data = $request->validate($this->rules());
        $data['tax_exempt'] = $request->boolean('tax_exempt');
        $before = $client->only(array_keys($data));
        $client->update($data);
        [$b, $a] = Audit::diff($before, $client->only(array_keys($data)));
        Audit::log($this->biz(), 'client.updated', $client, $b, $a);

        return $this->to('/clients/'.$id, 'Client saved. Issued invoices keep their original client details.');
    }

    public function archive(int $id)
    {
        $client = $this->client($id);
        $client->update(['status' => $client->status === 'archived' ? 'active' : 'archived']);
        Audit::log($this->biz(), 'client.'.$client->status, $client);

        return $this->to('/clients/'.$id, $client->status === 'archived' ? 'Client archived. History is preserved.' : 'Client restored.');
    }

    private function contactData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'], 'email' => ['nullable', 'email'], 'phone' => ['nullable', 'string', 'max:40'],
            'title' => ['nullable', 'string', 'max:120'],
        ]) + ['is_primary_billing' => $request->boolean('is_primary_billing'), 'is_signer' => $request->boolean('is_signer')];
    }

    public function addContact(Request $request, int $id)
    {
        $client = $this->client($id);
        $data = $this->contactData($request);
        if ($data['is_primary_billing']) {
            Contact::where('client_id', $client->id)->update(['is_primary_billing' => false]);
        }
        $contact = Contact::create($data + ['business_id' => $client->business_id, 'client_id' => $client->id]);
        Audit::log($this->biz(), 'client.contact_added', $client, null, ['contact' => $contact->name]);

        return $this->to('/clients/'.$id, 'Contact added.');
    }

    public function updateContact(Request $request, int $id, int $contact)
    {
        $client = $this->client($id);
        $ct = Contact::where('business_id', $client->business_id)->where('client_id', $client->id)->findOrFail($contact);
        $data = $this->contactData($request);
        if ($data['is_primary_billing']) {
            Contact::where('client_id', $client->id)->whereKeyNot($ct->id)->update(['is_primary_billing' => false]);
        }
        $before = $ct->only(array_keys($data));
        $ct->update($data);
        Audit::log($this->biz(), 'client.contact_updated', $client, $before, $ct->only(array_keys($data)));

        return $this->to('/clients/'.$id, 'Contact updated.');
    }

    public function removeContact(int $id, int $contact)
    {
        $client = $this->client($id);
        $ct = Contact::where('business_id', $client->business_id)->where('client_id', $client->id)->findOrFail($contact);
        $ct->delete();
        Audit::log($this->biz(), 'client.contact_removed', $client, ['contact' => $ct->name, 'email' => $ct->email]);

        return $this->to('/clients/'.$id, 'Contact removed.');
    }

    public function statement(Request $request, int $id, PdfRenderer $pdf, DocumentStore $docs)
    {
        $this->require('invoices.view');
        $client = $this->client($id);
        $b = $this->biz();
        $from = $request->query('from', $b->localToday()->subMonths(3)->startOfMonth()->toDateString());
        $to = $request->query('to', $b->localToday()->toDateString());
        $ar = $b->account('ar')->id;
        $opening = (int) JournalLine::where('business_id', $b->id)->where('client_id', $client->id)->where('account_id', $ar)->where('entry_date', '<', $from)->sum(\Illuminate\Support\Facades\DB::raw('debit_cents - credit_cents'));
        $lines = JournalLine::with('entry')->where('business_id', $b->id)->where('client_id', $client->id)->where('account_id', $ar)
            ->whereBetween('entry_date', [$from, $to])->orderBy('entry_date')->orderBy('id')->get();
        $data = ['client' => $client, 'business' => $b, 'from' => $from, 'to' => $to, 'opening' => $opening, 'lines' => $lines,
            'closing' => $opening + (int) $lines->sum(fn ($l) => $l->debit_cents - $l->credit_cents), 'logo' => $docs->dataUri($b->logo)];
        if ($request->query('format') === 'pdf') {
            return response($pdf->render('pdf.statement', $data), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="statement-'.DocumentStore::safeName($client->display_name).'.pdf"',
            ]);
        }

        return view('clients.statement', $data);
    }
}
