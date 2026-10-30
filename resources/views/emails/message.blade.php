@php
    $accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($brand['accent'] ?? '')) ? $brand['accent'] : '#1f5f8b';
    $font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light">
<title>{{ $subject ?? '' }}</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;-webkit-text-size-adjust:100%;">
@if (! empty($preheader))
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">{{ $preheader }}&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;</div>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f1f5f9;">
<tr><td align="center" style="padding:28px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;">

    <tr><td style="padding:0 4px 14px 4px;font-family:{!! $font !!};">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
            <td style="vertical-align:middle;">
                @if (! empty($brand['logo']))
                    <img src="{{ $brand['logo'] }}" alt="{{ $brand['name'] }}" height="40" style="display:block;height:40px;max-width:200px;border:0;">
                @else
                    <span style="font-size:18px;font-weight:700;color:#0f172a;">{{ $brand['name'] }}</span>
                @endif
            </td>
            @if (! empty($tag))
            <td align="right" style="vertical-align:middle;font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.06em;">{{ $tag }}</td>
            @endif
        </tr></table>
    </td></tr>

    <tr><td style="background:#ffffff;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden;">
        <div style="height:4px;background:{{ $accent }};line-height:4px;font-size:0;">&nbsp;</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr><td style="padding:28px 32px 8px 32px;font-family:{!! $font !!};color:#0f172a;">
            @if (! empty($heading))<h1 style="margin:0 0 14px 0;font-size:22px;line-height:1.3;font-weight:700;color:#0f172a;">{{ $heading }}</h1>@endif
            @if (! empty($greeting))<p style="margin:0 0 14px 0;font-size:15px;line-height:1.6;">{{ $greeting }}</p>@endif
            @foreach ($paragraphs ?? [] as $p)
                <p style="margin:0 0 14px 0;font-size:15px;line-height:1.6;color:#334155;white-space:pre-line;">{{ $p }}</p>
            @endforeach
        </td></tr>

        @if (! empty($highlight))
        <tr><td style="padding:6px 32px 6px 32px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
            <tr><td style="padding:18px 20px;font-family:{!! $font !!};">
                <div style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.06em;">{{ $highlight['label'] }}</div>
                <div style="font-size:30px;font-weight:700;color:#0f172a;margin-top:4px;">{{ $highlight['value'] }}</div>
                @if (! empty($highlight['sub']))
                    <div style="font-size:14px;margin-top:4px;color:{{ ($highlight['tone'] ?? '') === 'alert' ? '#b91c1c' : '#475569' }};font-weight:{{ ($highlight['tone'] ?? '') === 'alert' ? '600' : '400' }};">{{ $highlight['sub'] }}</div>
                @endif
                @if (! empty($details))
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:14px;border-top:1px solid #e2e8f0;">
                    @foreach ($details as [$k, $v])
                    <tr><td style="padding:8px 0 0 0;font-size:13px;color:#64748b;">{{ $k }}</td><td align="right" style="padding:8px 0 0 0;font-size:13px;color:#0f172a;font-weight:600;">{{ $v }}</td></tr>
                    @endforeach
                </table>
                @endif
            </td></tr></table>
        </td></tr>
        @elseif (! empty($details))
        <tr><td style="padding:6px 32px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
            @foreach ($details as [$k, $v])
                <tr><td style="padding:10px 16px;font-family:{!! $font !!};font-size:13px;color:#64748b;border-bottom:1px solid #eef2f7;">{{ $k }}</td><td align="right" style="padding:10px 16px;font-family:{!! $font !!};font-size:13px;color:#0f172a;font-weight:600;border-bottom:1px solid #eef2f7;">{{ $v }}</td></tr>
            @endforeach
            </table>
        </td></tr>
        @endif

        @if (! empty($code))
        <tr><td align="center" style="padding:10px 32px;">
            <div style="display:inline-block;font-family:'SFMono-Regular',Menlo,Consolas,monospace;font-size:32px;letter-spacing:8px;font-weight:700;color:#0f172a;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 22px;">{{ $code }}</div>
        </td></tr>
        @endif

        @if (! empty($buttons))
        <tr><td style="padding:18px 32px 8px 32px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
            @foreach ($buttons as $i => $b)
                @php $primary = ($b['style'] ?? ($i === 0 ? 'primary' : 'secondary')) === 'primary'; @endphp
                <td style="padding:0 10px 10px 0;">
                    <a href="{{ $b['url'] }}" target="_blank" rel="noopener" style="display:inline-block;font-family:{!! $font !!};font-size:15px;font-weight:600;text-decoration:none;border-radius:8px;padding:12px 22px;{{ $primary ? 'background:'.$accent.';color:#ffffff;border:1px solid '.$accent.';' : 'background:#ffffff;color:'.$accent.';border:1px solid #cbd5e1;' }}">{{ $b['label'] }}</a>
                </td>
            @endforeach
            </tr></table>
        </td></tr>
        @endif

        @foreach ($sections ?? [] as $s)
        <tr><td style="padding:10px 32px 4px 32px;font-family:{!! $font !!};">
            <div style="font-size:13px;font-weight:700;color:#0f172a;margin-bottom:6px;">{{ $s['title'] }}</div>
            @foreach ($s['lines'] as $line)
                <div style="font-size:14px;line-height:1.55;color:#334155;">{{ $line }}</div>
            @endforeach
        </td></tr>
        @endforeach

        <tr><td style="padding:14px 32px 28px 32px;font-family:{!! $font !!};">
            @foreach ($notes ?? [] as $n)
                <p style="margin:0 0 8px 0;font-size:12px;line-height:1.55;color:#64748b;">{{ $n }}</p>
            @endforeach
            @if (! empty($signoff))
                <p style="margin:14px 0 0 0;font-size:15px;line-height:1.6;color:#334155;">{{ $signoff }}<br><strong style="color:#0f172a;">{{ $brand['name'] }}</strong></p>
            @endif
        </td></tr>
        </table>
    </td></tr>

    <tr><td align="center" style="padding:16px 12px 0 12px;font-family:{!! $font !!};font-size:12px;line-height:1.6;color:#94a3b8;">
        {{ $brand['name'] }}@if (! empty($brand['contact'])) · {{ $brand['contact'] }}@endif<br>
        {{ $footer ?? 'Sent securely via McNutt Finance. Links in this email are personal to you — please don’t forward them.' }}
    </td></tr>
</table>
</td></tr>
</table>
@if (! empty($pixel))<img src="{{ $pixel }}" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;opacity:0;">@endif
</body>
</html>
