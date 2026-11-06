@extends('layouts.app')
@section('title', 'Vendors')
@section('content')
@include('settings._tabs')
<h2 class="text-lg">Vendors</h2>
<div class="mt-4 grid gap-4 lg:grid-cols-3">
    <div class="card overflow-x-auto lg:col-span-2">
        <table class="tbl"><thead><tr><th>Name</th><th>Email</th><th>Default category</th><th>Status</th></tr></thead><tbody>
        @forelse ($vendors as $v)
            <tr>
                <td>
                    @if ($ctx->can('catalog.manage'))
                    <details><summary class="cursor-pointer font-medium text-brand-700">{{ $v->name }}</summary>
                        <form method="post" action="{{ $bu }}/vendors/{{ $v->id }}" class="mt-2 grid gap-2">@csrf
                            @include('catalog._vendor-fields', ['v' => $v])
                            <label class="flex items-center gap-2 text-sm"><input class="check" type="checkbox" name="archived" value="1" @checked($v->status === 'archived')> Archived</label>
                            <div><button class="btn btn-sm btn-primary">Save</button></div>
                        </form></details>
                    @else {{ $v->name }} @endif
                </td>
                <td>{{ $v->email }}</td><td>{{ $accounts->firstWhere('id', $v->default_account_id)?->label() ?? '—' }}</td><td>@include('partials.status', ['s' => $v->status])</td>
            </tr>
        @empty <tr><td colspan="4"><div class="empty m-4">No vendors yet.</div></td></tr> @endforelse
        </tbody></table>
    </div>
    @if ($ctx->can('catalog.manage'))
    <div class="card"><div class="card-head"><h2>Add vendor</h2></div>
        <form method="post" action="{{ $bu }}/vendors" class="card-body grid gap-2">@csrf @include('catalog._vendor-fields', ['v' => new \App\Models\Vendor]) <div><button class="btn btn-primary">Add</button></div></form>
    </div>
    @endif
</div>
<div class="mt-4">{{ $vendors->links() }}</div>
@endsection
