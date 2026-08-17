<?php
/**
 * Visitors Book — standalone page shell.
 *
 * Deliberately NOT includes/header.php. That shell exists to render the staff
 * chrome — sidebar, topbar, notification bell, chat launcher — and this screen
 * must have none of it: it sits in reception in front of members of the public,
 * so there must be nothing on it to click into the business. Building it on the
 * staff header and hiding pieces would leave the markup (and the links) one CSS
 * rule away from being visible again.
 *
 * Set $vbTitle before including. Call vbFooter() at the end of the page.
 */

if (!defined('VB_LAYOUT')) {
    define('VB_LAYOUT', true);

    $vbCompany = getSetting('company_name', 'Mascardi');
    $vbLogo    = getSetting('company_logo', '');
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title><?= htmlspecialchars(($vbTitle ?? 'Visitors Book') . ' — ' . $vbCompany) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<style>
:root{
    --vb-ink:#0f172a; --vb-ink-2:#475569; --vb-ink-3:#94a3b8;
    --vb-line:#e2e8f0; --vb-bg:#f1f5f9; --vb-card:#ffffff;
    --vb-brand:#7e22ce; --vb-brand-soft:#faf5ff; --vb-brand-line:#e9d5ff;
    --vb-r:12px;
}
*{ box-sizing:border-box; }
body{
    margin:0; background:var(--vb-bg); color:var(--vb-ink);
    font-family:"Segoe UI",system-ui,-apple-system,sans-serif;
    -webkit-text-size-adjust:100%;
}
.vb-top{
    background:#fff; border-bottom:1px solid var(--vb-line);
    padding:18px 0; position:sticky; top:0; z-index:20;
}
.vb-wrap{ max-width:960px; margin:0 auto; padding:0 20px; }
.vb-top-in{ display:flex; align-items:center; justify-content:space-between; gap:16px; }
.vb-brandline{ display:flex; align-items:center; gap:13px; min-width:0; }
.vb-brandline img{ height:38px; width:auto; }
.vb-h1{ font-size:clamp(20px,3vw,27px); font-weight:800; letter-spacing:-.5px; margin:0; line-height:1.15; }
.vb-sub{ font-size:12.5px; color:var(--vb-ink-3); margin:2px 0 0; }

.vb-body{ padding:26px 0 70px; }
.vb-card{
    background:var(--vb-card); border:1px solid var(--vb-line);
    border-radius:var(--vb-r); margin-bottom:18px; overflow:hidden;
}
.vb-card-head{
    padding:14px 18px; border-bottom:1px solid var(--vb-line);
    background:#fbfcfe; display:flex; align-items:center; gap:9px;
    font-size:13px; font-weight:700; letter-spacing:.02em;
}
.vb-card-head i{ color:var(--vb-brand); }
.vb-card-head .n{
    width:22px; height:22px; border-radius:50%; background:var(--vb-brand); color:#fff;
    display:inline-flex; align-items:center; justify-content:center;
    font-size:11.5px; font-weight:800; flex:0 0 22px;
}
.vb-card-body{ padding:18px; }

.form-label{ font-size:12.5px; font-weight:600; color:var(--vb-ink-2); margin-bottom:5px; }
.form-control,.form-select{ font-size:15px; padding:11px 13px; border-color:var(--vb-line); border-radius:9px; }
.form-control:focus,.form-select:focus{ border-color:var(--vb-brand); box-shadow:0 0 0 3px rgba(126,34,206,.12); }
.form-control::placeholder{ color:var(--vb-ink-3); }
.req{ color:#dc2626; }

/* Purpose chooser — big targets, because this is used standing up at a counter. */
.vb-purposes{ display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
@media(max-width:720px){ .vb-purposes{ grid-template-columns:1fr; } }
.vb-purpose input{ position:absolute; opacity:0; width:0; height:0; }
.vb-purpose > div{
    border:2px solid var(--vb-line); border-radius:var(--vb-r); padding:20px 16px;
    text-align:center; cursor:pointer; transition:.14s; height:100%;
}
.vb-purpose i{ font-size:26px; display:block; margin-bottom:10px; color:var(--vb-ink-3); }
.vb-purpose span{ font-size:14px; font-weight:700; }
.vb-purpose > div:hover{ border-color:var(--vb-ink-3); }
.vb-purpose input:checked + div{ border-color:var(--vb-brand); background:var(--vb-brand-soft); }
.vb-purpose input:checked + div i{ color:var(--vb-brand); }
.vb-purpose input:focus-visible + div{ outline:3px solid rgba(126,34,206,.35); outline-offset:2px; }

/* Car picker */
.vb-cars{ display:grid; grid-template-columns:repeat(auto-fill,minmax(216px,1fr)); gap:14px; }
.vb-car input{ position:absolute; opacity:0; width:0; height:0; }
.vb-car > div{
    border:2px solid var(--vb-line); border-radius:var(--vb-r); overflow:hidden;
    cursor:pointer; transition:.14s; background:#fff; height:100%;
    display:flex; flex-direction:column;
}
.vb-car > div:hover{ border-color:var(--vb-ink-3); }
.vb-car input:checked + div{ border-color:var(--vb-brand); box-shadow:0 0 0 3px rgba(126,34,206,.14); }
.vb-car input:focus-visible + div{ outline:3px solid rgba(126,34,206,.35); outline-offset:2px; }
.vb-car-img{ aspect-ratio:4/3; background:var(--vb-bg); position:relative; }
.vb-car-img img{ width:100%; height:100%; object-fit:cover; display:block; }
.vb-car-noimg{
    position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
    color:var(--vb-ink-3); font-size:26px;
}
.vb-car-tick{
    position:absolute; top:9px; right:9px; width:26px; height:26px; border-radius:50%;
    background:var(--vb-brand); color:#fff; display:none;
    align-items:center; justify-content:center; font-size:12px;
}
.vb-car input:checked + div .vb-car-tick{ display:flex; }
.vb-car-b{ padding:12px 13px 14px; flex:1; display:flex; flex-direction:column; }
.vb-car-t{ font-size:13.5px; font-weight:700; line-height:1.3; }
.vb-car-m{ font-size:11.5px; color:var(--vb-ink-3); margin-top:3px; }
.vb-car-p{ font-size:14px; font-weight:800; color:var(--vb-brand); margin-top:auto; padding-top:9px; }

.vb-submit{
    background:var(--vb-brand); border:0; color:#fff; border-radius:10px;
    padding:15px 34px; font-size:15.5px; font-weight:700; cursor:pointer; transition:.14s;
}
.vb-submit:hover{ background:#6b21a8; }
.vb-submit:disabled{ opacity:.55; cursor:not-allowed; }
.vb-foot{ text-align:center; font-size:11.5px; color:var(--vb-ink-3); padding:0 0 30px; }
.vb-hidden{ display:none !important; }
</style>
</head>
<body>

<header class="vb-top">
    <div class="vb-wrap vb-top-in">
        <div class="vb-brandline">
            <?php if ($vbLogo): ?>
            <img src="<?= htmlspecialchars(BASE_URL . '/uploads/' . ltrim($vbLogo, '/')) ?>"
                 alt="<?= htmlspecialchars($vbCompany) ?>">
            <?php endif; ?>
            <div style="min-width:0">
                <h1 class="vb-h1">Visitors Book</h1>
                <p class="vb-sub"><?= htmlspecialchars($vbCompany) ?> &middot; please sign in below</p>
            </div>
        </div>
        <?php /* Confirmed, because a visitor tapping this would sign the kiosk
                 out and leave the next arrival unable to sign in at all. */ ?>
        <a href="<?= BASE_URL ?>/logout.php" class="btn btn-sm btn-outline-secondary"
           title="Close the visitors book (staff only)"
           onclick="return confirm('Close the visitors book?\n\nThis signs the reception account out and staff will need to sign back in before the next visitor can be recorded.');">
            <i class="fa fa-lock"></i>
            <span class="d-none d-sm-inline ms-1">Staff exit</span>
        </a>
    </div>
</header>

<main class="vb-body">
    <div class="vb-wrap">
<?php
}

function vbFooter(): void
{
    ?>
    </div>
</main>
<div class="vb-foot">
    Your details are held only so we can assist you with your visit.
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
}
