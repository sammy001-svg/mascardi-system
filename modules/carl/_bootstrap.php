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
            'patterns' => ['stock', 'inventory', 'how many cars', 'vehicles available',
                           'cars available', 'what cars', 'fleet'],
        ],
        'leads' => [
            'label'    => 'Sales pipeline',
            'module'   => 'crm',
            'patterns' => ['lead', 'leads', 'pipeline', 'prospects', 'enquiries', 'enquiry'],
        ],
        'reservations' => [
            'label'    => 'Reservations',
            'module'   => 'crm',
            'patterns' => ['reservation', 'reservations', 'reserved', 'deposits', 'deposit'],
        ],
        'visitors' => [
            'label'    => 'Visitors today',
            'module'   => 'visitors',
            'patterns' => ['visitor', 'visitors', 'walk in', 'walk-in', 'walkins', 'reception'],
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
                           'did we take', 'have we taken', 'how much have we', 'collected', 'cash'],
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
        'add_lead' => [
            'label'    => 'Add a lead',
            'module'   => 'crm',
            'patterns' => ['add a lead', 'new lead', 'create a lead', 'capture a lead',
                           'add lead', 'log a lead', 'register a lead'],
        ],
        'document' => [
            'label'    => 'Generate a document',
            'module'   => 'crm',
            'patterns' => ['document', 'proforma', 'invoice for', 'quotation', 'agreement',
                           'receipt', 'print', 'generate'],
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
    $verbs = [' open ', ' go to ', ' take me to ', ' show me the ', ' navigate '];
    foreach ($verbs as $v) {
        if (str_contains($t, $v) && isset(carlSkillsFor()['navigate'])) return 'navigate';
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
            if (str_contains($t, ' ' . $p . ' ') || str_contains($t, ' ' . $p)) {
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
                    if ($uw === $w || (strlen($w) > 3 && (str_starts_with($uw, $w) || str_starts_with($w, $uw)))) {
                        $hit++; break;
                    }
                }
            }
            if ($hit) $score = max($score, ($hit / count($pw)) * 6);
        }
        if ($score > $bestScore) { $bestScore = $score; $best = $key; }
    }

    // Below this the "match" is one incidental word and a guess would be worse
    // than admitting she did not follow.
    return $bestScore >= 3.0 ? $best : null;
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
    return [
        'stock_total'     => carlNum($db, "SELECT COUNT(*) FROM cars WHERE car_type IN ('inventory','sale_on_behalf')"),
        'stock_available' => carlNum($db, "SELECT COUNT(*) FROM cars WHERE car_type IN ('inventory','sale_on_behalf')
                                           AND (status IS NULL OR status NOT IN ('sold','delivered','reserved','in_transit'))"),
        'stock_reserved'  => carlNum($db, "SELECT COUNT(*) FROM cars WHERE status = 'reserved'"),
        'stock_transit'   => carlNum($db, "SELECT COUNT(*) FROM cars WHERE status = 'in_transit'"),
        'sold_month'      => carlNum($db, "SELECT COUNT(*) FROM cars WHERE status IN ('sold','delivered')
                                           AND updated_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),

        'leads_total'     => carlNum($db, "SELECT COUNT(*) FROM crm_leads WHERE stage NOT IN ('won','lost')"),
        'leads_new_today' => carlNum($db, "SELECT COUNT(*) FROM crm_leads WHERE DATE(created_at) = CURDATE()"),
        'leads_reserved'  => carlNum($db, "SELECT COUNT(*) FROM crm_leads WHERE stage = 'reserved'"),
        'leads_nofollow'  => carlNum($db, "SELECT COUNT(*) FROM crm_leads
                                           WHERE stage NOT IN ('won','lost') AND follow_up_date IS NULL"),
        'leads_overdue'   => carlNum($db, "SELECT COUNT(*) FROM crm_leads
                                           WHERE stage NOT IN ('won','lost') AND follow_up_date < CURDATE()"),
        'deposits_held'   => carlSum($db, "SELECT COALESCE(SUM(deposit_amount),0) FROM crm_leads WHERE stage = 'reserved'"),

        'visitors_today'  => carlNum($db, "SELECT COUNT(*) FROM visitors WHERE DATE(created_at) = CURDATE()"),
        'visitors_onsite' => carlNum($db, "SELECT COUNT(*) FROM visitors
                                           WHERE checked_out_at IS NULL AND DATE(created_at) = CURDATE()"),

        'jobs_open'       => carlNum($db, "SELECT COUNT(*) FROM workshop_jobs WHERE status NOT IN ('completed','cancelled')"),
        'jobs_today'      => carlNum($db, "SELECT COUNT(*) FROM workshop_jobs WHERE DATE(created_at) = CURDATE()"),

        'bookings_today'  => carlNum($db, "SELECT COUNT(*) FROM service_bookings WHERE DATE(booking_date) = CURDATE()"),

        'paid_month'      => carlSum($db, "SELECT COALESCE(SUM(amount),0) FROM payments
                                           WHERE payment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
        'paid_today'      => carlSum($db, "SELECT COALESCE(SUM(amount),0) FROM payments
                                           WHERE DATE(payment_date) = CURDATE()"),
        'invoices_unpaid' => carlNum($db, "SELECT COUNT(*) FROM invoices WHERE status <> 'paid'"),
    ];
}

} // function_exists('carlMigrate')
