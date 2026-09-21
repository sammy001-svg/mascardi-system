<?php
/**
 * One notification, three places it can land.
 *
 * The system had in-app notifications, an email helper and — since the WhatsApp
 * rebuild — a way to message a phone, and nothing joined them up. So a
 * reservation raised a bell nobody was looking at, a payment sent an email, and
 * a customer who had just paid several million shillings heard nothing at all.
 *
 * This is the join. Everything that wants to tell somebody something comes
 * through here, and here decides which doors to knock on.
 *
 * Two audiences, and they are not alike:
 *
 *   STAFF get the in-app bell always, because that is the record. Email and
 *   WhatsApp are per-event switches, because a yard that WhatsApps every
 *   salesperson about every event has taught them to mute it by Wednesday.
 *
 *   CLIENTS get WhatsApp, and never an in-app anything — they have no login.
 *   What they are sent is written for them rather than for the office: a
 *   customer does not want "Reservation #418 stage changed", they want to know
 *   their car is held and here is the receipt.
 *
 * Nothing in here is allowed to break the thing that called it. A deposit that
 * fails to record because a notification could not be sent is a far worse
 * outcome than a notification nobody received, so every path swallows its own
 * failures and writes them to the log.
 */

if (!function_exists('dispatchEvents')) {

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/notifications.php';

/**
 * Staff had no phone number anywhere — the users table has an email and
 * nothing else — so there was no way to reach anyone on WhatsApp however well
 * the sending worked. Added here, inline, like the rest of the schema.
 */
function dispatchMigrate(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try { $db->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(30) NULL AFTER email"); }
    catch (\Throwable $_) {}
}

/**
 * The events the yard can be told about, and what they are called on screen.
 *
 * Kept small on purpose. Every entry here becomes two switches on the settings
 * page, and a page of sixty switches is one nobody reads.
 */
function dispatchEvents(): array
{
    return [
        'reservation' => 'A car is reserved',
        'deposit'     => 'A deposit is received',
        'quotation'   => 'A quotation is issued',
        'invoice'     => 'An invoice is issued',
        'booking'     => 'A service booking is made',
        'delivery'    => 'A vehicle is delivered',
        'lead'        => 'A new lead arrives',
        'workshop'    => 'A job card is finished',
    ];
}

/** Whether a channel is switched on for an event. */
function dispatchOn(string $event, string $channel): bool
{
    // Staff email for the events that already had a switch stays as the yard set
    // it; everything new arrives off, because a notification system that turns
    // itself on is one people learn to distrust.
    $default = ($channel === 'inapp') ? '1' : '0';
    return getSetting('notify_' . $channel . '_' . $event, $default) === '1';
}

// ── Staff ────────────────────────────────────────────────────────────────────

/**
 * Tell people who work here.
 *
 * @param int[]    $userIds  who to tell
 * @param string   $event    a key from dispatchEvents()
 * @param array    $n        ['title'=>, 'message'=>, 'link'=>, 'whatsapp'=>?]
 *                           'whatsapp' overrides the text sent to a phone, where
 *                           the in-app wording would read oddly out of context.
 */
function dispatchToStaff(array $userIds, string $event, array $n): void
{
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$userIds) return;

    $title = (string)($n['title'] ?? 'Notification');
    $body  = (string)($n['message'] ?? '');
    $link  = (string)($n['link'] ?? '');

    foreach ($userIds as $uid) {
        // The bell is the record and is never conditional.
        try { createNotification($uid, $event, $title, $body, $link); }
        catch (\Throwable $e) { error_log('dispatch inapp: ' . $e->getMessage()); }
    }

    $wantMail = dispatchOn($event, 'email');
    $wantWa   = dispatchOn($event, 'whatsapp');
    if (!$wantMail && !$wantWa) return;

    try {
        $db = getDB();
        dispatchMigrate($db);
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $st = $db->prepare("SELECT id, name, email, phone FROM users
                             WHERE id IN ($in) AND status = 'active'");
        $st->execute($userIds);
        $people = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('dispatch staff lookup: ' . $e->getMessage());
        return;
    }

    foreach ($people as $p) {
        if ($wantMail && !empty($p['email']) && filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
            try {
                require_once __DIR__ . '/mailer.php';
                $html = mailTemplate($title, '<p>' . e($body) . '</p>'
                    . ($link !== '' ? '<p><a href="' . e($link) . '">Open it in the system</a></p>' : ''));
                sendMail($p['email'], (string)$p['name'], $title, $html, $event, 0);
            } catch (\Throwable $e) { error_log('dispatch mail: ' . $e->getMessage()); }
        }

        if ($wantWa && !empty($p['phone'])) {
            try {
                require_once __DIR__ . '/whatsapp.php';
                $text = (string)($n['whatsapp'] ?? ($title . "\n" . $body));
                if ($link !== '') $text .= "\n\n" . $link;
                sendWhatsApp((string)$p['phone'], $text, $event, 0);
            } catch (\Throwable $e) { error_log('dispatch wa: ' . $e->getMessage()); }
        }
    }
}

/** The same, addressed by role rather than by person. */
function dispatchToRoles(array $roles, string $event, array $n): void
{
    if (!$roles) return;
    try {
        $db = getDB();
        $in = implode(',', array_fill(0, count($roles), '?'));
        $st = $db->prepare("SELECT id FROM users WHERE role IN ($in) AND status = 'active'");
        $st->execute($roles);
        dispatchToStaff($st->fetchAll(PDO::FETCH_COLUMN), $event, $n);
    } catch (\Throwable $e) { error_log('dispatchToRoles: ' . $e->getMessage()); }
}

// ── Clients ──────────────────────────────────────────────────────────────────

/**
 * Tell a customer something, on WhatsApp, in their own language of business.
 *
 * The message lands in the same thread the team uses, so whoever picks up the
 * phone next can see exactly what the system already told them. A customer who
 * says "but your system said the car was mine" should be arguing with something
 * the yard can read.
 *
 * @param string $phone  any way a number is written
 * @param string $text   what to say — written for a customer, not for the office
 * @param string $link   an optional document link, appended on its own line
 * @return bool          whether it went
 */
function dispatchToClient(string $phone, string $event, string $text, string $link = ''): bool
{
    if (!dispatchOn($event, 'client')) return false;
    if (trim($phone) === '' || trim($text) === '') return false;

    try {
        require_once __DIR__ . '/whatsapp.php';
        if (!whatsappEnabled()) return false;

        $msg = rtrim($text);
        if ($link !== '') $msg .= "\n\n" . $link;

        $r = sendWhatsApp($phone, $msg, $event, 0);
        if (!$r['ok']) error_log('dispatchToClient: ' . ($r['error'] ?? 'failed'));
        return (bool)$r['ok'];
    } catch (\Throwable $e) {
        error_log('dispatchToClient: ' . $e->getMessage());
        return false;
    }
}

/**
 * A link to a document the customer can open without logging in.
 *
 * Thin wrapper over the signed token the WhatsApp module already uses, so there
 * is exactly one way a document reaches a customer and exactly one place the
 * rules about it live.
 */
function dispatchDocLink(string $kind, int $id, int $clientId, int $days = 14): string
{
    if ($clientId <= 0 || $id <= 0) return '';
    try {
        require_once __DIR__ . '/../modules/whatsapp/_tools.php';
        return rtrim(BASE_URL, '/') . '/modules/whatsapp/doc.php?t='
             . waSignDocToken($kind, $id, $clientId, $days);
    } catch (\Throwable $e) {
        error_log('dispatchDocLink: ' . $e->getMessage());
        return '';
    }
}

/** The client behind a lead, or 0. Used to decide whether a link may be issued. */
function dispatchClientOf(PDO $db, ?int $leadId = null, ?int $clientId = null): int
{
    if ($clientId) return $clientId;
    if (!$leadId)  return 0;
    try {
        $st = $db->prepare("SELECT client_id FROM crm_leads WHERE id = ?");
        $st->execute([$leadId]);
        return (int)($st->fetchColumn() ?: 0);
    } catch (\Throwable $e) { return 0; }
}

} // function_exists('dispatchEvents')
