<?php
/**
 * Visitors — the record of who came through the door.
 *
 * Answers the two questions management actually asks: how many people came, and
 * what did they come for. The third column of every row is the one that matters
 * most — what the visit turned into, linked straight through to the lead or the
 * client, so a walk-in can be followed from the front desk to the deal.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_bootstrap.php';
requireLogin();
canAccess('visitors') || redirect(BASE_URL . '/index.php');

$db = getDB();
visitorsMigrate($db);

$purpose = (string)($_GET['purpose'] ?? '');
$range   = in_array($_GET['range'] ?? '', ['today','week','month','all'], true) ? $_GET['range'] : 'month';
$search  = trim($_GET['q'] ?? '');

$where = ['1'];
$args  = [];
if (isset(visitorPurposes()[$purpose])) { $where[] = 'v.purpose = ?'; $args[] = $purpose; }
if ($range === 'today')      $where[] = 'DATE(v.created_at) = CURDATE()';
elseif ($range === 'week')   $where[] = 'YEARWEEK(v.created_at, 1) = YEARWEEK(CURDATE(), 1)';
elseif ($range === 'month')  $where[] = "v.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')";
if ($search !== '') {
    $where[] = '(CONCAT_WS(" ", v.first_name, v.middle_name, v.last_name) LIKE ?
                 OR v.phone LIKE ? OR v.id_number LIKE ? OR v.email LIKE ?)';
    $s = "%{$search}%";
    array_push($args, $s, $s, $s, $s);
}
$whereSql = implode(' AND ', $where);

$rows = [];
try {
    $st = $db->prepare("
        SELECT v.*, u.name AS staff_name, a.name AS officer_name, r.name AS recorded_by_name,
               TRIM(CONCAT_WS(' ', c.year, c.make, c.model)) AS car_label
        FROM visitors v
        LEFT JOIN users u ON u.id = v.staff_id
        LEFT JOIN users a ON a.id = v.assigned_to
        LEFT JOIN users r ON r.id = v.recorded_by
        LEFT JOIN cars  c ON c.id = v.car_id
        WHERE {$whereSql}
        ORDER BY v.created_at DESC
        LIMIT 500");
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {}

$stats = visitorStats($db);

$pageTitle = 'Visitors';
include __DIR__ . '/../../includes/header.php';
?>
<style>
.vs-tiles{ display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:13px; margin-bottom:18px; }
.vs-tile{ background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg,12px); padding:16px 18px; }
.vs-tile .k{ font-size:11.5px; color:var(--text-2,#64748b); font-weight:600; text-transform:uppercase; letter-spacing:.05em; }
.vs-tile .v{ font-size:26px; font-weight:800; letter-spacing:-1px; margin-top:4px; }
.vs-tile .s{ font-size:11.5px; color:var(--text-2,#64748b); }
.vs-chip{ display:inline-flex; align-items:center; gap:7px; border:1px solid var(--border);
    border-radius:8px; padding:6px 13px; font-size:12.5px; font-weight:600; color:var(--text);
    text-decoration:none; }
.vs-chip:hover{ border-color:var(--brand); color:var(--text); }
.vs-chip.on{ background:var(--brand); border-color:var(--brand); color:#fff; }
.vs-chip.on small{ color:rgba(255,255,255,.72); }
.vs-tag{ display:inline-flex; align-items:center; gap:6px; font-size:11px; font-weight:700;
    padding:3px 9px; border-radius:20px; white-space:nowrap; }
.vs-out{ font-size:12px; }
.vs-out a{ font-weight:600; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h1 style="font-size:19px;font-weight:800;letter-spacing:-.4px;margin:0">
            <i class="fa fa-book-open-reader me-2" style="color:var(--brand)"></i>Visitors
        </h1>
        <div class="small text-muted">Everyone who signed the visitors book at reception</div>
    </div>
    <a href="<?= BASE_URL ?>/visitorbook/index.php" target="_blank" class="btn btn-sm btn-outline-primary">
        <i class="fa fa-up-right-from-square me-1"></i>Open the sign-in form
    </a>
</div>

<div class="vs-tiles">
    <div class="vs-tile">
        <div class="k">Today</div>
        <div class="v" style="color:var(--brand)"><?= (int)$stats['today'] ?></div>
        <div class="s">visitors so far</div>
    </div>
    <div class="vs-tile">
        <div class="k">This week</div>
        <div class="v"><?= (int)$stats['week'] ?></div>
        <div class="s">since Monday</div>
    </div>
    <div class="vs-tile">
        <div class="k">This month</div>
        <div class="v"><?= (int)$stats['month'] ?></div>
        <div class="s"><?= date('F') ?></div>
    </div>
    <?php foreach (visitorPurposes() as $k => [$label, $icon, $colour]): ?>
    <div class="vs-tile">
        <div class="k"><i class="fa <?= $icon ?> me-1" style="color:<?= $colour ?>"></i><?= e($label) ?></div>
        <div class="v" style="color:<?= $colour ?>"><?= (int)($stats['by_purpose'][$k] ?? 0) ?></div>
        <div class="s">all time</div>
    </div>
    <?php endforeach; ?>
</div>

<form method="GET" class="card mb-3">
    <div class="card-body d-flex flex-wrap gap-2 align-items-center">
        <?php
        $qs = function (array $over) use ($purpose, $range, $search) {
            return BASE_URL . '/modules/visitors/index.php?' . http_build_query(array_merge(
                ['purpose' => $purpose, 'range' => $range, 'q' => $search], $over));
        };
        ?>
        <?php foreach (['today'=>'Today','week'=>'This week','month'=>'This month','all'=>'All time'] as $rk => $rl): ?>
        <a href="<?= e($qs(['range' => $rk])) ?>" class="vs-chip <?= $range === $rk ? 'on' : '' ?>"><?= $rl ?></a>
        <?php endforeach; ?>

        <span class="text-muted mx-1">|</span>

        <a href="<?= e($qs(['purpose' => ''])) ?>" class="vs-chip <?= $purpose === '' ? 'on' : '' ?>">All purposes</a>
        <?php foreach (visitorPurposes() as $k => [$label, $icon, $colour]): ?>
        <a href="<?= e($qs(['purpose' => $k])) ?>" class="vs-chip <?= $purpose === $k ? 'on' : '' ?>">
            <i class="fa <?= $icon ?>"></i><?= e($label) ?>
        </a>
        <?php endforeach; ?>

        <div class="ms-auto d-flex gap-2" style="min-width:250px">
            <input type="hidden" name="purpose" value="<?= e($purpose) ?>">
            <input type="hidden" name="range" value="<?= e($range) ?>">
            <input type="text" name="q" class="form-control form-control-sm" value="<?= e($search) ?>"
                   placeholder="Name, phone, ID or email">
            <button class="btn btn-sm btn-primary"><i class="fa fa-magnifying-glass"></i></button>
        </div>
    </div>
</form>

<div class="card">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="fa fa-list me-2"></i>Visitor log</span>
        <span class="text-muted small"><?= count($rows) ?> shown<?= count($rows) === 500 ? ' (most recent 500)' : '' ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle" style="font-size:13px">
            <thead>
                <tr>
                    <th>When</th><th>Visitor</th><th>Contact</th>
                    <th>Purpose</th><th>Details</th><th>Outcome</th><th>Heard via</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
            <tr><td colspan="7" class="text-center text-muted py-5">
                <i class="fa fa-book-open d-block mb-2" style="font-size:26px;opacity:.35"></i>
                No visitors recorded for this period.
            </td></tr>
            <?php else: foreach ($rows as $v):
                [$pl, $pi, $pc] = visitorPurposes()[$v['purpose']] ?? ['—', 'fa-user', '#64748b'];
            ?>
            <tr>
                <td class="text-nowrap">
                    <div class="fw-semibold"><?= date('j M', strtotime($v['created_at'])) ?></div>
                    <div class="text-muted" style="font-size:11.5px"><?= date('H:i', strtotime($v['created_at'])) ?></div>
                </td>
                <td>
                    <div class="fw-semibold"><?= e(visitorFullName($v)) ?></div>
                    <?php if ($v['id_number']): ?>
                    <div class="text-muted" style="font-size:11.5px">ID <?= e($v['id_number']) ?></div>
                    <?php endif; ?>
                </td>
                <td style="font-size:12px">
                    <a href="tel:<?= e($v['phone']) ?>" class="text-nowrap"><?= e($v['phone']) ?></a>
                    <?php if ($v['email']): ?><div class="text-muted text-truncate" style="max-width:170px"><?= e($v['email']) ?></div><?php endif; ?>
                </td>
                <td>
                    <span class="vs-tag" style="background:<?= $pc ?>1f;color:<?= $pc ?>">
                        <i class="fa <?= $pi ?>"></i><?= e($pl) ?>
                    </span>
                </td>
                <td style="font-size:12px;max-width:250px">
                    <?php if ($v['purpose'] === 'buy_car'): ?>
                        <div class="fw-semibold"><?= e($v['car_label'] ?: 'No vehicle recorded') ?></div>
                        <?php if ($v['buy_comment']): ?>
                        <div class="text-muted" style="font-size:11.5px"><?= e(mb_strimwidth($v['buy_comment'], 0, 90, '…')) ?></div>
                        <?php endif; ?>
                    <?php elseif ($v['purpose'] === 'car_service'): ?>
                        <div class="fw-semibold"><?= e(trim($v['svc_make'] . ' ' . $v['svc_model'])) ?>
                            <?= $v['svc_reg'] ? ' · ' . e($v['svc_reg']) : '' ?></div>
                        <?php if ($v['svc_notes']): ?>
                        <div class="text-muted" style="font-size:11.5px"><?= e(mb_strimwidth($v['svc_notes'], 0, 90, '…')) ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="fw-semibold">To see <?= e($v['staff_name'] ?: 'a member of staff') ?></div>
                        <?php if ($v['visit_reason']): ?>
                        <div class="text-muted" style="font-size:11.5px"><?= e(mb_strimwidth($v['visit_reason'], 0, 90, '…')) ?></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td class="vs-out">
                    <?php if ($v['lead_id']): ?>
                        <a href="<?= BASE_URL ?>/modules/crm/view_lead.php?id=<?= (int)$v['lead_id'] ?>">
                            <i class="fa fa-arrow-right me-1"></i>Lead #<?= (int)$v['lead_id'] ?></a>
                        <?php if ($v['officer_name']): ?>
                        <div class="text-muted" style="font-size:11px">with <?= e($v['officer_name']) ?></div>
                        <?php endif; ?>
                    <?php elseif ($v['client_id']): ?>
                        <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= (int)$v['client_id'] ?>">
                            <i class="fa fa-arrow-right me-1"></i>Client #<?= (int)$v['client_id'] ?></a>
                        <div class="text-muted" style="font-size:11px">ready to book</div>
                    <?php elseif ($v['staff_id']): ?>
                        <span class="text-muted">Notified <?= e($v['staff_name'] ?: '') ?></span>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="text-muted" style="font-size:11.5px"><?= e($v['heard_from'] ?: '—') ?></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
