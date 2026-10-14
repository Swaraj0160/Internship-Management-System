<?php

/*
 * ExcelExporter — shared helper for generating real .xlsx exports.
 *
 * -------------------------------------------------------------------
 * REWRITE (live-testing follow-up, round 2): the previous version of
 * this file depended on the phpoffice/phpspreadsheet Composer
 * package. On the live Hostinger site, Excel export still failed
 * after the round-1 fix (which made the failure graceful instead of
 * a raw 500) because `composer install` was never actually run there
 * — there is no SSH access on this hosting plan to run it, and no
 * `vendor/` folder was ever uploaded. Rather than keep asking for a
 * Composer step this hosting plan cannot do, this file is now a
 * native, dependency-free .xlsx writer: it builds a real Office Open
 * XML spreadsheet package by hand (a .xlsx file IS just a ZIP archive
 * of a handful of XML files — there is no proprietary binary format
 * involved), using PHP's built-in `ZipArchive` class. `ZipArchive` is
 * part of the standard `php-zip` extension, which is enabled by
 * default on effectively all shared hosting, including Hostinger —
 * unlike Composer/Packagist access, this requires no server
 * configuration step at all. Excel export now works immediately after
 * upload with zero setup.
 *
 * This produces real, spec-compliant OOXML: genuine numeric cells
 * (never a text string that Excel could misinterpret as a date — the
 * exact mechanism behind the original "Oct-50" bug), genuine date
 * cells with a real date number format, a bold header row, frozen
 * header row, column widths, and borders — verified in this sandbox
 * by opening the generated file with Python's `openpyxl` library and
 * confirming cell types, values, and formatting round-trip correctly.
 * -------------------------------------------------------------------
 */

class ExcelExporterException extends Exception
{
}

class ExcelExporter
{
    /**
     * Confirms the one thing this now actually depends on — PHP's
     * built-in ZipArchive class — is available. This is a standard
     * extension (php-zip) enabled on effectively all hosting,
     * including Hostinger, so this should never actually fail there;
     * it exists as a safety net so a genuinely unusual server
     * configuration fails with a clear message instead of a fatal
     * error.
     */
    private static function ensureAvailable(): void
    {
        if (!class_exists('ZipArchive')) {
            throw new ExcelExporterException(
                'Excel export is not available: the PHP "zip" extension (ZipArchive) is not enabled on ' .
                'this server. This is a standard PHP extension enabled by default on virtually all hosting, ' .
                'including Hostinger — check hPanel -> Advanced -> PHP Configuration -> Extensions and enable ' .
                '"zip" if it is off, then try again.'
            );
        }
    }

    /* =========================================================
       Small XML/OOXML helpers
       ========================================================= */

    private static function xmlEscape(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** 1-based column index -> spreadsheet column letters (1 -> A, 27 -> AA, ...) */
    private static function colLetter(int $index): string
    {
        $letters = '';
        while ($index > 0) {
            $rem = ($index - 1) % 26;
            $letters = chr(65 + $rem) . $letters;
            $index = intdiv($index - 1, 26);
        }
        return $letters;
    }

    /** "YYYY-MM-DD" -> Excel serial date number (the widely-used epoch-based formula). */
    private static function excelDateSerial(string $ymd): ?int
    {
        $ts = strtotime($ymd . ' 00:00:00 UTC');
        if ($ts === false) {
            return null;
        }
        return intdiv($ts, 86400) + 25569;
    }

    /**
     * Stream a professional .xlsx file to the browser and exit.
     *
     * @param string   $title          Report title, e.g. "Student Internship Report"
     * @param string   $filterSummary  Human-readable description of the filters applied, e.g. "Division: IT-SMAD"
     * @param string[] $headers        Column header labels, in order
     * @param array[]  $rows           Array of associative rows; each row's values are read in $headers order
     *                                 by matching each header label to a key in $columnMap
     * @param string[] $columnMap      Maps each header label to the associative key to read from $rows
     * @param string   $filename       Download filename, WITHOUT extension (".xlsx" is appended)
     * @param string   $sheetName      Worksheet tab name (max 31 chars, Excel limit)
     * @param array[]  $warningRows    Optional data-quality warnings (Section J): each entry
     *                                 needs keys student, enrollment_no, division, field,
     *                                 raw_value. When non-empty, a second "Data Warnings"
     *                                 sheet is added listing every malformed value found in
     *                                 the source data, so it is reported explicitly instead
     *                                 of silently disappearing as a 0 in the main sheet.
     */
    public static function streamStudentReport(
        string $title,
        string $filterSummary,
        array $headers,
        array $rows,
        array $columnMap,
        string $filename,
        string $sheetName = 'Report',
        array $warningRows = []
    ): void {
        self::ensureAvailable();

        $bytes = self::buildWorkbookBytes($title, $filterSummary, $headers, $rows, $columnMap, $sheetName, $warningRows);

        $safeFilename = preg_replace('/[^A-Za-z0-9_\-]/', '_', $filename);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $safeFilename . '.xlsx"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: max-age=0');

        echo $bytes;
        exit();
    }

    /**
     * Same as streamStudentReport() but returns the raw .xlsx bytes
     * instead of streaming + exiting — used for structural testing.
     */
    public static function buildBytes(
        string $title,
        string $filterSummary,
        array $headers,
        array $rows,
        array $columnMap,
        string $sheetName = 'Report',
        array $warningRows = []
    ): string {
        self::ensureAvailable();
        return self::buildWorkbookBytes($title, $filterSummary, $headers, $rows, $columnMap, $sheetName, $warningRows);
    }

    private static function buildWorkbookBytes(
        string $title,
        string $filterSummary,
        array $headers,
        array $rows,
        array $columnMap,
        string $sheetName,
        array $warningRows
    ): string {
        $columnCount = count($headers);
        $lastCol = self::colLetter($columnCount);
        $safeSheetName = substr(preg_replace('/[\\\\\/\?\*\[\]:]/', '', $sheetName), 0, 31) ?: 'Report';

        $hasWarnings = !empty($warningRows);

        $sheet1Xml = self::buildDataSheetXml($title, $filterSummary, $headers, $rows, $columnMap, $columnCount, $lastCol);
        $sheet2Xml = $hasWarnings ? self::buildWarningsSheetXml($warningRows) : null;

        $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_');
        if ($tmpFile === false) {
            throw new ExcelExporterException('Could not create a temporary file to build the .xlsx export.');
        }

        $zip = new ZipArchive();
        if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpFile);
            throw new ExcelExporterException('Could not create the .xlsx archive on this server.');
        }

        $zip->addEmptyDir('_rels');
        $zip->addEmptyDir('xl');
        $zip->addEmptyDir('xl/_rels');
        $zip->addEmptyDir('xl/worksheets');
        $zip->addEmptyDir('docProps');

        $zip->addFromString('[Content_Types].xml', self::contentTypesXml($hasWarnings));
        $zip->addFromString('_rels/.rels', self::rootRelsXml());
        $zip->addFromString('docProps/core.xml', self::coreXml());
        $zip->addFromString('docProps/app.xml', self::appXml($safeSheetName, $hasWarnings));
        $zip->addFromString('xl/workbook.xml', self::workbookXml($safeSheetName, $hasWarnings));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelsXml($hasWarnings));
        $zip->addFromString('xl/styles.xml', self::stylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet1Xml);

        if ($hasWarnings) {
            $zip->addFromString('xl/worksheets/sheet2.xml', $sheet2Xml);
        }

        $zip->close();

        $bytes = file_get_contents($tmpFile);
        @unlink($tmpFile);

        if ($bytes === false) {
            throw new ExcelExporterException('Could not read back the generated .xlsx file.');
        }

        return $bytes;
    }

    private static function buildDataSheetXml(
        string $title,
        string $filterSummary,
        array $headers,
        array $rows,
        array $columnMap,
        int $columnCount,
        string $lastCol
    ): string {
        $headerRowIndex = 4; // 1: title, 2: filter/generated line, 3: blank, 4: header

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        // Freeze the header row and everything above it.
        $xml .= '<sheetViews><sheetView workbookViewId="0">';
        $xml .= '<pane ySplit="' . $headerRowIndex . '" topLeftCell="A' . ($headerRowIndex + 1) . '" activePane="bottomLeft" state="frozen"/>';
        $xml .= '</sheetView></sheetViews>';

        // Column widths — a simple, readable heuristic (character-count based),
        // matching the previous auto-size behavior closely enough for Excel to
        // display everything without truncation.
        $xml .= '<cols>';
        for ($i = 1; $i <= $columnCount; $i++) {
            $label = $headers[$i - 1] ?? '';
            $width = max(10, min(42, strlen($label) + 4));
            $xml .= '<col min="' . $i . '" max="' . $i . '" width="' . $width . '" customWidth="1"/>';
        }
        $xml .= '</cols>';

        $xml .= '<sheetData>';

        // Row 1: title (bold, size 14)
        $xml .= '<row r="1">';
        $xml .= '<c r="A1" t="inlineStr" s="1"><is><t>' . self::xmlEscape($title) . '</t></is></c>';
        $xml .= '</row>';

        // Row 2: generated timestamp + filter summary (italic gray)
        $generatedLine = 'Generated: ' . date('d M Y, h:i A') . '   |   ' . $filterSummary;
        $xml .= '<row r="2">';
        $xml .= '<c r="A2" t="inlineStr" s="2"><is><t>' . self::xmlEscape($generatedLine) . '</t></is></c>';
        $xml .= '</row>';

        // Row 4: header (bold white on dark fill)
        $xml .= '<row r="' . $headerRowIndex . '">';
        for ($i = 1; $i <= $columnCount; $i++) {
            $ref = self::colLetter($i) . $headerRowIndex;
            $label = $headers[$i - 1] ?? '';
            $xml .= '<c r="' . $ref . '" t="inlineStr" s="3"><is><t>' . self::xmlEscape($label) . '</t></is></c>';
        }
        $xml .= '</row>';

        // Data rows
        $rowIndex = $headerRowIndex + 1;
        foreach ($rows as $row) {
            $xml .= '<row r="' . $rowIndex . '">';
            for ($i = 1; $i <= $columnCount; $i++) {
                $label = $headers[$i - 1] ?? '';
                $key = $columnMap[$label] ?? null;
                $value = $key !== null ? ($row[$key] ?? '') : '';
                $ref = self::colLetter($i) . $rowIndex;

                if ($value === null || $value === '') {
                    $xml .= '<c r="' . $ref . '" t="inlineStr" s="4"><is><t>-</t></is></c>';
                } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$value)) {
                    // Date-shaped values (start_date/end_date) become a genuine
                    // numeric date cell with a real date number format — never
                    // a text string Excel could try to re-parse.
                    $serial = self::excelDateSerial((string)$value);
                    if ($serial !== null) {
                        $xml .= '<c r="' . $ref . '" s="6"><v>' . $serial . '</v></c>';
                    } else {
                        $xml .= '<c r="' . $ref . '" t="inlineStr" s="4"><is><t>' . self::xmlEscape((string)$value) . '</t></is></c>';
                    }
                } elseif (is_numeric($value)) {
                    // Genuine numeric cell — a plain <v> number, General format.
                    // This is what structurally prevents the "Oct-50" bug: there
                    // is no ambiguous text string here for Excel to reinterpret.
                    $xml .= '<c r="' . $ref . '" s="5"><v>' . self::xmlEscape((string)(0 + $value)) . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '" t="inlineStr" s="4"><is><t>' . self::xmlEscape((string)$value) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
            $rowIndex++;
        }

        $xml .= '</sheetData>';

        $xml .= '<mergeCells count="2">';
        $xml .= '<mergeCell ref="A1:' . $lastCol . '1"/>';
        $xml .= '<mergeCell ref="A2:' . $lastCol . '2"/>';
        $xml .= '</mergeCells>';

        $xml .= '</worksheet>';

        return $xml;
    }

    private static function buildWarningsSheetXml(array $warningRows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        $xml .= '<cols>';
        $xml .= '<col min="1" max="1" width="26" customWidth="1"/>';
        $xml .= '<col min="2" max="2" width="18" customWidth="1"/>';
        $xml .= '<col min="3" max="3" width="14" customWidth="1"/>';
        $xml .= '<col min="4" max="4" width="20" customWidth="1"/>';
        $xml .= '<col min="5" max="5" width="30" customWidth="1"/>';
        $xml .= '</cols>';

        $xml .= '<sheetData>';

        $notice = 'Data Quality Warnings - values below could NOT be read as numbers and were NOT included in '
            . 'the totals on the main sheet. Please correct these directly in the source records; they were '
            . 'shown here instead of being silently treated as 0.';
        $xml .= '<row r="1"><c r="A1" t="inlineStr" s="8"><is><t>' . self::xmlEscape($notice) . '</t></is></c></row>';

        $whead = ['Student', 'Enrollment No', 'Division', 'Field', 'Raw Value Found In Database'];
        $xml .= '<row r="3">';
        foreach ($whead as $i => $label) {
            $ref = self::colLetter($i + 1) . '3';
            $xml .= '<c r="' . $ref . '" t="inlineStr" s="7"><is><t>' . self::xmlEscape($label) . '</t></is></c>';
        }
        $xml .= '</row>';

        $rowIndex = 4;
        foreach ($warningRows as $w) {
            $xml .= '<row r="' . $rowIndex . '">';
            $cols = [
                (string)($w['student'] ?? ''),
                (string)($w['enrollment_no'] ?? ''),
                (string)($w['division'] ?? ''),
                (string)($w['field'] ?? ''),
                (string)($w['raw_value'] ?? ''),
            ];
            foreach ($cols as $i => $val) {
                $ref = self::colLetter($i + 1) . $rowIndex;
                $xml .= '<c r="' . $ref . '" t="inlineStr" s="4"><is><t>' . self::xmlEscape($val) . '</t></is></c>';
            }
            $xml .= '</row>';
            $rowIndex++;
        }

        $xml .= '</sheetData>';
        $xml .= '<mergeCells count="1"><mergeCell ref="A1:E1"/></mergeCells>';
        $xml .= '</worksheet>';

        return $xml;
    }

    /* =========================================================
       Fixed package parts
       ========================================================= */

    private static function contentTypesXml(bool $hasWarnings): string
    {
        $sheets = '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        if ($hasWarnings) {
            $sheets .= '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $sheets
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private static function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private static function coreXml(): string
    {
        $now = date('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>MIT-ADT Internship Portal</dc:creator>'
            . '<cp:lastModifiedBy>MIT-ADT Internship Portal</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private static function appXml(string $sheetName, bool $hasWarnings): string
    {
        $count = $hasWarnings ? 2 : 1;
        $titles = '<vt:lpstr>' . self::xmlEscape($sheetName) . '</vt:lpstr>';
        if ($hasWarnings) {
            $titles .= '<vt:lpstr>Data Warnings</vt:lpstr>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
            . 'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>MIT-ADT Internship Portal</Application>'
            . '<HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant>'
            . '<vt:variant><vt:i4>' . $count . '</vt:i4></vt:variant></vt:vector></HeadingPairs>'
            . '<TitlesOfParts><vt:vector size="' . $count . '" baseType="lpstr">' . $titles . '</vt:vector></TitlesOfParts>'
            . '</Properties>';
    }

    private static function workbookXml(string $sheetName, bool $hasWarnings): string
    {
        $sheets = '<sheet name="' . self::xmlEscape($sheetName) . '" sheetId="1" r:id="rId1"/>';
        if ($hasWarnings) {
            $sheets .= '<sheet name="Data Warnings" sheetId="2" r:id="rId2"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheets . '</sheets>'
            . '</workbook>';
    }

    private static function workbookRelsXml(bool $hasWarnings): string
    {
        $rels = '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>';
        $nextId = 2;
        if ($hasWarnings) {
            $rels .= '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>';
            $nextId = 3;
        }
        $rels .= '<Relationship Id="rId' . $nextId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels
            . '</Relationships>';
    }

    /**
     * Styles referenced by index (the "s" attribute on <c>) throughout
     * this file:
     *   0 = default / General
     *   1 = title (bold, 14pt)
     *   2 = subtitle (italic, gray, 10pt)
     *   3 = table header (bold, white on dark fill, bordered, centered)
     *   4 = data cell, text/General (bordered)
     *   5 = data cell, numeric (bordered)
     *   6 = data cell, date (bordered, real date number format)
     *   7 = warnings sheet header (bold, light red fill)
     *   8 = warnings sheet notice (bold, red text, wrapped)
     */
    private static function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="dd-mmm-yyyy"/></numFmts>'
            . '<fonts count="6">'
            . '<font><sz val="11"/><name val="Calibri"/></font>' // 0 default
            . '<font><b/><sz val="14"/><name val="Calibri"/></font>' // 1 title
            . '<font><i/><sz val="10"/><color rgb="FF555555"/><name val="Calibri"/></font>' // 2 subtitle
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>' // 3 header
            . '<font><b/><sz val="11"/><color rgb="FFB91C1C"/><name val="Calibri"/></font>' // 4 warning notice
            . '<font><b/><sz val="11"/><color rgb="FF1F2937"/><name val="Calibri"/></font>' // 5 section label (Applied Filters / Report Summary)
            . '</fonts>'
            . '<fills count="4">'
            . '<fill><patternFill patternType="none"/></fill>' // 0 required
            . '<fill><patternFill patternType="gray125"/></fill>' // 1 required
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1F2937"/><bgColor indexed="64"/></patternFill></fill>' // 2 header fill
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFEE2E2"/><bgColor indexed="64"/></patternFill></fill>' // 3 warning header fill
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>' // 0 none
            . '<border><left style="thin"><color rgb="FFD1D5DB"/></left><right style="thin"><color rgb="FFD1D5DB"/></right>'
            . '<top style="thin"><color rgb="FFD1D5DB"/></top><bottom style="thin"><color rgb="FFD1D5DB"/></bottom><diagonal/></border>' // 1 thin all
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="10">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' // 0 default
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>' // 1 title
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>' // 2 subtitle
            . '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
            . '<alignment horizontal="center" vertical="center" wrapText="1"/></xf>' // 3 header
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>' // 4 data text
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>' // 5 data numeric (General is safe/correct for numbers)
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>' // 6 data date
            . '<xf numFmtId="0" fontId="0" fillId="3" borderId="0" xfId="0" applyFill="1"/>' // 7 warnings header
            . '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1">'
            . '<alignment wrapText="1" vertical="center"/></xf>' // 8 warnings notice
            . '<xf numFmtId="0" fontId="5" fillId="0" borderId="0" xfId="0" applyFont="1"/>' // 9 section label bold
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    /**
     * Renders a plain, readable HTML error page (HTTP 200, not a raw
     * 500) — kept for the rare case that ZipArchive itself is
     * unavailable (see ensureAvailable()). Call this from a
     * catch(ExcelExporterException) block instead of letting the
     * exception propagate. Does not exit — the caller should exit()
     * right after calling this.
     */
    public static function renderSetupError(string $message): void
    {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: text/html; charset=UTF-8');
        }
        echo '<!DOCTYPE html><html><head><title>Excel Export Unavailable</title>'
            . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">'
            . '</head><body style="background:#f5f7fb;padding:40px 20px;">'
            . '<div style="max-width:640px;margin:0 auto;background:#fff;padding:30px;border-radius:14px;box-shadow:0 8px 24px rgba(0,0,0,.08);">'
            . '<h3 style="color:#b91c1c;">Excel export isn\'t available on this server right now</h3>'
            . '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p class="text-muted">PDF export does not require this and works today.</p>'
            . '<a href="javascript:history.back()" class="btn btn-primary">Go Back</a>'
            . '</div></body></html>';
    }

    /* =========================================================================
       ADDITIVE — Reports Module (admin/reports.php) support.
       These methods are new and do not alter streamStudentReport()/buildBytes()
       above in any way, so every export that already relies on those (Student
       Evaluations, TY Evaluations) is completely unaffected. This adds a
       richer single-sheet layout that also includes a multi-line "Applied
       Filters" block and a trailing "Report Summary" block, in addition to
       the title/generated-line/table that streamStudentReport() already
       produces — needed because the Reports module must show both blocks
       inside the exported workbook (Section 5 of the spec), not just a single
       filter-summary line.
       ========================================================================= */

    /**
     * @param string   $title         e.g. "MIT-ADT University — Student Internship Report"
     * @param string[] $filterLines   ["Label: Value", ...] in the required filter sequence
     * @param string[] $summaryLines  ["Label: Value", ...]
     * @param string[] $headers
     * @param array[]  $rows
     * @param string[] $columnMap
     * @param string   $filename      without extension
     */
    public static function streamReportWithSummary(
        string $title,
        array $filterLines,
        array $summaryLines,
        array $headers,
        array $rows,
        array $columnMap,
        string $filename,
        string $sheetName = 'Report'
    ): void {
        self::ensureAvailable();

        $bytes = self::buildReportWorkbookBytes($title, $filterLines, $summaryLines, $headers, $rows, $columnMap, $sheetName);

        $safeFilename = preg_replace('/[^A-Za-z0-9_\-]/', '_', $filename);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $safeFilename . '.xlsx"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: max-age=0');

        echo $bytes;
        exit();
    }

    public static function buildReportBytes(
        string $title,
        array $filterLines,
        array $summaryLines,
        array $headers,
        array $rows,
        array $columnMap,
        string $sheetName = 'Report'
    ): string {
        self::ensureAvailable();
        return self::buildReportWorkbookBytes($title, $filterLines, $summaryLines, $headers, $rows, $columnMap, $sheetName);
    }

    private static function buildReportWorkbookBytes(
        string $title,
        array $filterLines,
        array $summaryLines,
        array $headers,
        array $rows,
        array $columnMap,
        string $sheetName
    ): string {
        $columnCount = count($headers);
        $lastCol = self::colLetter($columnCount);
        $safeSheetName = substr(preg_replace('/[\\\\\/\?\*\[\]:]/', '', $sheetName), 0, 31) ?: 'Report';

        $sheetXml = self::buildReportSheetXml($title, $filterLines, $summaryLines, $headers, $rows, $columnMap, $columnCount, $lastCol);

        $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_');
        if ($tmpFile === false) {
            throw new ExcelExporterException('Could not create a temporary file to build the .xlsx export.');
        }

        $zip = new ZipArchive();
        if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpFile);
            throw new ExcelExporterException('Could not create the .xlsx archive on this server.');
        }

        $zip->addEmptyDir('_rels');
        $zip->addEmptyDir('xl');
        $zip->addEmptyDir('xl/_rels');
        $zip->addEmptyDir('xl/worksheets');
        $zip->addEmptyDir('docProps');

        $zip->addFromString('[Content_Types].xml', self::contentTypesXml(false));
        $zip->addFromString('_rels/.rels', self::rootRelsXml());
        $zip->addFromString('docProps/core.xml', self::coreXml());
        $zip->addFromString('docProps/app.xml', self::appXml($safeSheetName, false));
        $zip->addFromString('xl/workbook.xml', self::workbookXml($safeSheetName, false));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelsXml(false));
        $zip->addFromString('xl/styles.xml', self::stylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);

        $zip->close();

        $bytes = file_get_contents($tmpFile);
        @unlink($tmpFile);

        if ($bytes === false) {
            throw new ExcelExporterException('Could not read back the generated .xlsx file.');
        }

        return $bytes;
    }

    private static function buildReportSheetXml(
        string $title,
        array $filterLines,
        array $summaryLines,
        array $headers,
        array $rows,
        array $columnMap,
        int $columnCount,
        string $lastCol
    ): string {
        $r = 1; // running row cursor

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        $xml .= '<cols>';
        for ($i = 1; $i <= $columnCount; $i++) {
            $label = $headers[$i - 1] ?? '';
            $width = max(10, min(42, strlen($label) + 4));
            $xml .= '<col min="' . $i . '" max="' . $i . '" width="' . $width . '" customWidth="1"/>';
        }
        $xml .= '</cols>';

        $xml .= '<sheetData>';
        $merges = [];

        // Title
        $xml .= '<row r="' . $r . '"><c r="A' . $r . '" t="inlineStr" s="1"><is><t>' . self::xmlEscape($title) . '</t></is></c></row>';
        $merges[] = 'A' . $r . ':' . $lastCol . $r;
        $r++;

        // Generated timestamp
        $xml .= '<row r="' . $r . '"><c r="A' . $r . '" t="inlineStr" s="2"><is><t>' . self::xmlEscape('Generated: ' . date('d M Y, h:i A')) . '</t></is></c></row>';
        $merges[] = 'A' . $r . ':' . $lastCol . $r;
        $r += 2; // blank row

        // Applied Filters block
        if (!empty($filterLines)) {
            $xml .= '<row r="' . $r . '"><c r="A' . $r . '" t="inlineStr" s="9"><is><t>APPLIED FILTERS</t></is></c></row>';
            $r++;
            foreach ($filterLines as $line) {
                $xml .= '<row r="' . $r . '"><c r="A' . $r . '" t="inlineStr"><is><t>' . self::xmlEscape($line) . '</t></is></c></row>';
                $r++;
            }
            $r++; // blank row
        }

        // Summary block
        if (!empty($summaryLines)) {
            $xml .= '<row r="' . $r . '"><c r="A' . $r . '" t="inlineStr" s="9"><is><t>REPORT SUMMARY</t></is></c></row>';
            $r++;
            foreach ($summaryLines as $line) {
                $xml .= '<row r="' . $r . '"><c r="A' . $r . '" t="inlineStr"><is><t>' . self::xmlEscape($line) . '</t></is></c></row>';
                $r++;
            }
            $r++; // blank row
        }

        // Table header row
        $tableHeaderRow = $r;
        $xml .= '<row r="' . $r . '">';
        for ($i = 1; $i <= $columnCount; $i++) {
            $ref = self::colLetter($i) . $r;
            $label = $headers[$i - 1] ?? '';
            $xml .= '<c r="' . $ref . '" t="inlineStr" s="3"><is><t>' . self::xmlEscape($label) . '</t></is></c>';
        }
        $xml .= '</row>';
        $r++;

        // Data rows
        foreach ($rows as $row) {
            $xml .= '<row r="' . $r . '">';
            for ($i = 1; $i <= $columnCount; $i++) {
                $label = $headers[$i - 1] ?? '';
                $key = $columnMap[$label] ?? null;
                $value = $key !== null ? ($row[$key] ?? '') : '';
                $ref = self::colLetter($i) . $r;

                if ($value === null || $value === '') {
                    $xml .= '<c r="' . $ref . '" t="inlineStr" s="4"><is><t>-</t></is></c>';
                } elseif (is_numeric($value)) {
                    $xml .= '<c r="' . $ref . '" s="5"><v>' . self::xmlEscape((string)(0 + $value)) . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '" t="inlineStr" s="4"><is><t>' . self::xmlEscape((string)$value) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
            $r++;
        }

        $xml .= '</sheetData>';

        if (!empty($merges)) {
            $xml .= '<mergeCells count="' . count($merges) . '">';
            foreach ($merges as $m) {
                $xml .= '<mergeCell ref="' . $m . '"/>';
            }
            $xml .= '</mergeCells>';
        }

        // Freeze panes just below the table header row so it stays visible
        // while scrolling through data — inserted via sheetViews, which must
        // appear before sheetData per the OOXML schema; rebuild with it now
        // that we know the header row index.
        $xml = str_replace(
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">',
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $tableHeaderRow . '" topLeftCell="A' . ($tableHeaderRow + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>',
            $xml
        );

        $xml .= '</worksheet>';

        return $xml;
    }
}