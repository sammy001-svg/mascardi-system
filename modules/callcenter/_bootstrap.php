<?php
/**
 * Call Centre — schema, provider client and shared helpers.
 *
 * Telephony provider: Africa's Talking, the same account the system already
 * uses for SMS (at_api_key / at_username in Settings → Messaging). Chosen
 * because it is already configured, bills in KES — which is what "airtime"
 * means here — and offers browser calling over WebRTC, which is what makes
 * "call from the laptop" possible without installing a softphone.
 *
 * How a call actually happens
 * ---------------------------
 * Outbound: the browser holds the audio. It fetches a short-lived capability
 * token from api/token.php, connects to the provider over WebRTC, and dials.
 * Audio never passes through this server.
 *
 * Inbound: a client rings the shared number. The provider POSTs to
 * api/voice_callback.php, which answers with XML telling it which agent
 * to ring. Agents are rung by their client name, so the call lands in
 * whichever browser they have the dialer open in.
 *
 * The shared number is the caller ID every agent presents, so a client
 * calling back reaches the call centre rather than one person's mobile.
 */

if (!function_exists('ccMigrate')) {

if (!defined('CC_SCHEMA_VERSION')) define('CC_SCHEMA_VERSION', '1');

function ccCallStatuses(): array {
    return [
        'queued'    => ['Queued',    '#64748b'],
        'ringing'   => ['Ringing',   '#f59e0b'],
        'active'    => ['In Call',   '#16a34a'],
        'completed' => ['Completed', '#2563eb'],
        'missed'    => ['Missed',    '#dc2626'],
        'failed'    => ['Failed',    '#dc2626'],
        'busy'      => ['Busy',      '#b45309'],
        'no_answer' => ['No Answer', '#94a3b8'],
    ];
}

function ccAgentStates(): array {
    return [
        'offline'   => ['Offline',   '#94a3b8'],
        'available' => ['Available', '#16a34a'],
        'busy'      => ['On a Call', '#f59e0b'],
        'paused'    => ['Paused',    '#64748b'],
    ];
}

function ccMigrate(PDO $db, bool $force = false): void
{
    static $done = false;
    if ($done && !$force) return;
    $done = true;

    if (!$force) {
        try {
            $st = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'cc_schema_version'");
            $st->execute();
            if ((string)$st->fetchColumn() === CC_SCHEMA_VERSION) return;
        } catch (\Throwable $_) {}
    }

    $tables = [
        // Every call, in or out. This is the record of who spoke to whom, so
        // rows are written on first sight and updated as the call progresses
        // rather than only on completion — a call that never connects still
        // has to be visible.
        "CREATE TABLE IF NOT EXISTS call_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(120) NULL,
            direction ENUM('outbound','inbound') NOT NULL,
            agent_id INT NULL,
            client_id INT NULL,
            lead_id INT NULL,
            from_number VARCHAR(32) NULL,
            to_number VARCHAR(32) NULL,
            caller_id VARCHAR(32) NULL,
            status ENUM('queued','ringing','active','completed','missed','failed','busy','no_answer')
                   NOT NULL DEFAULT 'queued',
            duration_sec INT NOT NULL DEFAULT 0,
            cost_amount DECIMAL(12,4) NULL,
            cost_currency VARCHAR(8) NULL,
            recording_url VARCHAR(500) NULL,
            hangup_cause VARCHAR(80) NULL,
            notes TEXT NULL,
            started_at DATETIME NULL,
            answered_at DATETIME NULL,
            ended_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_session (session_id),
            KEY idx_cl_agent (agent_id, created_at),
            KEY idx_cl_dir (direction, status),
            KEY idx_cl_when (created_at),
            KEY idx_cl_client (client_id),
            KEY idx_cl_to (to_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Who is signed in to take calls. client_name is the identity the
        // provider rings for an inbound call; it must be stable per user and
        // safe to put in a URL, so it is derived rather than free text.
        "CREATE TABLE IF NOT EXISTS call_agents (
            user_id INT NOT NULL PRIMARY KEY,
            client_name VARCHAR(64) NOT NULL,
            state ENUM('offline','available','busy','paused') NOT NULL DEFAULT 'offline',
            current_call_id INT NULL,
            calls_today INT NOT NULL DEFAULT 0,
            last_seen DATETIME NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_client_name (client_name),
            KEY idx_ca_state (state, last_seen)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Airtime ledger. The provider holds the real balance; this records
        // what was paid in and by whom, so spend can be reconciled against
        // the account without logging in to the provider.
        "CREATE TABLE IF NOT EXISTS call_topups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            amount DECIMAL(12,2) NOT NULL,
            currency VARCHAR(8) NOT NULL DEFAULT 'KES',
            reference VARCHAR(120) NULL,
            method VARCHAR(60) NULL,
            balance_after DECIMAL(12,2) NULL,
            note VARCHAR(255) NULL,
            recorded_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_tu_when (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($tables as $sql) { try { $db->exec($sql); } catch (\Throwable $_) {} }

    try {
        $db->prepare("INSERT INTO settings (setting_key, setting_value)
                      VALUES ('cc_schema_version', ?)
                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
           ->execute([CC_SCHEMA_VERSION]);
    } catch (\Throwable $_) {}
}

// ── Configuration ────────────────────────────────────────────────────────────

function ccConfig(): array
{
    return [
        // Reuses the SMS credentials — same Africa's Talking account.
        'username'      => getSetting('at_username', ''),
        'api_key'       => getSetting('at_api_key', ''),
        // The shared number every agent calls from and clients call back on.
        'caller_id'     => getSetting('cc_caller_id', ''),
        'enabled'       => getSetting('cc_enabled', '0') === '1',
        'low_balance'   => (float)getSetting('cc_low_balance', '500'),
        'rate_per_min'  => (float)getSetting('cc_rate_per_min', '3.5'),
        'record_calls'  => getSetting('cc_record_calls', '1') === '1',
        'ring_seconds'  => max(10, (int)getSetting('cc_ring_seconds', '25')),
        // Endpoints are configurable so a provider change of host does not
        // need a code edit.
        'token_url'     => getSetting('cc_token_url', 'https://webrtc.africastalking.com/capability-token/request'),
        'voice_url'     => getSetting('cc_voice_url', 'https://voice.africastalking.com/call'),
        'balance_url'   => getSetting('cc_balance_url', 'https://api.africastalking.com/version1/user'),
        'sandbox'       => getSetting('cc_sandbox', '0') === '1',
    ];
}

function ccSetSetting(PDO $db, string $key, string $value): void
{
    try {
        $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?)
                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
           ->execute([$key, $value]);
    } catch (\Throwable $e) { error_log('ccSetSetting: ' . $e->getMessage()); }
}

/** True when enough is configured for a call to have any chance of connecting. */
function ccReady(?array $cfg = null): array
{
    $cfg = $cfg ?? ccConfig();
    $missing = [];
    if ($cfg['username'] === '')  $missing[] = 'Africa\'s Talking username';
    if ($cfg['api_key'] === '')   $missing[] = 'API key';
    if ($cfg['caller_id'] === '') $missing[] = 'shared call-centre number';
    if (!$cfg['enabled'])         $missing[] = 'the module is switched off in settings';
    return ['ok' => !$missing, 'missing' => $missing];
}

// ── Phone numbers ────────────────────────────────────────────────────────────

/**
 * Kenyan numbers to E.164. Returns '' when it cannot be made sense of, so a
 * malformed number is refused up front rather than burning airtime on a call
 * that was never going to connect.
 */
function ccNormalizeNumber(string $raw): string
{
    $n = preg_replace('/[^\d+]/', '', trim($raw));
    if ($n === '') return '';
    if (str_starts_with($n, '+')) {
        return preg_match('/^\+\d{7,15}$/', $n) ? $n : '';
    }
    // 07xx / 01xx local mobile
    if (preg_match('/^0[17]\d{8}$/', $n))  return '+254' . substr($n, 1);
    // 2547xx already international, missing the plus
    if (preg_match('/^254\d{9}$/', $n))    return '+' . $n;
    // 7xx / 1xx without the leading zero
    if (preg_match('/^[17]\d{8}$/', $n))   return '+254' . $n;
    return preg_match('/^\d{7,15}$/', $n) ? '+' . $n : '';
}

/** Display form: +254712345678 → 0712 345 678 */
function ccPrettyNumber(?string $e164): string
{
    $e164 = (string)$e164;
    if (preg_match('/^\+254(\d{3})(\d{3})(\d{3})$/', $e164, $m)) {
        return '0' . $m[1] . ' ' . $m[2] . ' ' . $m[3];
    }
    return $e164 ?: '—';
}

/**
 * The identity the provider rings for this agent's browser. Derived from the
 * user id so it never collides and survives a name change; restricted to
 * characters the provider accepts in a client name.
 */
function ccClientName(int $userId): string
{
    return 'agent' . $userId;
}

// ── Provider calls ───────────────────────────────────────────────────────────

/** Shared cURL wrapper. Never throws — callers get ['ok'=>bool,...]. */
function ccHttp(string $url, array $fields = [], array $headers = [], string $method = 'POST'): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'cURL is not available on this server.'];
    }
    $ch = curl_init($method === 'GET' && $fields ? $url . '?' . http_build_query($fields) : $url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($fields);
    }
    curl_setopt_array($ch, $opts);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err !== '') return ['ok' => false, 'error' => $err, 'http' => 0];
    $json = json_decode((string)$raw, true);
    return [
        'ok'   => $code >= 200 && $code < 300,
        'http' => $code,
        'json' => is_array($json) ? $json : null,
        'raw'  => (string)$raw,
    ];
}

/**
 * Current account balance, e.g. ['ok'=>true,'amount'=>1234.5,'currency'=>'KES'].
 * Cached briefly — the dashboard and the dialer both want it, and it is a
 * remote call.
 */
function ccBalance(bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;

    $cfg = ccConfig();
    if ($cfg['username'] === '' || $cfg['api_key'] === '') {
        return $cache = ['ok' => false, 'error' => 'Africa\'s Talking credentials are not set.'];
    }

    $res = ccHttp($cfg['balance_url'], ['username' => $cfg['username']],
                  ['apiKey: ' . $cfg['api_key']], 'GET');
    if (!$res['ok']) {
        return $cache = ['ok' => false, 'error' => $res['error'] ?? ('Provider returned HTTP ' . ($res['http'] ?? '?'))];
    }

    // Documented shape: {"UserData":{"balance":"KES 1234.5000"}}
    $bal = $res['json']['UserData']['balance'] ?? '';
    if (preg_match('/([A-Z]{3})\s*([\d.]+)/', (string)$bal, $m)) {
        return $cache = ['ok' => true, 'currency' => $m[1], 'amount' => (float)$m[2], 'raw' => $bal];
    }
    return $cache = ['ok' => false, 'error' => 'Could not read the balance from the provider response.', 'raw' => $bal];
}

/** Roughly how many minutes the remaining balance buys, at the configured rate. */
function ccEstimatedMinutes(?array $balance = null, ?array $cfg = null): ?int
{
    $cfg = $cfg ?? ccConfig();
    $balance = $balance ?? ccBalance();
    if (empty($balance['ok']) || $cfg['rate_per_min'] <= 0) return null;
    return (int)floor($balance['amount'] / $cfg['rate_per_min']);
}

// ── Call log helpers ─────────────────────────────────────────────────────────

/** Creates or updates a call row keyed on the provider's session id. */
function ccUpsertCall(PDO $db, array $data): int
{
    $session = $data['session_id'] ?? null;
    try {
        if ($session) {
            $st = $db->prepare("SELECT id FROM call_logs WHERE session_id = ?");
            $st->execute([$session]);
            if ($existing = (int)$st->fetchColumn()) {
                $sets = []; $args = [];
                foreach ($data as $k => $v) {
                    if ($k === 'session_id') continue;
                    $sets[] = "`{$k}` = ?"; $args[] = $v;
                }
                if ($sets) {
                    $args[] = $existing;
                    $db->prepare("UPDATE call_logs SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);
                }
                return $existing;
            }
        }
        $cols = array_keys($data);
        $db->prepare("INSERT INTO call_logs (`" . implode('`,`', $cols) . "`) VALUES ("
                     . implode(',', array_fill(0, count($cols), '?')) . ")")
           ->execute(array_values($data));
        return (int)$db->lastInsertId();
    } catch (\Throwable $e) {
        error_log('ccUpsertCall: ' . $e->getMessage());
        return 0;
    }
}

/** Match a number to a client or lead so calls attach to the right record. */
function ccIdentifyCaller(PDO $db, string $e164): array
{
    $out = ['client_id' => null, 'lead_id' => null, 'name' => null];
    if ($e164 === '') return $out;

    // Compare on the last 9 digits — stored numbers vary between 07…, 2547…
    // and +2547… and would otherwise never match.
    $tail = substr(preg_replace('/\D/', '', $e164), -9);
    if ($tail === '') return $out;

    try {
        $st = $db->prepare("SELECT id, name FROM clients
                            WHERE RIGHT(REGEXP_REPLACE(COALESCE(phone,''), '[^0-9]', ''), 9) = ?
                            LIMIT 1");
        $st->execute([$tail]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            return ['client_id' => (int)$r['id'], 'lead_id' => null, 'name' => $r['name']];
        }
    } catch (\Throwable $_) {
        // REGEXP_REPLACE needs MySQL 8 / MariaDB 10.0+. Fall through to LIKE.
        try {
            $st = $db->prepare("SELECT id, name FROM clients WHERE phone LIKE ? LIMIT 1");
            $st->execute(['%' . $tail]);
            if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                return ['client_id' => (int)$r['id'], 'lead_id' => null, 'name' => $r['name']];
            }
        } catch (\Throwable $_) {}
    }

    try {
        $st = $db->prepare("SELECT id, name FROM crm_leads WHERE phone LIKE ? ORDER BY id DESC LIMIT 1");
        $st->execute(['%' . $tail]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            return ['client_id' => null, 'lead_id' => (int)$r['id'], 'name' => $r['name']];
        }
    } catch (\Throwable $_) {}

    return $out;
}

/** Agents currently signed in and free, longest-idle first. */
function ccAvailableAgents(PDO $db, int $staleSeconds = 60): array
{
    try {
        $st = $db->prepare("SELECT a.*, u.name FROM call_agents a
                            JOIN users u ON u.id = a.user_id
                            WHERE a.state = 'available'
                              AND a.last_seen IS NOT NULL
                              AND a.last_seen > DATE_SUB(NOW(), INTERVAL ? SECOND)
                            ORDER BY a.last_seen ASC");
        $st->execute([$staleSeconds]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $_) { return []; }
}

/** The public URL the provider must be pointed at for inbound calls. */
function ccCallbackUrl(): string
{
    return rtrim(BASE_URL, '/') . '/modules/callcenter/api/voice_callback.php';
}

function ccAvatarColor(int $id): string
{
    $p = ['#2563eb','#7c3aed','#db2777','#dc2626','#ea580c','#ca8a04','#16a34a','#0891b2','#4f46e5','#be123c'];
    return $p[abs($id) % count($p)];
}

function ccInitials(string $name): string
{
    $x = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: []));
    if (!$x) return '?';
    return mb_strtoupper(mb_substr($x[0], 0, 1) . (count($x) > 1 ? mb_substr(end($x), 0, 1) : ''));
}

function ccFormatDuration(int $sec): string
{
    if ($sec <= 0) return '—';
    $m = intdiv($sec, 60); $s = $sec % 60;
    return $m > 0 ? sprintf('%dm %02ds', $m, $s) : $s . 's';
}

} // function_exists guard
