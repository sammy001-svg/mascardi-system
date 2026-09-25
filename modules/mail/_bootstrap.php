<?php
/**
 * Staff mail — the foundation the rest of the module stands on.
 *
 * The company's email lives on cPanel. This lets each person read and send
 * their own mail from inside the system, over IMAP and SMTP to that same
 * server, so nothing changes about where mail is actually kept. A message
 * read here is read in Outlook and on their phone too; nothing is copied
 * into this database.
 *
 * ONE ROW PER PERSON, READ ONLY FOR THAT PERSON.
 *
 * There is deliberately no screen, route or query anywhere that opens
 * somebody else's mailbox — not for an administrator either. Every entry
 * point builds the mailbox from the signed-in user's own id and nothing in
 * any request can name another. An administrator who needs a colleague's
 * mail can reset that mailbox's password in cPanel, which the colleague will
 * notice. Reading it quietly through here is not something this system does.
 *
 * The password is stored encrypted rather than hashed, because it has to be
 * handed to the mail server on every request — that is simply how IMAP works.
 * It is the one secret in the system that cannot be one-way, so it is kept
 * under a key that lives outside the database.
 */

require_once __DIR__ . '/../../includes/functions.php';

foreach (['AuthFailed', 'Literal', 'Tokenizer', 'Mime', 'HtmlSanitizer', 'ImapClient'] as $__c) {
    require_once __DIR__ . '/_lib/' . $__c . '.php';
}

if (!function_exists('mailKey')) {

// ── The key ──────────────────────────────────────────────────────────────────

/**
 * The encryption key, generated once and kept in a file.
 *
 * A key in the database would be kept beside the thing it protects, which
 * protects nothing. A key in a constant means somebody has to edit PHP before
 * they can save a password, and on a hand-copied deployment that step is the
 * one that never happens. So it is a file, made on first use.
 *
 * Losing the file has the same effect as changing the key: stored passwords
 * stop decrypting and have to be entered again. Nothing else breaks. A failure
 * to write it is fatal rather than quietly falling back to a per-request key,
 * which would encrypt secrets nobody could ever read back.
 */
function mailKey(): string
{
    static $key = null;
    if ($key !== null) return $key;

    if (defined('APP_KEY') && trim((string)APP_KEY) !== '') {
        return $key = hash('sha256', (string)APP_KEY, true);
    }

    $dir  = BASE_PATH . '/storage';
    $path = $dir . '/mail.key';

    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    if (is_file($path)) {
        $existing = trim((string)@file_get_contents($path));
        if ($existing !== '') return $key = hash('sha256', $existing, true);
    }

    $generated = bin2hex(random_bytes(32));

    // Exclusive create, so two requests arriving together cannot each write a
    // key and leave the loser's password unreadable.
    $h = @fopen($path, 'xb');
    if ($h === false) {
        $existing = trim((string)@file_get_contents($path));
        if ($existing !== '') return $key = hash('sha256', $existing, true);
        throw new RuntimeException('Could not create the mail encryption key at ' . $path
                                 . '. Make the storage folder writable.');
    }
    fwrite($h, $generated);
    fclose($h);
    @chmod($path, 0600);

    // Anything that can be reached over the web should not be readable over it.
    @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");

    return $key = hash('sha256', $generated, true);
}

function mailEncrypt(string $plain): string
{
    $iv  = random_bytes(12);
    $tag = '';
    $c   = openssl_encrypt($plain, 'aes-256-gcm', mailKey(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($c === false) throw new RuntimeException('The password could not be encrypted.');
    return 'enc:v1:' . base64_encode($iv . $tag . $c);
}

/** null when it cannot be decrypted — a rotated key, or a restored backup. */
function mailDecrypt(string $payload): ?string
{
    if (!str_starts_with($payload, 'enc:v1:')) return $payload;
    $raw = base64_decode(substr($payload, 7), true);
    if ($raw === false || strlen($raw) < 29) return null;
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', mailKey(),
                             OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? null : $plain;
}

// ── Schema ───────────────────────────────────────────────────────────────────

define('MAIL_SCHEMA_VERSION', '1');

/**
 * The table and its settings, made on first use like the rest of this system.
 *
 * DDL commits implicitly in MySQL, so this must never be called inside a
 * transaction — a lesson this codebase has already paid for once.
 */
function mailMigrate(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    if (getSetting('MAIL_SCHEMA_VERSION', '') === MAIL_SCHEMA_VERSION) return;

    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS mail_accounts (
              id            INT AUTO_INCREMENT PRIMARY KEY,
              user_id       INT NOT NULL,
              email         VARCHAR(190) NOT NULL,
              password_enc  TEXT         NOT NULL,
              display_name  VARCHAR(120) DEFAULT NULL,
              signature     TEXT         DEFAULT NULL,
              -- Found once and remembered: cPanel calls it INBOX.Sent and
              -- other servers call it Sent.
              sent_folder   VARCHAR(190) DEFAULT NULL,
              trash_folder  VARCHAR(190) DEFAULT NULL,
              last_ok_at    DATETIME     DEFAULT NULL,
              last_error    VARCHAR(255) DEFAULT NULL,
              created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uq_mail_accounts_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (\Throwable $e) { error_log('mailMigrate: ' . $e->getMessage()); }

    // The server, once for everybody. cPanel's usual arrangement: the same
    // host for both, IMAP on 993 and SMTP on 465, encrypted from the first byte.
    $defaults = [
        'mail_enabled'       => '1',
        'mail_imap_host'     => '',
        'mail_imap_port'     => '993',
        'mail_imap_security' => 'ssl',
        'mail_smtp_host'     => '',
        'mail_smtp_port'     => '465',
        'mail_smtp_security' => 'ssl',
        // cPanel's own ceiling is usually 50MB; staying under it avoids a send
        // the server then bounces back.
        'mail_max_attach_mb' => '20',
    ];
    try {
        $ins = $db->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?,?)");
        foreach ($defaults as $k => $v) $ins->execute([$k, $v]);
        $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('MAIL_SCHEMA_VERSION', ?)
                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
           ->execute([MAIL_SCHEMA_VERSION]);
    } catch (\Throwable $e) { error_log('mailMigrate settings: ' . $e->getMessage()); }
}

// ── Who may use it ───────────────────────────────────────────────────────────

/**
 * Anybody signed in may have a mailbox — their own.
 *
 * Not gated on a module permission the way the rest of the system is, because
 * there is nothing here to be granted access TO: the only mail anyone can
 * reach is their own, and a person who has a company address has it whatever
 * their role. The gate that matters is the one that does not exist, which is
 * any way to read somebody else's.
 */
function mailCanUse(): bool
{
    return authUser() !== null;
}

/** Is the company's mail server filled in at all? */
function mailServerReady(): bool
{
    return getSetting('mail_enabled', '1') === '1'
        && trim((string)getSetting('mail_imap_host', '')) !== ''
        && trim((string)getSetting('mail_smtp_host', '')) !== '';
}

} // function_exists('mailKey')
