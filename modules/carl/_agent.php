<?php
/**
 * Carl — the conversational layer.
 *
 * Before this, Claude was only ever used as a classifier: it read the question,
 * picked one of twenty skill keys, and a fixed PHP handler wrote the sentence.
 * That is why Carl could recite figures but could not hold a conversation — the
 * model never got to speak, so every reply was one of twenty pre-written shapes
 * and anything outside them came back as "I did not catch that".
 *
 * Here Claude does the talking and calls tools when it needs facts. The figures
 * still come from the database and only from the database — the model is told,
 * firmly, that it may not invent one — so answers stay as trustworthy as the
 * old canned ones while sounding like a colleague rather than a menu.
 *
 * Writes are deliberately NOT tools. A reservation or a new lead is started by
 * handing the conversation to the existing step-by-step flow, which reads the
 * details back and waits for a yes. Letting a model write to the CRM directly
 * from one sentence is exactly the kind of convenience that produces records
 * nobody meant to create.
 */

require_once __DIR__ . '/_ai.php';

// The lead detail helpers are loaded lazily inside carlSkillLeads(); the tools
// below reach for them directly, so they have to be here before any call.
require_once __DIR__ . '/_detail.php';

// ── What Carl can look up ────────────────────────────────────────────────────

/**
 * Tool definitions, filtered to what this account may actually see.
 *
 * Gated here as well as in the handlers: a tool the user cannot use should not
 * be described to the model at all, or it will offer things and then fail.
 */
function carlTools(array $user): array
{
    $t = [];

    $t[] = [
        'name' => 'business_snapshot',
        'description' => 'Current figures across the whole business: stock, leads, reservations, '
                       . 'visitors, workshop, revenue, and last period comparisons. Call this for '
                       . 'any general question about how things are going, or when you need '
                       . 'context before answering.',
        'input_schema' => ['type' => 'object', 'properties' => (object)[]],
    ];

    if (canAccess('crm')) {
        $t[] = [
            'name' => 'list_leads',
            'description' => 'Real leads from the pipeline with names, phone numbers, stage, '
                           . 'follow-up dates and the officer assigned. Use whenever the question '
                           . 'is about which or who, not just how many.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'filter' => [
                        'type' => 'string',
                        'enum' => ['all', 'overdue', 'unassigned', 'nofollow', 'new', 'reserved', 'today', 'week', 'month'],
                        // Spelled out in the words people actually use. Asked "which leads
                        // have gone quiet", the model reached for nofollow — a rare
                        // bookkeeping gap — when overdue was plainly meant.
                        'description' =>
                            'overdue: past their follow-up date. These are the ones people mean by '
                          . '"gone quiet", "gone cold", "neglected", "slipping", "need chasing" '
                          . 'or "should have been called by now". This is almost always the right '
                          . 'filter when asked which leads need attention. '
                          . 'nofollow: no follow-up date was ever set — a bookkeeping gap, not a '
                          . 'neglected customer. Rare; only use it if asked specifically about '
                          . 'missing or unset follow-up dates. '
                          . 'unassigned: nobody owns the lead. '
                          . 'new: recently created. reserved: has paid a deposit. '
                          . 'today/week/month: created in that period. all: everything open.',
                    ],
                    'limit' => ['type' => 'integer', 'description' => 'How many to return, 1 to 25. Default 8.'],
                ],
                'required' => ['filter'],
            ],
        ];
        $t[] = [
            'name' => 'find_customer',
            'description' => 'Look up one customer by name or phone number. Returns their stage, '
                           . 'deposit, vehicle of interest, follow-up date, and which documents '
                           . 'can be printed for them right now.',
            'input_schema' => [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string', 'description' => 'Name or phone number.']],
                'required' => ['name'],
            ],
        ];
        $t[] = [
            'name' => 'delivery_pipeline',
            'description' => 'Vehicles sold or reserved but not yet handed over, and which step of '
                           . 'the delivery protocol each one is waiting on.',
            'input_schema' => ['type' => 'object', 'properties' => (object)[]],
        ];
    }

    if (canAccess('cars')) {
        $t[] = [
            'name' => 'list_stock',
            'description' => 'Vehicles on the yard with make, model, year, registration, price and '
                           . 'status. Use for questions about what is available or to find a '
                           . 'particular car.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'search' => ['type' => 'string', 'description' => 'Optional make, model or registration to filter by.'],
                    'limit'  => ['type' => 'integer', 'description' => 'How many, 1 to 25. Default 10.'],
                ],
            ],
        ];
    }

    if (canAccess('jobs')) {
        $t[] = [
            'name' => 'workshop_jobs',
            'description' => 'Job cards in the workshop — which vehicle, which mechanic, priority, '
                           . 'how long it has been open, and what the work is. Use for anything '
                           . 'about the workshop, repairs, mechanics, or what is holding a car up.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'filter' => [
                        'type' => 'string',
                        'enum' => ['open', 'unassigned', 'stalled', 'urgent', 'completed', 'all'],
                        'description' => 'open = not finished. unassigned = no mechanic on it. '
                                       . 'stalled = open more than 7 days. urgent = high priority.',
                    ],
                    'limit' => ['type' => 'integer', 'description' => 'How many, 1 to 25. Default 10.'],
                ],
                'required' => ['filter'],
            ],
        ];
        $t[] = [
            'name' => 'mechanic_workload',
            'description' => 'How many open job cards each mechanic is carrying, so you can say who '
                           . 'is busy, who is free, and whether work is fairly spread.',
            'input_schema' => ['type' => 'object', 'properties' => (object)[]],
        ];
    }

    if (canAccess('parts_requests')) {
        $t[] = [
            'name' => 'parts_requests',
            'description' => 'Parts and quote requests raised off assessments or job cards, with who '
                           . 'raised them and whether they are still waiting for approval. Use when '
                           . 'asked what is holding up a repair, or what needs approving.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'filter' => [
                        'type' => 'string',
                        'enum' => ['pending', 'approved', 'all'],
                        'description' => 'pending = still waiting on someone.',
                    ],
                    'limit' => ['type' => 'integer', 'description' => 'How many, 1 to 25. Default 10.'],
                ],
                'required' => ['filter'],
            ],
        ];
    }

    if (canAccess('visitors')) {
        $t[] = [
            'name' => 'list_visitors',
            'description' => 'People who have signed in at reception — who they came to see, why, '
                           . 'and whether they are still on site.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'period' => ['type' => 'string', 'enum' => ['today', 'onsite', 'week'],
                                 'description' => 'onsite = still in the building.'],
                ],
                'required' => ['period'],
            ],
        ];
    }

    // Tasks hand over to the guided flow rather than writing anything here.
    $tasks = [];
    if (canAccess('crm')) {
        $tasks = ['add_lead', 'reserve', 'document', 'followup_lead', 'note_lead',
                  'priority_lead', 'add_deposit'];
    }
    if (canWrite('cars')) $tasks[] = 'add_car';

    if ($tasks) {
        $t[] = [
            'name' => 'start_task',
            'description' => 'Begin a task that changes records. This does NOT complete it — it '
                           . 'starts a short guided conversation that reads the details back and '
                           . 'asks the user to confirm before anything is saved. Call it as soon '
                           . 'as the user asks to do one of these things; do not collect the '
                           . 'details yourself first.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'task' => [
                        'type' => 'string', 'enum' => $tasks,
                        'description' => 'add_lead = capture a new lead. reserve = take a deposit and '
                                       . 'hold a vehicle. document = print a proforma, agreement or '
                                       . 'receipt. followup_lead = set a follow-up date. note_lead = '
                                       . 'add a note. priority_lead = mark hot, lukewarm or cold. '
                                       . 'add_deposit = record a further payment against a '
                                       . 'reservation and produce the receipt. '
                                       . 'add_car = put a new vehicle into inventory.',
                    ],
                ],
                'required' => ['task'],
            ],
        ];
    }

    return $t;
}

// ── Running a tool ───────────────────────────────────────────────────────────

/**
 * Executes one tool call.
 *
 * Returns ['text' => what the model is told, 'html' => cards for the screen,
 *          'handoff' => a skill key when the guided flow should take over].
 */
function carlToolRun(PDO $db, array $user, string $name, array $in): array
{
    $none = ['text' => '', 'html' => '', 'handoff' => null];

    switch ($name) {

        case 'business_snapshot':
            $f = carlFigures($db);
            return ['text' => carlLlmFigureSnapshot($f), 'html' => '', 'handoff' => null];

        case 'list_leads': {
            if (!canAccess('crm')) return ['text' => 'Not permitted for this account.'] + $none;
            $filter = (string)($in['filter'] ?? 'all');
            $limit  = max(1, min(25, (int)($in['limit'] ?? 8)));
            $q      = $filter === 'all' ? null : $filter;
            $rows   = carlLeadRows($db, $q, $limit);
            $total  = carlLeadCount($db, $q);
            if (!$rows) {
                return ['text' => 'No leads match that. Total in this category: 0.', 'html' => '', 'handoff' => null];
            }
            $lines = ['Total in this category: ' . $total . '. Showing ' . count($rows) . ':'];
            foreach ($rows as $r) {
                $lines[] = '- ' . $r['name']
                    . ' | phone ' . ($r['phone'] ?: 'none')
                    . ' | stage ' . ($r['stage'] ?: 'new')
                    . ' | follow-up ' . ($r['follow_up_date'] ?: 'not set')
                    . ' | owner ' . ($r['owner'] ?? 'unassigned');
            }
            return ['text' => implode("\n", $lines), 'html' => carlLeadCards($rows), 'handoff' => null];
        }

        case 'find_customer': {
            if (!canAccess('crm')) return ['text' => 'Not permitted for this account.'] + $none;
            $lead = carlFindLead($db, (string)($in['name'] ?? ''));
            if (!$lead) {
                return ['text' => 'No customer found matching that.', 'html' => '', 'handoff' => null];
            }
            $st = $db->prepare("SELECT l.*, u.name AS owner, c.make, c.model, c.year, c.registration_number
                                  FROM crm_leads l
                             LEFT JOIN users u ON u.id = l.assigned_to
                             LEFT JOIN cars  c ON c.id = l.pinned_car_id
                                 WHERE l.id = ?");
            $st->execute([(int)$lead['id']]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: $lead;

            $ready = [];
            foreach (carlDocuments() as $k => $d) if (($d['ready'])($r)) $ready[] = $d['label'];

            $lines = [
                'Name: '        . $r['name'],
                'Phone: '       . ($r['phone'] ?: 'none'),
                'Stage: '       . ($r['stage'] ?: 'new'),
                'Reservation: ' . ($r['reservation_status'] ?: 'none'),
                'Deposit: '     . ((float)($r['deposit_amount'] ?? 0) > 0
                                    ? 'KES ' . number_format((float)$r['deposit_amount']) : 'none'),
                'Vehicle: '     . (trim(($r['year'] ?? '') . ' ' . ($r['make'] ?? '') . ' ' . ($r['model'] ?? '')) ?: 'none pinned'),
                'Follow-up: '   . ($r['follow_up_date'] ?: 'not set'),
                'Assigned to: ' . ($r['owner'] ?: 'nobody'),
                'Documents ready to print: ' . ($ready ? implode(', ', $ready) : 'none yet'),
            ];
            return ['text' => implode("\n", $lines), 'html' => carlLeadMini($r), 'handoff' => null];
        }

        case 'delivery_pipeline': {
            if (!canAccess('crm')) return ['text' => 'Not permitted for this account.'] + $none;
            $res = carlSkillDeliveries($db, $user, '');
            return ['text' => $res['say'], 'html' => $res['html'] ?? '', 'handoff' => null];
        }

        case 'list_stock': {
            if (!canAccess('cars')) return ['text' => 'Not permitted for this account.'] + $none;
            $limit  = max(1, min(25, (int)($in['limit'] ?? 10)));
            $search = trim((string)($in['search'] ?? ''));
            $sql = "SELECT make, model, year, registration_number, asking_price, status, mileage
                      FROM cars WHERE status NOT IN ('sold','delivered')";
            $args = [];
            if ($search !== '') {
                $sql .= " AND (CONCAT_WS(' ', make, model) LIKE ? OR registration_number LIKE ?)";
                $args[] = '%' . $search . '%';
                $args[] = '%' . $search . '%';
            }
            $sql .= " ORDER BY status = 'arrived' DESC, updated_at DESC LIMIT " . $limit;
            $st = $db->prepare($sql);
            $st->execute($args);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) return ['text' => 'No vehicles match that.', 'html' => '', 'handoff' => null];

            $lines = [];
            foreach ($rows as $r) {
                $lines[] = '- ' . trim(($r['year'] ?? '') . ' ' . $r['make'] . ' ' . $r['model'])
                    . ' | reg ' . ($r['registration_number'] ?: 'none')
                    . ' | ' . ((float)$r['asking_price'] > 0
                                ? 'KES ' . number_format((float)$r['asking_price']) : 'price not set')
                    . ' | ' . $r['status'];
            }
            return ['text' => implode("\n", $lines), 'html' => '', 'handoff' => null];
        }

        case 'list_visitors': {
            if (!canAccess('visitors')) return ['text' => 'Not permitted for this account.'] + $none;
            $period = (string)($in['period'] ?? 'today');
            $where  = match ($period) {
                'onsite' => "v.checked_out_at IS NULL AND DATE(v.created_at) = CURDATE()",
                'week'   => "v.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)",
                default  => "DATE(v.created_at) = CURDATE()",
            };
            try {
                $rows = $db->query(
                    "SELECT v.first_name, v.last_name, v.phone, v.purpose, v.created_at,
                            v.checked_out_at, u.name AS officer
                       FROM visitors v
                  LEFT JOIN users u ON u.id = v.assigned_to
                      WHERE $where
                   ORDER BY v.created_at DESC LIMIT 20"
                )->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                error_log('carlToolRun visitors: ' . $e->getMessage());
                return ['text' => 'The visitor book could not be read.', 'html' => '', 'handoff' => null];
            }
            if (!$rows) return ['text' => 'No visitors for that period.', 'html' => '', 'handoff' => null];

            $lines = [];
            foreach ($rows as $r) {
                $lines[] = '- ' . trim($r['first_name'] . ' ' . $r['last_name'])
                    . ' | ' . ($r['purpose'] ?: 'not stated')
                    . ' | signed in ' . date('g:ia', strtotime($r['created_at']))
                    . ' | ' . ($r['checked_out_at'] ? 'checked out' : 'still on site')
                    . ' | with ' . ($r['officer'] ?: 'nobody assigned');
            }
            return ['text' => implode("\n", $lines), 'html' => '', 'handoff' => null];
        }

        case 'workshop_jobs': {
            if (!canAccess('jobs')) return ['text' => 'Not permitted for this account.'] + $none;
            $limit  = max(1, min(25, (int)($in['limit'] ?? 10)));
            $filter = (string)($in['filter'] ?? 'open');
            // Dates are compared in SQL: PHP runs UTC here and MySQL runs EAT, so
            // "open more than seven days" worked out differently on each side.
            $where = match ($filter) {
                'unassigned' => "j.status NOT IN ('completed','cancelled') AND (j.mechanic_id IS NULL OR j.mechanic_id = 0)",
                'stalled'    => "j.status NOT IN ('completed','cancelled') AND j.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)",
                'urgent'     => "j.status NOT IN ('completed','cancelled') AND j.priority IN ('high','urgent')",
                'completed'  => "j.status = 'completed'",
                'all'        => "1=1",
                default      => "j.status NOT IN ('completed','cancelled')",
            };
            try {
                $rows = $db->query(
                    "SELECT j.job_number, j.status, j.priority, j.description, j.created_at,
                            DATEDIFF(NOW(), j.created_at) AS days_open,
                            m.name AS mechanic,
                            c.make, c.model, c.year, c.registration_number
                       FROM workshop_jobs j
                  LEFT JOIN mechanics m ON m.id = j.mechanic_id
                  LEFT JOIN cars c      ON c.id = j.car_id
                      WHERE $where
                   ORDER BY j.created_at ASC
                      LIMIT $limit"
                )->fetchAll(PDO::FETCH_ASSOC);
                $total = (int)$db->query(
                    "SELECT COUNT(*) FROM workshop_jobs j WHERE $where"
                )->fetchColumn();
            } catch (\Throwable $e) {
                error_log('carlToolRun workshop_jobs: ' . $e->getMessage());
                return ['text' => 'The workshop could not be read.', 'html' => '', 'handoff' => null];
            }
            if (!$rows) {
                return ['text' => 'No job cards match that. Total in this category: 0.',
                        'html' => '', 'handoff' => null];
            }
            $lines = ['Total in this category: ' . $total . '. Showing ' . count($rows) . ':'];
            $cards = '';
            foreach ($rows as $r) {
                $car = trim(($r['year'] ?? '') . ' ' . ($r['make'] ?? '') . ' ' . ($r['model'] ?? ''));
                $lines[] = '- ' . $r['job_number']
                    . ' | ' . ($car !== '' ? $car : 'no vehicle')
                    . ' | ' . ($r['registration_number'] ?: 'no plate')
                    . ' | ' . ($r['mechanic'] ?: 'no mechanic assigned')
                    . ' | ' . ($r['status'] ?: 'open')
                    . ' | ' . (int)$r['days_open'] . ' days open'
                    . ' | ' . (trim((string)$r['description']) !== ''
                                ? substr($r['description'], 0, 60) : 'no description');
                $cards .= '<a class="carl-rec" href="' . BASE_URL . '/modules/jobs/view.php?id=0">'
                        . '<b>' . e($r['job_number']) . '</b>'
                        . '<span>' . e($car !== '' ? $car : 'no vehicle') . '</span>'
                        . '<em>' . e(($r['mechanic'] ?: 'unassigned') . ' · '
                                  . (int)$r['days_open'] . ' days open') . '</em></a>';
            }
            return ['text' => implode("\n", $lines),
                    'html' => '<div class="carl-recs">' . $cards . '</div>', 'handoff' => null];
        }

        case 'mechanic_workload': {
            if (!canAccess('jobs')) return ['text' => 'Not permitted for this account.'] + $none;
            try {
                $rows = $db->query(
                    "SELECT m.name, m.specialization,
                            SUM(CASE WHEN j.status NOT IN ('completed','cancelled')
                                     THEN 1 ELSE 0 END) AS open_jobs
                       FROM mechanics m
                  LEFT JOIN workshop_jobs j ON j.mechanic_id = m.id
                      WHERE m.status = 'active'
                   GROUP BY m.id, m.name, m.specialization
                   ORDER BY open_jobs DESC, m.name"
                )->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                error_log('carlToolRun mechanic_workload: ' . $e->getMessage());
                return ['text' => 'The mechanic list could not be read.', 'html' => '', 'handoff' => null];
            }
            if (!$rows) return ['text' => 'No active mechanics on file.', 'html' => '', 'handoff' => null];

            $lines = [];
            foreach ($rows as $r) {
                $lines[] = '- ' . $r['name']
                    . ' | ' . ((int)$r['open_jobs']) . ' open job cards'
                    . ' | ' . ($r['specialization'] ?: 'general');
            }
            $unassigned = (int)$db->query(
                "SELECT COUNT(*) FROM workshop_jobs
                  WHERE status NOT IN ('completed','cancelled')
                    AND (mechanic_id IS NULL OR mechanic_id = 0)"
            )->fetchColumn();
            if ($unassigned > 0) {
                $lines[] = '- Nobody assigned: ' . $unassigned . ' open job cards have no mechanic.';
            }
            return ['text' => implode("\n", $lines), 'html' => '', 'handoff' => null];
        }

        case 'parts_requests': {
            if (!canAccess('parts_requests')) return ['text' => 'Not permitted for this account.'] + $none;
            $limit  = max(1, min(25, (int)($in['limit'] ?? 10)));
            $filter = (string)($in['filter'] ?? 'pending');
            $where  = match ($filter) {
                'approved' => "pr.status = 'approved'",
                'all'      => "1=1",
                default    => "pr.status NOT IN ('approved','rejected','cancelled','completed')",
            };
            try {
                $rows = $db->query(
                    "SELECT pr.request_number, pr.status, pr.created_at, pr.notes,
                            DATEDIFF(NOW(), pr.created_at) AS days_waiting,
                            pr.client_name, pr.car_make, pr.car_model, pr.car_registration,
                            m.name AS mechanic
                       FROM parts_requests pr
                  LEFT JOIN mechanics m ON m.id = pr.mechanic_id
                      WHERE $where
                   ORDER BY pr.created_at ASC
                      LIMIT $limit"
                )->fetchAll(PDO::FETCH_ASSOC);
                $total = (int)$db->query("SELECT COUNT(*) FROM parts_requests pr WHERE $where")->fetchColumn();
            } catch (\Throwable $e) {
                error_log('carlToolRun parts_requests: ' . $e->getMessage());
                return ['text' => 'The parts requests could not be read.', 'html' => '', 'handoff' => null];
            }
            if (!$rows) return ['text' => 'No parts requests match that. Total: 0.', 'html' => '', 'handoff' => null];

            $lines = ['Total in this category: ' . $total . '. Showing ' . count($rows) . ':'];
            foreach ($rows as $r) {
                $lines[] = '- ' . $r['request_number']
                    . ' | ' . ($r['client_name'] ?: 'no client')
                    . ' | ' . trim(($r['car_make'] ?? '') . ' ' . ($r['car_model'] ?? ''))
                    . ' | ' . ($r['mechanic'] ?: 'no mechanic')
                    . ' | ' . ($r['status'] ?: 'pending')
                    . ' | waiting ' . (int)$r['days_waiting'] . ' days';
            }
            return ['text' => implode("\n", $lines), 'html' => '', 'handoff' => null];
        }

        case 'start_task': {
            $task = (string)($in['task'] ?? '');
            $ok   = ['add_lead', 'reserve', 'document', 'followup_lead', 'note_lead', 'priority_lead'];
            if (!in_array($task, $ok, true) || !canAccess('crm')) {
                return ['text' => 'That task is not available for this account.', 'html' => '', 'handoff' => null];
            }
            // The guided flow owns the conversation from here.
            return ['text' => 'Guided task started.', 'html' => '', 'handoff' => $task];
        }
    }

    return ['text' => 'Unknown tool.', 'html' => '', 'handoff' => null];
}

// ── The conversation ─────────────────────────────────────────────────────────

/**
 * One turn of conversation, with as many tool round-trips as the answer needs.
 *
 * Returns the same shape as a skill handler, so ask.php does not care which of
 * the two answered.
 */
function carlConverse(PDO $db, array $user, string $msg, array $history = []): ?array
{
    if (!carlAiAvailable()) return null;

    $tools = carlTools($user);
    $now   = new DateTime('now', new DateTimeZone('Africa/Nairobi'));
    $hour  = (int)$now->format('G');
    $part  = $hour < 12 ? 'morning' : ($hour < 17 ? 'afternoon' : 'evening');

    $system = carlPersona() . "\n\n"
        . "You are speaking with {$user['name']}, whose role is "
        . str_replace('_', ' ', (string)($user['role'] ?? 'staff')) . ".\n"
        . "It is " . $now->format('l, j F Y') . ", " . $now->format('g:ia') . " in Nairobi — "
        . "the {$part}.\n\n"
        . "HOW TO BEHAVE\n"
        . "- Talk like a person. Greetings, thanks and small talk get a warm, short, human reply. "
        . "Never answer a greeting with a menu of what you can do.\n"
        . "- You have tools that read the live database. Use them whenever a question touches "
        . "real figures, names, vehicles, customers or visitors. Never state a number you were "
        . "not given by a tool, and never estimate one.\n"
        . "- If a tool returns nothing, say so plainly. Do not fill the gap with a guess.\n"
        . "- When the user wants to change something — add a lead, take a deposit, print a "
        . "document, set a follow-up — call start_task immediately. Do not ask for the details "
        . "yourself; the guided flow does that and confirms before saving.\n"
        . "- Answer in two or three sentences unless genuinely more is needed. This is read "
        . "aloud as well as shown on screen, so write prose, never lists or markdown.\n"
        . "- If you truly cannot help, say what you can do instead — briefly, in a sentence.";

    // Recent turns, so follow-up questions such as "and last month?" make sense.
    // Built by the provider layer, because Anthropic and Google disagree about
    // both the role names and the shape of a message.
    $msgs = carlAiSeed($history, $msg);

    $html    = '';
    $handoff = null;

    // Four rounds is ample: look something up, maybe look up one more thing, answer.
    $round = null;
    for ($i = 0; $i < 4; $i++) {
        $round = carlAiRound($system, $msgs, $tools, 900);
        if ($round === null) return null;          // fall back to the offline matcher

        if (!$round['calls']) {
            $say = $round['text'];
            if ($say === '') return null;
            return ['skill' => 'chat', 'done' => true, 'say' => $say, 'html' => $html];
        }

        // Run every tool it asked for, and hand the results straight back.
        carlAiAppendModelTurn($msgs, $round);
        $results = [];
        foreach ($round['calls'] as $c) {
            $out = carlToolRun($db, $user, (string)$c['name'], (array)($c['input'] ?? []));
            if ($out['html'] !== '') $html .= $out['html'];
            if (!empty($out['handoff'])) $handoff = $out['handoff'];
            $results[] = [
                'id'   => $c['id'],
                'name' => $c['name'],
                'text' => $out['text'] !== '' ? $out['text'] : 'No data.',
            ];
        }
        carlAiAppendToolResults($msgs, $results);

        // A task takes over the conversation — let the guided flow ask its first
        // question rather than having the model improvise one.
        if ($handoff !== null) {
            $res = carlRun($db, $user, $handoff, $msg);
            $res['html'] = $html . ($res['html'] ?? '');
            return $res;
        }
    }

    // Ran out of rounds — answer with whatever it has rather than saying nothing.
    $say = $round['text'] ?? '';
    return $say !== '' ? ['skill' => 'chat', 'done' => true, 'say' => $say, 'html' => $html] : null;
}
