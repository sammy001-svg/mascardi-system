<?php
/**
 * Showroom lead-capture bootstrap.
 *
 * The public forms (inquiry.php, contact.php) previously wrote into tables that
 * were never created by any migration, with the INSERT wrapped in a silent
 * catch — so submissions were discarded while the visitor was shown a success
 * message. This guarantees the tables exist and gives both endpoints one shared
 * path into the CRM, so a web enquiry becomes a real lead the team can work.
 *
 * Column names deliberately match what modules/showroom/index.php already reads.
 */

/** Inline migrations. Runs once per request; each statement no-ops if applied. */
function showroomLeadsMigrate(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS showroom_inquiries (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            car_id         INT NOT NULL,
            inquiry_name   VARCHAR(150) NOT NULL,
            inquiry_phone  VARCHAR(30)  NULL,
            inquiry_email  VARCHAR(150) NULL,
            message        TEXT NULL,
            status         VARCHAR(20) NOT NULL DEFAULT 'new',
            notes          TEXT NULL,
            responded_by   INT NULL,
            responded_at   DATETIME NULL,
            lead_id        INT NULL,
            created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_car    (car_id),
            INDEX idx_status (status),
            INDEX idx_created(created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $_) {}

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS contact_messages (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            name         VARCHAR(150) NOT NULL,
            phone        VARCHAR(30)  NULL,
            email        VARCHAR(150) NULL,
            subject      VARCHAR(200) NULL,
            message      TEXT NOT NULL,
            status       VARCHAR(20) NOT NULL DEFAULT 'new',
            notes        TEXT NULL,
            responded_by INT NULL,
            responded_at DATETIME NULL,
            lead_id      INT NULL,
            created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_created(created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $_) {}

    // Added after the tables shipped — no-op on fresh installs.
    try { $db->exec("ALTER TABLE showroom_inquiries ADD COLUMN lead_id INT NULL"); } catch (\Throwable $_) {}
    try { $db->exec("ALTER TABLE contact_messages   ADD COLUMN lead_id INT NULL"); } catch (\Throwable $_) {}

    // A request to come and see the car is an enquiry with a date on it, so it
    // lives in the same table rather than a parallel one: the inbox, the status
    // workflow and the route into the CRM already exist here, and a second table
    // would mean a second inbox nobody remembers to open.
    foreach ([
        "ALTER TABLE showroom_inquiries ADD COLUMN request_type VARCHAR(20) NOT NULL DEFAULT 'enquiry'",
        "ALTER TABLE showroom_inquiries ADD COLUMN preferred_date DATE NULL",
        "ALTER TABLE showroom_inquiries ADD COLUMN preferred_time VARCHAR(20) NULL",
        "ALTER TABLE showroom_inquiries ADD COLUMN location_id INT NULL",
        "ALTER TABLE showroom_inquiries ADD INDEX idx_si_type (request_type, status)",
        // What the yard actually agreed to, which is not always what was asked
        // for. Kept apart from preferred_date/preferred_time so the request and
        // the appointment can be compared later.
        "ALTER TABLE showroom_inquiries ADD COLUMN confirmed_date DATE NULL",
        "ALTER TABLE showroom_inquiries ADD COLUMN confirmed_time TIME NULL",
        "ALTER TABLE showroom_inquiries ADD COLUMN confirmed_by INT NULL",
        "ALTER TABLE showroom_inquiries ADD COLUMN confirmed_at DATETIME NULL",
    ] as $alter) { try { $db->exec($alter); } catch (\Throwable $_) {} }
}

/**
 * Create a CRM lead from a public web submission and return its id (0 on failure).
 *
 * Reuses an existing open lead for the same phone/email instead of creating a
 * duplicate, so a customer who enquires about three cars stays one lead with
 * three logged activities rather than cluttering the pipeline.
 */
function showroomCreateLead(
    PDO $db,
    string $name,
    ?string $phone,
    ?string $email,
    string $interestedIn,
    string $summary
): int {
    if ($name === '' || ($phone === null && $email === null)) return 0;

    try {
        // Match an existing lead still in play, by phone or email.
        $lead = null;
        if ($phone || $email) {
            $s = $db->prepare("SELECT id FROM crm_leads
                               WHERE stage NOT IN ('lost','delivered')
                                 AND ((phone IS NOT NULL AND phone <> '' AND phone = ?)
                                   OR (email IS NOT NULL AND email <> '' AND email = ?))
                               ORDER BY id DESC LIMIT 1");
            $s->execute([$phone ?? '', $email ?? '']);
            $lead = $s->fetchColumn();
        }

        if ($lead) {
            $leadId = (int)$lead;
            // Keep the lead surfacing in the pipeline as newly active.
            $db->prepare("UPDATE crm_leads SET updated_at = NOW() WHERE id = ?")->execute([$leadId]);
        } else {
            $db->prepare("INSERT INTO crm_leads (name, phone, email, source, interested_in, stage, notes)
                          VALUES (?,?,?,'website',?, 'new', ?)")
               ->execute([$name, $phone, $email, $interestedIn, $summary]);
            $leadId = (int)$db->lastInsertId();
        }

        // Log the submission on the lead's timeline.
        // crm_activities.summary is VARCHAR(300) — truncate so a long customer
        // message can't fail the insert and lose the timeline entry.
        try {
            $db->prepare("INSERT INTO crm_activities (lead_id, type, summary, created_at)
                          VALUES (?, 'note', ?, NOW())")
               ->execute([$leadId, mb_substr($summary, 0, 300)]);
        } catch (\Throwable $_) {}

        return $leadId;
    } catch (\Throwable $e) {
        error_log('showroomCreateLead: ' . $e->getMessage());
        return 0;
    }
}

/**
 * The times a customer can ask to come in.
 *
 * A list rather than a free-text time, because "afternoon" is what people
 * actually mean and a précise 14:35 is a promise the yard has not made. The
 * keys are stored, so adding to this list is safe and renaming a key is not.
 */
function showroomViewingSlots(): array
{
    return [
        'morning'   => 'Morning (9am – 12pm)',
        'afternoon' => 'Afternoon (12pm – 4pm)',
        'evening'   => 'Late afternoon (4pm – 6pm)',
        'any'       => 'Any time that suits you',
    ];
}

/** The label for a stored slot key, or the key itself if it is no longer offered. */
function showroomViewingSlotLabel(?string $key): string
{
    if ($key === null || $key === '') return '';
    return showroomViewingSlots()[$key] ?? $key;
}

/**
 * Tell the people who have to act on a viewing request.
 *
 * Narrower than a general enquiry on purpose: an enquiry is a lead anybody in
 * sales can pick up, but somebody asking to come in on Thursday needs a person
 * who can confirm the appointment and have the car ready. super_admin is in the
 * list because installs exist where that is the only administrator account, and
 * leaving it out would mean the one person who can always act never hears.
 */
function showroomNotifyViewingRequest(string $title, string $message, string $link): void
{
    try {
        require_once __DIR__ . '/../includes/notifications.php';
        notifyRoles(['super_admin', 'admin', 'customer_relations'],
                    'warning', $title, $message, $link);
    } catch (\Throwable $e) {
        error_log('showroomNotifyViewingRequest: ' . $e->getMessage());
    }
}

/**
 * Who may confirm a viewing.
 *
 * The same people the request was sent to. Confirming is a promise made to a
 * customer on the company's behalf -- somebody will be there, and the car will
 * be clean and out of the back row -- so it belongs with whoever can actually
 * arrange that.
 */
function showroomCanConfirmViewing(): bool
{
    return function_exists('hasRole')
        && hasRole(['super_admin', 'admin', 'customer_relations']);
}

/**
 * The hour a slot starts, used to prefill the confirm form.
 *
 * A confirmation says "come at 2:30", not "come in the afternoon": the whole
 * point of confirming is to replace a preference with an appointment. These are
 * only the starting suggestion, and whoever confirms can type over them.
 */
function showroomViewingSlotStart(?string $slot): string
{
    switch ((string)$slot) {
        case 'morning':   return '10:00';
        case 'afternoon': return '14:00';
        case 'evening':   return '16:30';
        default:          return '11:00';
    }
}

/**
 * The email the client gets, with the three things they need to turn up.
 *
 * Where to come is read from the car's location now rather than the one stored
 * on the request: stock moves between yards, and a confirmation that sends
 * somebody to the showroom the car left last week is worse than none.
 */
function showroomViewingEmailBody(array $req, array $loc, string $when): string
{
    $carLabel = trim((string)($req['year'] ?? '') . ' ' . ($req['make'] ?? '') . ' ' . ($req['model'] ?? ''));
    $rows = '';
    $add  = static function (string $k, string $v) use (&$rows): void {
        if (trim($v) === '') return;
        $rows .= '<tr><td style="color:#64748b;font-weight:600;width:34%">' . htmlspecialchars($k)
               . '</td><td>' . nl2br(htmlspecialchars($v)) . '</td></tr>';
    };
    $add('Vehicle', $carLabel);
    $add('When',    $when);
    $add('Where',   trim((string)($loc['name'] ?? '')));
    $add('Address', trim((string)($loc['address'] ?? '')));
    $add('Phone',   trim((string)($loc['phone'] ?? '')));

    $body  = '<p>Hello ' . htmlspecialchars(explode(' ', trim((string)$req['inquiry_name']))[0] ?: 'there') . ',</p>';
    $body .= '<p>Your viewing is confirmed. We look forward to seeing you.</p>';
    $body .= '<table class="data">' . $rows . '</table>';

    $dir = $loc ? locationDirectionsUrl($loc) : '';
    if ($dir !== '') {
        $body .= '<p><a class="btn" href="' . htmlspecialchars($dir) . '">Get directions</a></p>';
    }
    $body .= '<p style="font-size:13px;color:#64748b">If this time no longer suits you, reply to this '
           . 'email or call us and we will rearrange it.</p>';
    return mailTemplate('Your viewing is confirmed', $body);
}

/**
 * Confirm a viewing request and tell the client when and where.
 *
 * Re-confirming is allowed and deliberate: a customer who rings to move the day
 * needs the appointment changed and a fresh confirmation, not a second request.
 *
 * The email is the point of this, but it is not allowed to be the thing that
 * stops the appointment being recorded. A client who left no email address, or
 * an install whose SMTP is not set up, still gets a confirmed viewing -- the
 * caller is told the email did not go so somebody can phone instead.
 *
 * @return array{ok:bool,error:string,when:string,emailed:bool,email_note:string,
 *                rescheduled:bool}
 */
function showroomConfirmViewing(PDO $db, int $id, string $date, string $time, int $uid): array
{
    $out = ['ok' => false, 'error' => '', 'when' => '', 'emailed' => false,
            'email_note' => '', 'rescheduled' => false];

    showroomLeadsMigrate($db);

    $st = $db->prepare("SELECT i.*, c.make, c.model, c.year, c.location_id AS car_location_id
                          FROM showroom_inquiries i
                     LEFT JOIN cars c ON c.id = i.car_id
                         WHERE i.id = ?");
    $st->execute([$id]);
    $req = $st->fetch(PDO::FETCH_ASSOC);
    if (!$req) { $out['error'] = 'That request no longer exists.'; return $out; }
    if (($req['request_type'] ?? '') !== 'viewing') {
        $out['error'] = 'That is an enquiry, not a viewing request, so there is nothing to confirm.';
        return $out;
    }

    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) { $out['error'] = 'Choose a valid date.'; return $out; }
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
        $out['error'] = 'Choose a valid time, as HH:MM.';
        return $out;
    }
    // Against the database's clock: this application runs on UTC and MySQL on
    // EAT, so PHP's idea of "today" is wrong for the first three hours of it.
    try {
        $chk = $db->prepare('SELECT ? < CURDATE()');
        $chk->execute([$date]);
        if ((int)$chk->fetchColumn() === 1) {
            $out['error'] = 'That date has already passed. Pick the day the client is coming.';
            return $out;
        }
    } catch (\Throwable $_) {}

    $out['rescheduled'] = !empty($req['confirmed_at']);

    try {
        $db->prepare("UPDATE showroom_inquiries
                         SET status = 'confirmed', confirmed_date = ?, confirmed_time = ?,
                             confirmed_by = ?, confirmed_at = NOW(),
                             responded_by = ?, responded_at = NOW()
                       WHERE id = ?")
           ->execute([$date, $time, $uid ?: null, $uid ?: null, $id]);
    } catch (\Throwable $e) {
        error_log('showroomConfirmViewing: ' . $e->getMessage());
        $out['error'] = 'The confirmation could not be saved.';
        return $out;
    }

    $when = date('l j F Y', strtotime($date)) . ' at ' . date('g:ia', strtotime($date . ' ' . $time));
    $out['when'] = $when;

    // Where the car is now, not where it was when they asked.
    require_once __DIR__ . '/../modules/locations/_geo.php';
    $loc = locationMap($db, (int)($req['car_location_id'] ?: $req['location_id'] ?: 0)) ?? [];

    // On the lead's timeline, so the appointment is visible to whoever opens it.
    if (!empty($req['lead_id'])) {
        try {
            $db->prepare("INSERT INTO crm_activities (lead_id, type, summary, created_at)
                          VALUES (?, 'note', ?, NOW())")
               ->execute([(int)$req['lead_id'],
                          mb_substr(($out['rescheduled'] ? 'Viewing rearranged for ' : 'Viewing confirmed for ')
                              . $when . (!empty($loc['name']) ? ' at ' . $loc['name'] : ''), 0, 300)]);
        } catch (\Throwable $_) {}
    }

    $email = trim((string)($req['inquiry_email'] ?? ''));
    if ($email === '') {
        $out['ok'] = true;
        $out['email_note'] = 'No email address was given, so nothing could be sent — please call them.';
        return $out;
    }

    try {
        require_once __DIR__ . '/../includes/mailer.php';
        $carLabel = trim((string)$req['year'] . ' ' . $req['make'] . ' ' . $req['model']);
        $res = sendMail($email, (string)$req['inquiry_name'],
                        'Viewing confirmed: ' . $carLabel . ' — ' . date('j M Y', strtotime($date)),
                        showroomViewingEmailBody($req, $loc, $when),
                        'viewing', $id);
        $out['emailed']    = !empty($res['ok']);
        $out['email_note'] = !empty($res['ok'])
            ? 'Confirmation emailed to ' . $email . '.'
            : 'The confirmation email did not go: ' . (string)($res['error'] ?? 'unknown error');
    } catch (\Throwable $e) {
        error_log('showroomConfirmViewing email: ' . $e->getMessage());
        $out['email_note'] = 'The confirmation email could not be sent.';
    }

    $out['ok'] = true;
    return $out;
}

/** Notify the sales/CRM roles that handle inbound web leads. */
function showroomNotifyNewLead(string $title, string $message, string $link): void {
    try {
        require_once __DIR__ . '/../includes/notifications.php';
        notifyRoles(
            ['super_admin', 'admin', 'customer_relations', 'sales_manager', 'sales_officer'],
            'info', $title, $message, $link
        );
    } catch (\Throwable $e) { error_log('showroomNotifyNewLead: ' . $e->getMessage()); }
}
