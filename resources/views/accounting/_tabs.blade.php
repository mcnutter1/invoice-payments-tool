<div class="flex flex-wrap items-center justify-between gap-3">
    <h1>Accounting</h1>
    <nav class="flex flex-wrap gap-1" aria-label="Accounting sections">
        @php $seg = request()->segment(3); @endphp
        <a href="{{ $bu }}/accounts" class="btn btn-sm {{ $seg === 'accounts' ? 'btn-primary' : '' }}">Chart of accounts</a>
        <a href="{{ $bu }}/journals" class="btn btn-sm {{ $seg === 'journals' ? 'btn-primary' : '' }}">Journal</a>
        <a href="{{ $bu }}/periods" class="btn btn-sm {{ $seg === 'periods' ? 'btn-primary' : '' }}">Period close</a>
        @if ($ctx->can('journals.manage'))<a href="{{ $bu }}/journals/new" class="btn btn-sm">+ Journal entry</a>@endif
    </nav>
</div>
