<?php
/**
 * Where a buyer sends in their KYC papers. No login.
 *
 * Asking somebody to create an account before they can hand over a bank
 * statement is how a yard ends up collecting them on WhatsApp instead. So the
 * link is the whole of the access, which is why the link is a 40-character
 * token that expires, can be withdrawn, and is checked on every request here.
 *
 * The page tells the buyer their own first name, which papers are wanted, and
 * what they have already sent. Nothing else. Not the price, not the credit
 * terms, not the vehicle, not a word anyone has written on the lead. If the
 * link is forwarded or found, the finder learns that somebody is buying a car
 * on credit from us, and that is deliberately the ceiling.
 *
 * It cannot read anything back either: files already on the deal are not listed
 * and cannot be downloaded from here. The buyer sees only the names of what
 * they themselves sent, so they can tell whether the last one went through.
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_kyc.php';

$db    = getDB();
$token = (string)($_GET['k'] ?? '');
$req   = kycFind($db, $token);
$why   = kycUnusableReason($req);

$err = $note = '';

if ($why === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string)($_POST['do'] ?? '');

    if ($do === 'upload') {
        $r = kycStore($db, $req, (string)($_POST['doc_type'] ?? 'other'), $_FILES['document'] ?? []);
        $err  = $r['ok'] ? '' : $r['error'];
        $note = $r['ok'] ? 'Received. Send another, or press Done when you have finished.' : '';
    } elseif ($do === 'submit') {
        $r = kycSubmit($db, $req);
        if ($r['ok']) {
            // Re-read so the page below shows the submitted state rather than
            // the form it was just posted from.
            $req = kycFind($db, $token);
        }
        $err = $r['ok'] ? '' : $r['error'];
    }
    // Re-read the request either way: an upload may have been the fifteenth.
    if ($req) $req = kycFind($db, $token) ?: $req;
}

$company   = getSetting('company_name', 'Mascardi');
$phone     = trim((string)getSetting('company_phone', ''));
$logo      = companyLogo();
$done      = $req && !empty($req['submitted_at']);
$progress  = ($why === '' && $req) ? kycProgress($db, $req) : [];
$uploaded  = ($why === '' && $req) ? kycUploaded($db, (int)$req['lead_id']) : [];
$firstName = '';
if ($req && trim((string)$req['lead_name']) !== '') {
    $firstName = explode(' ', trim((string)$req['lead_name']))[0];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title>Your documents — <?= htmlspecialchars($company) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box}
:root{--ink:#0f172a;--ink-2:#475569;--ink-3:#94a3b8;--line:#e2e8f0;--paper:#f8fafc;--brand:#0f6b5c}
body{
  margin:0;font-family:Inter,system-ui,sans-serif;background:var(--paper);color:var(--ink);
  -webkit-text-size-adjust:100%;
}
.k-wrap{max-width:640px;margin:0 auto;padding:0 16px 60px}
.k-head{background:#fff;border-bottom:1px solid var(--line);padding:16px 0;margin-bottom:22px}
.k-head-in{max-width:640px;margin:0 auto;padding:0 16px;display:flex;align-items:center;gap:12px}
.k-head img{height:34px;width:auto;max-width:150px;object-fit:contain}
.k-head span{font-weight:800;font-size:16px;letter-spacing:-.01em}
.k-card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:22px 20px;margin-bottom:16px}
@media(min-width:600px){.k-card{padding:28px 26px}}
h1{font-size:22px;font-weight:700;letter-spacing:-.02em;margin:0 0 8px;line-height:1.25}
@media(min-width:600px){h1{font-size:26px}}
.k-lead{color:var(--ink-2);font-size:14.5px;line-height:1.6;margin:0}
.k-need{list-style:none;padding:0;margin:18px 0 0}
.k-need li{
  display:flex;align-items:flex-start;gap:12px;padding:13px 0;border-bottom:1px solid var(--paper);
}
.k-need li:last-child{border-bottom:none}
.k-tick{
  flex:none;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;
  justify-content:center;font-size:10px;margin-top:1px;
  background:var(--paper);border:1px solid var(--line);color:var(--ink-3);
}
.k-tick.on{background:var(--brand);border-color:var(--brand);color:#fff}
.k-need-t{font-size:14.5px;font-weight:600;line-height:1.35}
.k-need-h{font-size:12.5px;color:var(--ink-3);margin-top:2px;line-height:1.45}
.k-got{font-size:12px;color:var(--brand);font-weight:600;margin-top:3px}
label{display:block;font-size:12.5px;font-weight:600;color:var(--ink-2);margin-bottom:6px}
select,input[type=file]{
  width:100%;padding:12px 13px;border:1px solid var(--line);border-radius:10px;
  font-size:15px;font-family:inherit;background:#fff;color:var(--ink);
}
select:focus,input[type=file]:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 3px rgba(15,107,92,.13)}
.k-btn{
  width:100%;padding:14px;border:0;border-radius:10px;font-size:15.5px;font-weight:700;
  font-family:inherit;cursor:pointer;background:var(--brand);color:#fff;margin-top:14px;
  display:flex;align-items:center;justify-content:center;gap:9px;
}
.k-btn:disabled{opacity:.55;cursor:default}
.k-btn.k-ghost{background:#fff;color:var(--ink);border:1px solid var(--line)}
.k-alert{padding:12px 14px;border-radius:10px;font-size:14px;line-height:1.5;margin-bottom:16px}
.k-alert.bad{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c}
.k-alert.good{background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d}
.k-sent{list-style:none;padding:0;margin:0}
.k-sent li{
  display:flex;align-items:center;gap:11px;padding:10px 0;border-bottom:1px solid var(--paper);
  font-size:13.5px;
}
.k-sent li:last-child{border-bottom:none}
.k-sent i{color:var(--brand)}
.k-sent .n{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.k-sent .s{color:var(--ink-3);font-size:12px;flex:none}
.k-safe{
  display:flex;gap:10px;align-items:flex-start;font-size:12.5px;color:var(--ink-3);
  line-height:1.55;margin-top:18px;
}
.k-foot{text-align:center;color:var(--ink-3);font-size:12.5px;padding:8px 16px}
.k-foot a{color:var(--brand);text-decoration:none;font-weight:600}
.k-mid{text-align:center;padding:50px 20px}
.k-mid i{font-size:44px;color:var(--ink-3);margin-bottom:14px;display:block}
</style>
</head>
<body>

<div class="k-head">
  <div class="k-head-in">
    <?php if ($logo['exists']): ?>
    <img src="<?= htmlspecialchars($logo['url']) ?>" alt="<?= htmlspecialchars($company) ?>">
    <?php else: ?>
    <span><?= htmlspecialchars($company) ?></span>
    <?php endif; ?>
  </div>
</div>

<div class="k-wrap">

<?php if ($why !== ''): ?>
  <?php // One page for every unusable link. Telling a stranger which of the
        // three reasons applies would confirm the token once existed. ?>
  <div class="k-card k-mid">
    <i class="fa fa-link-slash"></i>
    <h1>This link is no longer active</h1>
    <p class="k-lead">
      It may have expired, or been replaced with a newer one.
      Please contact us and we will send you a fresh link.
    </p>
    <?php if ($phone !== ''): ?>
    <a class="k-btn" style="text-decoration:none;margin-top:20px" href="tel:<?= htmlspecialchars($phone) ?>">
      <i class="fa fa-phone"></i>Call <?= htmlspecialchars($company) ?>
    </a>
    <?php endif; ?>
  </div>

<?php elseif ($done): ?>
  <div class="k-card k-mid">
    <i class="fa fa-circle-check" style="color:var(--brand)"></i>
    <h1>Thank you<?= $firstName !== '' ? ', ' . htmlspecialchars($firstName) : '' ?></h1>
    <p class="k-lead">
      We have your documents and someone will be in touch shortly.
      There is nothing else for you to do.
    </p>
    <?php if ($uploaded): ?>
    <p class="k-lead" style="margin-top:14px;font-size:13px;color:var(--ink-3)">
      <?= count($uploaded) ?> document<?= count($uploaded) === 1 ? '' : 's' ?> received.
    </p>
    <?php endif; ?>
  </div>

<?php else: ?>

  <div class="k-card">
    <h1>Hello<?= $firstName !== '' ? ' ' . htmlspecialchars($firstName) : '' ?></h1>
    <p class="k-lead">
      To finish setting up the finance on your vehicle we need a few documents.
      You can send them here — a clear photo from your phone is fine.
    </p>

    <ul class="k-need">
      <?php foreach ($progress as $key => $p): ?>
      <li>
        <span class="k-tick<?= $p['count'] > 0 ? ' on' : '' ?>">
          <i class="fa <?= $p['count'] > 0 ? 'fa-check' : 'fa-minus' ?>"></i>
        </span>
        <div>
          <div class="k-need-t"><?= htmlspecialchars($p['label']) ?></div>
          <?php if ($p['hint'] !== ''): ?>
          <div class="k-need-h"><?= htmlspecialchars($p['hint']) ?></div>
          <?php endif; ?>
          <?php if ($p['count'] > 0): ?>
          <div class="k-got"><?= $p['count'] ?> sent</div>
          <?php endif; ?>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <?php if ($err !== ''): ?>
  <div class="k-alert bad"><i class="fa fa-circle-exclamation me-1"></i> <?= htmlspecialchars($err) ?></div>
  <?php endif; ?>
  <?php if ($note !== ''): ?>
  <div class="k-alert good"><i class="fa fa-circle-check me-1"></i> <?= htmlspecialchars($note) ?></div>
  <?php endif; ?>

  <div class="k-card">
    <form method="POST" enctype="multipart/form-data" id="kForm">
      <input type="hidden" name="do" value="upload">
      <div style="margin-bottom:14px">
        <label for="dt">What is this document?</label>
        <select name="doc_type" id="dt">
          <?php foreach ($progress as $key => $p): ?>
          <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($p['label']) ?></option>
          <?php endforeach; ?>
          <option value="other">Something else</option>
        </select>
      </div>
      <div>
        <label for="fl">Choose the file</label>
        <input type="file" name="document" id="fl" required
               accept=".pdf,.jpg,.jpeg,.png,.webp,image/*,application/pdf">
      </div>
      <button class="k-btn" id="kGo"><i class="fa fa-arrow-up-from-bracket"></i>Send this document</button>
    </form>

    <div class="k-safe">
      <i class="fa fa-lock" style="margin-top:2px"></i>
      <span>
        Sent straight to <?= htmlspecialchars($company) ?> over a private link and stored where only
        our staff can open it. PDF or photo, up to 10 MB each.
      </span>
    </div>
  </div>

  <?php if ($uploaded): ?>
  <div class="k-card">
    <label style="margin-bottom:10px">What you have sent so far</label>
    <ul class="k-sent">
      <?php foreach ($uploaded as $u): ?>
      <li>
        <i class="fa <?= str_ends_with(strtolower((string)$u['file_name']), '.pdf') ? 'fa-file-pdf' : 'fa-file-image' ?>"></i>
        <span class="n"><?= htmlspecialchars((string)$u['title']) ?></span>
        <span class="s"><?= htmlspecialchars(leadDocSize((int)$u['file_size'])) ?></span>
      </li>
      <?php endforeach; ?>
    </ul>

    <form method="POST" onsubmit="return confirm('Send these to us and finish? You will not be able to add more afterwards.')">
      <input type="hidden" name="do" value="submit">
      <button class="k-btn"><i class="fa fa-paper-plane"></i>Done — send these to <?= htmlspecialchars($company) ?></button>
    </form>
  </div>
  <?php endif; ?>

<?php endif; ?>

</div>

<div class="k-foot">
  <?= htmlspecialchars($company) ?>
  <?php if ($phone !== ''): ?>
  &nbsp;·&nbsp; <a href="tel:<?= htmlspecialchars($phone) ?>"><?= htmlspecialchars($phone) ?></a>
  <?php endif; ?>
</div>

<script>
// A bank statement photographed on a phone can take a while on a slow
// connection, and a second press sends it twice.
(function () {
    var f = document.getElementById('kForm'), b = document.getElementById('kGo');
    if (!f || !b) return;
    f.addEventListener('submit', function () {
        b.disabled = true;
        b.innerHTML = '<i class="fa fa-circle-notch fa-spin"></i>Sending…';
    });
}());
</script>
</body>
</html>
