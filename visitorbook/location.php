<?php
/**
 * Visitors Book — sign the kiosk in to a location.
 *
 * Staff answer this once when they open up, before the book is handed to
 * visitors. Everything signed in afterwards is recorded against that branch, and
 * a walk-in wanting to buy a car is allocated to a customer relations officer
 * there — a visitor who walks into one branch should not be chased by someone in
 * another town.
 *
 * The answer is held in the session, not on the account, because the same login
 * can be carried between desks and the location belongs to the sitting. A copy is
 * kept on the account purely to pre-select the choice next time.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../modules/visitors/_bootstrap.php';
requireLogin();

$db = getDB();
visitorsMigrate($db);

$me      = authUser();
$meId    = (int)$me['id'];
$isKiosk = authRole() === 'visitor_book';
if (!$isKiosk && !canAccess('visitors')) {
    redirect(BASE_URL . '/index.php');
}

$locations = visitorLocations($db);
$error     = '';

// No locations configured. Nothing to choose, and blocking reception over it
// would be worse than recording visitors without a branch — so step aside.
if (!$locations) {
    $_SESSION['vb_location_id'] = 0;
    redirect(BASE_URL . '/visitorbook/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $picked = (int)($_POST['location_id'] ?? 0);
    $valid  = false;
    foreach ($locations as $l) if ((int)$l['id'] === $picked) { $valid = true; break; }

    if (!$valid) {
        $error = 'Choose the location this desk is at.';
    } else {
        $_SESSION['vb_location_id'] = $picked;
        // Remembered on the account so tomorrow it is one tap to confirm rather
        // than a decision to make again.
        try {
            $db->prepare("UPDATE users SET location_id = ? WHERE id = ?")->execute([$picked, $meId]);
        } catch (\Throwable $_) {}
        setFlash('success', 'Signed in at ' . visitorLocationName($db, $picked)
               . '. Visitors recorded from now on will be logged against this location.');
        redirect(BASE_URL . '/visitorbook/index.php');
    }
}

// Pre-select: whatever this sitting already chose, else what the account used last.
$current = visitorSessionLocation() ?: (int)($me['location_id'] ?? 0);

$vbTitle = 'Choose your location';
require __DIR__ . '/_layout.php';
?>

<div class="vb-card">
    <div class="vb-card-head"><i class="fa fa-location-dot"></i>Which desk is this?</div>
    <div class="vb-card-body">
        <?php if ($error): ?>
        <div class="alert alert-warning" style="border-radius:10px;font-size:14px"><?= e($error) ?></div>
        <?php endif; ?>

        <p style="color:var(--vb-ink-2);font-size:14.5px;margin:0 0 18px">
            Sign in to the location you are working from. Every visitor recorded afterwards is
            logged against it, and anyone wanting to buy a car is passed to a customer relations
            officer here.
        </p>

        <form method="POST">
            <?= csrfField() ?>
            <div class="vb-locs">
                <?php foreach ($locations as $l):
                    $icon = ['yard' => 'fa-warehouse', 'showroom' => 'fa-store',
                             'port' => 'fa-anchor',    'office'   => 'fa-building'][$l['type']] ?? 'fa-location-dot';
                ?>
                <label class="vb-loc">
                    <input type="radio" name="location_id" value="<?= (int)$l['id'] ?>"
                           <?= $current === (int)$l['id'] ? 'checked' : '' ?>>
                    <div>
                        <i class="fa <?= $icon ?>"></i>
                        <div class="vb-loc-n"><?= e($l['name']) ?></div>
                        <?php if ($l['parent_name'] !== '' || $l['type']): ?>
                        <div class="vb-loc-m">
                            <?= e(trim(($l['parent_name'] !== '' ? $l['parent_name'] . ' · ' : '')
                                     . ucfirst((string)$l['type']), ' ·')) ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($l['address'])): ?>
                        <div class="vb-loc-m"><?= e(mb_strimwidth((string)$l['address'], 0, 58, '…')) ?></div>
                        <?php endif; ?>
                    </div>
                </label>
                <?php endforeach; ?>
            </div>

            <div class="mt-4 d-flex align-items-center gap-3 flex-wrap">
                <button type="submit" class="vb-submit">
                    <i class="fa fa-check me-2"></i>Save and open the visitors book
                </button>
                <span class="text-muted" style="font-size:12px">
                    You can change this later from the header.
                </span>
            </div>
        </form>
    </div>
</div>

<?php vbFooter(); ?>
