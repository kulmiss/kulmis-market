<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #13241a; }
    h1 { font-size: 16px; margin: 0 0 2px; }
    p { margin: 0 0 10px; color: #5b6b61; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 4px 6px; border-bottom: 1px solid #dde5df; text-align: left; }
    th { background: #f4f8f5; }
    td.n, th.n { text-align: right; }
    tfoot td { font-weight: bold; border-top: 2px solid #13241a; }
</style>
</head>
<body>
<h1>{{ $report['title'] }}</h1>
<p>{{ $site }} · {{ $from->format('d M Y') }} – {{ $to->format('d M Y') }} · made {{ now()->timezone(config('app.timezone'))->format('d M Y H:i') }}</p>
<table>
    <thead><tr>@foreach ($report['columns'] as $c)<th class="{{ $c[1] !== 'text' ? 'n' : '' }}">{{ $c[0] }}</th>@endforeach</tr></thead>
    <tbody>
        @forelse ($report['rows'] as $row)
            <tr>@foreach ($report['columns'] as $k => $c)<td class="{{ $c[1] !== 'text' ? 'n' : '' }}">{{ $cell($row[$k] ?? '', $c[1]) }}</td>@endforeach</tr>
        @empty
            <tr><td colspan="{{ count($report['columns']) }}">Nothing in these dates.</td></tr>
        @endforelse
    </tbody>
    @if (count($report['totals']) && count($report['rows']) > 1)
        <tfoot><tr>@foreach ($report['columns'] as $k => $c)<td class="{{ $c[1] !== 'text' ? 'n' : '' }}">{{ $loop->first ? 'Total' : (isset($report['totals'][$k]) ? $cell($report['totals'][$k], $c[1]) : '') }}</td>@endforeach</tr></tfoot>
    @endif
</table>
</body>
</html>
