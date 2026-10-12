<?php

namespace App\Http\Controllers\Traits;

use App\Services\SimplePdf;

trait ExportsReports
{
    protected const FORMATS = ['csv', 'excel', 'pdf'];

    /**
     * Dispatch a report export to CSV, Excel or PDF.
     *
     * @param array<int, string> $headers
     * @param array<int, string> $cols
     * @param array<int, array<string, mixed>> $rows
     */
    protected function exportReport(string $format, string $title, string $subtitle, array $headers, array $cols, array $rows, string $base)
    {
        $format = in_array($format, static::FORMATS, true) ? $format : 'csv';

        return match ($format) {
            'excel' => $this->excelResponse($title, $subtitle, $headers, $cols, $rows, $base),
            'pdf'   => $this->pdfResponse($title, $subtitle, $headers, $cols, $rows, $base),
            default => $this->csvResponse($title, $headers, $cols, $rows, $base),
        };
    }

    protected function csvResponse(string $title, array $headers, array $cols, array $rows, string $base)
    {
        $filename = $base . '.csv';
        return response()->stream(function () use ($headers, $cols, $rows) {
            $h = fopen('php://output', 'w');
            fputcsv($h, $headers);
            foreach ($rows as $row) {
                $line = [];
                foreach ($cols as $key) {
                    $line[] = $row[$key] ?? '';
                }
                fputcsv($h, $line);
            }
            fclose($h);
        }, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    protected function excelResponse(string $title, string $subtitle, array $headers, array $cols, array $rows, string $base)
    {
        $filename = $base . '.xls';
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= "<?mso-application progid=\"Excel.Sheet\"?>\n";
        $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" '
            . 'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
        $xml .= '  <Worksheet ss:Name="Report">' . "\n";
        $xml .= '    <Table>' . "\n";

        $xml .= $this->excelRow([$title], true);
        $xml .= $this->excelRow([$subtitle], false);
        $xml .= $this->excelRow([' '], false);
        $xml .= $this->excelRow($headers, true);

        foreach ($rows as $row) {
            $line = [];
            foreach ($cols as $key) {
                $line[] = $row[$key] ?? '';
            }
            $xml .= $this->excelRow($line, false);
        }

        $xml .= '    </Table>' . "\n";
        $xml .= '  </Worksheet>' . "\n";
        $xml .= '</Workbook>' . "\n";

        return response($xml, 200, [
            'Content-Type'        => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    protected function pdfResponse(string $title, string $subtitle, array $headers, array $cols, array $rows, string $base)
    {
        $filename = $base . '.pdf';

        $pdfRows = [];
        foreach ($rows as $row) {
            $line = [];
            foreach ($cols as $key) {
                $line[] = (string) ($row[$key] ?? '');
            }
            $pdfRows[] = $line;
        }

        $pdf = new SimplePdf();
        $content = $pdf->render($title, $subtitle, $headers, $pdfRows);

        return response($content, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    protected function excelRow(array $cells, bool $bold): string
    {
        $style = $bold ? ' ss:Bold="1"' : '';
        $out = '      <Row>';
        foreach ($cells as $value) {
            $out .= '<Cell' . $style . '><Data ss:Type="String">'
                . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8')
                . '</Data></Cell>';
        }
        $out .= '</Row>' . "\n";
        return $out;
    }
}
