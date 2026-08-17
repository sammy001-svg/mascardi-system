<?php
/**
 * Visitors — printable badge.
 *
 * Sized for a standard 90×54mm badge holder and printed on its own, with no page
 * furniture. Deliberately shows only what a badge needs to: who they are, who
 * they are seeing, and the date. No phone number, no ID number — a badge is worn
 * in public and gets left on desks, so it must not carry personal details.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_bootstrap.php';
requireLogin();

$db = getDB();
visitorsMigrate($db);
$meId = (int)authUser()['id'];
$id   = (int)($_GET['id'] ?? 0);

$st = $db->prepare("SELECT v.*, u.name AS staff_name FROM visitors v
                    LEFT JOIN users u ON u.id = v.staff_id WHERE v.id = ?");
$st->execute([$id]);
$v = $st->fetch(PDO::FETCH_ASSOC);
if (!$v) { http_response_code(404); exit('Visitor not found.'); }

if (!canAccess('visitors') && (int)$v['staff_id'] !== $meId) {
    http_response_code(403); exit('Not permitted.');
}

$company = getSetting('company_name', 'Mascardi');
[$pl] = visitorPurposes()[$v['purpose']] ?? ['Visitor'];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Visitor badge — <?= htmlspecialchars(visitorFullName($v)) ?></title>
<style>
@page { size: 90mm 54mm; margin: 0; }
* { box-sizing: border-box; }
body{ margin:0; background:#eef1f5; font-family:"Segoe UI",system-ui,sans-serif; color:#0f172a; }
.badge{
    width:90mm; height:54mm; background:#fff; margin:18px auto; padding:5mm 6mm;
    display:flex; flex-direction:column; position:relative; overflow:hidden;
}
.bar{ position:absolute; left:0; top:0; bottom:0; width:4mm; background:#7e22ce; }
.co{ font-size:8.5pt; font-weight:700; letter-spacing:.16em; text-transform:uppercase; color:#7e22ce; }
.vis{ font-size:7pt; letter-spacing:.2em; text-transform:uppercase; color:#94a3b8; margin-top:.4mm; }
.nm{ font-size:15pt; font-weight:800; line-height:1.15; margin-top:2.6mm; letter-spacing:-.3px; }
.meta{ margin-top:auto; font-size:8pt; color:#475569; line-height:1.5; }
.meta b{ color:#0f172a; }
.dt{ position:absolute; right:6mm; top:5mm; text-align:right; font-size:7.5pt; color:#64748b; }
.dt .t{ font-size:11pt; font-weight:700; color:#0f172a; }
@media print {
    body{ background:#fff; }
    .badge{ margin:0; page-break-after:avoid; }
    .noprint{ display:none !important; }
}
.noprint{ text-align:center; margin:0 0 18px; font-family:inherit; }
</style>
</head>
<body>

<div class="noprint" style="padding-top:18px">
    <button onclick="window.print()"
            style="background:#7e22ce;color:#fff;border:0;border-radius:8px;padding:10px 22px;
                   font-size:14px;font-weight:600;cursor:pointer">
        Print badge
    </button>
    <a href="<?= BASE_URL ?>/modules/visitors/view.php?id=<?= $id ?>"
       style="margin-left:10px;font-size:13px">Back to the visit</a>
</div>

<div class="badge">
    <div class="bar"></div>
    <div class="dt">
        <div><?= date('D j M Y', strtotime($v['created_at'])) ?></div>
        <div class="t"><?= date('H:i', strtotime($v['created_at'])) ?></div>
    </div>
    <div class="co"><?= htmlspecialchars($company) ?></div>
    <div class="vis">Visitor</div>
    <div class="nm"><?= htmlspecialchars(visitorFullName($v)) ?></div>
    <div class="meta">
        <?php if ($v['purpose'] === 'see_someone' && $v['staff_name']): ?>
        Here to see <b><?= htmlspecialchars($v['staff_name']) ?></b>
        <?php else: ?>
        <b><?= htmlspecialchars($pl) ?></b>
        <?php endif; ?>
        <br>Please return this badge to reception on leaving.
    </div>
</div>

<script>window.addEventListener('load', function () { window.print(); });</script>
</body>
</html>
