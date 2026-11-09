<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Models\Client;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\MileageTrip;
use App\Models\Project;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Projects group expenses and mileage for a client engagement or trip. */
class ProjectController extends Controller
{
    private function project(int $id): Project
    {
        return Project::with('client')->where('business_id', $this->biz()->id)->findOrFail($id);
    }

    public function index(Request $request)
    {
        $b = $this->biz();
        $q = Project::with('client')->where('business_id', $b->id);
        $status = $request->query('status', 'open');
        if ($status !== 'all') $q->where('status', $status);
        if ($c = $request->query('client')) $q->where('client_id', (int) $c);
        $projects = $q->orderByDesc('start_date')->orderBy('name')->get();
        $totals = Expense::where('business_id', $b->id)->whereNull('voided_at')->whereIn('project_id', $projects->pluck('id'))
            ->selectRaw('project_id, count(*) as n, sum(total_cents) as cents')->groupBy('project_id')->get()->keyBy('project_id');

        return view('projects.index', [
            'projects' => $projects, 'totals' => $totals, 'status' => $status,
            'clients' => Client::where('business_id', $b->id)->orderBy('display_name')->get(['id', 'display_name']),
        ]);
    }

    public function create(Request $request)
    {
        return view('projects.form', ['project' => null, 'clientId' => $request->query('client'), 'clients' => $this->clients()]);
    }

    public function edit(int $id)
    {
        $p = $this->project($id);

        return view('projects.form', ['project' => $p, 'clientId' => $p->client_id, 'clients' => $this->clients($p->client_id)]);
    }

    private function clients(?int $include = null)
    {
        return Client::where('business_id', $this->biz()->id)->where(fn ($q) => $q->where('status', 'active')->orWhere('id', $include))
            ->orderBy('display_name')->get(['id', 'display_name']);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'client_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:5000'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', Rule::in(['open', 'closed'])],
        ]);
        if (! empty($data['client_id'])) Client::where('business_id', $this->biz()->id)->findOrFail($data['client_id']);
        $data['client_id'] = ($data['client_id'] ?? null) ?: null;

        return $data;
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $p = new Project($data + ['status' => 'open', 'created_by' => $this->ctx()->user?->id]);
        $p->business_id = $this->biz()->id;
        $p->save();
        Audit::log($this->biz(), 'project.created', $p, null, $p->only(['name', 'client_id']));

        return $this->to('/projects/'.$p->id, 'Project created.');
    }

    public function update(Request $request, int $id)
    {
        $p = $this->project($id);
        $data = $this->validated($request);
        if ((int) $p->client_id !== (int) $data['client_id']) {
            $conflict = Expense::where('business_id', $p->business_id)->where('project_id', $p->id)->whereNull('voided_at')
                ->whereNotNull('client_id')->where('client_id', '!=', (int) $data['client_id'])->exists();
            if ($conflict && $data['client_id']) throw new DomainException('Some expenses in this project are assigned to a different client. Reassign them first.');
        }
        $before = $p->only(array_keys($data));
        $p->update($data);
        Audit::log($this->biz(), 'project.updated', $p, $before, $p->only(array_keys($data)));

        return $this->to('/projects/'.$p->id, 'Project saved.');
    }

    public function show(int $id)
    {
        $p = $this->project($id);
        $expenses = Expense::with('vendor', 'lines.account', 'mileageTrip')->where('business_id', $p->business_id)->where('project_id', $p->id)
            ->whereNull('voided_at')->orderByDesc('expense_date')->get();
        $byCategory = ExpenseLine::with('account')->whereIn('expense_id', $expenses->pluck('id'))->get()
            ->groupBy('account_id')->map(fn ($g) => ['account' => $g->first()->account, 'cents' => $g->sum('amount_cents')])->sortByDesc('cents')->values();
        $billable = ExpenseLine::whereIn('expense_id', $expenses->pluck('id'))->where('billable', true)->whereNull('billed_invoice_id')->sum('amount_cents');

        return view('projects.show', [
            'project' => $p, 'expenses' => $expenses, 'byCategory' => $byCategory, 'unbilled' => (int) $billable,
            'trips' => MileageTrip::where('business_id', $p->business_id)->where('project_id', $p->id)->orderByDesc('trip_date')->get(),
        ]);
    }
}
