<?php
/**
 * Lightweight SMTP mailer — no external dependencies.
 * Configure via Settings → Email in the admin panel.
 */

function sendMail(string $toEmail, string $toName, string $subject, string $htmlBody, string $refType = '', int $refId = 0): array {
    $host     = getSetting('smtp_host', '');
    $port     = (int)(getSetting('smtp_port', '587') ?: 587);
    $user     = getSetting('smtp_user', '');
    $pass     = getSetting('smtp_pass', '');
    $from     = getSetting('smtp_from_email', '');
    $fromName = getSetting('smtp_from_name', getSetting('company_name', 'Mascardi System'));
    $enc      = getSetting('smtp_encryption', 'tls');

    $result = ['ok' => false, 'error' => ''];

    if (!$from) {
        $result['error'] = 'Email not configured. Go to Settings → Email to set up SMTP.';
        _logEmail($toEmail, $toName, $subject, 'failed', $result['error'], $refType, $refId);
        return $result;
    }

    try {
        $socketHost = ($enc === 'ssl') ? "ssl://{$host}" : $host;
        $socket     = @fsockopen($socketHost, $port, $errno, $errstr, 15);
        if (!$socket) {
            throw new \RuntimeException("Cannot connect to {$host}:{$port} — {$errstr} ({$errno})");
        }

        $read = function () use ($socket): string {
            $out = '';
            while ($line = fgets($socket, 515)) {
                $out .= $line;
                if (substr($line, 3, 1) === ' ') break;
            }
            return $out;
        };
        $cmd = function (string $c) use ($socket): void {
            fputs($socket, $c . "\r\n");
        };

        $read(); // 220 banner

        // Use the actual server hostname — sending 'EHLO localhost' causes many
        // mail servers (e.g. cPanel/Exim) to reject or defer the connection with
        // a 451 because 'localhost' signals a misconfigured/untrusted sender.
        $ehloHost = gethostname() ?: ($_SERVER['SERVER_NAME'] ?? 'mail.mailer');
        $cmd("EHLO {$ehloHost}");
        $read();

        if ($enc === 'tls') {
            $cmd("STARTTLS");
            $read();
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $cmd("EHLO {$ehloHost}");
            $read();
        }

        if ($user) {
            $cmd("AUTH LOGIN");
            $read();
            $cmd(base64_encode($user));
            $read();
            $cmd(base64_encode($pass));
            $authResp = $read();
            if (strpos($authResp, '235') === false) {
                throw new \RuntimeException('SMTP auth failed: ' . trim($authResp));
            }
        }

        $cmd("MAIL FROM: <{$from}>");
        $mailFromResp = $read();
        if (strpos($mailFromResp, '250') === false) {
            throw new \RuntimeException('MAIL FROM rejected: ' . trim($mailFromResp));
        }
        $cmd("RCPT TO: <{$toEmail}>");
        $rcpt = $read();
        if (strpos($rcpt, '250') === false) {
            throw new \RuntimeException('RCPT rejected: ' . trim($rcpt));
        }

        $cmd("DATA");
        $read();

        $msg  = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>\r\n";
        $msg .= "To: =?UTF-8?B?" . base64_encode($toName) . "?= <{$toEmail}>\r\n";
        $msg .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n";
        $msg .= "Date: " . date('r') . "\r\n";
        $msg .= "\r\n";
        $msg .= chunk_split(base64_encode($htmlBody)) . "\r\n";
        $msg .= ".\r\n";

        fputs($socket, $msg);
        $dataResp = $read();
        $cmd("QUIT");
        fclose($socket);

        if (strpos($dataResp, '250') !== false) {
            $result['ok'] = true;
            _logEmail($toEmail, $toName, $subject, 'sent', '', $refType, $refId, $htmlBody);
        } else {
            throw new \RuntimeException('DATA rejected: ' . trim($dataResp));
        }
    } catch (\Throwable $e) {
        $result['error'] = $e->getMessage();
        _logEmail($toEmail, $toName, $subject, 'failed', $result['error'], $refType, $refId, $htmlBody);
    }

    return $result;
}

function ensureEmailLogsTable(PDO $db): void {
    static $healed = false;
    if ($healed) return;
    $healed = true;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS email_logs (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            to_email       VARCHAR(255) NOT NULL,
            to_name        VARCHAR(255) NULL,
            subject        VARCHAR(500) NULL,
            body           LONGTEXT NULL,
            status         VARCHAR(20) NOT NULL DEFAULT 'sent',
            error_message  TEXT NULL,
            reference_type VARCHAR(50) NULL,
            reference_id   INT NULL,
            sent_by        VARCHAR(100) NULL,
            created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_ref    (reference_type, reference_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Heal existing table columns if created with old/different types
        $cols = $db->query("SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_logs'")->fetchAll(PDO::FETCH_KEY_PAIR);

        if (isset($cols['sent_by']) && strtolower($cols['sent_by']) === 'int') {
            $db->exec("ALTER TABLE email_logs MODIFY COLUMN sent_by VARCHAR(100) NULL");
        }
        if (isset($cols['status']) && strtolower($cols['status']) === 'enum') {
            $db->exec("ALTER TABLE email_logs MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'sent'");
        }
        if (!isset($cols['body'])) {
            $db->exec("ALTER TABLE email_logs ADD COLUMN body LONGTEXT NULL AFTER subject");
        }
        if (!isset($cols['to_name'])) {
            $db->exec("ALTER TABLE email_logs ADD COLUMN to_name VARCHAR(255) NULL AFTER to_email");
        }
        if (!isset($cols['reference_type'])) {
            $db->exec("ALTER TABLE email_logs ADD COLUMN reference_type VARCHAR(50) NULL AFTER error_message");
        }
        if (!isset($cols['reference_id'])) {
            $db->exec("ALTER TABLE email_logs ADD COLUMN reference_id INT NULL AFTER reference_type");
        }
    } catch (\Throwable $e) {
        error_log('[ensureEmailLogsTable] ' . $e->getMessage());
    }
}

function _logEmail(string $to, string $toName, string $subject, string $status, string $error, string $refType, int $refId, string $body = ''): void {
    try {
        $db = getDB();
        ensureEmailLogsTable($db);
        $auth = function_exists('authUser') ? authUser() : null;
        $by   = $auth ? $auth['name'] : 'system';
        $db->prepare("INSERT INTO email_logs (to_email, to_name, subject, body, status, error_message, reference_type, reference_id, sent_by) VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$to, $toName ?: null, $subject ?: null, $body ?: null, $status, $error ?: null, $refType ?: null, $refId ?: null, $by]);
    } catch (\Throwable $e) {
        error_log('[logEmail] Failed to record email log: ' . $e->getMessage());
    }
}

function mailTemplate(string $title, string $body): string {
    $company = getSetting('company_name', 'Mascardi System');
    $addr    = getSetting('company_address', '');
    $phone   = getSetting('company_phone', '');
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
body{font-family:Inter,Arial,sans-serif;background:#f8fafc;margin:0;padding:0}
.wrap{max-width:600px;margin:32px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,.08)}
.hdr{background:#2563eb;color:#fff;padding:28px 32px}.hdr h2{margin:0;font-size:20px}.hdr p{margin:4px 0 0;opacity:.8;font-size:13px}
.body{padding:32px}.body h3{color:#1e293b;margin-top:0}
.ftr{background:#f1f5f9;padding:20px 32px;font-size:12px;color:#64748b;text-align:center}
table.data{width:100%;border-collapse:collapse;margin:16px 0}
table.data th{background:#f8fafc;padding:10px 12px;text-align:left;font-size:12px;color:#64748b;border-bottom:1px solid #e2e8f0}
table.data td{padding:10px 12px;border-bottom:1px solid #f1f5f9;font-size:13px}
.badge{display:inline-block;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:600}
.total-row td{font-weight:700;font-size:15px;color:#2563eb}
.btn{display:inline-block;padding:12px 24px;background:#2563eb;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;font-size:14px;margin-top:16px}
</style></head><body>
<div class="wrap">
  <div class="hdr"><h2>' . htmlspecialchars($company) . '</h2><p>' . htmlspecialchars($addr . ($phone ? ' | ' . $phone : '')) . '</p></div>
  <div class="body"><h3>' . htmlspecialchars($title) . '</h3>' . $body . '</div>
  <div class="ftr">&copy; ' . date('Y') . ' ' . htmlspecialchars($company) . '. This is an automated message.</div>
</div></body></html>';
}
