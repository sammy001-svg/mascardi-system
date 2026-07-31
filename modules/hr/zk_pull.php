<?php
/**
 * ZKTeco pull client — reads the attendance log over TCP port 4370.
 *
 * Only usable when the web server can actually reach the terminal: same LAN,
 * a VPN, or a port forward. A hosted server cannot reach a device behind NAT,
 * which is why push (iclock/router.php) is the primary integration and this is
 * the option for on-premise installs.
 *
 * Protocol notes
 * --------------
 * Every packet is
 *     [0x50 0x50 0x82 0x7d][payload length, 4 bytes LE]   <- TCP framing only
 *     [command 2][checksum 2][session id 2][reply id 2][data...]
 * all little-endian, checksum being the 16-bit one's-complement sum of the
 * packet with the checksum field zeroed — the same scheme as IP/UDP.
 *
 * Attendance comes back as a flat array of fixed-width records. Record width
 * differs between firmware generations, so the width is detected from the
 * payload rather than assumed; guessing wrong shifts every field and produces
 * plausible-looking but entirely wrong timestamps.
 *
 * ext-sockets is not installed on this server, so stream_socket_client is used
 * throughout — it handles TCP fine and is available everywhere.
 */

if (!function_exists('zkPullFromDevice')) {


if (!defined('ZK_CMD_CONNECT')) define('ZK_CMD_CONNECT', 1000);
if (!defined('ZK_CMD_EXIT')) define('ZK_CMD_EXIT', 1001);
if (!defined('ZK_CMD_AUTH')) define('ZK_CMD_AUTH', 1102);
if (!defined('ZK_CMD_ACK_OK')) define('ZK_CMD_ACK_OK', 2000);
if (!defined('ZK_CMD_ACK_UNAUTH')) define('ZK_CMD_ACK_UNAUTH', 2005);
if (!defined('ZK_CMD_PREPARE_DATA')) define('ZK_CMD_PREPARE_DATA', 1500);
if (!defined('ZK_CMD_DATA')) define('ZK_CMD_DATA', 1501);
if (!defined('ZK_CMD_FREE_DATA')) define('ZK_CMD_FREE_DATA', 1502);
if (!defined('ZK_CMD_DATA_WRRQ')) define('ZK_CMD_DATA_WRRQ', 1503);
if (!defined('ZK_CMD_DATA_RDY')) define('ZK_CMD_DATA_RDY', 1504);
if (!defined('ZK_CMD_ATTLOG_RRQ')) define('ZK_CMD_ATTLOG_RRQ', 13);
if (!defined('ZK_CMD_DISABLEDEVICE')) define('ZK_CMD_DISABLEDEVICE', 1003);
if (!defined('ZK_CMD_ENABLEDEVICE')) define('ZK_CMD_ENABLEDEVICE', 1002);

/** 16-bit one's-complement checksum over the packet with checksum zeroed. */
function zkChecksum(string $buf): int
{
    $sum = 0;
    $len = strlen($buf);
    for ($i = 0; $i + 1 < $len; $i += 2) {
        $sum += (ord($buf[$i]) | (ord($buf[$i + 1]) << 8));
    }
    if ($len % 2) $sum += ord($buf[$len - 1]);
    while ($sum > 0xFFFF) $sum = ($sum & 0xFFFF) + ($sum >> 16);
    return (~$sum) & 0xFFFF;
}

/** Builds one command packet, TCP-framed. */
function zkPacket(int $cmd, int $sessionId, int $replyId, string $data = ''): string
{
    $body = pack('vvvv', $cmd, 0, $sessionId, $replyId) . $data;
    $body = substr_replace($body, pack('v', zkChecksum($body)), 2, 2);
    return pack('VV', 0x7d825050, strlen($body)) . $body;
}

/** Reads one TCP-framed reply. Returns ['cmd','session','reply','data'] or null. */
function zkReadPacket($sock, int $timeout = 8): ?array
{
    stream_set_timeout($sock, $timeout);
    $head = '';
    while (strlen($head) < 8) {
        $chunk = fread($sock, 8 - strlen($head));
        if ($chunk === false || $chunk === '') return null;
        $head .= $chunk;
    }
    $hdr = unpack('Vmagic/Vsize', $head);
    if (($hdr['magic'] ?? 0) !== 0x7d825050) return null;

    $size = (int)$hdr['size'];
    if ($size < 8 || $size > 8 * 1024 * 1024) return null;

    $body = '';
    while (strlen($body) < $size) {
        $chunk = fread($sock, min(8192, $size - strlen($body)));
        if ($chunk === false || $chunk === '') break;
        $body .= $chunk;
    }
    if (strlen($body) < 8) return null;

    $f = unpack('vcmd/vchk/vsession/vreply', substr($body, 0, 8));
    return ['cmd' => $f['cmd'], 'session' => $f['session'],
            'reply' => $f['reply'], 'data' => substr($body, 8)];
}

/**
 * Splits the attendance blob into fixed-width records.
 *
 * Firmware writes 40-byte records (the common case) or 28-byte ones on older
 * terminals. The width is inferred by testing which one divides the payload
 * exactly AND yields timestamps in a sane range — a width that merely divides
 * evenly can still be the wrong one.
 */
function zkDetectRecordSize(string $data): ?int
{
    foreach ([40, 28, 32] as $size) {
        if (strlen($data) % $size !== 0) continue;
        $count = intdiv(strlen($data), $size);
        if ($count === 0) continue;

        // Sample a few records; if their decoded dates are plausible, accept.
        $ok = 0; $tried = 0;
        for ($i = 0; $i < min(4, $count); $i++) {
            $rec = substr($data, $i * $size, $size);
            $ts  = zkDecodeTime(unpack('V', substr($rec, $size === 28 ? 24 : 27, 4))[1] ?? 0);
            $tried++;
            if ($ts !== null) $ok++;
        }
        if ($tried && $ok === $tried) return $size;
    }
    return null;
}

/**
 * ZK packs a timestamp into one 32-bit integer counting from 2000-01-01:
 *   (((y*12 + m)*31 + d)*24 + hh)*60 + mm)*60 + ss
 * Returns 'Y-m-d H:i:s', or null when the value is not a plausible punch.
 *
 * The plausibility window is deliberately narrow — 2010 to next year — because
 * this function is also the test that decides the record width in
 * zkDetectRecordSize(). A wide window (any year up to 2100) lets random bytes
 * from a wrong-width read decode to a "valid" date, the width is accepted, and
 * every timestamp that follows is silently wrong. Attendance cannot legitimately
 * fall outside that window, so narrowing it costs nothing and makes a
 * misaligned read fail loudly instead.
 *
 * 0 is rejected rather than read as 2000-01-01 00:00:00: devices write zero
 * into unused record slots, so it means "empty", not midnight.
 */
function zkDecodeTime(int $v): ?string
{
    if ($v <= 0) return null;
    $s = $v % 60; $v = intdiv($v, 60);
    $i = $v % 60; $v = intdiv($v, 60);
    $h = $v % 24; $v = intdiv($v, 24);
    $d = ($v % 31) + 1; $v = intdiv($v, 31);
    $m = ($v % 12) + 1; $v = intdiv($v, 12);
    $y = $v + 2000;

    $maxYear = (int)date('Y') + 1;   // tolerate a device clock set slightly ahead
    if ($y < 2010 || $y > $maxYear || $m < 1 || $m > 12 || $d < 1 || $d > 31
        || $h > 23 || $i > 59 || $s > 59) return null;
    if (!checkdate($m, $d, $y)) return null;
    return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $m, $d, $h, $i, $s);
}

/** Decodes the attendance blob into the same shape zkParseAttlog() returns. */
function zkDecodeAttlog(string $data): array
{
    // Newer firmware prefixes the blob with a 4-byte total size.
    if (strlen($data) > 4 && strlen($data) % 40 === 4) $data = substr($data, 4);

    $size = zkDetectRecordSize($data);
    if ($size === null) return ['rows' => [], 'skipped' => 0, 'error' => 'Unrecognised record layout'];

    $rows = []; $skipped = 0;
    $count = intdiv(strlen($data), $size);
    for ($i = 0; $i < $count; $i++) {
        $rec = substr($data, $i * $size, $size);
        if ($size === 28) {
            $pin  = trim(substr($rec, 0, 9), " \0");
            $ts   = zkDecodeTime(unpack('V', substr($rec, 24, 4))[1]);
            $st   = ord($rec[27] ?? "\0");
            $vf   = ord($rec[23] ?? "\0");
        } else {
            // 40-byte: [uid 2][user_id 24][verify 1][timestamp 4][state 1][...]
            $pin  = trim(substr($rec, 2, 24), " \0");
            $vf   = ord($rec[26] ?? "\0");
            $ts   = zkDecodeTime(unpack('V', substr($rec, 27, 4))[1]);
            $st   = ord($rec[31] ?? "\0");
        }
        if ($pin === '' || $ts === null) { $skipped++; continue; }
        $rows[] = [
            'pin'         => ltrim($pin, '0') ?: '0',
            'time'        => $ts,
            'punch_state' => ($st >= 0 && $st <= 5) ? $st : 0,
            'verify_mode' => $vf,
            'work_code'   => null,
            'raw'         => 'pull:' . bin2hex(substr($rec, 0, 12)),
        ];
    }
    return ['rows' => $rows, 'skipped' => $skipped, 'record_size' => $size];
}

/**
 * Connects, reads the attendance log, disconnects.
 * Returns ['ok'=>bool,'rows'=>[],'error'=>string,'info'=>string].
 */
function zkPullFromDevice(string $ip, int $port = 4370, int $commKey = 0, int $timeout = 8): array
{
    $fail = fn(string $m) => ['ok' => false, 'rows' => [], 'error' => $m, 'info' => ''];

    $errNo = 0; $errStr = '';
    $sock = @stream_socket_client("tcp://{$ip}:{$port}", $errNo, $errStr, $timeout);
    if (!$sock) {
        return $fail("Could not reach {$ip}:{$port} — " . ($errStr ?: 'no route or refused')
                   . '. The server must be on the same network as the terminal for this mode.');
    }
    stream_set_timeout($sock, $timeout);

    try {
        fwrite($sock, zkPacket(ZK_CMD_CONNECT, 0, 0));
        $r = zkReadPacket($sock, $timeout);
        if (!$r) return $fail('The device did not answer the connect request.');

        $session = $r['session'];

        // Devices with a comm key reject the session until it is presented.
        if ($r['cmd'] === ZK_CMD_ACK_UNAUTH) {
            if ($commKey <= 0) return $fail('The device requires a communication key (set it on the device record).');
            $k = $commKey ^ 0x4F534B5A;
            fwrite($sock, zkPacket(ZK_CMD_AUTH, $session, 1, pack('V', $k)));
            $r = zkReadPacket($sock, $timeout);
            if (!$r || $r['cmd'] !== ZK_CMD_ACK_OK) return $fail('The communication key was rejected.');
        } elseif ($r['cmd'] !== ZK_CMD_ACK_OK) {
            return $fail('The device refused the connection (code ' . $r['cmd'] . ').');
        }

        $reply = 2;
        // Freeze the terminal briefly so the log cannot change mid-read.
        fwrite($sock, zkPacket(ZK_CMD_DISABLEDEVICE, $session, $reply++, pack('V', 0)));
        zkReadPacket($sock, $timeout);

        fwrite($sock, zkPacket(ZK_CMD_ATTLOG_RRQ, $session, $reply++));
        $r = zkReadPacket($sock, $timeout);
        if (!$r) return $fail('No response when requesting the attendance log.');

        $blob = '';
        if ($r['cmd'] === ZK_CMD_PREPARE_DATA) {
            // Bulk transfer: successive CMD_DATA packets until the ACK.
            while (($p = zkReadPacket($sock, $timeout)) !== null) {
                if ($p['cmd'] === ZK_CMD_DATA)   { $blob .= $p['data']; continue; }
                if ($p['cmd'] === ZK_CMD_ACK_OK) break;
                break;
            }
        } elseif ($r['cmd'] === ZK_CMD_DATA) {
            $blob = $r['data'];
        } elseif ($r['cmd'] === ZK_CMD_ACK_OK) {
            $blob = $r['data'];   // small logs come back inline
        } else {
            return $fail('Unexpected reply to the log request (code ' . $r['cmd'] . ').');
        }

        fwrite($sock, zkPacket(ZK_CMD_ENABLEDEVICE, $session, $reply++));
        zkReadPacket($sock, 2);
        fwrite($sock, zkPacket(ZK_CMD_EXIT, $session, $reply));
        fclose($sock);

        if ($blob === '') return ['ok' => true, 'rows' => [], 'error' => '', 'info' => 'The device holds no attendance records.'];

        $dec = zkDecodeAttlog($blob);
        if (!empty($dec['error'])) {
            return $fail($dec['error'] . ' — ' . strlen($blob) . ' bytes received. '
                       . 'Report this to have the layout added; the file import still works meanwhile.');
        }
        return ['ok' => true, 'rows' => $dec['rows'], 'error' => '',
                'info' => sprintf('%d record(s) read (%d-byte layout, %d unreadable).',
                                  count($dec['rows']), $dec['record_size'], $dec['skipped'])];
    } catch (\Throwable $e) {
        if (is_resource($sock)) fclose($sock);
        return $fail('Pull failed: ' . $e->getMessage());
    }
}

} // function_exists guard
