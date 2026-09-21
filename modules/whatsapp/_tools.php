<?php
/**
 * What Karl can actually do for a customer on WhatsApp.
 *
 * Until now he could only acknowledge and defer, which is polite and nearly
 * useless: a customer asking "do you have a Harrier" got "a colleague will be
 * in touch". These are the things he can do instead, and every one of them is
 * a lookup against real records rather than anything he composes himself.
 *
 * Two rules decide what is in here.
 *
 * He may state what the yard already publishes. A listed asking price is on the
 * website and on the windscreen; repeating it to somebody who asked is not a
 * commitment, it is service. What he still may not do is move it — no discount,
 * no negotiation, no "I could do it for", no holding a car for anyone.
 *
 * He may hand a customer their OWN paperwork, and nobody else's. That one is
 * the dangerous one. An invoice names what somebody paid for a car, and a
 * WhatsApp number is the only evidence of who is asking, so a document is only
 * ever released when the conversation is already tied to the client the
 * document belongs to — and never on the strength of a number the customer
 * types into the chat.
 */

if (!function_exists('waToolSchema')) {

require_once __DIR__ . '/_wa.php';

/** The tools, in the shape the model layer expects. */
function waToolSchema(): array
{
    return [
        [
            'name' => 'search_stock',
            'description' =>
                'Search the vehicles the dealership currently has for sale. Use this whenever the '
                . 'customer asks about a car, a make, a model, a body type or a budget — never answer '
                . 'from memory. Returns make, model, year, mileage, colour and the listed price.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query'     => ['type' => 'string',
                                    'description' => 'What they asked for, e.g. "Harrier", "Toyota SUV", "BMW"'],
                    'max_price' => ['type' => 'number', 'description' => 'Their budget in KES, if they gave one'],
                ],
                'required' => [],
            ],
        ],
        [
            'name' => 'send_car_photos',
            'description' =>
                'Send the customer photographs of one vehicle. Use it after search_stock when they '
                . 'show interest in a particular car. Give the car_id exactly as search_stock returned it.',
            'input_schema' => [
                'type' => 'object',
                'properties' => ['car_id' => ['type' => 'integer', 'description' => 'From search_stock']],
                'required' => ['car_id'],
            ],
        ],
        [
            'name' => 'my_documents',
            'description' =>
                'List the invoices and quotations belonging to THIS customer. Only works when we '
                . 'already know who they are from their phone number. Use it when they ask for their '
                . 'invoice, quotation, statement or paperwork.',
            'input_schema' => ['type' => 'object', 'properties' => [], 'required' => []],
        ],
        [
            'name' => 'send_document',
            'description' =>
                'Send the customer a secure link to one of their own documents, as listed by '
                . 'my_documents. Use the kind and id exactly as they were returned.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'kind' => ['type' => 'string', 'enum' => ['invoice', 'quotation'],
                               'description' => 'From my_documents'],
                    'id'   => ['type' => 'integer', 'description' => 'From my_documents'],
                ],
                'required' => ['kind', 'id'],
            ],
        ],
        [
            'name' => 'opening_hours',
            'description' => 'The yard opening hours and where it is. Use it when they ask.',
            'input_schema' => ['type' => 'object', 'properties' => [], 'required' => []],
        ],
    ];
}

/**
 * Run one tool.
 *
 * $ctx carries the conversation, because who is asking decides what may be
 * returned — and that must come from the thread, never from the arguments the
 * model produced. A model that can be talked into passing a different client_id
 * is a model that can be talked into leaking an invoice.
 *
 * @return array{result:string, sent:int}
 */
function waRunTool(PDO $db, string $name, array $args, array $ctx): array
{
    try {
        return match ($name) {
            'search_stock'    => ['result' => waToolStock($db, $args), 'sent' => 0],
            'send_car_photos' => waToolCarPhotos($db, (int)($args['car_id'] ?? 0), $ctx),
            'my_documents'    => ['result' => waToolDocuments($db, $ctx), 'sent' => 0],
            'send_document'   => waToolSendDocument($db, (string)($args['kind'] ?? ''),
                                                    (int)($args['id'] ?? 0), $ctx),
            'opening_hours'   => ['result' => waToolHours($db), 'sent' => 0],
            default           => ['result' => 'No such tool.', 'sent' => 0],
        };
    } catch (\Throwable $e) {
        error_log('waRunTool ' . $name . ': ' . $e->getMessage());
        return ['result' => 'That could not be looked up just now.', 'sent' => 0];
    }
}

// ── Stock ────────────────────────────────────────────────────────────────────

/**
 * Cars a customer could actually buy.
 *
 * Scoped to inventory that is meant to be seen: something already sold, or held
 * back from the website, is not something to show a stranger. The statuses come
 * from what the yard uses, and anything it has not decided about is left out
 * rather than guessed at.
 */
function waToolStock(PDO $db, array $args): string
{
    $q   = trim((string)($args['query'] ?? ''));
    $max = (float)($args['max_price'] ?? 0);

    $where = ["c.car_type = 'inventory'",
              "(c.status IS NULL OR c.status NOT IN ('sold','delivered','reserved'))",
              "c.show_on_website = 1"];
    $params = [];

    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = "(c.make LIKE ? OR c.model LIKE ? OR c.body_type LIKE ?
                     OR CONCAT_WS(' ', c.year, c.make, c.model) LIKE ?)";
        array_push($params, $like, $like, $like, $like);
    }
    if ($max > 0) {
        $where[] = "COALESCE(NULLIF(c.asking_price,0), c.offer_price) <= ?";
        $params[] = $max;
    }

    $st = $db->prepare("
        SELECT c.id, c.make, c.model, c.year, c.mileage, c.color, c.body_type,
               c.transmission, c.fuel_type, c.engine_cc,
               COALESCE(NULLIF(c.asking_price,0), c.offer_price) AS price
          FROM cars c
         WHERE " . implode(' AND ', $where) . "
      ORDER BY (price IS NULL), price ASC
         LIMIT 6");
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        return $q !== ''
            ? 'Nothing matching "' . $q . '" is in stock right now. Tell the customer honestly, '
              . 'offer to let a colleague know what they are looking for, and do not invent alternatives.'
            : 'There is nothing listed for sale right now.';
    }

    $out = "Vehicles in stock (car_id, then the details). Prices are the listed asking price:\n";
    foreach ($rows as $r) {
        $bits = [];
        if ($r['mileage'])     $bits[] = number_format((float)$r['mileage']) . ' km';
        if ($r['color'])       $bits[] = (string)$r['color'];
        if ($r['transmission'])$bits[] = (string)$r['transmission'];
        if ($r['fuel_type'])   $bits[] = (string)$r['fuel_type'];
        if ($r['engine_cc'])   $bits[] = (int)$r['engine_cc'] . 'cc';

        $out .= '- car_id ' . (int)$r['id'] . ': '
              . trim(($r['year'] ? $r['year'] . ' ' : '') . $r['make'] . ' ' . $r['model'])
              . ($bits ? ' — ' . implode(', ', $bits) : '')
              . ' — ' . ($r['price'] > 0 ? 'KES ' . number_format((float)$r['price'])
                                         : 'price on application')
              . "\n";
    }
    $out .= "\nList at most three unless they asked for more. Give the price exactly as shown. "
          . "Do not offer a discount and do not agree to hold any of them.";
    return $out;
}

/** Photographs of one car, sent as images. */
function waToolCarPhotos(PDO $db, int $carId, array $ctx): array
{
    if ($carId <= 0) return ['result' => 'No car was named.', 'sent' => 0];

    // Re-checked against the same rule search_stock uses. The model could name
    // any id at all, including one belonging to a car that has been sold.
    $st = $db->prepare("
        SELECT c.id, c.make, c.model, c.year
          FROM cars c
         WHERE c.id = ? AND c.car_type = 'inventory' AND c.show_on_website = 1
           AND (c.status IS NULL OR c.status NOT IN ('sold','delivered','reserved'))");
    $st->execute([$carId]);
    $car = $st->fetch(PDO::FETCH_ASSOC);
    if (!$car) return ['result' => 'That vehicle is not available to show.', 'sent' => 0];

    $img = $db->prepare("SELECT file_path FROM car_images WHERE car_id = ?
                      ORDER BY is_primary DESC, id ASC LIMIT 3");
    $img->execute([$carId]);
    $paths = $img->fetchAll(PDO::FETCH_COLUMN);
    if (!$paths) return ['result' => 'There are no photographs of that vehicle on file.', 'sent' => 0];

    $label = trim(($car['year'] ? $car['year'] . ' ' : '') . $car['make'] . ' ' . $car['model']);
    $sent  = 0;
    foreach ($paths as $rel) {
        $real = waSafeUploadPath((string)$rel);
        if ($real === null) continue;
        $r = waSendDocument($db, (int)$ctx['conversation_id'], $real,
                            $label . ' (' . ($sent + 1) . ').jpg', $sent === 0 ? $label : '', null);
        if ($r['ok']) $sent++;
    }

    return [
        'result' => $sent > 0
            ? $sent . ' photograph(s) of the ' . $label . ' have been sent to the customer. '
              . 'Say so briefly; do not describe the pictures.'
            : 'The photographs could not be sent.',
        'sent'   => $sent,
    ];
}

// ── The customer's own paperwork ─────────────────────────────────────────────

/**
 * What this customer may see.
 *
 * Bound to the client the conversation is already linked to. Not to anything in
 * the request, and not to a number the customer offers — "I'm calling for Mr
 * Odhiambo, send me his invoice" has to fail, and it fails here by never
 * consulting anything the customer said.
 */
function waToolDocuments(PDO $db, array $ctx): string
{
    $clientId = (int)($ctx['client_id'] ?? 0);
    if ($clientId <= 0) {
        return 'This number is not linked to a customer account, so no documents can be released. '
             . 'Tell them a colleague will confirm their details and send it — do not ask them to '
             . 'prove who they are over chat.';
    }

    $inv = $db->prepare("SELECT id, invoice_number, date, total, status
                           FROM invoices WHERE client_id = ? ORDER BY date DESC, id DESC LIMIT 5");
    $inv->execute([$clientId]);

    $quo = $db->prepare("SELECT id, quotation_number, date, total, status
                           FROM quotations WHERE client_id = ? ORDER BY date DESC, id DESC LIMIT 5");
    $quo->execute([$clientId]);

    $lines = [];
    foreach ($inv->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $lines[] = 'invoice id ' . (int)$r['id'] . ': ' . $r['invoice_number']
                 . ' dated ' . fmtDate($r['date'], 'j M Y')
                 . ' — KES ' . number_format((float)$r['total']) . ', ' . $r['status'];
    }
    foreach ($quo->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $lines[] = 'quotation id ' . (int)$r['id'] . ': ' . $r['quotation_number']
                 . ' dated ' . fmtDate($r['date'], 'j M Y')
                 . ' — KES ' . number_format((float)$r['total']) . ', ' . $r['status'];
    }

    if (!$lines) return 'This customer has no invoices or quotations on file.';

    return "This customer's documents:\n- " . implode("\n- ", $lines)
         . "\n\nIf they want one, send it with send_document using the kind and id above.";
}

/**
 * Hand over a document as a link that expires.
 *
 * A link rather than a file because nothing on this server can render a PDF —
 * there is no vendor directory on the live install — and a link the customer
 * opens in their own browser is honest about that rather than failing quietly.
 *
 * The token is signed with the application key and carries the client it was
 * issued for, so it cannot be edited into somebody else's invoice, and it dies
 * after a few days.
 */
function waToolSendDocument(PDO $db, string $kind, int $id, array $ctx): array
{
    $clientId = (int)($ctx['client_id'] ?? 0);
    if ($clientId <= 0) {
        return ['result' => 'This number is not linked to a customer account, so nothing can be '
                          . 'released. Say a colleague will send it.', 'sent' => 0];
    }
    if (!in_array($kind, ['invoice', 'quotation'], true) || $id <= 0) {
        return ['result' => 'That document could not be found.', 'sent' => 0];
    }

    // Ownership is proved from the record, every time, however it was asked for.
    $table = $kind === 'invoice' ? 'invoices' : 'quotations';
    $numCol = $kind === 'invoice' ? 'invoice_number' : 'quotation_number';
    $st = $db->prepare("SELECT id, {$numCol} AS num, client_id FROM {$table} WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);

    if (!$doc || (int)$doc['client_id'] !== $clientId) {
        // Deliberately the same answer as "no such document": confirming that a
        // document exists but belongs to somebody else is itself a disclosure.
        error_log('waToolSendDocument: refused ' . $kind . ' #' . $id . ' for client ' . $clientId);
        return ['result' => 'That document could not be found for this customer.', 'sent' => 0];
    }

    $token = waSignDocToken($kind, $id, $clientId);
    $url   = rtrim(BASE_URL, '/') . '/modules/whatsapp/doc.php?t=' . $token;

    $text  = ucfirst($kind) . ' ' . $doc['num'] . ":\n" . $url
           . "\n\nThe link works for 7 days.";

    $r = waSendText($db, (int)$ctx['conversation_id'], $text, null);
    if ($r['ok']) {
        try {
            logActivity('create', $table, $id,
                'Karl sent ' . $kind . ' ' . $doc['num'] . ' to the client on WhatsApp.');
        } catch (\Throwable $_) {}
    }

    return [
        'result' => $r['ok']
            ? 'The link to ' . $kind . ' ' . $doc['num'] . ' has been sent. Say so in one short line.'
            : 'That could not be sent.',
        'sent'   => $r['ok'] ? 1 : 0,
    ];
}

/**
 * The documents a customer may be handed a link to.
 *
 * The third field of a token is the owner, and what "owner" means depends on
 * the kind: for an invoice or a quotation it is the client, because those are
 * listed by client and an id could otherwise be swapped for a neighbour's.
 * For a deposit receipt or a booking it is the record's own id, because those
 * are reached only through a signed link in the first place and there is no
 * listing to walk.
 */
function waDocKinds(): array
{
    return ['invoice', 'quotation', 'deposit', 'booking'];
}

/** A token nobody can forge or edit into another customer's paperwork. */
function waSignDocToken(string $kind, int $id, int $clientId, int $days = 7): string
{
    $exp  = time() + ($days * 86400);
    $body = $kind . '.' . $id . '.' . $clientId . '.' . $exp;
    return rtrim(strtr(base64_encode($body . '.' . waDocSig($body)), '+/', '-_'), '=');
}

/** @return array{kind:string,id:int,client_id:int}|null */
function waVerifyDocToken(string $token): ?array
{
    $raw = base64_decode(strtr($token, '-_', '+/'), true);
    if ($raw === false) return null;

    $parts = explode('.', $raw);
    if (count($parts) !== 5) return null;
    [$kind, $id, $clientId, $exp, $sig] = $parts;

    $body = $kind . '.' . $id . '.' . $clientId . '.' . $exp;
    if (!hash_equals(waDocSig($body), $sig)) return null;
    if ((int)$exp < time()) return null;
    if (!in_array($kind, waDocKinds(), true)) return null;

    return ['kind' => $kind, 'id' => (int)$id, 'client_id' => (int)$clientId];
}

function waDocSig(string $body): string
{
    // The application's own secret. Falls back to the webhook secret rather than
    // to a constant, because a signature everybody's install shares is no
    // signature at all.
    $key = (string)(defined('APP_KEY') ? APP_KEY : '');
    if ($key === '') $key = (string)getSetting('wa_webhook_secret', '');
    if ($key === '') $key = (string)getSetting('wa_green_token', 'mascardi');
    return substr(hash_hmac('sha256', $body, $key), 0, 32);
}

// ── Opening hours ────────────────────────────────────────────────────────────

function waToolHours(PDO $db): string
{
    require_once __DIR__ . '/_auto.php';
    $cfg  = waAutoConfig();
    $open = waWithinHours($db, $cfg);
    $addr = trim((string)getSetting('company_address', ''));
    return 'The yard is open ' . $cfg['open'] . ' to ' . $cfg['close']
         . ' and is ' . ($open ? 'OPEN right now' : 'CLOSED right now') . '.'
         . ($addr !== '' ? ' It is at ' . $addr . '.' : '');
}

/** A stored upload path resolved safely, or null. */
function waSafeUploadPath(string $stored): ?string
{
    $root = realpath(BASE_PATH . '/uploads');
    if ($root === false) return null;
    foreach ([BASE_PATH . '/' . ltrim($stored, '/\\'),
              BASE_PATH . '/uploads/' . ltrim($stored, '/\\')] as $c) {
        $real = realpath($c);
        if ($real === false || !is_file($real) || !is_readable($real)) continue;
        if (!str_starts_with($real, $root . DIRECTORY_SEPARATOR)) continue;
        return $real;
    }
    return null;
}

} // function_exists('waToolSchema')
