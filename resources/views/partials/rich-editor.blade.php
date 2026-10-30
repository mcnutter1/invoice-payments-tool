{{-- Rich text editor. Without JavaScript the textarea is used directly (HTML is sanitized on the server). --}}
@php $disabled = $disabled ?? false; @endphp
<div class="rte" data-rte @if($disabled) data-rte-disabled @endif>
    <div class="rte-toolbar" role="toolbar" aria-label="Formatting" aria-controls="{{ $id }}" hidden data-rte-toolbar>
        <select class="rte-select" data-rte-block aria-label="Text style">
            <option value="p">Paragraph</option><option value="h2">Heading</option><option value="h3">Subheading</option><option value="blockquote">Quote</option>
        </select>
        <span class="rte-sep" aria-hidden="true"></span>
        <button type="button" class="rte-btn font-bold" data-cmd="bold" title="Bold (Ctrl+B)" aria-label="Bold">B</button>
        <button type="button" class="rte-btn italic" data-cmd="italic" title="Italic (Ctrl+I)" aria-label="Italic">I</button>
        <button type="button" class="rte-btn underline" data-cmd="underline" title="Underline (Ctrl+U)" aria-label="Underline">U</button>
        <button type="button" class="rte-btn line-through" data-cmd="strikeThrough" title="Strikethrough" aria-label="Strikethrough">S</button>
        <span class="rte-sep" aria-hidden="true"></span>
        <select class="rte-select" data-rte-size aria-label="Font size" title="Font size">
            <option value="">Size</option><option value="0.85em">Small</option><option value="">Normal</option><option value="1.25em">Large</option><option value="1.5em">Larger</option><option value="2em">Huge</option>
        </select>
        <label class="rte-btn relative cursor-pointer" title="Text color">
            <span class="flex flex-col items-center leading-none"><span class="font-semibold">A</span><span class="mt-0.5 h-1 w-4 rounded-sm" style="background:#1f5f8b" data-rte-swatch></span></span>
            <input type="color" class="absolute inset-0 h-full w-full cursor-pointer opacity-0" value="#1f5f8b" data-rte-color aria-label="Text color">
        </label>
        @foreach (['#0f172a' => 'Black', '#1f5f8b' => 'Blue', '#047857' => 'Green', '#b45309' => 'Amber', '#b91c1c' => 'Red', '#64748b' => 'Gray'] as $hex => $nm)
            <button type="button" class="rte-swatch" style="background: {{ $hex }}" data-rte-color-preset="{{ $hex }}" title="{{ $nm }}" aria-label="{{ $nm }} text"></button>
        @endforeach
        <span class="rte-sep" aria-hidden="true"></span>
        <button type="button" class="rte-btn" data-cmd="insertUnorderedList" title="Bulleted list" aria-label="Bulleted list">&#8226;&#8212;</button>
        <button type="button" class="rte-btn" data-cmd="insertOrderedList" title="Numbered list" aria-label="Numbered list">1.</button>
        <button type="button" class="rte-btn" data-cmd="outdent" title="Decrease indent" aria-label="Decrease indent">&#8676;</button>
        <button type="button" class="rte-btn" data-cmd="indent" title="Increase indent" aria-label="Increase indent">&#8677;</button>
        <span class="rte-sep" aria-hidden="true"></span>
        <button type="button" class="rte-btn" data-cmd="createLink" title="Link" aria-label="Insert link">&#128279;</button>
        <button type="button" class="rte-btn" data-cmd="insertHorizontalRule" title="Divider" aria-label="Insert divider">&#8213;</button>
        <button type="button" class="rte-btn" data-cmd="removeFormat" title="Clear formatting" aria-label="Clear formatting">T&#824;</button>
        <span class="rte-sep" aria-hidden="true"></span>
        <button type="button" class="rte-btn" data-cmd="undo" title="Undo (Ctrl+Z)" aria-label="Undo">&#8630;</button>
        <button type="button" class="rte-btn" data-cmd="redo" title="Redo (Ctrl+Shift+Z)" aria-label="Redo">&#8631;</button>
        @if (! empty($fields))
            <select class="rte-select" data-rte-field aria-label="Insert merge field">
                <option value="">Insert field…</option>
                @foreach ($fields as $f)<option value="{{ $f }}">{{ ucwords(str_replace(['.', '_'], [' · ', ' '], $f)) }}</option>@endforeach
            </select>
        @endif
    </div>
    <textarea class="input font-mono text-xs" id="{{ $id }}" name="{{ $name }}" rows="{{ $rows ?? 16 }}" @if(! empty($label)) aria-label="{{ $label }}" @endif @disabled($disabled) @required($required ?? false) data-rte-source>{{ $value }}</textarea>
</div>
