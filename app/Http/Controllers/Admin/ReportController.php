<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Member\MemberTable;
use App\Reports\Reports;
use App\Support\Activity;
use App\Support\Money;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Reports::TYPES]);
    }

    public function show(Request $request, Reports $reports, string $type): JsonResponse|StreamedResponse|Response
    {
        abort_unless(array_key_exists($type, Reports::TYPES), 404);
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'format' => ['nullable', Rule::in(['json', 'csv', 'pdf'])]]);
        $tz = (string) config('app.timezone');
        $from = isset($data['from']) ? Carbon::parse($data['from'], $tz)->startOfDay() : now($tz)->startOfMonth();
        $to = isset($data['to']) ? Carbon::parse($data['to'], $tz)->endOfDay() : now($tz)->endOfDay();
        if ($from->diffInDays($to) > 800) {
            $from = $to->copy()->subDays(800)->startOfDay(); // keep it quick
        }
        $report = $reports->run($type, $from, $to); // the database keeps times in the shop's time zone
        $format = $data['format'] ?? 'json';
        $name = "report-$type-".$from->timezone($tz)->toDateString().'-'.$to->timezone($tz)->toDateString();
        if ($format !== 'json') {
            Activity::log('report.exported', null, ['type' => $type, 'format' => $format, 'rows' => count($report['rows'])]);
        }

        return match ($format) {
            'csv' => response()->streamDownload(function () use ($report) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF"); // Excel reads Amharic correctly
                fputcsv($out, array_map(fn ($c) => $c[0], $report['columns']));
                foreach ($report['rows'] as $row) {
                    fputcsv($out, array_map(fn ($k) => $this->cell($row[$k] ?? '', $report['columns'][$k][1], true), array_keys($report['columns'])));
                }
                if (count($report['rows']) > 1 && $report['totals'] !== []) {
                    $keys = array_keys($report['columns']);
                    fputcsv($out, array_map(fn ($k, $i) => $i === 0 ? 'Total' : (isset($report['totals'][$k]) ? $this->cell($report['totals'][$k], $report['columns'][$k][1], true) : ''), $keys, array_keys($keys)));
                }
                fclose($out);
            }, "$name.csv", ['Content-Type' => 'text/csv; charset=UTF-8']),
            'pdf' => Pdf::loadView('pdf.report', ['report' => $report, 'from' => $from->timezone($tz), 'to' => $to->timezone($tz),
                'site' => Settings::string('site.name'), 'cell' => fn ($v, $kind) => $this->cell($v, $kind, false)])
                ->setPaper('a4', count($report['columns']) > 5 ? 'landscape' : 'portrait')->download("$name.pdf"),
            default => response()->json(['data' => $report + ['from' => $from->timezone($tz)->toDateString(), 'to' => $to->timezone($tz)->toDateString()]]),
        };
    }

    private function cell(mixed $v, string $kind, bool $csv): string
    {
        $s = match ($kind) {
            'money' => $csv ? number_format(((int) $v) / 100, 2, '.', '') : Money::format((int) $v),
            default => (string) $v,
        };

        return $csv ? MemberTable::safe($s) : $s;
    }
}
