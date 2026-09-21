<?php
/**
 * Karl answering a customer on WhatsApp when nobody is there to.
 *
 * This is the only part of the system that speaks to a customer without a
 * person having read what they said, so it is the part most able to embarrass
 * the yard. A car dealership lives on what it promised: a price, a date, a
 * condition. An assistant that invents any of those creates an obligation
 * somebody has to honour or explain away.
 *
 * So the rules here are about restraint rather than capability.
 *
 *   It never quotes a price, never confirms availability, never commits to a
 *   date, and never agrees to anything. It acknowledges, it answers the handful
 *   of things that are safely factual — where the yard is, when it opens — and
 *   it says a colleague will pick this up.
 *
 *   It speaks at most twice to a thread before a human has said something. A
 *   customer talking to a machine that keeps replying, on a number they believe
 *   is a business, works out what is happening and thinks less of the business.
 *
 *   It is off until somebody turns it on, and it says who it is.
 *
 * Timing is deliberate. Outside working hours it answers at once, because
 * nobody is coming and a quick "we have you, we open at eight" is worth more
 * than silence until morning. During working hours it waits, so that a real
 * person always gets first refusal on a live customer.
 */

if (!function_exists('waAutoEnabled')) {

require_once __DIR__ . '/_wa.php';

// Karl's own brain lives in the other module. Loaded lazily and guarded at
// every call site, because an install that has WhatsApp but no AI key must
// still send the fixed acknowledgement rather than fataling on a missing
// function — the whole point is that the customer hears something.
$__karl = __DIR__ . '/../carl/_ai.php';
if (is_readable($__karl)) { require_once __DIR__ . '/../carl/_skills.php'; require_once $__karl; }

/** Everything that governs the behaviour, in one place. */
function waAutoConfig(): array
{
    return [
        'enabled'   => getSetting('wa_auto_enabled', '0') === '1',
        // Minutes a customer waits during working hours before Karl steps in.
        'grace'     => max(1, min(180, (int)getSetting('wa_auto_grace', '10'))),
        // How many times Karl may speak into a thread with no human in between.
        'max_run'   => max(1, min(5, (int)getSetting('wa_auto_max', '2'))),
        // The least time between two automatic replies to the same person.
        'cooldown'  => max(5, min(720, (int)getSetting('wa_auto_cooldown', '30'))),
        'open'      => trim(getSetting('wa_auto_open',  '08:00')),
        'close'     => trim(getSetting('wa_auto_close', '18:00')),
        // 1 = Monday … 7 = Sunday, as MySQL's DAYOFWEEK-1 gives it.
        'days'      => trim(getSetting('wa_auto_days', '1,2,3,4,5,6')),
        'signature' => trim(getSetting('wa_auto_signature', '')),
    ];
}

function waAutoEnabled(): bool { return waAutoConfig()['enabled']; }

/**
 * Is the yard open right now?
 *
 * Asked of the database, not of PHP. PHP runs UTC on this host and MySQL runs
 * EAT, so "is it before six in the evening" answered in PHP is three hours out
 * — which would have Karl announcing the yard was closed through most of the
 * afternoon.
 */
function waWithinHours(PDO $db, ?array $cfg = null): bool
{
    $cfg  = $cfg ?? waAutoConfig();
    $days = array_filter(array_map('intval', explode(',', $cfg['days'])));
    if (!$days) return true;

    try {
        $row = $db->query("SELECT DAYOFWEEK(NOW()) AS dw, TIME(NOW()) AS t")->fetch(PDO::FETCH_ASSOC);
        if (!$row) return true;
        // DAYOFWEEK is 1=Sunday..7=Saturday; the setting is 1=Monday..7=Sunday.
        $iso = ((int)$row['dw'] + 5) % 7 + 1;
        if (!in_array($iso, $days, true)) return false;

        $now = substr((string)$row['t'], 0, 5);
        return $now >= $cfg['open'] && $now < $cfg['close'];
    } catch (\Throwable $e) {
        error_log('waWithinHours: ' . $e->getMessage());
        return true;   // when in doubt, assume somebody is about and stay quiet
    }
}

/**
 * Whether Karl may answer this thread, and if not, why not.
 *
 * Returns the reason rather than a bare false so the decision can be logged and
 * argued with. "It did not reply" is impossible to debug; "a person answered
 * four minutes ago" is not.
 *
 * @return array{allow:bool, why:string, wait:int}  wait = minutes still to go
 */
function waAutoDecide(PDO $db, int $convId): array
{
    $cfg = waAutoConfig();
    if (!$cfg['enabled'])   return ['allow' => false, 'why' => 'automatic replies are switched off', 'wait' => 0];
    if (!waConfigured())    return ['allow' => false, 'why' => 'WhatsApp is not connected', 'wait' => 0];

    $conv = waConversationById($db, $convId);
    if (!$conv)                          return ['allow' => false, 'why' => 'no such conversation', 'wait' => 0];
    if (($conv['status'] ?? '') === 'closed')
        return ['allow' => false, 'why' => 'the conversation is closed', 'wait' => 0];

    try {
        // Everything since the customer's last message, so the question is
        // always "has anyone dealt with THIS", not "has anyone ever replied".
        $st = $db->prepare("
            SELECT direction, sent_by, status, sent_at,
                   TIMESTAMPDIFF(MINUTE, sent_at, NOW()) AS mins_ago
              FROM wa_messages
             WHERE conversation_id = ?
          ORDER BY id DESC
             LIMIT 12");
        $st->execute([$convId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('waAutoDecide: ' . $e->getMessage());
        return ['allow' => false, 'why' => 'the thread could not be read', 'wait' => 0];
    }

    if (!$rows) return ['allow' => false, 'why' => 'the thread is empty', 'wait' => 0];

    // The newest message must be from the customer. If the last thing said was
    // ours, there is nothing waiting to be answered.
    if (($rows[0]['direction'] ?? '') !== 'in') {
        return ['allow' => false, 'why' => 'the last word was ours', 'wait' => 0];
    }
    $waiting = (int)$rows[0]['mins_ago'];

    // Walk back to the customer's previous turn, counting what we said in between.
    $autoRun = 0;
    foreach (array_slice($rows, 1) as $r) {
        if (($r['direction'] ?? '') === 'in') break;          // reached their last turn
        if ($r['sent_by'] === null && ($r['status'] ?? '') !== 'failed') {
            $autoRun++;                                        // Karl, not a person
        } else {
            // A person has spoken since. They own this conversation now.
            return ['allow' => false, 'why' => 'a colleague has already replied', 'wait' => 0];
        }
        if ((int)$r['mins_ago'] < $cfg['cooldown']) {
            return ['allow' => false, 'why' => 'Karl replied ' . (int)$r['mins_ago'] . ' minutes ago',
                    'wait' => $cfg['cooldown'] - (int)$r['mins_ago']];
        }
    }

    if ($autoRun >= $cfg['max_run']) {
        return ['allow' => false,
                'why'   => 'Karl has already answered ' . $autoRun . ' times without a colleague joining in',
                'wait'  => 0];
    }

    // Out of hours: nobody is coming, so answer now. In hours: a person gets
    // first refusal for the grace period.
    if (waWithinHours($db, $cfg)) {
        if ($waiting < $cfg['grace']) {
            return ['allow' => false,
                    'why'   => 'waiting ' . ($cfg['grace'] - $waiting) . ' more minutes for a colleague',
                    'wait'  => $cfg['grace'] - $waiting];
        }
        return ['allow' => true, 'why' => 'nobody answered within ' . $cfg['grace'] . ' minutes', 'wait' => 0];
    }
    return ['allow' => true, 'why' => 'outside working hours', 'wait' => 0];
}

/**
 * What Karl actually says.
 *
 * The model is given the conversation and a short, true brief, and is told in
 * plain terms what it may not do. Where the model is unavailable — no key, no
 * credit, provider down — a fixed sentence goes out instead. A customer who
 * wrote at nine at night should get an acknowledgement either way; silence is
 * the one outcome worth avoiding, and it is also the easiest to fall back into.
 */
function waAutoCompose(PDO $db, array $conv, array $recent): string
{
    $cfg     = waAutoConfig();
    $company = getSetting('company_name', 'Mascardi Luxury Cars');
    $open    = $cfg['open'];
    $close   = $cfg['close'];
    $inHours = waWithinHours($db, $cfg);

    $name = trim((string)($conv['client_name'] ?? $conv['contact_name'] ?? ''));
    if ($name !== '') $name = explode(' ', $name)[0];

    $fallback = ($name !== '' ? 'Hello ' . $name . '. ' : 'Hello. ')
        . 'Thank you for messaging ' . $company . '. '
        . ($inHours
            ? 'A member of our team will get back to you shortly.'
            : 'Our office is closed at the moment — we open at ' . $open
              . ' and someone will come back to you then.');

    if (!function_exists('carlAiRound') || !carlAiAvailable()) {
        return waAutoSign($fallback, $cfg);
    }

    $lines = [];
    foreach (array_slice($recent, -8) as $m) {
        $who  = ($m['direction'] === 'in') ? 'Customer' : 'Mascardi';
        $body = trim((string)($m['body'] ?? ''));
        if ($body === '') $body = '[' . $m['type'] . ']';
        $lines[] = $who . ': ' . mb_substr($body, 0, 400);
    }
    $transcript = implode("\n", $lines);

    $system = "You are Karl, answering a WhatsApp message on behalf of {$company}, a car "
        . "dealership and workshop in Nairobi, Kenya.\n\n"
        . "A customer has written and no member of staff is available to reply right now. "
        . "Your job is to acknowledge them warmly, answer only what you can answer safely, "
        . "and tell them a colleague will follow up.\n\n"
        . "The yard is open {$open} to {$close}. Right now it is "
        . ($inHours ? "OPEN." : "CLOSED.") . "\n\n"
        . "YOU MUST NEVER:\n"
        . "- Quote, estimate, confirm or negotiate any price, discount, deposit or figure.\n"
        . "- Say whether a particular vehicle is available, in stock, sold or reserved.\n"
        . "- Promise a date, a time, a delivery, a booking or a callback time.\n"
        . "- Agree to anything, accept an offer, or say a deal is done.\n"
        . "- Invent anything about a vehicle: mileage, year, condition, history, service record.\n"
        . "- Claim to be a human being. If asked, say plainly that you are an assistant.\n\n"
        . "If they ask about any of the above, say honestly that a colleague will confirm it, "
        . "and do not guess.\n\n"
        . "STYLE:\n"
        . "- One short paragraph. Two or three sentences at most. This is WhatsApp, not email.\n"
        . "- Warm and plain. No markdown, no bullet points, no emoji.\n"
        . "- Do not open with 'Of course', 'Certainly', 'Sure' or 'Great'.\n"
        . "- Reply in the language the customer used.";

    $user = "The conversation so far:\n\n" . $transcript . "\n\nWrite the reply and nothing else.";

    try {
        $round = carlAiRound($system, [['role' => 'user', 'content' => $user]], [], 300);
        // null is the ordinary failure here — no credit, provider down, a
        // timeout — and reading ['text'] off it would turn a quiet fallback
        // into a warning in the log on every message.
        if (!is_array($round)) return waAutoSign($fallback, $cfg);
        $text = trim((string)($round['text'] ?? ''));
        // A model that returns nothing, or a wall, is not answering a customer.
        if ($text === '' || mb_strlen($text) > 900) return waAutoSign($fallback, $cfg);
        // Belt and braces: never let a currency figure out, whatever it was told.
        if (preg_match('/(KES|KSH|Ksh|\bshs?\b)\s*[\d,]{4,}|\b\d{3},\d{3}\b/i', $text)) {
            error_log('waAutoCompose: refused a reply containing a figure');
            return waAutoSign($fallback, $cfg);
        }
        return waAutoSign($text, $cfg);
    } catch (\Throwable $e) {
        error_log('waAutoCompose: ' . $e->getMessage());
        return waAutoSign($fallback, $cfg);
    }
}

/**
 * Sign it.
 *
 * A customer is entitled to know they are not talking to a person. Saying so
 * once, at the end, is both honest and cheaper than the conversation that
 * follows when they work it out for themselves.
 */
function waAutoSign(string $text, array $cfg): string
{
    $sig = $cfg['signature'] !== ''
        ? $cfg['signature']
        : '— Karl, automated assistant. A colleague will follow up personally.';
    return rtrim($text) . "\n\n" . $sig;
}

/**
 * Answer one conversation, if it is allowed.
 *
 * @return array{sent:bool, why:string}
 */
function waAutoRespond(PDO $db, int $convId): array
{
    $d = waAutoDecide($db, $convId);
    if (!$d['allow']) return ['sent' => false, 'why' => $d['why']];

    $conv = waConversationById($db, $convId);
    if (!$conv) return ['sent' => false, 'why' => 'no such conversation'];

    // The client's name, for the greeting.
    try {
        $st = $db->prepare("SELECT cl.name AS client_name FROM wa_conversations c
                         LEFT JOIN clients cl ON cl.id = c.client_id WHERE c.id = ?");
        $st->execute([$convId]);
        $conv['client_name'] = (string)($st->fetchColumn() ?: '');
    } catch (\Throwable $_) {}

    $recent = waMessages($db, $convId, 0, 12);
    $text   = waAutoCompose($db, $conv, $recent);

    // sent_by stays null on purpose: that is what marks a message as Karl's
    // rather than a person's, and what waAutoDecide() counts when deciding
    // whether a human has joined in.
    $r = waSendText($db, $convId, $text, null);

    try {
        logActivity($r['ok'] ? 'create' : 'blocked', 'wa_messages', $r['message_id'],
            'Karl replied automatically to '
            . ($conv['contact_name'] ?: $conv['contact_phone'])
            . ' — ' . $d['why'] . ($r['ok'] ? '.' : ': ' . $r['error']));
    } catch (\Throwable $_) {}

    return ['sent' => $r['ok'], 'why' => $r['ok'] ? $d['why'] : $r['error']];
}

/**
 * Go through the threads that are waiting and answer the ones that are due.
 *
 * Called from the webhook and from cron. Capped, because a sweep that tries to
 * answer eighty threads in one request finishes none of them.
 *
 * @return array{considered:int, sent:int}
 */
function waAutoSweep(PDO $db, int $limit = 10): array
{
    if (!waAutoEnabled()) return ['considered' => 0, 'sent' => 0];

    try {
        $cfg = waAutoConfig();
        // Only threads whose newest message came from the customer and has been
        // sitting long enough to be worth looking at.
        $st = $db->prepare("
            SELECT c.id
              FROM wa_conversations c
              JOIN wa_messages m ON m.id = (SELECT MAX(m2.id) FROM wa_messages m2
                                             WHERE m2.conversation_id = c.id)
             WHERE c.status = 'open'
               AND m.direction = 'in'
               AND m.sent_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)
          ORDER BY m.sent_at ASC
             LIMIT " . max(1, min(50, $limit)));
        $st->execute();
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {
        error_log('waAutoSweep: ' . $e->getMessage());
        return ['considered' => 0, 'sent' => 0];
    }

    $sent = 0;
    foreach ($ids as $id) {
        $r = waAutoRespond($db, (int)$id);
        if ($r['sent']) $sent++;
    }
    return ['considered' => count($ids), 'sent' => $sent];
}

} // function_exists('waAutoEnabled')
