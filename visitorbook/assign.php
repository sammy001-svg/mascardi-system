<?php
/**
 * Visitors Book — who will attend to this customer.
 *
 * Sits between the sign-in form and the thank-you screen, for car buyers only.
 * Reception can see who is actually free — on the floor, off the phone, not
 * already walking someone round the yard — which no rota can know, so they get
 * first say.
 *
 * Skipping is a first-class outcome, not a failure. Tap Skip, or simply leave it,
 * and the rotation takes over. A busy desk should never have to make this choice
 * to get a customer signed in, and an unattended tablet must not strand somebody
 * on a staff-facing screen: the countdown allocates and moves on by itself.
 *
 * Nothing here is what guarantees ownership. visitorAssignOfficer() only ever
 * fills an empty assignment, and visitorSweepUnassigned() catches anything this
 * page never finished, so a closed tablet cannot leave a lead with no owner.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../modules/visitors/_bootstrap.php';
requireLogin();

$db = getDB();
visitorsMigrate($db);

$me      = authUser();
$isKiosk = authRole() === 'visitor_book';
if (!$isKiosk && !canAccess('visitors')) {
    redirect(BASE_URL . '/index.php');
}

$vid = (int)($_GET['v'] ?? $_POST['visitor_id'] ?? 0);
$st  = $db->prepare("SELECT * FROM visitors WHERE id = ?");
$st->execute([$vid]);
$visit = $st->fetch(PDO::FETCH_ASSOC);

// Only a car buyer reaches this step, and only while still unallocated.
if (!$visit || $visit['purpose'] !== 'buy_car') {
    redirect(BASE_URL . '/visitorbook/index.php');
}
if (!empty($visit['assigned_to'])) {
    redirect(BASE_URL . '/visitorbook/index.php?done=' . $vid);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // No officer id means Skip (or the countdown firing) — same path, and the
    // rotation decides. Nothing to validate: visitorAssignOfficer() checks the
    // choice against the offered list itself.
    $picked = (int)($_POST['officer_id'] ?? 0) ?: null;
    $res    = visitorAssignOfficer($db, $vid, $picked);

    if (!empty($res['ok'])) {
        // Only the call that actually made the assignment notifies, so a tap
        // racing the sweep cannot produce two messages to the same officer.
        if (!empty($res['assigned'])) {
            try {
                require_once __DIR__ . '/../includes/notifications.php';
                createNotification((int)$res['officer_id'], 'lead',
                    'Walk-in lead: ' . visitorFullName($visit),
                    'At reception now, interested in a vehicle. Phone ' . $visit['phone'],
                    BASE_URL . '/modules/crm/view_lead.php?id=' . (int)$visit['lead_id']);
            } catch (\Throwable $_) {}

            header('Location: ' . BASE_URL . '/visitorbook/index.php?done=' . $vid);
            $__vid = $vid;
            visitorFlushThenSend(function () use ($db, $__vid) {
                visitorSendAllocationEmails($db, $__vid);
            });
            exit;
        }
        redirect(BASE_URL . '/visitorbook/index.php?done=' . $vid);
    }

    setFlash('error', 'Could not allocate this visitor. Please try again, or ask a manager.');
    redirect(BASE_URL . '/visitorbook/index.php?done=' . $vid);
}

$officers = visitorAvailableOfficers($db, (int)($visit['location_id'] ?? 0) ?: null);

// Nobody to choose from at all — skip the screen rather than show an empty one.
// The sweep will still pick this up if a candidate appears later.
if (!$officers) {
    visitorAssignOfficer($db, $vid, null);
    redirect(BASE_URL . '/visitorbook/index.php?done=' . $vid);
}

$carLabel = '';
if (!empty($visit['car_id'])) {
    try {
        $c = $db->prepare("SELECT TRIM(CONCAT_WS(' ', year, make, model)) FROM cars WHERE id = ?");
        $c->execute([(int)$visit['car_id']]);
        $carLabel = (string)$c->fetchColumn();
    } catch (\Throwable $_) {}
}

$vbTitle = 'Who will attend to this customer?';
require __DIR__ . '/_layout.php';
?>

<div class="vb-card">
    <div class="vb-card-head">
        <span class="n"><i class="fa fa-user-check"></i></span>
        Who will attend to <?= htmlspecialchars($visit['first_name']) ?>?
    </div>
    <div class="vb-card-body">
        <p style="color:var(--vb-ink-2);font-size:14.5px;margin:0 0 4px">
            <strong><?= htmlspecialchars(visitorFullName($visit)) ?></strong>
            <?= $carLabel !== '' ? ' &middot; interested in ' . htmlspecialchars($carLabel) : '' ?>
        </p>
        <p style="color:var(--vb-ink-3);font-size:13px;margin:0 0 18px">
            Choose whoever is free right now. If you skip this, the next officer in
            the rotation is allocated automatically.
        </p>

        <form method="POST" id="vbAssignForm">
            <?= csrfField() ?>
            <input type="hidden" name="visitor_id" value="<?= (int)$vid ?>">

            <div class="vb-offs">
                <?php foreach ($officers as $i => $o):
                    $initials = '';
                    $parts = preg_split('/\s+/', trim((string)$o['name']));
                    if ($parts) {
                        $initials = strtoupper(substr($parts[0], 0, 1)
                                  . (count($parts) > 1 ? substr(end($parts), 0, 1) : ''));
                    }
                ?>
                <label class="vb-off">
                    <input type="radio" name="officer_id" value="<?= (int)$o['id'] ?>">
                    <div>
                        <?php if ($i === 0): ?>
                        <span class="vb-off-next">Next in rotation</span>
                        <?php endif; ?>
                        <div class="vb-off-pic">
                            <?php if (!empty($o['profile_image'])): ?>
                            <img src="<?= BASE_URL ?>/uploads/profiles/<?= htmlspecialchars($o['profile_image']) ?>"
                                 alt="" loading="lazy">
                            <?php else: ?>
                            <span><?= htmlspecialchars($initials ?: '?') ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="vb-off-name"><?= htmlspecialchars($o['name']) ?></div>
                        <div class="vb-off-meta">
                            <?php if ((int)$o['in_today'] === 1): ?>
                            <span class="vb-off-on"><i class="fa fa-circle"></i>In today</span>
                            <?php else: ?>
                            <span class="vb-off-off"><i class="fa fa-circle"></i>Not seen today</span>
                            <?php endif; ?>
                        </div>
                        <div class="vb-off-load">
                            <?= (int)$o['today_walkins'] ?> today &middot; <?= (int)$o['open_leads'] ?> open
                        </div>
                    </div>
                </label>
                <?php endforeach; ?>
            </div>

            <div class="mt-4 d-flex align-items-center gap-3 flex-wrap">
                <button type="submit" class="vb-submit" id="vbAssignGo" disabled>
                    <i class="fa fa-check me-2"></i>Allocate
                </button>
                <?php /* Same form, no officer chosen — the server reads that as skip. */ ?>
                <button type="submit" class="btn btn-outline-secondary" id="vbSkip"
                        formnovalidate style="border-radius:10px;padding:13px 22px;font-size:14px">
                    Skip &mdash; allocate automatically
                </button>
                <span class="text-muted" style="font-size:12.5px" id="vbAssignHint">
                    Allocating automatically in <strong id="vbAssignCount">30</strong>s
                </span>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    'use strict';
    var form  = document.getElementById('vbAssignForm');
    var go    = document.getElementById('vbAssignGo');
    var skip  = document.getElementById('vbSkip');
    var hint  = document.getElementById('vbAssignHint');
    var count = document.getElementById('vbAssignCount');

    // Allocate is meaningless until somebody is picked.
    form.addEventListener('change', function (e) {
        if (e.target.name === 'officer_id') { go.disabled = false; stop(); }
    });

    // The countdown is the "skipped" path for an unattended desk: a customer must
    // never be left looking at a staff screen because nobody pressed anything.
    var left = 30, timer = null;
    function stop() {
        if (timer) { clearInterval(timer); timer = null; }
        if (hint) hint.style.display = 'none';
    }
    timer = setInterval(function () {
        left -= 1;
        if (count) count.textContent = left;
        if (left <= 0) { stop(); skip.click(); }
    }, 1000);

    // Any interaction means someone is there and deciding — stop hurrying them.
    ['keydown', 'touchstart', 'mousedown'].forEach(function (ev) {
        document.addEventListener(ev, function () { stop(); }, { passive: true, once: true });
    });

    form.addEventListener('submit', function () {
        stop();
        go.disabled = true; skip.disabled = true;
        go.innerHTML = '<i class="fa fa-spinner fa-spin me-2"></i>Allocating…';
    });
}());
</script>

<?php vbFooter(); ?>
