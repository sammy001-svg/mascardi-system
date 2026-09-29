<?php
/**
 * The paperwork a deal collects on its way through.
 *
 * A reservation, a credit agreement and an import order each end in something
 * the buyer signs and hands back — a sales agreement, a reservation form, a
 * payment schedule, a copy of an ID. Until now there was nowhere to put the
 * signed copy. It lived in somebody's WhatsApp, or a drawer, and when the buyer
 * rang six months later to ask what they had agreed to, the answer depended on
 * who picked up.
 *
 * One store, not three. The lead is the thing the paperwork belongs to; which
 * part of the deal it came from is a tag on the row rather than a separate
 * table, so a document uploaded against the reservation is the same kind of
 * thing as one uploaded against the credit agreement, and the code that moves
 * them, lists them and deletes them is written once.
 *
 * On delivery the lead becomes a client, and the paperwork goes with it. It is
 * stamped with the client id rather than moved, so the document still knows
 * which deal produced it — a buyer on their third car has three sets, and they
 * should not merge into one pile.
 */

require_once __DIR__ . '/../../includes/functions.php';

/** Where in the deal a document came from. */
function leadDocContexts(): array
{
    return [
        'reservation'  => ['Reservation',  'fa-bookmark'],
        'credit'       => ['Credit',       'fa-file-contract'],
        'import_order' => ['Import order', 'fa-ship'],
        'delivery'     => ['Delivery',     'fa-truck'],
        'other'        => ['Other',        'fa-paperclip'],
    ];
}

/** What the document is. Free enough to cover a yard's own habits. */
function leadDocTypes(): array
{
    return [
        'sales_agreement'   => 'Sales agreement',
        'reservation_form'  => 'Reservation form',
        'credit_agreement'  => 'Credit / payment agreement',
        'payment_schedule'  => 'Payment schedule',
        'deposit_receipt'   => 'Deposit receipt',
        'id_copy'           => 'ID / passport copy',
        'kra_pin'           => 'KRA PIN certificate',
        'import_order'      => 'Import order form',
        'proforma'          => 'Proforma invoice',
        'delivery_note'     => 'Signed delivery note',
        'logbook_transfer'  => 'Logbook transfer form',
        'other'             => 'Other',
    ];
}

function leadDocsSchema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS crm_lead_documents (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            lead_id     INT          NOT NULL,
            client_id   INT          NULL DEFAULT NULL,
            context     VARCHAR(20)  NOT NULL DEFAULT 'other',
            doc_type    VARCHAR(40)  NOT NULL DEFAULT 'other',
            title       VARCHAR(255) NOT NULL,
            file_path   VARCHAR(255) NOT NULL,
            file_name   VARCHAR(255) NULL,
            file_size   INT          NULL,
            mime_type   VARCHAR(100) NULL,
            notes       TEXT         NULL,
            uploaded_by INT          NULL,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_lead   (lead_id),
            INDEX idx_client (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) { /* already there */ }
}

/** Where the files live. Outside the web root is not an option on this host,
 *  so the folder is closed to direct requests and the filename is unguessable. */
function leadDocsDir(): string
{
    return BASE_PATH . '/uploads/lead_docs/';
}

/**
 * Make the folder, and close it.
 *
 * The .htaccess has to be written by the application rather than shipped in the
 * repository, because .gitignore excludes uploads/* entirely — so a rule
 * committed there would never reach a server. Anything relying on the file
 * being deployed would be relying on somebody remembering to create it by hand,
 * and the failure is silent: the documents upload fine and are simply readable
 * by anyone who can guess a filename.
 */
function leadDocsEnsureDir(): bool
{
    $dir = leadDocsDir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;

    $guard = $dir . '.htaccess';
    if (!is_file($guard)) {
        @file_put_contents($guard,
            "# Signed sales agreements, credit agreements, ID and KRA PIN copies.
"
          . "# Written by modules/crm/_documents.php, because uploads/ is not in git.
"
          . "# Files are served by modules/crm/document_file.php, which checks a login.
"
          . "<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
"
          . "<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
");
    }
    return true;
}

/**
 * Everything on a lead, newest first, optionally just one part of the deal.
 */
function leadDocsFor(PDO $db, int $leadId, ?string $context = null): array
{
    leadDocsSchema($db);
    if ($leadId <= 0) return [];
    try {
        $sql  = "SELECT d.*, u.name AS uploader
                   FROM crm_lead_documents d
              LEFT JOIN users u ON u.id = d.uploaded_by
                  WHERE d.lead_id = ?";
        $args = [$leadId];
        if ($context !== null) { $sql .= " AND d.context = ?"; $args[] = $context; }
        $sql .= " ORDER BY d.created_at DESC, d.id DESC";
        $st = $db->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('leadDocsFor: ' . $e->getMessage());
        return [];
    }
}

/** How many, per part of the deal — for the counts on each summary heading. */
function leadDocsCounts(PDO $db, int $leadId): array
{
    leadDocsSchema($db);
    $out = [];
    if ($leadId <= 0) return $out;
    try {
        $st = $db->prepare("SELECT context, COUNT(*) n FROM crm_lead_documents
                             WHERE lead_id = ? GROUP BY context");
        $st->execute([$leadId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(string)$r['context']] = (int)$r['n'];
    } catch (\Throwable $e) { /* counts are decoration */ }
    return $out;
}

/**
 * Take an upload and keep it.
 *
 * The same limits the car documents use, because a yard should not have to
 * remember two sets of rules about what it can attach and how big.
 */
function leadDocsStore(PDO $db, int $leadId, string $context, string $docType,
                       string $title, string $notes, array $file, int $userId): array
{
    leadDocsSchema($db);

    if ($leadId <= 0)                           return ['ok' => false, 'error' => 'No lead to attach this to.'];
    if (!array_key_exists($context, leadDocContexts())) $context = 'other';
    if (!array_key_exists($docType, leadDocTypes()))    $docType = 'other';

    if (empty($file['name']))                   return ['ok' => false, 'error' => 'Choose a file to upload.'];
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
        // The size cap can be hit by php.ini before it ever reaches ours, and
        // "Upload failed" is no use to somebody holding a 40MB scan.
        return ['ok' => false, 'error' => ($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE
            ? 'That file is larger than the server accepts. Try a smaller scan.'
            : 'The upload did not complete. Please try again.'];
    }

    $orig = basename((string)$file['name']);
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allowed = ['pdf','jpg','jpeg','png','gif','webp','doc','docx','xls','xlsx','csv','txt'];

    if (!in_array($ext, $allowed, true)) {
        return ['ok' => false, 'error' => 'That file type is not accepted. Use: ' . implode(', ', $allowed) . '.'];
    }
    if ((int)$file['size'] > 10 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'That file is over 10 MB. Try a smaller scan.'];
    }

    $title = trim($title) !== '' ? trim($title) : (leadDocTypes()[$docType] ?? 'Document');

    if (!leadDocsEnsureDir()) {
        return ['ok' => false, 'error' => 'The upload folder could not be created on the server.'];
    }
    $dir = leadDocsDir();
    // Random, not sequential: these files sit under the web root, so the name is
    // the only thing standing between a signed agreement and anyone who thinks
    // to guess the next one along.
    $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;

    if (!@move_uploaded_file($file['tmp_name'], $dir . $stored)) {
        return ['ok' => false, 'error' => 'The file could not be saved. Check folder permissions on the server.'];
    }

    // A lead already delivered has a client; a document added afterwards should
    // land on them too rather than waiting for a conversion that has been and gone.
    $clientId = null;
    try {
        $st = $db->prepare("SELECT client_id FROM crm_leads WHERE id = ?");
        $st->execute([$leadId]);
        $clientId = ((int)$st->fetchColumn()) ?: null;
    } catch (\Throwable $e) { /* leave it null; conversion will stamp it */ }

    try {
        $db->prepare("INSERT INTO crm_lead_documents
                          (lead_id, client_id, context, doc_type, title, file_path, file_name,
                           file_size, mime_type, notes, uploaded_by)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$leadId, $clientId, $context, $docType, $title, $stored, $orig,
                      (int)$file['size'], (@mime_content_type($dir . $stored) ?: null),
                      trim($notes) ?: null, $userId ?: null]);
    } catch (\Throwable $e) {
        @unlink($dir . $stored);
        error_log('leadDocsStore: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'The document could not be recorded. Nothing was saved.'];
    }

    try {
        logActivity('create', 'crm_leads', $leadId,
            'Document attached: ' . $title . ' (' . (leadDocContexts()[$context][0] ?? $context) . ')');
    } catch (\Throwable $e) { /* the audit trail is not worth failing an upload over */ }

    return ['ok' => true, 'error' => ''];
}

/** Remove one, file and row together. */
function leadDocsDelete(PDO $db, int $docId, int $leadId): array
{
    leadDocsSchema($db);
    try {
        $st = $db->prepare("SELECT * FROM crm_lead_documents WHERE id = ? AND lead_id = ?");
        $st->execute([$docId, $leadId]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$doc) return ['ok' => false, 'error' => 'That document is not on this lead.'];

        $db->prepare("DELETE FROM crm_lead_documents WHERE id = ?")->execute([$docId]);
        @unlink(leadDocsDir() . $doc['file_path']);

        logActivity('delete', 'crm_leads', $leadId, 'Document removed: ' . (string)$doc['title']);
        return ['ok' => true, 'error' => ''];
    } catch (\Throwable $e) {
        error_log('leadDocsDelete: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'That did not work.'];
    }
}

/**
 * Delivery: the lead becomes a client, and its paperwork becomes theirs.
 *
 * Called from crmDeliverLeadToClient(). Stamping rather than moving keeps the
 * lead_id, so a buyer on their second car has two sets of papers that stay told
 * apart instead of becoming one undated pile.
 */
function leadDocsAttachToClient(PDO $db, int $leadId, int $clientId): int
{
    leadDocsSchema($db);
    if ($leadId <= 0 || $clientId <= 0) return 0;
    try {
        $st = $db->prepare("UPDATE crm_lead_documents SET client_id = ? WHERE lead_id = ?");
        $st->execute([$clientId, $leadId]);
        return $st->rowCount();
    } catch (\Throwable $e) {
        error_log('leadDocsAttachToClient: ' . $e->getMessage());
        return 0;
    }
}

/** Everything now belonging to a client, across every deal they have had. */
function clientDocsFor(PDO $db, int $clientId): array
{
    leadDocsSchema($db);
    if ($clientId <= 0) return [];
    try {
        $st = $db->prepare("SELECT d.*, u.name AS uploader,
                                   l.name AS lead_name,
                                   CONCAT_WS(' ', c.year, c.make, c.model) AS vehicle
                              FROM crm_lead_documents d
                         LEFT JOIN users     u ON u.id = d.uploaded_by
                         LEFT JOIN crm_leads l ON l.id = d.lead_id
                         LEFT JOIN cars      c ON c.id = l.pinned_car_id
                             WHERE d.client_id = ?
                          ORDER BY d.created_at DESC, d.id DESC");
        $st->execute([$clientId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('clientDocsFor: ' . $e->getMessage());
        return [];
    }
}

/** A readable file size. */
function leadDocSize(?int $bytes): string
{
    $b = (int)$bytes;
    if ($b <= 0)         return '';
    if ($b < 1024)       return $b . ' B';
    if ($b < 1048576)    return round($b / 1024) . ' KB';
    return round($b / 1048576, 1) . ' MB';
}

/**
 * The documents block, as it appears inside a summary card.
 *
 * Written once and called three times. The reservation, the credit agreement
 * and the import order collect different paperwork but handle it identically,
 * and three copies of this markup would have drifted apart by the second change.
 */
function leadDocsPanel(PDO $db, int $leadId, string $context, bool $canWrite = true): void
{
    $docs  = leadDocsFor($db, $leadId, $context);
    $label = leadDocContexts()[$context][0] ?? 'Document';
    $types = leadDocTypes();
    ?>
    <div class="lead-docs" id="docs-<?= e($context) ?>">
        <div class="lead-docs-head">
            <i class="fa fa-paperclip me-2"></i>Signed documents
            <?php if ($docs): ?><span class="lead-docs-n"><?= count($docs) ?></span><?php endif; ?>
        </div>

        <?php if (!$docs): ?>
        <div class="lead-docs-empty">
            Nothing attached yet. Once the client has signed, scan or photograph it and put it here —
            it follows them to their profile when the car is delivered.
        </div>
        <?php else: ?>
        <div class="lead-docs-list">
            <?php foreach ($docs as $d):
                $ext = strtolower(pathinfo((string)$d['file_name'], PATHINFO_EXTENSION));
                $ico = in_array($ext, ['jpg','jpeg','png','gif','webp'], true) ? 'fa-file-image'
                     : ($ext === 'pdf' ? 'fa-file-pdf'
                     : (in_array($ext, ['xls','xlsx','csv'], true) ? 'fa-file-excel' : 'fa-file-lines'));
            ?>
            <div class="lead-doc">
                <i class="fa <?= $ico ?> lead-doc-ico"></i>
                <div class="lead-doc-main">
                    <a href="<?= BASE_URL ?>/modules/crm/document_file.php?id=<?= (int)$d['id'] ?>&view=1"
                       target="_blank" rel="noopener" class="lead-doc-title"><?= e((string)$d['title']) ?></a>
                    <div class="lead-doc-meta">
                        <?= e($types[$d['doc_type']] ?? 'Document') ?>
                        <?php if ($sz = leadDocSize((int)$d['file_size'])): ?> &middot; <?= $sz ?><?php endif; ?>
                        <?php if (!empty($d['uploader'])): ?> &middot; <?= e((string)$d['uploader']) ?><?php endif; ?>
                        &middot; <?= date('j M Y', strtotime((string)$d['created_at'])) ?>
                        <?php if (!empty($d['client_id'])): ?>
                        <span class="lead-doc-onfile" title="Also on the client's profile">
                            <i class="fa fa-user-check"></i> on file
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($d['notes'])): ?>
                    <div class="lead-doc-note"><?= e((string)$d['notes']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="lead-doc-acts">
                    <a href="<?= BASE_URL ?>/modules/crm/document_file.php?id=<?= (int)$d['id'] ?>"
                       class="lead-doc-btn" title="Download"><i class="fa fa-download"></i></a>
                    <?php if ($canWrite): ?>
                    <form method="POST" class="d-inline"
                          onsubmit="return confirm('Remove <?= e(addslashes((string)$d['title'])) ?>? The file is deleted.')">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="delete_lead_doc">
                        <input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
                        <button class="lead-doc-btn lead-doc-del" title="Remove"><i class="fa fa-trash"></i></button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($canWrite): ?>
        <form method="POST" enctype="multipart/form-data" class="lead-docs-add">
            <?= csrfField() ?>
            <input type="hidden" name="action"  value="upload_lead_doc">
            <input type="hidden" name="context" value="<?= e($context) ?>">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Document</label>
                    <select name="doc_type" class="form-select form-select-sm">
                        <?php foreach ($types as $k => $v): ?>
                        <option value="<?= e($k) ?>"<?= $k === leadDocsDefaultType($context) ? ' selected' : '' ?>>
                            <?= e($v) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">File <span class="text-muted fw-normal">(PDF or photo, max 10 MB)</span></label>
                    <input type="file" name="document" class="form-control form-control-sm" required
                           accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx,.xls,.xlsx,.csv,.txt">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Note <span class="text-muted fw-normal">(optional)</span></label>
                    <input type="text" name="notes" class="form-control form-control-sm"
                           placeholder="e.g. signed 12 Oct">
                </div>
                <div class="col-md-1 d-grid">
                    <button class="btn btn-sm btn-primary" title="Attach"><i class="fa fa-upload"></i></button>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </div>
    <?php
}

/** The document most likely to be attached against each part of the deal. */
function leadDocsDefaultType(string $context): string
{
    return [
        'reservation'  => 'sales_agreement',
        'credit'       => 'credit_agreement',
        'import_order' => 'import_order',
        'delivery'     => 'delivery_note',
    ][$context] ?? 'other';
}
