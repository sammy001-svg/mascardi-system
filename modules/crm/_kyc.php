<?php
/**
 * Asking a buyer for their KYC papers, and letting them send them in.
 *
 * A credit sale needs bank statements, payslips, an ID copy. Collecting them
 * meant WhatsApp: the buyer photographs a bank statement and sends it to
 * whichever salesperson they have the number of, and it sits in that person's
 * phone. Nobody else can find it, it is on a handset that leaves the building
 * every evening, and when the buyer asks what they have already sent, the
 * answer is a scroll through a chat.
 *
 * So: a link. The buyer opens it, sees exactly which papers are wanted, sends
 * them, and presses a button to say they are done. No account, because asking
 * somebody to register before they can hand over a bank statement is how you
 * end up back on WhatsApp.
 *
 * That makes the link the only lock on the door, and the door opens onto
 * somebody's financial records. So the link is not a lead id, it cannot be
 * counted through, it stops working on a date, it can be switched off, and it
 * accepts a limited number of files of limited types and sizes. It shows the
 * buyer their own first name and the papers being asked for, and nothing else:
 * not the price, not the credit terms, not what anyone has written on the lead.
 * If the link goes astray, what the finder learns is that somebody is buying a
 * car on credit from us — and that is the most the page can give away.
 *
 * What comes in lands in crm_lead_documents like every other document on the
 * deal, tagged 'kyc', so it appears on the agreement and follows the buyer to
 * their profile on delivery without any of that being written a second time.
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/_documents.php';

const KYC_DEFAULT_DAYS = 14;
const KYC_MAX_FILES    = 15;

/** What a yard normally asks a credit buyer for. */
function kycDocTypes(): array
{
    return [
        'bank_statement'   => ['Bank statement',        'Last 6 months, all pages'],
        'payslip'          => ['Payslip',               'Your last 3 months'],
        'id_copy'          => ['National ID or passport', 'Both sides, clearly readable'],
        'kra_pin'          => ['KRA PIN certificate',   'The certificate itself, not a screenshot of the portal'],
        'employment_letter'=> ['Letter from your employer', 'Confirming your position and salary'],
        'business_permit'  => ['Business permit',       'If you are self-employed'],
        'utility_bill'     => ['Proof of address',      'A utility bill in your name, within 3 months'],
        'other'            => ['Anything else we asked for', ''],
    ];
}

function kycSchema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    leadDocsSchema($db);
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS crm_kyc_requests (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            lead_id      INT          NOT NULL,
            agreement_id INT          NULL DEFAULT NULL,
            token        VARCHAR(64)  NOT NULL UNIQUE,
            required     TEXT         NULL,
            sent_to      VARCHAR(150) NULL,
            sent_at      DATETIME     NULL,
            expires_at   DATETIME     NOT NULL,
            submitted_at DATETIME     NULL,
            revoked_at   DATETIME     NULL,
            created_by   INT          NULL,
            created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_lead (lead_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) { /* already there */ }
}

/** The address a request is opened at. */
function kycLink(string $token): string
{
    return rtrim(BASE_URL, '/') . '/modules/crm/kyc.php?k=' . $token;
}

/**
 * Open a request against a lead.
 *
 * One live request per lead: asking twice should extend the same link rather
 * than leave two working, because the second would silently outlive whatever
 * reason there was for stopping the first.
 */
function kycCreate(PDO $db, int $leadId, ?int $agreementId, array $required,
                   string $email, int $days, int $userId): array
{
    kycSchema($db);
    if ($leadId <= 0) return ['ok' => false, 'error' => 'No lead to ask about.'];

    $required = array_values(array_intersect(array_keys(kycDocTypes()), $required));
    if (!$required) return ['ok' => false, 'error' => 'Choose at least one document to ask for.'];

    $days = max(1, min(90, $days ?: KYC_DEFAULT_DAYS));

    try {
        // Retire anything still open on this lead before opening a new one.
        $db->prepare("UPDATE crm_kyc_requests SET revoked_at = NOW()
                       WHERE lead_id = ? AND revoked_at IS NULL AND submitted_at IS NULL")
           ->execute([$leadId]);

        $token = bin2hex(random_bytes(20));
        $db->prepare("INSERT INTO crm_kyc_requests
                          (lead_id, agreement_id, token, required, sent_to, expires_at, created_by)
                      VALUES (?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ? DAY), ?)")
           ->execute([$leadId, $agreementId ?: null, $token, json_encode($required),
                      trim($email) ?: null, $days, $userId ?: null]);

        logActivity('create', 'crm_leads', $leadId,
            'KYC document request opened (' . count($required) . ' items, ' . $days . ' days)');

        return ['ok' => true, 'error' => '', 'token' => $token, 'link' => kycLink($token)];
    } catch (\Throwable $e) {
        error_log('kycCreate: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'The request could not be opened.'];
    }
}

/**
 * The request behind a token, if it is still good for anything.
 *
 * Expiry is decided by the database. PHP here runs on UTC and MySQL on EAT, so
 * comparing a stored timestamp against time() in PHP grants every link three
 * extra hours.
 */
function kycFind(PDO $db, string $token): ?array
{
    kycSchema($db);
    if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) return null;
    try {
        $st = $db->prepare("SELECT r.*, (r.expires_at < NOW()) AS is_expired,
                                   l.name AS lead_name, l.email AS lead_email
                              FROM crm_kyc_requests r
                              JOIN crm_leads l ON l.id = r.lead_id
                             WHERE r.token = ? LIMIT 1");
        $st->execute([$token]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
        error_log('kycFind: ' . $e->getMessage());
        return null;
    }
}

/** Why a request cannot be used, in words the buyer can act on. */
function kycUnusableReason(?array $req): string
{
    if (!$req)                              return 'This link is not valid.';
    if (!empty($req['revoked_at']))         return 'This link has been withdrawn.';
    if ((int)($req['is_expired'] ?? 0) === 1) return 'This link has expired.';
    return '';
}

/** What is still being asked for, and what has already arrived. */
function kycProgress(PDO $db, array $req): array
{
    $required = json_decode((string)($req['required'] ?? '[]'), true) ?: [];
    $have     = [];
    try {
        $st = $db->prepare("SELECT doc_type, COUNT(*) n FROM crm_lead_documents
                             WHERE lead_id = ? AND context = 'kyc' GROUP BY doc_type");
        $st->execute([(int)$req['lead_id']]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $have[(string)$r['doc_type']] = (int)$r['n'];
    } catch (\Throwable $e) { /* counts only */ }

    $out = [];
    foreach ($required as $k) {
        $out[$k] = ['label' => kycDocTypes()[$k][0] ?? $k,
                    'hint'  => kycDocTypes()[$k][1] ?? '',
                    'count' => $have[$k] ?? 0];
    }
    return $out;
}

/** Everything sent in against this request, for the buyer's own reassurance. */
function kycUploaded(PDO $db, int $leadId): array
{
    try {
        $st = $db->prepare("SELECT id, doc_type, title, file_name, file_size, created_at
                              FROM crm_lead_documents
                             WHERE lead_id = ? AND context = 'kyc'
                          ORDER BY id ASC");
        $st->execute([$leadId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Take a file from the buyer.
 *
 * Deliberately narrower than the staff uploader: a bank statement is a PDF or a
 * photograph, and nothing here needs to accept a spreadsheet or a Word file. A
 * shorter list is a smaller thing to be wrong about.
 */
function kycStore(PDO $db, array $req, string $docType, array $file): array
{
    kycSchema($db);

    $why = kycUnusableReason($req);
    if ($why !== '')                 return ['ok' => false, 'error' => $why];
    if (!empty($req['submitted_at'])) return ['ok' => false, 'error' => 'You have already submitted these documents.'];

    $leadId = (int)$req['lead_id'];
    if (count(kycUploaded($db, $leadId)) >= KYC_MAX_FILES) {
        return ['ok' => false, 'error' => 'That is as many files as this link accepts. '
                                        . 'If something is missing, please call us.'];
    }

    if (!array_key_exists($docType, kycDocTypes())) $docType = 'other';
    if (empty($file['name']))        return ['ok' => false, 'error' => 'Choose a file first.'];
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => ($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE
            ? 'That file is too large for our server. Try photographing it in two parts.'
            : 'The upload did not finish. Please try again.'];
    }

    $ext = strtolower(pathinfo(basename((string)$file['name']), PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf','jpg','jpeg','png','webp'], true)) {
        return ['ok' => false, 'error' => 'Please send a PDF or a photo (JPG or PNG).'];
    }
    if ((int)$file['size'] > 10 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'That file is over 10 MB. A photo taken on a phone is usually fine.'];
    }

    // Straight into the same store as every other document on the deal, so it
    // shows on the agreement and travels to the client profile on delivery
    // without any of that being written twice. uploaded_by stays null: nobody
    // signed in, and recording a staff id here would be a lie in the audit.
    $res = leadDocsStore($db, $leadId, 'kyc', $docType,
                         kycDocTypes()[$docType][0] ?? 'Document', 'Sent in by the client',
                         $file, 0);
    if (!$res['ok']) return $res;

    try {
        logActivity('create', 'crm_leads', $leadId,
            'KYC document received from the client: ' . (kycDocTypes()[$docType][0] ?? $docType));
    } catch (\Throwable $e) { /* not worth failing an upload over */ }

    return ['ok' => true, 'error' => ''];
}

/** The buyer says they are finished. */
function kycSubmit(PDO $db, array $req): array
{
    kycSchema($db);
    $why = kycUnusableReason($req);
    if ($why !== '') return ['ok' => false, 'error' => $why];

    $leadId = (int)$req['lead_id'];
    if (!kycUploaded($db, $leadId)) {
        return ['ok' => false, 'error' => 'Please send at least one document before submitting.'];
    }

    try {
        $db->prepare("UPDATE crm_kyc_requests SET submitted_at = NOW() WHERE id = ?")
           ->execute([(int)$req['id']]);
        logActivity('update', 'crm_leads', $leadId, 'Client submitted their KYC documents');

        // Tell the people who asked for them. A folder that fills up silently
        // is a folder nobody looks in.
        try {
            require_once __DIR__ . '/../../includes/dispatch.php';
            dispatchToRoles(['sales_manager','sales_officer','finance_manager','accountant'], 'lead', [
                'title'   => 'KYC documents received: ' . (string)($req['lead_name'] ?? 'a client'),
                'message' => count(kycUploaded($db, $leadId)) . ' document(s) sent in and waiting to be checked.',
                'link'    => BASE_URL . '/modules/crm/view_lead.php?id=' . $leadId . '#docs-kyc',
            ]);
        } catch (\Throwable $e) { /* the submission stands whether or not the bell rings */ }

        return ['ok' => true, 'error' => ''];
    } catch (\Throwable $e) {
        error_log('kycSubmit: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'That did not go through. Please try again.'];
    }
}

/** Email the link to the buyer. */
function kycSend(PDO $db, array $req, string $email = ''): array
{
    kycSchema($db);
    $to = trim($email) ?: trim((string)($req['sent_to'] ?? '')) ?: trim((string)($req['lead_email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'No valid email address for this client. Copy the link and send it yourself.'];
    }

    $company  = getSetting('company_name', 'Mascardi');
    $name     = trim((string)($req['lead_name'] ?? ''));
    $first    = $name !== '' ? explode(' ', $name)[0] : 'there';
    $link     = kycLink((string)$req['token']);
    $required = json_decode((string)($req['required'] ?? '[]'), true) ?: [];

    $list = '';
    foreach ($required as $k) {
        $t = kycDocTypes()[$k] ?? [$k, ''];
        $list .= '<li style="margin-bottom:6px"><strong>' . e($t[0]) . '</strong>'
               . ($t[1] !== '' ? ' <span style="color:#64748b">— ' . e($t[1]) . '</span>' : '')
               . '</li>';
    }

    $body = mailTemplate('Documents for your vehicle finance', '
        <p>Hello ' . e($first) . ',</p>
        <p>To finish setting up the finance on your vehicle, we need a few documents from you.
           You can send them straight to us using the button below — there is no account to
           create and no password to remember.</p>
        <p><strong>What we need:</strong></p>
        <ul style="padding-left:18px;margin:12px 0 20px">' . $list . '</ul>
        <p style="margin:26px 0">
          <a href="' . e($link) . '"
             style="background:#0f6b5c;color:#fff;text-decoration:none;padding:13px 26px;
                    border-radius:8px;font-weight:700;display:inline-block">Send my documents</a>
        </p>
        <p style="color:#64748b;font-size:13px">
          If the button does not work, copy this into your browser:<br>
          <span style="word-break:break-all">' . e($link) . '</span>
        </p>
        <p style="color:#64748b;font-size:13px">
          This link is personal to you — please do not forward it. It stops working on '
          . e(date('j F Y', strtotime((string)$req['expires_at']))) . '.
        </p>');

    $sent = sendMail($to, $name ?: $to, $company . ' — documents needed for your finance',
                     $body, 'kyc_request', (int)$req['lead_id']);

    if ($sent['ok']) {
        try {
            $db->prepare("UPDATE crm_kyc_requests SET sent_to = ?, sent_at = NOW() WHERE id = ?")
               ->execute([$to, (int)$req['id']]);
            logActivity('update', 'crm_leads', (int)$req['lead_id'], 'KYC request emailed to ' . $to);
        } catch (\Throwable $e) { /* it went; the note about it is secondary */ }
        return ['ok' => true, 'error' => '', 'to' => $to];
    }
    return ['ok' => false, 'error' => 'The email did not send: ' . (string)$sent['error']];
}

/** Switch a link off. */
function kycRevoke(PDO $db, int $id, int $leadId): array
{
    kycSchema($db);
    try {
        $st = $db->prepare("UPDATE crm_kyc_requests SET revoked_at = NOW()
                             WHERE id = ? AND lead_id = ? AND revoked_at IS NULL");
        $st->execute([$id, $leadId]);
        if ($st->rowCount()) {
            logActivity('update', 'crm_leads', $leadId, 'KYC link withdrawn');
            return ['ok' => true, 'error' => ''];
        }
        return ['ok' => false, 'error' => 'That link is not open.'];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'That did not work.'];
    }
}

/** The live request on a lead, if there is one. */
function kycCurrent(PDO $db, int $leadId): ?array
{
    kycSchema($db);
    try {
        $st = $db->prepare("SELECT *, (expires_at < NOW()) AS is_expired
                              FROM crm_kyc_requests
                             WHERE lead_id = ? AND revoked_at IS NULL
                          ORDER BY id DESC LIMIT 1");
        $st->execute([$leadId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * The staff-side panel: ask for papers, send the link, watch them arrive.
 *
 * Sits under the credit summary, because that is where somebody realises the
 * papers are needed. One live link at a time — two working links would mean
 * that withdrawing one achieved nothing.
 */
function kycPanel(PDO $db, int $leadId, ?int $agreementId, array $lead, bool $canWrite = true): void
{
    kycSchema($db);
    $cur      = kycCurrent($db, $leadId);
    $types    = kycDocTypes();
    $expired  = $cur && (int)($cur['is_expired'] ?? 0) === 1;
    $live     = $cur && !$expired && empty($cur['submitted_at']);
    $required = $cur ? (json_decode((string)$cur['required'], true) ?: []) : [];
    $got      = kycUploaded($db, $leadId);
    $leadMail = trim((string)($lead['email'] ?? ''));
    ?>
    <div class="lead-docs" id="kyc">
        <div class="lead-docs-head">
            <i class="fa fa-id-card me-2"></i>KYC documents from the client
            <?php if ($got): ?><span class="lead-docs-n"><?= count($got) ?></span><?php endif; ?>
        </div>

        <?php if ($cur && !empty($cur['submitted_at'])): ?>
        <div class="alert alert-success py-2" style="font-size:13px">
            <i class="fa fa-circle-check me-2"></i>
            The client submitted their documents on
            <?= date('j M Y \a\t H:i', strtotime((string)$cur['submitted_at'])) ?>.
            They are listed with the agreement below.
        </div>

        <?php elseif ($live): ?>
        <div class="kyc-live">
            <div class="kyc-live-head">
                <span><i class="fa fa-link me-2"></i>Link is open</span>
                <span class="kyc-live-exp">expires <?= date('j M Y', strtotime((string)$cur['expires_at'])) ?></span>
            </div>
            <div class="kyc-url">
                <input type="text" readonly id="kycUrl<?= $leadId ?>"
                       value="<?= e(kycLink((string)$cur['token'])) ?>" onclick="this.select()">
                <button type="button" class="lead-doc-btn kyc-copy"
                        data-target="kycUrl<?= $leadId ?>" title="Copy the link">
                    <i class="fa fa-copy"></i>
                </button>
            </div>
            <div class="kyc-asked">
                <span class="kyc-asked-l">Asked for:</span>
                <?php foreach ($required as $k): ?>
                <span class="kyc-chip<?= kycHasType($got, $k) ? ' in' : '' ?>">
                    <?php if (kycHasType($got, $k)): ?><i class="fa fa-check"></i><?php endif; ?>
                    <?= e($types[$k][0] ?? $k) ?>
                </span>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($cur['sent_at'])): ?>
            <div class="kyc-sent-note">
                <i class="fa fa-paper-plane me-1"></i>Emailed to <?= e((string)$cur['sent_to']) ?>
                on <?= date('j M Y', strtotime((string)$cur['sent_at'])) ?>
            </div>
            <?php endif; ?>
            <?php if ($canWrite): ?>
            <div class="kyc-acts">
                <form method="POST" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="kyc_resend">
                    <input type="hidden" name="email" value="<?= e($leadMail) ?>">
                    <button class="btn btn-sm btn-outline-primary"
                            <?= $leadMail === '' ? 'disabled title="No email address on this lead"' : '' ?>>
                        <i class="fa fa-paper-plane me-1"></i>Email it<?= !empty($cur['sent_at']) ? ' again' : '' ?>
                    </button>
                </form>
                <form method="POST" class="d-inline"
                      onsubmit="return confirm('Withdraw this link? The client will not be able to use it.')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="kyc_revoke">
                    <input type="hidden" name="request_id" value="<?= (int)$cur['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger"><i class="fa fa-ban me-1"></i>Withdraw</button>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <?php elseif ($canWrite): ?>
        <div class="lead-docs-empty">
            Send the client a link and they upload their bank statements and ID themselves &mdash;
            no account, no password. What arrives lands on this agreement and follows them to their
            profile when the car is delivered.
            <?php if ($expired): ?>
            <br><strong>The last link expired on <?= date('j M Y', strtotime((string)$cur['expires_at'])) ?>.</strong>
            <?php endif; ?>
        </div>
        <form method="POST" class="kyc-ask">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="kyc_request">
            <input type="hidden" name="agreement_id" value="<?= (int)$agreementId ?>">

            <div class="kyc-picks">
                <?php foreach ($types as $k => $t): if ($k === 'other') continue; ?>
                <label class="kyc-pick">
                    <input type="checkbox" name="required[]" value="<?= e($k) ?>"
                           <?= in_array($k, ['bank_statement','id_copy','kra_pin'], true) ? 'checked' : '' ?>>
                    <span>
                        <strong><?= e($t[0]) ?></strong>
                        <?php if ($t[1] !== ''): ?><em><?= e($t[1]) ?></em><?php endif; ?>
                    </span>
                </label>
                <?php endforeach; ?>
            </div>

            <div class="row g-2 align-items-end mt-1">
                <div class="col-md-5">
                    <label class="form-label">Email it to</label>
                    <input type="email" name="email" class="form-control form-control-sm"
                           value="<?= e($leadMail) ?>" placeholder="Leave blank and copy the link instead">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Link lasts</label>
                    <select name="days" class="form-select form-select-sm">
                        <option value="7">7 days</option>
                        <option value="14" selected>14 days</option>
                        <option value="30">30 days</option>
                    </select>
                </div>
                <div class="col-md-4 d-grid">
                    <button class="btn btn-sm btn-primary" name="send_email" value="1"
                            <?= $leadMail === '' ? 'disabled title="No email address on this lead"' : '' ?>>
                        <i class="fa fa-paper-plane me-1"></i>Create &amp; email
                    </button>
                </div>
                <div class="col-12">
                    <button class="btn btn-sm btn-outline-secondary w-100">
                        <i class="fa fa-link me-1"></i>Just create the link &mdash; I will send it myself
                    </button>
                </div>
            </div>
        </form>

        <?php else: ?>
        <div class="lead-docs-empty">No KYC request has been opened on this lead.</div>
        <?php endif; ?>
    </div>
    <?php
}

/** Has anything of this type arrived yet? */
function kycHasType(array $uploaded, string $type): bool
{
    foreach ($uploaded as $u) {
        if ((string)$u['doc_type'] === $type) return true;
    }
    return false;
}
