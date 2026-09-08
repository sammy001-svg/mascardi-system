<?php
/**
 * A small .xlsx writer — no library, because there is no composer vendor
 * directory on this install.
 *
 * The report used to go out as SpreadsheetML with a .xls extension. It carried
 * the formatting, but every modern Excel opens it with "the file format and
 * extension don't match — the file could be corrupted or unsafe", and Google
 * Sheets and most phone viewers refuse it outright. People learn to click
 * through the warning, which is exactly the habit you do not want to teach.
 *
 * A real .xlsx is a zip of XML parts. That is a couple of hundred lines to write
 * once and it opens silently everywhere, so this writes one.
 *
 * Deliberately narrow: text and numbers, named cell styles, column widths, a
 * frozen top row and an autofilter. That is what a report needs. It is not a
 * spreadsheet library and should not grow into one.
 */

if (!function_exists('xlsxText')) {

/** A text cell. */
function xlsxText(?string $v, string $style = ''): array
{
    return ['t' => 's', 'v' => (string)$v, 's' => $style];
}

/** A number cell — written as a number so the column can be summed. */
function xlsxNumber(float $v, string $style = ''): array
{
    return ['t' => 'n', 'v' => $v, 's' => $style];
}

/** A cell that spans columns to its right. */
function xlsxMerge(?string $v, int $across, string $style = ''): array
{
    return ['t' => 's', 'v' => (string)$v, 's' => $style, 'across' => max(0, $across)];
}

/** XML text, with the control characters Excel refuses stripped out. */
function xlsxEsc(string $s): string
{
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? $s;
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** A1, B1 … Z1, AA1 — the column letters a cell reference needs. */
function xlsxCol(int $i): string
{
    $s = '';
    for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) {
        $s = chr(65 + (($n - 1) % 26)) . $s;
    }
    return $s;
}

/**
 * Turn the declarative style list into styles.xml, and hand back the name→index
 * map the cells refer to.
 *
 * Each style is ['fill'=>'RRGGBB', 'color'=>'RRGGBB', 'bold'=>bool,
 * 'italic'=>bool, 'align'=>'left|center|right', 'wrap'=>bool, 'fmt'=>'#,##0',
 * 'top'=>'RRGGBB'] and every part is optional.
 */
function xlsxStyles(array $defs): array
{
    // Index 0 of each table is the default, and fills 0 and 1 are reserved by
    // the format itself — an xlsx whose first fill is not "none" opens wrong.
    $fonts  = ['<font><sz val="10"/><name val="Calibri"/><color rgb="FF1F2937"/></font>'];
    $fills  = ['<fill><patternFill patternType="none"/></fill>',
               '<fill><patternFill patternType="gray125"/></fill>'];
    $borders = ['<border><left/><right/><top/><bottom/><diagonal/></border>',
                '<border><left/>'
                . '<right style="thin"><color rgb="FFE2E8F0"/></right><top/>'
                . '<bottom style="thin"><color rgb="FFE2E8F0"/></bottom><diagonal/></border>'];
    $numFmts = [];
    $xfs     = ['<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'];
    $map     = ['' => 0];

    foreach ($defs as $name => $d) {
        $fontId = 0;
        if (!empty($d['color']) || !empty($d['bold']) || !empty($d['italic'])) {
            $f = '<font><sz val="10"/><name val="Calibri"/>'
               . (!empty($d['bold'])   ? '<b/>' : '')
               . (!empty($d['italic']) ? '<i/>' : '')
               . '<color rgb="FF' . ($d['color'] ?? '1F2937') . '"/></font>';
            $fontId = array_search($f, $fonts, true);
            if ($fontId === false) { $fonts[] = $f; $fontId = count($fonts) - 1; }
        }

        $fillId = 0;
        if (!empty($d['fill'])) {
            $f = '<fill><patternFill patternType="solid">'
               . '<fgColor rgb="FF' . $d['fill'] . '"/><bgColor indexed="64"/></patternFill></fill>';
            $fillId = array_search($f, $fills, true);
            if ($fillId === false) { $fills[] = $f; $fillId = count($fills) - 1; }
        }

        $borderId = 1;
        if (!empty($d['top'])) {
            $b = '<border><left/><right style="thin"><color rgb="FFE2E8F0"/></right>'
               . '<top style="medium"><color rgb="FF' . $d['top'] . '"/></top>'
               . '<bottom style="thin"><color rgb="FFE2E8F0"/></bottom><diagonal/></border>';
            $borderId = array_search($b, $borders, true);
            if ($borderId === false) { $borders[] = $b; $borderId = count($borders) - 1; }
        }

        $numId = 0;
        if (!empty($d['fmt'])) {
            $code = $d['fmt'];
            $numId = array_search($code, $numFmts, true);
            if ($numId === false) { $numFmts[] = $code; $numId = count($numFmts) - 1; }
            $numId += 164;   // custom formats start at 164; below that is reserved
        }

        $align = '';
        if (!empty($d['align']) || !empty($d['wrap'])) {
            $align = '<alignment'
                   . (!empty($d['align']) ? ' horizontal="' . $d['align'] . '"' : '')
                   . ' vertical="center"'
                   . (!empty($d['wrap']) ? ' wrapText="1"' : '') . '/>';
        }

        $xfs[] = '<xf numFmtId="' . $numId . '" fontId="' . $fontId . '" fillId="' . $fillId
               . '" borderId="' . $borderId . '" xfId="0"'
               . ($numId  ? ' applyNumberFormat="1"' : '')
               . ($fontId ? ' applyFont="1"'   : '')
               . ($fillId ? ' applyFill="1"'   : '')
               . ' applyBorder="1"'
               . ($align  ? ' applyAlignment="1">' . $align . '</xf>' : '/>');
        $map[$name] = count($xfs) - 1;
    }

    $nf = '';
    foreach ($numFmts as $i => $code) {
        $nf .= '<numFmt numFmtId="' . (164 + $i) . '" formatCode="' . xlsxEsc($code) . '"/>';
    }

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
         . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
         . ($nf ? '<numFmts count="' . count($numFmts) . '">' . $nf . '</numFmts>' : '')
         . '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts>'
         . '<fills count="' . count($fills) . '">' . implode('', $fills) . '</fills>'
         . '<borders count="' . count($borders) . '">' . implode('', $borders) . '</borders>'
         . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
         . '<cellXfs count="' . count($xfs) . '">' . implode('', $xfs) . '</cellXfs>'
         . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
         . '</styleSheet>';

    return [$xml, $map];
}

/**
 * Write the workbook and return the path to it.
 *
 * @param array $rows    each row a list of cells from xlsxText()/xlsxNumber()
 * @param array $widths  column widths in characters
 * @param array $defs    named styles, see xlsxStyles()
 * @return string|null   temp file path, or null if it could not be written
 */
function xlsxWrite(string $sheetName, array $rows, array $widths, array $defs,
                   bool $autoFilter = true, bool $freezeHeader = true): ?string
{
    if (!class_exists('ZipArchive')) {
        error_log('xlsxWrite: ZipArchive is not available');
        return null;
    }

    [$stylesXml, $map] = xlsxStyles($defs);

    $cols = '';
    foreach ($widths as $i => $w) {
        $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1)
               . '" width="' . round($w, 2) . '" customWidth="1"/>';
    }

    $body = '';
    $merges = [];
    foreach ($rows as $rIdx => $cells) {
        $r = $rIdx + 1;
        $body .= '<row r="' . $r . '"' . ($rIdx === 0 ? ' ht="30" customHeight="1"' : '') . '>';
        $c = 0;
        foreach ($cells as $cell) {
            $ref = xlsxCol($c) . $r;
            $s   = $map[$cell['s'] ?? ''] ?? 0;
            if (($cell['t'] ?? 's') === 'n') {
                $body .= '<c r="' . $ref . '" s="' . $s . '"><v>'
                       . rtrim(rtrim(number_format((float)$cell['v'], 4, '.', ''), '0'), '.')
                       . '</v></c>';
            } else {
                $v = (string)($cell['v'] ?? '');
                $body .= '<c r="' . $ref . '" s="' . $s . '" t="inlineStr"><is><t xml:space="preserve">'
                       . xlsxEsc($v) . '</t></is></c>';
            }
            if (!empty($cell['across'])) {
                $merges[] = $ref . ':' . xlsxCol($c + (int)$cell['across']) . $r;
                // The cells a merge swallows still have to exist, or Excel repairs the file.
                for ($k = 1; $k <= (int)$cell['across']; $k++) {
                    $body .= '<c r="' . xlsxCol($c + $k) . $r . '" s="' . $s . '"/>';
                }
                $c += (int)$cell['across'];
            }
            $c++;
        }
        $body .= '</row>';
    }

    $nCols = max(1, count($widths));
    $nRows = max(1, count($rows));
    $dim   = 'A1:' . xlsxCol($nCols - 1) . $nRows;

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<dimension ref="' . $dim . '"/>'
        . '<sheetViews><sheetView workbookViewId="0">'
        . ($freezeHeader
            ? '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
              . '<selection pane="bottomLeft" activeCell="A2" sqref="A2"/>'
            : '')
        . '</sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . ($cols ? '<cols>' . $cols . '</cols>' : '')
        . '<sheetData>' . $body . '</sheetData>'
        // Order matters in the schema: mergeCells before autoFilter, or Excel repairs it.
        . ($merges ? '<mergeCells count="' . count($merges) . '">'
            . implode('', array_map(fn($m) => '<mergeCell ref="' . $m . '"/>', $merges))
            . '</mergeCells>' : '')
        . ($autoFilter ? '<autoFilter ref="A1:' . xlsxCol($nCols - 1) . '1"/>' : '')
        . '<pageMargins left="0.3" right="0.3" top="0.4" bottom="0.4" header="0.3" footer="0.3"/>'
        . '<pageSetup orientation="landscape" paperSize="9" fitToWidth="1"/>'
        . '</worksheet>';

    // Excel truncates sheet names at 31 and rejects these characters outright.
    $name = mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/u', '-', $sheetName) ?: 'Sheet1', 0, 31);

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    if ($tmp === false) return null;

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) { @unlink($tmp); return null; }

    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
      . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
      . '<Default Extension="xml" ContentType="application/xml"/>'
      . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
      . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
      . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
      . '</Types>');

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      . '<Relationship Id="rId1" Target="xl/workbook.xml"'
      . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"/>'
      . '</Relationships>');

    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
      . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
      . '<sheets><sheet name="' . xlsxEsc($name) . '" sheetId="1" r:id="rId1"/></sheets>'
      . '</workbook>');

    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      . '<Relationship Id="rId1" Target="worksheets/sheet1.xml"'
      . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/>'
      . '<Relationship Id="rId2" Target="styles.xml"'
      . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"/>'
      . '</Relationships>');

    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);

    if (!$zip->close()) { @unlink($tmp); return null; }
    return $tmp;
}

/** Send the workbook and delete the temp file. */
function xlsxSend(string $path, string $fileName): void
{
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $fileName) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store');
    readfile($path);
    @unlink($path);
}

} // function_exists('xlsxText')
