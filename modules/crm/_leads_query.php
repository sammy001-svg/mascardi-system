<?php
/**
 * Which leads a person is looking at — read once, used by the page and the
 * download.
 *
 * The filter boxes used to be read and turned into SQL inline on leads.php,
 * which was fine while the list was the only thing that answered them. It is
 * not any more: there is an Excel export, and an export that disagrees with
 * the screen is only ever discovered in a meeting. So the filters, the scope
 * rules and the ordering live here and both callers ask.
 *
 * It also remembers the filters. Somebody filters to one agent's overdue
 * leads, opens one, comes back, and the list was showing everything again —
 * so they filter again, every time. The set is kept for the session and put
 * back on a bare visit, and only Clear empties it.
 */

if (!function_exists('leadsFilterKeys')) {

/**
 * The filter boxes, by query-string name.
 *
 * Used to tell "the form was submitted" from "somebody arrived at the page",
 * so an empty value has to be distinguishable from an absent one — a stage
 * box put back to All Stages submits stage= and must clear the saved stage,
 * not be ignored as blank.
 */
function leadsFilterKeys(): array
{
    return ['q', 'stage', 'source', 'campaign', 'assigned', 'sort', 'date_from', 'date_to'];
}

/**
 * The stages, with the colour the list badges them in.
 *
 * Here rather than on the page because the export prints the same words, and
 * a download that calls a stage something else than the screen does is the
 * sort of difference nobody notices until two people are reading one figure
 * off two documents.
 */
function leadsStages(): array
{
    return [
        'hot'       => ['Hot',       'danger'],
        'lukewarm'  => ['Lukewarm',  'warning'],
        'cold'      => ['Cold',      'info'],
        'lost'      => ['Lost',      'secondary'],
        'reserved'  => ['Reserved',  'purple'],
        'delivered' => ['Delivered', 'success'],
    ];
}

/** Where a lead came from. */
function leadsSources(): array
{
    return [
        'walk_in'    => 'Walk-in',    'referral'  => 'Referral',
        'facebook'   => 'Facebook',   'instagram' => 'Instagram',
        'website'    => 'Website',    'phone_call' => 'Phone Call',
        'whatsapp'   => 'WhatsApp',   'other'     => 'Other',
    ];
}

/** Who is looking, and what they are allowed to see. */
function leadsScope(array $me, ?int $supLocId = null): array
{
    $role = (string)($me['role'] ?? '');
    return [
        'uid'        => (int)($me['id'] ?? 0),
        'is_agent'   => $role === 'customer_relations',
        'is_super'   => $role === 'supervisor',
        'loc'        => $supLocId,
    ];
}

/** The filters, sanitised, from a query string or a saved set. */
function leadsReadFilters(array $src, array $scope): array
{
    $date = static fn (string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($src[$k] ?? ''))
        ? (string)$src[$k] : '';

    return [
        'q'         => trim((string)($src['q'] ?? '')),
        'stage'     => (string)($src['stage'] ?? ''),
        'source'    => (string)($src['source'] ?? ''),
        'campaign'  => trim((string)($src['campaign'] ?? '')),
        // An agent is pinned to their own leads, so the box is neither shown
        // to them nor read from them.
        'assigned'  => $scope['is_agent'] ? $scope['uid'] : (int)($src['assigned'] ?? 0),
        'sort'      => in_array((string)($src['sort'] ?? ''), ['score_desc', 'score_asc', 'updated', 'overdue'], true)
                        ? (string)$src['sort'] : 'overdue',
        'date_from' => $date('date_from'),
        'date_to'   => $date('date_to'),
    ];
}

/** Is anything actually narrowing the list? Sort is a view, not a filter. */
function leadsFilterActive(array $f, array $scope): bool
{
    return $f['q'] !== '' || $f['stage'] !== '' || $f['source'] !== ''
        || $f['campaign'] !== '' || $f['date_from'] !== '' || $f['date_to'] !== ''
        || (!$scope['is_agent'] && (int)$f['assigned'] > 0);
}

/**
 * The filters to use for this request, remembering them between visits.
 *
 * - ?clear=1 forgets the saved set and shows everything.
 * - A request carrying any filter box is the person using the form: that wins,
 *   and is saved. Carrying a box with nothing in it counts, which is how
 *   putting Stage back to All Stages clears the saved stage.
 * - A request carrying none of them is somebody arriving at the page, maybe
 *   back from a lead they opened: the saved set is put back.
 */
function leadsStickyFilters(array $get, array $scope): array
{
    $slot = 'crm_lead_filters';

    if (!empty($get['clear'])) {
        unset($_SESSION[$slot]);
        return leadsReadFilters([], $scope);
    }

    $submitted = false;
    foreach (leadsFilterKeys() as $k) {
        if (array_key_exists($k, $get)) { $submitted = true; break; }
    }

    if ($submitted) {
        $f = leadsReadFilters($get, $scope);
        // Saved per session, so it is already per person and goes when they
        // log out. Only the keys, so nothing else rides along in the session.
        $_SESSION[$slot] = array_intersect_key($f, array_flip(leadsFilterKeys()));
        return $f;
    }

    $saved = $_SESSION[$slot] ?? null;
    return is_array($saved) ? leadsReadFilters($saved, $scope) : leadsReadFilters([], $scope);
}

/** The query string that reproduces a filter set, for a link that keeps it. */
function leadsFilterQuery(array $f, array $scope): string
{
    $out = [];
    foreach (leadsFilterKeys() as $k) {
        $v = $f[$k] ?? '';
        if ($k === 'assigned') {
            if ($scope['is_agent'] || (int)$v <= 0) continue;
            $out[$k] = (int)$v;
            continue;
        }
        if ($k === 'sort' && $v === 'overdue') continue;     // the default needs no saying
        if ((string)$v !== '') $out[$k] = (string)$v;
    }
    return $out ? '?' . http_build_query($out) : '';
}

/** WHERE and its parameters, scope rules included. */
function leadsWhere(array $f, array $scope): array
{
    $where  = ['1=1'];
    $params = [];

    // A CRM agent is always locked to their own leads.
    if ($scope['is_agent']) {
        $where[]  = 'l.assigned_to = ?';
        $params[] = $scope['uid'];
    }

    // A supervisor sees the leads of the staff at their location, and nothing
    // at all until somebody gives them a location — showing the whole book
    // would be the wrong way to fail.
    if ($scope['is_super']) {
        if ($scope['loc']) {
            $where[]  = 'l.assigned_to IN (SELECT id FROM users WHERE location_id = ?)';
            $params[] = $scope['loc'];
        } else {
            $where[] = '1 = 0';
        }
    }

    if ($f['stage'] !== '')    { $where[] = 'l.stage = ?';    $params[] = $f['stage']; }
    if ($f['source'] !== '')   { $where[] = 'l.source = ?';   $params[] = $f['source']; }
    if ($f['campaign'] !== '') { $where[] = 'l.campaign = ?'; $params[] = $f['campaign']; }
    if (!$scope['is_agent'] && (int)$f['assigned'] > 0) {
        $where[]  = 'l.assigned_to = ?';
        $params[] = (int)$f['assigned'];
    }
    if ($f['date_from'] !== '') { $where[] = 'DATE(l.created_at) >= ?'; $params[] = $f['date_from']; }
    if ($f['date_to'] !== '')   { $where[] = 'DATE(l.created_at) <= ?'; $params[] = $f['date_to']; }
    if ($f['q'] !== '') {
        $where[] = '(l.name LIKE ? OR l.phone LIKE ? OR l.email LIKE ?'
                 . ' OR l.interested_in LIKE ? OR l.campaign LIKE ?)';
        $like = '%' . $f['q'] . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }

    return ['sql' => implode(' AND ', $where), 'params' => $params];
}

/** The ORDER BY for a sort choice. */
function leadsOrder(string $sort): string
{
    return match ($sort) {
        'score_desc' => 'l.lead_score DESC, l.updated_at DESC',
        'score_asc'  => 'l.lead_score ASC,  l.updated_at DESC',
        'updated'    => 'l.updated_at DESC',
        // Overdue follow-ups first, which is what the list is for. A lead that
        // is lost or delivered is not overdue, however old its date.
        default      => "CASE WHEN l.follow_up_date < CURDATE()
                                   AND l.stage NOT IN ('lost','delivered') THEN 0 ELSE 1 END,
                         l.updated_at DESC",
    };
}

/**
 * The leads themselves.
 *
 * $extra is joined on for the export, which wants the car and the client that
 * the list does not show.
 */
function leadsRows(PDO $db, array $f, array $scope, bool $withDeal = false): array
{
    $w = leadsWhere($f, $scope);

    $cols = 'l.*, u.name AS assigned_name';
    $join = 'LEFT JOIN users u ON u.id = l.assigned_to';
    if ($withDeal) {
        $cols .= ', c.make, c.model, c.year, c.registration_number, cl.name AS client_name';
        $join .= ' LEFT JOIN cars    c  ON c.id  = l.pinned_car_id'
               . ' LEFT JOIN clients cl ON cl.id = l.client_id';
    }

    try {
        $st = $db->prepare("SELECT $cols FROM crm_leads l $join
                             WHERE {$w['sql']}
                          ORDER BY " . leadsOrder((string)$f['sort']));
        $st->execute($w['params']);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('leadsRows: ' . $e->getMessage());
        return [];
    }
}

} // function_exists('leadsFilterKeys')
