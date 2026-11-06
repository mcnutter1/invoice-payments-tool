{{-- Tab strip. $tabs: list of [label, href, active]; optional $label (aria) and $class. --}}
<nav class="tabs {{ $class ?? '' }}" aria-label="{{ $label ?? 'Sections' }}">
    @foreach ($tabs as [$text, $href, $active])
        <a href="{{ $href }}" @class(['tab', 'active' => $active]) @if($active) aria-current="page" @endif>{{ $text }}</a>
    @endforeach
</nav>
