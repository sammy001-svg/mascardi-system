<?php
/**
 * Carl — the in-system assistant.
 *
 * Carl sits in the top navbar beside the notification bell. She answers
 * questions about the business, reports figures, offers advice on what needs
 * attention, and carries out a small set of real tasks.
 *
 * How she works
 * -------------
 * Every answer Carl gives comes from a SKILL: a named capability with patterns
 * that match an utterance, and a handler that reads the database and returns a
 * spoken line plus optional rich HTML.
 *
 * The important design decision is that the language layer never touches the
 * database. Skills are deterministic PHP running ordinary queries, so a figure
 * Carl reads out is the same figure the report shows, and a task she performs is
 * one the code performed — not one a model decided to perform. When an Anthropic
 * API key is configured she additionally uses Claude to interpret free-form
 * questions and to phrase replies more naturally, but Claude only ever chooses
 * WHICH skill runs. It never executes anything and never invents a number.
 *
 * That also means Carl works with no API key at all, which is how she ships:
 * everything below runs offline. See carlLlmAvailable().
 *
 * Permissions
 * -----------
 * Carl is not a way around access control. Every skill declares the module it
 * belongs to and is filtered through canAccess() for the asking user, so a
 * mechanic asking about margins is told she cannot help rather than being told
 * the margins.
 */

if (!function_exists('carlMigrate')) {

if (!defined('CARL_SCHEMA_VERSION')) define('CARL_SCHEMA_VERSION', '1');

/** Her name, in one place, in case the company ever renames her. */
if (!defined('CARL_NAME')) define('CARL_NAME', 'Carl');

function carlMigrate(PDO $db, bool $force = false): void
{
    static $done = false;
    if ($done && !$force) return;
    $done = true;

    if (!$force) {
        try {
            $st = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'carl_schema_version'");
            $st->execute();
            if ((string)$st->fetchColumn() === CARL_SCHEMA_VERSION) return;
        } catch (\Throwable $_) {}
    }

    $tables = [
        // The conversation, so Carl can be closed and reopened without losing
        // the thread, and so a multi-step task survives a page navigation.
        "CREATE TABLE IF NOT EXISTS carl_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            role ENUM('user','carl') NOT NULL,
            body TEXT NOT NULL,
            skill VARCHAR(40) NULL,
            html MEDIUMTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_carl_user (user_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // A task in progress — Carl asking for the parts she still needs before
        // she can act. Kept server-side so a refresh does not lose half a lead.
        "CREATE TABLE IF NOT EXISTS carl_pending (
            user_id INT NOT NULL PRIMARY KEY,
            skill VARCHAR(40) NOT NULL,
            collected TEXT NULL,
            awaiting VARCHAR(40) NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // One greeting a day, per person — not one per page load.
        "CREATE TABLE IF NOT EXISTS carl_greetings (
            user_id INT NOT NULL PRIMARY KEY,
            greeted_on DATE NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($tables as $sql) { try { $db->exec($sql); } catch (\Throwable $_) {} }

    try {
        $db->prepare("INSERT INTO settings (setting_key, setting_value)
                      VALUES ('carl_schema_version', ?)
                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
           ->execute([CARL_SCHEMA_VERSION]);
    } catch (\Throwable $_) {}
}

// ── Small helpers ────────────────────────────────────────────────────────────

/** First name only — Carl addresses people the way a colleague would. */
function carlFirstName(string $full): string
{
    $p = preg_split('/\s+/', trim($full));
    return $p && $p[0] !== '' ? $p[0] : 'there';
}

/** Morning / afternoon / evening, on the DATABASE clock (PHP here runs UTC). */
function carlPartOfDay(PDO $db): string
{
    $h = 9;
    try { $h = (int)$db->query("SELECT HOUR(NOW())")->fetchColumn(); } catch (\Throwable $_) {}
    if ($h < 12) return 'morning';
    if ($h < 17) return 'afternoon';
    return 'evening';
}

function carlMoney(float $n): string { return 'KES ' . number_format($n); }

/**
 * "1 new lead" / "4 new leads".
 *
 * Carl reads her answers aloud, where a mismatched plural is far more obvious
 * than it is on screen.
 */
function carlPlural(int $n, string $singular, ?string $plural = null): string
{
    return $n . ' ' . ($n === 1 ? $singular : ($plural ?? $singular . 's'));
}

/** Speech is read aloud, so it must not contain markup or the reader says it. */
function carlSay(string $text): string
{
    return trim(preg_replace('/\s+/u', ' ', strip_tags($text)));
}

// ── Skills ───────────────────────────────────────────────────────────────────

/**
 * The full skill table.
 *
 * `module` is checked with canAccess() before a skill is offered or run, so the
 * same question gives different answers to different roles — which is correct.
 * `patterns` are matched case-insensitively against the utterance.
 */
function carlSkills(): array
{
    return [
        // Ordinary conversation, matched as a first-class skill so a greeting is
        // answered instantly rather than falling through the whole matcher first.
        // Handled natively by carlSkillChitchat: saying hello is not a business
        // query, and an assistant that needs a working API key and a network
        // round trip to say "good morning" looks broken in the first three
        // seconds of use.
        'chitchat' => [
            'label'    => 'Greetings and polite conversation',
            'module'   => null,
            'hidden'   => true,   // works, but is not a capability worth advertising
            'patterns' => ['hallo', 'hello', 'helo', 'hi', 'hey', 'heya', 'yo', 'sup',
                           'greetings', 'howdy', 'hi carl', 'hello carl',
                           // Only the greeting forms. A bare 'morning' also sits inside
                           // "how is business this morning", which was answered with hello.
                           'good morning', 'good afternoon', 'good evening',
                           // Swahili — this is a Nairobi yard, and people greet in it.
                           'habari', 'jambo', 'sasa', 'mambo', 'asante',
                           'how are you', 'how are you doing', 'how are things',
                           'you there', 'are you there', 'you awake', 'anyone there',
                           'who are you', 'what is your name', 'your name',
                           'thanks', 'thank you', 'cheers', 'well done', 'nice one',
                           'goodbye', 'bye', 'see you', 'good night'],
        ],
        'help' => [
            'label'    => 'What Carl can do',
            'module'   => null,
            'patterns' => ['help', 'what can you do', 'what do you do', 'commands', 'options'],
        ],
        'briefing' => [
            'label'    => 'Today at a glance',
            'module'   => null,
            'patterns' => ['brief', 'briefing', 'today', 'summary', 'overview', 'how are we doing',
                           'how is business', 'status', 'update me', 'catch me up'],
        ],
        'stock' => [
            'label'    => 'Vehicles in stock',
            'module'   => 'cars',
            'patterns' => ['stock', 'inventory', 'on the yard', 'in the yard', 'the yard', 'how many cars', 'vehicles available',
                           'cars available', 'what cars', 'fleet'],
        ],
        'leads' => [
            'label'    => 'Sales pipeline',
            'module'   => 'crm',
            'patterns' => ['lead', 'leads', 'pipeline', 'prospects', 'enquiries', 'enquiry',
                           'follow up date', 'past their follow up', 'overdue follow up',
                           'due for follow up', 'not been followed', 'been followed up',
                           'needs calling', 'need calling', 'to call'],
        ],
        'reservations' => [
            'label'    => 'Reservations',
            'module'   => 'crm',
            'patterns' => ['reservation', 'reservations', 'reserved', 'deposits', 'deposit'],
        ],
        'reserve' => [
            'label'    => 'Make a reservation',
            'module'   => 'crm',
            'patterns' => ['make a reservation', 'reserve a car', 'reserve a vehicle',
                           'take a deposit', 'put a deposit', 'hold a car', 'hold the car',
                           'book a car', 'new reservation', 'create a reservation',
                           'reserve it for', 'place a deposit'],
        ],
        'deliveries' => [
            'label'    => 'Deliveries',
            'module'   => 'crm',
            'patterns' => ['delivery', 'deliveries', 'delivered', 'handover', 'hand over',
                           'ready to deliver', 'delivery pipeline', 'delivery protocol',
                           'what is holding up', 'due for delivery', 'delivering',
                           'when are we delivering', 'ready for handover'],
        ],
        'visitors' => [
            'label'    => 'Visitors today',
            'module'   => 'visitors',
            'patterns' => ['visitor', 'visitors', 'walk in', 'walk-in', 'walkins', 'reception',
                           'visited', 'who came in', 'signed in'],
        ],
        'workshop' => [
            'label'    => 'Workshop',
            'module'   => 'jobs',
            'patterns' => ['workshop', 'job card', 'job cards', 'repairs', 'garage', 'service jobs'],
        ],
        'money' => [
            'label'    => 'Money in',
            'module'   => 'payments',
            'patterns' => ['revenue', 'payments', 'money', 'sales figures', 'takings',
                           'invoiced', 'invoices', 'turnover', 'income',
                           'did we take', 'have we taken', 'how much have we', 'collected', 'cash',
                           'our sales', 'sales this', 'total sales'],
        ],
        'trends' => [
            'label'    => 'Trends and comparisons',
            'module'   => null,
            'patterns' => ['compare', 'comparison', 'trend', 'trends', 'last month', 'last week',
                           'previous month', 'previous week', 'vs last', 'versus last', 'better than',
                           'worse than', 'ahead', 'behind', 'improve', 'improved', 'growth', 'decline',
                           'this month vs', 'this week vs', 'year to date'],
        ],
        'advice' => [
            'label'    => 'What needs attention',
            'module'   => null,
            'patterns' => ['advice', 'advise', 'what should i do', 'what needs attention',
                           'priorities', 'what is urgent', 'anything urgent', 'recommend',
                           'worry', 'worrying', 'worried', 'concern', 'concerns', 'problem',
                           'problems', 'issues', 'focus on', 'needs doing', 'anything wrong',
                           'chase', 'slipping', 'falling behind'],
        ],
        // Checked before anything else it might resemble: "delete that lead" would
        // otherwise score on 'lead' and open the pipeline report, which reads as
        // though she is about to do it.
        'no_delete' => [
            'label'    => 'Removing records',
            'module'   => null,
            'patterns' => ['delete', 'remove the', 'remove this', 'remove that',
                           'erase', 'wipe', 'get rid of', 'clear out', 'purge',
                           'drop the', 'take it off the system', 'take off the system'],
        ],
        'add_deposit' => [
            'label'    => 'Record another deposit',
            'module'   => 'crm',
            'patterns' => ['additional deposit', 'another deposit', 'extra deposit',
                           'add a deposit', 'add deposit', 'top up the deposit',
                           'topped up', 'further deposit', 'part payment',
                           'record a payment', 'paid more', 'more deposit'],
        ],
        'add_lead' => [
            'label'    => 'Add a lead',
            'module'   => 'crm',
            'patterns' => ['add a lead', 'new lead', 'create a lead', 'capture a lead',
                           'add lead', 'log a lead', 'register a lead',
                           'add a customer', 'new customer', 'add a client', 'capture a customer'],
        ],
        'priority_lead' => [
            'label'    => 'Change lead priority (hot / lukewarm / cold)',
            'module'   => 'crm',
            'patterns' => ['mark as hot', 'mark hot', 'mark as lukewarm', 'mark lukewarm',
                           'mark as cold', 'mark cold', 'lead is hot', 'lead is cold',
                           'set priority', 'change priority', 'flag as hot', 'flag lead',
                           'hot lead', 'cold lead', 'move to hot', 'move to cold'],
        ],
        'followup_lead' => [
            'label'    => 'Set a follow-up date on a lead',
            'module'   => 'crm',
            // Imperatives only. 'follow up' on its own also appears in
            // "leads past their follow up date", which is a question about the
            // pipeline — answering it by starting to write a date is worse than
            // useless, because it changes a record nobody asked to change.
            'patterns' => ['set a follow up', 'set follow up', 'schedule follow up',
                           'schedule a follow up', 'change the follow up', 'remind me',
                           'chase', 'set a date', 'book a call', 'set follow up for',
                           'remind about'],
        ],
        'note_lead' => [
            'label'    => 'Add a note to a lead',
            'module'   => 'crm',
            'patterns' => ['add a note', 'note on', 'log a note', 'add note', 'note to lead',
                           'record a note', 'log a call', 'note down', 'make a note', 'log note'],
        ],
        'call_lead' => [
            'label'    => 'Call a lead',
            'module'   => 'crm',
            'patterns' => ['call', 'ring', 'phone', 'dial', 'call the lead', 'ring the lead'],
        ],
        'document' => [
            'label'    => 'Generate a document',
            'module'   => 'crm',
            'patterns' => ['document', 'proforma', 'invoice for', 'quotation', 'agreement',
                           'receipt', 'print', 'generate',
                           // Longer than the 'delivery' the deliveries skill matches on,
                           // so asking for the note reaches the printer, not the report.
                           'delivery note', 'handover note', 'gate pass',
                           // Named in full. "Print a deposit receipt" tied with the
                           // reservations report on the word 'deposit', and the report
                           // won on iteration order — so asking for the receipt gave a
                           // count of deposits held instead.
                           'deposit receipt', 'deposit slip', 'sales receipt',
                           'proforma invoice', 'sales agreement', 'credit agreement',
                           'print a', 'print the', 'print me', 'generate a', 'generate the'],
        ],
        'navigate' => [
            'label'    => 'Open a page',
            'module'   => null,
            'patterns' => ['open', 'go to', 'take me to', 'show me the', 'navigate'],
        ],
    ];
}

/** The skills this particular user is allowed to use. */
function carlSkillsFor(): array
{
    $out = [];
    foreach (carlSkills() as $key => $s) {
        if ($s['module'] === null || canAccess($s['module'])) $out[$key] = $s;
    }
    return $out;
}

/**
 * Which skill an utterance is asking for.
 *
 * Longest pattern first, so "add a lead" beats the bare "lead" — otherwise every
 * request to create something is answered with a pipeline report.
 */
/** Words too common to identify a subject — they carry no signal about topic. */
function carlIsStopWord(string $w): bool
{
    static $stop = ['what','how','who','when','where','which','why','the','are','you','can','and',
                    'for','with','was','were','have','has','had','our','its','from','that','this',
                    'about','any','all','give','get','tell','show','see','many','much','doing','did',
                    'does','done','been','being','into','over','than','then','them','they','there'];
    return in_array($w, $stop, true);
}

function carlMatchSkill(string $text): ?string
{
    $t = ' ' . strtolower(trim(preg_replace('/\s+/u', ' ', $text))) . ' ';

    // An explicit imperative wins over the subject it names. Without this,
    // "open the leads page" scored on the longer word "leads" and returned a
    // pipeline report, while "show me the leads" navigated — the same request
    // answered two different ways depending on which word happened to be longer.
    // Only when the command LEADS the sentence. Matching the verb anywhere sent
    // "any job cards open" to the navigator, because it happens to end in "open".
    $lead = preg_replace('/^ (?:please|can you|could you|would you|kindly|carl,?) /', ' ', $t);
    $verbs = [' open ', ' go to ', ' take me to ', ' show me the ', ' navigate '];
    foreach ($verbs as $v) {
        if (str_starts_with($lead, $v) && isset(carlSkillsFor()['navigate'])) return 'navigate';
    }

    // Matched against EVERY skill, not only the permitted ones. A question about
    // something this account cannot see should be answered "that is not open to
    // your account" — which carlRun() does — rather than falling through to
    // "I did not catch that", which is both untrue and unhelpful.
    //
    // Scored rather than exact-matched. Requiring the phrase verbatim made Carl
    // brittle in exactly the way people notice: "leads" worked, "any leads that
    // went cold?" did not. Scoring on word overlap means a question only has to
    // be recognisably about something, not phrased the way the table happens to
    // spell it.
    $words = array_values(array_filter(explode(' ', trim($t)), fn($w) => $w !== ''));
    $best = null; $bestScore = 0.0;

    foreach (carlSkills() as $key => $s) {
        $score = 0.0;
        foreach ($s['patterns'] as $p) {
            // A whole phrase present verbatim is the strongest signal there is.
            // Anchored at BOTH ends, with only a genuine suffix allowed. Matching
            // on a word start alone meant the greeting "hi" fired inside
            // "history", and Carl answered a question about the record with hello.
            if (preg_match('/\b' . preg_quote($p, '/') . '(s|es|ed|ing)?\b/', $t)) {
                $score = max($score, 10 + strlen($p) / 10);
                continue;
            }
            // Otherwise credit the words of the pattern that do appear, so a
            // multi-word pattern is not all-or-nothing.
            //
            // Question words are excluded. Nearly every question begins "what"
            // or "how", so counting them scored "what vehicles are on the yard"
            // against help's "what can you do" and answered the wrong question
            // entirely. Only the words that actually name a subject count.
            $pw = array_filter(explode(' ', $p), fn($w) => strlen($w) > 2 && !carlIsStopWord($w));
            if (!$pw) continue;
            $hit = 0;
            foreach ($pw as $w) {
                foreach ($words as $uw) {
                    // Tolerates plurals and simple endings: lead/leads, reserve/reserved.
                    // Both sides need length. With a floor on only one of them, the
                    // bare "a" in "a spaceship" prefix-matched "asante" and scored a
                    // full greeting.
                    if ($uw === $w
                        || (strlen($w) > 3 && strlen($uw) > 3
                            && (str_starts_with($uw, $w) || str_starts_with($w, $uw)))) {
                        $hit++; break;
                    }
                }
            }
            if ($hit) $score = max($score, ($hit / count($pw)) * 6);
        }
        if ($score > $bestScore) { $bestScore = $score; $best = $key; }
    }

    // Below this the "match" is one incidental word and a guess would be worse
    // than admitting she did not follow. The bar sits just above half of a
    // two-word pattern (3.0), because matching only "one" of "nice one" is what
    // made "the blue one" read as a greeting.
    return $bestScore >= 3.5 ? $best : null;
}

// ── Figures ──────────────────────────────────────────────────────────────────

/** One number, guarded — a missing table must never break the whole answer. */
function carlNum(PDO $db, string $sql, array $args = []): int
{
    try { $st = $db->prepare($sql); $st->execute($args); return (int)$st->fetchColumn(); }
    catch (\Throwable $_) { return 0; }
}
function carlSum(PDO $db, string $sql, array $args = []): float
{
    try { $st = $db->prepare($sql); $st->execute($args); return (float)$st->fetchColumn(); }
    catch (\Throwable $_) { return 0.0; }
}

/**
 * Everything Carl reports on, gathered once.
 *
 * All date arithmetic is done in SQL. PHP here has no timezone configured and
 * runs in UTC while MySQL runs local time, so "today" computed in PHP would be
 * the wrong day for three hours every evening.
 */
function carlFigures(PDO $db): array
{
    // ── Current period ────────────────────────────────────────────────────────
    $now = [
        'stock_total'     => carlNum($db, "SELECT COUNT(*) FROM cars WHERE car_type IN ('inventory','sale_on_behalf')"),
        'stock_available' => carlNum($db, "SELECT COUNT(*) FROM cars WHERE car_type IN ('inventory','sale_on_behalf')
                                           AND (status IS NULL OR status NOT IN ('sold','delivered','reserved','in_transit'))"),
        'stock_reserved'  => carlNum($db, "SELECT COUNT(*) FROM cars WHERE status = 'reserved'"),
        'stock_transit'   => carlNum($db, "SELECT COUNT(*) FROM cars WHERE status = 'in_transit'"),
        'sold_month'      => carlNum($db, "SELECT COUNT(*) FROM cars WHERE status IN ('sold','delivered')
                                           AND updated_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),

        'leads_total'     => carlNum($db, "SELECT COUNT(*) FROM crm_leads WHERE stage NOT IN ('won','lost')"),
        'leads_new_today' => carlNum($db, "SELECT COUNT(*) FROM crm_leads WHERE DATE(created_at) = CURDATE()"),
        'leads_new_week'  => carlNum($db, "SELECT COUNT(*) FROM crm_leads WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)"),
        'leads_new_month' => carlNum($db, "SELECT COUNT(*) FROM crm_leads WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
        'leads_reserved'  => carlNum($db, "SELECT COUNT(*) FROM crm_leads WHERE stage = 'reserved'"),
        'leads_nofollow'  => carlNum($db, "SELECT COUNT(*) FROM crm_leads
                                           WHERE stage NOT IN ('won','lost') AND follow_up_date IS NULL"),
        'leads_overdue'   => carlNum($db, "SELECT COUNT(*) FROM crm_leads
                                           WHERE stage NOT IN ('won','lost') AND follow_up_date < CURDATE()"),
        'deposits_held'   => carlSum($db, "SELECT COALESCE(SUM(deposit_amount),0) FROM crm_leads WHERE stage = 'reserved'"),

        'visitors_today'  => carlNum($db, "SELECT COUNT(*) FROM visitors WHERE DATE(created_at) = CURDATE()"),
        'visitors_onsite' => carlNum($db, "SELECT COUNT(*) FROM visitors
                                           WHERE checked_out_at IS NULL AND DATE(created_at) = CURDATE()"),
        'visitors_week'   => carlNum($db, "SELECT COUNT(*) FROM visitors WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)"),
        'visitors_month'  => carlNum($db, "SELECT COUNT(*) FROM visitors WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),

        'jobs_open'       => carlNum($db, "SELECT COUNT(*) FROM workshop_jobs WHERE status NOT IN ('completed','cancelled')"),
        'jobs_today'      => carlNum($db, "SELECT COUNT(*) FROM workshop_jobs WHERE DATE(created_at) = CURDATE()"),

        'bookings_today'  => carlNum($db, "SELECT COUNT(*) FROM service_bookings WHERE DATE(booking_date) = CURDATE()"),

        'paid_month'      => carlSum($db, "SELECT COALESCE(SUM(amount),0) FROM payments
                                           WHERE payment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
        'paid_today'      => carlSum($db, "SELECT COALESCE(SUM(amount),0) FROM payments
                                           WHERE DATE(payment_date) = CURDATE()"),
        'paid_week'       => carlSum($db, "SELECT COALESCE(SUM(amount),0) FROM payments
                                           WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)"),
        'invoices_unpaid' => carlNum($db, "SELECT COUNT(*) FROM invoices WHERE status <> 'paid'"),
    ];

    // ── Comparison period (previous full calendar month) ────────────────────
    $prev = [
        'sold_month'      => carlNum($db, "SELECT COUNT(*) FROM cars WHERE status IN ('sold','delivered')
                                           AND updated_at >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01')
                                           AND updated_at <  DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
        'leads_new_month' => carlNum($db, "SELECT COUNT(*) FROM crm_leads
                                           WHERE created_at >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01')
                                           AND created_at  <  DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
        'leads_new_week'  => carlNum($db, "SELECT COUNT(*) FROM crm_leads
                                           WHERE created_at >= DATE_SUB(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 7 DAY)
                                           AND created_at  <  DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)"),
        'visitors_month'  => carlNum($db, "SELECT COUNT(*) FROM visitors
                                           WHERE created_at >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01')
                                           AND created_at  <  DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
        'visitors_week'   => carlNum($db, "SELECT COUNT(*) FROM visitors
                                           WHERE created_at >= DATE_SUB(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 7 DAY)
                                           AND created_at  <  DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)"),
        'paid_month'      => carlSum($db, "SELECT COALESCE(SUM(amount),0) FROM payments
                                           WHERE payment_date >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01')
                                           AND payment_date  <  DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
        'paid_week'       => carlSum($db, "SELECT COALESCE(SUM(amount),0) FROM payments
                                           WHERE payment_date >= DATE_SUB(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 7 DAY)
                                           AND payment_date  <  DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)"),
    ];

    return array_merge($now, ['prev' => $prev]);
}


/**
 * Is this a message the model should handle, or can the database answer it?
 *
 * Every API call costs money, and the matcher answers the forty phrasings people
 * actually use — greetings, stock, pipeline, revenue, visitors, tasks — correctly
 * and instantly. Paying Opus to say "there are five vehicles available" is money
 * spent on the questions that needed it least.
 *
 * The model earns its keep in two places, and only these:
 *
 *   1. Nothing matched. This is the case that used to produce "I did not catch
 *      that", and it is exactly where a conversation is worth paying for.
 *   2. The message only makes sense against the previous turn — "and last
 *      month?", "what about theirs?", "why?". The matcher reads each message
 *      alone, so it cannot follow those, and answering them from a keyword would
 *      be worse than not answering.
 */
function carlNeedsModel(string $msg, ?string $skill, array $history = []): bool
{
    // Nothing recognised — this is what the model is for.
    if ($skill === null) return true;

    $t = strtolower(trim($msg));

    // A reply that leans on what was just said. Only meaningful when there IS a
    // previous turn; the same words opening a conversation are just a question.
    if ($history) {
        if (preg_match('/^(and|so|then|but|what about|how about|why|why not|really|are you sure)\b/', $t)) {
            return true;
        }
        // Pronouns with no noun of their own: "show me theirs", "is it ready".
        if (preg_match('/\b(them|those|theirs|that one|the same|it)\b/', $t)
            && str_word_count($t) <= 8) {
            return true;
        }
    }

    // Asking for judgement rather than a figure. "Is it worth chasing the ones
    // that went quiet" matches the pipeline skill, but a count of cold leads is
    // not an answer to it — the question is what to DO, and that is reasoning,
    // which is the one thing a lookup table cannot fake.
    if (preg_match('/\b(should i|should we|worth|do you think|what do you reckon|reckon|'
                 . 'recommend|advise|advice on|your view|opinion|better to|rather than|'
                 . 'compare|versus|vs\.?|instead of|how do i|what would you)\b/', $t)) {
        return true;
    }

    // Long, discursive questions carry more than the matched keyword — "how many
    // leads went cold and who was meant to be calling them" is not answered by
    // the pipeline report alone.
    if (str_word_count($t) >= 12) return true;

    // Everything else the matcher recognised, it can answer for nothing.
    return false;
}

// ── Carl may never destroy anything ──────────────────────────────────────────
//
// This is a standing rule, not a preference, so it is enforced here rather than
// left to whoever adds the next task remembering it. Every write Carl makes goes
// through carlGuardedExec(), which refuses anything destructive outright.
//
// The point is that Carl grows. Tasks get added by people who are thinking about
// the feature, not about the guarantee — and a guarantee that depends on nobody
// forgetting is not a guarantee. A DELETE reaching this function is a bug, and
// it is stopped and logged rather than executed.
//
// Note what this does NOT prevent, deliberately: a person doing the same thing
// through the normal pages, where the module's own permissions and confirmations
// apply. This constrains Carl, not the system.

/** SQL Carl is never allowed to run, however it is spelled. */
function carlForbiddenSql(string $sql): ?string
{
    // Comments stripped first: a destructive verb hidden behind /* */ or --
    // would otherwise slip past a naive scan and still execute.
    $bare = preg_replace('~/\*.*?\*/~s', ' ', $sql);
    $bare = preg_replace('~--[^\n]*~', ' ', (string)$bare);
    $bare = strtolower(preg_replace('/\s+/', ' ', (string)$bare));

    foreach ([
        'delete'   => 'delete rows',
        'drop'     => 'drop a table or column',
        'truncate' => 'empty a table',
        'rename'   => 'rename a table',
    ] as $verb => $what) {
        if (preg_match('/\b' . $verb . '\b/', $bare)) return $what;
    }

    // An UPDATE with no WHERE rewrites every row in the table, which is
    // destruction by another name.
    if (preg_match('/^\s*update\b/', $bare) && !preg_match('/\bwhere\b/', $bare)) {
        return 'change every row in a table at once';
    }

    return null;
}

/**
 * The only way Carl writes to the database.
 *
 * Returns true when the statement ran. A refusal is logged with the statement
 * that caused it, so an attempt shows up in the log rather than failing quietly.
 */
function carlGuardedExec(PDO $db, string $sql, array $params = []): bool
{
    $forbidden = carlForbiddenSql($sql);
    if ($forbidden !== null) {
        error_log('[Carl] REFUSED — a task tried to ' . $forbidden . ': ' . trim($sql));
        try {
            logActivity('blocked', 'carl', null,
                'Carl was asked to ' . $forbidden . ' and refused. She is not permitted to '
                . 'remove anything from the system.');
        } catch (\Throwable $e) { /* never let logging break the refusal */ }
        return false;
    }

    try {
        $db->prepare($sql)->execute($params);
        return true;
    } catch (\Throwable $e) {
        error_log('[Carl] write failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * What Carl says when asked to remove something.
 *
 * Plainly, once, without lecturing — and pointing at who can, so the person is
 * not left stuck.
 */
function carlRefuseDeletion(string $what = 'that'): array
{
    return [
        'skill' => 'refused', 'done' => true,
        'say'   => 'I am not able to delete ' . $what . '. Removing records is deliberately '
                 . 'outside what I can do — it needs a person on the record\'s own page, where '
                 . 'the permissions and the confirmation live. I can correct, update or add '
                 . 'instead, if that would do.',
        'html'  => '',
    ];
}

} // function_exists('carlMigrate')
