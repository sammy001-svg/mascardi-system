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

/**
 * The events where telling the customer is the whole point.
 *
 * A reservation, a payment, a quotation, an invoice, a booking and a delivery
 * are all moments the customer is waiting to hear about, and each one produces
 * a document they are entitled to. Silence at any of them is the yard looking
 * careless at exactly the wrong moment.
 */
function dispatchClientEvents(): array
{
    return ['reservation', 'deposit', 'quotation', 'invoice', 'booking', 'delivery'];
}

/**
 * Whether a channel is switched on for an event.
 *
 * Three different defaults, for three different risks:
 *
 *   inapp  is always on. It is the record, it costs nothing, and a bell nobody
 *          looks at is still better than no trace of what happened.
 *
 *   client is on for the six events above. This was off, along with everything
 *          else, and the result was a notification system that had been built,
 *          wired and switched off at the last valve — reservations, quotations
 *          and bookings all went out in silence and looked like a bug, because
 *          from the yard's side it was one.
 *
 *   staff  email and WhatsApp stay off until somebody asks for them. A yard
 *          that WhatsApps every salesperson about every event has taught them
 *          to mute it by Wednesday, and a muted channel is worse than none:
 *          it looks like it is working.
 */
function dispatchOn(string $event, string $channel): bool
{
    $default = match ($channel) {
        'inapp'  => '1',
        'client' => in_array($event, dispatchClientEvents(), true) ? '1' : '0',
        default  => '0',
    };
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
    if ($id <= 0) return '';

    // An invoice or a quotation is opened by matching the client on the record,
    // so a link without one cannot open and must not be sent — a customer given
    // a link that says "we could not open that" is worse off than one given no
    // link. A deposit receipt belongs to a lead and a booking to itself; neither
    // is looked up by client, so both are fine for a walk-in with no client
    // record, which is most of them.
    $needsClient = in_array($kind, ['invoice', 'quotation'], true);
    if ($needsClient && $clientId <= 0) return '';

    try {
        require_once __DIR__ . '/../modules/whatsapp/_tools.php';
        return rtrim(BASE_URL, '/') . '/modules/whatsapp/doc.php?t='
             . waSignDocToken($kind, $id, $clientId, $days);
    } catch (\Throwable $e) {
        error_log('dispatchDocLink: ' . $e->getMessage());
        return '';
    }
}

/** "2019 Toyota Prado KDA 123A", or '' — for saying which car we mean. */
function dispatchCarLabel(PDO $db, int $carId): string
{
    if ($carId <= 0) return '';
    try {
        $st = $db->prepare("SELECT year, make, model, registration_number FROM cars WHERE id = ?");
        $st->execute([$carId]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) return '';
        $label = trim(($c['year'] ? $c['year'] . ' ' : '') . $c['make'] . ' ' . $c['model']);
        if (!empty($c['registration_number'])) $label .= ' ' . $c['registration_number'];
        return trim(preg_replace('/\s+/', ' ', $label));
    } catch (\Throwable $e) { return ''; }
}

/**
 * Tell a customer their car is held.
 *
 * Shared because a reservation becomes real at two different points depending
 * on who made it — immediately when a super admin saves it, and on approval
 * when anybody else does — and the customer should hear the same thing either
 * way. The ordinary user's own save is only a request for approval and
 * deliberately says nothing at all: a customer told their car is reserved, by
 * a reservation that is then declined, is a worse problem than a slow message.
 */
function dispatchReservationToClient(PDO $db, array $lead, int $leadId,
                                     int $carId, float $depositAmt): void
{
    $phone = trim((string)($lead['phone'] ?? ''));
    if ($phone === '') return;

    $co    = getSetting('company_name', 'Mascardi');
    $first = explode(' ', trim((string)($lead['name'] ?? '')))[0] ?: 'there';
    $car   = dispatchCarLabel($db, $carId);
    $link  = dispatchDocLink('deposit', $leadId, (int)($lead['client_id'] ?? 0));

    $text = "Hello {$first},\n\n"
          . "Your " . ($car !== '' ? '*' . $car . '*' : 'vehicle') . " is now reserved with {$co}.";
    if ($depositAmt > 0) {
        $text .= "\n\nDeposit received: *" . money($depositAmt) . "*";
    }
    $text .= "\n\nReply to this message if you have any questions.";
    if ($link !== '') {
        $text .= "\n\nYour reservation and deposit receipt:";
    }

    dispatchToClient($phone, 'reservation', $text, $link);
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
