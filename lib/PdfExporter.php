<?php

/*
 * PdfExporter — dependency-free, native PDF generator.
 *
 * WHY THIS EXISTS INSTEAD OF DOMPDF:
 * Dompdf could not be installed in the development sandbox this project
 * was built in — Composer's package registry (repo.packagist.org) is
 * blocked at the network level, and GitHub repository access for
 * dompdf/dompdf was not available either. Rather than hand you PDF code
 * that has never actually produced a PDF file, this class writes the PDF
 * file format directly (PDF 1.4 spec: objects, a cross-reference table,
 * a trailer, and content streams using the standard, non-embedded
 * Helvetica font). It has ZERO external dependencies — no Composer,
 * no vendor/ folder, nothing to install on Hostinger.
 *
 * -------------------------------------------------------------------
 * REDESIGNED (post-implementation audit — readability complaint):
 *
 * ROOT CAUSE of "text is extremely faint": the previous version's
 * header-row background rectangle was drawn with the PDF `rg` operator
 * (0.90 0.90 0.90 rg ... f) to fill it light gray. In the PDF content
 * stream model, `rg` sets the CURRENT NON-STROKING (fill) COLOR, and
 * that color stays in effect for every following operation — including
 * text, which is filled (not stroked) by default. Because the gray
 * `rg` was never reset back to black afterward, every single piece of
 * text drawn AFTER the first header-row rectangle on a page — every
 * column header, every data row, the footer — inherited that pale
 * gray fill color instead of black. That's why the whole table read as
 * faint: it was rendering in light gray, not black, by construction.
 * Fixed by explicitly emitting `0 0 0 rg` (opaque black) immediately
 * before every text-drawing operation, so no fill-color state can leak
 * from a preceding shape into text.
 *
 * ALSO REDESIGNED for the "12 columns squeezed" / "small font"
 * complaint:
 *   - Data font raised 8pt -> 9.5pt, header font raised 9pt -> 11pt bold.
 *   - Row height raised 16pt -> 20pt, header row height 20pt -> 28pt.
 *   - Cell left padding raised 3pt -> 6pt.
 *   - Page size is now chosen automatically: A4 landscape for tables
 *     with a modest column count, A3 landscape when there are enough
 *     columns (e.g. the 12-column evaluation table) that A4 would force
 *     columns below a readable minimum width. This directly implements
 *     "use A4 landscape if it remains genuinely readable; otherwise use
 *     A3 landscape."
 *   - Column width weights now support an optional per-column MINIMUM
 *     width in points ($columnMinWidths), so a column like "Student
 *     Name" is guaranteed real space instead of being squeezed down to
 *     whatever its relative weight alone would produce.
 *   - Rows per page is computed from real available vertical space at
 *     the new row height, then clamped to a 12–22 range so a page never
 *     ends up with either an unreadable wall of rows or an
 *     unnecessarily sparse one — landing in the requested ~18–22/page
 *     range on both A4 and A3 landscape for the evaluation table.
 * -------------------------------------------------------------------
 */

class PdfExporter
{
    // Landscape A4 in points (72pt = 1 inch)
    const A4_WIDTH = 841.89;
    const A4_HEIGHT = 595.28;

    // Landscape A3 in points
    const A3_WIDTH = 1190.55;
    const A3_HEIGHT = 841.89;

    const MARGIN = 36;

    const ROW_HEIGHT = 20;
    const HEADER_ROW_HEIGHT = 28;
    const FONT_SIZE_DATA = 9.5;
    const FONT_SIZE_HEADER = 11;
    const FONT_SIZE_TITLE = 16;
    const FONT_SIZE_SUBTITLE = 10;

    const MIN_ROWS_PER_PAGE = 12;
    const MAX_ROWS_PER_PAGE = 22;

    /**
     * @param string   $orgName        e.g. "MIT Art, Design and Technology University"
     * @param string   $title          e.g. "Student Internship Evaluation Sheet"
     * @param string   $subtitle       e.g. "Division: IT-DA   |   Generated: 12 Aug 2026, 3:45 PM"
     * @param string[] $headers        column header labels, in order
     * @param float[]  $columnWidths   relative widths (any positive numbers; auto-scaled to page width), same order/count as $headers
     * @param array[]  $rows           each row is an array of string values, same order/count as $headers
     * @param string   $filename       without extension; sanitized automatically
     * @param float[]  $columnMinWidths  OPTIONAL — minimum width in points per column, same order/count as
     *                                    $headers. Use this to guarantee real space for wide content (e.g.
     *                                    Student Name, Enrollment No) regardless of relative weight.
     */
    public static function streamEvaluationTable(
        string $orgName,
        string $title,
        string $subtitle,
        array $headers,
        array $columnWidths,
        array $rows,
        string $filename,
        array $columnMinWidths = []
    ): void {
        $pdfBytes = self::build($orgName, $title, $subtitle, $headers, $columnWidths, $rows, $columnMinWidths);

        $safeFilename = preg_replace('/[^A-Za-z0-9_\-]/', '_', $filename);

        if (!headers_sent()) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $safeFilename . '.pdf"');
            header('Content-Length: ' . strlen($pdfBytes));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
        }

        echo $pdfBytes;
    }

    /**
     * Returns the raw PDF bytes without sending headers or echoing —
     * useful for tests/verification.
     */
    public static function buildBytes(
        string $orgName,
        string $title,
        string $subtitle,
        array $headers,
        array $columnWidths,
        array $rows,
        array $columnMinWidths = []
    ): string {
        return self::build($orgName, $title, $subtitle, $headers, $columnWidths, $rows, $columnMinWidths);
    }

    /**
     * Decide A4 vs A3 landscape: start from A4; if the relative weights
     * would force ANY column below its minimum readable width (either
     * an explicit $columnMinWidths entry, or a sane per-column default
     * based on how many characters its header needs at the header
     * font size), switch to A3 landscape instead.
     */
    private static function choosePageSize(array $columnWidths, array $headers, array $columnMinWidths): array
    {
        $candidates = [
            [self::A4_WIDTH, self::A4_HEIGHT],
            [self::A3_WIDTH, self::A3_HEIGHT],
        ];

        foreach ($candidates as [$pw, $ph]) {
            $usableWidth = $pw - (2 * self::MARGIN);
            $totalWeight = array_sum($columnWidths) ?: 1;
            $fitsAll = true;

            foreach ($columnWidths as $i => $w) {
                $scaled = ($w / $totalWeight) * $usableWidth;
                $minRequired = $columnMinWidths[$i] ?? self::defaultMinWidth($headers[$i] ?? '');
                if ($scaled < $minRequired) {
                    $fitsAll = false;
                    break;
                }
            }

            if ($fitsAll) {
                return [$pw, $ph];
            }
        }

        // Neither fit perfectly (very many columns) — use A3, the larger option,
        // rather than silently falling back to an even-more-cramped A4.
        return [self::A3_WIDTH, self::A3_HEIGHT];
    }

    private static function defaultMinWidth(string $header): float
    {
        $h = strtolower($header);
        if (str_contains($h, 'name')) {
            return 130;
        }
        if (str_contains($h, 'enroll')) {
            return 100;
        }
        if (str_contains($h, 'division')) {
            return 60;
        }
        if (str_contains($h, 'roll')) {
            return 55;
        }
        if (str_contains($h, 'company') || str_contains($h, 'role') || str_contains($h, 'mentor') || str_contains($h, 'type')) {
            return 85;
        }
        // Short numeric-style headers (W1, Total, etc.)
        if (strlen($header) <= 14) {
            return 42;
        }
        return 65;
    }

    private static function build(
        string $orgName,
        string $title,
        string $subtitle,
        array $headers,
        array $columnWidths,
        array $rows,
        array $columnMinWidths
    ): string {
        [$pageWidth, $pageHeight] = self::choosePageSize($columnWidths, $headers, $columnMinWidths);

        $usableWidth = $pageWidth - (2 * self::MARGIN);
        $totalWeight = array_sum($columnWidths) ?: 1;
        $colWidths = array_map(function ($w) use ($usableWidth, $totalWeight) {
            return ($w / $totalWeight) * $usableWidth;
        }, $columnWidths);

        // Vertical budget per page for data rows
        $headerBlockHeight = 92; // org name + title + subtitle + spacing
        $tableHeaderHeight = self::HEADER_ROW_HEIGHT;
        $footerHeight = 30;
        $availableForRows = $pageHeight - (2 * self::MARGIN) - $headerBlockHeight - $tableHeaderHeight - $footerHeight;

        $rowsPerPage = (int) floor($availableForRows / self::ROW_HEIGHT);
        $rowsPerPage = max(self::MIN_ROWS_PER_PAGE, min(self::MAX_ROWS_PER_PAGE, $rowsPerPage));

        $pages = array_chunk($rows, $rowsPerPage);
        if (count($pages) === 0) {
            $pages = [[]];
        }
        $totalPages = count($pages);

        $contentStreams = [];
        foreach ($pages as $pageIndex => $pageRows) {
            $contentStreams[] = self::buildPageContent(
                $orgName,
                $title,
                $subtitle,
                $headers,
                $colWidths,
                $pageRows,
                $pageIndex + 1,
                $totalPages,
                $pageWidth,
                $pageHeight
            );
        }

        return self::assemblePdf($contentStreams, $pageWidth, $pageHeight);
    }

    private static function buildPageContent(
        string $orgName,
        string $title,
        string $subtitle,
        array $headers,
        array $colWidths,
        array $rows,
        int $pageNum,
        int $totalPages,
        float $pageWidth,
        float $pageHeight
    ): string {
        $x0 = self::MARGIN;
        $topY = $pageHeight - self::MARGIN;

        $ops = [];

        // ---- Header block (repeated every page) ----
        // Explicit black fill before every text draw — see file header
        // comment for why this matters (prevents color bleed from the
        // gray header-row rectangle fill further down).
        $ops[] = "0 0 0 rg";
        $ops[] = self::textOp($x0, $topY - 16, self::FONT_SIZE_TITLE + 1, self::pdfEscape($orgName), true);
        $ops[] = self::textOp($x0, $topY - 36, self::FONT_SIZE_TITLE, self::pdfEscape($title), true);
        $ops[] = self::textOp($x0, $topY - 54, self::FONT_SIZE_SUBTITLE, self::pdfEscape($subtitle), false);

        $tableTop = $topY - 74;

        // ---- Table header row ----
        $y = $tableTop;
        $x = $x0;
        $ops[] = self::rectOp($x0, $y - self::HEADER_ROW_HEIGHT, array_sum($colWidths), self::HEADER_ROW_HEIGHT, [0.85, 0.87, 0.95]);
        $ops[] = "0 0 0 rg"; // reset to black immediately after the fill — see file header comment
        foreach ($headers as $i => $h) {
            $w = $colWidths[$i];
            $ops[] = self::cellBorderOp($x, $y - self::HEADER_ROW_HEIGHT, $w, self::HEADER_ROW_HEIGHT, true);
            $ops[] = "0 0 0 rg";
            $ops[] = self::textOp($x + 6, $y - (self::HEADER_ROW_HEIGHT / 2) - 3, self::FONT_SIZE_HEADER, self::pdfEscape(self::truncateToWidth($h, $w, self::FONT_SIZE_HEADER, true)), true);
            $x += $w;
        }
        $y -= self::HEADER_ROW_HEIGHT;

        // ---- Data rows (alternating light band for readability) ----
        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex % 2 === 1) {
                $ops[] = self::rectOp($x0, $y - self::ROW_HEIGHT, array_sum($colWidths), self::ROW_HEIGHT, [0.96, 0.97, 0.99]);
            }
            $ops[] = "0 0 0 rg";

            $x = $x0;
            foreach ($row as $i => $val) {
                $w = $colWidths[$i] ?? 40;
                $ops[] = self::cellBorderOp($x, $y - self::ROW_HEIGHT, $w, self::ROW_HEIGHT, false);
                $ops[] = "0 0 0 rg";
                $display = self::truncateToWidth((string)$val, $w, self::FONT_SIZE_DATA, false);
                $ops[] = self::textOp($x + 6, $y - (self::ROW_HEIGHT / 2) - 3, self::FONT_SIZE_DATA, self::pdfEscape($display), false);
                $x += $w;
            }
            $y -= self::ROW_HEIGHT;
        }

        // ---- Footer ----
        $ops[] = "0 0 0 rg";
        $footerY = self::MARGIN - 6;
        $ops[] = self::textOp($x0, $footerY, 9, self::pdfEscape('Generated by MIT-ADT Internship Portal'), false);
        $pageLabel = "Page $pageNum of $totalPages";
        $labelWidth = self::estimateTextWidth($pageLabel, 9);
        $ops[] = self::textOp($pageWidth - self::MARGIN - $labelWidth, $footerY, 9, self::pdfEscape($pageLabel), true);

        return implode("\n", $ops);
    }

    private static function textOp(float $x, float $y, float $size, string $escapedText, bool $bold): string
    {
        $font = $bold ? '/F2' : '/F1';
        return sprintf("BT %s %.2f Tf %.2f %.2f Td (%s) Tj ET", $font, $size, $x, $y, $escapedText);
    }

    private static function rectOp(float $x, float $y, float $w, float $h, array $rgb): string
    {
        return sprintf("%.2f %.2f %.2f rg %.2f %.2f %.2f %.2f re f", $rgb[0], $rgb[1], $rgb[2], $x, $y, $w, $h);
    }

    private static function cellBorderOp(float $x, float $y, float $w, float $h, bool $strong): string
    {
        $lineWidth = $strong ? 0.9 : 0.6;
        return sprintf("0 0 0 RG %.1f w %.2f %.2f %.2f %.2f re S", $lineWidth, $x, $y, $w, $h);
    }

    private static function pdfEscape(string $text): string
    {
        // Standard Helvetica (WinAnsi-ish) — strip characters outside printable ASCII to avoid corrupting the stream
        $text = preg_replace('/[^\x20-\x7E]/', '', $text) ?? '';
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        return $text;
    }

    private static function estimateTextWidth(string $text, float $size): float
    {
        // Helvetica average glyph width heuristic (~0.5em regular, wider for bold) — good enough for right-aligning the footer page label
        return strlen($text) * $size * 0.55;
    }

    private static function truncateToWidth(string $text, float $widthPts, float $size, bool $bold = false): string
    {
        $emFactor = $bold ? 0.62 : 0.55;
        $maxChars = max(3, (int) floor(($widthPts - 10) / ($size * $emFactor)));
        if (strlen($text) <= $maxChars) {
            return $text;
        }
        // ASCII-only ellipsis ("...") — pdfEscape() strips non-ASCII characters
        // (the standard Helvetica font here isn't embedded with a full Unicode
        // map), so a Unicode "…" would silently vanish here instead of showing.
        return substr($text, 0, max(1, $maxChars - 3)) . '...';
    }

    private static function getLogoData(): ?array
    {
        $logoPath = __DIR__ . '/../assets/images/MIT_LOGO.jpg';
        if (!file_exists($logoPath)) {
            return null;
        }
        $info = @getimagesize($logoPath);
        if (!$info || $info[2] !== IMAGETYPE_JPEG) {
            return null;
        }
        $bytes = file_get_contents($logoPath);
        if ($bytes === false || strlen($bytes) === 0) {
            return null;
        }
        return [
            'width' => $info[0],
            'height' => $info[1],
            'bytes' => $bytes,
        ];
    }

    private static function assemblePdf(array $contentStreams, float $pageWidth, float $pageHeight, ?array $logoData = null): string
    {
        $objects = [];
        $pageCount = count($contentStreams);

        $hasLogo = ($logoData !== null);
        $pageObjIdsStart = $hasLogo ? 6 : 5;

        $pageObjectIds = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $pageObjectIds[] = $pageObjIdsStart + $i;
        }
        $contentObjIdsStart = $pageObjIdsStart + $pageCount;

        $kids = implode(' ', array_map(fn($id) => "$id 0 R", $pageObjectIds));

        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[2] = "<< /Type /Pages /Kids [$kids] /Count $pageCount >>";
        $objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
        $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";

        if ($hasLogo) {
            $objects[5] = sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream",
                $logoData['width'],
                $logoData['height'],
                strlen($logoData['bytes']),
                $logoData['bytes']
            );
        }

        $resourceXObj = $hasLogo ? " /XObject << /Im1 5 0 R >>" : "";

        foreach ($pageObjectIds as $i => $pageId) {
            $contentId = $contentObjIdsStart + $i;
            $objects[$pageId] = sprintf(
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>%s >> /Contents %d 0 R >>",
                $pageWidth,
                $pageHeight,
                $resourceXObj,
                $contentId
            );
        }

        foreach ($contentStreams as $i => $stream) {
            $contentId = $contentObjIdsStart + $i;
            $len = strlen($stream);
            $objects[$contentId] = "<< /Length $len >>\nstream\n" . $stream . "\nendstream";
        }

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$body\nendobj\n";
        }

        $xrefStart = strlen($pdf);
        $maxId = max(array_keys($objects));

        $pdf .= "xref\n0 " . ($maxId + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            if (isset($offsets[$id])) {
                $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
            } else {
                $pdf .= "0000000000 00000 f \n";
            }
        }

        $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n$xrefStart\n%%EOF";

        return $pdf;
    }

    /* =========================================================================
       ADDITIVE — Reports Module (admin/reports.php) support.
       streamEvaluationTable()/buildBytes() above are completely untouched, so
       the existing Student Evaluations and TY Evaluations PDF exports keep
       behaving exactly as before. This adds a report layout that also needs
       an "Applied Filters" block and a "Report Summary" block ahead of the
       table (Section 7 of the spec) — rendered once, on page 1 only; every
       page (including page 1) still repeats the university header and the
       table's own column-header row, and every page gets the same
       "Page X of Y" footer as the existing exports.
       ========================================================================= */

    /**
     * @param string   $orgName
     * @param string   $title
     * @param string   $subtitle       e.g. "Academic Year: 2026-27   |   Semester: SEM I"
     * @param string[] $filterLines    ["Label: Value", ...] rendered under "APPLIED FILTERS", page 1 only
     * @param string[] $summaryLines   ["Label: Value", ...] rendered under "SUMMARY", page 1 only
     * @param string[] $headers
     * @param float[]  $columnWidths
     * @param array[]  $rows
     * @param string   $filename
     * @param float[]  $columnMinWidths
     */
    public static function streamReportWithSummary(
        string $orgName,
        string $title,
        string $subtitle,
        array $filterLines,
        array $summaryLines,
        array $headers,
        array $columnWidths,
        array $rows,
        string $filename,
        array $columnMinWidths = []
    ): void {
        $pdfBytes = self::buildWithSummary($orgName, $title, $subtitle, $filterLines, $summaryLines, $headers, $columnWidths, $rows, $columnMinWidths);

        $safeFilename = preg_replace('/[^A-Za-z0-9_\-]/', '_', $filename);

        if (!headers_sent()) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $safeFilename . '.pdf"');
            header('Content-Length: ' . strlen($pdfBytes));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
        }

        echo $pdfBytes;
    }

    public static function buildBytesWithSummary(
        string $orgName,
        string $title,
        string $subtitle,
        array $filterLines,
        array $summaryLines,
        array $headers,
        array $columnWidths,
        array $rows,
        string $filename,
        array $columnMinWidths = []
    ): string {
        return self::buildWithSummary($orgName, $title, $subtitle, $filterLines, $summaryLines, $headers, $columnWidths, $rows, $columnMinWidths);
    }

    private static function buildWithSummary(
        string $orgName,
        string $title,
        string $subtitle,
        array $filterLines,
        array $summaryLines,
        array $headers,
        array $columnWidths,
        array $rows,
        array $columnMinWidths
    ): string {
        [$pageWidth, $pageHeight] = self::choosePageSize($columnWidths, $headers, $columnMinWidths);

        $usableWidth = $pageWidth - (2 * self::MARGIN);
        $totalWeight = array_sum($columnWidths) ?: 1;
        $colWidths = array_map(function ($w) use ($usableWidth, $totalWeight) {
            return ($w / $totalWeight) * $usableWidth;
        }, $columnWidths);

        $headerBlockHeight = 92;
        $tableHeaderHeight = self::HEADER_ROW_HEIGHT;
        $footerHeight = 30;

        // Page 1 loses extra vertical space to the Applied Filters + Summary
        // block; every later page gets the full row budget, same as the
        // existing (non-summary) report layout above.
        $extraLines = 0;
        if (!empty($filterLines)) $extraLines += count($filterLines) + 1;
        if (!empty($summaryLines)) $extraLines += count($summaryLines) + 1;
        if ($extraLines > 0) $extraLines += 1; // spacing between the two blocks / after them
        $extraBlockHeight = $extraLines * 13;

        $availableFirst = $pageHeight - (2 * self::MARGIN) - $headerBlockHeight - $extraBlockHeight - $tableHeaderHeight - $footerHeight;
        $availableRest  = $pageHeight - (2 * self::MARGIN) - $headerBlockHeight - $tableHeaderHeight - $footerHeight;

        $rowsPerPageFirst = max(4, min(self::MAX_ROWS_PER_PAGE, (int) floor($availableFirst / self::ROW_HEIGHT)));
        $rowsPerPageRest = max(self::MIN_ROWS_PER_PAGE, min(self::MAX_ROWS_PER_PAGE, (int) floor($availableRest / self::ROW_HEIGHT)));

        $pages = [];
        $remaining = $rows;
        $pages[] = array_splice($remaining, 0, $rowsPerPageFirst);
        while (!empty($remaining)) {
            $pages[] = array_splice($remaining, 0, $rowsPerPageRest);
        }
        if (empty($pages)) {
            $pages = [[]];
        }
        $totalPages = count($pages);

        $contentStreams = [];
        foreach ($pages as $pageIndex => $pageRows) {
            $contentStreams[] = self::buildPageContentWithExtra(
                $orgName,
                $title,
                $subtitle,
                $pageIndex === 0 ? $filterLines : [],
                $pageIndex === 0 ? $summaryLines : [],
                $headers,
                $colWidths,
                $pageRows,
                $pageIndex + 1,
                $totalPages,
                $pageWidth,
                $pageHeight
            );
        }

        return self::assemblePdf($contentStreams, $pageWidth, $pageHeight);
    }

    private static function buildPageContentWithExtra(
        string $orgName,
        string $title,
        string $subtitle,
        array $filterLines,
        array $summaryLines,
        array $headers,
        array $colWidths,
        array $rows,
        int $pageNum,
        int $totalPages,
        float $pageWidth,
        float $pageHeight
    ): string {
        $x0 = self::MARGIN;
        $topY = $pageHeight - self::MARGIN;

        $ops = [];

        $ops[] = "0 0 0 rg";
        $ops[] = self::textOp($x0, $topY - 16, self::FONT_SIZE_TITLE + 1, self::pdfEscape($orgName), true);
        $ops[] = self::textOp($x0, $topY - 36, self::FONT_SIZE_TITLE, self::pdfEscape($title), true);
        $ops[] = self::textOp($x0, $topY - 54, self::FONT_SIZE_SUBTITLE, self::pdfEscape($subtitle), false);

        $y = $topY - 74;

        if (!empty($filterLines)) {
            $ops[] = "0 0 0 rg";
            $ops[] = self::textOp($x0, $y, 10, self::pdfEscape('APPLIED FILTERS'), true);
            $y -= 14;
            foreach ($filterLines as $line) {
                $ops[] = self::textOp($x0, $y, 9, self::pdfEscape($line), false);
                $y -= 13;
            }
            $y -= 6;
        }

        if (!empty($summaryLines)) {
            $ops[] = "0 0 0 rg";
            $ops[] = self::textOp($x0, $y, 10, self::pdfEscape('SUMMARY'), true);
            $y -= 14;
            foreach ($summaryLines as $line) {
                $ops[] = self::textOp($x0, $y, 9, self::pdfEscape($line), false);
                $y -= 13;
            }
            $y -= 6;
        }

        $tableTop = $y;

        // ---- Table header row (identical drawing logic to buildPageContent()) ----
        $y = $tableTop;
        $x = $x0;
        $ops[] = self::rectOp($x0, $y - self::HEADER_ROW_HEIGHT, array_sum($colWidths), self::HEADER_ROW_HEIGHT, [0.85, 0.87, 0.95]);
        $ops[] = "0 0 0 rg";
        foreach ($headers as $i => $h) {
            $w = $colWidths[$i];
            $ops[] = self::cellBorderOp($x, $y - self::HEADER_ROW_HEIGHT, $w, self::HEADER_ROW_HEIGHT, true);
            $ops[] = "0 0 0 rg";
            $ops[] = self::textOp($x + 6, $y - (self::HEADER_ROW_HEIGHT / 2) - 3, self::FONT_SIZE_HEADER, self::pdfEscape(self::truncateToWidth($h, $w, self::FONT_SIZE_HEADER, true)), true);
            $x += $w;
        }
        $y -= self::HEADER_ROW_HEIGHT;

        // ---- Data rows ----
        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex % 2 === 1) {
                $ops[] = self::rectOp($x0, $y - self::ROW_HEIGHT, array_sum($colWidths), self::ROW_HEIGHT, [0.96, 0.97, 0.99]);
            }
            $ops[] = "0 0 0 rg";

            $x = $x0;
            foreach ($row as $i => $val) {
                $w = $colWidths[$i] ?? 40;
                $ops[] = self::cellBorderOp($x, $y - self::ROW_HEIGHT, $w, self::ROW_HEIGHT, false);
                $ops[] = "0 0 0 rg";
                $display = self::truncateToWidth((string)$val, $w, self::FONT_SIZE_DATA, false);
                $ops[] = self::textOp($x + 6, $y - (self::ROW_HEIGHT / 2) - 3, self::FONT_SIZE_DATA, self::pdfEscape($display), false);
                $x += $w;
            }
            $y -= self::ROW_HEIGHT;
        }

        // ---- Footer ----
        $ops[] = "0 0 0 rg";
        $footerY = self::MARGIN - 6;
        $ops[] = self::textOp($x0, $footerY, 9, self::pdfEscape('Generated by MIT-ADT Internship Portal'), false);
        $pageLabel = "Page $pageNum of $totalPages";
        $labelWidth = self::estimateTextWidth($pageLabel, 9);
        $ops[] = self::textOp($pageWidth - self::MARGIN - $labelWidth, $footerY, 9, self::pdfEscape($pageLabel), true);

        return implode("\n", $ops);
    }

    /**
     * Specialized Admin Export method:
     * - Header: Simple "MIT ADT UNIVERSITY, LONI." and "Third year Internship marks"
     * - Footer: Only Page number (Page X of Y)
     * - Grouping: Every new class/division starts on a new page
     * - Rows: ~35 students per page max
     */
    public static function streamAdminTYTable(
        string $orgName,
        string $title,
        array $headers,
        array $columnWidths,
        array $rows,
        string $filename,
        ?int $groupColumnIndex = 3,
        int $maxRowsPerPage = 35
    ): void {
        $pdfBytes = self::buildAdminTYTable($orgName, $title, $headers, $columnWidths, $rows, $groupColumnIndex, $maxRowsPerPage);

        $safeFilename = preg_replace('/[^A-Za-z0-9_\-]/', '_', $filename);

        if (!headers_sent()) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $safeFilename . '.pdf"');
            header('Content-Length: ' . strlen($pdfBytes));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
        }

        echo $pdfBytes;
    }

    private static function isColumnCentered(string $header): bool
    {
        $h = strtolower($header);
        if (str_contains($h, 'enrollment') || str_contains($h, 'name') || str_contains($h, 'specialization')) {
            return false;
        }
        return true;
    }

    public static function buildAdminTYTable(
        string $orgName,
        string $title,
        array $headers,
        array $columnWidths,
        array $rows,
        ?int $groupColumnIndex,
        int $maxRowsPerPage
    ): string {
        $pageWidth = self::A4_WIDTH;
        $pageHeight = self::A4_HEIGHT;
        $margin = 24.0;

        $logoData = self::getLogoData();

        $usableWidth = $pageWidth - (2 * $margin);
        $totalWeight = array_sum($columnWidths) ?: 1;
        $colWidths = array_map(function ($w) use ($usableWidth, $totalWeight) {
            return ($w / $totalWeight) * $usableWidth;
        }, $columnWidths);

        // Group rows into pages: 35 rows per page max, and force new page when class/group changes
        $pages = [];
        $currentPage = [];
        $currentGroup = null;

        foreach ($rows as $r) {
            $groupValue = ($groupColumnIndex !== null && isset($r[$groupColumnIndex])) ? $r[$groupColumnIndex] : null;

            // New class -> start a new page!
            if ($groupColumnIndex !== null && $currentGroup !== null && $groupValue !== $currentGroup && !empty($currentPage)) {
                $pages[] = $currentPage;
                $currentPage = [];
            }

            // Max rows (35) reached -> start a new page!
            if (count($currentPage) >= $maxRowsPerPage) {
                $pages[] = $currentPage;
                $currentPage = [];
            }

            $currentPage[] = $r;
            $currentGroup = $groupValue;
        }

        if (!empty($currentPage)) {
            $pages[] = $currentPage;
        }

        $totalPages = count($pages);
        $contentStreams = [];

        $rowHeight = 12.8;
        $headerRowHeight = 20.0;
        $dataFontSize = 8.2;
        $headerFontSize = 8.8;

        foreach ($pages as $pageIndex => $pageRows) {
            $x0 = $margin;
            $topY = $pageHeight - $margin;

            // Fetch dynamic class name for this specific page
            $pageClassName = ($groupColumnIndex !== null && isset($pageRows[0][$groupColumnIndex])) ? (string)$pageRows[0][$groupColumnIndex] : '';

            $ops = [];

            // 1. Draw Official MIT-ADT University Logo on Header Left
            if ($logoData !== null) {
                // Aspect ratio of logo (1500 x 785)
                $logoWidthPts = 115.0;
                $logoHeightPts = $logoWidthPts * ($logoData['height'] / $logoData['width']); // ~60.18 pt
                $logoY = $topY - $logoHeightPts - 2.0;
                $ops[] = sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /Im1 Do Q", $logoWidthPts, $logoHeightPts, $x0, $logoY);
            }

            // 2. Institutional Header Centered Text
            // Title Line 1: MIT ADT UNIVERSITY, PUNE
            $line1 = "MIT ADT UNIVERSITY, PUNE";
            $w1 = self::estimateTextWidth($line1, 13.5, true);
            $ops[] = "0.05 0.36 0.23 rg"; // Dark Green
            $ops[] = self::textOp(($pageWidth - $w1) / 2.0, $topY - 14, 13.5, self::pdfEscape($line1), true);

            // Title Line 2: MIT SCHOOL OF COMPUTING
            $line2 = "MIT SCHOOL OF COMPUTING";
            $w2 = self::estimateTextWidth($line2, 11.5, true);
            $ops[] = "0.1 0.1 0.1 rg"; // Dark Slate
            $ops[] = self::textOp(($pageWidth - $w2) / 2.0, $topY - 29, 11.5, self::pdfEscape($line2), true);

            // Title Line 3: SUMMER INTERNSHIP
            $line3 = "SUMMER INTERNSHIP";
            $w3 = self::estimateTextWidth($line3, 10.5, true);
            $ops[] = "0.15 0.15 0.15 rg";
            $ops[] = self::textOp(($pageWidth - $w3) / 2.0, $topY - 43, 10.5, self::pdfEscape($line3), true);

            // Subtitle Line 4: Class & Academic Metadata
            $classText = ($pageClassName !== '') ? "Class: " . $pageClassName : "All Classes";
            $line4 = $classText . "   |   Academic Year: 2026-27";
            $w4 = self::estimateTextWidth($line4, 9.0, false);
            $ops[] = "0.2 0.2 0.2 rg";
            $ops[] = self::textOp(($pageWidth - $w4) / 2.0, $topY - 56, 9.0, self::pdfEscape($line4), true);

            // Accent dividing line
            $ops[] = "0.05 0.36 0.23 RG 0.8 w";
            $ops[] = sprintf("%.2f %.2f m %.2f %.2f l S", $margin, $topY - 64, $pageWidth - $margin, $topY - 64);

            $tableTop = $topY - 72.0;

            // 3. Table Header Row (Dark Green Fill `#0D5C3A`, Opaque White Bold Text)
            $y = $tableTop;
            $x = $x0;
            // Background fill rectangle
            $ops[] = self::rectOp($x0, $y - $headerRowHeight, array_sum($colWidths), $headerRowHeight, [0.05, 0.36, 0.23]);

            foreach ($headers as $i => $h) {
                $w = $colWidths[$i];
                // Border around header cell
                $ops[] = "1 1 1 RG 0.5 w";
                $ops[] = sprintf("%.2f %.2f %.2f %.2f re S", $x, $y - $headerRowHeight, $w, $headerRowHeight);
                // White bold text
                $ops[] = "1 1 1 rg";
                $dispHeader = self::truncateToWidth($h, $w, $headerFontSize, true);
                $isCenter = self::isColumnCentered($h);
                if ($isCenter) {
                    $hw = self::estimateTextWidth($dispHeader, $headerFontSize, true);
                    $tx = $x + max(2.0, ($w - $hw) / 2.0);
                } else {
                    $tx = $x + 4.0;
                }
                $ty = $y - ($headerRowHeight / 2.0) - 2.8;
                $ops[] = self::textOp($tx, $ty, $headerFontSize, self::pdfEscape($dispHeader), true);
                $x += $w;
            }
            $y -= $headerRowHeight;

            // 4. Data Rows (Thin Crisp Gray Borders, Alternating Light Tint Fills)
            foreach ($pageRows as $rowIndex => $row) {
                if ($rowIndex % 2 === 1) {
                    // Soft subtle light green/gray tint
                    $ops[] = self::rectOp($x0, $y - $rowHeight, array_sum($colWidths), $rowHeight, [0.96, 0.98, 0.96]);
                }
                $ops[] = "0 0 0 rg";

                $x = $x0;
                foreach ($row as $i => $val) {
                    $w = $colWidths[$i] ?? 40.0;
                    $hName = $headers[$i] ?? '';
                    $isCenter = self::isColumnCentered($hName);

                    // Cell Border
                    $ops[] = "0.78 0.80 0.78 RG 0.4 w";
                    $ops[] = sprintf("%.2f %.2f %.2f %.2f re S", $x, $y - $rowHeight, $w, $rowHeight);

                    // Text
                    $ops[] = "0 0 0 rg";
                    $isTotalCol = (str_contains(strtolower($hName), 'total'));
                    $display = self::truncateToWidth((string)$val, $w, $dataFontSize, $isTotalCol);

                    if ($isCenter) {
                        $tw = self::estimateTextWidth($display, $dataFontSize, $isTotalCol);
                        $tx = $x + max(2.0, ($w - $tw) / 2.0);
                    } else {
                        $tx = $x + 4.0;
                    }
                    $ty = $y - ($rowHeight / 2.0) - 2.5;

                    $ops[] = self::textOp($tx, $ty, $dataFontSize, self::pdfEscape($display), $isTotalCol);
                    $x += $w;
                }
                $y -= $rowHeight;
            }

            // 5. Footer (Branding Left, Page X of Y Right)
            $footerY = $margin - 6.0;
            $ops[] = "0.3 0.3 0.3 rg";
            $ops[] = self::textOp($x0, $footerY, 8.5, self::pdfEscape("MIT ADT University - School of Computing"), false);

            $pageLabel = "Page " . ($pageIndex + 1) . " of $totalPages";
            $labelWidth = self::estimateTextWidth($pageLabel, 8.5, true);
            $ops[] = "0 0 0 rg";
            $ops[] = self::textOp($pageWidth - $margin - $labelWidth, $footerY, 8.5, self::pdfEscape($pageLabel), true);

            $contentStreams[] = implode("\n", $ops);
        }

        return self::assemblePdf($contentStreams, $pageWidth, $pageHeight, $logoData);
    }

}
