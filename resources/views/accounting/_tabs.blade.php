@php $seg = request()->segment(3); @endphp
<div class="mb-3 flex flex-wrap items-center justify-between gap-3">
    <h1>Accounting</h1>
    @if ($ctx->can('journals.manage'))<a href="{{ $bu }}/journals/new" class="btn btn-primary">+ Journal entry</a>@endif
</div>
@include('partials.tabs', ['label' => 'Accounting sections', 'class' => 'mb-5', 'tabs' => [
    ['Chart of accounts', $bu.'/accounts', $seg === 'accounts'],
    ['Journal', $bu.'/journals', $seg === 'journals'],
    ['Period close', $bu.'/periods', $seg === 'periods'],
]])
