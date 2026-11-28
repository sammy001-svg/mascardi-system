<?php
/**
 * The customer's door.
 *
 * Staff sign in at /login.php, which is a different job for a different person:
 * they know the system, they arrive every morning, and the page they want is a
 * username box and nothing else. A customer arrives perhaps twice a year, is
 * not sure an account exists for them, and has no idea what is behind the door.
 * So this page spends its left-hand side answering "what is in here and why
 * would I go in", and keeps the form on the right to one job at a time.
 *
 * The engine is includes/client_auth.php and is shared with nothing else here:
 * this file decides what is shown, never what is true. Three panes, because
 * the sign-up is in two steps -- details, then a code to the address on file --
 * and a pane that is not the one being used is not on the page at all.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/images.php';
require_once __DIR__ . '/../includes/client_auth.php';
require_once __DIR__ . '/../modules/cars/_slug.php';

$db = getDB();

// Somebody already signed in has no business here.
if (!empty($_SESSION['_client'])) {
    header('Location: ' . BASE_URL . '/client/index.php');
    exit;
}

$portalOn = clientPortalEnabled();
$signupOn = clientPortalSelfSignup();

// The door is shut. Say so here rather than showing a form that cannot work.
if (!$portalOn) {
    header('Location: ' . BASE_URL . '/showroom/contact.php');
    exit;
}

$pane  = in_array(($_GET['pane'] ?? ''), ['signin', 'register'], true) ? $_GET['pane'] : 'signin';
$error = '';
$note  = '';
$email = '';
$name  = '';
$phone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do    = (string)($_POST['do'] ?? '');
    $email = trim((string)($_POST['email'] ?? ''));
    $name  = trim((string)($_POST['name'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));

    if ($do === 'signin') {
        $r = clientPortalLogin($db, $email, (string)($_POST['password'] ?? ''));
        if ($r['ok']) {
            clientPortalSignIn($r['client']);
            header('Location: ' . BASE_URL . '/client/index.php');
            exit;
        }
        $pane  = 'signin';
        $error = $r['error'];

    } elseif ($do === 'register' || $do === 'resend') {
        $pass  = (string)($_POST['password'] ?? '');
        $pass2 = (string)($_POST['password_confirm'] ?? '');

        if ($do === 'register' && $pass !== $pass2) {
            $pane  = 'register';
            $error = 'The two passwords do not match.';
        } else {
            $r = clientPortalStart($db, $name, $email, $phone, $pass);
            if (!$r['ok']) {
                $pane  = 'register';
                $error = $r['error'];
            } else {
                $pane  = 'verify';
                $email = $r['email'];
                // Worded the same way whether or not that address is on file.
                // A stranger must not be able to learn who banks here by
                // watching which addresses get a different answer.
                $note  = 'If we can reach that address, a 6-digit code is on its way.';
                if (!$r['sent'] && ($r['error'] ?? '') !== '') {
                    $error = 'We could not send the code just now. Please call us and we will '
                           . 'set your access up by hand.';
                }
            }
        }

    } elseif ($do === 'verify') {
        $r = clientPortalVerify($db, $email, (string)($_POST['code'] ?? ''));
        if ($r['ok']) {
            clientPortalSignIn($r['client']);
            header('Location: ' . BASE_URL . '/client/index.php');
            exit;
        }
        $pane  = 'verify';
        $error = $r['error'];
    }
}

/* ── What is behind the door ─────────────────────────────────────────────────
   Named after the pages the portal actually has, so nothing here is a promise
   the portal does not keep. */
$services = [
    ['fa-car-side',      'Your vehicles',      'Every car you have bought from us, with its papers and its history.'],
    ['fa-file-invoice',  'Invoices & quotes',  'What is owed, what is paid, and a receipt for each of them.'],
    ['fa-folder-open',   'Documents',          'Logbook, transfer papers and import records, kept where you can reach them.'],
    ['fa-calendar',      'Service bookings',   'Book the workshop and see what was done last time.'],
    ['fa-list-check',    'Assessments',        'What we found when we looked over the car, in writing.'],
    ['fa-bell',          'Notices',            'Anything we need to tell you, rather than hoping a text gets through.'],
];

/* ── The latest cars, for the carousel ───────────────────────────────────────
   Only published stock with a photograph: a slide with nothing in it is worse
   than one slide fewer. */
$slides = [];
try {
    carSlugMigrate($db);
    $st = $db->query(
        "SELECT c.id, c.make, c.model, c.year, c.asking_price, c.offer_price,
                c.product_id, c.status, c.created_at,
                (SELECT file_path FROM car_images WHERE car_id = c.id AND is_primary = 1 LIMIT 1) AS primary_image
           FROM cars c
          WHERE c.show_on_website = 1
            AND c.car_type IN ('inventory','sale_on_behalf')
            AND (c.status IS NULL OR c.status NOT IN ('delivered','sold','in_transit'))
            AND EXISTS (SELECT 1 FROM car_images i WHERE i.car_id = c.id)
          ORDER BY c.created_at DESC, c.id DESC
          LIMIT 8"
    );
    $slides = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    $slides = [];
}

$companyName = getSetting('company_name', 'Mascardi');
$brandWord   = trim(explode(' ', $companyName)[0] ?: 'Mascardi');
$wmV         = (int) @filemtime(BASE_PATH . '/assets/images/brand/wordmark-light.png');
$iconV       = (int) @filemtime(BASE_PATH . '/assets/images/icons/favicon.ico');
$waClean     = preg_replace('/\D+/', '', (string)getSetting('whatsapp_number', ''));
$coPhone     = trim((string)getSetting('company_phone', ''));
$coEmail     = trim((string)getSetting('company_email', ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Client Area — <?= e($companyName) ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="<?= BASE_URL ?>/assets/images/icons/favicon.ico?v=<?= $iconV ?>" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="<?= BASE_URL ?>/assets/images/icons/icon-32.png?v=<?= $iconV ?>">
<link rel="apple-touch-icon" href="<?= BASE_URL ?>/assets/images/icons/apple-touch-icon.png?v=<?= $iconV ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{
    --ink:#121212; --ink-2:#4a4a4a; --ink-3:#8a8a8a;
    --line:#e6e6e4; --paper:#f7f7f5; --white:#fff;
    --brand:#7A0502;                       /* sampled from the house artwork */
    --r:3px;
    --ease:cubic-bezier(.22,.61,.36,1);
}
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{
    font-family:'Inter',system-ui,-apple-system,sans-serif;
    color:var(--ink);background:var(--paper);
    -webkit-font-smoothing:antialiased;
}
a{color:inherit}

/* ── The two halves ───────────────────────────────────────────────────────
   A grid rather than a flex row, so the two sides are exactly half each and
   neither can be pushed about by the length of its own contents. */
.cl-wrap{min-height:100vh;min-height:100svh;display:grid;grid-template-columns:1fr 1fr}

/* ── Left: what is in here ───────────────────────────────────────────────── */
.cl-show{
    position:relative;background:var(--ink);color:#fff;overflow:hidden;
    display:flex;flex-direction:column;padding:42px 48px 36px;
}
/* A slow wash of the house red, so the panel is not a flat black rectangle. */
.cl-show::before{
    content:'';position:absolute;inset:0;pointer-events:none;
    background:
        radial-gradient(900px 500px at 12% -10%, rgba(122,5,2,.55), transparent 62%),
        radial-gradient(700px 600px at 108% 110%, rgba(122,5,2,.32), transparent 60%);
}
.cl-show > *{position:relative;z-index:1}
.cl-mark{height:22px;width:auto;display:block;margin-bottom:auto}
.cl-lead{padding:40px 0 30px;max-width:520px}
.cl-eyebrow{
    font-size:11px;font-weight:600;letter-spacing:.2em;text-transform:uppercase;
    color:rgba(255,255,255,.62);margin-bottom:16px;
}
.cl-lead h1{
    font-size:clamp(28px,2.7vw,40px);font-weight:300;line-height:1.14;
    letter-spacing:-.01em;margin-bottom:16px;
}
.cl-lead p{font-size:15px;line-height:1.75;color:rgba(255,255,255,.72);max-width:430px}

.cl-services{
    display:grid;grid-template-columns:1fr 1fr;gap:18px 26px;
    padding:4px 0 34px;max-width:560px;
}
.cl-svc{display:flex;gap:12px;align-items:flex-start}
.cl-svc i{
    flex-shrink:0;width:30px;height:30px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;font-size:12px;
    background:rgba(255,255,255,.1);color:#fff;
    border:1px solid rgba(255,255,255,.16);
}
.cl-svc .t{font-size:13.5px;font-weight:600;margin-bottom:3px}
.cl-svc .d{font-size:12px;line-height:1.55;color:rgba(255,255,255,.58)}

/* ── The carousel ─────────────────────────────────────────────────────────
   A sliding track rather than a fade, because a fade between two photographs
   of two different cars reads as a glitch. One rail, translated. */
.cl-carousel{margin-top:auto}
.cl-car-head{
    display:flex;align-items:baseline;justify-content:space-between;gap:16px;margin-bottom:14px;
}
.cl-car-head .h{font-size:11px;font-weight:600;letter-spacing:.2em;text-transform:uppercase;color:rgba(255,255,255,.62)}
.cl-car-head a{font-size:12px;color:rgba(255,255,255,.72);text-decoration:none;border-bottom:1px solid rgba(255,255,255,.25);padding-bottom:2px}
.cl-car-head a:hover{color:#fff;border-color:#fff}
.cl-vp{overflow:hidden}
.cl-track{display:flex;gap:16px;transition:transform .75s var(--ease);will-change:transform}
.cl-slide{
    flex:0 0 calc((100% - 32px) / 3);min-width:0;
    background:rgba(255,255,255,.055);border:1px solid rgba(255,255,255,.1);
    border-radius:var(--r);overflow:hidden;text-decoration:none;color:#fff;
    transition:border-color .3s var(--ease),background .3s var(--ease);
}
.cl-slide:hover{border-color:rgba(255,255,255,.3);background:rgba(255,255,255,.1)}
.cl-slide .ph{position:relative;aspect-ratio:16/10;overflow:hidden;background:#1c1c1c}
.cl-slide .ph img{width:100%;height:100%;object-fit:cover;transition:transform .9s var(--ease)}
.cl-slide:hover .ph img{transform:scale(1.05)}
.cl-slide .tag{
    position:absolute;top:8px;left:8px;font-size:9px;font-weight:600;
    letter-spacing:.14em;text-transform:uppercase;padding:4px 8px;border-radius:2px;
    background:rgba(0,0,0,.6);backdrop-filter:blur(6px);
}
.cl-slide .bd{padding:11px 12px 13px}
.cl-slide .nm{
    font-size:12.5px;font-weight:600;line-height:1.35;margin-bottom:5px;
    display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;
}
.cl-slide .pr{font-size:13px;font-weight:300;color:rgba(255,255,255,.9);letter-spacing:.01em}
.cl-slide .pr small{font-size:10px;color:rgba(255,255,255,.5);font-weight:500}
.cl-dots{display:flex;gap:6px;margin-top:16px}
.cl-dots button{
    width:18px;height:2px;border:none;padding:0;cursor:pointer;
    background:rgba(255,255,255,.22);transition:background .3s var(--ease);
}
.cl-dots button.on{background:#fff}
@media (prefers-reduced-motion: reduce){
    .cl-track{transition:none}
    .cl-slide .ph img{transition:none}
}

/* ── Right: the form ─────────────────────────────────────────────────────── */
.cl-form-side{
    display:flex;align-items:center;justify-content:center;
    padding:48px 40px;background:var(--white);
}
.cl-card{width:100%;max-width:412px}
.cl-back{
    display:inline-flex;align-items:center;gap:8px;font-size:11px;font-weight:600;
    letter-spacing:.14em;text-transform:uppercase;color:var(--ink-3);
    text-decoration:none;margin-bottom:34px;transition:color .2s var(--ease);
}
.cl-back:hover{color:var(--ink)}
.cl-card h2{font-size:25px;font-weight:400;letter-spacing:-.01em;margin-bottom:7px}
.cl-card .sub{font-size:13.5px;color:var(--ink-2);line-height:1.6;margin-bottom:26px}

.cl-tabs{display:flex;gap:0;border-bottom:1px solid var(--line);margin-bottom:26px}
.cl-tabs a{
    flex:1;text-align:center;padding:11px 4px;font-size:12px;font-weight:600;
    letter-spacing:.1em;text-transform:uppercase;color:var(--ink-3);
    text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-1px;
    transition:color .2s var(--ease),border-color .2s var(--ease);
}
.cl-tabs a:hover{color:var(--ink-2)}
.cl-tabs a.on{color:var(--ink);border-bottom-color:var(--brand)}

.cl-field{margin-bottom:16px}
.cl-field label{
    display:block;font-size:10.5px;font-weight:600;letter-spacing:.14em;
    text-transform:uppercase;color:var(--ink-3);margin-bottom:7px;
}
.cl-field input{
    width:100%;min-width:0;border:1px solid var(--line);border-radius:var(--r);
    padding:12px 14px;font-size:14px;font-family:inherit;color:var(--ink);
    background:var(--white);outline:none;transition:border-color .2s var(--ease),box-shadow .2s var(--ease);
}
.cl-field input:focus{border-color:var(--ink);box-shadow:0 0 0 3px rgba(18,18,18,.06)}
.cl-field input::placeholder{color:#b9b9b7}
.cl-pw{position:relative}
.cl-pw input{padding-right:46px}
.cl-pw button{
    position:absolute;right:4px;top:50%;transform:translateY(-50%);
    border:none;background:none;cursor:pointer;color:var(--ink-3);
    padding:8px 10px;font-size:13px;line-height:1;
}
.cl-pw button:hover{color:var(--ink)}
.cl-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.cl-row > *{min-width:0}

.cl-submit{
    width:100%;border:1px solid var(--ink);background:var(--ink);color:#fff;
    border-radius:var(--r);padding:13px 20px;font-size:11.5px;font-weight:600;
    letter-spacing:.16em;text-transform:uppercase;cursor:pointer;font-family:inherit;
    transition:background .25s var(--ease);margin-top:6px;
}
.cl-submit:hover{background:#000}
.cl-note,.cl-error{
    font-size:13px;line-height:1.6;padding:11px 14px;border-radius:var(--r);
    margin-bottom:20px;border:1px solid;
}
.cl-error{background:#fdf2f2;border-color:#f3d2d2;color:#8a2020}
.cl-note{background:#f1f7f4;border-color:#cfe6dc;color:#1f5e49}
.cl-aside{font-size:12.5px;color:var(--ink-3);line-height:1.7;margin-top:22px;text-align:center}
.cl-aside a{color:var(--ink);font-weight:600;text-decoration:none;border-bottom:1px solid var(--line)}
.cl-aside a:hover{border-color:var(--ink)}
.cl-help{
    margin-top:30px;padding-top:22px;border-top:1px solid var(--line);
    font-size:12.5px;color:var(--ink-3);line-height:1.8;
}
.cl-help a{color:var(--ink);text-decoration:none;font-weight:500}
.cl-help a:hover{text-decoration:underline}
.cl-contact{display:flex;flex-wrap:wrap;gap:6px 16px;margin-top:6px}
.cl-contact a{display:inline-flex;align-items:center;gap:6px;white-space:nowrap}
.cl-contact i{font-size:11px;color:var(--ink-3)}
.cl-code{
    letter-spacing:.5em;text-align:center;font-size:21px;font-weight:600;
    font-variant-numeric:tabular-nums;
}

/* ── Narrower ─────────────────────────────────────────────────────────────
   The form comes first on a phone: somebody who came here to sign in should
   not have to scroll past the sales pitch to do it. The panel keeps its
   welcome and its carousel underneath, where it reads as what it is. */
@media (max-width: 1100px){
    .cl-services{grid-template-columns:1fr;gap:14px}
    .cl-slide{flex:0 0 calc((100% - 16px) / 2)}
    .cl-show{padding:34px 34px 30px}
    .cl-form-side{padding:40px 30px}
}
@media (max-width: 860px){
    .cl-wrap{grid-template-columns:1fr}
    .cl-show{order:2;min-height:0}
    .cl-form-side{order:1;padding:34px 20px 40px;min-height:0}
    .cl-mark{margin-bottom:26px}
    .cl-lead{padding:0 0 26px}
    .cl-carousel{margin-top:30px}
}
@media (max-width: 560px){
    .cl-slide{flex:0 0 calc((100% - 16px) / 2)}
    .cl-show{padding:30px 18px 28px}
    .cl-row{grid-template-columns:1fr;gap:0}
}
@media (max-width: 400px){
    .cl-slide{flex:0 0 100%}
}
</style>
</head>
<body>

<div class="cl-wrap">

    <!-- ── What is behind the door ─────────────────────────────────────── -->
    <section class="cl-show">
        <a href="<?= BASE_URL ?>/showroom/" aria-label="<?= e($companyName) ?>">
            <img class="cl-mark" src="<?= BASE_URL ?>/assets/images/brand/wordmark-light.png?v=<?= $wmV ?>"
                 width="1400" height="172" alt="<?= e($companyName) ?>">
        </a>

        <div class="cl-lead">
            <div class="cl-eyebrow">Client Area</div>
            <h1>Welcome to your<br>client area.</h1>
            <p>Everything to do with your car in one place &mdash; what you own, what it has
               cost, what has been done to it, and what is due next.</p>
        </div>

        <div class="cl-services">
            <?php foreach ($services as [$icon, $title, $desc]): ?>
            <div class="cl-svc">
                <i class="fa <?= e($icon) ?>"></i>
                <div>
                    <div class="t"><?= e($title) ?></div>
                    <div class="d"><?= e($desc) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($slides): ?>
        <div class="cl-carousel">
            <div class="cl-car-head">
                <div class="h">Latest in the showroom</div>
                <a href="<?= BASE_URL ?>/showroom/vehicles.php">See all stock &rarr;</a>
            </div>
            <div class="cl-vp">
                <div class="cl-track" id="clTrack">
                    <?php foreach ($slides as $s):
                        $isReserved = ($s['status'] ?? '') === 'reserved';
                        $isNew      = !empty($s['created_at']) && strtotime((string)$s['created_at']) > strtotime('-30 days');
                        $ask        = (float)($s['asking_price'] ?? 0);
                        $offer      = (float)($s['offer_price'] ?? 0);
                        $price      = ($offer > 0 && !$isReserved) ? $offer : $ask;
                        $img        = imgFallbackUrl('cars', (string)$s['primary_image'], 480);
                        $srcset     = imgSrcset('cars', (string)$s['primary_image']);
                    ?>
                    <a class="cl-slide" href="<?= e(carUrl($db, $s)) ?>">
                        <div class="ph">
                            <?php if ($isReserved): ?><span class="tag">Reserved</span>
                            <?php elseif ($isNew): ?><span class="tag">Just in</span><?php endif; ?>
                            <img src="<?= e($img) ?>"
                                 <?php if ($srcset !== ''): ?>srcset="<?= e($srcset) ?>" sizes="240px"<?php endif; ?>
                                 width="480" height="300" loading="lazy" decoding="async"
                                 alt="<?= e(trim($s['make'] . ' ' . $s['model'])) ?>">
                        </div>
                        <div class="bd">
                            <div class="nm"><?= e(trim(($s['year'] ? $s['year'] . ' ' : '') . $s['make'] . ' ' . $s['model'])) ?></div>
                            <div class="pr">
                                <?php if ($price > 0): ?>
                                    <small>KES</small> <?= number_format($price) ?>
                                <?php else: ?>
                                    <small>Price on request</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="cl-dots" id="clDots" aria-label="Choose a page of vehicles"></div>
        </div>
        <?php endif; ?>
    </section>

    <!-- ── The form ────────────────────────────────────────────────────── -->
    <section class="cl-form-side">
        <div class="cl-card">
            <a class="cl-back" href="<?= BASE_URL ?>/showroom/">
                <i class="fa fa-arrow-left"></i> Back to the showroom
            </a>

            <?php if ($pane === 'verify'): ?>
                <h2>Check your email</h2>
                <p class="sub">We sent a 6-digit code to <strong><?= e($email) ?></strong>.
                   It is good for a few minutes.</p>
            <?php elseif ($pane === 'register'): ?>
                <h2>Create your account</h2>
                <p class="sub">If you have bought or serviced a car with us, use the address
                   we already have for you and your history will be waiting.</p>
            <?php else: ?>
                <h2>Sign in</h2>
                <p class="sub">Your vehicles, invoices, documents and bookings.</p>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="cl-error"><i class="fa fa-circle-exclamation me-1"></i> <?= e($error) ?></div>
            <?php endif; ?>
            <?php if ($note !== ''): ?>
                <div class="cl-note"><i class="fa fa-envelope-circle-check me-1"></i> <?= e($note) ?></div>
            <?php endif; ?>

            <?php if ($pane !== 'verify' && $signupOn): ?>
            <div class="cl-tabs">
                <a href="?pane=signin"   class="<?= $pane === 'signin'   ? 'on' : '' ?>">Sign in</a>
                <a href="?pane=register" class="<?= $pane === 'register' ? 'on' : '' ?>">Register</a>
            </div>
            <?php endif; ?>

            <?php if ($pane === 'signin'): ?>
            <form method="POST" autocomplete="on">
                <input type="hidden" name="do" value="signin">
                <div class="cl-field">
                    <label for="clEmail">Email address</label>
                    <input id="clEmail" type="email" name="email" required autofocus
                           autocomplete="email" value="<?= e($email) ?>" placeholder="you@example.com">
                </div>
                <div class="cl-field">
                    <label for="clPass">Password</label>
                    <div class="cl-pw">
                        <input id="clPass" type="password" name="password" required
                               autocomplete="current-password" placeholder="Your password">
                        <button type="button" class="js-eye" data-for="clPass" aria-label="Show password">
                            <i class="fa fa-eye"></i>
                        </button>
                    </div>
                </div>
                <button class="cl-submit" type="submit">Sign in to my account</button>
            </form>
            <?php if ($signupOn): ?>
            <div class="cl-aside">
                First time here? <a href="?pane=register">Create an account</a>
            </div>
            <?php endif; ?>

            <?php elseif ($pane === 'register'): ?>
            <form method="POST" autocomplete="on">
                <input type="hidden" name="do" value="register">
                <div class="cl-field">
                    <label for="clName">Full name</label>
                    <input id="clName" type="text" name="name" required autofocus
                           autocomplete="name" value="<?= e($name) ?>" placeholder="e.g. John Kamau">
                </div>
                <div class="cl-row">
                    <div class="cl-field">
                        <label for="clRegEmail">Email address</label>
                        <input id="clRegEmail" type="email" name="email" required
                               autocomplete="email" value="<?= e($email) ?>" placeholder="you@example.com">
                    </div>
                    <div class="cl-field">
                        <label for="clPhone">Phone</label>
                        <input id="clPhone" type="tel" name="phone"
                               autocomplete="tel" value="<?= e($phone) ?>" placeholder="07XX XXX XXX">
                    </div>
                </div>
                <div class="cl-field">
                    <label for="clRegPass">Password <span style="text-transform:none;letter-spacing:0;font-weight:500">(at least 8 characters)</span></label>
                    <div class="cl-pw">
                        <input id="clRegPass" type="password" name="password" required minlength="8"
                               autocomplete="new-password" placeholder="Choose a password">
                        <button type="button" class="js-eye" data-for="clRegPass" aria-label="Show password">
                            <i class="fa fa-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="cl-field">
                    <label for="clRegPass2">Confirm password</label>
                    <input id="clRegPass2" type="password" name="password_confirm" required minlength="8"
                           autocomplete="new-password" placeholder="Type it again">
                </div>
                <button class="cl-submit" type="submit">Send me a code</button>
            </form>
            <div class="cl-aside">
                Already have an account? <a href="?pane=signin">Sign in</a>
            </div>

            <?php else: /* verify */ ?>
            <form method="POST" autocomplete="off">
                <input type="hidden" name="do" value="verify">
                <input type="hidden" name="email" value="<?= e($email) ?>">
                <div class="cl-field">
                    <label for="clCode">6-digit code</label>
                    <input id="clCode" class="cl-code" type="text" name="code" required autofocus
                           inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="------">
                </div>
                <button class="cl-submit" type="submit">Finish and sign in</button>
            </form>
            <div class="cl-aside">
                Nothing arrived? <a href="?pane=register">Start again</a> and we will send another.
            </div>
            <?php endif; ?>

            <?php /* Somebody who cannot get in has no other way through, and
                     "contact your administrator" means nothing to a customer.
                     These are the details they actually need, and each one is
                     a link rather than a number to copy out by hand. */ ?>
            <div class="cl-help">
                Staff sign in <a href="<?= BASE_URL ?>/login.php">over here</a>.
                <?php if ($coPhone !== '' || $coEmail !== '' || $waClean !== ''): ?>
                <br>Trouble getting in?
                <span class="cl-contact">
                    <?php if ($waClean !== ''): ?>
                    <a href="https://wa.me/<?= e($waClean) ?>" target="_blank" rel="noopener">
                        <i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
                    <?php endif; ?>
                    <?php if ($coPhone !== ''): ?>
                    <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $coPhone)) ?>">
                        <i class="fa fa-phone"></i> <?= e($coPhone) ?></a>
                    <?php endif; ?>
                    <?php if ($coEmail !== ''): ?>
                    <a href="mailto:<?= e($coEmail) ?>">
                        <i class="fa fa-envelope"></i> <?= e($coEmail) ?></a>
                    <?php endif; ?>
                </span>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>

<script>
(function () {
    'use strict';

    /* Show the password. People mistype a password they cannot see, and then
       cannot tell a typo from a forgotten password -- which is when they give
       up and telephone. */
    document.querySelectorAll('.js-eye').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById(btn.getAttribute('data-for'));
            if (!input) return;
            var showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            btn.querySelector('i').className = showing ? 'fa fa-eye' : 'fa fa-eye-slash';
            btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
            input.focus();
        });
    });

    /* ── The carousel ─────────────────────────────────────────────────────
       It moves a page at a time rather than a card, so a card is never cut in
       half at the edge, and a page is however many cards actually fit -- which
       changes with the window, so it is measured rather than assumed.

       It stops while the pointer is on it, while the tab is in the background
       (an animation nobody is watching is just battery), and entirely for
       anybody who has asked their system for less motion. */
    var track = document.getElementById('clTrack');
    var dots  = document.getElementById('clDots');
    if (!track || !track.children.length) return;

    var slides = [].slice.call(track.children);
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var page = 0, pages = 1, timer = null, hovering = false;

    function perPage() {
        var vp = track.parentElement.clientWidth;
        var w  = slides[0].getBoundingClientRect().width;
        var gap = parseFloat(getComputedStyle(track).gap) || 0;
        return Math.max(1, Math.round((vp + gap) / (w + gap)));
    }

    function layout() {
        var per = perPage();
        pages = Math.max(1, Math.ceil(slides.length / per));
        if (page >= pages) page = pages - 1;
        dots.innerHTML = '';
        if (pages > 1) {
            for (var i = 0; i < pages; i++) {
                var b = document.createElement('button');
                b.type = 'button';
                b.setAttribute('aria-label', 'Vehicles, page ' + (i + 1) + ' of ' + pages);
                b.addEventListener('click', (function (n) {
                    return function () { page = n; render(); restart(); };
                }(i)));
                dots.appendChild(b);
            }
        }
        render();
    }

    function render() {
        var per = perPage();
        var first = slides[Math.min(page * per, slides.length - 1)];
        // Measured from the rail itself rather than computed from widths, so a
        // rounding difference cannot accumulate across pages.
        var x = first.offsetLeft - track.offsetLeft;
        track.style.transform = 'translateX(' + (-x) + 'px)';
        [].slice.call(dots.children).forEach(function (d, i) {
            d.className = i === page ? 'on' : '';
        });
    }

    function tick() { page = (page + 1) % pages; render(); }
    function restart() {
        if (timer) clearInterval(timer);
        if (reduce || pages < 2) return;
        timer = setInterval(function () { if (!hovering && !document.hidden) tick(); }, 4200);
    }

    track.addEventListener('mouseenter', function () { hovering = true; });
    track.addEventListener('mouseleave', function () { hovering = false; });
    document.addEventListener('visibilitychange', function () { if (!document.hidden) restart(); });

    var resizeTimer = null;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(layout, 150);
    });

    layout();
    restart();
}());
</script>
</body>
</html>
