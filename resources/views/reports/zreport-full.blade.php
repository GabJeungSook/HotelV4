{{--
    The whole Z-report as one standalone HTML file (37b + all its tabs on one page): header, a
    glance strip, the Z paper (sales, payments, net), the shift drawer and roll-forwards, rooms by
    floor with per-floor charts, the cleaning chart, the housekeeping log, stocks and expenses. It
    renders the one assembled array from ReportController::zHtml() and computes nothing; amounts
    arrive as signed centavos and are only formatted.
--}}
@php
    $paper = $data['paper'];
    $shiftR = $data['shift'];
    $rooms = $data['rooms'];
    $cleaning = $data['cleaning'];
    $hk = $data['housekeeping'];
    $stocks = $data['stocks'];
    $expenses = $data['expenses'];
    $h = $paper['header'];

    $money = function (?int $c, bool $sign = true): string {
        if ($c === null) {
            return '—';
        }
        $abs = number_format(intdiv(abs($c), 100)).'.'.str_pad((string) (abs($c) % 100), 2, '0', STR_PAD_LEFT);

        return ($c < 0 && $sign ? '−' : '').'₱'.$abs;
    };
    $time = function (?string $iso): string {
        return $iso ? \Illuminate\Support\Carbon::parse($iso)->timezone('Asia/Manila')->format('g:i A') : '—';
    };
    $kindLabel = ['expense' => 'Expense', 'remittance' => 'Remittance', 'shortage_payment' => 'Shortage payment'];
    $left = array_values(array_filter($paper['sections'], fn ($s) => $s['column'] === 'left'));
    $right = array_values(array_filter($paper['sections'], fn ($s) => $s['column'] === 'right'));
    $g = $shiftR['guests'];
    $dep = $shiftR['deposits'];
    $unpaid = $shiftR['unpaid'];
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Z-Report · {{ $h['branch'] }}</title>
<style>
    *, *::before, *::after { box-sizing: border-box; }
    body { margin: 0; font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #1f2328; background: #f3f4f6; font-size: 13px; line-height: 1.5; }
    .page { max-width: 980px; margin: 0 auto; padding: 24px 20px 64px; }
    .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px 22px; margin-bottom: 18px; }
    h1 { font-size: 26px; margin: 0; letter-spacing: -0.02em; }
    h2 { font-size: 17px; margin: 0 0 12px; }
    h3 { font-size: 13px; text-transform: uppercase; letter-spacing: 0.06em; color: #6b7280; margin: 14px 0 6px; }
    .muted { color: #6b7280; }
    .brandrow { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; border-bottom: 2px solid #1f2328; padding-bottom: 16px; margin-bottom: 18px; }
    .brandrow img { height: 34px; }
    .znum { font-size: 22px; font-weight: 700; text-align: right; }
    table { border-collapse: collapse; width: 100%; }
    .data th, .data td { border: 1px solid #e5e7eb; padding: 6px 9px; text-align: left; }
    .data th { background: #f9fafb; font-weight: 600; }
    .data tbody tr:nth-child(even) td { background: #fafbfc; }
    .r { text-align: right; white-space: nowrap; }
    .num { font-variant-numeric: tabular-nums; }
    .cols { display: flex; gap: 28px; flex-wrap: wrap; }
    .cols > div { flex: 1 1 320px; }
    .kv { display: flex; justify-content: space-between; gap: 12px; padding: 3px 0; }
    .kv b { font-variant-numeric: tabular-nums; }
    .tot { border-top: 1px solid #1f2328; margin-top: 4px; padding-top: 4px; font-weight: 700; }
    .bad { color: #b42318; }
    .stats { display: flex; flex-wrap: wrap; gap: 12px; }
    .stat { flex: 1 1 150px; border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px 14px; background: #f9fafb; }
    .stat .lbl { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; }
    .stat .val { font-size: 22px; font-weight: 700; font-variant-numeric: tabular-nums; margin-top: 2px; }
    .fwd td { color: #b42318; }
    .pill { display: inline-block; font-size: 11px; font-weight: 600; padding: 0 6px; border-radius: 999px; border: 1px solid; margin-left: 4px; }
    .pill.fwd { color: #b42318; border-color: #f3b4ab; }
    .pill.ext { color: #b45309; border-color: #f5d08a; }
    .sig { display: flex; gap: 40px; margin-top: 36px; }
    .sig > div { flex: 1; }
    .sig .line { border-top: 1px solid #1f2328; padding-top: 4px; }
    .foot { text-align: center; color: #6b7280; font-size: 12px; margin-top: 28px; }
    .roomlist { font-size: 12px; color: #374151; }
    .roomlist span { display: inline-block; margin-right: 14px; white-space: nowrap; }
    @media print {
        body { background: #fff; }
        .page { max-width: none; padding: 0; }
        .card { border: none; border-radius: 0; padding: 0 0 10px; margin-bottom: 14px; page-break-inside: avoid; }
        .no-print { display: none !important; }
    }
</style>
</head>
<body>
<div class="page">

    {{-- Header --}}
    <div class="brandrow">
        <div>
            @if ($logo)<img src="{{ $logo }}" alt="{{ $product }}"><br>@else<b>{{ $product }}</b><br>@endif
            <h1 style="font-size:19px; margin-top:6px;">{{ $h['branch'] }}</h1>
            @if ($h['registered_name'])<div class="muted">{{ $h['registered_name'] }} · TIN {{ $h['tin'] }}</div>@endif
            <div class="muted">{{ $h['title'] }}</div>
        </div>
        <div>
            <div class="znum">{{ $h['number'] ?? '' }}</div>
            <div class="muted" style="text-align:right;">{{ $h['opened'] }}@if ($h['closed']) · {{ $h['closed'] }}@endif</div>
            @if ($h['printed'])<div class="muted" style="text-align:right;">{{ $h['printed'] }}</div>@endif
        </div>
    </div>

    {{-- At a glance --}}
    <div class="card">
        <div class="stats">
            <div class="stat"><div class="lbl">Net sales</div><div class="val">{{ $money($rooms['net_sales']) }}</div></div>
            <div class="stat"><div class="lbl">New guests</div><div class="val">{{ $g['checked_in'] }}</div></div>
            <div class="stat"><div class="lbl">Checked out</div><div class="val">{{ $g['checked_out'] }}</div></div>
            <div class="stat"><div class="lbl">In house at close</div><div class="val">{{ $g['forwarded_out'] }}</div></div>
            <div class="stat"><div class="lbl">Rooms cleaned</div><div class="val">{{ $hk['total']['cleans'] }}</div></div>
        </div>
    </div>

    {{-- Z paper: sales, payments, net --}}
    <div class="card">
        <h2>Sales &amp; cash</h2>
        <div class="cols">
            <div>
                @foreach ($left as $section)
                    <h3>{{ $section['title'] }}</h3>
                    @foreach ($section['lines'] as $line)
                        <div class="kv {{ ($line['style'] ?? '') === 'equals' ? 'tot' : '' }}">
                            <span class="{{ ($line['tone'] ?? null) === 'bad' ? 'bad' : '' }}">{{ $line['label'] }}@isset($line['count']) · {{ $line['count'] }}@endisset</span>
                            <b class="{{ ($line['tone'] ?? null) === 'bad' ? 'bad' : '' }}">{{ $money($line['amount'] ?? null) }}</b>
                        </div>
                        @if (! empty($line['note']))<div class="muted" style="font-size:12px;">{{ $line['note'] }}</div>@endif
                    @endforeach
                @endforeach
            </div>
            <div>
                @foreach ($right as $section)
                    <h3>{{ $section['title'] }}</h3>
                    @if (($section['layout'] ?? '') === 'table')
                        <table class="data">
                            <tr>@foreach ($section['columns'] as $col)<th class="{{ isset($col['type']) ? 'r' : '' }}">{{ $col['label'] }}</th>@endforeach</tr>
                            @foreach ($section['lines'] as $line)
                                <tr>
                                    <td class="{{ ($line['tone'] ?? null) === 'bad' ? 'bad' : '' }}">{{ $line['label'] }}</td>
                                    @if (isset($line['cells']))
                                        @foreach ($line['cells'] as $n => $cell)<td class="r num">{{ ($section['columns'][$n + 1]['type'] ?? '') === 'money' ? $money($cell) : $cell }}</td>@endforeach
                                    @else
                                        <td class="r num">{{ $line['count'] ?? '' }}</td><td class="r num">{{ $money($line['amount'] ?? null) }}</td>
                                    @endif
                                </tr>
                            @endforeach
                        </table>
                    @else
                        @foreach ($section['lines'] as $line)
                            <div class="kv {{ ($line['style'] ?? '') === 'equals' ? 'tot' : '' }}">
                                <span>{{ $line['label'] }}</span>
                                <b>@if (array_key_exists('amount', $line)){{ $money($line['amount']) }}@else{{ $line['count'] ?? '' }}@endif</b>
                            </div>
                        @endforeach
                    @endif
                @endforeach
            </div>
        </div>
    </div>

    {{-- Drawer, guests, deposits, unpaid --}}
    <div class="card">
        <h2>Shift reconciliation</h2>
        <div class="cols">
            <div>
                <h3>Cash drawer</h3>
                @foreach ($shiftR['cash'] as $line)
                    <div class="kv"><span>{{ $line['label'] }}</span><b>{{ $money($line['amount']) }}</b></div>
                @endforeach
                <div class="kv tot"><span>Expected in drawer</span><b>{{ $money($shiftR['expected']) }}</b></div>
                <div class="kv"><span class="muted">Counted</span><b>{{ $money($shiftR['counted']) }}</b></div>
                @if ($shiftR['variance'])<div class="kv"><span class="bad">Short / over</span><b class="bad">{{ $money($shiftR['variance']) }}</b></div>@endif
                <div class="kv"><span class="muted">GCash / card (not in drawer)</span><b>{{ $money($shiftR['not_in_drawer']) }}</b></div>
            </div>
            <div>
                <h3>Guests</h3>
                <div class="kv"><span class="muted">Forwarded in</span><b>{{ $g['forwarded_in'] }}</b></div>
                <div class="kv"><span class="muted">Checked in</span><b>{{ $g['checked_in'] }}</b></div>
                <div class="kv"><span class="muted">Checked out{{ $g['cancelled'] ? " (incl. {$g['cancelled']} cancelled)" : '' }}</span><b>{{ $g['checked_out'] ? '−'.$g['checked_out'] : 0 }}</b></div>
                <div class="kv tot"><span>Forwarded out</span><b>{{ $g['forwarded_out'] }}</b></div>

                <h3>Key deposits</h3>
                <div class="kv"><span class="muted">Held at open</span><b>{{ $money($dep['held_open']) }}</b></div>
                <div class="kv"><span class="muted">Taken</span><b>{{ $money($dep['taken']) }}</b></div>
                <div class="kv"><span class="muted">Returned</span><b>{{ $money(-$dep['returned']) }}</b></div>
                <div class="kv"><span class="muted">Forfeited</span><b>{{ $money(-$dep['forfeited']) }}</b></div>
                <div class="kv tot"><span>Held at close</span><b>{{ $money($dep['held_close']) }}</b></div>

                <h3>Unpaid balances</h3>
                <div class="kv"><span class="muted">Forwarded in</span><b>{{ $money($unpaid['forwarded_in']) }}</b></div>
                <div class="kv"><span class="muted">Charged</span><b>{{ $money($unpaid['charged']) }}</b></div>
                <div class="kv"><span class="muted">Collected</span><b>{{ $money(-$unpaid['collected']) }}</b></div>
                <div class="kv tot"><span>Forwarded out</span><b>{{ $money($unpaid['forwarded_out']) }}</b></div>
            </div>
        </div>
    </div>

    {{-- Rooms by floor --}}
    <div class="card">
        <h2>Rooms by floor</h2>
        <table class="data">
            <thead>
                <tr>
                    <th>Category</th>
                    @foreach ($rooms['columns'] as $c)<th class="r">{{ $c['label'] }}</th>@endforeach
                    <th class="r">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rooms['rows'] as $row)
                    <tr>
                        <td>{{ $row['label'] }}</td>
                        @foreach ($rooms['columns'] as $c)<td class="r num">{{ $money($row['cells'][$c['key']] ?? 0) }}</td>@endforeach
                        <td class="r num">{{ $money($row['total']) }}</td>
                    </tr>
                @endforeach
                <tr style="font-weight:700;">
                    <td>Gross total</td>
                    @foreach ($rooms['columns'] as $c)<td class="r num">{{ $money($rooms['gross']['cells'][$c['key']] ?? 0) }}</td>@endforeach
                    <td class="r num">{{ $money($rooms['gross']['total']) }}</td>
                </tr>
                <tr>
                    <td>Deposits held at close</td>
                    @foreach ($rooms['columns'] as $c)<td class="r num">{{ $c['key'] === 'none' ? '—' : $money($rooms['deposits']['cells'][$c['key']] ?? 0) }}</td>@endforeach
                    <td class="r num">{{ $money($rooms['deposits']['total']) }}</td>
                </tr>
            </tbody>
        </table>

        @foreach ($rooms['charts'] as $chart)
            <h3>{{ $chart['floor'] }} · room chart</h3>
            <table class="data">
                <thead>
                    <tr><th>Room</th><th>Guest</th><th>In</th><th>Out</th><th class="r">Room</th><th class="r">Ext.</th><th class="r">Food</th><th class="r">Drinks</th><th class="r">Misc</th><th class="r">Dep.</th></tr>
                </thead>
                <tbody>
                    @foreach ($chart['rows'] as $row)
                        <tr class="{{ ($row['forwarded'] ?? false) ? 'fwd' : '' }}">
                            <td class="num">{{ $row['room'] }}</td>
                            @if ($row['vacant'] ?? false)
                                <td class="muted">Vacant all shift</td><td></td><td></td>
                                @for ($n = 0; $n < 6; $n++)<td class="r">0</td>@endfor
                            @else
                                <td>{{ $row['guest'] ?? '' }}@if ($row['forwarded'] ?? false)<span class="pill fwd">FWD</span>@endif @if ($row['extended'] ?? false)<span class="pill ext">EXT</span>@endif</td>
                                <td>{{ $time($row['in'] ?? null) }}</td>
                                <td>{{ $time($row['out'] ?? null) }}</td>
                                <td class="r num">{{ $money($row['room_charge'] ?? 0) }}</td>
                                <td class="r num">{{ $money($row['ext'] ?? 0) }}</td>
                                <td class="r num">{{ $money($row['foods'] ?? 0) }}</td>
                                <td class="r num">{{ $money($row['drinks'] ?? 0) }}</td>
                                <td class="r num">{{ $money($row['misc'] ?? 0) }}</td>
                                <td class="r num">{{ $money($row['deposit'] ?? 0) }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach
        <p class="muted" style="font-size:12px;">Red rows are forwarded guests: their room charge was a sale of the shift that checked them in, so it shows 0 here.</p>
    </div>

    {{-- Cleaning chart --}}
    <div class="card">
        <h2>Cleaning chart</h2>
        @if (count($cleaning) === 0)
            <p class="muted">No room was queued or cleaned during this shift.</p>
        @else
            <table class="data">
                <thead><tr><th>Room</th><th>Guest left</th><th>Deadline</th><th>Started</th><th>Ready</th><th>Housekeeper</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach ($cleaning as $t)
                        <tr>
                            <td class="num">{{ $t['room'] }}</td>
                            <td>{{ $time($t['queued_at']) }}</td>
                            <td>{{ $time($t['due_at']) }}</td>
                            <td>{{ $time($t['started_at']) }}</td>
                            <td>{{ $time($t['finished_at']) }}</td>
                            <td>{{ $t['by'] ?? '—' }}</td>
                            <td class="{{ $t['is_late'] ? 'bad' : '' }}">{{ $t['is_late'] ? 'Late' : ($t['status'] !== 'done' ? ucfirst(str_replace('_', ' ', $t['status'])) : ($t['result'] === 'problem' ? 'Problem' : 'Done')) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Housekeeping --}}
    <div class="card">
        <h2>Housekeeping</h2>
        @if (count($hk['people']) === 0)
            <p class="muted">No room was cleaned during this shift.</p>
        @else
            <table class="data">
                <thead><tr><th>Housekeeper</th><th>Floors</th><th class="r">Cleans</th><th class="r">Average</th><th class="r">Late</th></tr></thead>
                <tbody>
                    @foreach ($hk['people'] as $p)
                        <tr>
                            <td>{{ $p['name'] }}</td>
                            <td>{{ $p['floors'] }}</td>
                            <td class="r num">{{ $p['cleans'] }}</td>
                            <td class="r num">{{ $p['average_minutes'] }} min</td>
                            <td class="r num {{ $p['late'] ? 'bad' : '' }}">{{ $p['late'] }}</td>
                        </tr>
                        @if (! empty($p['rooms']))
                            <tr><td colspan="5" class="roomlist">@foreach ($p['rooms'] as $room)<span><b class="{{ $room['late'] ? 'bad' : '' }}">{{ $room['room'] }}</b> <span class="muted">{{ $time($room['ready']) }}</span></span>@endforeach</td></tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Stocks --}}
    <div class="card">
        <h2>Stocks</h2>
        @if (count($stocks) === 0)
            <p class="muted">No stock movement in this shift.</p>
        @else
            <table class="data">
                <thead><tr><th>Product</th><th class="r">Opening</th><th class="r">In</th><th class="r">Sold</th><th class="r">Wastage</th><th class="r">Closing</th></tr></thead>
                <tbody>
                    @foreach ($stocks as $p)
                        <tr><td>{{ $p['name'] }}</td><td class="r num">{{ $p['opening'] }}</td><td class="r num">{{ $p['in'] }}</td><td class="r num">{{ $p['sold'] }}</td><td class="r num">{{ $p['wastage'] }}</td><td class="r num">{{ $p['closing'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Expenses --}}
    <div class="card">
        <h2>Expenses &amp; cash movements</h2>
        @if (count($expenses) === 0)
            <p class="muted">No cash entries on this shift.</p>
        @else
            <table class="data">
                <thead><tr><th>Time</th><th>Entry</th><th>Note</th><th>By</th><th class="r">Amount</th></tr></thead>
                <tbody>
                    @foreach ($expenses as $e)
                        <tr @if ($e['voided']) style="text-decoration:line-through; color:#9ca3af;" @endif>
                            <td>{{ $time($e['occurred_at']) }}</td>
                            <td>{{ $e['category'] ?? ($kindLabel[$e['kind']] ?? $e['kind']) }}</td>
                            <td>{{ $e['note'] }}@if ($e['voided']) · voided: {{ $e['void_reason'] }}@endif</td>
                            <td>{{ $e['by'] }}</td>
                            <td class="r num">{{ $money($e['amount']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Signatures --}}
    <div class="sig">
        @foreach ($paper['signatures'] as $s)
            <div><div class="line">{{ $s['name'] ?? ' ' }}<br><span class="muted">{{ $s['role'] }}</span></div></div>
        @endforeach
    </div>

    <div class="foot">{{ $product }} · generated {{ now()->timezone('Asia/Manila')->format('F d, Y \a\t g:i A') }}</div>
</div>
</body>
</html>
