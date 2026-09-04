<?php
/**
 * Carl — proactive alert endpoint.
 *
 * Called once on every page load by the widget (a lightweight background fetch,
 * not a visible request). Returns whether anything has changed since the user
 * last acknowledged Carl so the button can be badged.
 *
 * Designed to be very fast — all queries are counts or single-row lookups, and
 * the result is cached in the session for 60 seconds so rapid page navigation
 * does not hammer the database.
 *
 * Response shape:
 *   {
 *     "ok":    true,
 *     "count": 3,           // total unacknowledged alerts (0 means no badge)
 *     "top":   "3 leads are past their follow-up date.",   // most urgent, for the panel
 *     "items": [ { "icon": "...", "say": "...", "href": "..." }, … ]
 *   }
 */
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/notifications.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (authRole() === 'visitor_book') {
    echo json_encode(['ok' => true, 'count' => 0, 'top' => '', 'items' => []]);
    exit;
}

// ── Session cache — recalculate at most once per minute ──────────────────────
$cacheKey = 'carl_alerts_' . (int)authUser()['id'];
if (isset($_SESSION[$cacheKey]) && isset($_SESSION[$cacheKey . '_ts'])
    && time() - $_SESSION[$cacheKey . '_ts'] < 60) {
    echo json_encode($_SESSION[$cacheKey]);
    exit;
}

$db    = getDB();
$f     = carlFigures($db);
$items = [];

// Priority order — most costly-to-ignore first.

if (canAccess('crm') && $f['leads_overdue'] > 0) {
    $items[] = [
        'icon' => 'fa-circle-exclamation',
        'tone' => 'bad',
        'say'  => carlPlural($f['leads_overdue'], 'lead is', 'leads are') . ' past the follow-up date.',
        'href' => BASE_URL . '/modules/crm/leads.php',
    ];
}

if (canAccess('visitors') && $f['visitors_onsite'] > 0) {
    // Flag visitors who have been on site for more than 2 hours — that is a sign
    // that either someone forgot to sign them out or they are still being attended to.
    $longStay = 0;
    try {
        $longStay = (int)$db->query("SELECT COUNT(*) FROM visitors
            WHERE checked_out_at IS NULL
              AND DATE(created_at) = CURDATE()
              AND TIMESTAMPDIFF(HOUR, created_at, NOW()) >= 2")->fetchColumn();
    } catch (\Throwable $_) {}

    if ($longStay > 0) {
        $items[] = [
            'icon' => 'fa-person-walking-arrow-loop-left',
            'tone' => 'warn',
            'say'  => carlPlural($longStay, 'visitor has', 'visitors have') . ' been on site for over 2 hours.',
            'href' => BASE_URL . '/modules/visitors/index.php',
        ];
    }
}

if (canAccess('crm') && $f['leads_nofollow'] > 0) {
    $items[] = [
        'icon' => 'fa-calendar-xmark',
        'tone' => 'warn',
        'say'  => carlPlural($f['leads_nofollow'], 'lead has', 'leads have') . ' no follow-up date set.',
        'href' => BASE_URL . '/modules/crm/leads.php',
    ];
}

if (canAccess('crm') && $f['leads_new_today'] > 0) {
    // Only flag new leads that came in recently (last 30 min) to avoid repeating the same alert all day.
    $veryNew = 0;
    try {
        $veryNew = (int)$db->query("SELECT COUNT(*) FROM crm_leads
            WHERE DATE(created_at) = CURDATE()
              AND created_at >= NOW() - INTERVAL 30 MINUTE")->fetchColumn();
    } catch (\Throwable $_) {}
    if ($veryNew > 0) {
        $items[] = [
            'icon' => 'fa-user-plus',
            'tone' => 'good',
            'say'  => carlPlural($veryNew, 'new lead just came in', 'new leads just came in') . '.',
            'href' => BASE_URL . '/modules/crm/leads.php',
        ];
    }
}

if (canAccess('payments') && $f['invoices_unpaid'] > 0) {
    // Only surface invoices older than 14 days as a proactive alert.
    $aged = 0;
    try {
        $aged = (int)$db->query("SELECT COUNT(*) FROM invoices
            WHERE status <> 'paid'
              AND created_at < NOW() - INTERVAL 14 DAY")->fetchColumn();
    } catch (\Throwable $_) {}
    if ($aged > 0) {
        $items[] = [
            'icon' => 'fa-file-invoice-dollar',
            'tone' => 'warn',
            'say'  => carlPlural($aged, 'invoice is', 'invoices are') . ' more than 14 days unpaid.',
            'href' => BASE_URL . '/modules/invoices/index.php',
        ];
    }
}

// ── The workshop ─────────────────────────────────────────────────────────────
//
// Nothing here was watched, even though these are the two places work quietly
// stops. A job card nobody is assigned to, or one that has sat for a fortnight,
// costs a customer their car and nobody a phone call — precisely the sort of
// thing that is never noticed by whoever is not looking for it.
//
// Every date comparison is made in SQL: PHP runs UTC on this host and MySQL runs
// EAT, so "open more than N days" worked out in PHP is three hours adrift.
if (canAccess('jobs')) {
    try {
        $stalled = (int)$db->query(
            "SELECT COUNT(*) FROM workshop_jobs
              WHERE status NOT IN ('completed','cancelled')
                AND created_at < DATE_SUB(NOW(), INTERVAL 14 DAY)"
        )->fetchColumn();
        if ($stalled > 0) {
            $items[] = [
                'icon' => 'fa-screwdriver-wrench',
                'tone' => 'bad',
                'say'  => carlPlural($stalled, 'job card has', 'job cards have')
                        . ' been open more than two weeks.',
                'href' => BASE_URL . '/modules/jobs/index.php',
            ];
        }

        $unassigned = (int)$db->query(
            "SELECT COUNT(*) FROM workshop_jobs
              WHERE status NOT IN ('completed','cancelled')
                AND (mechanic_id IS NULL OR mechanic_id = 0)"
        )->fetchColumn();
        if ($unassigned > 0) {
            $items[] = [
                'icon' => 'fa-user-slash',
                'tone' => 'warn',
                'say'  => carlPlural($unassigned, 'job card has', 'job cards have')
                        . ' no mechanic on it.',
                'href' => BASE_URL . '/modules/jobs/index.php',
            ];
        }
    } catch (\Throwable $e) { /* a missing table must not empty the whole badge */ }
}

// ── Deliveries ───────────────────────────────────────────────────────────────
//
// A sold vehicle stuck mid-handover is money already taken and a customer
// already waiting, so it belongs above most of what is above it.
if (canAccess('crm')) {
    try {
        $waiting = (int)$db->query(
            "SELECT COUNT(*) FROM crm_leads
              WHERE stage IN ('reserved','won')
                AND delivered_at IS NULL
                AND updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
        )->fetchColumn();
        if ($waiting > 0) {
            $items[] = [
                'icon' => 'fa-truck-ramp-box',
                'tone' => 'warn',
                'say'  => carlPlural($waiting, 'sale has', 'sales have')
                        . ' been waiting to be handed over for over a week.',
                'href' => BASE_URL . '/modules/reservations/index.php',
            ];
        }
    } catch (\Throwable $e) { /* as above */ }
}

// ── Parts waiting on somebody ────────────────────────────────────────────────
if (canAccess('parts_requests')) {
    try {
        $pending = (int)$db->query(
            "SELECT COUNT(*) FROM parts_requests
              WHERE status NOT IN ('approved','rejected','cancelled','completed')
                AND created_at < DATE_SUB(NOW(), INTERVAL 3 DAY)"
        )->fetchColumn();
        if ($pending > 0) {
            $items[] = [
                'icon' => 'fa-boxes-packing',
                'tone' => 'warn',
                'say'  => carlPlural($pending, 'parts request has', 'parts requests have')
                        . ' been waiting more than three days for approval.',
                'href' => BASE_URL . '/modules/parts_requests/index.php',
            ];
        }
    } catch (\Throwable $e) { /* as above */ }
}

// ── Speaking first ───────────────────────────────────────────────────────────
//
// Everything above is reactive: it badges the button and waits to be opened. The
// people most likely to be sitting on something overdue are exactly the people
// who never think to open Carl, so once a day the same findings go out as a
// notification they will see anyway.
//
// This endpoint is polled on every page load, which makes it the one place that
// reaches everybody who logs in. The day is claimed with a conditional UPDATE
// before the notification is written, so two tabs opening together cannot both
// decide they are the first.
if ($items && function_exists('createNotification')) {
    $urgent = array_values(array_filter($items, fn ($i) => ($i['tone'] ?? '') !== 'good'));
    if ($urgent) {
        try {
            $uid   = (int)authUser()['id'];
            $today = (string)$db->query("SELECT CURDATE()")->fetchColumn();

            $claim = $db->prepare(
                "INSERT INTO carl_greetings (user_id, greeted_on, digest_on)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     digest_on = IF(digest_on IS NULL OR digest_on <> VALUES(digest_on),
                                    VALUES(digest_on), digest_on)"
            );
            $claim->execute([$uid, $today, $today]);

            // rowCount is 1 on insert and 2 on a row actually changed; 0 means
            // today was already claimed by another tab or an earlier page load.
            if ($claim->rowCount() > 0) {
                $lines = [];
                foreach (array_slice($urgent, 0, 4) as $i) $lines[] = '• ' . $i['say'];
                if (count($urgent) > 4) {
                    $lines[] = '• and ' . (count($urgent) - 4) . ' more.';
                }
                createNotification(
                    $uid,
                    'alert',
                    CARL_NAME . ': ' . carlPlural(count($urgent), 'thing', 'things') . ' worth a look',
                    implode("\n", $lines),
                    BASE_URL . '/index.php'
                );
            }
        } catch (\Throwable $e) {
            error_log('carl digest: ' . $e->getMessage());
        }
    }
}

// Sorted by how costly it is to ignore, not by the order the checks happen to
// run in. The badge shows the first item, and it was showing "a new lead just
// came in" — good news — above a job card that had sat for a fortnight.
// A stable sort keeps the existing order within each level.
$rank = ['bad' => 0, 'warn' => 1, 'good' => 2];
$i = 0;
foreach ($items as &$__it) { $__it['_seq'] = $i++; }
unset($__it);
usort($items, function ($a, $b) use ($rank) {
    $ra = $rank[$a['tone'] ?? 'warn'] ?? 1;
    $rb = $rank[$b['tone'] ?? 'warn'] ?? 1;
    return $ra === $rb ? $a['_seq'] <=> $b['_seq'] : $ra <=> $rb;
});
foreach ($items as &$__it) { unset($__it['_seq']); }
unset($__it);

$result = [
    'ok'    => true,
    'count' => count($items),
    'top'   => $items ? $items[0]['say'] : '',
    'items' => $items,
];

// Cache for 60 seconds.
$_SESSION[$cacheKey]         = $result;
$_SESSION[$cacheKey . '_ts'] = time();

echo json_encode($result, JSON_UNESCAPED_UNICODE);
