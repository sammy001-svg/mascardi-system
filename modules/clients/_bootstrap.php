<?php
/**
 * Clients — shared helpers.
 *
 * The problem this solves
 * ----------------------
 * Every document that a client appears on keeps its own frozen copy of their
 * name, phone and email, taken at the moment it was created:
 *
 *   invoices           customer_name, customer_phone, customer_email
 *   quotations         customer_name, customer_phone, customer_email
 *   payments           client_name,   client_phone
 *   quick_assessments  client_name,   client_phone,   client_email
 *   service_bookings   client_name,   client_phone,   client_email
 *
 * That is a reasonable way to build it — a document should still print if the
 * client record is later deleted — but it means correcting a misspelt name on
 * the client leaves every invoice, quotation and receipt still showing the old
 * one. Fifty-odd pages read those columns, so the fix has to be at the point of
 * saving, not at every point of reading.
 */

if (!function_exists('clientDocumentTables')) {

/**
 * Which tables hold a copy, and under which column names.
 *
 * A table only appears here if it carries BOTH a client_id to match on and a
 * name to correct. Tables that merely reference client_id — cars, leads,
 * consignments — already read through to the live record and need nothing.
 */
function clientDocumentTables(): array
{
    return [
        'invoices'          => ['name' => 'customer_name', 'phone' => 'customer_phone',
                                'email' => 'customer_email', 'kra' => 'customer_kra_pin',
                                'label' => 'invoices'],
        'quotations'        => ['name' => 'customer_name', 'phone' => 'customer_phone',
                                'email' => 'customer_email', 'label' => 'quotations'],
        'payments'          => ['name' => 'client_name',   'phone' => 'client_phone',
                                'label' => 'receipts'],
        'quick_assessments' => ['name' => 'client_name',   'phone' => 'client_phone',
                                'email' => 'client_email', 'label' => 'assessments'],
        'service_bookings'  => ['name' => 'client_name',   'phone' => 'client_phone',
                                'email' => 'client_email', 'label' => 'service bookings'],
    ];
}

/** Columns actually present on a table — schemas differ between installs. */
function clientTableColumns(PDO $db, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $cols = $db->query("SHOW COLUMNS FROM `" . $table . "`")->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {
        $cols = [];
    }
    return $cache[$table] = array_map('strval', $cols);
}

/**
 * Pushes a client's current details onto every document that belongs to them.
 *
 * $new and $old are the client rows after and before the edit. Only fields that
 * actually changed are written, so a document deliberately made out to a
 * different contact keeps whatever was not touched.
 *
 * Documents created without a client_id cannot be found by matching on it, so
 * they are additionally matched on the PREVIOUS name together with the previous
 * phone or email — two fields, not one, because a name alone is not enough to be
 * sure it is the same person. Those are then linked by client_id so that the
 * next correction reaches them directly.
 *
 * @return array{updated:int, adopted:int, detail:array<string,int>}
 */
function clientSyncDocuments(PDO $db, int $clientId, array $new, array $old): array
{
    $result = ['updated' => 0, 'adopted' => 0, 'detail' => []];
    if ($clientId <= 0) return $result;

    // What actually changed. Nothing changed, nothing to do.
    $fields = [];
    foreach (['name', 'phone', 'email', 'kra_pin'] as $f) {
        $a = trim((string)($new[$f] ?? ''));
        $b = trim((string)($old[$f] ?? ''));
        if ($a !== $b && $a !== '') $fields[$f === 'kra_pin' ? 'kra' : $f] = $a;
    }
    if (!$fields) return $result;

    $oldName  = trim((string)($old['name'] ?? ''));
    $oldPhone = trim((string)($old['phone'] ?? ''));
    $oldEmail = trim((string)($old['email'] ?? ''));

    foreach (clientDocumentTables() as $table => $map) {
        $cols = clientTableColumns($db, $table);
        if (!$cols || !in_array('client_id', $cols, true)) continue;

        // Build the SET clause from the fields that changed AND exist here.
        $sets = [];
        $args = [];
        foreach ($fields as $field => $value) {
            $col = $map[$field] ?? null;
            if ($col === null || !in_array($col, $cols, true)) continue;
            $sets[] = "`$col` = ?";
            $args[] = $value;
        }
        if (!$sets) continue;

        // ── Documents already linked to this client ──────────────────────────
        try {
            $st = $db->prepare("UPDATE `$table` SET " . implode(', ', $sets) . " WHERE client_id = ?");
            $st->execute([...$args, $clientId]);
            $n = $st->rowCount();
            if ($n > 0) {
                $result['updated'] += $n;
                $label = $map['label'];
                $result['detail'][$label] = ($result['detail'][$label] ?? 0) + $n;
            }
        } catch (\Throwable $e) {
            error_log("clientSyncDocuments($table): " . $e->getMessage());
        }

        // ── Documents that were never linked ─────────────────────────────────
        // Only when we can corroborate the name with a second field; a shared
        // name on its own is how one client's details end up on another's paperwork.
        $nameCol  = $map['name'];
        $phoneCol = $map['phone'] ?? null;
        $emailCol = $map['email'] ?? null;
        if ($oldName === '' || !in_array($nameCol, $cols, true)) continue;

        $corroborate = [];
        $cArgs = [];
        if ($oldPhone !== '' && $phoneCol && in_array($phoneCol, $cols, true)) {
            $corroborate[] = "REPLACE(REPLACE(TRIM(`$phoneCol`), ' ', ''), '-', '') = ?";
            $cArgs[] = str_replace([' ', '-'], '', $oldPhone);
        }
        if ($oldEmail !== '' && $emailCol && in_array($emailCol, $cols, true)) {
            $corroborate[] = "LOWER(TRIM(`$emailCol`)) = ?";
            $cArgs[] = strtolower($oldEmail);
        }
        if (!$corroborate) continue;

        try {
            $sql = "UPDATE `$table` SET " . implode(', ', $sets) . ", client_id = ?
                     WHERE (client_id IS NULL OR client_id = 0)
                       AND LOWER(TRIM(`$nameCol`)) = ?
                       AND (" . implode(' OR ', $corroborate) . ")";
            $st = $db->prepare($sql);
            $st->execute([...$args, $clientId, strtolower($oldName), ...$cArgs]);
            $n = $st->rowCount();
            if ($n > 0) {
                $result['adopted'] += $n;
                $label = $map['label'];
                $result['detail'][$label] = ($result['detail'][$label] ?? 0) + $n;
            }
        } catch (\Throwable $e) {
            error_log("clientSyncDocuments($table, orphans): " . $e->getMessage());
        }
    }

    return $result;
}

/** "3 invoices and 1 receipt" — for telling the user what was touched. */
function clientSyncSummary(array $result): string
{
    if (($result['updated'] + $result['adopted']) === 0) return '';
    $parts = [];
    foreach ($result['detail'] as $label => $n) {
        $parts[] = $n . ' ' . ($n === 1 ? rtrim($label, 's') : $label);
    }
    if (!$parts) return '';
    $last = array_pop($parts);
    $list = $parts ? implode(', ', $parts) . ' and ' . $last : $last;
    return 'Updated ' . $list . '.';
}

/**
 * A WHERE fragment that finds every document belonging to a client.
 *
 * Matching on client_id alone is not enough, for two reasons the schema makes
 * unavoidable:
 *
 *   - invoices.client_id and quotations.client_id are NULLABLE, so a document
 *     raised without picking a client off the list — from a job card, or at the
 *     counter — is never linked, and disappears from the profile even though the
 *     customer name on it is right there.
 *   - fk_inv_client is ON DELETE SET NULL, so removing any client blanks the
 *     link on their invoices rather than refusing. The paperwork survives; the
 *     connection to the person does not.
 *
 * So unlinked rows are additionally matched on the name TOGETHER WITH the phone
 * or the email. Two fields, never the name alone: shared names are common, and
 * showing one client another's invoices is a worse failure than showing none.
 *
 * @return array{sql:string, params:array}
 */
function clientDocumentMatch(
    array $client,
    string $alias,
    string $nameCol,
    ?string $phoneCol = null,
    ?string $emailCol = null
): array {
    $id     = (int)($client['id'] ?? 0);
    $name   = strtolower(trim((string)($client['name'] ?? '')));
    $phone  = str_replace([' ', '-'], '', trim((string)($client['phone'] ?? '')));
    $email  = strtolower(trim((string)($client['email'] ?? '')));

    $sql    = "$alias.client_id = ?";
    $params = [$id];

    $corroborate = [];
    if ($phone !== '' && $phoneCol) {
        $corroborate[] = "REPLACE(REPLACE(TRIM($alias.`$phoneCol`), ' ', ''), '-', '') = ?";
        $params[] = $phone;   // bound in order below
    }
    if ($email !== '' && $emailCol) {
        $corroborate[] = "LOWER(TRIM($alias.`$emailCol`)) = ?";
        $params[] = $email;
    }

    if ($name !== '' && $corroborate) {
        // Rebuild in the order the placeholders appear.
        $params = [$id, $name];
        if ($phone !== '' && $phoneCol) $params[] = $phone;
        if ($email !== '' && $emailCol) $params[] = $email;

        $sql = "($alias.client_id = ?
                 OR (($alias.client_id IS NULL OR $alias.client_id = 0)
                     AND LOWER(TRIM($alias.`$nameCol`)) = ?
                     AND (" . implode(' OR ', $corroborate) . ")))";
    }

    return ['sql' => $sql, 'params' => $params];
}

} // function_exists('clientDocumentTables')
