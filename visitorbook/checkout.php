<?php
/**
 * Visitors Book — signing out.
 *
 * The visitor types their phone number and is checked out. It works this way,
 * rather than by picking a name from a list, because the kiosk faces the public:
 * showing everyone currently in the building to whoever is standing at the desk
 * would leak the very thing the book is meant to keep track of. Reception can
 * still check people out by name from modules/visitors, which is staff-only.
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

$error = '';
$done  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim($_POST['phone'] ?? '');
    if ($phone === '') {
        $error = 'Enter the phone number you signed in with.';
    } else {
        $open = visitorOpenVisitByPhone($db, $phone);
        if (!$open) {
            $error = 'We could not find anyone signed in today with that number. '
                   . 'Please check it, or ask a member of staff.';
        } elseif (visitorCheckOut($db, (int)$open['id'], (int)$me['id'])) {
            redirect(BASE_URL . '/visitorbook/checkout.php?out=' . (int)$open['id']);
        } else {
            $error = 'That visit has already been signed out. Thank you.';
        }
    }
}

if (!empty($_GET['out'])) {
    $st = $db->prepare("SELECT * FROM visitors WHERE id = ? AND checked_out_at IS NOT NULL");
    $st->execute([(int)$_GET['out']]);
    $done = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$vbTitle = 'Signing out';
require __DIR__ . '/_layout.php';
?>

<?php if ($done): ?>
<div class="vb-card">
    <div class="vb-card-body text-center" style="padding:44px 22px">
        <div style="width:72px;height:72px;border-radius:50%;background:var(--vb-info-bg);color:var(--vb-info-fg);
                    display:flex;align-items:center;justify-content:center;font-size:30px;margin:0 auto 20px">
            <i class="fa fa-hand-peace"></i>
        </div>
        <h2 style="font-size:22px;font-weight:800;margin:0 0 8px">
            Goodbye, <?= htmlspecialchars($done['first_name']) ?>
        </h2>
        <p style="color:var(--vb-ink-2);font-size:15px;margin:0 auto 4px;max-width:430px">
            You have been signed out. Thank you for visiting.
        </p>
        <p style="color:var(--vb-ink-3);font-size:12.5px;margin:0 0 26px">
            In at <?= date('H:i', strtotime($done['created_at'])) ?>,
            out at <?= date('H:i', strtotime($done['checked_out_at'])) ?>
        </p>
        <a href="<?= BASE_URL ?>/visitorbook/index.php" class="vb-submit"
           style="text-decoration:none;display:inline-block">
            <i class="fa fa-house me-2"></i>Back to the visitors book
        </a>
    </div>
</div>
<script>setTimeout(function(){ location.href = '<?= BASE_URL ?>/visitorbook/index.php'; }, 12000);</script>
<?php else: ?>

<div class="vb-card">
    <div class="vb-card-head"><i class="fa fa-right-from-bracket"></i>Signing out</div>
    <div class="vb-card-body">
        <?php if ($error): ?>
        <div class="alert alert-warning" style="border-radius:10px;font-size:14px"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <p style="color:var(--vb-ink-2);font-size:14.5px;margin:0 0 18px">
            Leaving? Enter the phone number you signed in with and we will sign you out.
        </p>

        <form method="POST" style="max-width:380px">
            <?= csrfField() ?>
            <label class="form-label">Phone number</label>
            <div class="d-flex gap-2">
                <input type="tel" name="phone" class="form-control" required autofocus
                       autocomplete="off" placeholder="07xx xxx xxx"
                       value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                <button type="submit" class="vb-submit" style="padding:11px 22px;white-space:nowrap">
                    Sign out
                </button>
            </div>
        </form>

        <div class="mt-4 pt-3" style="border-top:1px solid var(--vb-line)">
            <a href="<?= BASE_URL ?>/visitorbook/index.php" style="font-size:13.5px;text-decoration:none">
                <i class="fa fa-arrow-left me-1"></i>Back to signing in
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<?php vbFooter(); ?>
