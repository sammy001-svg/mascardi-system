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
 *   It answers every message a customer sends while nobody else is answering
 *   them. An assistant that replies once and then ignores the next four
 *   questions is worse than one that never spoke: the customer has been told
 *   somebody is there, and then watched them walk away mid-sentence.
 *
 *   It stands down the moment a colleague replies, and stays down while that
 *   colleague is still about. The handover is by the clock, not forever — a
 *   reply from last Tuesday must not mute Karl on a message sent today.
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
require_once __DIR__ . '/_tools.php';

// Karl's own brain lives in the other module. Loaded lazily and guarded at
// every call site, because an install that has WhatsApp but no AI key must
// still send the fixed acknowledgement rather than fataling on a missing
// function — the whole point is that the customer hears something.
$__karl = __DIR__ . '/../carl/_ai.php';
if (is_readable($__karl)) { require_once __DIR__ . '/../carl/_skills.php'; require_once $__karl; }

/**
 * Everything that governs the behaviour, in one place.
 *
 * The first three settings replaced a pair that turned out to be the reason
 * Karl answered once and then went quiet, and they are deliberately under new
 * keys rather than new meanings for the old ones. Reusing `wa_auto_max` would
 * have read the 2 already saved on every live install as "two replies a day",
 * which is the same silence wearing a different hat.
 */
function waAutoConfig(): array
{
    return [
        'enabled'   => getSetting('wa_auto_enabled', '0') === '1',
        // Minutes a customer waits during working hours before Karl steps in.
        'grace'     => max(1, min(180, (int)getSetting('wa_auto_grace', '1'))),
        // Seconds between two automatic replies. This exists to collapse a
        // burst — three lines typed in ten seconds deserve one answer — and to
        // stop two sweeps racing into a double reply. It is NOT a limit on how
        // often a customer may be answered, which is what the old minute-based
        // cooldown had quietly become.
        'gap'       => max(5, min(600, (int)getSetting('wa_auto_gap', '45'))),
        // Minutes a colleague owns a thread after they speak in it. Inside the
        // window Karl says nothing at all. Outside it, a customer who writes
        // again and is ignored gets an answer, because the colleague who
        // replied yesterday is not the one sitting in silence today.
        'handover'  => max(5, min(1440, (int)getSetting('wa_auto_handover', '60'))),
        // A ceiling per conversation per day. Not a conversational limit —
        // it is high enough that no real customer will reach it — but a stop
        // against the one failure that cannot be argued with: an auto-responder
        // on the other end, and the two of them talking until the bill arrives.
        'daily'     => max(5, min(200, (int)getSetting('wa_auto_daily', '40'))),
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
 * The question this asks is deliberately narrow: is there a customer message
 * that nobody has answered? If there is, Karl answers it. The previous version
 * asked a broader one — has Karl said enough already — and got three things
 * wrong, each of which silenced him permanently on a thread:
 *
 *   The gap between replies was counted in minutes and applied to the customer.
 *   A customer who wrote back four minutes after Karl's answer was refused,
 *   although nobody had read a word of what they said. Five minutes is a normal
 *   pace for a WhatsApp conversation; it was the common case, not the edge one.
 *
 *   The cap on replies counted rows, and one reply is often several rows —
 *   Karl sends a car's photographs as separate messages. Three photographs
 *   and the cap of two was reached inside a single answer, after which that
 *   thread was mute until a colleague happened to type into it.
 *
 *   A colleague's reply held the thread for ever. The salesperson who answered
 *   on Monday and went on leave took the thread with them.
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

    // Everything the decision turns on, asked of the database in one go so that
    // every clock in it is MySQL's. PHP runs UTC on this host and MySQL runs
    // EAT; a comparison that crosses the two is three hours wrong.
    //
    // "Karl" is an outbound message with no author. That covers his own replies
    // and the documents the system sends on the yard's behalf, which is right:
    // neither means a person has read anything.
    try {
        $st = $db->prepare("
            SELECT
              (SELECT direction FROM wa_messages
                WHERE conversation_id = ? ORDER BY id DESC LIMIT 1)              AS last_dir,
              (SELECT TIMESTAMPDIFF(MINUTE, sent_at, NOW()) FROM wa_messages
                WHERE conversation_id = ? ORDER BY id DESC LIMIT 1)              AS waiting,
              (SELECT TIMESTAMPDIFF(MINUTE, MAX(sent_at), NOW()) FROM wa_messages
                WHERE conversation_id = ? AND direction = 'out'
                  AND sent_by IS NOT NULL AND sent_by > 0
                  AND status <> 'failed')                                        AS colleague_mins,
              (SELECT TIMESTAMPDIFF(SECOND, MAX(sent_at), NOW()) FROM wa_messages
                WHERE conversation_id = ? AND direction = 'out'
                  AND (sent_by IS NULL OR sent_by = 0)
                  AND status <> 'failed')                                        AS karl_secs,
              (SELECT COUNT(*) FROM wa_messages
                WHERE conversation_id = ? AND direction = 'out'
                  AND (sent_by IS NULL OR sent_by = 0)
                  AND type = 'text' AND status <> 'failed'
                  AND sent_at >= DATE_SUB(NOW(), INTERVAL 1 DAY))                AS karl_today
        ");
        $st->execute(array_fill(0, 5, $convId));
        $s = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        error_log('waAutoDecide: ' . $e->getMessage());
        return ['allow' => false, 'why' => 'the thread could not be read', 'wait' => 0];
    }

    if (($s['last_dir'] ?? null) === null) {
        return ['allow' => false, 'why' => 'the thread is empty', 'wait' => 0];
    }

    // The newest message must be from the customer. If the last thing said was
    // ours, there is nothing waiting to be answered — and since this is the
    // only thing that decides "has this message been dealt with", it is also
    // what stops Karl answering the same message twice.
    if ($s['last_dir'] !== 'in') {
        return ['allow' => false, 'why' => 'the last word was ours', 'wait' => 0];
    }
    $waiting = (int)$s['waiting'];

    // A colleague is in this conversation. They own it while they are still
    // about; the clock decides when that stops being true.
    if ($s['colleague_mins'] !== null && (int)$s['colleague_mins'] < $cfg['handover']) {
        $mins = (int)$s['colleague_mins'];
        return ['allow' => false,
                'why'   => 'a colleague replied ' . ($mins < 1 ? 'just now' : $mins . ' minutes ago'),
                'wait'  => $cfg['handover'] - $mins];
    }

    // A burst of messages gets one answer, not one each. Seconds, not minutes:
    // long enough to gather up a customer typing in three short lines, short
    // enough to be invisible to a customer having a conversation.
    if ($s['karl_secs'] !== null && (int)$s['karl_secs'] < $cfg['gap']) {
        return ['allow' => false,
                'why'   => 'Karl replied ' . (int)$s['karl_secs'] . ' seconds ago',
                'wait'  => 1];
    }

    // The runaway stop.
    if ((int)$s['karl_today'] >= $cfg['daily']) {
        return ['allow' => false,
                'why'   => 'Karl has sent ' . (int)$s['karl_today']
                         . ' replies to this thread today — stopping until tomorrow',
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
    $inHours = waWithinHours($db, $cfg);

    $name = trim((string)($conv['client_name'] ?? $conv['contact_name'] ?? ''));
    if ($name !== '') $name = explode(' ', $name)[0];

    $fallback = ($name !== '' ? 'Hello ' . $name . '. ' : 'Hello. ')
        . 'Thank you for messaging ' . $company . '. '
        . ($inHours
            ? 'A member of our team will get back to you shortly.'
            : 'Our office is closed at the moment — we open at ' . $cfg['open']
              . ' and someone will come back to you then.');

    if (!function_exists('carlAiRound') || !carlAiAvailable()) {
        return waAutoSign($fallback, $cfg);
    }

    $known = (int)($conv['client_id'] ?? 0) > 0;

    $system = "You are Karl, answering WhatsApp for {$company}, a car dealership and workshop "
        . "in Nairobi, Kenya. You are talking to a customer, and no colleague is free right now.\n\n"
        . "You have tools. USE THEM — never answer about stock, prices or paperwork from memory.\n"
        . "- A customer asking about any car: search_stock first, then answer from what it returns.\n"
        . "- Interested in one car: send_car_photos with the car_id you were given.\n"
        . "- Asking for their invoice, quotation or paperwork: my_documents, then send_document.\n"
        . "- Asking when you open or where you are: opening_hours.\n\n"
        . ($known
            ? "This customer is recognised from their number, so their own documents may be sent.\n\n"
            : "This number is NOT linked to a customer account, so no documents can be sent. "
              . "If they ask for paperwork, say a colleague will confirm their details and send it. "
              . "Never ask them to prove who they are over chat.\n\n")
        . "The yard is open {$cfg['open']} to {$cfg['close']} and is "
        . ($inHours ? "OPEN now.\n\n" : "CLOSED now.\n\n")
        . "YOU MAY state a listed price exactly as search_stock gives it. That price is on the "
        . "windscreen and on the website; repeating it is service, not a commitment.\n\n"
        . "YOU MUST NEVER:\n"
        . "- Offer, imply or negotiate a discount, or say what the yard 'could do'.\n"
        . "- Promise to hold, reserve or keep a vehicle for anybody.\n"
        . "- Commit to a delivery date, a viewing time, a callback time or a booking.\n"
        . "- State a price for anything search_stock did not give you.\n"
        . "- Invent mileage, year, condition, service history or accident history.\n"
        . "- Send, describe or confirm a document belonging to anyone but this customer.\n"
        . "- Claim to be a person. Asked directly, say plainly that you are an assistant.\n\n"
        . "Anything you cannot do, a colleague will. Say that, briefly, and mean it.\n\n"
        . "STYLE:\n"
        . "- Short. Two or three sentences, or a tight list of at most three cars.\n"
        . "- Warm, plain, human. No markdown, no bullet symbols, no emoji.\n"
        . "- Listing cars: one per line, as 'Year Make Model — mileage — KES price'.\n"
        . "- Never open with 'Of course', 'Certainly', 'Sure' or 'Great'.\n"
        . "- Reply in the language the customer wrote in.\n"
        . "- End by inviting the next step: which one interests them, or that a colleague "
        . "will call.";

    $lines = [];
    foreach (array_slice($recent, -10) as $m) {
        $who  = ($m['direction'] === 'in') ? 'Customer' : 'Mascardi';
        $body = trim((string)($m['body'] ?? ''));
        if ($body === '') $body = '[' . $m['type'] . ']';
        $lines[] = $who . ': ' . mb_substr($body, 0, 400);
    }

    $msgs = [['role' => 'user',
              'content' => "The conversation so far:\n\n" . implode("\n", $lines)
                         . "\n\nReply to the last customer message."]];

    $ctx = [
        'conversation_id' => (int)$conv['id'],
        'client_id'       => (int)($conv['client_id'] ?? 0),
    ];

    // A short loop. Two rounds of tools is enough to search stock and then send
    // photographs, or list documents and then send one; more than that and it is
    // casting about rather than answering, and every round costs the customer
    // another few seconds of silence.
    $usedStock = false;
    try {
        for ($round = 0; $round < 3; $round++) {
            $r = carlAiRound($system, $msgs, waToolSchema(), 500);
            if (!is_array($r)) return waAutoSign($fallback, $cfg);

            $calls = $r['calls'] ?? [];
            if (!$calls) {
                $text = trim((string)($r['text'] ?? ''));
                if ($text === '' || mb_strlen($text) > 1200) return waAutoSign($fallback, $cfg);
                // A figure is allowed only when it came from a stock lookup this
                // turn. Without that test the model can be talked into a price by
                // a customer who simply asserts one.
                if (!$usedStock && preg_match('/(KES|KSH|Ksh)\s*[\d,]{4,}|\b\d{3},\d{3}\b/i', $text)) {
                    error_log('waAutoCompose: refused an ungrounded figure');
                    return waAutoSign($fallback, $cfg);
                }
                return waAutoSign($text, $cfg);
            }

            carlAiAppendModelTurn($msgs, $r);

            $results = [];
            foreach ($calls as $c) {
                $tool = (string)($c['name'] ?? '');
                if ($tool === 'search_stock') $usedStock = true;
                $out = waRunTool($db, $tool, (array)($c['input'] ?? []), $ctx);
                // The key is 'text': carlAiAppendToolResults() reads that, and a
                // mismatch here hands the model an empty tool result while
                // looking perfectly correct from this side.
                $results[] = ['id' => $c['id'] ?? '', 'name' => $tool, 'text' => $out['result']];
            }
            carlAiAppendToolResults($msgs, $results);
        }
    } catch (\Throwable $e) {
        error_log('waAutoCompose: ' . $e->getMessage());
        return waAutoSign($fallback, $cfg);
    }

    return waAutoSign($fallback, $cfg);
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
/**
 * Has Karl already said something into this thread recently?
 *
 * Only asked where he has nothing but the fixed acknowledgement to offer. With
 * no AI configured every reply he can produce is the same sentence, and now
 * that he answers every message, a customer writing four lines would get that
 * sentence four times — a machine with a stuck key, and obviously so. Answering
 * once and then waiting for a colleague is the honest version of having nothing
 * further to say.
 */
function waAutoAlreadyAcknowledged(PDO $db, int $convId, int $withinHours = 6): bool
{
    try {
        $st = $db->prepare("SELECT 1 FROM wa_messages
                             WHERE conversation_id = ? AND direction = 'out'
                               AND (sent_by IS NULL OR sent_by = 0)
                               AND type = 'text' AND status <> 'failed'
                               AND sent_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                             LIMIT 1");
        $st->execute([$convId, max(1, $withinHours)]);
        return (bool)$st->fetchColumn();
    } catch (\Throwable $e) {
        error_log('waAutoAlreadyAcknowledged: ' . $e->getMessage());
        return false;   // rather a repeat than a silence
    }
}

/** The last thing Karl himself said here, trimmed, or '' if he has not. */
function waAutoLastSaid(PDO $db, int $convId): string
{
    try {
        $st = $db->prepare("SELECT body FROM wa_messages
                             WHERE conversation_id = ? AND direction = 'out'
                               AND (sent_by IS NULL OR sent_by = 0)
                               AND type = 'text' AND status <> 'failed'
                          ORDER BY id DESC LIMIT 1");
        $st->execute([$convId]);
        return trim((string)($st->fetchColumn() ?: ''));
    } catch (\Throwable $e) {
        error_log('waAutoLastSaid: ' . $e->getMessage());
        return '';
    }
}

function waAutoRespond(PDO $db, int $convId): array
{
    $d = waAutoDecide($db, $convId);
    if (!$d['allow']) return ['sent' => false, 'why' => $d['why']];

    // Checked before composing rather than after, because where there is no AI
    // there is nothing to compose — the answer is known in advance to be the
    // same sentence as last time.
    if ((!function_exists('carlAiRound') || !carlAiAvailable())
        && waAutoAlreadyAcknowledged($db, $convId)) {
        return ['sent' => false,
                'why'  => 'already acknowledged, and without an AI key there is nothing to add'];
    }

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

    // The same words twice in a row are not an answer.
    //
    // The check above catches the install with no AI key at all. This catches
    // the worse and less obvious case: a key that is configured but not working
    // — expired, out of quota, or a network that is down — where every call
    // fails and compose returns the same fixed acknowledgement each time. From
    // the customer's side those are identical, and both read as a machine that
    // has stopped listening. Whatever produced the text, it goes out once.
    if (waAutoLastSaid($db, $convId) === trim($text)) {
        error_log('waAutoRespond: held back a reply identical to the last one on thread ' . $convId);
        return ['sent' => false, 'why' => 'that is word for word what Karl said last time'];
    }

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
 * The heartbeat.
 *
 * Out of hours the webhook answers on arrival, because the message itself is
 * the trigger. During opening hours the trigger is the opposite — a minute
 * passing with nobody replying — and nothing on a web server fires when
 * nothing happens. Cron is the proper answer and cron_auto.php is there for it,
 * but a yard that has not set one up would simply never see a reply, and
 * "it works once you configure a cron job" is not a feature.
 *
 * So every logged-in browser already polls the unread badge every twenty
 * seconds, and that poll drives this. During working hours somebody is almost
 * always signed in, which is precisely when the grace period matters.
 *
 * Guarded by a lock in the settings table so that twenty staff polling at once
 * produce one sweep between them, not twenty. The lock is taken with a
 * conditional UPDATE — checking the time and then writing it would let two
 * requests pass the check together, which is the whole problem it exists to
 * prevent.
 */
function waAutoHeartbeat(PDO $db, int $everySeconds = 25): array
{
    if (!waAutoEnabled()) return ['ran' => false, 'sent' => 0];

    try {
        // Claim the slot. Exactly one caller sees a row affected.
        $st = $db->prepare("UPDATE settings
                               SET setting_value = UNIX_TIMESTAMP()
                             WHERE setting_key = 'wa_auto_last_sweep'
                               AND setting_value < (UNIX_TIMESTAMP() - ?)");
        $st->execute([max(5, $everySeconds)]);

        if ($st->rowCount() === 0) {
            // Either somebody else just swept, or the row does not exist yet.
            $has = $db->query("SELECT 1 FROM settings WHERE setting_key = 'wa_auto_last_sweep'")
                      ->fetchColumn();
            if ($has) return ['ran' => false, 'sent' => 0];
            $db->prepare("INSERT IGNORE INTO settings (setting_key, setting_value)
                          VALUES ('wa_auto_last_sweep', UNIX_TIMESTAMP())")->execute();
        }
    } catch (\Throwable $e) {
        error_log('waAutoHeartbeat lock: ' . $e->getMessage());
        return ['ran' => false, 'sent' => 0];
    }

    try {
        // Still small — this runs inside somebody's badge poll, and a sweep
        // that answers eight customers keeps their sidebar waiting. It is the
        // number of replies, though, not the number of threads examined:
        // skipping a thread nobody needs Karl in costs one query.
        $r = waAutoSweep($db, 6);
        return ['ran' => true, 'sent' => $r['sent']];
    } catch (\Throwable $e) {
        error_log('waAutoHeartbeat: ' . $e->getMessage());
        return ['ran' => true, 'sent' => 0];
    }
}
/**
 * Go through the threads that are waiting and answer the ones that are due.
 *
 * Called from the webhook and from cron. The number of REPLIES is capped,
 * because a sweep that tries to answer eighty threads in one request finishes
 * none of them — but the number of threads LOOKED AT is not the same number,
 * and conflating the two was its own quiet bug.
 *
 * The queue is ordered oldest-waiting-first, which is the right order to answer
 * people in. It is the wrong order to spend a budget of four on, because most
 * of the threads at the front are there precisely because they cannot be
 * answered — a colleague is handling them, or the grace period has not run out.
 * Those filled all four slots and the sweep went home, so a customer who had
 * just written waited for a turn that never came. That is the shape of "Karl
 * answered once and then stopped" seen from the other end.
 *
 * So: consider many, send few, stop when the budget is gone.
 *
 * @return array{considered:int, sent:int}
 */
function waAutoSweep(PDO $db, int $limit = 10): array
{
    if (!waAutoEnabled()) return ['considered' => 0, 'sent' => 0];

    $limit = max(1, min(50, $limit));

    try {
        // Only threads whose newest message came from the customer and is
        // recent enough to be worth answering at all.
        $st = $db->prepare("
            SELECT c.id
              FROM wa_conversations c
              JOIN wa_messages m ON m.id = (SELECT MAX(m2.id) FROM wa_messages m2
                                             WHERE m2.conversation_id = c.id)
             WHERE c.status = 'open'
               AND m.direction = 'in'
               AND m.sent_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)
          ORDER BY m.sent_at ASC
             LIMIT " . ($limit * 10));
        $st->execute();
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {
        error_log('waAutoSweep: ' . $e->getMessage());
        return ['considered' => 0, 'sent' => 0];
    }

    $sent = $seen = 0;
    foreach ($ids as $id) {
        $seen++;
        $r = waAutoRespond($db, (int)$id);
        if ($r['sent'] && ++$sent >= $limit) break;
    }
    return ['considered' => $seen, 'sent' => $sent];
}

} // function_exists('waAutoEnabled')
