<?php
/**
 * Monthly delivery report — the Excel download.
 *
 * Written as SpreadsheetML (the Excel 2003 XML format) rather than CSV, because
 * the thing being replaced is a formatted workbook: a dark header band, filter
 * dropdowns, frozen headings, green shading on the mileage and the sale price,
 * pink on the rows with a hole in them. A CSV throws all of that away and
 * somebody has to re-apply it by hand every month, which is the exact chore
 * this is meant to end.
 *
 * SpreadsheetML is plain XML with no library behind it, which matters here —
 * there is no composer vendor directory on this install.
 *
 * Numbers are written as real numbers, never as pre-formatted strings. A sheet
 * whose figures are text cannot be summed, and the first thing anyone does with
 * this file is put a total under a column.
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_report.php';
requireLogin();

if (!canAccess('crm')) { http_response_code(403); exit('Access denied'); }

$db  = getDB();
$me  = authUser();
$uid = (int)$me['id'];
$isCrmAgent = ($me['role'] === 'customer_relations');
$canFilter  = in_array($me['role'], ['admin', 'super_admin', 'general_manager'], true);
$scopeWhere = $isCrmAgent ? "AND l.assigned_to = $uid" : '';

deliveredReportEnsure($db);

$month   = deliveredReportMonth($_GET, deliveredReportMonths($db, $scopeWhere));
$filters = [
    'month' => $month === 'all' ? '' : $month,
    'make'  => trim($_GET['make'] ?? ''),
    'agent' => $canFilter ? (int)($_GET['agent'] ?? 0) : 0,
    'q'     => trim($_GET['q'] ?? ''),
];
$rows = deliveredReportRows($db, $filters, $scopeWhere);

[$milMin, $milMax]   = deliveredReportRange($rows, fn($r) => (float)($r['mileage'] ?? 0));
[$sellMin, $sellMax] = deliveredReportRange($rows, 'deliveredReportFinal');

$periodLabel = deliveredReportMonthLabel($month);
// ASCII only. A non-ASCII Content-Disposition filename is handled differently by
// every browser, and the one that gets it wrong saves the file with the header
// text in its name.
$fileName = 'Mascardi Deliveries - ' . $periodLabel . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $fileName) . '"');
header('Pragma: no-cache');
header('Expires: 0');

/** XML-escape. Excel is stricter about this than a browser is. */
function xl(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** A cell holding text. */
function xlText(string $v, string $style = ''): string
{
    return '<Cell' . ($style ? ' ss:StyleID="' . $style . '"' : '') . '>'
         . '<Data ss:Type="String">' . xl($v) . '</Data></Cell>';
}

/** A cell holding a real number, so the column can be summed. */
function xlNum(float $v, string $style = ''): string
{
    return '<Cell' . ($style ? ' ss:StyleID="' . $style . '"' : '') . '>'
         . '<Data ss:Type="Number">' . rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') . '</Data></Cell>';
}

$cols = ['#', 'Vehicle Make', 'Model', 'Model Year', 'Registration', 'Mileage (Kms)',
         'List Price (KES)', 'Final Sale Price (KES)', 'Sales PI / Salesperson',
         'Date Sold/Delivered', 'Payment Type', 'Customer Name', 'Commentary'];
$widths = [34, 96, 132, 62, 78, 76, 88, 104, 118, 90, 76, 168, 230];

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
          xmlns:o="urn:schemas-microsoft-com:office:office"
          xmlns:x="urn:schemas-microsoft-com:office:excel"
          xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
 <DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">
  <Title>Mascardi — Delivered Vehicles</Title>
  <Author><?= xl(getSetting('company_name', 'Mascardi')) ?></Author>
  <Created><?= date('c') ?></Created>
 </DocumentProperties>
 <Styles>
  <Style ss:ID="Default" ss:Name="Normal">
   <Alignment ss:Vertical="Center"/>
   <Font ss:FontName="Calibri" ss:Size="10" ss:Color="#1f2937"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#d9d9d9"/>
    <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#d9d9d9"/>
   </Borders>
  </Style>

  <Style ss:ID="hdr">
   <Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/>
   <Interior ss:Color="#0F6B5C" ss:Pattern="Solid"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#0B4D43"/>
    <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#FFFFFF"/>
   </Borders>
  </Style>

  <Style ss:ID="idx"><Alignment ss:Horizontal="Center"/><Font ss:Color="#6b7280" ss:Size="10"/></Style>
  <Style ss:ID="txt"/>
  <Style ss:ID="na"><Font ss:Italic="1" ss:Color="#9ca3af"/><Alignment ss:Horizontal="Center"/></Style>
  <Style ss:ID="num"><NumberFormat ss:Format="#,##0"/><Alignment ss:Horizontal="Right"/></Style>
  <Style ss:ID="date"><Alignment ss:Horizontal="Center"/></Style>

  <?php // One hue, four steps, light to dark — the same scale the page uses. ?>
  <?php foreach (['#E8F6EE', '#C9EAD6', '#A3DCBE', '#79CBA4'] as $i => $c): ?>
  <Style ss:ID="g<?= $i + 1 ?>">
   <NumberFormat ss:Format="#,##0"/><Alignment ss:Horizontal="Right"/>
   <Interior ss:Color="<?= $c ?>" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="gb<?= $i + 1 ?>">
   <NumberFormat ss:Format="#,##0"/><Alignment ss:Horizontal="Right"/>
   <Font ss:Bold="1"/><Interior ss:Color="<?= $c ?>" ss:Pattern="Solid"/>
  </Style>
  <?php endforeach; ?>

  <?php // Pink: the record has a hole in it. Red: nobody recorded the payment. ?>
  <Style ss:ID="hole"><Interior ss:Color="#F7E4F5" ss:Pattern="Solid"/></Style>
  <Style ss:ID="holeNum"><NumberFormat ss:Format="#,##0"/><Alignment ss:Horizontal="Right"/>
   <Interior ss:Color="#F7E4F5" ss:Pattern="Solid"/></Style>
  <Style ss:ID="holeNa"><Font ss:Italic="1" ss:Color="#9ca3af"/><Alignment ss:Horizontal="Center"/>
   <Interior ss:Color="#F7E4F5" ss:Pattern="Solid"/></Style>
  <Style ss:ID="red"><Font ss:Color="#C0392B" ss:Bold="1"/></Style>
  <Style ss:ID="redHole"><Font ss:Color="#C0392B" ss:Bold="1"/>
   <Interior ss:Color="#F7E4F5" ss:Pattern="Solid"/></Style>

  <Style ss:ID="ttl">
   <Font ss:Bold="1" ss:Size="10"/><Interior ss:Color="#EEF2F7" ss:Pattern="Solid"/>
   <NumberFormat ss:Format="#,##0"/><Alignment ss:Horizontal="Right"/>
   <Borders><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#0F6B5C"/></Borders>
  </Style>
  <Style ss:ID="ttlL">
   <Font ss:Bold="1" ss:Size="10"/><Interior ss:Color="#EEF2F7" ss:Pattern="Solid"/>
   <Alignment ss:Horizontal="Right"/>
   <Borders><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#0F6B5C"/></Borders>
  </Style>
  <Style ss:ID="key"><Font ss:Size="9" ss:Color="#6b7280"/></Style>
 </Styles>

 <Worksheet ss:Name="<?= xl(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', '-', $periodLabel), 0, 28)) ?>">
  <Table ss:DefaultRowHeight="15">
   <?php foreach ($widths as $w): ?>
   <Column ss:AutoFitWidth="0" ss:Width="<?= (int)$w ?>"/>
   <?php endforeach; ?>

   <Row ss:Height="30" ss:StyleID="hdr">
    <?php foreach ($cols as $c) echo xlText($c, 'hdr'); ?>
   </Row>

   <?php
   $total = 0.0;
   foreach ($rows as $i => $r):
       $v     = deliveredReportVehicle($r);
       $fl    = deliveredReportFlags($r);
       $final = deliveredReportFinal($r);
       $list  = deliveredReportList($r);
       $mil   = (float)($r['mileage'] ?? 0);
       $total += $final;
       $hole  = $fl['incomplete'];

       // A pink row wins over the green scale — the hole in the record is the
       // more urgent thing to see, and two fills on one cell is not a choice.
       $sTxt = $hole ? 'hole'    : 'txt';
       $sNum = $hole ? 'holeNum' : 'num';
       $sNa  = $hole ? 'holeNa'  : 'na';
       $sMil = $mil   > 0 ? ($hole ? 'holeNum' : 'g'  . deliveredReportShade($mil, $milMin, $milMax, true)) : $sNa;
       $sSel = $final > 0 ? ($hole ? 'holeNum' : 'gb' . deliveredReportShade($final, $sellMin, $sellMax)) : $sNa;
       $sPay = $fl['no_payment'] ? ($hole ? 'redHole' : 'red') : $sTxt;
       $cust = trim((string)($r['client_name'] ?? '')) ?: trim((string)($r['lead_name'] ?? ''));
   ?>
   <Row>
    <?= xlText((string)($i + 1), $hole ? 'hole' : 'idx') ?>
    <?= $v['make']  !== '' ? xlText($v['make'],  $sTxt) : xlText('N/A', $sNa) ?>
    <?= $v['model'] !== '' ? xlText($v['model'], $sTxt) : xlText('N/A', $sNa) ?>
    <?= $v['year']  !== '' ? xlNum((float)$v['year'], $hole ? 'holeNum' : 'date') : xlText('N/A', $sNa) ?>
    <?= $v['reg']   !== '' ? xlText($v['reg'],   $sTxt) : xlText('N/A', $sNa) ?>
    <?= $mil   > 0 ? xlNum($mil,   $sMil) : xlText('N/A', $sNa) ?>
    <?= $list  > 0 ? xlNum($list,  $sNum) : xlText('N/A', $sNa) ?>
    <?= $final > 0 ? xlNum($final, $sSel) : xlText('N/A', $sNa) ?>
    <?= xlText((string)($r['agent_name'] ?? ''), $sTxt) ?>
    <?= xlText($r['sold_at'] ? (new DateTime($r['sold_at']))->format('j-M-y') : '',
               $hole ? 'hole' : 'date') ?>
    <?= xlText((string)($r['payment_type'] ?? '') ?: 'N/A', $sPay) ?>
    <?= xlText($cust, $sTxt) ?>
    <?= xlText((string)($r['sale_commentary'] ?? ''), $sTxt) ?>
   </Row>
   <?php endforeach; ?>

   <?php if ($rows): ?>
   <Row ss:Height="20">
    <Cell ss:StyleID="ttlL" ss:MergeAcross="6"><Data ss:Type="String"><?=
        xl('Total for ' . $periodLabel) ?></Data></Cell>
    <?= xlNum($total, 'ttl') ?>
    <Cell ss:StyleID="ttlL" ss:MergeAcross="4"><Data ss:Type="String"><?=
        xl(count($rows) . ' ' . (count($rows) === 1 ? 'vehicle' : 'vehicles')) ?></Data></Cell>
   </Row>
   <?php endif; ?>

   <Row ss:Height="6"/>
   <Row><?= xlText('Greener mileage = lower. Greener sale price = higher. '
        . 'A pink row is missing something on the record. Red payment type = not recorded.',
        'key') ?></Row>
   <Row><?= xlText('Generated ' . date('j M Y, H:i') . ' by ' . ($me['name'] ?? ''), 'key') ?></Row>
  </Table>

  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
   <PageSetup>
    <Layout x:Orientation="Landscape"/>
    <PageMargins x:Bottom="0.4" x:Left="0.3" x:Right="0.3" x:Top="0.4"/>
   </PageSetup>
   <Print><ValidPrinterInfo/><PaperSizeIndex>9</PaperSizeIndex>
    <HorizontalResolution>600</HorizontalResolution></Print>
   <?php // The headings stay put while you scroll, and each carries a filter arrow —
         // the two things that make the hand-kept sheet usable. ?>
   <FreezePanes/>
   <FrozenNoSplit/>
   <SplitHorizontal>1</SplitHorizontal>
   <TopRowBottomPane>1</TopRowBottomPane>
   <ActivePane>2</ActivePane>
   <Panes><Pane><Number>3</Number></Pane><Pane><Number>2</Number></Pane></Panes>
  </WorksheetOptions>
  <AutoFilter x:Range="R1C1:R1C13" xmlns="urn:schemas-microsoft-com:office:excel"/>
 </Worksheet>
</Workbook>
