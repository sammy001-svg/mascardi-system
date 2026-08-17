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
<html lang="en" data-theme="dark" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<meta name="color-scheme" content="dark light">
<title><?= htmlspecialchars(($vbTitle ?? 'Visitors Book') . ' — ' . $vbCompany) ?></title>
<script>
/* Applied before the stylesheets load so the kiosk never flashes the wrong
   colour on a screen that is on display all day. Dark is the default: the
   attributes are already on <html>, and this only switches to light when someone
   has explicitly asked for it. System preference is deliberately ignored — the
   kiosk's look is a decision about the room it stands in, not about the machine. */
(function () {
    try {
        if (localStorage.getItem('vbTheme') === 'light') {
            document.documentElement.setAttribute('data-theme', 'light');
            document.documentElement.setAttribute('data-bs-theme', 'light');
        }
    } catch (e) { /* private mode — stay on the dark default */ }
}());
</script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<style>
/* Dark is the primary palette, so it lives on bare :root and light is the
   override. Bootstrap 5.3 is themed alongside it through data-bs-theme, which is
   what keeps its alerts, buttons and form controls in step — hand-darkening those
   would drift out of sync with the framework. */
:root{
    --vb-ink:#e8eaed; --vb-ink-2:#9aa4b2; --vb-ink-3:#6b7688;
    --vb-line:#2a3442; --vb-bg:#0d1219; --vb-card:#151c26; --vb-card-2:#1b2431;
    --vb-brand:#a855f7; --vb-brand-soft:#1e1430; --vb-brand-line:#3b2a5c;
    --vb-ok-bg:#132a1c;   --vb-ok-fg:#4ade80;
    --vb-info-bg:#12233d; --vb-info-fg:#60a5fa;
    --vb-warn-fg:#fbbf24;
    --vb-shadow:0 10px 30px rgba(0,0,0,.45);
    --vb-r:12px;
}
:root[data-theme="light"]{
    --vb-ink:#0f172a; --vb-ink-2:#475569; --vb-ink-3:#94a3b8;
    --vb-line:#e2e8f0; --vb-bg:#f1f5f9; --vb-card:#ffffff; --vb-card-2:#fbfcfe;
    --vb-brand:#7e22ce; --vb-brand-soft:#faf5ff; --vb-brand-line:#e9d5ff;
    --vb-ok-bg:#dcfce7;   --vb-ok-fg:#16a34a;
    --vb-info-bg:#dbeafe; --vb-info-fg:#2563eb;
    --vb-warn-fg:#b45309;
    --vb-shadow:0 10px 30px rgba(15,23,42,.10);
}
*{ box-sizing:border-box; }
body{
    margin:0; background:var(--vb-bg); color:var(--vb-ink);
    font-family:"Segoe UI",system-ui,-apple-system,sans-serif;
    -webkit-text-size-adjust:100%;
}
.vb-top{
    background:var(--vb-card); border-bottom:1px solid var(--vb-line);
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
    background:var(--vb-card-2); display:flex; align-items:center; gap:9px;
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
.form-control:focus,.form-select:focus{ border-color:var(--vb-brand); box-shadow:0 0 0 3px color-mix(in srgb, var(--vb-brand) 32%, transparent); }
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
.vb-purpose input:focus-visible + div{ outline:3px solid color-mix(in srgb, var(--vb-brand) 55%, transparent); outline-offset:2px; }

/* Location chooser — the first thing staff see, so the targets are large. */
.vb-locs{ display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:12px; }
.vb-loc input{ position:absolute; opacity:0; width:0; height:0; }
.vb-loc > div{
    border:2px solid var(--vb-line); border-radius:var(--vb-r); padding:18px 16px;
    cursor:pointer; transition:.14s; height:100%;
}
.vb-loc > div:hover{ border-color:var(--vb-ink-3); }
.vb-loc input:checked + div{ border-color:var(--vb-brand); background:var(--vb-brand-soft); }
.vb-loc input:checked + div i{ color:var(--vb-brand); }
.vb-loc input:focus-visible + div{ outline:3px solid color-mix(in srgb, var(--vb-brand) 55%, transparent); outline-offset:2px; }
.vb-loc i{ font-size:22px; color:var(--vb-ink-3); display:block; margin-bottom:10px; }
.vb-loc-n{ font-size:14.5px; font-weight:700; }
.vb-loc-m{ font-size:11.5px; color:var(--vb-ink-3); margin-top:3px; }

/* The location this desk is signed in to, shown in the header on every page. */
.vb-here{
    display:inline-flex; align-items:center; gap:7px; text-decoration:none;
    background:var(--vb-brand-soft); border:1px solid var(--vb-brand-line);
    color:var(--vb-brand); border-radius:20px; padding:5px 13px;
    font-size:12px; font-weight:700; max-width:230px;
}
.vb-here:hover{ border-color:var(--vb-brand); }
.vb-here span{ overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

/* Car filters — few controls, big targets, used standing at a counter. */
.vb-filters{
    display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;
    padding:14px; margin-bottom:16px;
    background:var(--vb-card-2); border:1px solid var(--vb-line); border-radius:var(--vb-r);
}
.vb-filter{ flex:1 1 165px; min-width:0; }
.vb-filter .form-label{ margin-bottom:4px; }
.vb-filter-actions{ flex:0 0 auto; }
@media(max-width:560px){ .vb-filter{ flex:1 1 100%; } }

/* Car picker */
.vb-cars{ display:grid; grid-template-columns:repeat(auto-fill,minmax(216px,1fr)); gap:14px; }

/* Once a vehicle is chosen the others are cleared away, so the visitor is looking
   at their choice rather than re-reading the whole list. The chosen card gets the
   full width it no longer has to share. */
.vb-cars.picked{ grid-template-columns:minmax(0,340px); }
.vb-cars.picked .vb-car:not(.is-picked){ display:none; }
.vb-car.filtered-out{ display:none; }
.vb-car input{ position:absolute; opacity:0; width:0; height:0; }
.vb-car > div{
    border:2px solid var(--vb-line); border-radius:var(--vb-r); overflow:hidden;
    cursor:pointer; transition:.14s; background:var(--vb-card); height:100%;
    display:flex; flex-direction:column;
}
.vb-car > div:hover{ border-color:var(--vb-ink-3); }
.vb-car input:checked + div{ border-color:var(--vb-brand); box-shadow:0 0 0 3px color-mix(in srgb, var(--vb-brand) 38%, transparent); }
.vb-car input:focus-visible + div{ outline:3px solid color-mix(in srgb, var(--vb-brand) 55%, transparent); outline-offset:2px; }
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

.vb-theme{
    background:transparent; border:1px solid var(--vb-line); color:var(--vb-ink-2);
    border-radius:8px; padding:6px 12px; font-size:12.5px; font-weight:600;
    display:inline-flex; align-items:center; gap:7px; cursor:pointer; transition:.14s;
}
.vb-theme:hover{ border-color:var(--vb-brand); color:var(--vb-ink); }

/* Photographs and the placeholder tile are the two things a dark palette cannot
   simply recolour: a bright thumbnail against a near-black card is glaring at the
   distance this screen is read from, so it is eased back a little and restored on
   hover once someone is actually looking at it. */
:root[data-theme="dark"] .vb-car-img img{ filter:brightness(.9); transition:filter .2s; }
:root[data-theme="dark"] .vb-car:hover .vb-car-img img,
:root[data-theme="dark"] .vb-car input:checked + div .vb-car-img img{ filter:none; }
:root[data-theme="dark"] .vb-brandline img{ filter:brightness(1.08); }

/* Bootstrap's subtle alert backgrounds are readable under data-bs-theme="dark",
   but its borders all but vanish on this background, so they are brought back. */
:root[data-theme="dark"] .alert{ border-color:var(--vb-line); }
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
        <div class="d-flex align-items-center gap-2">
            <?php
            // Which desk this is, on every page. Reception needs to be able to see
            // at a glance that the book is pointed at the right branch, because
            // everything recorded is attributed to it.
            $vbHere = function_exists('visitorSessionLocation') ? visitorSessionLocation() : null;
            if ($vbHere):
                $vbHereName = visitorLocationName(getDB(), $vbHere);
            ?>
            <a href="<?= BASE_URL ?>/visitorbook/location.php" class="vb-here"
               title="Signed in at <?= htmlspecialchars($vbHereName) ?> — tap to change">
                <i class="fa fa-location-dot"></i><span><?= htmlspecialchars($vbHereName) ?></span>
            </a>
            <?php endif; ?>
            <button type="button" id="vbTheme" class="vb-theme" title="Switch between dark and light">
                <i class="fa fa-moon" id="vbThemeIcon"></i>
                <span class="d-none d-md-inline" id="vbThemeLabel">Dark</span>
            </button>
            <?php /* Confirmed, because a visitor tapping this would sign the kiosk
                     out and leave the next arrival unable to sign in at all. */ ?>
            <a href="<?= BASE_URL ?>/logout.php" class="btn btn-sm btn-outline-secondary"
               title="Close the visitors book (staff only)"
               onclick="return confirm('Close the visitors book?\n\nThis signs the reception account out and staff will need to sign back in before the next visitor can be recorded.');">
                <i class="fa fa-lock"></i>
                <span class="d-none d-sm-inline ms-1">Staff exit</span>
            </a>
        </div>
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
<script>
/* Theme toggle. Lives in the footer so every page built on this shell gets it,
   and only ever writes 'light' or clears the key — dark is the absence of a
   preference, which keeps a wiped browser profile coming back up dark. */
(function () {
    var btn   = document.getElementById('vbTheme');
    var icon  = document.getElementById('vbThemeIcon');
    var label = document.getElementById('vbThemeLabel');
    if (!btn) return;
    var root = document.documentElement;

    function paint() {
        var light = root.getAttribute('data-theme') === 'light';
        if (icon)  icon.className = light ? 'fa fa-sun' : 'fa fa-moon';
        if (label) label.textContent = light ? 'Light' : 'Dark';
        btn.setAttribute('aria-label', light ? 'Switch to dark mode' : 'Switch to light mode');
    }

    btn.addEventListener('click', function () {
        var toLight = root.getAttribute('data-theme') !== 'light';
        root.setAttribute('data-theme',    toLight ? 'light' : 'dark');
        root.setAttribute('data-bs-theme', toLight ? 'light' : 'dark');
        try {
            if (toLight) localStorage.setItem('vbTheme', 'light');
            else         localStorage.removeItem('vbTheme');
        } catch (e) { /* preference just will not persist */ }
        paint();
    });

    paint();
}());
</script>
</body>
</html>
<?php
}
