<?php
/**
 * Carl — the service desk.
 *
 * The booking desk is the busiest keyboard in the building and the one most
 * often used with a phone against the other ear, which is exactly the work a
 * spoken assistant should be taking. So Carl both reads the diary and takes a
 * booking, and a booking she takes is indistinguishable from one typed on
 * modules/service_bookings/add.php — same numbering, same notifications, same
 * confirmation email, same audit line. This file mirrors that page on purpose.
 *
 * Confirming a booking is here too, because a desk that can only take bookings
 * and never move them along is half a desk. Cancelling is not: a booking is
 * cancelled by a person who has spoken to the customer, and Carl has not.
 */

if (!function_exists('carlServiceTypes')) {

// ── What the workshop sells ──────────────────────────────────────────────────

/** The six services, spelled as the booking form spells them. */
function carlServiceTypes(): array
{
    return ['Engine Service', 'Major Service', 'Diagnostics', 'Paint Job', 'Body Work', 'Buffing'];
}

/**
 * Resolve a spoken service to one of the six.
 *
 * Order matters more than it looks. Nearly every phrase contains the word
 * "service", so the specific readings have to be tried before the general one —
 * otherwise "major service" books as an engine service, which is a different
 * job at a different price.
 */
function carlMatchService(string $text): ?string
{
    $t = strtolower($text);
    $map = [
        'Major Service'  => ['major', 'full service', 'big service'],
        'Diagnostics'    => ['diagnostic', 'scan', 'check engine', 'fault', 'error code'],
        'Paint Job'      => ['paint', 'respray', 'spray'],
        'Body Work'      => ['body work', 'bodywork', 'panel', 'dent', 'accident', 'body'],
        'Buffing'        => ['buff', 'polish', 'detailing', 'valet'],
        'Engine Service' => ['engine', 'oil change', 'minor service', 'service', 'servicing'],
    ];
    foreach ($map as $service => $words) {
        foreach ($words as $w) if (str_contains($t, $w)) return $service;
    }
    return null;
}

// ── Finding people and vehicles ──────────────────────────────────────────────

/** Resolve a client from a name, phone or email fragment. */
function carlFindClient(PDO $db, string $hint): ?array
{
    $hint = trim($hint);
    if (strlen($hint) < 3) return null;
    try {
        $like = '%' . $hint . '%';
        $st = $db->prepare(
            "SELECT id, name, phone, email
               FROM clients
              WHERE status = 'active'
                AND (name LIKE ? OR phone LIKE ? OR email LIKE ?)
           ORDER BY (name LIKE ?) DESC, LENGTH(name)
              LIMIT 1"
        );
        $st->execute([$like, $like, $like, $like]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $_) { return null; }
}

/**
 * Resolve a vehicle for servicing.
 *
 * Deliberately wider than carlFindCar(), which only offers stock that can still
 * be sold. A car comes in for service long after it has been sold and delivered,
 * so restricting by status here would hide exactly the vehicles the workshop
 * sees most.
 */
function carlFindServiceCar(PDO $db, string $hint): ?array
{
    $hint = trim($hint);
    if (strlen($hint) < 3) return null;
    try {
        $like = '%' . $hint . '%';
        $st = $db->prepare(
            "SELECT id, make, model, year, registration_number, chassis_number,
                    car_type, status, client_id
               FROM cars
              WHERE registration_number LIKE ?
                 OR chassis_number LIKE ?
                 OR CONCAT_WS(' ', year, make, model) LIKE ?
           ORDER BY (registration_number LIKE ?) DESC,
                    (chassis_number LIKE ?) DESC,
                    updated_at DESC
              LIMIT 1"
        );
        $st->execute([$like, $like, $like, $like, $like]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $_) { return null; }
}

/** The vehicle already on file for a client — theirs, or one we service for them. */
function carlClientCar(PDO $db, int $clientId): ?array
{
    try {
        $st = $db->prepare(
            "SELECT id, make, model, year, registration_number, chassis_number
               FROM cars
              WHERE client_id = ? OR service_client_id = ?
           ORDER BY (client_id = ?) DESC, id
              LIMIT 1"
        );
        $st->execute([$clientId, $clientId, $clientId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $_) { return null; }
}

/** How a vehicle is said out loud: enough to recognise, short enough to hear. */
function carlServiceCarLabel(array $c): string
{
    $label = trim(($c['year'] ?? '') . ' ' . ($c['make'] ?? '') . ' ' . ($c['model'] ?? ''));
    if (!empty($c['registration_number'])) $label .= ' ' . $c['registration_number'];
    $label = trim($label);
    return $label !== '' ? $label : 'the vehicle';
}

/** Today, as the database reckons it. PHP runs UTC here and MySQL runs EAT. */
function carlToday(PDO $db): string
{
    try { return (string)$db->query("SELECT CURDATE()")->fetchColumn(); }
    catch (\Throwable $_) { return date('Y-m-d'); }
}

// ── Reading the diary ────────────────────────────────────────────────────────

function carlSkillBookings(PDO $db, array $user, string $u): array
{
    $link = carlLink(BASE_URL . '/modules/service_bookings/index.php',
                     'Open the diary', 'fa-calendar-check');

    try {
        // Every comparison against "today" is made in SQL: PHP is three hours
        // behind the database here, so a date worked out in PHP is a day wrong
        // for the first three hours of every morning.
        $rows = $db->query(
            "SELECT id, booking_number, client_name, service_type, status,
                    car_make, car_model, car_registration,
                    preferred_date, preferred_time,
                    DATEDIFF(preferred_date, CURDATE()) AS days_away
               FROM service_bookings
              WHERE status IN ('pending','confirmed','in_progress')
           ORDER BY preferred_date IS NULL, preferred_date, preferred_time
              LIMIT 20"
        )->fetchAll(PDO::FETCH_ASSOC);

        $today     = carlNum($db, "SELECT COUNT(*) FROM service_bookings
                                    WHERE preferred_date = CURDATE()
                                      AND status IN ('pending','confirmed','in_progress')");
        $awaiting  = carlNum($db, "SELECT COUNT(*) FROM service_bookings WHERE status = 'pending'");
        $overdue   = carlNum($db, "SELECT COUNT(*) FROM service_bookings
                                    WHERE preferred_date < CURDATE()
                                      AND status IN ('pending','confirmed')");
        $doneMonth = carlNum($db, "SELECT COUNT(*) FROM service_bookings
                                    WHERE status = 'completed'
                                      AND YEAR(updated_at) = YEAR(CURDATE())
                                      AND MONTH(updated_at) = MONTH(CURDATE())");
    } catch (\Throwable $e) {
        error_log('carlSkillBookings: ' . $e->getMessage());
        return ['skill' => 'bookings', 'done' => true,
                'say'   => 'I could not read the service diary just now.', 'html' => $link];
    }

    if (!$rows) {
        return ['skill' => 'bookings', 'done' => true,
                'say'   => $doneMonth > 0
                    ? 'The diary is clear. ' . carlPlural($doneMonth, 'booking has', 'bookings have')
                      . ' been completed this month.'
                    : 'There is nothing in the service diary at the moment.',
                'html'  => carlTiles([['Completed this month', $doneMonth, 'good']]) . $link
                         . carlChips(['Book a service', 'How is the workshop doing'])];
    }

    $cards = '';
    foreach ($rows as $r) {
        $car  = trim(($r['car_make'] ?? '') . ' ' . ($r['car_model'] ?? '')
                     . ' ' . ($r['car_registration'] ?? ''));
        $days = $r['days_away'];
        if ($r['preferred_date'] === null)   $when = 'no date yet';
        elseif ((int)$days === 0)            $when = 'today';
        elseif ((int)$days === 1)            $when = 'tomorrow';
        elseif ((int)$days < 0)              $when = abs((int)$days) . ' days overdue';
        else                                 $when = 'in ' . (int)$days . ' days';
        if ($r['preferred_time'] && (int)$days === 0) $when .= ' at ' . $r['preferred_time'];

        $cards .= '<a class="carl-rec" href="' . BASE_URL . '/modules/service_bookings/view.php?id='
                . (int)$r['id'] . '">'
                . '<b>' . e((string)$r['client_name']) . '</b>'
                . '<span>' . e(($car !== '' ? $car . ' — ' : '') . $r['service_type']) . '</span>'
                . '<em>' . e($when . ' · ' . str_replace('_', ' ', (string)$r['status'])) . '</em></a>';
    }

    $say = carlPlural(count($rows), 'booking is', 'bookings are') . ' open. '
         . ($today > 0 ? carlPlural($today, 'is', 'are') . ' due in today. '
                       : 'None are due in today. ')
         . ($awaiting > 0 ? carlPlural($awaiting, 'is', 'are') . ' still waiting to be confirmed. ' : '')
         . ($overdue > 0 ? carlPlural($overdue, 'booking has', 'bookings have')
                         . ' gone past the date without being seen — those are worth a call.' : '');

    $h = carlTiles([
            ['Due in today',    $today,     $today > 0 ? 'good' : ''],
            ['To confirm',      $awaiting,  $awaiting > 0 ? 'warn' : ''],
            ['Past the date',   $overdue,   $overdue > 0 ? 'bad' : ''],
            ['Done this month', $doneMonth, 'good'],
         ])
       . '<div class="carl-recs">' . $cards . '</div>' . $link
       . carlChips(['Book a service', 'What needs attention?']);

    return ['skill' => 'bookings', 'done' => true, 'say' => trim($say), 'html' => $h];
}

// ── Taking a booking ─────────────────────────────────────────────────────────

/** The questions, in the order the desk actually asks them. */
function carlBookingFields(): array
{
    return [
        'client'  => 'Who is the booking for?',
        'phone'   => 'What number can we reach them on?',
        'car'     => 'Which vehicle? A registration, a chassis number, or the make and model.',
        'service' => 'What needs doing? Engine service, major service, diagnostics, '
                   . 'a paint job, body work or buffing.',
        'when'    => 'When would they like to bring it in? Say a day, or "not sure".',
    ];
}

function carlSkillBookService(PDO $db, array $user, string $u): array
{
    if (!canWrite('service_bookings')) {
        return ['skill' => 'book_service', 'done' => true,
                'say'   => 'Taking a booking needs service desk rights, which your account does '
                         . 'not have. I can show you the diary instead.',
                'html'  => carlLink(BASE_URL . '/modules/service_bookings/index.php',
                                    'Open the diary', 'fa-calendar-check')];
    }
    carlPendingSet($db, (int)$user['id'], 'book_service', [], 'client');
    return ['skill' => 'book_service', 'done' => false,
            'say'   => 'Of course. ' . carlBookingFields()['client'],
            'html'  => '<p class="carl-note">Say "cancel" at any point to stop.</p>'];
}

function carlContinueBookService(PDO $db, array $user, array $pending, string $r): array
{
    $uid  = (int)$user['id'];
    $got  = $pending['collected'];
    $step = (string)$pending['awaiting'];
    $ask  = function (string $say, string $html = '') {
        return ['skill' => 'book_service', 'done' => false, 'say' => $say, 'html' => $html];
    };

    if ($step === 'confirm') {
        if (preg_match('/^(yes|yeah|yep|correct|confirm|save|book it|go ahead|do it|ok|okay)\b/i', $r)) {
            return carlCreateBooking($db, $user, $got);
        }
        if (preg_match('/^(no|nope|wrong|cancel)\b/i', $r)) {
            carlPendingClear($db, $uid);
            return ['skill' => 'book_service', 'done' => true,
                    'say' => 'Dropped — nothing was booked.', 'html' => ''];
        }
        return $ask('Shall I book it? Please say yes or no.');
    }

    $value = trim($r);
    if ($value === '') return $ask(carlBookingFields()[$step] ?? 'Sorry, could you say that again?');

    if ($step === 'client') {
        $got['client'] = $value;
        if ($c = carlFindClient($db, $value)) {
            $got['client']    = $c['name'];
            $got['client_id'] = (int)$c['id'];
            $got['email']     = (string)($c['email'] ?? '');
            // Their number is already on file, so there is no sense asking for it.
            if (!empty($c['phone'])) $got['phone'] = (string)$c['phone'];
            carlContextSet($db, $uid, 'client', (int)$c['id'], $c['name']);
            if ($car = carlClientCar($db, (int)$c['id'])) {
                // The make, model and plate are carried too, not just the id. The
                // diary reads those columns, so a booking with only a car_id shows
                // an empty vehicle to everyone who looks at it afterwards.
                $got['car_suggest']       = carlServiceCarLabel($car);
                $got['car_suggest_id']    = (int)$car['id'];
                $got['car_suggest_make']  = (string)$car['make'];
                $got['car_suggest_model'] = (string)$car['model'];
                $got['car_suggest_reg']   = (string)($car['registration_number'] ?? '');
            }
        }
    } elseif ($step === 'phone') {
        $digits = preg_replace('/\D+/', '', $value);
        if (strlen($digits) < 9) {
            return $ask('That does not look like a full phone number. Could you give it to me again?');
        }
        $got['phone'] = $digits;
    } elseif ($step === 'car') {
        if (!empty($got['car_suggest'])
            && preg_match('/^(yes|yeah|yep|that one|the same|same one|correct|it is|hers|his|theirs)\b/i', $value)) {
            $got['car']    = (string)$got['car_suggest'];
            $got['car_id'] = (int)$got['car_suggest_id'];
            $got['make']   = (string)($got['car_suggest_make'] ?? '');
            $got['model']  = (string)($got['car_suggest_model'] ?? '');
            $got['reg']    = (string)($got['car_suggest_reg'] ?? '');
        } elseif (preg_match('/^(none|no car|not sure|unknown|n\/?a|skip)$/i', $value)) {
            $got['car'] = '';
        } elseif ($found = carlFindServiceCar($db, $value)) {
            $got['car']    = carlServiceCarLabel($found);
            $got['car_id'] = (int)$found['id'];
            $got['make']   = (string)$found['make'];
            $got['model']  = (string)$found['model'];
            $got['reg']    = (string)($found['registration_number'] ?? '');
        } else {
            // A vehicle we have never seen is the ordinary case at a service desk,
            // so this must not be a dead end. The words are kept as they were said
            // and the booking carries them as free text, exactly as the form allows.
            $got['car'] = $value;
            if (preg_match('/^[A-Z]{1,3}\s?\d{3}\s?[A-Z]?$/i', $value)) {
                $got['reg'] = strtoupper(preg_replace('/\s+/', ' ', $value));
            } else {
                $parts        = preg_split('/\s+/', $value, 2);
                $got['make']  = $parts[0];
                $got['model'] = $parts[1] ?? '';
            }
            $got['car_unknown'] = 1;
        }
    } elseif ($step === 'service') {
        $svc = carlMatchService($value);
        if (!$svc) {
            return $ask('I did not catch which service. Is it an engine service, a major service, '
                      . 'diagnostics, a paint job, body work or buffing?');
        }
        $got['service'] = $svc;
    } elseif ($step === 'when') {
        if (preg_match('/^(not sure|any ?time|whenever|no rush|unknown|soon|to be confirmed|tbc)$/i', $value)) {
            $got['when'] = '';
        } else {
            $date = carlParseDate($value);
            if (!$date) {
                return $ask('I did not catch the date. You can say tomorrow, a day of the week, '
                          . 'or a date like 12/03/2026 — or "not sure".');
            }
            if ($date < carlToday($db)) {
                return $ask('That date has already gone. When would they like to bring it in?');
            }
            $got['when'] = $date;
        }
    }

    // Ask for whatever is still missing. Anything already known — a number taken
    // from the client's record, a vehicle already on file — is simply skipped.
    foreach (carlBookingFields() as $k => $question) {
        if (array_key_exists($k, $got)) continue;
        carlPendingSet($db, $uid, 'book_service', $got, $k);
        if ($k === 'car' && !empty($got['car_suggest'])) {
            return $ask('We have ' . $got['car_suggest'] . ' on file for them. Is it that one?');
        }
        return $ask($question);
    }

    carlPendingSet($db, $uid, 'book_service', $got, 'confirm');

    $when = $got['when'] === '' ? 'To be arranged'
                                : date('D j M Y', strtotime((string)$got['when']));
    $h = '<div class="carl-confirm">'
       . '<div class="r"><span>Client</span><b>' . e((string)$got['client']) . '</b></div>'
       . '<div class="r"><span>Phone</span><b>' . e((string)$got['phone']) . '</b></div>'
       . '<div class="r"><span>Vehicle</span><b>'
       . e($got['car'] !== '' ? (string)$got['car'] : 'Not given') . '</b></div>'
       . '<div class="r"><span>Service</span><b>' . e((string)$got['service']) . '</b></div>'
       . '<div class="r"><span>Coming in</span><b>' . e($when) . '</b></div></div>';

    $say = 'So that is ' . $got['service'] . ' for ' . $got['client']
         . ($got['car'] !== '' ? ' on the ' . $got['car'] : '')
         . ($got['when'] === '' ? ', date to be arranged' : ', ' . $when) . '. '
         . (!empty($got['car_unknown'])
             ? 'That vehicle is not on the system, so I have noted it as you said it. ' : '')
         . (!empty($got['email']) ? 'They will get a confirmation email. ' : '')
         . 'Shall I book it?';

    return $ask($say, $h);
}

function carlCreateBooking(PDO $db, array $user, array $got): array
{
    $uid = (int)$user['id'];
    carlPendingClear($db, $uid);

    if (!canWrite('service_bookings')) {
        return ['skill' => 'book_service', 'done' => true,
                'say' => 'Taking a booking needs service desk rights.', 'html' => ''];
    }

    $make = (string)($got['make'] ?? '');
    $mod  = (string)($got['model'] ?? '');
    $reg  = (string)($got['reg'] ?? '');

    try {
        $bNum = nextNumber('service_bookings', 'booking_number', 'BK');
        $ok = carlGuardedExec($db,
            "INSERT INTO service_bookings
                (booking_number, client_id, client_name, client_email, client_phone,
                 car_id, car_make, car_model, car_registration, car_description,
                 service_type, description, booking_date, preferred_date,
                 admin_notes, created_by)
             VALUES (?,?,?,?,?, ?,?,?,?,?, ?,?, CURDATE(), ?, ?,?)",
            [
                $bNum,
                !empty($got['client_id']) ? (int)$got['client_id'] : null,
                (string)$got['client'],
                (string)($got['email'] ?? ''),
                (string)$got['phone'],
                !empty($got['car_id']) ? (int)$got['car_id'] : null,
                $make, $mod, $reg,
                trim((string)$got['car']),
                (string)$got['service'],
                'Taken by ' . CARL_NAME . ' over the phone.',
                $got['when'] !== '' ? (string)$got['when'] : null,
                'Booked through ' . CARL_NAME . ' by ' . $user['name'] . ' on ' . date('j M Y') . '.',
                $user['name'],
            ]
        );
        if (!$ok) throw new \RuntimeException('the write was refused');
        $id = (int)$db->lastInsertId();
    } catch (\Throwable $e) {
        error_log('carlCreateBooking: ' . $e->getMessage());
        return ['skill' => 'book_service', 'done' => true,
                'say'   => 'I could not save the booking — something went wrong at my end. Nothing '
                         . 'was saved, so please take it on the bookings page.',
                'html'  => carlLink(BASE_URL . '/modules/service_bookings/add.php',
                                    'New service booking', 'fa-plus')];
    }

    // The booking exists from here on, and nothing below is worth losing it over.
    // notifyRoles() lives in a file this one had not loaded, and that alone was
    // enough to tell somebody "nothing was saved" about a row sitting in the
    // table — which is how a customer ends up booked in twice.
    try {
        require_once __DIR__ . '/../../includes/notifications.php';
        require_once __DIR__ . '/../../includes/mailer.php';

        logActivity('create', 'service_bookings', $id,
            'Booking ' . $bNum . ' for ' . $got['client'] . ' taken via ' . CARL_NAME
            . ' by ' . $user['name'] . '.');
        notifyRoles(['admin', 'workshop_manager', 'sales_officer'], 'booking',
            'New Booking: ' . $bNum,
            $got['client'] . ' — ' . $got['service'],
            BASE_URL . '/modules/service_bookings/view.php?id=' . $id);

        // The same confirmation the form sends, under the same setting. The user
        // was told it would go out before they said yes.
        $email = (string)($got['email'] ?? '');
        if (getSetting('alert_email_booking', '1') === '1'
            && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $subj = 'Booking Confirmation — ' . $bNum;
            $when = $got['when'] !== ''
                  ? date('d M Y', strtotime((string)$got['when'])) : 'To be confirmed';
            $body = '<p>Dear ' . e((string)$got['client']) . ',</p>'
                  . '<p>Thank you! Your service booking has been received. Here are the details:</p>'
                  . "<table class='data'>"
                  . '<tr><th>Booking No.</th><td><strong>' . e($bNum) . '</strong></td></tr>'
                  . '<tr><th>Service</th><td>' . e((string)$got['service']) . '</td></tr>'
                  . '<tr><th>Vehicle</th><td>'
                  . e($got['car'] !== '' ? (string)$got['car'] : 'Not specified') . '</td></tr>'
                  . '<tr><th>Preferred Date</th><td>' . e($when) . '</td></tr>'
                  . '</table>'
                  . '<p>We will contact you shortly to confirm your appointment.</p>';
            try {
                sendMail($email, (string)$got['client'], $subj, mailTemplate($subj, $body),
                         'service_booking', $id);
            } catch (\Throwable $e) {
                error_log('carlCreateBooking mail: ' . $e->getMessage());
            }
        }
    } catch (\Throwable $e) {
        error_log('carlCreateBooking after-save: ' . $e->getMessage());
    }

    $whenSay = $got['when'] !== '' ? ' for ' . date('D j M', strtotime((string)$got['when'])) : '';
    return ['skill' => 'book_service', 'done' => true,
            'say'   => 'Booked. ' . $bNum . ' is in the diary for ' . $got['client'] . $whenSay
                     . '. It is waiting to be confirmed — the workshop has been told.',
            'html'  => carlLink(BASE_URL . '/modules/service_bookings/view.php?id=' . $id,
                                'Open booking ' . $bNum, 'fa-calendar-check')
                     . carlChips(['What is booked for today', 'Confirm ' . $bNum])];
}

// ── Confirming one ───────────────────────────────────────────────────────────

/** Find a booking by its number, or failing that by who it is for. */
function carlFindBooking(PDO $db, string $hint): ?array
{
    $hint = trim($hint);
    try {
        if (preg_match('/\b(BK[\s-]?\d{3,})\b/i', $hint, $m)) {
            $num = strtoupper(preg_replace('/[\s-]/', '', $m[1]));
            $st  = $db->prepare(
                "SELECT * FROM service_bookings
                  WHERE REPLACE(REPLACE(booking_number,'-',''),' ','') = ? LIMIT 1"
            );
            $st->execute([$num]);
            if ($row = $st->fetch(PDO::FETCH_ASSOC)) return $row;
        }
        // Strip the words of the instruction so that what is left is the name.
        $name = preg_replace(
            '/\b(please|can you|could you|confirm|confirmed|the|booking|bookings|service|for|of|carl)\b/i',
            ' ', $hint);
        $name = trim(preg_replace('/\s+/', ' ', (string)$name));
        if (strlen($name) < 3) return null;
        $st = $db->prepare(
            "SELECT * FROM service_bookings
              WHERE client_name LIKE ? AND status IN ('pending','confirmed')
           ORDER BY FIELD(status,'pending','confirmed'), preferred_date IS NULL, preferred_date
              LIMIT 1"
        );
        $st->execute(['%' . $name . '%']);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $_) { return null; }
}

function carlSkillConfirmBooking(PDO $db, array $user, string $u): array
{
    $diary = carlLink(BASE_URL . '/modules/service_bookings/index.php',
                      'Open the diary', 'fa-calendar-check');

    if (!canWrite('service_bookings')) {
        return ['skill' => 'confirm_booking', 'done' => true,
                'say'   => 'Confirming a booking needs service desk rights, which your account '
                         . 'does not have.', 'html' => $diary];
    }

    $b = carlFindBooking($db, $u);
    if (!$b) {
        return ['skill' => 'confirm_booking', 'done' => true,
                'say'   => 'I could not tell which booking you mean. Give me the booking number, '
                         . 'or the name it is under.', 'html' => $diary];
    }
    if ($b['status'] === 'confirmed') {
        return ['skill' => 'confirm_booking', 'done' => true,
                'say'   => $b['booking_number'] . ' for ' . $b['client_name']
                         . ' is already confirmed. I have left it as it is.',
                'html'  => carlLink(BASE_URL . '/modules/service_bookings/view.php?id=' . (int)$b['id'],
                                    'Open ' . $b['booking_number'], 'fa-calendar-check')];
    }
    if ($b['status'] !== 'pending') {
        return ['skill' => 'confirm_booking', 'done' => true,
                'say'   => $b['booking_number'] . ' is already '
                         . str_replace('_', ' ', (string)$b['status'])
                         . ', so there is nothing to confirm.', 'html' => $diary];
    }

    carlPendingSet($db, (int)$user['id'], 'confirm_booking',
        ['id' => (int)$b['id'], 'number' => $b['booking_number'], 'client' => $b['client_name']],
        'confirm');

    $when = $b['preferred_date']
          ? date('D j M Y', strtotime((string)$b['preferred_date'])) : 'no date yet';
    $h = '<div class="carl-confirm">'
       . '<div class="r"><span>Booking</span><b>' . e((string)$b['booking_number']) . '</b></div>'
       . '<div class="r"><span>Client</span><b>' . e((string)$b['client_name']) . '</b></div>'
       . '<div class="r"><span>Service</span><b>' . e((string)$b['service_type']) . '</b></div>'
       . '<div class="r"><span>Coming in</span><b>' . e($when) . '</b></div></div>';

    return ['skill' => 'confirm_booking', 'done' => false,
            'say'   => $b['booking_number'] . ' is ' . $b['service_type'] . ' for '
                     . $b['client_name'] . ', ' . $when . '. Shall I confirm it?',
            'html'  => $h];
}

function carlContinueConfirmBooking(PDO $db, array $user, array $pending, string $r): array
{
    $uid = (int)$user['id'];
    $got = $pending['collected'];

    if (preg_match('/^(no|nope|not yet|wrong|cancel)\b/i', $r)) {
        carlPendingClear($db, $uid);
        return ['skill' => 'confirm_booking', 'done' => true,
                'say' => 'Left as it was — still waiting to be confirmed.', 'html' => ''];
    }
    if (!preg_match('/^(yes|yeah|yep|correct|confirm|go ahead|do it|ok|okay)\b/i', $r)) {
        return ['skill' => 'confirm_booking', 'done' => false,
                'say' => 'Shall I confirm it? Please say yes or no.', 'html' => ''];
    }

    carlPendingClear($db, $uid);
    $id = (int)$got['id'];

    $ok = carlGuardedExec($db,
        "UPDATE service_bookings SET status='confirmed', updated_at=NOW()
          WHERE id = ? AND status = 'pending'", [$id]);
    if (!$ok) {
        return ['skill' => 'confirm_booking', 'done' => true,
                'say'   => 'I could not confirm it — nothing was changed. Please do it on the '
                         . 'booking itself.',
                'html'  => carlLink(BASE_URL . '/modules/service_bookings/view.php?id=' . $id,
                                    'Open the booking', 'fa-calendar-check')];
    }

    // Already confirmed in the table; telling people is a courtesy, not a condition.
    try {
        require_once __DIR__ . '/../../includes/notifications.php';
        logActivity('update', 'service_bookings', $id,
            'Booking ' . $got['number'] . ' confirmed via ' . CARL_NAME
            . ' by ' . $user['name'] . '.');
        notifyRoles(['admin', 'workshop_manager'], 'booking',
            'Booking confirmed: ' . $got['number'],
            $got['client'] . ' is expected as booked.',
            BASE_URL . '/modules/service_bookings/view.php?id=' . $id);
    } catch (\Throwable $e) { error_log('carlConfirmBooking notify: ' . $e->getMessage()); }

    return ['skill' => 'confirm_booking', 'done' => true,
            'say'   => 'Confirmed. ' . $got['number'] . ' is set for ' . $got['client']
                     . ' and the workshop has been told.',
            'html'  => carlLink(BASE_URL . '/modules/service_bookings/view.php?id=' . $id,
                                'Open ' . $got['number'], 'fa-calendar-check')
                     . carlChips(['What is booked for today'])];
}

} // function_exists('carlServiceTypes')
