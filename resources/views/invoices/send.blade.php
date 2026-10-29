@extends('layouts.app')
@section('title', 'Send '.$invoice->number)
@section('content')
<h1>Send invoice {{ $invoice->number }}</h1>
@if ($notifyHealth['status'] !== 'healthy')
    <div class="alert alert-warn mt-4">Email delivery is <strong>{{ $notifyHealth['status'] }}</strong>: {{ $notifyHealth['message'] }}. Messages will stay queued or fail until it is configured.</div>
@endif
@if ($previous)
    <div class="alert alert-info mt-4">This invoice was emailed before. Sending again re-uses the same issued PDF and creates a fresh secure link — it does not change or repost the invoice.</div>
@endif
<form method="post" action="{{ $bu }}/invoices/{{ $invoice->id }}/send" class="card card-body mt-4 max-w-2xl space-y-4">
    @csrf
    <div><label class="label" for="recipients">To</label><input class="input" id="recipients" name="recipients" value="{{ old('recipients', $recipients) }}" required><p class="hint">Separate multiple addresses with commas. Each recipient gets their own link.</p></div>
    <fieldset><legend class="label">Message type</legend>
        <label class="mr-4 inline-flex items-center gap-2 text-sm"><input type="radio" name="kind" value="invoice" checked> Invoice</label>
        <label class="inline-flex items-center gap-2 text-sm"><input type="radio" name="kind" value="reminder"> Payment reminder</label>
    </fieldset>
    <div><label class="label" for="message">Message (optional)</label><textarea class="input" id="message" name="message" rows="3" placeholder="{{ $biz->brand('email_intro') }}">{{ old('message') }}</textarea></div>
    <div class="rounded-md bg-slate-50 p-3 text-sm text-slate-700">
        <div class="font-medium">The email will include</div>
        <ul class="mt-1 list-disc pl-5">
            <li>Invoice {{ $invoice->number }} · amount due @money($invoice->balance_cents) · due {{ $invoice->due_date?->format('M j, Y') }}</li>
            <li>A secure “View invoice &amp; pay” link (expires in {{ $biz->invoice_link_ttl_days }} days)</li>
            <li>A secure link to download the PDF. <span class="text-slate-500">The PDF is not attached; the notification service does not support attachments.</span></li>
            <li>Your payment instructions as issued on this invoice</li>
        </ul>
    </div>
    <div class="flex gap-2"><button class="btn btn-primary">Queue email</button><a href="{{ $bu }}/invoices/{{ $invoice->id }}" class="btn">Cancel</a></div>
</form>
@endsection
