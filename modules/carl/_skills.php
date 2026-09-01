<?php
/**
 * Carl — skill handlers.
 *
 * One function per skill. Each returns:
 *
 *   say   what Carl speaks aloud — plain prose, no markup, no figures the eye
 *         cannot follow. "Eleven vehicles available" reads well; a table does not.
 *   html  the same answer for the eye, where a table or a set of tiles carries
 *         far more than a sentence can.
 *   done  false while a multi-step task is still gathering what it needs.
 *
 * Speech and screen say the same thing at different resolutions. They must never
 * disagree — if a figure changes, it changes in one query above them both.
 */

if (!function_exists('carlRun')) {

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_llm.php';
require_once __DIR__ . '/_ai.php';
require_once __DIR__ . '/_tasks.php';

/** Dispatches to a handler, refusing anything this user may not see. */
function carlRun(PDO $db, array $user, string $skill, string $utterance): array
{
    $all = carlSkills();
    if (!isset($all[$skill])) return carlSkillUnknown($user);

    $mod = $all[$skill]['module'];
    if ($mod !== null && !canAccess($mod)) {
        return ['skill' => 'denied', 'done' => true,
                'say'   => 'I am not able to look at that for your account. '
                         . 'A manager can open it for you if you need it.',
                'html'  => ''];
    }

    $fn = 'carlSkill' . str_replace(' ', '', ucwords(str_replace('_', ' ', $skill)));
    if (!function_exists($fn)) return carlSkillUnknown($user);
    return $fn($db, $user, $utterance);
}

// ── Presentation helpers ─────────────────────────────────────────────────────

/** A row of figures. Numbers belong on screen; prose belongs in speech. */
function carlTiles(array $tiles): string
{
    $h = '<div class="carl-tiles">';
    foreach ($tiles as [$label, $value, $tone]) {
        $h .= '<div class="carl-tile' . ($tone ? ' carl-' . $tone : '') . '">'
            . '<div class="v">' . e((string)$value) . '</div>'
            . '<div class="k">' . e($label) . '</div></div>';
    }
    return $h . '</div>';
}

/** A link Carl offers as the next step, rather than describing where to click. */
function carlLink(string $href, string $label, string $icon = 'fa-arrow-right'): string
{
    return '<a class="carl-act" href="' . e($href) . '"><i class="fa ' . e($icon) . '"></i>'
         . e($label) . '</a>';
}

/**
 * Suggested follow-up chips — the questions someone would naturally ask next,
 * pre-typed so they can be tapped without typing.
 *
 * @param  string[] $prompts  Short phrases — each becomes a clickable chip.
 */
function carlChips(array $prompts): string
{
    if (!$prompts) return '';
    $h = '<div class="carl-chips carl-follow-chips">';
    foreach ($prompts as $p) {
        $h .= '<button type="button" class="carl-chip" data-ask="' . e($p) . '">' . e($p) . '</button>';
    }
    return $h . '</div>';
}

/**
 * When no skill matches, try freeform LLM if available, otherwise return the
 * static fallback so offline mode is indistinguishable from a graceful miss.
 *
 * @param  array    $user      Auth user row
 * @param  PDO|null $db        If provided, passes live figures to the LLM
 * @param  string   $utterance The original user message (must be passed explicitly)
 * @param  array    $history   Recent conversation turns for context
 */
function carlSkillUnknown(array $user, ?PDO $db = null, string $utterance = '', array $history = []): array
{
    // Check if the utterance is a simple greeting or smalltalk.
    $t = strtolower(trim($utterance));
    if (preg_match('/\b(hallo|hello|hi|hey|habari|jambo|sasa|mambo|sup|good morning|good afternoon|good evening|how are you|who are you)\b/i', $t)) {
        return carlSkillChitchat($db ?: getDB(), $user, $utterance);
    }

    if ($db !== null && $utterance !== '' && carlLegacyFreeform()) {
        $figures = carlFigures($db);
        $res     = carlLlmFreeform($utterance, $figures, $user, $history);
        if (!empty($res['say'])) return $res;
    }

    return ['skill' => 'unknown', 'done' => true,
            'say'   => 'I\'m not sure how to answer that from the data I have. '
                     . 'Try asking for a briefing, the sales pipeline, stock, visitors, or revenue — '
                     . 'or say "help" to see everything I can do.',
            'html'  => carlChips(['Today\'s briefing', 'Vehicles in stock', 'Sales pipeline', 'What needs attention?'])];
}


// ── Skills ───────────────────────────────────────────────────────────────────

function carlSkillChitchat(PDO $db, array $user, string $u): array
{
    $name      = carlFirstName((string)$user['name']);
    $partOfDay = carlPartOfDay($db);
    $t         = strtolower(trim($u));

    // If LLM is available, let Claude generate a warm, natural response.
    if (carlLegacyFreeform()) {
        $figures = carlFigures($db);
        $res     = carlLlmFreeform($u, $figures, $user);
        if (!empty($res['say'])) return $res;
    }

    if (preg_match('/(thank|asante)/i', $t)) {
        $say = "You're very welcome, $name! Let me know if you need anything else.";
    } elseif (preg_match('/(bye|goodbye|see you)/i', $t)) {
        $say = "Goodbye, $name! Have a wonderful $partOfDay.";
    } elseif (preg_match('/(who are you|what is your name)/i', $t)) {
        $say = "I am " . CARL_NAME . ", your AI assistant for Mascardi Luxury Cars. I can provide briefings, track leads, check stock, report revenue, and update your records.";
    } elseif (preg_match('/(how are you)/i', $t)) {
        $say = "I'm doing great, thank you $name! How can I assist you at Mascardi Luxury Cars today?";
    } else {
        $say = "Hello $name! Good $partOfDay. I'm " . CARL_NAME . ", your assistant at Mascardi Luxury Cars. What can I do for you today?";
    }

    $h = carlChips(['Today\'s briefing', 'Vehicles in stock', 'Sales pipeline', 'What needs attention?']);
    return ['skill' => 'chitchat', 'done' => true, 'say' => $say, 'html' => $h];
}

/**
 * Asked to remove something.
 *
 * Named as a skill so the refusal is a deliberate answer rather than a failure to
 * understand — being told plainly that she will not is far better than being told
 * she did not follow the question.
 */
function carlSkillNoDelete(PDO $db, array $user, string $u): array
{
    $what = 'that';
    if (preg_match('/\b(lead|client|customer|car|vehicle|invoice|quotation|receipt|'
                 . 'booking|job|user|record|reservation|payment)s?\b/i', $u, $m)) {
        $what = 'the ' . strtolower($m[1]);
    }
    return carlRefuseDeletion($what);
}

function carlSkillHelp(PDO $db, array $user, string $u): array
{
    $mine = carlSkillsFor();
    unset($mine['help']);
    // Small talk works but is not something to advertise as a capability.
    $mine = array_filter($mine, fn($s) => empty($s['hidden']));
    $h = '<p class="carl-p">Here is what I can do for you right now.</p><div class="carl-chips">';
    foreach ($mine as $s) {
        $h .= '<button type="button" class="carl-chip" data-ask="' . e($s['patterns'][0]) . '">'
            . e($s['label']) . '</button>';
    }
    $h .= '</div><p class="carl-note">Tap one, type a question, or press the microphone and just ask.</p>';

    // Spoken from the same filtered list as the chips. A fixed sentence here
    // promised a mechanic reports on the pipeline and takings, which Carl would
    // then refuse — offering something and declining it is worse than not
    // offering it.
    $labels = array_values(array_map(fn($s) => strtolower($s['label']), $mine));
    $say = $labels
        ? 'I can help you with ' . carlJoin($labels) . '. What would you like?'
        : 'I can give you a summary of your day and open pages for you. What would you like?';

    return ['skill' => 'help', 'done' => true, 'say' => $say, 'html' => $h];
}

function carlSkillBriefing(PDO $db, array $user, string $u): array
{
    $f    = carlFigures($db);
    $name = carlFirstName((string)$user['name']);

    // Only report what this person is allowed to see, so the briefing is honest
    // rather than a list of things they will be refused if they follow up.
    $tiles = []; $lines = [];
    if (canAccess('cars')) {
        $tiles[] = ['Available', $f['stock_available'], ''];
        $lines[] = carlPlural($f['stock_available'], 'vehicle') . ' available';
    }
    if (canAccess('crm')) {
        $tiles[] = ['Open leads', $f['leads_total'], ''];
        $tiles[] = ['Reserved', $f['leads_reserved'], $f['leads_reserved'] ? 'good' : ''];
        $lines[] = carlPlural($f['leads_total'], 'open lead');
        if ($f['leads_new_today']) $lines[] = carlPlural($f['leads_new_today'], 'new one') . ' today';
    }
    if (canAccess('visitors')) {
        $tiles[] = ['Visitors today', $f['visitors_today'], ''];
        if ($f['visitors_onsite']) $lines[] = carlPlural($f['visitors_onsite'], 'visitor') . ' still on site';
    }
    if (canAccess('jobs') && $f['jobs_open']) {
        $tiles[] = ['Open jobs', $f['jobs_open'], ''];
        $lines[] = carlPlural($f['jobs_open'], 'job') . ' open in the workshop';
    }
    if (canAccess('payments')) {
        $tiles[] = ['Taken this month', carlMoney($f['paid_month']), 'good'];
    }

    $say = 'Good ' . carlPartOfDay($db) . ', ' . $name . '. ';
    $say .= $lines
        ? 'Right now we have ' . carlJoin($lines) . '.'
        : 'There is nothing outstanding on your desk at the moment.';

    // The briefing is only useful if it points somewhere, so the sharpest single
    // concern is appended rather than left for a second question.
    $flag = carlTopConcern($f);
    if ($flag) $say .= ' ' . $flag['say'];

    $h = $tiles ? carlTiles($tiles) : '';
    if ($flag) $h .= '<div class="carl-flag"><i class="fa fa-circle-exclamation"></i>'
                   . e($flag['say']) . '</div>' . $flag['html'];

    $chips = [];
    if (canAccess('crm'))      $chips[] = 'Show me overdue leads';
    if (canAccess('payments')) $chips[] = 'How much have we taken this month?';
    if (canAccess('crm'))      $chips[] = 'What needs attention?';
    $h .= carlChips($chips);

    return ['skill' => 'briefing', 'done' => true, 'say' => $say, 'html' => $h];
}

/** Natural list punctuation — "a, b and c" rather than "a, b, c". */
function carlJoin(array $items): string
{
    if (count($items) <= 1) return $items[0] ?? '';
    $last = array_pop($items);
    return implode(', ', $items) . ' and ' . $last;
}

/**
 * The single thing most worth saying, chosen by cost of leaving it.
 *
 * Deliberately one item. A list of six concerns is a list nobody acts on, and
 * Carl speaking it aloud would take longer than reading the dashboard.
 */
function carlTopConcern(array $f): ?array
{
    if (canAccess('crm') && $f['leads_overdue'] > 0) {
        return ['say'  => $f['leads_overdue'] . ' ' . ($f['leads_overdue'] === 1 ? 'lead is' : 'leads are')
                        . ' past their follow-up date.',
                'html' => carlLink(BASE_URL . '/modules/crm/leads.php', 'Open the leads that are overdue')];
    }
    if (canAccess('crm') && $f['leads_nofollow'] > 0) {
        return ['say'  => $f['leads_nofollow'] . ' open ' . ($f['leads_nofollow'] === 1 ? 'lead has' : 'leads have')
                        . ' no follow-up date set, which is how they go cold.',
                'html' => carlLink(BASE_URL . '/modules/crm/leads.php', 'Set follow-up dates')];
    }
    if (canAccess('visitors') && $f['visitors_onsite'] > 0) {
        return ['say'  => $f['visitors_onsite'] . ' ' . ($f['visitors_onsite'] === 1 ? 'visitor is' : 'visitors are')
                        . ' still signed in at reception.',
                'html' => carlLink(BASE_URL . '/modules/visitors/index.php', 'See who is on site')];
    }
    if (canAccess('payments') && $f['invoices_unpaid'] > 0) {
        return ['say'  => $f['invoices_unpaid'] . ' ' . ($f['invoices_unpaid'] === 1 ? 'invoice is' : 'invoices are')
                        . ' still unpaid.',
                'html' => carlLink(BASE_URL . '/modules/invoices/index.php', 'Open unpaid invoices')];
    }
    return null;
}

function carlSkillStock(PDO $db, array $user, string $u): array
{
    $f = carlFigures($db);
    $say = 'There are ' . carlPlural($f['stock_available'], 'vehicle') . ' available to sell, out of '
         . $f['stock_total'] . ' on the books.';
    if ($f['stock_reserved']) $say .= ' ' . $f['stock_reserved'] . ($f['stock_reserved']===1?' is reserved.':' are reserved.');
    if ($f['stock_transit'])  $say .= ' ' . $f['stock_transit'] . ($f['stock_transit']===1?' is':' are') . ' still in shipment.';
    if ($f['sold_month'])     $say .= ' ' . $f['sold_month'] . ($f['sold_month']===1?' has':' have') . ' sold this month.';

    $h = carlTiles([
        ['Available', $f['stock_available'], 'good'],
        ['Reserved',  $f['stock_reserved'],  ''],
        ['In shipment', $f['stock_transit'], ''],
        ['Sold this month', $f['sold_month'], ''],
    ]);

    // The makes actually on the yard — the follow-up question, answered first.
    try {
        $rows = $db->query("SELECT make, COUNT(*) c FROM cars
                            WHERE car_type IN ('inventory','sale_on_behalf')
                              AND (status IS NULL OR status NOT IN ('sold','delivered','in_transit'))
                              AND make IS NOT NULL AND make <> ''
                            GROUP BY make ORDER BY c DESC, make LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $h .= '<div class="carl-list">';
            foreach ($rows as $r) {
                $h .= '<div class="carl-row"><span>' . e($r['make']) . '</span><b>' . (int)$r['c'] . '</b></div>';
            }
            $h .= '</div>';
        }
    } catch (\Throwable $_) {}

    $h .= carlLink(BASE_URL . '/modules/cars/index.php', 'Open the vehicle list', 'fa-car');
    $h .= carlChips(['What sold this month?', 'Show me the leads', 'How much have we taken?']);
    return ['skill' => 'stock', 'done' => true, 'say' => $say, 'html' => $h];
}

/**
 * The pipeline — answered at the level the question was asked.
 *
 * A bare "how are the leads" gets the overview. Anything narrower ("which are
 * overdue", "who has no follow-up date", "leads nobody owns") gets the actual
 * records: names, numbers, whose they are, and how late. A count alone tells
 * somebody there is a problem without telling them whose it is.
 */
function carlSkillLeads(PDO $db, array $user, string $u): array
{
    require_once __DIR__ . '/_detail.php';
    $f    = carlFigures($db);
    $q    = carlQualifier($u);

    // A narrowing word turns this into a "which ones" answer.
    $named = [
        'overdue'    => 'leads past their follow-up date',
        'nofollow'   => 'leads with no follow-up date set',
        'unassigned' => 'leads with nobody assigned',
        'today'      => 'leads that came in today',
        'new'        => 'leads at the new stage',
        'week'       => 'leads from the past week',
        'month'      => 'leads this month',
        'reserved'   => 'reserved leads',
    ];

    if ($q !== null && isset($named[$q])) {
        $rows  = carlLeadRows($db, $q, 8);
        $total = carlLeadCount($db, $q);
        $say   = carlLeadSpeech($rows, $total, $named[$q]);

        $h = carlTiles([
            [ucfirst($named[$q]), $total, $total ? ($q === 'overdue' ? 'bad' : 'warn') : 'good'],
            ['Open leads in total', $f['leads_total'], ''],
        ]);
        $h .= carlLeadCards($rows);
        if ($total > count($rows)) {
            $h .= '<p class="carl-note">Showing the first ' . count($rows) . ' of ' . $total . '.</p>';
        }
        $h .= carlLink(BASE_URL . '/modules/crm/leads.php', 'Open the pipeline', 'fa-filter');
        $h .= carlChips(['Set a follow-up', 'What needs attention?', 'Show overdue leads']);
        return ['skill' => 'leads', 'done' => true, 'say' => $say, 'html' => $h];
    }

    // No qualifier — the overview, but still with the leads that need work,
    // because that is what the next question would have been anyway.
    $say = 'You have ' . carlPlural($f['leads_total'], 'open lead');
    $say .= $f['leads_new_today'] ? ', ' . $f['leads_new_today'] . ' of them new today.' : '.';
    if ($f['leads_overdue'])  $say .= ' ' . carlPlural($f['leads_overdue'], 'is', 'are') . ' past the follow-up date.';
    elseif ($f['leads_nofollow']) $say .= ' ' . carlPlural($f['leads_nofollow'], 'has', 'have') . ' no follow-up date set.';

    $h = carlTiles([
        ['Open',        $f['leads_total'], ''],
        ['New today',   $f['leads_new_today'], $f['leads_new_today'] ? 'good' : ''],
        ['Overdue',     $f['leads_overdue'], $f['leads_overdue'] ? 'bad' : ''],
        ['No follow-up', $f['leads_nofollow'], $f['leads_nofollow'] ? 'warn' : ''],
    ]);

    $attention = carlLeadRows($db, $f['leads_overdue'] ? 'overdue' : ($f['leads_nofollow'] ? 'nofollow' : null), 5);
    if ($attention) {
        $h .= '<p class="carl-p" style="margin-top:6px"><b>'
            . ($f['leads_overdue'] ? 'Past their follow-up date' : 'Waiting on a follow-up date')
            . '</b></p>' . carlLeadCards($attention);
    }
    $h .= carlLink(BASE_URL . '/modules/crm/leads.php', 'Open the pipeline', 'fa-filter');
    $h .= carlChips(['Which are overdue?', 'Who has no follow-up date?', 'Show this week\'s leads']);
    return ['skill' => 'leads', 'done' => true, 'say' => $say, 'html' => $h];
}

function carlSkillReservations(PDO $db, array $user, string $u): array
{
    $f = carlFigures($db);
    $say = $f['leads_reserved'] === 0
        ? 'There are no active reservations at the moment.'
        : 'There are ' . $f['leads_reserved'] . ' active reservations, holding '
          . carlMoney($f['deposits_held']) . ' in deposits.';

    $h = carlTiles([
        ['Reservations', $f['leads_reserved'], ''],
        ['Deposits held', carlMoney($f['deposits_held']), 'good'],
        ['Cars reserved', $f['stock_reserved'], ''],
    ]);
    $h .= carlLink(BASE_URL . '/modules/reservations/index.php', 'Open reservations', 'fa-bookmark');
    $h .= carlChips(['Show all leads', 'How much have we taken?']);
    return ['skill' => 'reservations', 'done' => true, 'say' => $say, 'html' => $h];
}

function carlSkillVisitors(PDO $db, array $user, string $u): array
{
    $f = carlFigures($db);
    $say = $f['visitors_today'] === 0
        ? 'Nobody has signed the visitors book yet today.'
        : $f['visitors_today'] . ' ' . ($f['visitors_today'] === 1 ? 'person has' : 'people have')
          . ' signed in today'
          . ($f['visitors_onsite'] ? ', and ' . $f['visitors_onsite'] . ' are still on site.' : '.');

    $h = carlTiles([
        ['Today',    $f['visitors_today'], ''],
        ['On site now', $f['visitors_onsite'], $f['visitors_onsite'] ? 'good' : ''],
    ]);
    $h .= carlLink(BASE_URL . '/modules/visitors/index.php', 'Open the visitors book', 'fa-book-open-reader');
    $h .= carlChips(['Add a lead from a visitor', 'How many visitors this week?']);
    return ['skill' => 'visitors', 'done' => true, 'say' => $say, 'html' => $h];
}

function carlSkillWorkshop(PDO $db, array $user, string $u): array
{
    $f = carlFigures($db);
    $say = $f['jobs_open'] === 0
        ? 'The workshop has no open job cards.'
        : 'There are ' . carlPlural($f['jobs_open'], 'open job card')
          . ($f['jobs_today'] ? ', ' . $f['jobs_today'] . ' opened today.' : '.');
    if ($f['bookings_today']) $say .= ' ' . carlPlural($f['bookings_today'], 'service booking is', 'service bookings are') . ' due today.';

    $h = carlTiles([
        ['Open jobs',      $f['jobs_open'], $f['jobs_open'] ? 'warn' : 'good'],
        ['Opened today',   $f['jobs_today'], ''],
        ['Bookings today', $f['bookings_today'], ''],
    ]);
    $h .= carlLink(BASE_URL . '/modules/jobs/index.php', 'Open job cards', 'fa-toolbox');
    $h .= carlChips(['What needs attention?', 'Show service bookings']);
    return ['skill' => 'workshop', 'done' => true, 'say' => $say, 'html' => $h];
}

function carlSkillMoney(PDO $db, array $user, string $u): array
{
    $f = carlFigures($db);
    $say = 'We have taken ' . carlMoney($f['paid_month']) . ' this month';
    $say .= $f['paid_today'] ? ', ' . carlMoney($f['paid_today']) . ' of it today.' : '.';
    if ($f['invoices_unpaid']) {
        $say .= ' ' . $f['invoices_unpaid'] . ' ' . ($f['invoices_unpaid'] === 1 ? 'invoice is' : 'invoices are')
              . ' still unpaid.';
    }

    $h = carlTiles([
        ['This month', carlMoney($f['paid_month']), 'good'],
        ['Today',      carlMoney($f['paid_today']), ''],
        ['Unpaid invoices', $f['invoices_unpaid'], $f['invoices_unpaid'] ? 'warn' : 'good'],
        ['Deposits held',   carlMoney($f['deposits_held']), ''],
    ]);
    $h .= carlLink(BASE_URL . '/modules/payments/index.php', 'Open payments', 'fa-money-bill-transfer');
    $h .= carlChips(['How does this month compare to last month?', 'Show overdue invoices']);
    return ['skill' => 'money', 'done' => true, 'say' => $say, 'html' => $h];
}

/**
 * What needs attention.
 *
 * Ordered by what it costs to leave the thing undone, not by how big the number
 * is — a single overdue lead outranks fifty available cars.
 */
function carlSkillAdvice(PDO $db, array $user, string $u): array
{
    $f = carlFigures($db);
    $items = [];

    if (canAccess('crm') && $f['leads_overdue']) {
        $items[] = [$f['leads_overdue'] . ' leads past their follow-up date',
            'These are the ones most likely to have gone to a competitor already. Work them first.',
            BASE_URL . '/modules/crm/leads.php', 'bad'];
    }
    if (canAccess('visitors') && $f['visitors_onsite']) {
        $items[] = [$f['visitors_onsite'] . ' visitors still on site',
            'Either they are being helped, or somebody forgot to sign them out. Both are worth a look.',
            BASE_URL . '/modules/visitors/index.php', 'warn'];
    }
    if (canAccess('crm') && $f['leads_nofollow']) {
        $items[] = [$f['leads_nofollow'] . ' leads with no follow-up date',
            'A lead with no date is a lead nobody is chasing. Set one on each.',
            BASE_URL . '/modules/crm/leads.php', 'warn'];
    }
    if (canAccess('payments') && $f['invoices_unpaid']) {
        $items[] = [$f['invoices_unpaid'] . ' unpaid invoices',
            'Worth a call before they age any further.',
            BASE_URL . '/modules/invoices/index.php', 'warn'];
    }
    if (canAccess('cars') && $f['stock_transit']) {
        $items[] = [$f['stock_transit'] . ' vehicles still in shipment',
            'Customers can reserve these before they land — they are stock you can sell today.',
            BASE_URL . '/showroom/in-shipment.php', 'good'];
    }

    if (!$items) {
        return ['skill' => 'advice', 'done' => true,
                'say'   => 'Nothing is pressing. The pipeline is followed up, reception is clear, '
                         . 'and there is nothing overdue. A good position to be in.',
                'html'  => '<p class="carl-p">Nothing needs your attention right now.</p>'];
    }

    $h = '';
    foreach ($items as [$title, $why, $href, $tone]) {
        $h .= '<div class="carl-advice carl-' . $tone . '">'
            . '<div class="t">' . e($title) . '</div>'
            . '<div class="w">' . e($why) . '</div>'
            . carlLink($href, 'Open') . '</div>';
    }

    $say = 'The first thing I would look at is ' . lcfirst($items[0][0]) . '. ' . $items[0][1];
    if (count($items) > 1) {
        $say .= ' There ' . (count($items) === 2 ? 'is one more thing' : 'are ' . (count($items) - 1) . ' more things')
              . ' on the list below.';
    }
    return ['skill' => 'advice', 'done' => true, 'say' => $say, 'html' => $h];
}

function carlSkillNavigate(PDO $db, array $user, string $u): array
{
    // Deliberately a fixed table rather than a search: Carl sending someone to a
    // page they cannot open would be worse than admitting she does not know it.
    $places = [
        'lead'        => ['crm', '/modules/crm/leads.php',            'the leads'],
        'pipeline'    => ['crm', '/modules/crm/index.php',            'the sales pipeline'],
        'reservation' => ['crm', '/modules/reservations/index.php',   'reservations'],
        'visitor'     => ['visitors', '/modules/visitors/index.php',  'the visitors book'],
        'car'         => ['cars', '/modules/cars/index.php',          'the vehicle list'],
        'vehicle'     => ['cars', '/modules/cars/index.php',          'the vehicle list'],
        'stock'       => ['cars', '/modules/cars/index.php',          'the vehicle list'],
        'client'      => ['clients', '/modules/clients/index.php',    'clients'],
        'customer'    => ['clients', '/modules/clients/index.php',    'clients'],
        'invoice'     => ['invoices', '/modules/invoices/index.php',  'invoices'],
        'payment'     => ['payments', '/modules/payments/index.php',  'payments'],
        'job'         => ['jobs', '/modules/jobs/index.php',          'job cards'],
        'workshop'    => ['jobs', '/modules/jobs/index.php',          'job cards'],
        'meeting'     => ['meetings', '/modules/meetings/index.php',  'meetings'],
        'report'      => ['reports', '/modules/reports/index.php',    'reports'],
        'chat'        => ['chat', '/modules/chat/index.php',          'team chat'],
    ];
    $t = strtolower($u);
    foreach ($places as $word => [$mod, $path, $label]) {
        if (str_contains($t, $word)) {
            if (!canAccess($mod)) {
                return ['skill' => 'navigate', 'done' => true,
                        'say'   => 'I can see ' . $label . ', but your account does not have access to it.',
                        'html'  => ''];
            }
            return ['skill' => 'navigate', 'done' => true,
                    'say'   => 'Opening ' . $label . ' for you.',
                    'html'  => carlLink(BASE_URL . $path, 'Open ' . $label),
                    'go'    => BASE_URL . $path];
        }
    }
    return ['skill' => 'navigate', 'done' => true,
            'say'   => 'I am not sure which page you mean. Try naming it — leads, stock, visitors, '
                     . 'invoices, payments, job cards or meetings.',
            'html'  => ''];
}

// carlSkillDocument now lives in _tasks.php — it resolves a record and
// produces the real documents for it.


// ── Trends & comparisons ─────────────────────────────────────────────────────

function carlSkillTrends(PDO $db, array $user, string $u): array
{
    $f    = carlFigures($db);
    $prev = $f['prev'];

    // Determine which period the user is asking about.
    $t = strtolower($u);
    $isWeek = str_contains($t, 'week') || str_contains($t, '7 day');

    if ($isWeek) {
        $label     = 'this week vs last week';
        $leadsNow  = $f['leads_new_week']  ?? 0;
        $leadsPrev = $prev['leads_new_week'] ?? 0;
        $revNow    = $f['paid_week']        ?? 0;
        $revPrev   = $prev['paid_week']     ?? 0;
        $visNow    = $f['visitors_week']    ?? 0;
        $visPrev   = $prev['visitors_week'] ?? 0;
    } else {
        $label     = 'this month vs last month';
        $leadsNow  = $f['leads_new_month']  ?? 0;
        $leadsPrev = $prev['leads_new_month'] ?? 0;
        $revNow    = $f['paid_month']         ?? 0;
        $revPrev   = $prev['paid_month']      ?? 0;
        $visNow    = $f['visitors_month']     ?? 0;
        $visPrev   = $prev['visitors_month']  ?? 0;
    }

    // Helper: direction arrow + plain-English delta.
    $delta = function (int|float $now, int|float $prev, bool $money = false) {
        if ($prev == 0) return $now > 0 ? 'up from nothing' : 'flat';
        $pct = round((($now - $prev) / $prev) * 100);
        $fmt = $money ? carlMoney($now) : (int)$now;
        if ($pct > 0)  return "$fmt — up {$pct}%";
        if ($pct < 0)  return "$fmt — down " . abs($pct) . '%';
        return "$fmt — flat";
    };

    $say = 'Comparing ' . $label . '. '
         . 'Leads: ' . $delta($leadsNow, $leadsPrev) . '. '
         . 'Revenue: ' . $delta($revNow, $revPrev, true) . '. '
         . 'Visitors: ' . $delta($visNow, $visPrev) . '.';

    $tone = fn ($now, $prev) => $now >= $prev ? 'good' : 'bad';

    $h = carlTiles([
        ['Leads — now',   $leadsNow,  $tone($leadsNow, $leadsPrev)],
        ['Leads — before', $leadsPrev, ''],
        ['Revenue — now',  carlMoney($revNow),  $tone($revNow, $revPrev)],
        ['Revenue — before', carlMoney($revPrev), ''],
        ['Visitors — now',   $visNow,  $tone($visNow, $visPrev)],
        ['Visitors — before', $visPrev, ''],
    ]);
    $h .= carlChips([
        $isWeek ? 'Compare this month to last month' : 'Compare this week to last week',
        'How much have we taken?',
        'What needs attention?',
    ]);

    return ['skill' => 'trends', 'done' => true, 'say' => $say, 'html' => $h];
}

// ── Lead action skills ────────────────────────────────────────────────────────
//
// These write to the database. Every write goes through a two-step flow:
// Carl reads back what she understood and waits for a yes. Nothing is saved
// on the strength of a single spoken sentence that might have been misheared.

/** Find a lead by a loose name or make — returns the best match row, or null. */
function carlFindLead(PDO $db, string $hint): ?array
{
    $hint = trim($hint);
    if ($hint === '') return null;
    try {
        // Exact name match first, then fuzzy.
        $st = $db->prepare("SELECT id, name, phone, stage, assigned_to, follow_up_date
                            FROM crm_leads
                            WHERE stage NOT IN ('won','lost')
                              AND (name LIKE ? OR interested_in LIKE ? OR phone LIKE ?)
                            ORDER BY updated_at DESC LIMIT 1");
        $like = '%' . $hint . '%';
        $st->execute([$like, $like, $like]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        // Whoever we just found becomes the subject, so the next request can say
        // "him" instead of naming them again.
        if ($row && function_exists('carlContextSet')) {
            $uid = (int)(authUser()['id'] ?? 0);
            if ($uid) carlContextSet($db, $uid, 'lead', (int)$row['id'], (string)$row['name']);
        }
        return $row ?: null;
    } catch (\Throwable $_) { return null; }
}

/** Render a mini lead card for confirmation screens. */
function carlLeadMini(array $lead): string
{
    return '<div class="carl-confirm">'
         . '<div class="r"><span>Name</span><b>' . e($lead['name']) . '</b></div>'
         . '<div class="r"><span>Phone</span><b>' . e($lead['phone'] ?? '—') . '</b></div>'
         . '<div class="r"><span>Stage</span><b>' . e(ucfirst($lead['stage'] ?? '—')) . '</b></div>'
         . '</div>';
}

// ── priority_lead — change a lead\'s stage/priority ─────────────────────────

function carlSkillPriorityLead(PDO $db, array $user, string $u): array
{
    // Detect the requested priority from the utterance.
    $t = strtolower($u);
    if      (str_contains($t, 'hot'))      $newStage = 'hot';
    elseif  (str_contains($t, 'lukewarm') || str_contains($t, 'warm')) $newStage = 'lukewarm';
    elseif  (str_contains($t, 'cold'))     $newStage = 'cold';
    else    $newStage = null;

    carlPendingSet($db, (int)$user['id'], 'priority_lead',
        ['stage' => $newStage], 'lead_name');

    $stageWord = $newStage ? ' as ' . $newStage : '';
    return ['skill' => 'priority_lead', 'done' => false,
            'say'   => 'Which lead would you like to mark' . $stageWord . '? Give me the name or the car they are interested in.',
            'html'  => '<p class="carl-note">Say "cancel" at any point to stop.</p>'];
}

// ── followup_lead — set a follow-up date ─────────────────────────────────────

function carlSkillFollowupLead(PDO $db, array $user, string $u): array
{
    // Try to extract a date from the utterance (tomorrow, Friday, 2025-09-05, etc.).
    $date = carlParseDate($u);
    carlPendingSet($db, (int)$user['id'], 'followup_lead',
        ['date' => $date], 'lead_name');

    return ['skill' => 'followup_lead', 'done' => false,
            'say'   => 'Which lead should I set the follow-up for?',
            'html'  => '<p class="carl-note">Say "cancel" at any point to stop.</p>'];
}

// ── note_lead — add an activity note ─────────────────────────────────────────

function carlSkillNoteLead(PDO $db, array $user, string $u): array
{
    carlPendingSet($db, (int)$user['id'], 'note_lead', [], 'lead_name');
    return ['skill' => 'note_lead', 'done' => false,
            'say'   => 'Which lead should I add the note to?',
            'html'  => '<p class="carl-note">Say "cancel" at any point to stop.</p>'];
}

// ── call_lead — open a tel: link for a lead\'s phone number ──────────────────

function carlSkillCallLead(PDO $db, array $user, string $u): array
{
    // Try to find the lead from the utterance itself.
    // Strip common action words so "call John Kamau" → search for "John Kamau".
    $cleaned = trim(preg_replace('/^(call|ring|phone|dial)\s+/i', '', $u));
    $lead    = carlFindLead($db, $cleaned);

    if (!$lead) {
        return ['skill' => 'call_lead', 'done' => true,
                'say'   => 'I could not find a lead matching that name. Try: "call John Kamau" or check the pipeline.',
                'html'  => carlLink(BASE_URL . '/modules/crm/leads.php', 'Open the pipeline')];
    }

    $phone = preg_replace('/\D/', '', $lead['phone'] ?? '');
    if (!$phone) {
        return ['skill' => 'call_lead', 'done' => true,
                'say'   => $lead['name'] . ' does not have a phone number on their lead.',
                'html'  => carlLink(BASE_URL . '/modules/crm/view_lead.php?id=' . $lead['id'], 'Open the lead to add one')];
    }

    return ['skill' => 'call_lead', 'done' => true,
            'say'   => 'Calling ' . $lead['name'] . ' on ' . $lead['phone'] . '.',
            'html'  => '<a class="carl-act carl-call" href="tel:' . e($phone) . '"><i class="fa fa-phone"></i>Call ' . e($lead['name']) . ' — ' . e($lead['phone']) . '</a>',
            'go'    => 'tel:' . $phone];
}

/**
 * Parse a natural date expression from free text.
 * Returns a Y-m-d string or null.
 */
function carlParseDate(string $text): ?string
{
    $t = strtolower(trim($text));
    $today = new \DateTime('today');

    if (str_contains($t, 'tomorrow')) {
        return (clone $today)->modify('+1 day')->format('Y-m-d');
    }
    if (str_contains($t, 'next week')) {
        return (clone $today)->modify('next monday')->format('Y-m-d');
    }
    // Day names: monday, tuesday, …
    $days = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
    foreach ($days as $day) {
        if (str_contains($t, $day)) {
            return (clone $today)->modify('next ' . $day)->format('Y-m-d');
        }
    }
    // "in X days"
    if (preg_match('/in\s+(\d+)\s+day/i', $text, $m)) {
        return (clone $today)->modify('+' . $m[1] . ' days')->format('Y-m-d');
    }
    // ISO date pattern YYYY-MM-DD
    if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $text, $m)) {
        return $m[1];
    }
    // D/M/YYYY or D-M-YYYY
    if (preg_match('/\b(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})\b/', $text, $m)) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    return null;
}

// ── Continuation handlers for lead actions ───────────────────────────────

function carlContinuePriorityLead(PDO $db, array $user, array $pending, string $reply): array
{
    $uid = (int)$user['id'];
    $got = $pending['collected'];
    $awaiting = $pending['awaiting'];

    if ($awaiting === 'lead_name') {
        // The subject already under discussion, when the reply refers back to it —
        // otherwise "yes" is searched for as though somebody were called that.
        $lead = null;
        if (function_exists('carlMeansTheSame') && carlMeansTheSame($reply)) {
            $ctx = carlContextGet($db, $uid, 'lead');
            if ($ctx) {
                $cq = $db->prepare("SELECT id, name, phone, stage, assigned_to, follow_up_date
                                      FROM crm_leads WHERE id = ?");
                $cq->execute([$ctx['id']]);
                $lead = $cq->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }
        if (!$lead) $lead = carlFindLead($db, $reply);
        if (!$lead) {
            return ['skill' => 'priority_lead', 'done' => false,
                    'say'   => 'I could not find a lead matching "' . $reply . '". Please give me their name or phone number, or say "cancel".',
                    'html'  => ''];
        }
        $got['lead_id']   = $lead['id'];
        $got['lead_name'] = $lead['name'];
        if (empty($got['stage'])) {
            carlPendingSet($db, $uid, 'priority_lead', $got, 'stage');
            return ['skill' => 'priority_lead', 'done' => false,
                    'say'   => 'Got it, ' . $lead['name'] . '. Should I mark them as hot, lukewarm, or cold?',
                    'html'  => carlLeadMini($lead) . '<div class="carl-chips">'
                             . '<button type="button" class="carl-chip" data-ask="hot">Hot</button>'
                             . '<button type="button" class="carl-chip" data-ask="lukewarm">Lukewarm</button>'
                             . '<button type="button" class="carl-chip" data-ask="cold">Cold</button></div>'];
        }
    }

    if ($awaiting === 'stage') {
        $t = strtolower($reply);
        if      (str_contains($t, 'hot'))      $stage = 'hot';
        elseif  (str_contains($t, 'lukewarm') || str_contains($t, 'warm')) $stage = 'lukewarm';
        elseif  (str_contains($t, 'cold'))     $stage = 'cold';
        else {
            return ['skill' => 'priority_lead', 'done' => false,
                    'say'   => 'Please choose hot, lukewarm, or cold.',
                    'html'  => ''];
        }
        $got['stage'] = $stage;
    }

    if ($awaiting === 'confirm' || isset($got['lead_id'], $got['stage'])) {
        if ($awaiting === 'confirm') {
            if (preg_match('/^(yes|yeah|yep|correct|confirm|save|go ahead|do it|ok|okay)\b/i', $reply)) {
                try {
                    $db->prepare("UPDATE crm_leads SET stage = ?, updated_at = NOW() WHERE id = ?")
                       ->execute([$got['stage'], $got['lead_id']]);
                    try { logActivity('update', 'crm_leads', $got['lead_id'], CARL_NAME . ': marked lead stage as ' . $got['stage']); } catch (\Throwable $_) {}
                } catch (\Throwable $e) {
                    error_log('carlContinuePriorityLead: ' . $e->getMessage());
                }
                carlPendingClear($db, $uid);
                return ['skill' => 'priority_lead', 'done' => true,
                        'say'   => 'Done. ' . $got['lead_name'] . ' is now marked as ' . $got['stage'] . '.',
                        'html'  => carlLink(BASE_URL . '/modules/crm/view_lead.php?id=' . $got['lead_id'], 'Open lead')];
            }
            if (preg_match('/^(no|nope|cancel)\b/i', $reply)) {
                carlPendingClear($db, $uid);
                return ['skill' => 'priority_lead', 'done' => true,
                        'say'   => 'Cancelled — no changes were made.', 'html' => ''];
            }
        }

        carlPendingSet($db, $uid, 'priority_lead', $got, 'confirm');
        $say = 'Shall I mark ' . $got['lead_name'] . ' as ' . $got['stage'] . '?';
        $h   = carlLeadMini(['name' => $got['lead_name'], 'stage' => $got['stage']])
             . '<div class="carl-chips"><button type="button" class="carl-chip carl-yes" data-ask="yes">Yes, update</button>'
             . '<button type="button" class="carl-chip" data-ask="no">Cancel</button></div>';
        return ['skill' => 'priority_lead', 'done' => false, 'say' => $say, 'html' => $h];
    }

    return carlSkillUnknown($user);
}

function carlContinueFollowupLead(PDO $db, array $user, array $pending, string $reply): array
{
    $uid = (int)$user['id'];
    $got = $pending['collected'];
    $awaiting = $pending['awaiting'];

    if ($awaiting === 'lead_name') {
        // The subject already under discussion, when the reply refers back to it —
        // otherwise "yes" is searched for as though somebody were called that.
        $lead = null;
        if (function_exists('carlMeansTheSame') && carlMeansTheSame($reply)) {
            $ctx = carlContextGet($db, $uid, 'lead');
            if ($ctx) {
                $cq = $db->prepare("SELECT id, name, phone, stage, assigned_to, follow_up_date
                                      FROM crm_leads WHERE id = ?");
                $cq->execute([$ctx['id']]);
                $lead = $cq->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }
        if (!$lead) $lead = carlFindLead($db, $reply);
        if (!$lead) {
            return ['skill' => 'followup_lead', 'done' => false,
                    'say'   => 'I could not find a lead matching "' . $reply . '". Please give me their name or phone number, or say "cancel".',
                    'html'  => ''];
        }
        $got['lead_id']   = $lead['id'];
        $got['lead_name'] = $lead['name'];
        if (empty($got['date'])) {
            carlPendingSet($db, $uid, 'followup_lead', $got, 'date');
            return ['skill' => 'followup_lead', 'done' => false,
                    'say'   => 'Got it, ' . $lead['name'] . '. When should the follow-up be? (e.g. tomorrow, Friday, or in 3 days)',
                    'html'  => carlLeadMini($lead)];
        }
    }

    if ($awaiting === 'date') {
        $date = carlParseDate($reply);
        if (!$date) {
            return ['skill' => 'followup_lead', 'done' => false,
                    'say'   => 'I didn\'t recognize that date. Try saying "tomorrow", "Friday", or "in 3 days".',
                    'html'  => ''];
        }
        $got['date'] = $date;
    }

    if ($awaiting === 'confirm' || isset($got['lead_id'], $got['date'])) {
        if ($awaiting === 'confirm') {
            if (preg_match('/^(yes|yeah|yep|correct|confirm|save|go ahead|do it|ok|okay)\b/i', $reply)) {
                try {
                    $db->prepare("UPDATE crm_leads SET follow_up_date = ?, updated_at = NOW() WHERE id = ?")
                       ->execute([$got['date'], $got['lead_id']]);
                    try { logActivity('update', 'crm_leads', $got['lead_id'], CARL_NAME . ': set follow-up date to ' . $got['date']); } catch (\Throwable $_) {}
                } catch (\Throwable $e) {
                    error_log('carlContinueFollowupLead: ' . $e->getMessage());
                }
                carlPendingClear($db, $uid);
                $fmtDate = date('d M Y', strtotime($got['date']));
                return ['skill' => 'followup_lead', 'done' => true,
                        'say'   => 'Set! Follow-up for ' . $got['lead_name'] . ' is scheduled for ' . $fmtDate . '.',
                        'html'  => carlLink(BASE_URL . '/modules/crm/view_lead.php?id=' . $got['lead_id'], 'Open lead')];
            }
            if (preg_match('/^(no|nope|cancel)\b/i', $reply)) {
                carlPendingClear($db, $uid);
                return ['skill' => 'followup_lead', 'done' => true,
                        'say'   => 'Cancelled — no date was set.', 'html' => ''];
            }
        }

        carlPendingSet($db, $uid, 'followup_lead', $got, 'confirm');
        $fmtDate = date('d M Y', strtotime($got['date']));
        $say = 'Shall I set the follow-up date for ' . $got['lead_name'] . ' to ' . $fmtDate . '?';
        $h   = carlLeadMini(['name' => $got['lead_name'], 'stage' => 'Follow-up: ' . $fmtDate])
             . '<div class="carl-chips"><button type="button" class="carl-chip carl-yes" data-ask="yes">Set date</button>'
             . '<button type="button" class="carl-chip" data-ask="no">Cancel</button></div>';
        return ['skill' => 'followup_lead', 'done' => false, 'say' => $say, 'html' => $h];
    }

    return carlSkillUnknown($user);
}

function carlContinueNoteLead(PDO $db, array $user, array $pending, string $reply): array
{
    $uid = (int)$user['id'];
    $got = $pending['collected'];
    $awaiting = $pending['awaiting'];

    if ($awaiting === 'lead_name') {
        // The subject already under discussion, when the reply refers back to it —
        // otherwise "yes" is searched for as though somebody were called that.
        $lead = null;
        if (function_exists('carlMeansTheSame') && carlMeansTheSame($reply)) {
            $ctx = carlContextGet($db, $uid, 'lead');
            if ($ctx) {
                $cq = $db->prepare("SELECT id, name, phone, stage, assigned_to, follow_up_date
                                      FROM crm_leads WHERE id = ?");
                $cq->execute([$ctx['id']]);
                $lead = $cq->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }
        if (!$lead) $lead = carlFindLead($db, $reply);
        if (!$lead) {
            return ['skill' => 'note_lead', 'done' => false,
                    'say'   => 'I could not find a lead matching "' . $reply . '". Please give me their name or phone number, or say "cancel".',
                    'html'  => ''];
        }
        $got['lead_id']   = $lead['id'];
        $got['lead_name'] = $lead['name'];
        if (empty($got['note'])) {
            carlPendingSet($db, $uid, 'note_lead', $got, 'note');
            return ['skill' => 'note_lead', 'done' => false,
                    'say'   => 'Got it, ' . $lead['name'] . '. What note would you like me to add?',
                    'html'  => carlLeadMini($lead)];
        }
    }

    if ($awaiting === 'note') {
        if (trim($reply) === '') {
            return ['skill' => 'note_lead', 'done' => false,
                    'say'   => 'Please tell me what note to write.', 'html' => ''];
        }
        $got['note'] = trim($reply);
    }

    if ($awaiting === 'confirm' || isset($got['lead_id'], $got['note'])) {
        if ($awaiting === 'confirm') {
            if (preg_match('/^(yes|yeah|yep|correct|confirm|save|go ahead|do it|ok|okay)\b/i', $reply)) {
                try {
                    $noteEntry = "\n[" . date('d M Y H:i') . ' by ' . $user['name'] . ' via ' . CARL_NAME . ']: ' . $got['note'];
                    $db->prepare("UPDATE crm_leads SET notes = CONCAT(COALESCE(notes, ''), ?), updated_at = NOW() WHERE id = ?")
                       ->execute([$noteEntry, $got['lead_id']]);
                    try {
                        $db->prepare("INSERT INTO crm_activities (lead_id, type, summary, created_by) VALUES (?, 'note', ?, ?)")
                           ->execute([$got['lead_id'], $got['note'], $uid]);
                    } catch (\Throwable $_) {}
                    try { logActivity('update', 'crm_leads', $got['lead_id'], CARL_NAME . ': added note'); } catch (\Throwable $_) {}
                } catch (\Throwable $e) {
                    error_log('carlContinueNoteLead: ' . $e->getMessage());
                }
                carlPendingClear($db, $uid);
                return ['skill' => 'note_lead', 'done' => true,
                        'say'   => 'Note added to ' . $got['lead_name'] . '\'s lead record.',
                        'html'  => carlLink(BASE_URL . '/modules/crm/view_lead.php?id=' . $got['lead_id'], 'Open lead')];
            }
            if (preg_match('/^(no|nope|cancel)\b/i', $reply)) {
                carlPendingClear($db, $uid);
                return ['skill' => 'note_lead', 'done' => true,
                        'say'   => 'Cancelled — no note was added.', 'html' => ''];
            }
        }

        carlPendingSet($db, $uid, 'note_lead', $got, 'confirm');
        $say = 'Shall I add this note to ' . $got['lead_name'] . '? "' . $got['note'] . '"';
        $h   = carlLeadMini(['name' => $got['lead_name'], 'stage' => 'Note: ' . $got['note']])
             . '<div class="carl-chips"><button type="button" class="carl-chip carl-yes" data-ask="yes">Save note</button>'
             . '<button type="button" class="carl-chip" data-ask="no">Cancel</button></div>';
        return ['skill' => 'note_lead', 'done' => false, 'say' => $say, 'html' => $h];
    }

    return carlSkillUnknown($user);
}

// ── Adding a lead ────────────────────────────────────────────────────────────
//
// The only skill that writes. It is deliberately the most cautious thing Carl
// does: she collects the parts one at a time, reads the whole thing back, and
// waits for a yes. Nothing reaches the database on the strength of one sentence
// that might have been misheard by a microphone.

/** What Carl still needs, in the order she asks for it. */
function carlLeadFields(): array
{
    return [
        'name'  => 'What is the customer\'s name?',
        'phone' => 'And their phone number?',
    ];
}

function carlPendingGet(PDO $db, int $userId): ?array
{
    try {
        $st = $db->prepare("SELECT * FROM carl_pending WHERE user_id = ?");
        $st->execute([$userId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $r['collected'] = json_decode((string)$r['collected'], true) ?: [];
        return $r;
    } catch (\Throwable $_) { return null; }
}

function carlPendingSet(PDO $db, int $userId, string $skill, array $collected, ?string $awaiting): void
{
    try {
        $db->prepare("INSERT INTO carl_pending (user_id, skill, collected, awaiting)
                      VALUES (?,?,?,?)
                      ON DUPLICATE KEY UPDATE skill=VALUES(skill),
                          collected=VALUES(collected), awaiting=VALUES(awaiting)")
           ->execute([$userId, $skill, json_encode($collected), $awaiting]);
    } catch (\Throwable $_) {}
}

function carlPendingClear(PDO $db, int $userId): void
{
    try { $db->prepare("DELETE FROM carl_pending WHERE user_id = ?")->execute([$userId]); }
    catch (\Throwable $_) {}
}

function carlSkillAddLead(PDO $db, array $user, string $u): array
{
    carlPendingSet($db, (int)$user['id'], 'add_lead', [], 'name');
    return ['skill' => 'add_lead', 'done' => false,
            'say'   => 'Of course. What is the customer\'s name?',
            'html'  => '<p class="carl-note">Say “cancel” at any point to stop.</p>'];
}

/**
 * Continues a task already under way.
 *
 * Called before intent matching, so a bare "0712345678" is understood as the
 * answer to the question Carl just asked rather than as a new request she cannot
 * parse.
 */
function carlContinue(PDO $db, array $user, array $pending, string $reply): array
{
    $uid = (int)$user['id'];
    $r   = trim($reply);

    if (preg_match('/^(cancel|stop|forget it|never mind|nevermind)$/i', $r)) {
        carlPendingClear($db, $uid);
        return ['skill' => $pending['skill'] ?? 'add_lead', 'done' => true,
                'say'   => 'No problem, I have dropped it.', 'html' => ''];
    }

    // Route to the correct skill's continuation handler.
    $skill = $pending['skill'] ?? 'add_lead';
    if ($skill === 'priority_lead') return carlContinuePriorityLead($db, $user, $pending, $r);
    if ($skill === 'followup_lead') return carlContinueFollowupLead($db, $user, $pending, $r);
    if ($skill === 'note_lead')     return carlContinueNoteLead($db, $user, $pending, $r);
    if ($skill === 'reserve')       return carlContinueReserve($db, $user, $pending, $r);
    if ($skill === 'document')      return carlContinueDocument($db, $user, $pending, $r);
    if ($skill === 'add_deposit')   return carlContinueAddDeposit($db, $user, $pending, $r);
    if ($skill === 'add_car')       return carlContinueAddCar($db, $user, $pending, $r);
    if ($skill !== 'add_lead') { carlPendingClear($db, $uid); return carlSkillUnknown($user); }

    $got = $pending['collected'];

    if ($pending['awaiting'] === 'confirm') {
        if (preg_match('/^(yes|yeah|yep|correct|confirm|save|go ahead|do it|ok|okay)\b/i', $r)) {
            return carlCreateLead($db, $user, $got);
        }
        if (preg_match('/^(no|nope|wrong|cancel)\b/i', $r)) {
            carlPendingClear($db, $uid);
            return ['skill' => 'add_lead', 'done' => true,
                    'say'   => 'Dropped — nothing was saved. Ask me again when you are ready.',
                    'html'  => ''];
        }
        return ['skill' => 'add_lead', 'done' => false,
                'say'   => 'Shall I save it? Please say yes or no.', 'html' => ''];
    }

    $field = (string)$pending['awaiting'];
    if ($field === 'phone') {
        // A phone number read aloud arrives with spaces and sometimes words.
        $digits = preg_replace('/\D+/', '', $r);
        if (strlen($digits) < 9) {
            return ['skill' => 'add_lead', 'done' => false,
                    'say'   => 'That does not look like a full phone number. Could you give it to me again?',
                    'html'  => ''];
        }
        $got['phone'] = $digits;
    } else {
        if ($r === '') {
            return ['skill' => 'add_lead', 'done' => false,
                    'say'   => carlLeadFields()[$field] ?? 'Sorry, could you say that again?', 'html' => ''];
        }
        $got[$field] = $r;
    }

    // Next missing field, or read it back for confirmation.
    foreach (carlLeadFields() as $k => $question) {
        if (empty($got[$k])) {
            carlPendingSet($db, $uid, 'add_lead', $got, $k);
            return ['skill' => 'add_lead', 'done' => false, 'say' => $question, 'html' => ''];
        }
    }

    carlPendingSet($db, $uid, 'add_lead', $got, 'confirm');
    $say = 'Let me read that back. ' . $got['name'] . ', on ' . $got['phone']
         . '. Shall I save it as a new lead?';
    $h = '<div class="carl-confirm"><div class="r"><span>Name</span><b>' . e($got['name']) . '</b></div>'
       . '<div class="r"><span>Phone</span><b>' . e($got['phone']) . '</b></div></div>'
       . '<div class="carl-chips"><button type="button" class="carl-chip carl-yes" data-ask="yes">Save it</button>'
       . '<button type="button" class="carl-chip" data-ask="no">Cancel</button></div>';
    return ['skill' => 'add_lead', 'done' => false, 'say' => $say, 'html' => $h];
}

function carlCreateLead(PDO $db, array $user, array $got): array
{
    $uid = (int)$user['id'];
    carlPendingClear($db, $uid);

    // Owned by whoever asked when they carry leads themselves; otherwise it goes
    // through the same rotation the walk-in book uses, so it is never ownerless.
    $owner = in_array($user['role'] ?? '', ['customer_relations','sales_person','sales_officer','sales_manager'], true)
           ? $uid : null;
    if (!$owner) {
        try {
            require_once __DIR__ . '/../visitors/_bootstrap.php';
            $owner = visitorNextCrmOfficer($db, null);
        } catch (\Throwable $_) {}
    }

    try {
        $db->prepare("INSERT INTO crm_leads (name, phone, source, stage, assigned_to,
                        notes, follow_up_date, created_at)
                      VALUES (?,?, 'Carl', 'new', ?, ?, CURDATE(), NOW())")
           ->execute([$got['name'], $got['phone'], $owner ?: null,
                      'Added by ' . CARL_NAME . ' for ' . $user['name'] . ' on ' . date('j M Y') . '.']);
        $leadId = (int)$db->lastInsertId();
    } catch (\Throwable $e) {
        error_log('carlCreateLead: ' . $e->getMessage());
        return ['skill' => 'add_lead', 'done' => true,
                'say'   => 'I could not save that — something went wrong at my end. '
                         . 'Nothing was recorded, so please try adding it from the leads page.',
                'html'  => carlLink(BASE_URL . '/modules/crm/leads.php', 'Open leads')];
    }

    $ownerName = '';
    if ($owner) {
        try {
            $s = $db->prepare("SELECT name FROM users WHERE id = ?");
            $s->execute([$owner]); $ownerName = (string)$s->fetchColumn();
        } catch (\Throwable $_) {}
    }
    if ($owner && $owner !== $uid) {
        try {
            require_once __DIR__ . '/../../includes/notifications.php';
            createNotification($owner, 'lead', 'New lead: ' . $got['name'],
                'Added by ' . $user['name'] . ' through ' . CARL_NAME . '. Phone ' . $got['phone'],
                BASE_URL . '/modules/crm/view_lead.php?id=' . $leadId);
        } catch (\Throwable $_) {}
    }
    try { logActivity('create', 'crm_leads', $leadId, CARL_NAME . ': added lead ' . $got['name']); }
    catch (\Throwable $_) {}

    $say = 'Saved. ' . $got['name'] . ' is in the pipeline'
         . ($ownerName ? ', with ' . $ownerName : '')
         . ', and I have set today as the follow-up date.';

    return ['skill' => 'add_lead', 'done' => true, 'say' => $say,
            'html'  => '<div class="carl-ok"><i class="fa fa-check"></i>Lead #' . $leadId . ' created</div>'
                     . carlLink(BASE_URL . '/modules/crm/view_lead.php?id=' . $leadId, 'Open the lead')];
}

// ── The morning greeting ─────────────────────────────────────────────────────

/**
 * Whether Carl should introduce herself, and what she should say.
 *
 * Once per person per day. She greets by first name, says what is waiting, and
 * offers to help — which is the whole point of her being there when someone
 * opens the system rather than after they have gone looking for her.
 */
function carlGreetingFor(PDO $db, array $user): ?array
{
    $uid = (int)$user['id'];
    try {
        $st = $db->prepare("SELECT greeted_on FROM carl_greetings WHERE user_id = ?");
        $st->execute([$uid]);
        $last  = (string)$st->fetchColumn();
        $today = (string)$db->query("SELECT CURDATE()")->fetchColumn();
        if ($last === $today) return null;

        // Claimed before the greeting is built: two tabs opening together must
        // not both decide they are the first of the day.
        $ins = $db->prepare("INSERT INTO carl_greetings (user_id, greeted_on) VALUES (?,?)
                             ON DUPLICATE KEY UPDATE greeted_on = IF(greeted_on <> VALUES(greeted_on),
                                                                     VALUES(greeted_on), greeted_on)");
        $ins->execute([$uid, $today]);
        if ($ins->rowCount() === 0) return null;
    } catch (\Throwable $_) { return null; }

    $name      = carlFirstName((string)$user['name']);
    $f         = carlFigures($db);
    $partOfDay = carlPartOfDay($db);

    // Gather human-readable facts for the LLM (and for the offline template).
    $bits = [];
    if (canAccess('crm') && $f['leads_new_today'])    $bits[] = carlPlural($f['leads_new_today'], 'new lead');
    if (canAccess('visitors') && $f['visitors_today']) $bits[] = carlPlural($f['visitors_today'], 'visitor') . ' so far';
    if (canAccess('jobs') && $f['jobs_open'])          $bits[] = carlPlural($f['jobs_open'], 'open job card');

    $flag = carlTopConcern($f);
    if ($flag) $bits[] = lcfirst(rtrim($flag['say'], '.'));

    // Try the LLM first — it produces a varied, warm greeting each day.
    $say = '';
    if (carlLegacyFreeform()) {
        $say = carlLlmGreeting($name, $partOfDay, $bits);
    }

    // Offline fallback: same deterministic template as before.
    if ($say === '') {
        $say = 'Good ' . $partOfDay . ', ' . $name . '. I am ' . CARL_NAME
             . ', and I am here to help you through the day.';
        if ($bits) $say .= ' You have ' . carlJoin(array_slice($bits, 0, 2)) . '.';
        if ($flag) $say .= ' ' . $flag['say'];
        $say .= ' Ask me for a briefing whenever you are ready, or anything else you need.';
    }

    return ['say' => $say, 'html' => $flag ? $flag['html'] : ''];
}

} // function_exists('carlRun')
