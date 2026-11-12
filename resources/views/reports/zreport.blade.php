{{--
    The PDF twin of ZPaper (37b, 38b): A4 portrait, 12 mm margins, DejaVu Sans 9.5 pt, plain tables
    only (DESIGN_SYSTEM §6.10). It renders the builder array of ReportService::zReport() / dayZ()
    line by line and computes nothing; amounts arrive as signed centavos and are only formatted.
--}}
@php
    $money = function (?int $c, bool $sign = true): string {
        if ($c === null) {
            return '—';
        }
        $abs = number_format(intdiv(abs($c), 100)).'.'.str_pad((string) (abs($c) % 100), 2, '0', STR_PAD_LEFT);

        return ($c < 0 && $sign ? '−' : '').$abs;
    };
    $h = $paper['header'];
    $left = array_values(array_filter($paper['sections'], fn ($s) => $s['column'] === 'left'));
    $right = array_values(array_filter($paper['sections'], fn ($s) => $s['column'] === 'right'));
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $h['title'] }}</title>
<style>
    @page { size: A4 portrait; margin: 12mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; color: #1f2328; }
    table { border-collapse: collapse; width: 100%; }
    td, th { padding: 2px 0; vertical-align: top; }
    .r { text-align: right; white-space: nowrap; }
    .muted { color: #6b7280; }
    .head td { padding: 0 0 10px 0; }
    .num { font-size: 15pt; font-weight: bold; }
    h6 { font-size: 8pt; text-transform: uppercase; letter-spacing: .06em; color: #6b7280; margin: 12px 0 4px; }
    .indent td.l { padding-left: 12px; color: #4b5563; }
    .equals td { border-top: 1px solid #1f2328; font-weight: bold; }
    .bad { color: #a12b2b; }
    .t th { text-align: left; font-weight: normal; color: #6b7280; border-bottom: 1px solid #d1d5db; }
    .t th.r { text-align: right; }
    .t td { border-bottom: 1px solid #eceef1; }
    .sig td { width: 33%; padding: 36px 12px 0 0; }
    .sig div { border-top: 1px solid #1f2328; padding-top: 3px; }
    .preview { color: #9ca3af; font-size: 14pt; font-weight: bold; }
</style>
</head>
<body>
<table class="head"><tr>
    <td>
        @if ($logo)<img src="{{ $logo }}" style="width: 30mm"><br>@else<b>{{ $product }}</b><br>@endif
        <b>{{ $h['branch'] }}</b><br>
        @if ($h['registered_name'])<span class="muted">{{ $h['registered_name'] }} · TIN {{ $h['tin'] }}</span><br>@endif
        <span class="muted">{{ $h['title'] }}</span>
    </td>
    <td class="r">
        <span class="num">{{ $h['number'] ?? '' }}</span><br>
        <span class="muted">{{ $h['opened'] }}@if ($h['closed']) · {{ $h['closed'] }}@endif</span><br>
        @if ($h['printed'])<span class="muted">{{ $h['printed'] }}</span>@endif
    </td>
</tr></table>
@if ($paper['preview'])<p class="preview">Preview. Do not sign.</p>@endif

<table><tr>
<td style="width: 50%; padding-right: 14px">
@foreach ($left as $section)
    <h6>{{ $section['title'] }}</h6>
    <table>
    @foreach ($section['lines'] as $line)
        <tr class="{{ $line['style'] ?? 'plain' }}">
            <td class="l {{ ($line['tone'] ?? null) === 'bad' ? 'bad' : '' }}">{{ $line['label'] }}@isset($line['count']) · {{ $line['count'] }}@endisset</td>
            <td class="r {{ ($line['tone'] ?? null) === 'bad' ? 'bad' : '' }}">{{ $money($line['amount'] ?? null) }}</td>
        </tr>
        @if (! empty($line['note']))<tr><td colspan="2" class="muted">{{ $line['note'] }}</td></tr>@endif
    @endforeach
    </table>
@endforeach
</td>
<td style="width: 50%; padding-left: 14px">
@foreach ($right as $section)
    <h6>{{ $section['title'] }}</h6>
    @if ($section['layout'] === 'table')
        <table class="t">
            <tr>@foreach ($section['columns'] as $col)<th class="{{ isset($col['type']) ? 'r' : '' }}">{{ $col['label'] }}</th>@endforeach</tr>
            @foreach ($section['lines'] as $line)
                <tr>
                    <td class="{{ ($line['tone'] ?? null) === 'bad' ? 'bad' : '' }}">{{ $line['label'] }}</td>
                    @if (isset($line['cells']))
                        @foreach ($line['cells'] as $n => $cell)
                            <td class="r">{{ ($section['columns'][$n + 1]['type'] ?? '') === 'money' ? $money($cell) : $cell }}</td>
                        @endforeach
                    @else
                        <td class="r">{{ $line['count'] ?? '' }}</td>
                        <td class="r">{{ $money($line['amount'] ?? null) }}</td>
                    @endif
                </tr>
            @endforeach
        </table>
    @else
        <table>
        @foreach ($section['lines'] as $line)
            <tr class="{{ $line['style'] ?? 'plain' }}">
                <td class="l">{{ $line['label'] }}</td>
                <td class="r">@if (array_key_exists('amount', $line)){{ $money($line['amount']) }}@else{{ $line['count'] ?? '' }}@endif</td>
            </tr>
        @endforeach
        </table>
    @endif
@endforeach
</td>
</tr></table>

<table class="sig"><tr>
@foreach ($paper['signatures'] as $s)
    <td><div>{{ $s['name'] ?? ' ' }}<br><span class="muted">{{ $s['role'] }}</span></div></td>
@endforeach
</tr></table>
</body>
</html>
