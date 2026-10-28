<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
// customer_relations is here because this is where website enquiries and
// viewing requests land, and they are the people notified to work them --
// being sent a request and then refused the page is no use to anybody.
// Page-scoped on purpose: it is not the whole showroom module.
canAccess('showroom') || hasRole(['admin','sales_manager','sales_officer','sales_person',
                                  'general_manager','customer_relations']) || die('Access denied.');
$pageTitle = 'Showroom Inquiries';
$db = getDB();

// The viewing-request columns live with the public forms that write them.
require_once __DIR__ . '/../../showroom/_leads_bootstrap.php';
showroomLeadsMigrate($db);

// Inline migrations — ensure columns exist
try { $db->exec("ALTER TABLE showroom_inquiries ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'new'"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE showroom_inquiries ADD COLUMN notes TEXT NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE showroom_inquiries ADD COLUMN responded_by INT NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE showroom_inquiries ADD COLUMN responded_at DATETIME NULL DEFAULT NULL"); } catch (\Throwable $_) {}

// ── Handle status update ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrf();
    $iqId = (int)($_POST['inquiry_id'] ?? 0);
    if ($iqId) {
        if ($_POST['action'] === 'confirm_viewing') {
            // Confirming promises a customer that somebody will be there and
            // the car will be ready, so it sits with the roles that were told
            // about the request in the first place.
            if (!showroomCanConfirmViewing()) {
                setFlash('danger', 'Only an administrator or customer relations can confirm a viewing.');
            } else {
                $r = showroomConfirmViewing(
                    $db, $iqId,
                    trim((string)($_POST['confirmed_date'] ?? '')),
                    trim((string)($_POST['confirmed_time'] ?? '')),
                    (int)authUser()['id']
                );
                if (!$r['ok']) {
                    setFlash('danger', $r['error']);
                } else {
                    // The email is the point of confirming, so whether it went
                    // is part of the answer rather than a detail in a log.
                    setFlash($r['emailed'] ? 'success' : 'warning',
                        ($r['rescheduled'] ? 'Viewing rearranged for ' : 'Viewing confirmed for ')
                        . $r['when'] . '. ' . $r['email_note']);
                }
            }
        } elseif ($_POST['action'] === 'update_status') {
            $status = in_array($_POST['status'], ['new','contacted','confirmed','closed']) ? $_POST['status'] : 'new';
            $db->prepare("UPDATE showroom_inquiries SET status=?, responded_by=?, responded_at=NOW() WHERE id=?")
               ->execute([$status, authUser()['id'], $iqId]);
        } elseif ($_POST['action'] === 'save_notes') {
            $db->prepare("UPDATE showroom_inquiries SET notes=?, responded_by=?, responded_at=NOW() WHERE id=?")
               ->execute([trim($_POST['notes'] ?? ''), authUser()['id'], $iqId]);
        }
    }
    redirect(BASE_URL . '/modules/showroom/index.php' . ($_GET['id'] ? '?id=' . (int)$_GET['id'] : ''));
}

// ── Inquiry detail view ───────────────────────────────────────────────────
$viewId = (int)($_GET['id'] ?? 0);
$detail = null;
if ($viewId) {
    $s = $db->prepare("SELECT i.*, c.make, c.model, c.year, c.asking_price,
                              c.registration_number, c.chassis_number,
                              u.name AS responded_by_name,
                              cb.name AS confirmed_by_name,
                              l.name AS location_name
                       FROM showroom_inquiries i
                       JOIN cars c ON c.id = i.car_id
                       LEFT JOIN users u ON u.id = i.responded_by
                       LEFT JOIN users cb ON cb.id = i.confirmed_by
                       LEFT JOIN locations l ON l.id = i.location_id
                       WHERE i.id = ?");
    $s->execute([$viewId]);
    $detail = $s->fetch(PDO::FETCH_ASSOC);
}

// ── List ──────────────────────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? '';
$where  = ['1=1'];
$params = [];
if ($filterStatus) { $where[] = 'i.status = ?'; $params[] = $filterStatus; }

$inquiries = $db->prepare("
    SELECT i.*, c.make, c.model, c.year
    FROM showroom_inquiries i
    JOIN cars c ON c.id = i.car_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY i.status='new' DESC, i.created_at DESC
    LIMIT 200
");
$inquiries->execute($params);
$inquiries = $inquiries->fetchAll(PDO::FETCH_ASSOC);

$counts = $db->query("SELECT status, COUNT(*) AS n FROM showroom_inquiries GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0"><i class="fa fa-store me-2 text-primary"></i>Showroom Inquiries</h5>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/showroom/" target="_blank" class="btn btn-sm btn-outline-primary">
            <i class="fa fa-external-link me-1"></i>View Showroom
        </a>
    </div>
</div>

<!-- Status tabs -->
<div class="d-flex gap-2 mb-3 flex-wrap">
    <?php
    $tabs = [''=>'All', 'new'=>'New', 'contacted'=>'Contacted',
             'confirmed'=>'Confirmed', 'closed'=>'Closed'];
    $tabColors = ['new'=>'danger','contacted'=>'primary',
                  'confirmed'=>'success','closed'=>'secondary'];
    foreach ($tabs as $val => $label):
        $cnt = $val === '' ? array_sum($counts) : ($counts[$val] ?? 0);
    ?>
    <a href="?status=<?= $val ?>" class="btn btn-sm <?= $filterStatus === $val ? 'btn-primary' : 'btn-outline-secondary' ?>">
        <?= $label ?>
        <?php if ($cnt): ?><span class="badge <?= $filterStatus === $val ? 'bg-white text-primary' : 'bg-' . ($tabColors[$val] ?? 'secondary') ?> ms-1"><?= $cnt ?></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<div class="row g-3">

    <!-- ── Inquiry list ──────────────────────────────────── -->
    <div class="col-lg-<?= $detail ? '5' : '12' ?>">
        <div class="card">
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Enquirer</th>
                            <th>Vehicle</th>
                            <th>Contact</th>
                            <th>Status</th>
                            <th>Received</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$inquiries): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No inquiries yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($inquiries as $iq): ?>
                        <tr class="<?= $viewId === $iq['id'] ? 'table-active' : '' ?>" style="cursor:pointer"
                            onclick="location.href='?<?= $filterStatus ? 'status='.$filterStatus.'&' : '' ?>id=<?= $iq['id'] ?>'">
                            <td class="ps-3">
                                <div class="fw-semibold" style="font-size:13.5px"><?= e($iq['inquiry_name']) ?></div>
                                <?php if ($iq['status'] === 'new'): ?>
                                <span class="badge bg-danger" style="font-size:10px">New</span>
                                <?php endif; ?>
                                <?php /* Somebody who has named a day is not the same as somebody
                                         asking a question, and the difference decides who picks
                                         it up first. */ ?>
                                <?php if (($iq['request_type'] ?? '') === 'viewing'): ?>
                                <span class="badge bg-warning text-dark" style="font-size:10px">
                                    <i class="fa fa-calendar-check"></i> Viewing<?php
                                        if (!empty($iq['preferred_date'])): ?>
                                        · <?= e(date('j M', strtotime((string)$iq['preferred_date']))) ?><?php
                                        endif; ?>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted">
                                <?= e($iq['year'] . ' ' . $iq['make'] . ' ' . $iq['model']) ?>
                            </td>
                            <td class="small">
                                <?php if ($iq['inquiry_phone']): ?>
                                <div><i class="fa fa-phone me-1 text-muted"></i><?= e($iq['inquiry_phone']) ?></div>
                                <?php endif; ?>
                                <?php if ($iq['inquiry_email']): ?>
                                <div><i class="fa fa-envelope me-1 text-muted"></i><?= e($iq['inquiry_email']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $sc = ['new'=>'danger','contacted'=>'primary','closed'=>'secondary'];
                                echo '<span class="badge bg-' . ($sc[$iq['status']] ?? 'secondary') . '">' . ucfirst($iq['status']) . '</span>';
                                ?>
                            </td>
                            <td class="text-muted small"><?= fmtDate($iq['created_at'], 'd M H:i') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ── Inquiry detail ─────────────────────────────────── -->
    <?php if ($detail): ?>
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-envelope-open me-2 text-primary"></i>Inquiry #<?= $detail['id'] ?></span>
                <a href="?<?= $filterStatus ? 'status='.$filterStatus : '' ?>" class="btn btn-xs btn-outline-secondary">
                    <i class="fa fa-xmark me-1"></i>Close
                </a>
            </div>
            <div class="card-body">
                <!-- Vehicle -->
                <div class="mb-3 p-3 rounded-3" style="background:#f8fafc;border:1px solid #e2e8f0">
                    <div class="text-muted small mb-1">Vehicle of Interest</div>
                    <div class="fw-bold" style="font-size:15px"><?= e($detail['year'] . ' ' . $detail['make'] . ' ' . $detail['model']) ?></div>
                    <?php if ($detail['asking_price']): ?>
                    <div class="text-primary fw-semibold">KES <?= number_format((float)$detail['asking_price']) ?></div>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= $detail['car_id'] ?>" target="_blank" class="small text-muted">
                        <i class="fa fa-external-link me-1"></i>View in showroom
                    </a>
                    &nbsp;&bull;&nbsp;
                    <a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= $detail['car_id'] ?>" class="small text-muted">
                        <i class="fa fa-car me-1"></i>View car record
                    </a>
                </div>

                <!-- Contact -->
                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <div class="text-muted small mb-1">Name</div>
                        <div class="fw-semibold"><?= e($detail['inquiry_name']) ?></div>
                    </div>
                    <?php if ($detail['inquiry_phone']): ?>
                    <div class="col-sm-6">
                        <div class="text-muted small mb-1">Phone</div>
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <a href="tel:<?= e($detail['inquiry_phone']) ?>" class="fw-semibold">
                                <i class="fa fa-phone me-1 text-success"></i><?= e($detail['inquiry_phone']) ?>
                            </a>
                            <?php $wp = preg_replace('/[^0-9]/', '', $detail['inquiry_phone']); ?>
                            <a href="https://wa.me/<?= $wp ?>" target="_blank" rel="noopener"
                               class="btn btn-xs" style="background:#dcfce7;color:#16a34a;border:none">
                                <i class="fa-brands fa-whatsapp me-1"></i>WhatsApp
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($detail['inquiry_email']): ?>
                    <div class="col-sm-6">
                        <div class="text-muted small mb-1">Email</div>
                        <a href="mailto:<?= e($detail['inquiry_email']) ?>" class="fw-semibold">
                            <i class="fa fa-envelope me-1 text-primary"></i><?= e($detail['inquiry_email']) ?>
                        </a>
                    </div>
                    <?php endif; ?>
                    <div class="col-sm-6">
                        <div class="text-muted small mb-1">Received</div>
                        <div class="fw-semibold"><?= fmtDate($detail['created_at'], 'd M Y, H:i') ?></div>
                    </div>
                </div>

                <!-- Message -->
                <?php if ($detail['message']): ?>
                <div class="mb-3">
                    <div class="text-muted small mb-1">Message</div>
                    <div class="p-3 rounded-3" style="background:#f0f9ff;border:1px solid #bae6fd;font-size:14px;line-height:1.7;color:#0c4a6e">
                        <?= nl2br(e($detail['message'])) ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php /* ── Confirm the viewing ───────────────────────────────────
                         A date and a clock time, not the slot the customer asked
                         for: the point of confirming is to replace a preference
                         with an appointment they can turn up to. Prefilled from
                         what they asked for, and editable, because the yard often
                         has to offer a different hour.

                         Saving sends them the email. Re-saving rearranges it and
                         sends another, which is what a customer ringing to move
                         the day actually needs. */ ?>
                <?php if (($detail['request_type'] ?? '') === 'viewing'): ?>
                <?php $isConfirmed = !empty($detail['confirmed_at']); ?>
                <div class="border rounded-3 p-3 mb-3"
                     style="background:<?= $isConfirmed ? '#f0fdf4' : '#fffbeb' ?>;border-color:<?= $isConfirmed ? '#bbf7d0' : '#fde68a' ?> !important">
                    <?php if ($isConfirmed): ?>
                    <div class="fw-bold text-success mb-1" style="font-size:13.5px">
                        <i class="fa fa-circle-check me-1"></i>Confirmed for
                        <?= e(date('l j F Y', strtotime((string)$detail['confirmed_date']))) ?>
                        at <?= e(date('g:ia', strtotime((string)$detail['confirmed_date'] . ' ' . (string)$detail['confirmed_time']))) ?>
                    </div>
                    <div class="text-muted mb-2" style="font-size:11.5px">
                        <?php if (!empty($detail['confirmed_by_name'])): ?>
                            by <?= e($detail['confirmed_by_name']) ?>
                        <?php endif; ?>
                        <?php if (empty($detail['inquiry_email'])): ?>
                            · no email address on file, so nothing was sent
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="fw-semibold mb-2" style="font-size:13.5px">
                        <i class="fa fa-calendar-check me-1 text-warning"></i>Confirm this viewing
                    </div>
                    <div class="text-muted mb-2" style="font-size:11.5px">
                        <?php if (!empty($detail['inquiry_email'])): ?>
                            Saving emails <?= e($detail['inquiry_email']) ?> the day, the time and how to find us.
                        <?php else: ?>
                            They left no email address, so please call them — the appointment is still recorded.
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if (showroomCanConfirmViewing()): ?>
                    <form method="POST" class="d-flex gap-2 flex-wrap align-items-end">
                        <?= csrfField() ?>
                        <input type="hidden" name="inquiry_id" value="<?= (int)$detail['id'] ?>">
                        <input type="hidden" name="action" value="confirm_viewing">
                        <div>
                            <label class="form-label small fw-semibold mb-1">Date</label>
                            <input type="date" name="confirmed_date" class="form-control form-control-sm" required
                                   style="width:auto"
                                   min="<?= e((string)$db->query('SELECT CURDATE()')->fetchColumn()) ?>"
                                   value="<?= e((string)($detail['confirmed_date'] ?: $detail['preferred_date'] ?: $db->query('SELECT CURDATE()')->fetchColumn())) ?>">
                        </div>
                        <div>
                            <label class="form-label small fw-semibold mb-1">Time</label>
                            <input type="time" name="confirmed_time" class="form-control form-control-sm" required
                                   style="width:auto"
                                   value="<?= e($detail['confirmed_time']
                                       ? substr((string)$detail['confirmed_time'], 0, 5)
                                       : showroomViewingSlotStart($detail['preferred_time'] ?? null)) ?>">
                        </div>
                        <button class="btn btn-sm <?= $isConfirmed ? 'btn-outline-success' : 'btn-success' ?>">
                            <i class="fa fa-paper-plane me-1"></i><?= $isConfirmed ? 'Rearrange & resend' : 'Confirm & email client' ?>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Status update -->
                <form method="POST" class="d-flex gap-2 mb-3 flex-wrap align-items-end">
                    <input type="hidden" name="inquiry_id" value="<?= $detail['id'] ?>">
                    <input type="hidden" name="action" value="update_status">
                    <div>
                        <label class="form-label small fw-semibold">Update Status</label>
                        <select name="status" class="form-select form-select-sm" style="width:auto">
                            <option value="new"       <?= $detail['status']==='new'       ? 'selected' : '' ?>>New</option>
                            <option value="contacted" <?= $detail['status']==='contacted' ? 'selected' : '' ?>>Contacted</option>
                            <option value="confirmed" <?= $detail['status']==='confirmed' ? 'selected' : '' ?>>Confirmed</option>
                            <option value="closed"    <?= $detail['status']==='closed'    ? 'selected' : '' ?>>Closed</option>
                        </select>
                    </div>
                    <button class="btn btn-sm btn-primary"><i class="fa fa-save me-1"></i>Save</button>
                    <?php if ($detail['responded_by_name']): ?>
                    <small class="text-muted align-self-end">Last updated by <?= e($detail['responded_by_name']) ?></small>
                    <?php endif; ?>
                </form>

                <!-- Internal notes -->
                <form method="POST">
                    <input type="hidden" name="inquiry_id" value="<?= $detail['id'] ?>">
                    <input type="hidden" name="action" value="save_notes">
                    <label class="form-label small fw-semibold">Internal Notes</label>
                    <textarea name="notes" class="form-control form-control-sm mb-2" rows="3"
                              placeholder="Spoke to client on 12 June, interested but checking financing..."><?= e($detail['notes'] ?? '') ?></textarea>
                    <button class="btn btn-sm btn-outline-secondary"><i class="fa fa-save me-1"></i>Save Notes</button>
                </form>

            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php /* ── Who it is, in one dialog ──────────────────────────────────────────
         Reached from the notification a viewing request raises. Somebody is
         being told "a customer wants to see a car on Thursday"; the only
         useful next step is the customer's name and number, so that is what
         opens, rather than leaving them to find the row in a list.

         Rendered only when a record is actually open, and opened by itself
         when the link said to. */ ?>
<?php if ($detail): ?>
<div class="modal fade" id="clientDetailModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title fw-bold">
                    <?php if (($detail['request_type'] ?? '') === 'viewing'): ?>
                    <i class="fa fa-calendar-check me-2 text-warning"></i>Viewing Request
                    <?php else: ?>
                    <i class="fa fa-envelope-open-text me-2 text-primary"></i>Website Enquiry
                    <?php endif; ?>
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.6px">Client</div>
                    <div class="fw-bold" style="font-size:17px"><?= e($detail['inquiry_name']) ?></div>
                </div>

                <div class="d-flex flex-column gap-2 mb-3">
                    <?php if (!empty($detail['inquiry_phone'])): ?>
                    <a href="tel:<?= e($detail['inquiry_phone']) ?>" class="btn btn-success btn-sm text-start">
                        <i class="fa fa-phone me-2"></i><?= e($detail['inquiry_phone']) ?>
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($detail['inquiry_email'])): ?>
                    <a href="mailto:<?= e($detail['inquiry_email']) ?>" class="btn btn-outline-secondary btn-sm text-start">
                        <i class="fa fa-envelope me-2"></i><?= e($detail['inquiry_email']) ?>
                    </a>
                    <?php endif; ?>
                </div>

                <table class="table table-sm mb-0" style="font-size:13px">
                    <tr>
                        <td class="text-muted" style="width:38%">Vehicle</td>
                        <td class="fw-semibold">
                            <?= e(trim(($detail['year'] ? $detail['year'] . ' ' : '')
                                       . $detail['make'] . ' ' . $detail['model'])) ?>
                            <?php if (!empty($detail['registration_number'])): ?>
                            <div class="text-muted font-monospace" style="font-size:11.5px"><?= e($detail['registration_number']) ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if (($detail['request_type'] ?? '') === 'viewing'): ?>
                    <tr>
                        <td class="text-muted">Wants to come</td>
                        <td class="fw-semibold">
                            <?php if (!empty($detail['preferred_date'])): ?>
                                <?= e(date('D j M Y', strtotime((string)$detail['preferred_date']))) ?>
                            <?php else: ?>
                                <span class="text-muted fw-normal">No day given</span>
                            <?php endif; ?>
                            <?php $slot = showroomViewingSlotLabel($detail['preferred_time'] ?? null);
                                  if ($slot !== ''): ?>
                            <div class="text-muted fw-normal" style="font-size:11.5px"><?= e($slot) ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if (!empty($detail['location_name'])): ?>
                    <tr>
                        <td class="text-muted">At</td>
                        <td><?= e($detail['location_name']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="text-muted">Confirmed</td>
                        <td>
                            <?php if (!empty($detail['confirmed_at'])): ?>
                            <span class="fw-semibold text-success">
                                <?= e(date('D j M Y', strtotime((string)$detail['confirmed_date']))) ?>
                                at <?= e(date('g:ia', strtotime((string)$detail['confirmed_date'] . ' ' . (string)$detail['confirmed_time']))) ?>
                            </span>
                            <?php else: ?>
                            <span class="badge bg-warning text-dark">Not yet — confirm it below</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="text-muted">Received</td>
                        <td><?= e(date('D j M Y, g:ia', strtotime((string)$detail['created_at']))) ?></td>
                    </tr>
                    <?php if (!empty($detail['message'])): ?>
                    <tr>
                        <td class="text-muted align-top">They said</td>
                        <td style="white-space:pre-line"><?= e($detail['message']) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
            <div class="modal-footer">
                <?php if (!empty($detail['lead_id'])): ?>
                <a href="<?= BASE_URL ?>/modules/crm/view_lead.php?id=<?= (int)$detail['lead_id'] ?>"
                   class="btn btn-primary btn-sm">
                    <i class="fa fa-user me-1"></i>Open the lead
                </a>
                <?php endif; ?>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">
                    Work it here
                </button>
            </div>
        </div>
    </div>
</div>
<?php if (!empty($_GET['popup'])): ?>
<script>
// Arrived from the notification, so show who it is without another click.
// Waited on because the bootstrap bundle loads at the end of the page.
(function () {
    var tries = 0;
    var t = setInterval(function () {
        var el = document.getElementById('clientDetailModal');
        if (el && window.bootstrap && bootstrap.Modal) {
            clearInterval(t);
            bootstrap.Modal.getOrCreateInstance(el).show();
        } else if (++tries > 40) {
            clearInterval(t);
        }
    }, 75);
}());
</script>
<?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
