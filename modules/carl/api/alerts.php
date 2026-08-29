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
