<?php
/**
 * The lead nobody has spoken to in a fortnight.
 *
 * A buyer walks in, gets a quote, goes away to think. The salesperson means to
 * ring them and then a delivery goes wrong, and by the time anyone looks again
 * the lead is three weeks cold and the buyer has assumed we lost interest. It
 * is the commonest way a deal dies, and nothing in the system was watching for
 * it — silence does not raise an event, so something has to come looking.
 *
 * So once a day this looks for leads nobody has touched in fourteen days and
 * sends one message, from Karl, on WhatsApp and by email. It is not a sales
 * push: it asks whether the buyer has what they need and offers to escalate.
 *
 * The things that stop it becoming a nuisance, which matter more than the
 * sending itself:
 *
 *   · Only leads still in play. A lost lead said no, and a delivered one is a
 *     client — neither wants asking how their purchase is going.
 *   · Once, then a long cooldown. A lead that stays quiet is a lead the yard
 *     is ignoring, and the answer to that is a person, not a second message.
 *   · Its own messages count as contact, so it cannot see its own silence and
 *     nudge again tomorrow.
 *   · A cap per run, so the first run over a year of backlog sends a handful
 *     and not nine hundred.
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/dispatch.php';

/** How the yard has it set up. All overridable in settings, none needing code. */
function nudgeConf(): array
{
    return [
        'on'       => getSetting('lead_nudge_enabled', '1') === '1',
        'days'     => max(1, (int)(getSetting('lead_nudge_days', '14') ?: 14)),
        'cooldown' => max(1, (int)(getSetting('lead_nudge_cooldown', '30') ?: 30)),
        'cap'      => max(1, (int)(getSetting('lead_nudge_cap', '40') ?: 40)),
        'wa'       => getSetting('lead_nudge_whatsapp', '1') === '1',
        'email'    => getSetting('lead_nudge_email', '1') === '1',
    ];
}

/** Stages worth chasing. Not the ones that have already ended. */
function nudgeStages(): array
{
    // The stages this system actually uses. 'lost' said no and 'delivered' is
    // already a client — neither wants asking how their purchase is going.
    return ['hot', 'lukewarm', 'cold', 'reserved', 'import_order'];
}

/** The message, as the yard wrote it. */
function nudgeText(): string
{
    $d = 'Hi, Karl here with the Mascardi Customer Care Team. We hope your sales team has been '
       . 'able to be of support on your new car purchase. Please let us know if there is any '
       . 'other information I may assist with or escalate to enhance your car buying experience '
       . 'with Mascardi?';
    $t = trim((string)getSetting('lead_nudge_message', ''));
    return $t !== '' ? $t : $d;
}

function nudgeSchema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS crm_lead_nudges (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            lead_id    INT          NOT NULL,
            channel    VARCHAR(12)  NOT NULL,
            ok         TINYINT(1)   NOT NULL DEFAULT 0,
            error      VARCHAR(255) NULL,
            sent_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_lead (lead_id),
            INDEX idx_sent (sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) { /* already there */ }
}

/**
 * Leads nobody has touched.
 *
 * "Touched" is the latest of four things, because contact is recorded in four
 * places and using only one of them would chase people who were spoken to
 * yesterday:
 *
 *   the activity log        a call, a note, a meeting, a visit
 *   outbound WhatsApp       on a conversation linked to this lead
 *   a nudge we already sent so it cannot answer its own silence
 *   the lead being created  a brand-new lead has not gone quiet, it is new
 *
 * All of it in SQL, deliberately. PHP here runs on UTC and MySQL on EAT, so a
 * fortnight measured across the two languages is a fortnight plus three hours
 * in one direction and minus three in the other.
 */
function nudgeDue(PDO $db, ?int $days = null, int $limit = 100): array
{
    nudgeSchema($db);
    $c    = nudgeConf();
    $days = $days ?? $c['days'];
    $in   = implode(',', array_fill(0, count(nudgeStages()), '?'));

    $sql = "
        SELECT l.id, l.name, l.phone, l.email, l.stage, l.assigned_to,
               GREATEST(
                   l.created_at,
                   COALESCE((SELECT MAX(a.created_at) FROM crm_activities a
                              WHERE a.lead_id = l.id), l.created_at),
                   COALESCE((SELECT MAX(m.sent_at) FROM wa_messages m
                               JOIN wa_conversations cv ON cv.id = m.conversation_id
                              WHERE cv.lead_id = l.id AND m.direction = 'out'), l.created_at),
                   COALESCE((SELECT MAX(n.sent_at) FROM crm_lead_nudges n
                              WHERE n.lead_id = l.id), l.created_at)
               ) AS last_touch
          FROM crm_leads l
         WHERE l.stage IN ($in)
           AND (l.phone IS NOT NULL AND l.phone <> '' OR l.email IS NOT NULL AND l.email <> '')
        HAVING last_touch < DATE_SUB(NOW(), INTERVAL ? DAY)
      ORDER BY last_touch ASC
         LIMIT ?";

    try {
        $st = $db->prepare($sql);
        $i  = 1;
        foreach (nudgeStages() as $s) $st->bindValue($i++, $s);
        $st->bindValue($i++, $days, PDO::PARAM_INT);
        $st->bindValue($i++, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        // wa_messages or wa_conversations may not exist on a install that has
        // never connected WhatsApp. Fall back to the activity log alone rather
        // than letting the whole sweep die on a missing table.
        error_log('nudgeDue: ' . $e->getMessage());
        try {
            $st = $db->prepare("
                SELECT l.id, l.name, l.phone, l.email, l.stage, l.assigned_to,
                       GREATEST(l.created_at,
                           COALESCE((SELECT MAX(a.created_at) FROM crm_activities a
                                      WHERE a.lead_id = l.id), l.created_at),
                           COALESCE((SELECT MAX(n.sent_at) FROM crm_lead_nudges n
                                      WHERE n.lead_id = l.id), l.created_at)) AS last_touch
                  FROM crm_leads l
                 WHERE l.stage IN ($in)
                   AND (l.phone IS NOT NULL AND l.phone <> '' OR l.email IS NOT NULL AND l.email <> '')
                HAVING last_touch < DATE_SUB(NOW(), INTERVAL ? DAY)
              ORDER BY last_touch ASC
                 LIMIT ?");
            $i = 1;
            foreach (nudgeStages() as $s) $st->bindValue($i++, $s);
            $st->bindValue($i++, $days, PDO::PARAM_INT);
            $st->bindValue($i++, $limit, PDO::PARAM_INT);
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e2) {
            error_log('nudgeDue fallback: ' . $e2->getMessage());
            return [];
        }
    }
}

/** Was this lead nudged recently enough that another would be nagging? */
function nudgeInCooldown(PDO $db, int $leadId, ?int $days = null): bool
{
    nudgeSchema($db);
    $days = $days ?? nudgeConf()['cooldown'];
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM crm_lead_nudges
                             WHERE lead_id = ? AND sent_at > DATE_SUB(NOW(), INTERVAL ? DAY)");
        $st->bindValue(1, $leadId, PDO::PARAM_INT);
        $st->bindValue(2, $days, PDO::PARAM_INT);
        $st->execute();
        return (int)$st->fetchColumn() > 0;
    } catch (\Throwable $e) {
        // Unsure means do not send. A missed nudge is a missed opportunity; a
        // repeated one is a customer wondering why we keep messaging them.
        return true;
    }
}

/** Write down what happened, whichever way it went. */
function nudgeRecord(PDO $db, int $leadId, string $channel, bool $ok, string $error = ''): void
{
    nudgeSchema($db);
    try {
        $db->prepare("INSERT INTO crm_lead_nudges (lead_id, channel, ok, error) VALUES (?,?,?,?)")
           ->execute([$leadId, $channel, $ok ? 1 : 0, mb_substr($error, 0, 250) ?: null]);
    } catch (\Throwable $e) {
        error_log('nudgeRecord: ' . $e->getMessage());
    }
}

/**
 * Message one lead, on whichever channels it has.
 *
 * Both are attempted: a buyer who does not read WhatsApp may read email, and
 * the yard's WhatsApp being cut off should not mean nobody hears from us. A
 * channel with no address is skipped rather than recorded as a failure.
 */
function nudgeOne(PDO $db, array $lead, bool $dry = false): array
{
    $c      = nudgeConf();
    $leadId = (int)$lead['id'];
    $text   = nudgeText();
    $out    = ['lead' => $leadId, 'name' => (string)$lead['name'],
               'whatsapp' => 'skipped', 'email' => 'skipped'];

    $phone = trim((string)($lead['phone'] ?? ''));
    $email = trim((string)($lead['email'] ?? ''));

    if ($c['wa'] && $phone !== '') {
        if ($dry) {
            $out['whatsapp'] = 'would send';
        } else {
            $ok = dispatchToClient($phone, 'lead_followup', $text);
            $out['whatsapp'] = $ok ? 'sent' : 'failed';
            nudgeRecord($db, $leadId, 'whatsapp', $ok,
                $ok ? '' : 'WhatsApp did not accept the message');
        }
    }

    if ($c['email'] && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        if ($dry) {
            $out['email'] = 'would send';
        } else {
            $company = getSetting('company_name', 'Mascardi');
            $body = mailTemplate('How is your car purchase going?',
                '<p>' . nl2br(e($text)) . '</p>');
            $r = sendMail($email, (string)$lead['name'],
                          'How is your car purchase going?', $body, 'lead_followup', $leadId);
            $out['email'] = $r['ok'] ? 'sent' : 'failed';
            nudgeRecord($db, $leadId, 'email', (bool)$r['ok'], (string)$r['error']);
        }
    }

    // On the lead itself, so the next person to open it sees that Karl has
    // already been in touch and does not repeat him.
    if (!$dry && ($out['whatsapp'] === 'sent' || $out['email'] === 'sent')) {
        try {
            $sent = array_keys(array_filter(
                ['WhatsApp' => $out['whatsapp'] === 'sent', 'email' => $out['email'] === 'sent']));
            // 'note', because that is a type this system's timeline knows how
            // to draw. 'follow_up' is not one of them and would have rendered
            // as a blank icon with no label.
            $db->prepare("INSERT INTO crm_activities (lead_id, type, summary, created_by)
                          VALUES (?, 'note', ?, NULL)")
               ->execute([$leadId, 'Karl followed up automatically by '
                                   . implode(' and ', $sent)
                                   . ' — no contact for ' . $c['days'] . ' days']);
            logActivity('update', 'crm_leads', $leadId,
                'Automatic follow-up sent (' . implode(', ', $sent) . ')');
        } catch (\Throwable $e) { /* the message went; the note is secondary */ }
    }

    return $out;
}

/**
 * The daily pass.
 *
 * Returns what it did rather than printing, so the cron can report and a test
 * can assert. Nothing is sent when the feature is switched off, and a dry run
 * touches nothing at all.
 */
function nudgeSweep(PDO $db, bool $dry = false, ?int $cap = null, ?int $days = null): array
{
    nudgeSchema($db);
    $c    = nudgeConf();
    $cap  = $cap  ?? $c['cap'];
    $days = $days ?? $c['days'];
    $res = ['enabled' => $c['on'], 'considered' => 0, 'cooling' => 0,
            'sent' => 0, 'failed' => 0, 'dry' => $dry, 'rows' => []];

    if (!$c['on']) return $res;

    // Ask for more than the cap: the ones in cooldown are filtered here rather
    // than in SQL, so a page full of them would otherwise crowd out the leads
    // that genuinely are due.
    $due = nudgeDue($db, $days, $cap * 3);
    foreach ($due as $lead) {
        if ($res['sent'] >= $cap) break;
        $res['considered']++;

        if (nudgeInCooldown($db, (int)$lead['id'], $c['cooldown'])) {
            $res['cooling']++;
            continue;
        }

        $r = nudgeOne($db, $lead, $dry);
        $res['rows'][] = $r;
        if ($r['whatsapp'] === 'sent' || $r['email'] === 'sent'
            || ($dry && ($r['whatsapp'] === 'would send' || $r['email'] === 'would send'))) {
            $res['sent']++;
        } else {
            $res['failed']++;
        }
    }
    return $res;
}
