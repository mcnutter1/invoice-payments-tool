@if (session('status'))
    <div class="alert alert-ok mb-4" role="status">{{ session('status') }}</div>
@endif
@if (session('error'))
    <div class="alert alert-err mb-4" role="alert">{{ session('error') }}</div>
@endif
@if (session('duplicate'))
    <div class="alert alert-warn mb-4" role="alert">{{ session('duplicate') }}</div>
@endif
@if ($errors->any())
    <div class="alert alert-err mb-4" role="alert">
        <p class="font-medium">Please fix the following:</p>
        <ul class="mt-1 list-disc pl-5">
            @foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach
        </ul>
    </div>
@endif
