<?php
/**
 * Visitors Book — the reception sign-in form.
 *
 * One page: the visitor's details, why they came, and a section that depends on
 * the answer. Submitting it records the visit AND does something with it — see
 * modules/visitors/_bootstrap.php for why that matters.
 *
 * Reachable by the dedicated `visitor_book` account, and by a super admin who
 * wants to look at it without logging out.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../modules/visitors/_bootstrap.php';
requireLogin();

$db = getDB();
visitorsMigrate($db);

$me     = authUser();
$meId   = (int)$me['id'];
$isKiosk = authRole() === 'visitor_book';
if (!$isKiosk && !canAccess('visitors')) {
    setFlash('error', 'The visitors book is used by the reception account.');
    redirect(BASE_URL . '/index.php');
}

$errors = [];
$done   = null;

// ── Submission ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first  = trim($_POST['first_name']  ?? '');
    $middle = trim($_POST['middle_name'] ?? '');
    $last   = trim($_POST['last_name']   ?? '');
    $phone  = trim($_POST['phone']       ?? '');
    $idNo   = trim($_POST['id_number']   ?? '');
    $email  = trim($_POST['email']       ?? '');
    $heard  = trim($_POST['heard_from']  ?? '');
    $purpose = (string)($_POST['purpose'] ?? '');

    if ($first === '')  $errors[] = 'Enter the visitor’s first name.';
    if ($phone === '')  $errors[] = 'Enter a phone number.';
    if (!isset(visitorPurposes()[$purpose])) $errors[] = 'Choose the purpose of the visit.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'That email address does not look right.';
    }
    if ($heard !== '' && !in_array($heard, visitorHeardFrom(), true)) $heard = 'Other';

    // Purpose-specific
    $carId   = (int)($_POST['car_id'] ?? 0);
    $comment = trim($_POST['buy_comment'] ?? '');
    $svc = [
        'make'    => trim($_POST['svc_make']    ?? ''),
        'model'   => trim($_POST['svc_model']   ?? ''),
        'year'    => trim($_POST['svc_year']    ?? ''),
        'reg'     => trim($_POST['svc_reg']     ?? ''),
        'mileage' => (int)($_POST['svc_mileage'] ?? 0),
        'notes'   => trim($_POST['svc_notes']   ?? ''),
    ];
    $staffId = (int)($_POST['staff_id'] ?? 0);
    $reason  = trim($_POST['visit_reason'] ?? '');

    if ($purpose === 'buy_car' && $carId < 1) {
        $errors[] = 'Select the vehicle the visitor is interested in.';
    }
    if ($purpose === 'car_service') {
        if ($svc['make'] === '')  $errors[] = 'Enter the make of the vehicle to be serviced.';
        if ($svc['model'] === '') $errors[] = 'Enter the model of the vehicle to be serviced.';
        if ($svc['reg'] === '')   $errors[] = 'Enter the registration number of the vehicle.';
    }
    if ($purpose === 'see_someone') {
        if ($staffId < 1) $errors[] = 'Choose the member of staff the visitor came to see.';
        if ($reason === '') $errors[] = 'Give the reason for the visit.';
    }

    // A car must actually be one of the cars offered, not just any id posted.
    if (!$errors && $purpose === 'buy_car') {
        $ok = false;
        foreach (visitorSelectableCars($db, 500) as $c) if ((int)$c['id'] === $carId) { $ok = true; break; }
        if (!$ok) $errors[] = 'That vehicle is no longer available. Please pick another.';
    }
    if (!$errors && $purpose === 'see_someone') {
        $ok = false;
        foreach (visitorStaffList($db) as $s) if ((int)$s['id'] === $staffId) { $ok = true; break; }
        if (!$ok) $errors[] = 'Choose the member of staff from the list.';
    }

    if (!$errors) {
        $fullName = trim(implode(' ', array_filter([$first, $middle, $last])));
        $leadId = $clientId = $assignee = null;

        try {
            $db->beginTransaction();

            // ── What the visit becomes ───────────────────────────────────────
            if ($purpose === 'buy_car') {
                // A walk-in who names a car is a lead, and a lead nobody owns is
                // a lead nobody follows up — so it is assigned as it is created.
                $assignee = visitorNextCrmOfficer($db);
                $car = $db->prepare("SELECT make, model, year FROM cars WHERE id = ?");
                $car->execute([$carId]);
                $c = $car->fetch(PDO::FETCH_ASSOC) ?: [];
                $interest = trim(($c['year'] ?? '') . ' ' . ($c['make'] ?? '') . ' ' . ($c['model'] ?? ''));

                $db->prepare("INSERT INTO crm_leads
                        (name, phone, email, id_number, source, interested_in, stage,
                         assigned_to, pinned_car_id, notes, follow_up_date, created_at)
                        VALUES (?,?,?,?,?,?, 'new', ?,?,?, CURDATE(), NOW())")
                   ->execute([$fullName, $phone, $email ?: null, $idNo ?: null,
                              'Walk-in', $interest ?: null, $assignee ?: null, $carId,
                              trim("Walked in on " . date('j M Y')
                                   . ($heard ? ". Heard of us via: {$heard}" : '')
                                   . ($comment ? ".\n\nVisitor's comment: {$comment}" : '.'))]);
                $leadId = (int)$db->lastInsertId();

            } elseif ($purpose === 'car_service') {
                // Service work is booked against a client, so the client record
                // is what a service visit has to produce. Matched on phone first
                // so a returning customer does not get a second record.
                $ex = $db->prepare("SELECT id FROM clients WHERE phone = ? LIMIT 1");
                $ex->execute([$phone]);
                $clientId = (int)($ex->fetchColumn() ?: 0);

                if (!$clientId) {
                    $db->prepare("INSERT INTO clients (name, phone, email, id_number, status, notes, created_at)
                                  VALUES (?,?,?,?, 'active', ?, NOW())")
                       ->execute([$fullName, $phone, $email ?: null, $idNo ?: null,
                                  'Registered from the visitors book on ' . date('j M Y')
                                  . ($heard ? '. Heard of us via: ' . $heard : '')]);
                    $clientId = (int)$db->lastInsertId();
                } else {
                    // Fill in anything reception has now that we did not have.
                    $db->prepare("UPDATE clients
                                  SET email = COALESCE(NULLIF(email,''), ?),
                                      id_number = COALESCE(NULLIF(id_number,''), ?)
                                  WHERE id = ?")
                       ->execute([$email ?: null, $idNo ?: null, $clientId]);
                }

                // The vehicle itself, so the service booking has something to
                // attach to. Matched on registration to avoid duplicates.
                $vex = $db->prepare("SELECT id FROM cars WHERE registration_number = ? AND registration_number <> '' LIMIT 1");
                $vex->execute([$svc['reg']]);
                if (!$vex->fetchColumn()) {
                    try {
                        $db->prepare("INSERT INTO cars
                                (make, model, year, registration_number, mileage, car_type, status,
                                 show_on_website, owner_name, owner_phone, client_id, created_at)
                                VALUES (?,?,?,?,?, 'client', 'available', 0, ?,?,?, NOW())")
                           ->execute([$svc['make'], $svc['model'], $svc['year'] ?: null, $svc['reg'],
                                      $svc['mileage'] ?: null, $fullName, $phone, $clientId]);
                    } catch (\Throwable $_) { /* a client vehicle is a bonus, not the point */ }
                }
            }

            // ── The visit itself ─────────────────────────────────────────────
            $db->prepare("INSERT INTO visitors
                    (first_name, middle_name, last_name, phone, id_number, email, heard_from, purpose,
                     car_id, buy_comment, svc_make, svc_model, svc_year, svc_reg, svc_mileage, svc_notes,
                     staff_id, visit_reason, lead_id, client_id, assigned_to, recorded_by, created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, NOW())")
               ->execute([
                   $first, $middle ?: null, $last ?: null, $phone, $idNo ?: null, $email ?: null,
                   $heard ?: null, $purpose,
                   $purpose === 'buy_car' ? $carId : null,
                   $purpose === 'buy_car' ? ($comment ?: null) : null,
                   $purpose === 'car_service' ? $svc['make'] : null,
                   $purpose === 'car_service' ? $svc['model'] : null,
                   $purpose === 'car_service' ? ($svc['year'] ?: null) : null,
                   $purpose === 'car_service' ? $svc['reg'] : null,
                   $purpose === 'car_service' ? ($svc['mileage'] ?: null) : null,
                   $purpose === 'car_service' ? ($svc['notes'] ?: null) : null,
                   $purpose === 'see_someone' ? $staffId : null,
                   $purpose === 'see_someone' ? $reason : null,
                   $leadId, $clientId, $assignee, $meId,
               ]);
            $visitorId = (int)$db->lastInsertId();

            $db->commit();

            // ── Tell whoever needs to act ────────────────────────────────────
            // After the commit: a notification about a visit that failed to save
            // would send someone to reception for nobody.
            try {
                require_once __DIR__ . '/../includes/notifications.php';
                if ($purpose === 'see_someone' && $staffId) {
                    createNotification($staffId, 'visitor',
                        $fullName . ' is at reception to see you',
                        $reason, BASE_URL . '/modules/visitors/index.php?range=today');
                } elseif ($purpose === 'buy_car' && $assignee) {
                    createNotification($assignee, 'lead',
                        'Walk-in lead: ' . $fullName,
                        'At reception now, interested in a vehicle. Phone ' . $phone,
                        BASE_URL . '/modules/crm/view_lead.php?id=' . $leadId);
                } elseif ($purpose === 'car_service') {
                    notifyRoles(['workshop_manager', 'receptionist'], 'service',
                        'Service walk-in: ' . $fullName,
                        trim($svc['make'] . ' ' . $svc['model'] . ' — ' . $svc['reg']),
                        BASE_URL . '/modules/clients/view.php?id=' . $clientId);
                }
            } catch (\Throwable $_) {}

            try { logActivity('create', 'visitors', $visitorId, 'Visitor signed in: ' . $fullName); }
            catch (\Throwable $_) {}

            // Redirect so a refresh cannot record the same visitor twice.
            redirect(BASE_URL . '/visitorbook/index.php?done=' . $visitorId);

        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('visitorbook: ' . $e->getMessage());
            $errors[] = 'Could not save the visit. Please try again or ask a member of staff.';
        }
    }
}

// ── Confirmation ─────────────────────────────────────────────────────────────
if (!empty($_GET['done'])) {
    $st = $db->prepare("SELECT v.*, u.name AS staff_name, a.name AS officer_name
                        FROM visitors v
                        LEFT JOIN users u ON u.id = v.staff_id
                        LEFT JOIN users a ON a.id = v.assigned_to
                        WHERE v.id = ?");
    $st->execute([(int)$_GET['done']]);
    $done = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$cars  = visitorSelectableCars($db);
$staff = visitorStaffList($db);
$P     = $_POST;

$vbTitle = 'Visitors Book';
require __DIR__ . '/_layout.php';
?>

<?php if ($done): ?>
<div class="vb-card">
    <div class="vb-card-body text-center" style="padding:44px 22px">
        <div style="width:72px;height:72px;border-radius:50%;background:var(--vb-ok-bg);color:var(--vb-ok-fg);
                    display:flex;align-items:center;justify-content:center;font-size:32px;margin:0 auto 20px">
            <i class="fa fa-check"></i>
        </div>
        <h2 style="font-size:22px;font-weight:800;margin:0 0 8px">
            Thank you, <?= htmlspecialchars($done['first_name']) ?>
        </h2>
        <p style="color:var(--vb-ink-2);font-size:15px;margin:0 auto 6px;max-width:460px">
            <?php if ($done['purpose'] === 'see_someone'): ?>
                <?= htmlspecialchars($done['staff_name'] ?: 'The person you came to see') ?>
                has been notified that you are here. Please take a seat.
            <?php elseif ($done['purpose'] === 'buy_car'): ?>
                <?= $done['officer_name']
                    ? htmlspecialchars($done['officer_name']) . ' will be with you shortly'
                    : 'One of our sales team will be with you shortly' ?>
                to talk through the vehicle you selected.
            <?php else: ?>
                Your vehicle details have been recorded. Our service team will be with you shortly.
            <?php endif; ?>
        </p>
        <p style="color:var(--vb-ink-3);font-size:12.5px;margin:0 0 26px">
            Signed in at <?= date('H:i', strtotime($done['created_at'])) ?>
        </p>
        <a href="<?= BASE_URL ?>/visitorbook/index.php" class="vb-submit" style="text-decoration:none;display:inline-block">
            <i class="fa fa-user-plus me-2"></i>Sign in the next visitor
        </a>
    </div>
</div>
<?php else: ?>

<?php if ($errors): ?>
<div class="alert alert-danger" style="border-radius:var(--vb-r)">
    <strong>Please check the following:</strong>
    <ul class="mb-0 mt-1" style="padding-left:20px">
        <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div id="vbIdle" class="vb-hidden alert alert-warning d-flex align-items-center gap-2"
     style="border-radius:var(--vb-r);font-size:14px">
    <i class="fa fa-clock"></i>
    <span>Still there? This form will clear in <strong id="vbIdleCount">15</strong> seconds.</span>
    <?php /* Any interaction re-arms the timer via the document-level listener, so
             this needs no handler of its own — it is here to make that obvious. */ ?>
    <button type="button" class="btn btn-sm btn-warning ms-auto">I'm still here</button>
</div>

<form method="POST" id="vbForm" novalidate>
    <?= csrfField() ?>

    <!-- ── 1. Who ─────────────────────────────────────────────────────── -->
    <div class="vb-card">
        <div class="vb-card-head"><span class="n">1</span>Your details</div>
        <div class="vb-card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">First name <span class="req">*</span></label>
                    <input type="text" name="first_name" class="form-control" required autofocus
                           autocomplete="off" value="<?= htmlspecialchars($P['first_name'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Second name</label>
                    <input type="text" name="middle_name" class="form-control" autocomplete="off"
                           value="<?= htmlspecialchars($P['middle_name'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Last name</label>
                    <input type="text" name="last_name" class="form-control" autocomplete="off"
                           value="<?= htmlspecialchars($P['last_name'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Phone number <span class="req">*</span></label>
                    <input type="tel" name="phone" id="vbPhone" class="form-control" required autocomplete="off"
                           placeholder="07xx xxx xxx" value="<?= htmlspecialchars($P['phone'] ?? '') ?>">
                    <div id="vbKnown" class="vb-hidden" style="font-size:12px;margin-top:6px">
                        <span style="color:var(--vb-ok-fg);font-weight:600">
                            <i class="fa fa-circle-check me-1"></i>You have been here before
                        </span>
                        <button type="button" class="btn btn-sm btn-outline-success mt-1 w-100"
                                id="vbUseKnown" style="font-size:12px;padding:5px 8px">
                            Use my saved details
                        </button>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">ID number</label>
                    <input type="text" name="id_number" class="form-control" autocomplete="off"
                           value="<?= htmlspecialchars($P['id_number'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Email address</label>
                    <input type="email" name="email" class="form-control" autocomplete="off"
                           placeholder="name@example.com" value="<?= htmlspecialchars($P['email'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Where did you hear about us?</label>
                    <select name="heard_from" class="form-select">
                        <option value="">Please choose…</option>
                        <?php foreach (visitorHeardFrom() as $h): ?>
                        <option value="<?= htmlspecialchars($h) ?>"
                            <?= ($P['heard_from'] ?? '') === $h ? 'selected' : '' ?>><?= htmlspecialchars($h) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- ── 2. Why ─────────────────────────────────────────────────────── -->
    <div class="vb-card">
        <div class="vb-card-head"><span class="n">2</span>Purpose of your visit</div>
        <div class="vb-card-body">
            <div class="vb-purposes">
                <?php foreach (visitorPurposes() as $k => [$label, $icon, $colour]): ?>
                <label class="vb-purpose">
                    <input type="radio" name="purpose" value="<?= $k ?>" class="vb-purpose-radio"
                           <?= ($P['purpose'] ?? '') === $k ? 'checked' : '' ?>>
                    <div>
                        <i class="fa <?= $icon ?>"></i>
                        <span><?= htmlspecialchars($label) ?></span>
                    </div>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ── 3a. Buying a car ───────────────────────────────────────────── -->
    <div class="vb-card vb-section vb-hidden" data-for="buy_car">
        <div class="vb-card-head"><span class="n">3</span>Which vehicle interests you?</div>
        <div class="vb-card-body">
            <?php if (!$cars): ?>
            <p class="text-muted mb-0" style="font-size:14px">
                We have no vehicles listed at the moment — a member of our sales team will
                talk you through what is coming in.
            </p>
            <?php else: ?>
            <p class="text-muted" style="font-size:13px;margin-bottom:14px">
                Tap a vehicle to select it.
            </p>
            <div class="vb-cars">
                <?php foreach ($cars as $c):
                    $price = visitorCarPrice($c);
                    $bits  = array_filter([
                        $c['body_type'] ?: null,
                        $c['transmission'] ? ucfirst($c['transmission']) : null,
                        $c['fuel_type'] ? ucfirst($c['fuel_type']) : null,
                        !empty($c['mileage']) ? number_format((int)$c['mileage']) . ' km' : null,
                    ]);
                ?>
                <label class="vb-car">
                    <input type="radio" name="car_id" value="<?= (int)$c['id'] ?>"
                           <?= (int)($P['car_id'] ?? 0) === (int)$c['id'] ? 'checked' : '' ?>>
                    <div>
                        <div class="vb-car-img">
                            <?php if ($c['primary_image']): ?>
                            <img src="<?= htmlspecialchars(thumbUrl('cars', $c['primary_image'])) ?>"
                                 alt="<?= htmlspecialchars($c['make'] . ' ' . $c['model']) ?>" loading="lazy">
                            <?php else: ?>
                            <div class="vb-car-noimg"><i class="fa fa-car-side"></i></div>
                            <?php endif; ?>
                            <div class="vb-car-tick"><i class="fa fa-check"></i></div>
                        </div>
                        <div class="vb-car-b">
                            <div class="vb-car-t"><?= htmlspecialchars(trim($c['year'] . ' ' . $c['make'] . ' ' . $c['model'])) ?></div>
                            <?php if ($bits): ?>
                            <div class="vb-car-m"><?= htmlspecialchars(implode(' · ', $bits)) ?></div>
                            <?php endif; ?>
                            <div class="vb-car-p"><?= $price ? 'KES ' . number_format($price) : 'Ask us' ?></div>
                        </div>
                    </div>
                </label>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="mt-4">
                <label class="form-label">Anything you would like us to know?</label>
                <textarea name="buy_comment" class="form-control" rows="3"
                          placeholder="e.g. looking for something economical, trading in my current car, budget around…"><?= htmlspecialchars($P['buy_comment'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <!-- ── 3b. Car service ────────────────────────────────────────────── -->
    <div class="vb-card vb-section vb-hidden" data-for="car_service">
        <div class="vb-card-head"><span class="n">3</span>Vehicle to be serviced</div>
        <div class="vb-card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Make <span class="req">*</span></label>
                    <input type="text" name="svc_make" class="form-control" placeholder="e.g. Toyota"
                           value="<?= htmlspecialchars($P['svc_make'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Model <span class="req">*</span></label>
                    <input type="text" name="svc_model" class="form-control" placeholder="e.g. Prado"
                           value="<?= htmlspecialchars($P['svc_model'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Registration number <span class="req">*</span></label>
                    <input type="text" name="svc_reg" class="form-control" placeholder="e.g. KDA 123A"
                           style="text-transform:uppercase"
                           value="<?= htmlspecialchars($P['svc_reg'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Year</label>
                    <input type="text" name="svc_year" class="form-control" placeholder="e.g. 2018"
                           value="<?= htmlspecialchars($P['svc_year'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Current mileage (km)</label>
                    <input type="number" name="svc_mileage" class="form-control" min="0"
                           value="<?= htmlspecialchars($P['svc_mileage'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">What needs doing?</label>
                    <textarea name="svc_notes" class="form-control" rows="3"
                              placeholder="e.g. routine service, brakes squeaking, warning light on the dash"><?= htmlspecialchars($P['svc_notes'] ?? '') ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <!-- ── 3c. Seeing someone ─────────────────────────────────────────── -->
    <div class="vb-card vb-section vb-hidden" data-for="see_someone">
        <div class="vb-card-head"><span class="n">3</span>Who are you here to see?</div>
        <div class="vb-card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Member of staff <span class="req">*</span></label>
                    <select name="staff_id" class="form-select">
                        <option value="">Please choose…</option>
                        <?php foreach ($staff as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" data-in="<?= (int)$s['in_today'] ?>"
                            <?= (int)($P['staff_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['name']) ?>
                            <?= $s['role'] ? ' — ' . htmlspecialchars(ucwords(str_replace('_', ' ', $s['role']))) : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php /* A warning, never a block: someone can be on site without
                             having opened the system today. */ ?>
                    <div id="vbStaffWarn" class="vb-hidden"
                         style="font-size:12px;color:var(--vb-warn-fg);margin-top:6px">
                        <i class="fa fa-triangle-exclamation me-1"></i>
                        This person has not used the system today — they may not be in.
                        Reception will check for you.
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label">Reason for your visit <span class="req">*</span></label>
                    <textarea name="visit_reason" class="form-control" rows="3"
                              placeholder="Briefly, what is the visit about?"><?= htmlspecialchars($P['visit_reason'] ?? '') ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="text-center mt-2">
        <button type="submit" class="vb-submit" id="vbSubmit">
            <i class="fa fa-check me-2"></i>Sign in
        </button>
        <div class="text-muted mt-2" style="font-size:12px" id="vbHint">
            Choose the purpose of your visit to continue.
        </div>
        <div class="mt-4 pt-3" style="border-top:1px solid var(--vb-line)">
            <a href="<?= BASE_URL ?>/visitorbook/checkout.php"
               style="font-size:13.5px;text-decoration:none;color:var(--vb-ink-2)">
                <i class="fa fa-right-from-bracket me-1"></i>Leaving? Sign out here
            </a>
        </div>
    </div>
</form>

<script>
(function () {
    'use strict';
    var form     = document.getElementById('vbForm');
    var sections = Array.prototype.slice.call(document.querySelectorAll('.vb-section'));
    var hint     = document.getElementById('vbHint');

    function chosen() {
        var r = form.querySelector('input[name="purpose"]:checked');
        return r ? r.value : '';
    }

    function sync() {
        var p = chosen();
        sections.forEach(function (s) {
            var on = (s.dataset.for === p);
            s.classList.toggle('vb-hidden', !on);
            // A hidden section's inputs are disabled so the browser cannot ask
            // the visitor to fill in a required field they cannot even see, and
            // so a half-completed answer for one purpose is not submitted with
            // another. Disabled fields are not posted at all.
            s.querySelectorAll('input, select, textarea').forEach(function (el) {
                el.disabled = !on;
            });
        });
        if (hint) hint.style.display = p ? 'none' : '';
    }

    form.addEventListener('change', function (e) {
        if (e.target.name === 'purpose') {
            sync();
            var open = document.querySelector('.vb-section:not(.vb-hidden)');
            if (open) open.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    });

    form.addEventListener('submit', function (e) {
        if (!chosen()) {
            e.preventDefault();
            if (hint) { hint.style.display = ''; hint.style.color = '#dc2626'; }
            var pc = document.querySelector('.vb-purposes');
            if (pc) pc.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        // Guard against a double tap on a slow connection creating two visitors.
        var b = document.getElementById('vbSubmit');
        b.disabled = true;
        b.innerHTML = '<i class="fa fa-spinner fa-spin me-2"></i>Signing in…';
    });

    // ── Returning visitor ───────────────────────────────────────────────────
    // Typing a full number offers the details given last time. Offered, not
    // applied: reception confirms it is the same person before anything is
    // filled in, so one shared phone does not silently sign in the wrong name.
    var phone = document.getElementById('vbPhone');
    var known = document.getElementById('vbKnown');
    var useBtn = document.getElementById('vbUseKnown');
    var pending = null, lookupTimer = null;

    function digits(s) { return String(s || '').replace(/\D+/g, ''); }

    function lookup() {
        var d = digits(phone.value);
        if (d.length < 9) { known.classList.add('vb-hidden'); pending = null; return; }
        fetch('<?= BASE_URL ?>/visitorbook/api/lookup.php?phone=' + encodeURIComponent(d),
              { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (!j || !j.found) { known.classList.add('vb-hidden'); pending = null; return; }
                pending = j.fields;
                known.classList.remove('vb-hidden');
            })
            .catch(function () { /* offline or blocked — typing by hand still works */ });
    }

    if (phone) {
        phone.addEventListener('input', function () {
            clearTimeout(lookupTimer);
            lookupTimer = setTimeout(lookup, 400);
        });
    }
    if (useBtn) {
        useBtn.addEventListener('click', function () {
            if (!pending) return;
            Object.keys(pending).forEach(function (k) {
                var el = form.querySelector('[name="' + k + '"]');
                if (el && pending[k]) el.value = pending[k];
            });
            known.classList.add('vb-hidden');
            var fn = form.querySelector('[name="first_name"]');
            if (fn) fn.focus();
        });
    }

    // ── Staff availability ──────────────────────────────────────────────────
    var staffSel = form.querySelector('select[name="staff_id"]');
    var staffWarn = document.getElementById('vbStaffWarn');
    if (staffSel && staffWarn) {
        staffSel.addEventListener('change', function () {
            var o = staffSel.options[staffSel.selectedIndex];
            var away = o && o.value && o.dataset.in === '0';
            staffWarn.classList.toggle('vb-hidden', !away);
        });
    }

    // ── Idle reset ──────────────────────────────────────────────────────────
    // This screen stands in a public place. Someone half-fills it, is called
    // away, and their name, phone and ID number sit there for the next visitor to
    // read. So an untouched form clears itself — with a visible warning first, so
    // nobody loses what they were typing without being told.
    var IDLE = <?= (int)VISITOR_KIOSK_IDLE ?> * 1000;
    var WARN = 15000;
    var idleTimer = null, warnTimer = null, tick = null;
    var banner = document.getElementById('vbIdle');
    var countEl = document.getElementById('vbIdleCount');

    function typedAnything() {
        var els = form.querySelectorAll('input[type="text"], input[type="tel"], input[type="email"], input[type="number"], textarea');
        for (var i = 0; i < els.length; i++) if (els[i].value.trim() !== '') return true;
        return !!chosen();
    }

    function hideBanner() { if (banner) banner.classList.add('vb-hidden'); clearInterval(tick); }

    function resetForm() {
        hideBanner();
        form.reset();
        known.classList.add('vb-hidden');
        pending = null;
        if (staffWarn) staffWarn.classList.add('vb-hidden');
        sync();
        window.scrollTo({ top: 0, behavior: 'smooth' });
        var fn = form.querySelector('[name="first_name"]');
        if (fn) fn.focus();
    }

    function showWarning() {
        if (!typedAnything()) { armIdle(); return; }   // nothing to protect
        if (!banner) { resetForm(); return; }
        var left = Math.round(WARN / 1000);
        if (countEl) countEl.textContent = left;
        banner.classList.remove('vb-hidden');
        clearInterval(tick);
        tick = setInterval(function () {
            left -= 1;
            if (countEl) countEl.textContent = Math.max(0, left);
            if (left <= 0) clearInterval(tick);
        }, 1000);
        warnTimer = setTimeout(resetForm, WARN);
    }

    function armIdle() {
        clearTimeout(idleTimer); clearTimeout(warnTimer); hideBanner();
        idleTimer = setTimeout(showWarning, IDLE);
    }

    ['click', 'keydown', 'input', 'touchstart', 'change'].forEach(function (ev) {
        document.addEventListener(ev, armIdle, { passive: true });
    });
    armIdle();

    sync();
}());
</script>
<?php endif; ?>

<?php vbFooter(); ?>
