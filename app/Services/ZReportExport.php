<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\Reports\LogPrint;
use App\Enums\PrintDocument;
use App\Enums\PrintFormat;
use App\Exceptions\PreviewNotExportable;
use App\Models\User;
use App\Services\Reports\ZPaper;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports of the Z paper (37b) and the day paper (38b), TRD §6 and SYSTEM_DESIGN §5.2. Both
 * formats render the one array from ReportService::zReport() / dayZ(): the PDF through the Blade
 * twin of ZPaper (resources/views/reports/zreport.blade.php, A4, dompdf), the CSV as streamed
 * rows Section, Line, Count, Amount with a UTF-8 BOM. A preview is refused. Every export writes
 * print_logs first, so the first export of a paper is the print and later ones are reprints.
 *
 * Other reports export their tables as CSV through table().
 */
final class ZReportExport
{
    public function __construct(private readonly LogPrint $log) {}

    /** @param  array<string, mixed>  $paper */
    public function pdf(array $paper, User $by, int $branchId, PrintDocument $document, int $subjectId, string $filename): Response
    {
        $this->refusePreview($paper);
        $this->log->handle($by, $branchId, $document, $subjectId, PrintFormat::Pdf);

        $logo = resource_path('images/brand/homi-logo-400.png');

        return Pdf::loadView('reports.zreport', [
            'paper' => $paper,
            'logo' => extension_loaded('gd') && is_file($logo) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logo)) : null,
            'product' => config('hotel.name'),
        ])->setPaper('a4')->download($filename);
    }

    /** @param  array<string, mixed>  $paper */
    public function csv(array $paper, User $by, int $branchId, PrintDocument $document, int $subjectId, string $filename): StreamedResponse
    {
        $this->refusePreview($paper);
        $this->log->handle($by, $branchId, $document, $subjectId, PrintFormat::Csv);

        return self::stream(ZPaper::csvRows($paper), $filename);
    }

    /**
     * A report's tables as one CSV (38, 40–43 and 37): each table is its title, its header row
     * and its rows, with a blank row between tables.
     *
     * @param  list<array{title: string, columns: list<string>, rows: list<list<string|int>>}>  $tables
     */
    public function table(array $tables, User $by, int $branchId, ?int $subjectId, string $filename): StreamedResponse
    {
        $this->log->handle($by, $branchId, PrintDocument::Report, $subjectId, PrintFormat::Csv);
        $rows = [];

        foreach ($tables as $n => $table) {
            if ($n > 0) {
                $rows[] = [];
            }

            $rows[] = [$table['title']];
            $rows[] = $table['columns'];

            foreach ($table['rows'] as $row) {
                $rows[] = array_map(static fn (string|int $v): string => (string) $v, $row);
            }
        }

        return self::stream($rows, $filename);
    }

    /** @param  list<list<string>>  $rows */
    private static function stream(array $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            fwrite($out, "\u{FEFF}");

            foreach ($rows as $row) {
                fputcsv($out, $row, ',', '"', '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @param  array<string, mixed>  $paper */
    private function refusePreview(array $paper): void
    {
        if (($paper['preview'] ?? true) === true) {
            throw PreviewNotExportable::make();
        }
    }
}
