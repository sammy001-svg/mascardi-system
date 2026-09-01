<?php
/**
 * Carl — tasks that change records, and the reporting that surrounds them.
 *
 * Kept apart from _skills.php because the rules here are not Carl's own. A
 * reservation made by talking to Carl must land exactly where a reservation made
 * on the lead page lands: same approval routing, same notifications, same audit
 * line. The moment those drift, the spoken path becomes a way around the process
 * rather than another door into it — so this file deliberately mirrors
 * modules/crm/view_lead.php rather than inventing its own shorter version.
 *
 * Deliveries are read-only here on purpose. The six-step protocol has approval
 * gates, converts the buyer into a client and settles consignment money as it
 * completes; that is not something to drive from a sentence that might have been
 * misheard. Carl reports where each deal has stalled and links to the record.
 */

// ── Finding things ───────────────────────────────────────────────────────────

/** Resolve a car from a loose phrase: registration, chassis, or make and model. */
function carlFindCar(PDO $db, string $hint): ?array
{
    $hint = trim($hint);
    if ($hint === '') return null;
    try {
        $like = '%' . $hint . '%';
        // Registration is the only thing people say that is genuinely unique, so
        // matches on it outrank a loose make/model phrase.
        $st = $db->prepare(
            "SELECT id, make, model, year, registration_number, chassis_number,
                    asking_price, status
               FROM cars
              WHERE status IN ('arrived','in_assessment','completed','in_transit')
                AND (registration_number LIKE ? OR CONCAT_WS(' ', make, model) LIKE ?
                     OR chassis_number LIKE ?)
           ORDER BY (registration_number LIKE ?) DESC, updated_at DESC
              LIMIT 1"
        );
        $st->execute([$like, $like, $like, $like]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $_) { return null; }
}

/** A short label for a car, the way someone would say it aloud. */
function carlCarLabel(array $c): string
{
    $s = trim(($c['year'] ?? '') . ' ' . ($c['make'] ?? '') . ' ' . ($c['model'] ?? ''));
    if (!empty($c['registration_number'])) $s .= ' (' . $c['registration_number'] . ')';
    return $s !== '' ? $s : 'the vehicle';
}

/** Read an amount out of speech: "250,000", "ksh 250k", "two hundred thousand". */
function carlParseMoney(string $s): ?float
{
    $t = strtolower(trim($s));
    $t = str_replace([',', 'ksh', 'kes', 'shillings', 'shs', '/-', '/='], ' ', $t);

    if (preg_match('/(\d+(?:\.\d+)?)\s*([km])\b/', $t, $m)) {
        return (float)$m[1] * ($m[2] === 'k' ? 1000 : 1000000);
    }
    if (preg_match('/(\d+(?:\.\d+)?)\s*(thousand|million)/', $t, $m)) {
        return (float)$m[1] * ($m[2] === 'thousand' ? 1000 : 1000000);
    }
    if (preg_match('/(\d[\d\s]*(?:\.\d+)?)/', $t, $m)) {
        $n = (float)preg_replace('/\s+/', '', $m[1]);
        return $n > 0 ? $n : null;
    }
    return null;
}

// ── reserve — take a deposit and hold a vehicle ──────────────────────────────

function carlSkillReserve(PDO $db, array $user, string $u): array
{
    $who = carlContextGet($db, (int)$user['id'], 'lead');
    carlPendingSet($db, (int)$user['id'], 'reserve', [], 'lead_name');
    return ['skill' => 'reserve', 'done' => false,
            'say'   => $who
                ? 'I can start that. Is the reservation for ' . $who['label']
                  . '? Say yes, or give me another name.'
                : 'I can start that. Which customer is the reservation for? '
                  . 'Give me their name or their phone number.',
            'html'  => '<p class="carl-note">Say "cancel" at any point to stop.</p>'];
}

function carlContinueReserve(PDO $db, array $user, array $pending, string $r): array
{
    $uid  = (int)$user['id'];
    $got  = $pending['collected'];
    $step = (string)$pending['awaiting'];
    $ask  = function (string $say, string $html = '') {
        return ['skill' => 'reserve', 'done' => false, 'say' => $say, 'html' => $html];
    };

    // 1 — who is it for
    if ($step === 'lead_name') {
        // "yes", "him", "the same" — take the subject we offered rather than
        // searching for a customer literally called "yes".
        $lead = null;
        if (carlMeansTheSame($r)) {
            $ctx = carlContextGet($db, $uid, 'lead');
            if ($ctx) {
                $q = $db->prepare("SELECT id, name, phone, stage, assigned_to, follow_up_date
                                      FROM crm_leads WHERE id = ?");
                $q->execute([$ctx['id']]);
                $lead = $q->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }
        if (!$lead) $lead = carlFindLead($db, $r);
        if (!$lead) {
            return $ask('I could not find a lead by that name. Try the surname on its own, '
                      . 'or the phone number.');
        }
        $got['lead_id']   = (int)$lead['id'];
        $got['lead_name'] = $lead['name'];

        // Already reserved? Say so rather than quietly writing over it.
        $st = $db->prepare("SELECT stage, reservation_status FROM crm_leads WHERE id = ?");
        $st->execute([$got['lead_id']]);
        $cur     = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $already = ($cur['stage'] ?? '') === 'reserved'
                || ($cur['reservation_status'] ?? '') === 'pending_approval';

        if ($already && !isSuperAdmin()) {
            carlPendingClear($db, $uid);
            return ['skill' => 'reserve', 'done' => true,
                    'say'   => $lead['name'] . ' already has a reservation on file, and only a '
                             . 'super admin can change one that already exists.',
                    'html'  => carlLink(BASE_URL . '/modules/crm/view_lead.php?id=' . $got['lead_id'],
                                        'Open ' . $lead['name'], 'fa-user')];
        }

        carlPendingSet($db, $uid, 'reserve', $got, 'car');
        $say = $already
            ? $lead['name'] . ' already has a reservation — I will update it. Which vehicle?'
            : 'Which vehicle is ' . $lead['name'] . ' reserving? '
              . 'The registration, or the make and model.';
        return $ask($say, carlLeadMini($lead));
    }

    // 2 — which car
    if ($step === 'car') {
        $car = carlFindCar($db, $r);
        if (!$car) {
            return $ask('I could not match that to a vehicle on the yard. Try the registration '
                      . 'number, or just the make and model.');
        }
        if (in_array($car['status'] ?? '', ['reserved', 'sold', 'delivered'], true)) {
            return $ask(carlCarLabel($car) . ' is already ' . $car['status']
                      . '. Which other vehicle should I use?');
        }
        $got['car_id']    = (int)$car['id'];
        $got['car_label'] = carlCarLabel($car);
        carlPendingSet($db, $uid, 'reserve', $got, 'deposit');

        $h = '<div class="carl-confirm"><div class="r"><span>Vehicle</span><b>'
           . e($got['car_label']) . '</b></div>'
           . (!empty($car['asking_price'])
               ? '<div class="r"><span>Asking</span><b>'
                 . e(carlMoney((float)$car['asking_price'])) . '</b></div>'
               : '')
           . '</div>';
        return $ask('How much is the deposit?', $h);
    }

    // 3 — how much
    if ($step === 'deposit') {
        $amt = carlParseMoney($r);
        if ($amt === null || $amt <= 0) {
            return $ask('I did not catch an amount there. How much is the deposit?');
        }
        $got['deposit'] = $amt;
        carlPendingSet($db, $uid, 'reserve', $got, 'confirm');

        $now = isSuperAdmin();
        $say = 'Let me read it back. ' . $got['lead_name'] . ', ' . $got['car_label']
             . ', deposit ' . carlMoney($amt) . '. '
             . ($now ? 'Shall I save it and hold the vehicle?'
                     : 'Shall I submit it for super admin approval?');

        $h = '<div class="carl-confirm">'
           . '<div class="r"><span>Customer</span><b>' . e($got['lead_name']) . '</b></div>'
           . '<div class="r"><span>Vehicle</span><b>'  . e($got['car_label'])  . '</b></div>'
           . '<div class="r"><span>Deposit</span><b>'  . e(carlMoney($amt))    . '</b></div>'
           . '<div class="r"><span>On saving</span><b>'
           . ($now ? 'Reserved immediately' : 'Held for approval') . '</b></div></div>'
           . '<div class="carl-chips">'
           . '<button type="button" class="carl-chip carl-yes" data-ask="yes">'
           . ($now ? 'Reserve it' : 'Submit it') . '</button>'
           . '<button type="button" class="carl-chip" data-ask="no">Cancel</button></div>';
        return $ask($say, $h);
    }

    // 4 — yes or no
    if ($step === 'confirm') {
        if (preg_match('/^(yes|yeah|yep|correct|confirm|save|submit|go ahead|do it|ok|okay|reserve it)\b/i', $r)) {
            return carlCreateReservation($db, $user, $got);
        }
        if (preg_match('/^(no|nope|wrong|cancel)\b/i', $r)) {
            carlPendingClear($db, $uid);
            return ['skill' => 'reserve', 'done' => true,
                    'say'   => 'Dropped — nothing was saved.', 'html' => ''];
        }
        return $ask('Shall I go ahead? Please say yes or no.');
    }

    carlPendingClear($db, $uid);
    return carlSkillUnknown($user);
}

/**
 * Writes the reservation.
 *
 * The same two paths as the lead page: a super admin's reservation takes effect
 * at once and holds the car; anyone else's is recorded as pending and changes
 * neither the lead stage nor the vehicle until it has been approved.
 */
function carlCreateReservation(PDO $db, array $user, array $got): array
{
    $uid = (int)$user['id'];
    carlPendingClear($db, $uid);

    $leadId  = (int)$got['lead_id'];
    $carId   = (int)$got['car_id'];
    $deposit = (float)$got['deposit'];
    $note    = 'Reserved via ' . CARL_NAME . ' by ' . $user['name'] . '.';
    $url     = BASE_URL . '/modules/crm/view_lead.php?id=' . $leadId;
    $super   = isSuperAdmin();

    try {
        require_once __DIR__ . '/../../includes/notifications.php';

        if ($super) {
            $db->prepare(
                "UPDATE crm_leads
                    SET stage = 'reserved', reservation_status = 'approved',
                        pinned_car_id = COALESCE(?, pinned_car_id),
                        deposit_amount = ?, deposit_date = CURDATE(), deposit_notes = ?,
                        updated_at = NOW()
                  WHERE id = ?"
            )->execute([$carId, $deposit, $note, $leadId]);
            $db->prepare("UPDATE cars SET status = 'reserved', updated_at = NOW() WHERE id = ?")
               ->execute([$carId]);

            notifyRoles(['admin', 'sales_manager', 'general_manager'], 'sale',
                'Vehicle Reserved: ' . $got['lead_name'],
                'Deposit: ' . carlMoney($deposit) . ' — ' . $note, $url);
            logActivity('update', 'crm_leads', $leadId,
                'Reservation saved via ' . CARL_NAME . ' by ' . $user['name']
                . '. Deposit: ' . number_format($deposit, 2));
        } else {
            $db->prepare(
                "UPDATE crm_leads
                    SET reservation_status = 'pending_approval',
                        pinned_car_id = COALESCE(?, pinned_car_id),
                        deposit_amount = ?, deposit_date = CURDATE(), deposit_notes = ?,
                        updated_at = NOW()
                  WHERE id = ?"
            )->execute([$carId, $deposit, $note, $leadId]);

            notifyRoles(['super_admin', 'admin'], 'alert',
                'Reservation Approval Needed: ' . $got['lead_name'],
                'Submitted by ' . $user['name'] . ' through ' . CARL_NAME
                . '. Deposit: ' . carlMoney($deposit) . ' — review and approve.',
                $url . '&dp_open=1');
            logActivity('update', 'crm_leads', $leadId,
                'Reservation submitted for approval via ' . CARL_NAME . ' by ' . $user['name']
                . '. Deposit: ' . number_format($deposit, 2));
        }
    } catch (\Throwable $e) {
        error_log('carlCreateReservation: ' . $e->getMessage());
        return ['skill' => 'reserve', 'done' => true,
                'say'   => 'I could not save that — something went wrong at my end. Nothing was '
                         . 'recorded, so please make the reservation from the lead page.',
                'html'  => carlLink($url, 'Open ' . $got['lead_name'], 'fa-user')];
    }

    $say = $super
        ? 'Done. ' . $got['lead_name'] . ' has reserved the ' . $got['car_label']
          . ' with a deposit of ' . carlMoney($deposit) . '. The vehicle is held, and the '
          . 'proforma and sales agreement are ready on the lead.'
        : 'Submitted. The reservation for ' . $got['lead_name'] . ' on the ' . $got['car_label']
          . ' is with the super admin for approval. The vehicle stays available until it is '
          . 'approved, and I have notified them.';

    $h = '<div class="carl-confirm">'
       . '<div class="r"><span>Customer</span><b>' . e($got['lead_name']) . '</b></div>'
       . '<div class="r"><span>Vehicle</span><b>'  . e($got['car_label'])  . '</b></div>'
       . '<div class="r"><span>Deposit</span><b>'  . e(carlMoney($deposit)) . '</b></div>'
       . '<div class="r"><span>Status</span><b>'
       . ($super ? 'Reserved' : 'Awaiting approval') . '</b></div></div>'
       . carlLink($url, 'Open ' . $got['lead_name'], 'fa-user');

    if ($super) {
        $h .= carlLink(BASE_URL . '/modules/crm/proforma.php?lead_id=' . $leadId,
                       'Proforma invoice', 'fa-file-invoice');
        $h .= carlLink(BASE_URL . '/modules/crm/deposit_receipt.php?lead_id=' . $leadId,
                       'Deposit receipt', 'fa-receipt');
    }
    $h .= carlChips(['Show reservations', 'What needs attention?']);

    return ['skill' => 'reserve', 'done' => true, 'say' => $say, 'html' => $h];
}

// ── deliveries — where each sale has reached, and what is holding it ─────────

/**
 * The six protocol steps in order, with the column that proves each is done and
 * the words to describe what is being waited on.
 */
function carlDeliverySteps(): array
{
    return [
        ['s1_approved_at',   'reservation approval'],
        ['s2_workshop_done_at', 'pre-delivery service'],
        ['s3_completed_at',  'registration'],
        ['s4_completed_at',  'insurance'],
        ['s5_confirmed_at',  'handover items'],
        ['s6_approved_at',   'delivery note confirmation'],
    ];
}

function carlSkillDeliveries(PDO $db, array $user, string $u): array
{
    $steps = carlDeliverySteps();

    try {
        // Everything reserved or sold but not yet handed over, plus its protocol row.
        $rows = $db->query(
            "SELECT l.id, l.name, l.delivered_at, l.stage,
                    c.make, c.model, c.year, c.registration_number,
                    d.s1_approved_at, d.s2_workshop_done_at, d.s3_completed_at,
                    d.s4_completed_at, d.s5_confirmed_at, d.s6_approved_at
               FROM crm_leads l
          LEFT JOIN cars c ON c.id = l.pinned_car_id
          LEFT JOIN crm_delivery_protocol d ON d.lead_id = l.id
              WHERE l.stage IN ('reserved','won')
                AND l.delivered_at IS NULL
           ORDER BY l.updated_at DESC
              LIMIT 25"
        )->fetchAll(PDO::FETCH_ASSOC);

        $doneMonth = (int)$db->query(
            "SELECT COUNT(*) FROM crm_leads
              WHERE delivered_at IS NOT NULL
                AND YEAR(delivered_at) = YEAR(CURDATE())
                AND MONTH(delivered_at) = MONTH(CURDATE())"
        )->fetchColumn();
    } catch (\Throwable $e) {
        error_log('carlSkillDeliveries: ' . $e->getMessage());
        return ['skill' => 'deliveries', 'done' => true,
                'say'   => 'I could not read the delivery pipeline just now.',
                'html'  => carlLink(BASE_URL . '/modules/delivered_cars/index.php',
                                    'Open delivered cars', 'fa-truck')];
    }

    $waiting = count($rows);
    if ($waiting === 0) {
        $say = $doneMonth > 0
            ? 'Nothing is waiting to be delivered. '
              . carlPlural($doneMonth, 'vehicle has', 'vehicles have')
              . ' gone out this month.'
            : 'There is nothing in the delivery pipeline at the moment.';
        return ['skill' => 'deliveries', 'done' => true, 'say' => $say,
                'html'  => carlTiles([['Delivered this month', $doneMonth, 'good']])
                         . carlLink(BASE_URL . '/modules/delivered_cars/index.php',
                                    'Delivered cars', 'fa-truck')];
    }

    // Work out the first incomplete step for each deal, and tally the blockages.
    $stuck = [];
    $cards = '';
    foreach ($rows as $r) {
        $at = 'reservation approval';
        foreach ($steps as [$col, $label]) {
            if (empty($r[$col])) { $at = $label; break; }
            $at = 'ready to hand over';
        }
        $stuck[$at] = ($stuck[$at] ?? 0) + 1;

        $car = trim(($r['year'] ?? '') . ' ' . ($r['make'] ?? '') . ' ' . ($r['model'] ?? ''));
        $cards .= '<a class="carl-rec" href="' . BASE_URL . '/modules/crm/view_lead.php?id='
                . (int)$r['id'] . '&dp_open=1">'
                . '<b>' . e($r['name']) . '</b>'
                . '<span>' . e($car !== '' ? $car : 'no vehicle pinned') . '</span>'
                . '<em>waiting on ' . e($at) . '</em></a>';
    }
    arsort($stuck);
    $top      = array_key_first($stuck);
    $topCount = $stuck[$top];

    $say = carlPlural($waiting, 'vehicle is', 'vehicles are')
         . ' in the delivery pipeline. The most common hold-up is ' . $top
         . ' — ' . $topCount . ' of them. '
         . ($doneMonth > 0
             ? carlPlural($doneMonth, 'has', 'have') . ' gone out this month.'
             : 'None have gone out this month yet.');

    $h = carlTiles([
        ['In the pipeline',      $waiting,   $waiting > 0 ? 'warn' : ''],
        ['Waiting on ' . $top,   $topCount,  'warn'],
        ['Delivered this month', $doneMonth, 'good'],
    ]);
    $h .= '<div class="carl-recs">' . $cards . '</div>';
    $h .= carlLink(BASE_URL . '/modules/delivered_cars/index.php', 'Delivered cars', 'fa-truck');
    $h .= carlChips(['Show reservations', 'What needs attention?']);

    return ['skill' => 'deliveries', 'done' => true, 'say' => $say, 'html' => $h];
}

// ── add_car — put a vehicle into inventory ──────────────────────────────────
//
// The fields asked for are only the four the cars table actually requires, plus
// the registration, which is what people identify a car by day to day. Price,
// photographs, website copy and the rest belong on the vehicle page where there
// is room for them — asking for twenty things in a conversation is how a
// two-minute job becomes a five-minute one.

/** What the conversation asks for, in order. */
function carlCarFields(): array
{
    return [
        'make'    => 'What make is it? Toyota, BMW, Mercedes and so on.',
        'model'   => 'And the model?',
        'year'    => 'What year?',
        'chassis' => 'What is the chassis number?',
        'reg'     => 'And the registration? Say "none" if it has not been plated yet.',
    ];
}

function carlSkillAddCar(PDO $db, array $user, string $u): array
{
    if (!canWrite('cars')) {
        return ['skill' => 'add_car', 'done' => true,
                'say'   => 'Adding a vehicle needs stock rights, which your account does not '
                         . 'have. A manager can add it, or I can note it against a lead for you.',
                'html'  => ''];
    }
    carlPendingSet($db, (int)$user['id'], 'add_car', [], 'make');
    return ['skill' => 'add_car', 'done' => false,
            'say'   => 'Of course. ' . carlCarFields()['make'],
            'html'  => '<p class="carl-note">Say "cancel" at any point to stop.</p>'];
}

function carlContinueAddCar(PDO $db, array $user, array $pending, string $r): array
{
    $uid  = (int)$user['id'];
    $got  = $pending['collected'];
    $step = (string)$pending['awaiting'];
    $ask  = function (string $say, string $html = '') {
        return ['skill' => 'add_car', 'done' => false, 'say' => $say, 'html' => $html];
    };

    if ($step === 'confirm') {
        if (preg_match('/^(yes|yeah|yep|correct|confirm|save|add it|go ahead|do it|ok|okay)\b/i', $r)) {
            return carlCreateCar($db, $user, $got);
        }
        if (preg_match('/^(no|nope|wrong|cancel)\b/i', $r)) {
            carlPendingClear($db, $uid);
            return ['skill' => 'add_car', 'done' => true,
                    'say' => 'Dropped — nothing was added.', 'html' => ''];
        }
        return $ask('Shall I add it? Please say yes or no.');
    }

    $value = trim($r);

    if ($step === 'year') {
        // Spoken years arrive as "twenty twenty one" or with stray words around them.
        if (!preg_match('/\b(19[5-9]\d|20[0-4]\d)\b/', $value, $m)) {
            return $ask('That does not look like a year. Which year is it, as four digits?');
        }
        $value = $m[1];
    }

    if ($step === 'chassis') {
        $value = strtoupper(preg_replace('/\s+/', '', $value));
        if (strlen($value) < 5) {
            return $ask('That looks too short for a chassis number. Could you give it to me again?');
        }
        // Named up front rather than letting the insert fail on the unique key.
        $c = $db->prepare("SELECT id, make, model, car_type FROM cars WHERE chassis_number = ?");
        $c->execute([$value]);
        if ($clash = $c->fetch(PDO::FETCH_ASSOC)) {
            carlPendingClear($db, $uid);
            return ['skill' => 'add_car', 'done' => true,
                    'say'   => 'That chassis number is already on the system — '
                             . trim($clash['make'] . ' ' . $clash['model'])
                             . ', held as ' . $clash['car_type'] . '. I have not added a second one.',
                    'html'  => carlLink(BASE_URL . '/modules/cars/view.php?id=' . (int)$clash['id'],
                                        'Open the vehicle already on file', 'fa-car')];
        }
    }

    if ($step === 'reg') {
        $value = preg_match('/^(none|no|not yet|n\/a|skip)$/i', $value)
               ? '' : strtoupper($value);
    }

    if ($step !== 'reg' && $value === '') {
        return $ask(carlCarFields()[$step] ?? 'Sorry, could you say that again?');
    }

    $got[$step] = $value;

    foreach (carlCarFields() as $k => $question) {
        if (!array_key_exists($k, $got)) {
            carlPendingSet($db, $uid, 'add_car', $got, $k);
            return $ask($question);
        }
    }

    carlPendingSet($db, $uid, 'add_car', $got, 'confirm');
    $label = trim($got['year'] . ' ' . $got['make'] . ' ' . $got['model']);
    $h = '<div class="carl-confirm">'
       . '<div class="r"><span>Vehicle</span><b>' . e($label) . '</b></div>'
       . '<div class="r"><span>Chassis</span><b>' . e($got['chassis']) . '</b></div>'
       . '<div class="r"><span>Registration</span><b>'
       . e($got['reg'] !== '' ? $got['reg'] : 'not plated yet') . '</b></div>'
       . '<div class="r"><span>Goes in as</span><b>Inventory, arrived</b></div></div>'
       . '<div class="carl-chips">'
       . '<button type="button" class="carl-chip carl-yes" data-ask="yes">Add it</button>'
       . '<button type="button" class="carl-chip" data-ask="no">Cancel</button></div>';

    return $ask('Let me read that back. ' . $label . ', chassis ' . $got['chassis']
              . ($got['reg'] !== '' ? ', registration ' . $got['reg'] : ', not yet plated')
              . '. Shall I add it to inventory?', $h);
}

/**
 * Writes the vehicle.
 *
 * Goes in as inventory/arrived — ours to sell, on the yard — which is what
 * "add a car to inventory" means. Anything else about it is edited on the
 * vehicle page afterwards, and the reply links straight there.
 */
function carlCreateCar(PDO $db, array $user, array $got): array
{
    $uid = (int)$user['id'];
    carlPendingClear($db, $uid);

    if (!canWrite('cars')) {
        return ['skill' => 'add_car', 'done' => true,
                'say' => 'Adding a vehicle needs stock rights.', 'html' => ''];
    }

    $label = trim($got['year'] . ' ' . $got['make'] . ' ' . $got['model']);
    try {
        $ok = carlGuardedExec($db,
            "INSERT INTO cars (chassis_number, registration_number, make, model, year,
                               car_type, status, show_on_website, notes, created_at)
             VALUES (?,?,?,?,?, 'inventory', 'arrived', 0, ?, NOW())",
            [
                $got['chassis'], $got['reg'] !== '' ? $got['reg'] : null,
                $got['make'], $got['model'], (int)$got['year'],
                'Added through ' . CARL_NAME . ' by ' . $user['name'] . ' on ' . date('j M Y') . '.',
            ]
        );
        if (!$ok) throw new \RuntimeException('the write was refused');
        $carId = (int)$db->lastInsertId();
        logActivity('create', 'cars', $carId,
            'Vehicle ' . $label . ' (' . $got['chassis'] . ') added via ' . CARL_NAME
            . ' by ' . $user['name'] . '.');
    } catch (\Throwable $e) {
        error_log('carlCreateCar: ' . $e->getMessage());
        return ['skill' => 'add_car', 'done' => true,
                'say'   => 'I could not add it — something went wrong at my end. Nothing was '
                         . 'saved, so please add it from the vehicles page.',
                'html'  => carlLink(BASE_URL . '/modules/cars/add.php', 'Add a vehicle', 'fa-plus')];
    }

    $h = '<div class="carl-confirm">'
       . '<div class="r"><span>Vehicle</span><b>' . e($label) . '</b></div>'
       . '<div class="r"><span>Chassis</span><b>' . e($got['chassis']) . '</b></div>'
       . '<div class="r"><span>Status</span><b>In inventory</b></div></div>'
       . carlLink(BASE_URL . '/modules/cars/view.php?id=' . $carId, 'Open ' . $label, 'fa-car')
       . carlLink(BASE_URL . '/modules/cars/edit.php?id=' . $carId,
                  'Add price, photos and description', 'fa-pen')
       . carlChips(['How many cars do we have', 'What is on the yard']);

    return ['skill' => 'add_car', 'done' => true,
            'say'   => 'Added. The ' . $label . ' is in inventory. It has no price or photographs '
                     . 'yet — open it when you are ready to put those on.',
            'html'  => $h];
}

// ── add_deposit — record a further payment against a reservation ─────────────
//
// A customer rarely pays the whole deposit at once. Until now the only way to
// record a second payment was to open the lead and overwrite deposit_amount by
// hand, which loses the fact that it arrived in instalments and makes the
// receipt wrong. This adds to what is already held and keeps a dated note of
// each payment, so the receipt shows the true running total.

function carlSkillAddDeposit(PDO $db, array $user, string $u): array
{
    // If we were just looking at somebody, assume it is them and let a bare
    // "yes" carry it. Naming them again is the step people find tiresome.
    $who = carlContextGet($db, (int)$user['id'], 'lead');
    carlPendingSet($db, (int)$user['id'], 'add_deposit', [], 'lead_name');
    return ['skill' => 'add_deposit', 'done' => false,
            'say'   => $who
                ? 'Certainly. Is this for ' . $who['label'] . '? Say yes, or give me another name.'
                : 'Certainly. Which customer has paid? Give me their name or phone number.',
            'html'  => '<p class="carl-note">Say "cancel" at any point to stop.</p>'];
}

function carlContinueAddDeposit(PDO $db, array $user, array $pending, string $r): array
{
    $uid  = (int)$user['id'];
    $got  = $pending['collected'];
    $step = (string)$pending['awaiting'];
    $ask  = function (string $say, string $html = '') {
        return ['skill' => 'add_deposit', 'done' => false, 'say' => $say, 'html' => $html];
    };

    if ($step === 'lead_name') {
        // "yes", "him", "the same" — take the subject we offered rather than
        // searching for a customer literally called "yes".
        $lead = null;
        if (carlMeansTheSame($r)) {
            $ctx = carlContextGet($db, $uid, 'lead');
            if ($ctx) {
                $q = $db->prepare("SELECT id, name, phone, stage, assigned_to, follow_up_date
                                      FROM crm_leads WHERE id = ?");
                $q->execute([$ctx['id']]);
                $lead = $q->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }
        if (!$lead) $lead = carlFindLead($db, $r);
        if (!$lead) {
            return $ask('I could not find that customer. Try the surname on its own, '
                      . 'or the phone number.');
        }
        $st = $db->prepare("SELECT l.*, c.make, c.model, c.year, c.registration_number
                              FROM crm_leads l
                         LEFT JOIN cars c ON c.id = l.pinned_car_id
                             WHERE l.id = ?");
        $st->execute([(int)$lead['id']]);
        $full = $st->fetch(PDO::FETCH_ASSOC) ?: $lead;

        $got['lead_id']   = (int)$full['id'];
        $got['lead_name'] = $full['name'];
        $got['held']      = (float)($full['deposit_amount'] ?? 0);
        carlPendingSet($db, $uid, 'add_deposit', $got, 'amount');

        $car = trim(($full['year'] ?? '') . ' ' . ($full['make'] ?? '') . ' ' . ($full['model'] ?? ''));
        $h = '<div class="carl-confirm">'
           . '<div class="r"><span>Customer</span><b>' . e($full['name']) . '</b></div>'
           . ($car !== '' ? '<div class="r"><span>Vehicle</span><b>' . e($car) . '</b></div>' : '')
           . '<div class="r"><span>Already held</span><b>' . e(carlMoney($got['held'])) . '</b></div></div>';

        return $ask($got['held'] > 0
            ? 'We are holding ' . carlMoney($got['held']) . ' for ' . $full['name']
              . '. How much have they paid this time?'
            : 'Nothing is held for ' . $full['name'] . ' yet. How much have they paid?', $h);
    }

    if ($step === 'amount') {
        $amt = carlParseMoney($r);
        if ($amt === null || $amt <= 0) {
            return $ask('I did not catch an amount there. How much have they paid?');
        }
        $got['amount'] = $amt;
        carlPendingSet($db, $uid, 'add_deposit', $got, 'confirm');

        $newTotal = $got['held'] + $amt;
        $h = '<div class="carl-confirm">'
           . '<div class="r"><span>Customer</span><b>' . e($got['lead_name']) . '</b></div>'
           . '<div class="r"><span>Already held</span><b>' . e(carlMoney($got['held'])) . '</b></div>'
           . '<div class="r"><span>Paid now</span><b>' . e(carlMoney($amt)) . '</b></div>'
           . '<div class="r"><span>New total</span><b>' . e(carlMoney($newTotal)) . '</b></div></div>'
           . '<div class="carl-chips">'
           . '<button type="button" class="carl-chip carl-yes" data-ask="yes">Record it</button>'
           . '<button type="button" class="carl-chip" data-ask="no">Cancel</button></div>';

        return $ask('That takes ' . $got['lead_name'] . ' from ' . carlMoney($got['held'])
                  . ' to ' . carlMoney($newTotal) . '. Shall I record it?', $h);
    }

    if ($step === 'confirm') {
        if (preg_match('/^(yes|yeah|yep|correct|confirm|save|record it|go ahead|do it|ok|okay)\b/i', $r)) {
            return carlRecordDeposit($db, $user, $got);
        }
        if (preg_match('/^(no|nope|wrong|cancel)\b/i', $r)) {
            carlPendingClear($db, $uid);
            return ['skill' => 'add_deposit', 'done' => true,
                    'say' => 'Dropped — nothing was recorded.', 'html' => ''];
        }
        return $ask('Shall I record it? Please say yes or no.');
    }

    carlPendingClear($db, $uid);
    return carlSkillUnknown($user);
}

/**
 * Adds to the deposit held and hands back the receipt.
 *
 * Adds rather than replaces, and appends a dated line to deposit_notes so the
 * history of how the money arrived survives — a receipt that shows only the
 * latest payment is no use to anybody.
 */
function carlRecordDeposit(PDO $db, array $user, array $got): array
{
    $uid = (int)$user['id'];
    carlPendingClear($db, $uid);

    $leadId = (int)$got['lead_id'];
    $amount = (float)$got['amount'];
    $total  = (float)$got['held'] + $amount;
    $url    = BASE_URL . '/modules/crm/view_lead.php?id=' . $leadId;
    $line   = date('j M Y') . ': ' . carlMoney($amount) . ' received, recorded by '
            . $user['name'] . ' through ' . CARL_NAME . '.';

    try {
        $db->prepare(
            "UPDATE crm_leads
                SET deposit_amount = COALESCE(deposit_amount, 0) + ?,
                    deposit_date   = CURDATE(),
                    deposit_notes  = TRIM(CONCAT(COALESCE(deposit_notes, ''), '\n', ?)),
                    updated_at     = NOW()
              WHERE id = ?"
        )->execute([$amount, $line, $leadId]);

        logActivity('update', 'crm_leads', $leadId,
            'Additional deposit of ' . number_format($amount, 2) . ' recorded via ' . CARL_NAME
            . ' by ' . $user['name'] . '. New total: ' . number_format($total, 2));
    } catch (\Throwable $e) {
        error_log('carlRecordDeposit: ' . $e->getMessage());
        return ['skill' => 'add_deposit', 'done' => true,
                'say'   => 'I could not record that — something went wrong at my end. Nothing was '
                         . 'saved, so please add it from the lead page.',
                'html'  => carlLink($url, 'Open ' . $got['lead_name'], 'fa-user')];
    }

    $h = '<div class="carl-confirm">'
       . '<div class="r"><span>Customer</span><b>' . e($got['lead_name']) . '</b></div>'
       . '<div class="r"><span>Paid now</span><b>' . e(carlMoney($amount)) . '</b></div>'
       . '<div class="r"><span>Total held</span><b>' . e(carlMoney($total)) . '</b></div></div>'
       . carlLink(BASE_URL . '/modules/crm/deposit_receipt.php?lead_id=' . $leadId,
                  'Deposit receipt — showing ' . carlMoney($total), 'fa-receipt')
       . carlLink($url, 'Open ' . $got['lead_name'], 'fa-user');

    return ['skill' => 'add_deposit', 'done' => true,
            'say'   => 'Recorded. ' . $got['lead_name'] . ' has now paid ' . carlMoney($total)
                     . ' in total. The deposit receipt is ready and shows the full amount.',
            'html'  => $h,
            'go'    => BASE_URL . '/modules/crm/deposit_receipt.php?lead_id=' . $leadId];
}

// ── document — produce the real paperwork for a named record ─────────────────

/**
 * Every document the CRM can print, with the condition that makes it meaningful.
 *
 * Carl offers all of them but says which are ready, because a sales agreement
 * before a deposit, or a delivery note before handover, is a document nobody can
 * use — and finding that out after printing wastes the customer's time at the desk.
 */
function carlDocuments(): array
{
    return [
        'proforma' => [
            'label' => 'Proforma invoice', 'icon' => 'fa-file-invoice',
            'file'  => 'proforma.php',
            'words' => ['proforma', 'pro forma', 'quote', 'quotation'],
            'ready' => fn (array $l) => true,
            'needs' => '',
        ],
        'deposit_receipt' => [
            'label' => 'Deposit receipt', 'icon' => 'fa-receipt',
            'file'  => 'deposit_receipt.php',
            'words' => ['deposit receipt', 'deposit slip', 'receipt for the deposit'],
            'ready' => fn (array $l) => (float)($l['deposit_amount'] ?? 0) > 0,
            'needs' => 'once a deposit is recorded',
        ],
        'sales_agreement' => [
            'label' => 'Sales agreement', 'icon' => 'fa-file-signature',
            'file'  => 'sales_agreement.php',
            'words' => ['sales agreement', 'sale agreement', 'agreement', 'contract'],
            'ready' => fn (array $l) => (float)($l['deposit_amount'] ?? 0) > 0
                                     || in_array($l['stage'] ?? '', ['reserved', 'won', 'delivered'], true),
            'needs' => 'once the vehicle is reserved',
        ],
        'credit_payment_agreement' => [
            'label' => 'Credit payment agreement', 'icon' => 'fa-file-contract',
            'file'  => 'credit_payment_agreement.php',
            'words' => ['credit agreement', 'credit payment', 'instalment', 'installment', 'payment plan'],
            'ready' => fn (array $l) => in_array($l['stage'] ?? '', ['reserved', 'won', 'delivered'], true),
            'needs' => 'once the vehicle is reserved',
        ],
        'sales_receipt' => [
            'label' => 'Sales receipt', 'icon' => 'fa-receipt',
            'file'  => 'sales_receipt.php',
            'words' => ['sales receipt', 'final receipt', 'payment receipt'],
            'ready' => fn (array $l) => in_array($l['stage'] ?? '', ['won', 'delivered'], true),
            'needs' => 'once the sale is closed',
        ],
        'delivery_note' => [
            'label' => 'Delivery note', 'icon' => 'fa-truck-ramp-box',
            'file'  => 'delivery_note.php',
            'words' => ['delivery note', 'handover note', 'gate pass'],
            'ready' => fn (array $l) => !empty($l['delivered_at']) || ($l['stage'] ?? '') === 'delivered',
            'needs' => 'once the delivery protocol is complete',
        ],
    ];
}

/** Which document did they ask for? Null when they only said "a document". */
function carlWhichDocument(string $u): ?string
{
    $t = strtolower($u);
    $best = null; $bestLen = 0;
    foreach (carlDocuments() as $key => $d) {
        foreach ($d['words'] as $w) {
            // Longest matching phrase wins, so "deposit receipt" is not eaten by "receipt".
            if (str_contains($t, $w) && strlen($w) > $bestLen) { $best = $key; $bestLen = strlen($w); }
        }
    }
    return $best;
}

function carlSkillDocument(PDO $db, array $user, string $u): array
{
    $want = carlWhichDocument($u);

    // "proforma for John Mwangi" — take the name off the end and go straight there.
    $name = '';
    if (preg_match('/\b(?:for|to|of)\s+(.+)$/i', $u, $m)) {
        $name = trim(rtrim($m[1], '.?!'));
    }
    if ($name !== '') {
        $lead = carlFindLead($db, $name);
        if ($lead) return carlDocumentsFor($db, $lead, $want);
    }

    carlPendingSet($db, (int)$user['id'], 'document', ['want' => $want], 'lead_name');
    $what = $want ? strtolower(carlDocuments()[$want]['label']) : 'the document';
    return ['skill' => 'document', 'done' => false,
            'say'   => 'Certainly. Who is ' . $what . ' for? '
                     . 'Give me the customer name or phone number.',
            'html'  => '<p class="carl-note">Say "cancel" at any point to stop.</p>'];
}

function carlContinueDocument(PDO $db, array $user, array $pending, string $r): array
{
    $lead = carlFindLead($db, $r);
    if (!$lead) {
        return ['skill' => 'document', 'done' => false,
                'say'   => 'I could not find that customer. Try the surname on its own, '
                         . 'or the phone number.',
                'html'  => ''];
    }
    carlPendingClear($db, (int)$user['id']);
    return carlDocumentsFor($db, $lead, $pending['collected']['want'] ?? null);
}

/** Build the document list for one lead, marking what is and is not ready yet. */
function carlDocumentsFor(PDO $db, array $lead, ?string $want): array
{
    // carlFindLead returns a slim row; the readiness rules need the money columns.
    try {
        $st = $db->prepare("SELECT id, name, stage, deposit_amount, delivered_at
                              FROM crm_leads WHERE id = ?");
        $st->execute([(int)$lead['id']]);
        $full = $st->fetch(PDO::FETCH_ASSOC) ?: $lead;
    } catch (\Throwable $_) { $full = $lead; }

    $base = BASE_URL . '/modules/crm/';
    $docs = carlDocuments();
    $h    = carlLeadMini($lead);
    $readyCount = 0;

    // The one they asked for goes first; the rest follow in their natural order.
    $order = ($want && isset($docs[$want])) ? [$want => $docs[$want]] + $docs : $docs;

    $h .= '<div class="carl-recs">';
    foreach ($order as $key => $d) {
        $ready = ($d['ready'])($full);
        if ($ready) $readyCount++;
        $href = $base . $d['file'] . '?lead_id=' . (int)$full['id'];
        $h .= $ready
            ? '<a class="carl-rec" href="' . $href . '" target="_blank" rel="noopener">'
              . '<b><i class="fa ' . $d['icon'] . '"></i> ' . e($d['label']) . '</b>'
              . '<em>opens ready to print</em></a>'
            : '<span class="carl-rec is-off"><b>' . e($d['label']) . '</b>'
              . '<em>' . e($d['needs']) . '</em></span>';
    }
    $h .= '</div>';

    if ($want && isset($docs[$want])) {
        $d  = $docs[$want];
        $ok = ($d['ready'])($full);
        $say = $ok
            ? 'Here is the ' . strtolower($d['label']) . ' for ' . $full['name']
              . '. It opens in a new tab ready to print, with their details and the figures '
              . 'already filled in.'
            : 'I cannot produce the ' . strtolower($d['label']) . ' for ' . $full['name']
              . ' yet — that one becomes available ' . $d['needs']
              . '. I have listed what is ready in the meantime.';
        return $ok
            ? ['skill' => 'document', 'done' => true, 'say' => $say, 'html' => $h,
               'go' => $base . $d['file'] . '?lead_id=' . (int)$full['id']]
            : ['skill' => 'document', 'done' => true, 'say' => $say, 'html' => $h];
    }

    $say = $readyCount === 0
        ? 'There is nothing I can print for ' . $full['name'] . ' yet. The paperwork opens up '
          . 'once a deposit is recorded.'
        : carlPlural($readyCount, 'document is', 'documents are')
          . ' ready for ' . $full['name'] . '. Which one would you like?';

    return ['skill' => 'document', 'done' => true, 'say' => $say, 'html' => $h];
}
