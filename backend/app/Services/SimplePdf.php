<?php

namespace App\Services;

/**
 * Minimal dependency-free PDF renderer for simple tabular reports.
 *
 * Uses built-in PDF Type1 fonts (Helvetica) and approximates text widths so
 * cell content is wrapped across multiple lines. Suitable for A4 reports
 * with a title, subtitle and a data table that flows over pages.
 */
class SimplePdf
{
    protected float $pageWidth = 595.28;
    protected float $pageHeight = 841.89;
    protected float $margin = 36;

    protected string $content = '';
    protected array $pages = [];
    protected float $y = 0;
    protected int $pageNumber = 0;

    protected string $title = '';
    protected string $subtitle = '';

    protected array $tableHeader = [];
    protected bool $inTable = false;

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function setSubtitle(string $subtitle): void
    {
        $this->subtitle = $subtitle;
    }

    public function render(string $title, string $subtitle, array $headers, array $rows): string
    {
        $this->title = $title;
        $this->subtitle = $subtitle;

        $this->startPage();
        $this->drawTitle();

        $fontSize = 8;
        $widths = $this->computeColumnWidths($headers, $rows, $fontSize);
        $this->drawTable($headers, $rows, $widths, $fontSize);

        return $this->output();
    }

    protected function startPage(): void
    {
        $this->content = '';
        $this->y = $this->pageHeight - $this->margin;
        $this->pageNumber++;
    }

    protected function finishPage(): void
    {
        $this->drawFooter();
        $this->pages[] = $this->content;
    }

    protected function ensureSpace(float $height): bool
    {
        if ($this->y - $height < $this->margin) {
            $this->finishPage();
            $this->startPage();
            $this->redrawTableHeader();
            return true;
        }
        return false;
    }

    protected function drawTitle(): void
    {
        if ($this->title !== '') {
            $this->drawText($this->title, $this->margin, $this->y, 15, true, [0.1, 0.12, 0.2]);
            $this->y -= 20;
        }
        if ($this->subtitle !== '') {
            $this->drawText($this->subtitle, $this->margin, $this->y, 9, false, [0.35, 0.37, 0.45]);
            $this->y -= 18;
        }
        $this->drawLine($this->margin, $this->y, $this->pageWidth - $this->margin, $this->y, [0.8, 0.82, 0.86]);
        $this->y -= 10;
    }

    protected function drawFooter(): void
    {
        $text = 'Generated ' . now()->format('Y-m-d H:i') . '   |   Page ' . $this->pageNumber;
        $this->drawText($text, $this->margin, 26, 7, false, [0.55, 0.57, 0.63]);
    }

    protected function computeColumnWidths(array $headers, array $rows, float $fontSize): array
    {
        $cols = count($headers);
        $available = $this->pageWidth - $this->margin * 2;
        $raw = [];
        for ($c = 0; $c < $cols; $c++) {
            $maxW = $this->textWidth($headers[$c] ?? '', $fontSize) + 10;
            foreach ($rows as $r) {
                $w = $this->textWidth($r[$c] ?? '', $fontSize) + 10;
                if ($w > $maxW) {
                    $maxW = $w;
                }
            }
            $raw[] = min($maxW, 240);
        }
        $totalRaw = array_sum($raw);
        $widths = [];
        if ($totalRaw <= 0) {
            $w = round($available / $cols, 2);
            return array_fill(0, $cols, $w);
        }
        foreach ($raw as $w) {
            $widths[] = max(26, round($w / $totalRaw * $available, 2));
        }
        $sum = array_sum($widths);
        if ($sum > $available) {
            $scale = $available / $sum;
            foreach ($widths as &$w) {
                $w = max(20, round($w * $scale, 2));
            }
            unset($w);
        }
        return $widths;
    }

    protected function drawTable(array $headers, array $rows, array $widths, float $fontSize): void
    {
        $this->inTable = true;
        $this->tableHeader = [];

        $lineHeight = 10.5;
        $pad = 4;
        $headerH = 16;
        $tableWidth = array_sum($widths);
        $x = $this->margin;

        $this->ensureSpace($headerH);
        $this->tableHeader = [$headers, $widths];
        $this->drawHeaderRow($headers, $widths, $x, $headerH, $fontSize);
        $this->y -= $headerH;

        foreach ($rows as $i => $row) {
            $wrapped = [];
            $maxLines = 1;
            foreach ($headers as $c => $_) {
                $lines = $this->wrapText((string) ($row[$c] ?? ''), $widths[$c] - $pad * 2, $fontSize);
                $wrapped[$c] = $lines;
                if (count($lines) > $maxLines) {
                    $maxLines = count($lines);
                }
            }
            $rowH = $maxLines * $lineHeight + 6;

            $this->ensureSpace($rowH);

            if ($i % 2 === 1) {
                $this->rect($x, $this->y - $rowH, $tableWidth, $rowH, [0.95, 0.96, 0.98]);
            }

            $cx = $x;
            $ty = $this->y - 11;
            foreach ($headers as $c => $_) {
                $ly = $ty;
                foreach ($wrapped[$c] as $line) {
                    $this->drawText($line, $cx + $pad, $ly, $fontSize, false, [0.15, 0.17, 0.22]);
                    $ly -= $lineHeight;
                }
                $cx += $widths[$c];
            }

            $this->y -= $rowH;
        }

        $this->inTable = false;
        $this->tableHeader = [];
    }

    protected function drawHeaderRow(array $headers, array $widths, float $x, float $headerH, float $fontSize): void
    {
        $tableWidth = array_sum($widths);
        $pad = 4;
        $this->rect($x, $this->y - $headerH, $tableWidth, $headerH, [0.16, 0.18, 0.28]);
        $cx = $x;
        foreach ($headers as $c => $h) {
            $this->drawText((string) $h, $cx + $pad, $this->y - 11, $fontSize, true, [1, 1, 1]);
            $cx += $widths[$c];
        }
    }

    protected function redrawTableHeader(): void
    {
        if (!$this->inTable || empty($this->tableHeader)) {
            return;
        }
        [$headers, $widths] = $this->tableHeader;
        $headerH = 16;
        $this->ensureSpace($headerH);
        $this->drawHeaderRow($headers, $widths, $this->margin, $headerH, 8);
        $this->y -= $headerH;
    }

    protected function wrapText(string $text, float $maxWidth, float $fontSize): array
    {
        $text = trim($text);
        if ($text === '') {
            return [''];
        }
        $words = preg_split('/\s+/u', $text);
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $test = $current === '' ? $word : $current . ' ' . $word;
            if ($current === '' || $this->textWidth($test, $fontSize) <= $maxWidth) {
                $current = $test;
            } else {
                $lines[] = $current;
                $current = $word;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }
        return $lines;
    }

    protected function textWidth(string $text, float $fontSize): float
    {
        return mb_strlen($text) * $fontSize * 0.5;
    }

    protected function rect(float $x, float $y, float $w, float $h, array $rgb): void
    {
        $this->content .= sprintf(
            "%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f\n",
            $rgb[0], $rgb[1], $rgb[2], $x, $y, $w, $h
        );
    }

    protected function drawLine(float $x1, float $y1, float $x2, float $y2, array $rgb): void
    {
        $this->content .= sprintf(
            "%.3f %.3f %.3f RG %.2f w %.2f %.2f m %.2f %.2f l S\n",
            $rgb[0], $rgb[1], $rgb[2], 0.6, $x1, $y1, $x2, $y2
        );
    }

    protected function drawText(string $text, float $x, float $y, float $size, bool $bold, array $rgb): void
    {
        $font = $bold ? '/F2' : '/F1';
        $this->content .= sprintf(
            "%.3f %.3f %.3f rg BT %s %.1f Tf %.2f %.2f Td (%s) Tj ET\n",
            $rgb[0], $rgb[1], $rgb[2], $font, $size, $x, $y, $this->escape($text)
        );
    }

    protected function escape(string $text): string
    {
        $text = iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $text);
        if ($text === false) {
            $text = '';
        }
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    protected function output(): string
    {
        $this->finishPage();
        $pages = $this->pages;
        $numPages = count($pages);

        $objects = [];
        $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";       // 1
        $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";   // 2
        $objects[] = "<< /Type /Catalog /Pages 4 0 R >>";                             // 3

        $kids = [];
        for ($i = 0; $i < $numPages; $i++) {
            $kids[] = (5 + $i * 2) . ' 0 R';
        }
        $objects[] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count $numPages >>"; // 4

        for ($i = 0; $i < $numPages; $i++) {
            $pageObj = 5 + $i * 2;
            $contentObj = 6 + $i * 2;
            $objects[] = "<< /Type /Page /Parent 4 0 R /MediaBox [0 0 {$this->pageWidth} {$this->pageHeight}] "
                . "/Resources << /Font << /F1 1 0 R /F2 2 0 R >> >> /Contents $contentObj 0 R >>";
            $stream = $pages[$i];
            $objects[] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        }

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $idx => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($idx + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefStart = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 3 0 R >>\n";
        $pdf .= "startxref\n" . $xrefStart . "\n%%EOF";

        return $pdf;
    }
}
