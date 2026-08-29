<?php
/**
 * Carl — detail behind the numbers.
 *
 * A count on its own is rarely the answer. "Four leads are overdue" tells you
 * there is a problem; it does not tell you whose problem it is or what to do
 * next. Everything here answers the question the count provokes — which ones,
 * whose are they, how late, and what should happen now.
 *
 * Qualifiers
 * ----------
 * The same topic is asked about in very different ways. "How many leads have
 * we got", "which leads are overdue", "who has not been followed up", and
 * "leads nobody owns" are four questions about one table, and answering all of
 * them with a total is what makes an assistant feel stupid. carlQualifier()
 * pulls the narrowing word out of the sentence so the skill can answer the
 * question actually asked.
 */

if (!function_exists('carlQualifier')) {

require_once __DIR__ . '/_bootstrap.php';

/**
 * The narrowing intent inside an utterance, if any.
 *
 * Order matters: the more specific readings are tested first, because "leads
 * with no follow-up date" also contains the word "follow up" that a naive
 * overdue test would seize on.
 */
function carlQualifier(string $text): ?string
{
    $t = ' ' . strtolower(preg_replace('/[^a-z0-9\s]/i', ' ', $text)) . ' ';
    $t = preg_replace('/\s+/', ' ', $t);

    $tests = [
        'unassigned' => ['unassigned', 'no owner', 'nobody owns', 'not assigned', 'no one is handling',
                         'nobody is handling', 'without an owner', 'no agent', 'no officer'],
        'nofollow'   => ['no follow up', 'without a follow up', 'no followup', 'never followed',
                         'not been followed', 'no follow-up', 'missing follow up'],
        'overdue'    => ['overdue', 'past their follow up', 'past follow up', 'past the follow up',
                         'past their followup', 'late', 'behind', 'past due', 'chased',
                         'not followed up', 'due already', 'should have been',
                         // How people actually put it, rather than how the column is named.
                         'gone cold', 'going cold', 'cold', 'gone quiet', 'stale', 'neglected',
                         'forgotten', 'slipping', 'ignored', 'not been contacted'],
        'today'      => ['today', 'so far today', 'this morning'],
        'week'       => ['this week', 'past week', 'last 7 days', 'seven days'],
        'month'      => ['this month', 'past month', 'last 30 days'],
        'reserved'   => ['reserved', 'reservation', 'deposit'],
        'new'        => ['new ', 'fresh', 'just came in', 'recent'],
    ];
    foreach ($tests as $key => $words) {
        foreach ($words as $w) if (str_contains($t, ' ' . $w)) return $key;
    }
    return null;
}

/** Was the question a "how many", or a "which ones"? Both get detail; this only changes emphasis. */
function carlWantsCount(string $text): bool
{
    return (bool)preg_match('/\b(how many|count|number of|total)\b/i', $text);
}

// ── Rendering records ────────────────────────────────────────────────────────

/**
 * A record list.
 *
 * Deliberately a table of real rows rather than prose: the eye reads six names
 * and dates far faster than Carl can say them, and speech gets the summary.
 */
function carlRecords(array $rows, array $cols, string $empty = 'Nothing to show.'): string
{
    if (!$rows) return '<p class="carl-note">' . e($empty) . '</p>';
    $h = '<div class="carl-recs">';
    foreach ($rows as $r) {
        $h .= '<div class="carl-rec">';
        $first = true;
        foreach ($cols as $key => $label) {
            $v = $r[$key] ?? '';
            if ($v === '' || $v === null) continue;
            if ($first) {
                $h .= '<div class="carl-rec-t">' . (isset($r['_href'])
                        ? '<a href="' . e($r['_href']) . '">' . e((string)$v) . '</a>'
                        : e((string)$v)) . '</div>';
                $first = false;
            } else {
                $h .= '<div class="carl-rec-f"><span>' . e($label) . '</span>'
                    . '<b class="' . e($r['_tone_' . $key] ?? '') . '">' . e((string)$v) . '</b></div>';
            }
        }
        $h .= '</div>';
    }
    return $h . '</div>';
}

/** "3 days ago" / "in 2 days" — how late, in words people use. */
function carlWhen(?string $date): string
{
    if (!$date) return '';
    $d = strtotime($date);
    if (!$d) return (string)$date;
    // Compared against the database's today, since PHP here runs a different clock.
    static $today = null;
    if ($today === null) {
        try { $today = strtotime((string)getDB()->query("SELECT CURDATE()")->fetchColumn()); }
        catch (\Throwable $_) { $today = strtotime(date('Y-m-d')); }
    }
    $days = (int)round(($d - $today) / 86400);
    if ($days === 0)  return 'today';
    if ($days === 1)  return 'tomorrow';
    if ($days === -1) return 'yesterday';
    if ($days < 0)    return abs($days) . ' days ago';
    return 'in ' . $days . ' days';
}

// ── Leads ────────────────────────────────────────────────────────────────────

/**
 * The leads behind a figure, with everything needed to act on them: who they
 * are, how to reach them, whose they are, and how late.
 */
function carlLeadRows(PDO $db, ?string $qualifier, int $limit = 8): array
{
    $where = ["l.stage NOT IN ('won','lost')"];
    $order = 'l.follow_up_date IS NULL, l.follow_up_date ASC';

    switch ($qualifier) {
        case 'overdue':    $where[] = 'l.follow_up_date < CURDATE()'; break;
        case 'nofollow':   $where[] = 'l.follow_up_date IS NULL'; break;
        case 'unassigned': $where[] = 'l.assigned_to IS NULL'; break;
        case 'today':      $where[] = 'DATE(l.created_at) = CURDATE()';
                           $order   = 'l.created_at DESC'; break;
        case 'new':        $where[] = "l.stage = 'new'"; $order = 'l.created_at DESC'; break;
        case 'week':       $where[] = 'l.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)';
                           $order   = 'l.created_at DESC'; break;
        case 'month':      $where[] = "l.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')";
                           $order   = 'l.created_at DESC'; break;
        case 'reserved':   $where = ["l.stage = 'reserved'"]; $order = 'l.updated_at DESC'; break;
    }

    try {
        $st = $db->prepare("
            SELECT l.id, l.name, l.phone, l.stage, l.follow_up_date, l.created_at,
                   l.deposit_amount,
                   u.name AS officer,
                   TRIM(CONCAT_WS(' ', c.year, c.make, c.model)) AS car,
                   DATEDIFF(CURDATE(), l.follow_up_date) AS days_late
            FROM crm_leads l
            LEFT JOIN users u ON u.id = l.assigned_to
            LEFT JOIN cars  c ON c.id = l.pinned_car_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY {$order}
            LIMIT " . (int)$limit);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $_) { return []; }
}

function carlLeadCount(PDO $db, ?string $qualifier): int
{
    $where = ["stage NOT IN ('won','lost')"];
    switch ($qualifier) {
        case 'overdue':    $where[] = 'follow_up_date < CURDATE()'; break;
        case 'nofollow':   $where[] = 'follow_up_date IS NULL'; break;
        case 'unassigned': $where[] = 'assigned_to IS NULL'; break;
        case 'today':      $where[] = 'DATE(created_at) = CURDATE()'; break;
        case 'new':        $where[] = "stage = 'new'"; break;
        case 'week':       $where[] = 'created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)'; break;
        case 'month':      $where[] = "created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"; break;
        case 'reserved':   $where = ["stage = 'reserved'"]; break;
    }
    return carlNum($db, "SELECT COUNT(*) FROM crm_leads WHERE " . implode(' AND ', $where));
}

/** Renders lead rows into the record list, with the follow-up date called out. */
function carlLeadCards(array $rows): string
{
    $out = [];
    foreach ($rows as $r) {
        $late = $r['follow_up_date'] !== null ? (int)$r['days_late'] : null;
        $out[] = [
            'name'    => $r['name'],
            'phone'   => $r['phone'],
            'officer' => $r['officer'] ?: 'Nobody assigned',
            'follow'  => $r['follow_up_date'] ? carlWhen($r['follow_up_date']) : 'never set',
            'car'     => $r['car'] ?: '',
            'stage'   => ucfirst((string)$r['stage']),
            '_href'   => BASE_URL . '/modules/crm/view_lead.php?id=' . (int)$r['id'],
            '_tone_follow'  => ($late !== null && $late > 0) ? 'carl-t-bad'
                             : ($r['follow_up_date'] === null ? 'carl-t-warn' : ''),
            '_tone_officer' => $r['officer'] ? '' : 'carl-t-warn',
        ];
    }
    return carlRecords($out, [
        'name'    => 'Name',
        'phone'   => 'Phone',
        'officer' => 'Handled by',
        'follow'  => 'Follow-up',
        'car'     => 'Interested in',
        'stage'   => 'Stage',
    ], 'No leads match that.');
}

/** The same rows, said aloud — names, not a table. */
function carlLeadSpeech(array $rows, int $total, string $describe): string
{
    if ($total === 0) return 'There are no ' . $describe . '.';

    $names = array_map(fn($r) => (string)$r['name'], array_slice($rows, 0, 3));
    $say   = $total . ' ' . $describe . '. ';

    if ($names) {
        $say .= (count($names) === 1 ? 'It is ' : 'They include ') . carlJoin($names);
        if ($total > count($names)) $say .= ', and ' . ($total - count($names)) . ' more';
        $say .= '. ';
    }

    // The single most useful extra fact, rather than reading the whole table out.
    $first = $rows[0] ?? null;
    if ($first) {
        if ($first['follow_up_date'] && (int)$first['days_late'] > 0) {
            $say .= $first['name'] . ' is the longest waiting, '
                  . carlPlural((int)$first['days_late'], 'day') . ' past the follow-up date';
            $say .= $first['officer'] ? ', with ' . $first['officer'] . '.' : ', and nobody is assigned.';
        } elseif (!$first['officer']) {
            $say .= 'None of them has an officer assigned yet.';
        }
    }
    return trim($say);
}

} // function_exists('carlQualifier')
