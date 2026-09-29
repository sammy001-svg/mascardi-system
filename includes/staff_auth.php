<?php
/**
 * Staff who have forgotten their password.
 *
 * Until now the only way back in was to find somebody with an admin account
 * and ask them to type a new password in — which means the new password is
 * known to two people, and the person who needs it is locked out until the
 * other one is free. On a Sunday that is the rest of the day.
 *
 * This works the way the customer door already does, and deliberately so:
 * a code goes to the address on file and has to come back. The proof is that
 * you can read the mailbox, because that is the only thing we can check from
 * outside. Nothing about the account changes until the code returns, so
 * asking for a reset cannot itself be used to lock somebody out.
 *
 * Staff sign in with a username rather than an email, so the form takes
 * either — a person who has forgotten their password may well not remember
 * which of the two they were given.
 *
 * Two things are deliberately NOT said out loud:
 *
 *   whether the account exists, and
 *   whether it has an email address on file
 *
 * Both get the same answer as success. A reset form that says "no such user"
 * is a way to find out who works here, and one that says "no email on file"
 * tells an attacker which accounts cannot be recovered and are therefore
 * worth attacking directly. The wording covers both cases instead, and points
 * anyone genuinely stuck at an administrator.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mailer.php';

const STAFF_RESET_TTL_MINUTES = 30;
const STAFF_RESET_MAX_TRIES   = 6;

/** The holding table, added the way the rest of this codebase adds things. */
function staffResetSchema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS staff_password_resets (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            user_id     INT          NOT NULL UNIQUE,
            code_hash   VARCHAR(255) NOT NULL,
            expires_at  DATETIME     NOT NULL,
            tries       INT          NOT NULL DEFAULT 0,
            requested_ip VARCHAR(45) NULL,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) { /* already there */ }
}

/**
 * The account behind what they typed, whether that was a username or the
 * address we have for them. Only active accounts: somebody who has left
 * should not be able to let themselves back in.
 */
function staffResetFind(PDO $db, string $who): ?array
{
    $who = trim($who);
    if ($who === '') return null;

    $st = $db->prepare("SELECT * FROM users
                         WHERE status = 'active'
                           AND (username = ? OR (email IS NOT NULL AND email <> '' AND LOWER(email) = LOWER(?)))
                         LIMIT 1");
    $st->execute([$who, $who]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Step one: send a code to the address on file.
 *
 * The answer is the same whether the account exists, has no email, or the
 * mail itself failed — see the note at the top of this file. 'sent' comes
 * back for the caller's own logging, never for the screen.
 */
function staffResetStart(PDO $db, string $who): array
{
    staffResetSchema($db);

    $user = staffResetFind($db, $who);
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '';

    // Throttled on what they typed, so a script cannot walk a list of
    // usernames asking for a code against each one.
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM login_attempts
                             WHERE (username = ? OR ip_address = ?)
                               AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $st->execute(['reset:' . trim($who), $ip]);
        if ((int)$st->fetchColumn() >= 8) {
            return ['ok' => false, 'sent' => false,
                    'error' => 'Too many requests. Please wait a few minutes and try again.'];
        }
        $db->prepare("INSERT INTO login_attempts (username, ip_address) VALUES (?,?)")
           ->execute(['reset:' . trim($who), $ip]);
    } catch (\Throwable $e) { /* no attempts table yet — the login page makes it */ }

    if (!$user || trim((string)($user['email'] ?? '')) === '') {
        return ['ok' => true, 'sent' => false, 'error' => ''];
    }

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $db->prepare("INSERT INTO staff_password_resets (user_id, code_hash, expires_at, tries, requested_ip)
                  VALUES (?,?, DATE_ADD(NOW(), INTERVAL ? MINUTE), 0, ?)
                  ON DUPLICATE KEY UPDATE
                      code_hash = VALUES(code_hash), expires_at = VALUES(expires_at),
                      tries = 0, requested_ip = VALUES(requested_ip)")
       ->execute([(int)$user['id'], password_hash($code, PASSWORD_DEFAULT),
                  STAFF_RESET_TTL_MINUTES, $ip ?: null]);

    $company = getSetting('company_name', 'Mascardi');
    $body = mailTemplate('Reset your password', '
        <p>Hello ' . e((string)$user['name']) . ',</p>
        <p>Somebody asked to reset the password for <strong>' . e((string)$user['username'])
            . '</strong> on the ' . e($company) . ' system. Use this code to set a new one:</p>
        <p style="font-size:30px;font-weight:800;letter-spacing:7px;margin:22px 0;color:#0f6b5c">'
            . e($code) . '</p>
        <p>It is good for ' . STAFF_RESET_TTL_MINUTES . ' minutes.</p>
        <p style="color:#64748b;font-size:13px">If this was not you, ignore this message. Your
           password has not changed, and nobody can change it without the code above. If you keep
           getting these, tell an administrator — somebody knows your username.</p>');

    $sent = sendMail((string)$user['email'], (string)$user['name'],
                     'Reset your ' . $company . ' password', $body, 'staff_reset', (int)$user['id']);

    return ['ok' => true, 'sent' => (bool)$sent['ok'], 'error' => ''];
}

/**
 * Step two: the code comes back with the new password, and only then does
 * anything change.
 *
 * Taking both at once means there is no half-reset state to hold onto — no
 * "verified, now waiting" row that would itself be worth stealing.
 */
function staffResetComplete(PDO $db, string $who, string $code, string $password, string $confirm): array
{
    staffResetSchema($db);

    if (strlen($password) < 8)     return ['ok' => false, 'error' => 'Choose a password of at least 8 characters.'];
    if ($password !== $confirm)    return ['ok' => false, 'error' => 'The two passwords do not match.'];

    $user = staffResetFind($db, $who);
    $gone = ['ok' => false, 'error' => 'That code has expired. Ask for a new one.'];
    if (!$user) return $gone;

    // Expiry is decided by the database. PHP here runs on UTC and MySQL on
    // EAT, so comparing a stored timestamp against time() in PHP would quietly
    // grant every code three extra hours.
    $st = $db->prepare("SELECT *, (expires_at < NOW()) AS is_expired
                          FROM staff_password_resets WHERE user_id = ? LIMIT 1");
    $st->execute([(int)$user['id']]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return $gone;

    if ((int)$row['is_expired'] === 1) {
        $db->prepare("DELETE FROM staff_password_resets WHERE id = ?")->execute([$row['id']]);
        return $gone;
    }

    // Six digits is a million guesses, which is nothing to a script. The
    // counter is what makes the code worth anything.
    if ((int)$row['tries'] >= STAFF_RESET_MAX_TRIES) {
        $db->prepare("DELETE FROM staff_password_resets WHERE id = ?")->execute([$row['id']]);
        return ['ok' => false, 'error' => 'Too many wrong codes. Start again and we will send a new one.'];
    }

    if (!password_verify(trim($code), (string)$row['code_hash'])) {
        $db->prepare("UPDATE staff_password_resets SET tries = tries + 1 WHERE id = ?")->execute([$row['id']]);
        $left = STAFF_RESET_MAX_TRIES - ((int)$row['tries'] + 1);
        return ['ok' => false, 'error' => 'That code is not right.'
            . ($left > 0 ? ' ' . $left . ' ' . ($left === 1 ? 'try' : 'tries') . ' left.' : '')];
    }

    $db->prepare("UPDATE users SET password = ? WHERE id = ?")
       ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$user['id']]);
    $db->prepare("DELETE FROM staff_password_resets WHERE id = ?")->execute([$row['id']]);

    // Whoever reset it is the only one who should still be signed in. A
    // remembered browser somewhere else is exactly what a reset is for
    // getting rid of, so those tokens go with the old password.
    try {
        $db->prepare("DELETE FROM remember_tokens WHERE user_id = ?")->execute([(int)$user['id']]);
    } catch (\Throwable $e) { /* table may not exist on an old install */ }

    // Failed sign-ins from before the reset should not count against them.
    try {
        $db->prepare("DELETE FROM login_attempts WHERE username IN (?, ?)")
           ->execute([(string)$user['username'], 'reset:' . trim($who)]);
    } catch (\Throwable $e) { /* as above */ }

    try {
        logActivity('update', 'users', (int)$user['id'],
            'Password reset by email code from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    } catch (\Throwable $e) { /* the audit trail is not worth failing a reset over */ }

    return ['ok' => true, 'error' => '', 'username' => (string)$user['username']];
}
