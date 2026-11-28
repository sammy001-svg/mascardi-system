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
   it does not keep -- and named only. Each of these used to carry a sentence
   explaining it, which is six sentences nobody reads on the way to a password
   box. A label and an icon say the same thing in a glance. */
$services = [
    ['fa-car-side',     'Your vehicles'],
    ['fa-file-invoice', 'Invoices & quotes'],
    ['fa-folder-open',  'Documents'],
    ['fa-calendar',     'Service bookings'],
    ['fa-list-check',   'Assessments'],
    ['fa-bell',         'Notices'],
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
/* ── Dark, with the house red as the light source ────────────────────────────
   One surface colour and one accent. The red is the red sampled out of the
   artwork; everything that glows is that same red at a different strength, so
   the page reads as lit rather than decorated. */
:root{
    --bg:#08080a;            /* the room */
    --panel:#0e0e11;         /* a surface in it */
    --panel-2:#131317;
    --edge:rgba(255,255,255,.08);
    --txt:#f2f2f3;
    --txt-2:rgba(242,242,243,.68);
    --txt-3:rgba(242,242,243,.42);
    --brand:#7A0502;         /* sampled from the house artwork */
    --neon:#ff2a1f;          /* the same hue, lit */
    --neon-soft:rgba(255,42,31,.42);
    --r:4px;
    --ease:cubic-bezier(.22,.61,.36,1);
}
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{
    font-family:'Inter',system-ui,-apple-system,sans-serif;
    color:var(--txt);background:var(--bg);
    -webkit-font-smoothing:antialiased;
}
a{color:inherit}
::selection{background:var(--neon-soft);color:#fff}

.cl-wrap{min-height:100vh;min-height:100svh;display:grid;grid-template-columns:1fr 1fr}

/* ── Left ────────────────────────────────────────────────────────────────── */
.cl-show{
    position:relative;overflow:hidden;
    display:flex;flex-direction:column;padding:44px 48px 38px;
    background:var(--bg);
}
/* Two lamps rather than a flat fill: one behind the wordmark, one low and
   right, so the panel has a direction to its light. */
.cl-show::before{
    content:'';position:absolute;inset:0;pointer-events:none;
    background:
        radial-gradient(760px 420px at 6% -8%,  rgba(122,5,2,.60), transparent 64%),
        radial-gradient(620px 520px at 104% 108%, rgba(255,42,31,.16), transparent 62%);
}
/* A hairline of neon down the seam between the halves. */
.cl-show::after{
    content:'';position:absolute;top:0;right:0;width:1px;height:100%;
    background:linear-gradient(180deg,transparent,var(--neon-soft) 28%,var(--neon-soft) 72%,transparent);
    opacity:.5;
}
.cl-show > *{position:relative;z-index:1}
/* The wordmark, the welcome and the labels sit together at the top; the rail
   is anchored to the bottom. One gap between them rather than two, which is
   what the panel had when the copy was long enough to fill it. */
.cl-mark{height:22px;width:auto;display:block}

/* The pair of them centred between the wordmark and the rail: with the copy
   this short, letting the space fall entirely in one place left a hole. */
.cl-lead{margin-top:auto;padding:0 0 26px;max-width:520px}
.cl-eyebrow{
    display:inline-flex;align-items:center;gap:8px;
    font-size:10.5px;font-weight:600;letter-spacing:.22em;text-transform:uppercase;
    color:var(--txt-3);margin-bottom:18px;
}
.cl-eyebrow::before{
    content:'';width:5px;height:5px;border-radius:50%;background:var(--neon);
    box-shadow:0 0 8px var(--neon),0 0 18px var(--neon-soft);
}
.cl-lead h1{
    font-size:clamp(30px,3vw,44px);font-weight:300;line-height:1.1;
    letter-spacing:-.015em;
}

/* The six things, as labels. */
.cl-services{display:flex;flex-wrap:wrap;gap:9px;padding:0 0 34px;max-width:560px;margin-bottom:auto}
.cl-svc{
    display:inline-flex;align-items:center;gap:9px;
    padding:8px 14px 8px 11px;border-radius:999px;
    background:rgba(255,255,255,.035);border:1px solid var(--edge);
    font-size:12.5px;font-weight:500;color:var(--txt-2);
    transition:border-color .3s var(--ease),color .3s var(--ease),background .3s var(--ease);
}
.cl-svc i{font-size:11.5px;color:var(--neon);opacity:.9}
.cl-svc:hover{border-color:var(--neon-soft);color:var(--txt);background:rgba(255,42,31,.07)}

/* ── The carousel ────────────────────────────────────────────────────────────
   Perspective on the frame and depth on the cards, so the rail reads as
   something standing in a room rather than three rectangles in a row. The
   centre of each page faces you; its neighbours turn away and sit back.
   The neon is a rim light on the glass, brightest on the card in front. */
.cl-carousel{margin-top:auto}
.cl-car-head{display:flex;align-items:baseline;justify-content:space-between;gap:16px;margin-bottom:16px}
.cl-car-head .h{font-size:10.5px;font-weight:600;letter-spacing:.22em;text-transform:uppercase;color:var(--txt-3)}
.cl-car-head a{font-size:12px;color:var(--txt-2);text-decoration:none;border-bottom:1px solid var(--edge);padding-bottom:2px;transition:color .25s,border-color .25s}
.cl-car-head a:hover{color:var(--txt);border-color:var(--neon)}

.cl-vp{overflow:hidden;perspective:1500px;perspective-origin:50% 50%;padding:14px 0 4px}
.cl-track{display:flex;gap:18px;transform-style:preserve-3d;transition:transform .8s var(--ease);will-change:transform}
.cl-slide{
    flex:0 0 calc((100% - 36px) / 3);min-width:0;
    position:relative;border-radius:var(--r);overflow:hidden;text-decoration:none;color:var(--txt);
    background:linear-gradient(160deg,var(--panel-2),var(--panel));
    border:1px solid var(--edge);
    transform-style:preserve-3d;
    /* Set per card by the script; the defaults keep it sane without it. */
    transform:rotateY(var(--ry,0deg)) rotateX(var(--rx,0deg)) translateZ(var(--tz,0px)) scale(var(--sc,1));
    transition:transform .8s var(--ease),box-shadow .45s var(--ease),border-color .45s var(--ease),opacity .6s var(--ease);
    box-shadow:0 12px 30px rgba(0,0,0,.55);
    opacity:var(--op,1);
}
/* The rim light. A thin inner line plus an outer bloom, both the same hue. */
.cl-slide.lit{
    border-color:rgba(255,42,31,.34);
    box-shadow:
        0 0 0 1px rgba(255,42,31,.20),
        0 0 22px rgba(255,42,31,.20),
        0 18px 44px rgba(0,0,0,.6),
        inset 0 1px 0 rgba(255,255,255,.05);
}
.cl-slide:hover{
    border-color:rgba(255,42,31,.70);
    box-shadow:
        0 0 0 1px rgba(255,42,31,.45),
        0 0 30px rgba(255,42,31,.40),
        0 0 70px rgba(255,42,31,.16),
        0 22px 52px rgba(0,0,0,.65),
        inset 0 1px 0 rgba(255,255,255,.08);
}
/* A filament along the top edge, lit on the card in front. */
.cl-slide::after{
    content:'';position:absolute;top:0;left:12%;right:12%;height:1px;
    background:linear-gradient(90deg,transparent,var(--neon),transparent);
    opacity:0;transition:opacity .45s var(--ease);
}
.cl-slide.lit::after{opacity:.55}
.cl-slide:hover::after{opacity:1}

.cl-slide .ph{position:relative;aspect-ratio:16/10;overflow:hidden;background:#000}
.cl-slide .ph img{width:100%;height:100%;object-fit:cover;transition:transform 1s var(--ease);opacity:.92}
.cl-slide:hover .ph img{transform:scale(1.06);opacity:1}
.cl-slide .ph::after{
    content:'';position:absolute;inset:0;
    background:linear-gradient(180deg,transparent 48%,rgba(8,8,10,.85));
}
.cl-slide .tag{
    position:absolute;top:9px;left:9px;z-index:2;font-size:8.5px;font-weight:700;
    letter-spacing:.16em;text-transform:uppercase;padding:4px 9px;border-radius:2px;
    color:#fff;background:rgba(122,5,2,.82);
    border:1px solid rgba(255,42,31,.45);
    box-shadow:0 0 14px rgba(255,42,31,.35);
}
.cl-slide .bd{padding:12px 14px 15px;position:relative;z-index:2}
.cl-slide .nm{
    font-size:12.5px;font-weight:600;line-height:1.35;margin-bottom:6px;color:var(--txt);
    display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;
}
.cl-slide .pr{font-size:14px;font-weight:300;color:#fff;letter-spacing:.01em}
.cl-slide .pr small{font-size:9.5px;color:var(--txt-3);font-weight:600;letter-spacing:.1em}

.cl-dots{display:flex;gap:7px;margin-top:18px}
.cl-dots button{
    width:20px;height:2px;border:none;padding:0;cursor:pointer;
    background:rgba(255,255,255,.16);transition:background .35s var(--ease),box-shadow .35s var(--ease);
}
.cl-dots button.on{background:var(--neon);box-shadow:0 0 10px var(--neon),0 0 22px var(--neon-soft)}

@media (prefers-reduced-motion: reduce){
    .cl-track,.cl-slide,.cl-slide .ph img{transition:none}
    .cl-slide{transform:none}
}

/* ── Right: the form, on the same dark ───────────────────────────────────── */
.cl-form-side{
    display:flex;align-items:center;justify-content:center;
    padding:48px 40px;background:var(--panel);position:relative;overflow:hidden;
}
.cl-form-side::before{
    content:'';position:absolute;inset:0;pointer-events:none;
    background:radial-gradient(620px 420px at 50% 0%, rgba(122,5,2,.22), transparent 66%);
}
.cl-card{width:100%;max-width:392px;position:relative;z-index:1}
.cl-back{
    display:inline-flex;align-items:center;gap:8px;font-size:10.5px;font-weight:600;
    letter-spacing:.16em;text-transform:uppercase;color:var(--txt-3);
    text-decoration:none;margin-bottom:34px;transition:color .25s var(--ease);
}
.cl-back:hover{color:var(--txt)}
.cl-card h2{font-size:26px;font-weight:400;letter-spacing:-.015em;margin-bottom:6px}
.cl-card .sub{font-size:13px;color:var(--txt-3);line-height:1.6;margin-bottom:26px}

.cl-tabs{display:flex;border-bottom:1px solid var(--edge);margin-bottom:26px}
.cl-tabs a{
    flex:1;text-align:center;padding:11px 4px;font-size:11px;font-weight:600;
    letter-spacing:.14em;text-transform:uppercase;color:var(--txt-3);
    text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-1px;
    transition:color .25s var(--ease),border-color .25s var(--ease);
}
.cl-tabs a:hover{color:var(--txt-2)}
.cl-tabs a.on{
    color:var(--txt);border-bottom-color:var(--neon);
    text-shadow:0 0 18px var(--neon-soft);
}

.cl-field{margin-bottom:15px}
.cl-field label{
    display:block;font-size:10px;font-weight:600;letter-spacing:.16em;
    text-transform:uppercase;color:var(--txt-3);margin-bottom:7px;
}
.cl-field input{
    width:100%;min-width:0;border:1px solid var(--edge);border-radius:var(--r);
    padding:12px 14px;font-size:14px;font-family:inherit;color:var(--txt);
    background:rgba(255,255,255,.035);outline:none;
    transition:border-color .25s var(--ease),box-shadow .25s var(--ease),background .25s var(--ease);
}
.cl-field input:focus{
    border-color:rgba(255,42,31,.55);background:rgba(255,255,255,.055);
    box-shadow:0 0 0 3px rgba(255,42,31,.12),0 0 22px rgba(255,42,31,.14);
}
.cl-field input::placeholder{color:rgba(242,242,243,.26)}
/* The browser's own autofill paints its own background; this keeps it dark. */
.cl-field input:-webkit-autofill,
.cl-field input:-webkit-autofill:focus{
    -webkit-text-fill-color:var(--txt);
    -webkit-box-shadow:0 0 0 1000px #16161a inset,0 0 0 3px rgba(255,42,31,.12);
    caret-color:var(--txt);
}
.cl-pw{position:relative}
.cl-pw input{padding-right:46px}
.cl-pw button{
    position:absolute;right:4px;top:50%;transform:translateY(-50%);
    border:none;background:none;cursor:pointer;color:var(--txt-3);
    padding:8px 10px;font-size:13px;line-height:1;transition:color .2s var(--ease);
}
.cl-pw button:hover{color:var(--neon)}
.cl-row{display:grid;grid-template-columns:1fr 1fr;gap:15px}
.cl-row > *{min-width:0}

.cl-submit{
    position:relative;width:100%;border:1px solid rgba(255,42,31,.5);
    background:linear-gradient(135deg,#8d0703,var(--brand));
    color:#fff;border-radius:var(--r);padding:13px 20px;
    font-size:11px;font-weight:700;letter-spacing:.18em;text-transform:uppercase;
    cursor:pointer;font-family:inherit;margin-top:8px;
    box-shadow:0 0 0 1px rgba(255,42,31,.18),0 0 24px rgba(255,42,31,.22),0 10px 26px rgba(0,0,0,.5);
    transition:box-shadow .3s var(--ease),transform .15s var(--ease),filter .3s var(--ease);
}
.cl-submit:hover{
    filter:brightness(1.12);
    box-shadow:0 0 0 1px rgba(255,42,31,.4),0 0 34px rgba(255,42,31,.42),0 12px 30px rgba(0,0,0,.55);
}
.cl-submit:active{transform:translateY(1px)}

.cl-note,.cl-error{
    font-size:12.5px;line-height:1.6;padding:11px 14px;border-radius:var(--r);
    margin-bottom:18px;border:1px solid;
}
.cl-error{background:rgba(255,42,31,.1);border-color:rgba(255,42,31,.32);color:#ffb4ae}
.cl-note{background:rgba(32,200,140,.08);border-color:rgba(32,200,140,.28);color:#8ee9c4}
.cl-aside{font-size:12.5px;color:var(--txt-3);line-height:1.7;margin-top:20px;text-align:center}
.cl-aside a{color:var(--txt);font-weight:600;text-decoration:none;border-bottom:1px solid var(--edge)}
.cl-aside a:hover{border-color:var(--neon)}
.cl-help{
    margin-top:28px;padding-top:20px;border-top:1px solid var(--edge);
    font-size:12px;color:var(--txt-3);line-height:1.8;
}
.cl-help a{color:var(--txt-2);text-decoration:none;font-weight:500;transition:color .2s var(--ease)}
.cl-help a:hover{color:var(--neon)}
.cl-contact{display:flex;flex-wrap:wrap;gap:6px 16px;margin-top:6px}
.cl-contact a{display:inline-flex;align-items:center;gap:6px;white-space:nowrap}
.cl-contact i{font-size:11px;color:var(--txt-3)}
.cl-code{letter-spacing:.5em;text-align:center;font-size:21px;font-weight:600;font-variant-numeric:tabular-nums}

/* ── Narrower ─────────────────────────────────────────────────────────────
   The form comes first on a phone: somebody who came here to sign in should
   not have to scroll past the panel to do it. */
@media (max-width: 1100px){
    .cl-slide{flex:0 0 calc((100% - 18px) / 2)}
    .cl-show{padding:34px 34px 30px}
    .cl-form-side{padding:40px 30px}
}
@media (max-width: 860px){
    .cl-wrap{grid-template-columns:1fr}
    .cl-show{order:2;min-height:0}
    .cl-show::after{display:none}
    .cl-form-side{order:1;padding:34px 20px 42px;min-height:0}
    .cl-mark{margin-bottom:26px}
    .cl-lead{padding:0 0 24px}
    .cl-carousel{margin-top:30px}
    .cl-vp{perspective:900px}
}
@media (max-width: 560px){
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

        <?php /* The heading, and nothing under it. The paragraph that used to
                 sit here said what the six labels below it already say. */ ?>
        <div class="cl-lead">
            <div class="cl-eyebrow">Client Area</div>
            <h1>Welcome to your<br>client area.</h1>
        </div>

        <div class="cl-services">
            <?php foreach ($services as [$icon, $title]): ?>
            <span class="cl-svc"><i class="fa <?= e($icon) ?>"></i><?= e($title) ?></span>
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

            <?php /* A line each. The register step keeps its one sentence
                     because it is the one that stops people making a second
                     account beside the one they already have. */ ?>
            <?php if ($pane === 'verify'): ?>
                <h2>Check your email</h2>
                <p class="sub">A 6-digit code is on its way to <strong><?= e($email) ?></strong>.</p>
            <?php elseif ($pane === 'register'): ?>
                <h2>Create your account</h2>
                <p class="sub">Use the address we already have for you and your history comes with it.</p>
            <?php else: ?>
                <h2>Sign in</h2>
                <p class="sub">Your vehicles, invoices and documents.</p>
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
        var firstIndex = Math.min(page * per, slides.length - 1);
        var first = slides[firstIndex];
        // Measured from the rail itself rather than computed from widths, so a
        // rounding difference cannot accumulate across pages.
        var x = first.offsetLeft - track.offsetLeft;
        track.style.transform = 'translateX(' + (-x) + 'px)';
        [].slice.call(dots.children).forEach(function (d, i) {
            d.className = i === page ? 'on' : '';
        });
        depth(firstIndex, per);
    }

    /* ── Depth ────────────────────────────────────────────────────────────
       The rail is in perspective, so the cards can stand at an angle to it.
       Whatever is on the page you are looking at faces you and is lit; the
       cards either side of it turn away and sit back, which is what gives the
       row its depth rather than a drop shadow pretending to.

       The angle is signed by which side of the page a card is on, so the two
       edges lean towards the middle and the row reads as curved. */
    function depth(firstIndex, per) {
        var lastIndex = firstIndex + per - 1;
        var mid = (per - 1) / 2;          // the middle seat of the page
        slides.forEach(function (card, i) {
            var onPage = i >= firstIndex && i <= lastIndex;
            var ry, tz;
            if (onPage) {
                // Within the page the row is a shallow curve: the middle card
                // faces you, the ones beside it turn in towards it and sit
                // back a little. This is what makes the rail read as standing
                // in a room; the cards off the page only confirm it.
                var seat = (i - firstIndex) - mid;
                ry = seat * -7;
                tz = -Math.abs(seat) * 22;
            } else {
                var offset = i < firstIndex ? i - firstIndex : i - lastIndex;
                // Clamped: a card eight along should not be turned eight times
                // as far as the one next door.
                var lean = Math.max(-2, Math.min(2, offset));
                ry = lean * -14;
                tz = -90 - Math.abs(lean) * 28;
            }
            card.style.setProperty('--ry', ry.toFixed(2) + 'deg');
            card.style.setProperty('--rx', '0deg');
            card.style.setProperty('--tz', tz.toFixed(0) + 'px');
            card.style.setProperty('--sc', onPage ? 1 : 0.93);
            card.style.setProperty('--op', onPage ? 1 : 0.38);
            card.classList.toggle('lit', onPage);
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

    /* The card under the pointer leans towards it. Small -- six degrees at the
       corner -- because the point is that the card has a surface, not that it
       is a toy. Pointer events only, so a finger scrolling past a card on a
       phone does not leave it stuck at an angle. */
    if (!reduce && window.matchMedia && window.matchMedia('(hover: hover)').matches) {
        slides.forEach(function (card) {
            card.addEventListener('mousemove', function (e) {
                var r = card.getBoundingClientRect();
                var dx = (e.clientX - r.left) / r.width  - 0.5;
                var dy = (e.clientY - r.top)  / r.height - 0.5;
                card.style.setProperty('--ry', (dx * 12).toFixed(2) + 'deg');
                card.style.setProperty('--rx', (-dy * 8).toFixed(2) + 'deg');
                card.style.setProperty('--tz', '26px');
            });
            card.addEventListener('mouseleave', function () {
                card.style.setProperty('--rx', '0deg');
                render();            // back to wherever the page says it belongs
            });
        });
    }

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
