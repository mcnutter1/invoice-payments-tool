@php
    $box = fn (bool $on) => '<span class="cb">'.($on ? 'X' : '&nbsp;').'</span>';
    $c = $box ?? \App\Services\Tax\W9Service::formBox((string) $s['classification']);
    $digits = str_split((string) $tin);
    $ssn = $s['tin_type'] === 'ssn';
    $cell = fn ($d) => '<td class="d">'.e($d ?? '').'</td>';
@endphp
<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
    @page { margin: 30px 34px 28px 34px; }
    body { font-family: Helvetica, Arial, sans-serif; font-size: 7.5px; color: #000; line-height: 1.25; }
    table { border-collapse: collapse; width: 100%; }
    td { vertical-align: top; padding: 0; }
    .b { font-weight: bold; }
    .hdr td { border-bottom: 2px solid #000; }
    .formno { font-size: 8px; } .formno .big { font-size: 20px; font-weight: bold; }
    .title { font-size: 13.5px; font-weight: bold; text-align: center; padding-top: 4px; }
    .sub { text-align: center; font-size: 8px; padding-top: 3px; }
    .give { font-weight: bold; font-size: 8.5px; border-left: 1px solid #000; padding-left: 5px; padding-top: 6px; }
    .lines { border-bottom: 1px solid #000; }
    .ln { border-bottom: 1px solid #000; padding: 2px 4px 2px 4px; }
    .ln .n { font-weight: bold; display: inline-block; width: 12px; }
    .val { font-size: 10px; padding: 2px 0 1px 12px; min-height: 12px; font-family: "DejaVu Sans Mono", monospace; }
    .cb { display: inline-block; width: 9px; height: 9px; border: 1px solid #000; font-size: 8px; line-height: 9px; text-align: center; font-weight: bold; margin-right: 3px; vertical-align: middle; font-family: Helvetica, Arial, sans-serif; overflow: hidden; }
    .opts td { padding: 2px 6px 2px 0; white-space: nowrap; }
    .rbox { border-left: 1px solid #000; padding: 2px 4px; }
    .bar td { border-top: 1px solid #000; border-bottom: 1px solid #000; }
    .bar .tag { background: #000; color: #fff; font-weight: bold; font-size: 9px; padding: 2px 6px; width: 40px; }
    .bar .head { font-weight: bold; font-size: 9px; padding: 2px 8px; }
    .tinbox { border: 1px solid #000; }
    .tinbox .lab { font-weight: bold; padding: 2px 4px; border-bottom: 1px solid #000; }
    .digits { border-collapse: collapse; width: auto; margin: 4px 4px 4px 4px; }
    .digits td.d { width: 12px; height: 14px; border: 1px solid #000; text-align: center; font-size: 10px; font-family: "DejaVu Sans Mono", monospace; vertical-align: middle; }
    .digits td.dash { width: 8px; text-align: center; font-weight: bold; vertical-align: middle; border: none; }
    .cert p { margin: 2px 0; }
    .strike { text-decoration: line-through; }
    .sign td { border-top: 1px solid #000; border-bottom: 2px solid #000; }
    .sigimg { max-height: 30px; max-width: 220px; }
    .typed { font-family: "DejaVu Serif", serif; font-style: italic; font-size: 14px; }
    .foot { font-size: 7px; margin-top: 2px; }
    .gen p { margin: 3px 0; text-align: justify; }
    .gen h3 { font-size: 9px; margin: 6px 0 2px; }
</style></head><body>
@if (! empty($preview))<div style="position: fixed; top: 300px; left: 90px; font-size: 96px; font-weight: bold; color: rgba(220,38,38,0.13); transform: rotate(-30deg);">PREVIEW</div>@endif

<table class="hdr"><tr>
    <td style="width: 95px; border-right: 1px solid #000; padding-right: 4px;" class="formno">Form <span class="big">W-9</span><br>(Rev. March 2024)<br><span style="font-size:6.5px">Department of the Treasury<br>Internal Revenue Service</span></td>
    <td><div class="title">Request for Taxpayer<br>Identification Number and Certification</div>
        <div class="sub"><span class="b">Go to</span> <em>www.irs.gov/FormW9</em> for instructions and the latest information.</div></td>
    <td style="width: 112px;" class="give">Give form to the<br>requester. Do not<br>send to the IRS.</td>
</tr></table>

<p style="margin: 3px 0 2px;"><span class="b">Before you begin.</span> For guidance related to the purpose of Form W-9, see <em>Purpose of Form</em>, below. <span style="float: right;">Print or type. See <span class="b">Specific Instructions</span> on page 3.</span></p>

<table class="lines" style="border-top: 1px solid #000;"><tr>
<td>
    <div class="ln"><span class="n">1</span>Name of entity/individual. An entry is required. (For a sole proprietor or disregarded entity, enter the owner’s name on line 1, and enter the business/disregarded entity’s name on line 2.)
        <div class="val">{{ $s['name'] }}</div></div>
    <div class="ln"><span class="n">2</span>Business name/disregarded entity name, if different from above.
        <div class="val">{{ $s['business_name'] }}</div></div>
    <table><tr>
        <td class="ln" style="border-right: 1px solid #000;">
            <span class="n">3a</span>Check the appropriate box for federal tax classification of the entity/individual whose name is entered on line 1. Check <span class="b">only one</span> of the following seven boxes.
            <table class="opts" style="margin: 3px 0 2px 12px; width: auto;"><tr>
                <td>{!! $box($c === 'individual') !!}Individual/sole proprietor</td>
                <td>{!! $box($c === 'c_corp') !!}C corporation</td>
                <td>{!! $box($c === 's_corp') !!}S corporation</td>
                <td>{!! $box($c === 'partnership') !!}Partnership</td>
                <td>{!! $box($c === 'trust') !!}Trust/estate</td>
            </tr></table>
            <div style="margin-left: 12px;">{!! $box($c === 'llc') !!}LLC. Enter the tax classification (C = C corporation, S = S corporation, P = Partnership) . . . .
                <span style="display:inline-block; min-width: 26px; border-bottom: 1px solid #000; text-align:center; font-size: 10px;" class="b">{{ $c === 'llc' ? $s['llc_code'] : '' }}</span></div>
            <div style="margin: 2px 0 2px 12px;"><span class="b">Note:</span> Check the “LLC” box above and, in the entry space, enter the appropriate code (C, S, or P) for the tax classification of the LLC, unless it is a disregarded entity. A disregarded entity should instead check the appropriate box for the tax classification of its owner.</div>
            <div style="margin-left: 12px;">{!! $box($c === 'other') !!}Other (see instructions) <span style="border-bottom: 1px solid #000; display: inline-block; min-width: 160px; font-size: 9px;">{{ $c === 'other' ? $s['other_text'] : '' }}</span></div>
            <div style="margin-top: 3px;"><span class="n">3b</span>If on line 3a you checked “Partnership” or “Trust/estate,” or checked “LLC” and entered “P” as its tax classification, and you are providing this form to a partnership, trust, or estate in which you have an ownership interest, check this box if you have any foreign partners, owners, or beneficiaries. See instructions . . . {!! $box(! empty($s['foreign_partners'])) !!}</div>
        </td>
        <td class="ln" style="width: 150px;">
            <span class="n">4</span>Exemptions (codes apply only to certain entities, not individuals; see instructions on page 3):
            <div style="margin-top: 6px;">Exempt payee code (if any) <span style="display:inline-block; min-width: 30px; border-bottom: 1px solid #000; font-size: 10px; text-align:center;">{{ $s['exempt_payee_code'] }}</span></div>
            <div style="margin-top: 8px;">Exemption from Foreign Account Tax Compliance Act (FATCA) reporting code (if any) <span style="display:inline-block; min-width: 30px; border-bottom: 1px solid #000; font-size: 10px; text-align:center;">{{ $s['fatca_code'] }}</span></div>
            <div style="margin-top: 6px; font-size: 6.5px;"><em>(Applies to accounts maintained outside the United States.)</em></div>
        </td>
    </tr></table>
    <table><tr>
        <td style="border-right: 1px solid #000;">
            <div class="ln"><span class="n">5</span>Address (number, street, and apt. or suite no.). See instructions.<div class="val">{{ $s['address'] }}</div></div>
            <div class="ln"><span class="n">6</span>City, state, and ZIP code<div class="val">{{ $s['city_state_zip'] }}</div></div>
        </td>
        <td class="ln" style="width: 200px;">Requester’s name and address (optional)
            <div style="font-size: 9px; padding: 3px 0 0 4px;">@foreach ($requester as $line){{ $line }}<br>@endforeach</div></td>
    </tr></table>
    <div class="ln" style="border-bottom: none;"><span class="n">7</span>List account number(s) here (optional)<div class="val">{{ $s['account_numbers'] }}</div></div>
</td>
</tr></table>

<table class="bar"><tr><td class="tag">Part I</td><td class="head">Taxpayer Identification Number (TIN)</td></tr></table>
<table style="margin-top: 3px;"><tr>
    <td style="padding-right: 8px;">
        <p style="margin: 0 0 3px;">Enter your TIN in the appropriate box. The TIN provided must match the name given on line 1 to avoid backup withholding. For individuals, this is generally your social security number (SSN). However, for a resident alien, sole proprietor, or disregarded entity, see the instructions for Part I, later. For other entities, it is your employer identification number (EIN). If you do not have a number, see <em>How to get a TIN</em>, later.</p>
        <p style="margin: 0;"><span class="b">Note:</span> If the account is in more than one name, see the instructions for line 1. See also <em>What Name and Number To Give the Requester</em> for guidelines on whose number to enter.</p>
    </td>
    <td style="width: 190px;">
        <div class="tinbox"><div class="lab">Social security number</div>
            <table class="digits"><tr>
                @foreach ([0, 1, 2] as $i){!! $cell($ssn ? ($digits[$i] ?? '') : '') !!}@endforeach<td class="dash">–</td>
                @foreach ([3, 4] as $i){!! $cell($ssn ? ($digits[$i] ?? '') : '') !!}@endforeach<td class="dash">–</td>
                @foreach ([5, 6, 7, 8] as $i){!! $cell($ssn ? ($digits[$i] ?? '') : '') !!}@endforeach
            </tr></table></div>
        <div class="b" style="padding: 2px 4px;">or</div>
        <div class="tinbox"><div class="lab">Employer identification number</div>
            <table class="digits"><tr>
                @foreach ([0, 1] as $i){!! $cell(! $ssn ? ($digits[$i] ?? '') : '') !!}@endforeach<td class="dash">–</td>
                @foreach ([2, 3, 4, 5, 6, 7, 8] as $i){!! $cell(! $ssn ? ($digits[$i] ?? '') : '') !!}@endforeach
            </tr></table></div>
    </td>
</tr></table>

<table class="bar" style="margin-top: 4px;"><tr><td class="tag">Part II</td><td class="head">Certification</td></tr></table>
<div class="cert" style="margin-top: 2px;">
    <p>Under penalties of perjury, I certify that:</p>
    <p>1. The number shown on this form is my correct taxpayer identification number (or I am waiting for a number to be issued to me); and</p>
    <p @class(['strike' => ! empty($s['backup_withholding'])])>2. I am not subject to backup withholding because (a) I am exempt from backup withholding, or (b) I have not been notified by the Internal Revenue Service (IRS) that I am subject to backup withholding as a result of a failure to report all interest or dividends, or (c) the IRS has notified me that I am no longer subject to backup withholding; and</p>
    <p>3. I am a U.S. citizen or other U.S. person (defined below); and</p>
    <p>4. The FATCA code(s) entered on this form (if any) indicating that I am exempt from FATCA reporting is correct.</p>
    <p><span class="b">Certification instructions.</span> You must cross out item 2 above if you have been notified by the IRS that you are currently subject to backup withholding because you have failed to report all interest and dividends on your tax return. For real estate transactions, item 2 does not apply. For mortgage interest paid, acquisition or abandonment of secured property, cancellation of debt, contributions to an individual retirement arrangement (IRA), and, generally, payments other than interest and dividends, you are not required to sign the certification, but you must provide your correct TIN. See the instructions for Part II, later.</p>
</div>
<table class="sign" style="margin-top: 3px;"><tr>
    <td style="width: 46px; font-weight: bold; font-size: 11px; padding: 6px 4px; border-right: 1px solid #000;">Sign<br>Here</td>
    <td style="padding: 2px 6px; width: 70px;" class="b">Signature of<br>U.S. person</td>
    <td style="padding: 2px 4px; vertical-align: bottom;">
        @if ($signature)<img class="sigimg" src="{{ $signature }}" alt="">@else<span class="typed">/s/ {{ $s['signer_name'] }}</span>@endif
        <div style="font-size: 7px;">{{ $s['signer_name'] }}</div></td>
    <td style="width: 120px; padding: 2px 4px; vertical-align: bottom;"><span class="b">Date</span> <span style="font-size: 10px;">{{ $date }}</span></td>
</tr></table>

<div class="gen" style="margin-top: 8px; column-count: 2;">
    <h3>General Instructions</h3>
    <p>Section references are to the Internal Revenue Code unless otherwise noted.</p>
    <p><span class="b">Future developments.</span> For the latest information about developments related to Form W-9 and its instructions, such as legislation enacted after they were published, go to <em>www.irs.gov/FormW9</em>.</p>
    <h3>Purpose of Form</h3>
    <p>An individual or entity (Form W-9 requester) who is required to file an information return with the IRS is giving you this form because they must obtain your correct taxpayer identification number (TIN), which may be your social security number (SSN), individual taxpayer identification number (ITIN), adoption taxpayer identification number (ATIN), or employer identification number (EIN), to report on an information return the amount paid to you, or other amount reportable on an information return.</p>
</div>

<table class="foot" style="border-top: 1px solid #000; margin-top: 6px;"><tr>
    <td style="width: 33%;"></td><td style="text-align: center;">Cat. No. 10231X</td><td style="text-align: right;">Form <span class="b">W-9</span> (Rev. 3-2024)</td>
</tr></table>
</body></html>
