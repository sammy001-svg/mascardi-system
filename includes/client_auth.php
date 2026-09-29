<?php
/**
 * The customer's own door into the system.
 *
 * Staff sign in with a username against `users`. A buyer signs in with the
 * email address we invoice them at, against `clients` — the same record their
 * cars, invoices, documents and statements already hang off. That record is
 * the reason this cannot simply take a password and let somebody in:
 *
 * By the time a buyer wants portal access they are usually ALREADY in the
 * clients table, put there when their lead was delivered. So "registering" is
 * really two different things wearing one coat — a brand-new person signing
 * up, and an existing customer claiming the record we already hold. If those
 * were told apart by nothing more than typing an email address, anyone who
 * knew a customer's address could claim their record and read their invoices.
 *
 * So nobody is let in on the strength of a typed email. A code goes to that
 * address and has to come back. Whoever can read the mailbox is the owner of
 * the record, whether the record existed already or is about to. One flow
 * therefore covers signing up, claiming an existing account, and resetting a
 * forgotten password, because from here all three are the same act.
 *
 * Until the code comes back nothing is written to `clients`. Pending sign-ups
 * wait in their own table, so a yard's customer book never fills up with
 * people who typed an address once and wandered off.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mailer.php';

const CLIENT_CODE_TTL_MINUTES = 30;
const CLIENT_CODE_MAX_TRIES   = 6;

/** Columns and the holding table, added the way the rest of this codebase does. */
function clientPortalSchema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS client_portal_signups (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            email         VARCHAR(150) NOT NULL UNIQUE,
            name          VARCHAR(150) NOT NULL,
            phone         VARCHAR(50)  NULL,
            password_hash VARCHAR(255) NOT NULL,
            code_hash     VARCHAR(255) NOT NULL,
            expires_at    DATETIME     NOT NULL,
            tries         INT          NOT NULL DEFAULT 0,
            sent_at       DATETIME     NULL,
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) { /* already there */ }

    foreach ([
        // Non-null means they came in through the portal door rather than
        // through a sale, so the customer book can tell the two apart.
        "ALTER TABLE clients ADD COLUMN portal_signup_at  DATETIME NULL DEFAULT NULL",
        "ALTER TABLE clients ADD COLUMN portal_verified_at DATETIME NULL DEFAULT NULL",
        "ALTER TABLE clients ADD COLUMN portal_last_login  DATETIME NULL DEFAULT NULL",
    ] as $sql) {
        try { $db->exec($sql); } catch (\Throwable $e) { /* already there */ }
    }
}

/**
 * May somebody who is not yet a customer open an account at all?
 *
 * A yard that only wants its actual buyers in there turns this off, and then
 * the door still opens for anyone already on file — it just will not create
 * anybody new. Default is on, because a buyer who has paid a deposit and is
 * not yet in the book should not be turned away.
 */
function clientPortalSelfSignup(): bool
{
    return getSetting('client_portal_signup', '1') === '1';
}

/** Is the customer door open at all? */
function clientPortalEnabled(): bool
{
    return getSetting('client_portal_enabled', '1') === '1';
}

/** A normalised email, or '' if it is not one. */
function clientPortalEmail(string $raw): string
{
    $e = strtolower(trim($raw));
    return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
}

/** The client on file for this address, or null. */
function clientPortalFind(PDO $db, string $email): ?array
{
    $st = $db->prepare("SELECT * FROM clients WHERE LOWER(email) = ? AND status = 'active' LIMIT 1");
    $st->execute([$email]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Step one: take what they typed, park it, and send a code to the address.
 *
 * The answer is deliberately the same whether or not we have ever heard of
 * that address. Telling a stranger "no such customer" hands them a way to
 * find out who banks here, one address at a time.
 */
function clientPortalStart(PDO $db, string $name, string $email, string $phone, string $password): array
{
    clientPortalSchema($db);

    $email = clientPortalEmail($email);
    if ($email === '')            return ['ok' => false, 'error' => 'Enter a valid email address.'];
    if (strlen($password) < 8)    return ['ok' => false, 'error' => 'Choose a password of at least 8 characters.'];

    $existing = clientPortalFind($db, $email);
    $name     = trim($name) ?: (string)($existing['name'] ?? '');
    if ($name === '')             return ['ok' => false, 'error' => 'Enter your name.'];

    // Nothing on file and the yard is not taking new sign-ups. Still answered
    // the same way, so this cannot be used to probe who is a customer.
    if (!$existing && !clientPortalSelfSignup()) {
        return ['ok' => true, 'sent' => false, 'email' => $email];
    }

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $db->prepare("INSERT INTO client_portal_signups
                      (email, name, phone, password_hash, code_hash, expires_at, tries, sent_at)
                  VALUES (?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ? MINUTE), 0, NOW())
                  ON DUPLICATE KEY UPDATE
                      name = VALUES(name), phone = VALUES(phone),
                      password_hash = VALUES(password_hash), code_hash = VALUES(code_hash),
                      expires_at = VALUES(expires_at), tries = 0, sent_at = NOW()")
       ->execute([$email, $name, trim($phone) ?: null,
                  password_hash($password, PASSWORD_DEFAULT),
                  password_hash($code, PASSWORD_DEFAULT),
                  CLIENT_CODE_TTL_MINUTES]);

    $company = getSetting('company_name', 'Mascardi');
    $body = mailTemplate('Your access code', '
        <p>Hello ' . e($name) . ',</p>
        <p>Use this code to finish setting up your ' . e($company) . ' account:</p>
        <p style="font-size:30px;font-weight:800;letter-spacing:7px;margin:22px 0;color:#0f6b5c">'
            . e($code) . '</p>
        <p>It is good for ' . CLIENT_CODE_TTL_MINUTES . ' minutes.</p>
        <p style="color:#64748b;font-size:13px">If you did not ask for this, you can ignore this
           message — nothing has changed on your account, and nobody can use the code without it.</p>');

    $sent = sendMail($email, $name, 'Your ' . $company . ' access code', $body, 'client_portal', 0);

    return ['ok' => true, 'sent' => (bool)$sent['ok'], 'email' => $email,
            'error' => $sent['ok'] ? '' : (string)$sent['error']];
}

/**
 * Step two: the code comes back, and only now does anything reach `clients`.
 *
 * An address already on file has its portal access set on the record that is
 * already there — the buyer keeps their cars, invoices and history rather
 * than starting again beside themselves as a duplicate.
 */
function clientPortalVerify(PDO $db, string $email, string $code): array
{
    clientPortalSchema($db);

    $email = clientPortalEmail($email);
    if ($email === '') return ['ok' => false, 'error' => 'Enter a valid email address.'];

    // Whether the code has expired is decided by the database, not by PHP.
    // PHP here runs on UTC and MySQL on EAT, so comparing a stored timestamp
    // against time() in PHP quietly grants every code three extra hours.
    $st = $db->prepare("SELECT *, (expires_at < NOW()) AS is_expired
                           FROM client_portal_signups WHERE email = ? LIMIT 1");
    $st->execute([$email]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) return ['ok' => false, 'error' => 'That code has expired. Ask for a new one.'];

    if ((int)$row['is_expired'] === 1) {
        $db->prepare("DELETE FROM client_portal_signups WHERE id = ?")->execute([$row['id']]);
        return ['ok' => false, 'error' => 'That code has expired. Ask for a new one.'];
    }

    // Six digits is a million guesses, which is nothing to a script. The
    // counter is what makes the code worth anything.
    if ((int)$row['tries'] >= CLIENT_CODE_MAX_TRIES) {
        $db->prepare("DELETE FROM client_portal_signups WHERE id = ?")->execute([$row['id']]);
        return ['ok' => false, 'error' => 'Too many wrong codes. Start again and we will send a new one.'];
    }

    if (!password_verify(trim($code), (string)$row['code_hash'])) {
        $db->prepare("UPDATE client_portal_signups SET tries = tries + 1 WHERE id = ?")->execute([$row['id']]);
        $left = CLIENT_CODE_MAX_TRIES - ((int)$row['tries'] + 1);
        return ['ok' => false, 'error' => 'That code is not right.'
            . ($left > 0 ? ' ' . $left . ' ' . ($left === 1 ? 'try' : 'tries') . ' left.' : '')];
    }

    $existing = clientPortalFind($db, $email);

    if ($existing) {
        $db->prepare("UPDATE clients
                         SET portal_password = ?, portal_enabled = 1, portal_verified_at = NOW(),
                             phone = COALESCE(NULLIF(phone,''), ?), updated_at = NOW()
                       WHERE id = ?")
           ->execute([$row['password_hash'], $row['phone'], $existing['id']]);
        $clientId = (int)$existing['id'];
        $name     = (string)$existing['name'];
    } else {
        if (!clientPortalSelfSignup()) {
            return ['ok' => false, 'error' => 'We could not find an account for that address. Please contact us.'];
        }
        $db->prepare("INSERT INTO clients (name, email, phone, portal_password, portal_enabled,
                          status, portal_signup_at, portal_verified_at)
                      VALUES (?,?,?,?,1,'active',NOW(),NOW())")
           ->execute([$row['name'], $email, $row['phone'], $row['password_hash']]);
        $clientId = (int)$db->lastInsertId();
        $name     = (string)$row['name'];
    }

    $db->prepare("DELETE FROM client_portal_signups WHERE id = ?")->execute([$row['id']]);

    try {
        logActivity('create', 'clients', $clientId,
            $existing ? 'Client claimed portal access' : 'Client registered through the portal');
    } catch (\Throwable $e) { /* activity log is not worth failing a sign-up over */ }

    return ['ok' => true, 'client' => ['id' => $clientId, 'name' => $name, 'email' => $email]];
}

/**
 * The everyday sign-in.
 *
 * Throttled through the same login_attempts table the staff door uses, so a
 * script cannot sit on one address trying passwords. A wrong email and a
 * wrong password give the same answer, for the same reason as above.
 */
function clientPortalLogin(PDO $db, string $email, string $password): array
{
    clientPortalSchema($db);

    $email = clientPortalEmail($email);
    $ip    = $_SERVER['REMOTE_ADDR'] ?? '';
    $wrong = ['ok' => false, 'error' => 'Those details did not match an account.'];

    if ($email === '') return $wrong;

    try {
        $st = $db->prepare("SELECT COUNT(*) FROM login_attempts
                             WHERE (username = ? OR ip_address = ?)
                               AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $st->execute([$email, $ip]);
        if ((int)$st->fetchColumn() >= 8) {
            return ['ok' => false, 'error' => 'Too many attempts. Please wait a few minutes and try again.'];
        }
    } catch (\Throwable $e) { /* no attempts table yet — the staff door makes it */ }

    $client = clientPortalFind($db, $email);

    if (!$client || empty($client['portal_password']) || (int)$client['portal_enabled'] !== 1
        || !password_verify($password, (string)$client['portal_password'])) {
        try {
            $db->prepare("INSERT INTO login_attempts (username, ip_address) VALUES (?,?)")->execute([$email, $ip]);
        } catch (\Throwable $e) { /* as above */ }
        return $wrong;
    }

    try {
        $db->prepare("DELETE FROM login_attempts WHERE username = ? OR ip_address = ?")->execute([$email, $ip]);
        $db->prepare("UPDATE clients SET portal_last_login = NOW() WHERE id = ?")->execute([$client['id']]);
    } catch (\Throwable $e) { /* neither is worth failing a good sign-in over */ }

    return ['ok' => true, 'client' => ['id' => (int)$client['id'],
                                       'name' => (string)$client['name'],
                                       'email' => (string)$client['email']]];
}

/** Put the customer in session, on their own key so staff sessions never mix. */
function clientPortalSignIn(array $client): void
{
    session_regenerate_id(true);
    $_SESSION['_client'] = ['id' => (int)$client['id'],
                            'name' => (string)$client['name'],
                            'email' => (string)$client['email']];
}
