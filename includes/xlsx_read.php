<?php
/**
 * A small .xlsx reader — the twin of xlsx.php, and for the same reason: there
 * is no composer vendor directory on this install.
 *
 * The finance team keeps its receivables in Excel, and telling them to "save
 * as CSV first" is the kind of instruction people follow twice and then stop
 * following. A .xlsx is a zip of XML parts, and ZipArchive and SimpleXML are
 * both here, so this reads one.
 *
 * Deliberately narrow, exactly as the writer is: the first worksheet, as rows
 * of strings. No formulas (the cached value is used), no merged-cell logic, no
 * multiple sheets. It is not a spreadsheet library and should not grow into
 * one.
 *
 * Dates are the one place it has to be clever. Excel stores a date as a serial
 * number and only the cell's format says it is a date, so the styles are read
 * to find out, and date cells come back as YYYY-MM-DD.
 */

/** How many rows will be read before it gives up. A receivables book is not
 *  a hundred thousand rows, and a file that big is a wrong file. */
const XLSX_MAX_ROWS = 5000;

/**
 * Rows from the first worksheet of an .xlsx.
 *
 * Returns ['ok' => bool, 'error' => string, 'rows' => array<int, array<int, string>>].
 * Rows are zero-indexed and dense: a gap in the middle of a row comes back as
 * an empty string, so column positions line up with the header.
 */
function xlsxReadFile(string $path, int $maxRows = XLSX_MAX_ROWS): array
{
    $fail = static fn (string $why) => ['ok' => false, 'error' => $why, 'rows' => []];

    if (!is_readable($path))      return $fail('That file could not be read.');
    if (!class_exists('ZipArchive')) {
        return $fail('This server cannot open .xlsx files. Save the sheet as CSV and upload that instead.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        // The commonest cause by a mile: an .xls saved by an old Excel, or a
        // file renamed rather than converted.
        return $fail('That is not a readable .xlsx file. If it is an older .xls, '
                   . 'open it in Excel and use Save As to make it .xlsx or CSV.');
    }

    try {
        $shared = xlsxReadShared($zip);
        $dates  = xlsxReadDateStyles($zip);
        $sheet  = xlsxFirstSheetPath($zip);
        if ($sheet === null) return $fail('That workbook has no worksheets in it.');

        $xml = $zip->getFromName($sheet);
        if ($xml === false) return $fail('The first worksheet could not be read.');

        $rows = xlsxParseSheet($xml, $shared, $dates, $maxRows);
    } catch (\Throwable $e) {
        $zip->close();
        error_log('xlsxReadFile: ' . $e->getMessage());
        return $fail('That workbook could not be read. Try saving it again as .xlsx, or as CSV.');
    }
    $zip->close();

    return ['ok' => true, 'error' => '', 'rows' => $rows];
}

/**
 * Rows from a CSV, in the same shape, so one caller handles both.
 *
 * The byte-order mark Excel puts at the front of "CSV UTF-8" is stripped:
 * left in, it becomes part of the first header and that column stops matching.
 */
function csvReadFile(string $path, int $maxRows = XLSX_MAX_ROWS): array
{
    if (!is_readable($path)) return ['ok' => false, 'error' => 'That file could not be read.', 'rows' => []];

    $fh = fopen($path, 'r');
    if (!$fh) return ['ok' => false, 'error' => 'That file could not be opened.', 'rows' => []];

    $rows  = [];
    $first = true;
    while (count($rows) < $maxRows && ($r = fgetcsv($fh)) !== false) {
        if ($r === [null]) continue;              // a blank line
        if ($first) {
            if (isset($r[0])) $r[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$r[0]);
            $first = false;
        }
        $rows[] = array_map(static fn ($v) => trim((string)$v), $r);
    }
    fclose($fh);

    return ['ok' => true, 'error' => '', 'rows' => $rows];
}

/** Either kind, chosen by extension. */
function sheetReadFile(string $path, string $originalName, int $maxRows = XLSX_MAX_ROWS): array
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext === 'csv' || $ext === 'txt') return csvReadFile($path, $maxRows);
    if ($ext === 'xls') {
        return ['ok' => false, 'rows' => [],
                'error' => 'That is the old .xls format, which this server cannot read. '
                         . 'Open it in Excel and use Save As to make it .xlsx or CSV.'];
    }
    return xlsxReadFile($path, $maxRows);
}

// ── The parts of a workbook ──────────────────────────────────────────────────

/** The shared string table, which is where most text in a sheet actually lives. */
function xlsxReadShared(ZipArchive $zip): array
{
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml === false) return [];

    $out = [];
    $doc = @simplexml_load_string($xml);
    if (!$doc) return [];

    foreach ($doc->si as $si) {
        // A string can be one <t>, or split across runs when part of it is
        // formatted differently; the runs have to be joined or the cell comes
        // back as only its first word.
        if (isset($si->t)) {
            $out[] = (string)$si->t;
            continue;
        }
        $s = '';
        foreach ($si->r as $r) $s .= (string)$r->t;
        $out[] = $s;
    }
    return $out;
}

/**
 * Which cell styles mean "this is a date".
 *
 * Excel keeps a date as a number and says so only in the format, so without
 * this a due date arrives as 46204 and every row fails validation.
 */
function xlsxReadDateStyles(ZipArchive $zip): array
{
    $xml = $zip->getFromName('xl/styles.xml');
    if ($xml === false) return [];

    $doc = @simplexml_load_string($xml);
    if (!$doc) return [];

    // The built-in formats that are dates or times. 14–22 are the date and
    // time ones; 45–47 are elapsed time, which nobody puts a due date in but
    // which read as dates all the same.
    $dateFmt = array_fill_keys([14,15,16,17,18,19,20,21,22,45,46,47], true);

    // Anything the file defines itself counts if its code mentions a day, a
    // month or a year — d/m/yy, dd mmm yyyy, and the rest of what people pick.
    if (isset($doc->numFmts->numFmt)) {
        foreach ($doc->numFmts->numFmt as $f) {
            $code = (string)$f['formatCode'];
            // Strip the parts that are not format letters, so the "m" in a
            // currency label does not make a money column a date.
            $bare = preg_replace('/\[[^\]]*\]|"[^"]*"|\\\\./', '', $code);
            if (preg_match('#[dy]#i', $bare) || preg_match('#m{3,}#i', $bare)
                || preg_match('#m{1,2}[/.-]#i', $bare)) {
                $dateFmt[(int)$f['numFmtId']] = true;
            }
        }
    }

    // cellXfs is what a cell's s="N" points at; each entry names a numFmtId.
    $out = [];
    if (isset($doc->cellXfs->xf)) {
        $i = 0;
        foreach ($doc->cellXfs->xf as $xf) {
            $out[$i++] = isset($dateFmt[(int)$xf['numFmtId']]);
        }
    }
    return $out;
}

/**
 * The path of the first worksheet.
 *
 * Resolved through the workbook's relationships rather than assuming
 * sheet1.xml: a sheet that has been added and deleted leaves the survivor
 * named sheet2.xml, and guessing then reads an empty file or nothing at all.
 */
function xlsxFirstSheetPath(ZipArchive $zip): ?string
{
    $wb = $zip->getFromName('xl/workbook.xml');
    $rl = $zip->getFromName('xl/_rels/workbook.xml.rels');

    if ($wb !== false && $rl !== false) {
        $wbDoc = @simplexml_load_string($wb);
        $rlDoc = @simplexml_load_string($rl);
        if ($wbDoc && $rlDoc && isset($wbDoc->sheets->sheet[0])) {
            $rid = (string)$wbDoc->sheets->sheet[0]->attributes('r', true)->id;
            foreach ($rlDoc->Relationship as $rel) {
                if ((string)$rel['Id'] === $rid) {
                    $t = ltrim((string)$rel['Target'], '/');
                    if (strpos($t, 'xl/') !== 0) $t = 'xl/' . $t;
                    return $t;
                }
            }
        }
    }

    // Fall back to looking for one, still without guessing a number.
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = $zip->getNameIndex($i);
        if ($n !== false && preg_match('#^xl/worksheets/[^/]+\.xml$#', $n)) return $n;
    }
    return null;
}

/** Rows and cells, with the column letters turned back into positions. */
function xlsxParseSheet(string $xml, array $shared, array $dates, int $maxRows): array
{
    $doc = @simplexml_load_string($xml);
    if (!$doc || !isset($doc->sheetData)) return [];

    $rows = [];
    foreach ($doc->sheetData->row as $row) {
        if (count($rows) >= $maxRows) break;

        $cells = [];
        $widest = -1;
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            $col = xlsxRefToIndex($ref);
            if ($col < 0) continue;

            $cells[$col] = xlsxCellValue($c, $shared, $dates);
            if ($col > $widest) $widest = $col;
        }

        // Dense, so a gap does not shift every later column left and quietly
        // put a phone number in the deposit field.
        $out = [];
        for ($i = 0; $i <= $widest; $i++) $out[$i] = $cells[$i] ?? '';

        // A row that is entirely empty is a spacer, not a record.
        if ($out && implode('', $out) === '') { $rows[] = []; continue; }
        $rows[] = $out;
    }

    // Trailing blank rows are what you get from a sheet somebody scrolled in.
    while ($rows && $rows[count($rows) - 1] === []) array_pop($rows);

    return $rows;
}

/** One cell, as a string — and as YYYY-MM-DD where the format says date. */
function xlsxCellValue(SimpleXMLElement $c, array $shared, array $dates): string
{
    $t = (string)$c['t'];

    if ($t === 's') {
        $i = (int)$c->v;
        return trim($shared[$i] ?? '');
    }
    if ($t === 'inlineStr') {
        $s = isset($c->is->t) ? (string)$c->is->t : '';
        if ($s === '' && isset($c->is->r)) {
            foreach ($c->is->r as $r) $s .= (string)$r->t;
        }
        return trim($s);
    }
    if ($t === 'str') {                 // a formula's cached result
        return trim((string)$c->v);
    }
    if ($t === 'b') {
        return ((string)$c->v) === '1' ? '1' : '0';
    }
    if ($t === 'e') {                   // #N/A, #REF! and friends
        return '';
    }

    $v = trim((string)$c->v);
    if ($v === '') return '';

    // A number. If its style is a date format, turn the serial into a date.
    $style = $c['s'] !== null ? (int)$c['s'] : -1;
    if ($style >= 0 && !empty($dates[$style]) && is_numeric($v)) {
        $d = xlsxSerialToDate((float)$v);
        if ($d !== null) return $d;
    }
    return $v;
}

/** "BC12" → 54. */
function xlsxRefToIndex(string $ref): int
{
    if (!preg_match('/^([A-Z]+)/i', $ref, $m)) return -1;
    $letters = strtoupper($m[1]);
    $n = 0;
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return $n - 1;
}

/**
 * An Excel date serial as YYYY-MM-DD.
 *
 * Serial 1 is 1 January 1900, but Excel also believes in 29 February 1900,
 * which never happened. Every serial above 59 is therefore one day further
 * along than it should be, which the 25569 offset already accounts for;
 * anything at or below 60 is in the broken stretch and is not trusted.
 */
function xlsxSerialToDate(float $serial): ?string
{
    if ($serial <= 60 || $serial > 2958465) return null;   // 2958465 = 31 Dec 9999
    $days = (int)floor($serial);
    $ts   = ($days - 25569) * 86400;
    $d    = gmdate('Y-m-d', $ts);
    return $d !== false ? $d : null;
}
