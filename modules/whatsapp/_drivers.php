<?php
/**
 * WhatsApp — the connection, behind one interface.
 *
 * Two ways to reach WhatsApp, and they are not alike:
 *
 *   green  A relay of WhatsApp Web. An admin scans a QR code once with the
 *          company phone and the whole team sends through that number, keeping
 *          the number and the chat history the yard already uses. It is not
 *          sanctioned by Meta, so the number can be banned, and it is a paid
 *          monthly dependency on somebody else's servers.
 *
 *   cloud  Meta's official API. No QR: a dedicated number is verified with Meta
 *          and can no longer be used in the ordinary WhatsApp app. No ban risk
 *          and real delivery receipts, but a conversation can only be OPENED
 *          with a template Meta approved in advance, and free-form replies stop
 *          24 hours after the customer last wrote.
 *
 * Everything above this file — the inbox, the permissions, the documents, the
 * history — is written against the interface, not against either of them. The
 * yard goes live today on the QR bridge and can move to the official API from
 * Settings later without any of that being rebuilt.
 *
 * The interface is deliberately small, because every function here has to be
 * implementable by both:
 *
 *   waDriverStatus()   where the connection stands, and a QR if one is wanted
 *   waDriverSendText() send words
 *   waDriverSendFile() send a file, by upload — never by public link
 *   waDriverInbound()  turn a provider's webhook into one shape
 *
 * Files are uploaded as bytes rather than handed over as a URL on purpose. The
 * obvious shortcut is to give the provider a link to the document on this
 * server, which means publishing a customer's invoice at a guessable address
 * for anyone who finds it. Uploading costs a little bandwidth and leaks nothing.
 */

if (!function_exists('waProvider')) {

require_once __DIR__ . '/../../includes/functions.php';

/** Which connection is live. Anything unrecognised falls back to the bridge. */
function waProvider(): string
{
    $p = strtolower(trim((string)getSetting('wa_provider', 'green')));
    return in_array($p, ['green', 'cloud'], true) ? $p : 'green';
}

/** The credentials for whichever provider is live. */
function waConfig(): array
{
    if (waProvider() === 'cloud') {
        return [
            'provider' => 'cloud',
            'phone_id' => trim((string)getSetting('wa_cloud_phone_id', '')),
            'token'    => trim((string)getSetting('wa_cloud_token', '')),
            'waba_id'  => trim((string)getSetting('wa_cloud_waba_id', '')),
        ];
    }
    return [
        'provider' => 'green',
        'instance' => trim((string)getSetting('wa_green_instance', '')),
        'token'    => trim((string)getSetting('wa_green_token', '')),
        'host'     => rtrim((string)getSetting('wa_green_host', 'https://api.greenapi.com'), '/'),
    ];
}

/** Whether there is enough configuration to try at all. */
function waConfigured(): bool
{
    $c = waConfig();
    return $c['provider'] === 'cloud'
        ? ($c['phone_id'] !== '' && $c['token'] !== '')
        : ($c['instance'] !== '' && $c['token'] !== '');
}

/** A human name for the live provider, for screens and logs. */
function waProviderLabel(?string $p = null): string
{
    return ($p ?? waProvider()) === 'cloud'
        ? 'Meta Cloud API (official)'
        : 'WhatsApp Web bridge (QR)';
}

// ── Phone numbers ────────────────────────────────────────────────────────────

/**
 * A phone number as WhatsApp wants it: digits only, full country code, no plus.
 *
 * Kenyan numbers arrive written every way there is — 0712…, +254712…, 254712…,
 * 0112… — and a number that is one character out simply never arrives, with no
 * error to notice. So the shapes are normalised here, once, rather than at each
 * of the places that sends.
 */
function waNormalisePhone(string $raw): ?string
{
    $d = preg_replace('/\D+/', '', $raw) ?? '';
    if ($d === '') return null;

    $cc = preg_replace('/\D+/', '', (string)getSetting('wa_country_code', '254')) ?: '254';

    // Order matters. "00" has to be tested before the single "0", or
    // 00254712345678 is read as a local number and comes out as
    // 2540254712345678 — long enough to be refused, so the message simply
    // never goes anywhere and nobody is told why.
    if (str_starts_with($d, '00'))           $d = substr($d, 2);
    // 0712345678 → 254712345678
    elseif (str_starts_with($d, '0'))        $d = $cc . substr($d, 1);
    // 712345678 (nine digits, no leading zero) → 254712345678
    elseif (strlen($d) === 9)                $d = $cc . $d;

    // Short enough to be a typo, long enough to be nonsense: refuse either way
    // rather than send a message into the void.
    return (strlen($d) >= 10 && strlen($d) <= 15) ? $d : null;
}

/** The provider's address for a number. */
function waChatId(string $phone): ?string
{
    $n = waNormalisePhone($phone);
    if ($n === null) return null;
    return waProvider() === 'cloud' ? $n : $n . '@c.us';
}

/** A chat id back to a plain number, for display and for matching clients. */
function waChatPhone(string $chatId): string
{
    return preg_replace('/\D+/', '', explode('@', $chatId)[0]) ?? '';
}

// ── HTTP ─────────────────────────────────────────────────────────────────────

/**
 * One request, with the failure modes named.
 *
 * Providers go down, tokens expire and somebody's DNS breaks. Each of those
 * wants a different sentence on screen, and "Something went wrong" sends a
 * person to look in the wrong place.
 */
function waHttp(string $method, string $url, array $opts = []): array
{
    $ch = curl_init($url);
    $headers = $opts['headers'] ?? [];
    $curl = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => (int)($opts['timeout'] ?? 25),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST  => $method,
    ];
    if (isset($opts['json'])) {
        $curl[CURLOPT_POSTFIELDS] = json_encode($opts['json'], JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
    } elseif (isset($opts['multipart'])) {
        $curl[CURLOPT_POSTFIELDS] = $opts['multipart'];
    }
    if ($headers) $curl[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $curl);

    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'code' => 0, 'error' => 'Could not reach WhatsApp: ' . $err, 'data' => []];
    }
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) $data = ['raw' => (string)$raw];

    if ($code === 401 || $code === 403) {
        return ['ok' => false, 'code' => $code, 'data' => $data,
                'error' => 'WhatsApp rejected the credentials. Check the connection settings.'];
    }
    if ($code === 429) {
        return ['ok' => false, 'code' => $code, 'data' => $data,
                'error' => 'WhatsApp is rate limiting us. Wait a moment and try again.'];
    }
    if ($code >= 400) {
        $msg = $data['message'] ?? ($data['error']['message'] ?? ('HTTP ' . $code));
        return ['ok' => false, 'code' => $code, 'data' => $data, 'error' => (string)$msg];
    }
    return ['ok' => true, 'code' => $code, 'data' => $data, 'error' => ''];
}

/** The base of a Green API call. */
function waGreenUrl(string $method): string
{
    $c = waConfig();
    return $c['host'] . '/waInstance' . $c['instance'] . '/' . $method . '/' . $c['token'];
}

// ── Where the connection stands ──────────────────────────────────────────────

/**
 * @return array{state:string, label:string, qr:?string, phone:string, detail:string}
 *   state is one of: connected · scan · starting · blocked · unconfigured · error
 */
function waDriverStatus(bool $wantQr = false): array
{
    $none = ['state' => 'unconfigured', 'label' => 'Not connected', 'qr' => null,
             'phone' => '', 'detail' => 'No WhatsApp connection has been set up yet.'];
    if (!waConfigured()) return $none;

    if (waProvider() === 'cloud') {
        // There is no QR and nothing to scan: the number is either verified with
        // Meta or it is not, and the only way to find out is to ask.
        $c = waConfig();
        $r = waHttp('GET', 'https://graph.facebook.com/v21.0/' . $c['phone_id']
                         . '?fields=display_phone_number,verified_name,quality_rating',
                    ['headers' => ['Authorization: Bearer ' . $c['token']]]);
        if (!$r['ok']) {
            return ['state' => 'error', 'label' => 'Not connected', 'qr' => null,
                    'phone' => '', 'detail' => $r['error']];
        }
        return ['state' => 'connected', 'label' => 'Connected',
                'qr' => null,
                'phone' => (string)($r['data']['display_phone_number'] ?? ''),
                'detail' => trim('Verified as ' . (string)($r['data']['verified_name'] ?? 'this business'))];
    }

    $r = waHttp('GET', waGreenUrl('getStateInstance'));
    if (!$r['ok']) {
        return ['state' => 'error', 'label' => 'Not connected', 'qr' => null,
                'phone' => '', 'detail' => $r['error']];
    }
    $state = (string)($r['data']['stateInstance'] ?? '');

    if ($state === 'authorized') {
        $phone = '';
        $s = waHttp('GET', waGreenUrl('getSettings'));
        if ($s['ok']) $phone = waChatPhone((string)($s['data']['wid'] ?? ''));
        return ['state' => 'connected', 'label' => 'Connected', 'qr' => null,
                'phone' => $phone,
                'detail' => 'The company phone is linked. Everyone with access can send.'];
    }

    if ($state === 'notAuthorized' || $state === 'starting') {
        $qr = null;
        if ($wantQr && $state === 'notAuthorized') {
            $q = waHttp('GET', waGreenUrl('qr'), ['timeout' => 20]);
            if ($q['ok'] && ($q['data']['type'] ?? '') === 'qrCode') {
                $qr = (string)$q['data']['message'];   // base64 PNG, no data: prefix
            }
        }
        return [
            'state'  => $state === 'starting' ? 'starting' : 'scan',
            'label'  => $state === 'starting' ? 'Starting up' : 'Waiting for a scan',
            'qr'     => $qr,
            'phone'  => '',
            'detail' => $state === 'starting'
                ? 'The connection is waking up. This takes a few seconds.'
                : 'Scan the code with the company phone to link it.',
        ];
    }

    if ($state === 'blocked') {
        return ['state' => 'blocked', 'label' => 'Blocked', 'qr' => null, 'phone' => '',
                'detail' => 'The provider has blocked this instance — usually an unpaid '
                          . 'subscription, sometimes a ban. Check the provider account.'];
    }

    return ['state' => 'error', 'label' => 'Not connected', 'qr' => null, 'phone' => '',
            'detail' => 'WhatsApp reported an unexpected state: ' . ($state ?: 'none') . '.'];
}

/** Drop the link so a different phone can be scanned. */
function waDriverLogout(): array
{
    if (waProvider() === 'cloud') {
        return ['ok' => false, 'error' => 'The official API has no link to drop — '
                                        . 'the number is verified with Meta, not scanned.'];
    }
    $r = waHttp('GET', waGreenUrl('logout'));
    return ['ok' => $r['ok'], 'error' => $r['error']];
}

// ── Sending ──────────────────────────────────────────────────────────────────

/**
 * @return array{ok:bool, id:string, error:string}
 */
function waDriverSendText(string $chatId, string $text): array
{
    if (!waConfigured()) return ['ok' => false, 'id' => '', 'error' => 'WhatsApp is not connected.'];
    if (trim($text) === '') return ['ok' => false, 'id' => '', 'error' => 'There is nothing to send.'];

    if (waProvider() === 'cloud') {
        $c = waConfig();
        $r = waHttp('POST', 'https://graph.facebook.com/v21.0/' . $c['phone_id'] . '/messages', [
            'headers' => ['Authorization: Bearer ' . $c['token']],
            'json'    => ['messaging_product' => 'whatsapp', 'to' => $chatId,
                          'type' => 'text', 'text' => ['preview_url' => true, 'body' => $text]],
        ]);
        return ['ok' => $r['ok'], 'error' => $r['error'],
                'id' => (string)($r['data']['messages'][0]['id'] ?? '')];
    }

    $r = waHttp('POST', waGreenUrl('sendMessage'), [
        'json' => ['chatId' => $chatId, 'message' => $text],
    ]);
    return ['ok' => $r['ok'], 'error' => $r['error'], 'id' => (string)($r['data']['idMessage'] ?? '')];
}

/**
 * Send a file the customer can keep.
 *
 * Takes a path on this server, never a URL. Handing the provider a link to the
 * document would mean publishing a customer's invoice at an address anybody who
 * guessed it could read; uploading the bytes costs a little bandwidth and leaks
 * nothing.
 */
function waDriverSendFile(string $chatId, string $path, string $fileName, string $caption = ''): array
{
    if (!waConfigured())   return ['ok' => false, 'id' => '', 'error' => 'WhatsApp is not connected.'];
    if (!is_readable($path)) return ['ok' => false, 'id' => '', 'error' => 'That file could not be read.'];

    $size = filesize($path) ?: 0;
    $max  = 16 * 1024 * 1024;   // WhatsApp refuses documents above roughly this
    if ($size > $max) {
        return ['ok' => false, 'id' => '',
                'error' => 'That file is ' . round($size / 1048576, 1) . ' MB. WhatsApp will not '
                         . 'carry anything over 16 MB — send a link instead.'];
    }

    $mime = waMimeOf($path);

    if (waProvider() === 'cloud') {
        $c = waConfig();
        // Two steps on the official API: the bytes are uploaded, then the media
        // id that comes back is what actually gets sent.
        $up = waHttp('POST', 'https://graph.facebook.com/v21.0/' . $c['phone_id'] . '/media', [
            'headers'   => ['Authorization: Bearer ' . $c['token']],
            'multipart' => ['messaging_product' => 'whatsapp', 'type' => $mime,
                            'file' => new CURLFile($path, $mime, $fileName)],
            'timeout'   => 60,
        ]);
        if (!$up['ok']) return ['ok' => false, 'id' => '', 'error' => $up['error']];
        $mediaId = (string)($up['data']['id'] ?? '');
        if ($mediaId === '') return ['ok' => false, 'id' => '', 'error' => 'The upload returned no media id.'];

        $r = waHttp('POST', 'https://graph.facebook.com/v21.0/' . $c['phone_id'] . '/messages', [
            'headers' => ['Authorization: Bearer ' . $c['token']],
            'json'    => ['messaging_product' => 'whatsapp', 'to' => $chatId, 'type' => 'document',
                          'document' => array_filter(['id' => $mediaId, 'filename' => $fileName,
                                                      'caption' => $caption])],
            'timeout' => 60,
        ]);
        return ['ok' => $r['ok'], 'error' => $r['error'],
                'id' => (string)($r['data']['messages'][0]['id'] ?? '')];
    }

    $r = waHttp('POST', waGreenUrl('sendFileByUpload'), [
        'multipart' => array_filter([
            'chatId'   => $chatId,
            'fileName' => $fileName,
            'caption'  => $caption,
            'file'     => new CURLFile($path, $mime, $fileName),
        ], fn($v) => $v !== ''),
        'timeout'   => 120,   // a 10 MB brochure on a Kenyan uplink is not quick
    ]);
    return ['ok' => $r['ok'], 'error' => $r['error'], 'id' => (string)($r['data']['idMessage'] ?? '')];
}

/** The type of a file, without trusting whatever the browser claimed it was. */
function waMimeOf(string $path): string
{
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $m = finfo_file($fi, $path);
            finfo_close($fi);
            if (is_string($m) && $m !== '') return $m;
        }
    }
    return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
        'pdf'          => 'application/pdf',
        'jpg', 'jpeg'  => 'image/jpeg',
        'png'          => 'image/png',
        'webp'         => 'image/webp',
        'doc'          => 'application/msword',
        'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'          => 'application/vnd.ms-excel',
        'xlsx'         => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'mp4'          => 'video/mp4',
        default        => 'application/octet-stream',
    };
}

// ── Receiving ────────────────────────────────────────────────────────────────

/**
 * One shape for an arriving message, whichever provider delivered it.
 *
 * @return array|null  null when the payload is something we do not act on —
 *                     a delivery receipt, a status ping, another instance's traffic
 */
function waDriverInbound(array $p): ?array
{
    return waProvider() === 'cloud' ? waInboundCloud($p) : waInboundGreen($p);
}

function waInboundGreen(array $p): ?array
{
    if (($p['typeWebhook'] ?? '') !== 'incomingMessageReceived') return null;

    $chatId = (string)($p['senderData']['chatId'] ?? '');
    // Group traffic is deliberately ignored. The yard's number sits in supplier
    // and family groups, and pulling those into a client inbox is both noise and
    // a privacy problem.
    if ($chatId === '' || !str_ends_with($chatId, '@c.us')) return null;

    $m    = $p['messageData'] ?? [];
    $type = (string)($m['typeMessage'] ?? '');
    $body = '';
    $kind = 'text';
    $file = null;

    if ($type === 'textMessage') {
        $body = (string)($m['textMessageData']['textMessage'] ?? '');
    } elseif ($type === 'extendedTextMessage') {
        $body = (string)($m['extendedTextMessageData']['text'] ?? '');
    } elseif ($type === 'quotedMessage') {
        $body = (string)($m['extendedTextMessageData']['text'] ?? '');
    } elseif (in_array($type, ['imageMessage', 'documentMessage', 'videoMessage', 'audioMessage'], true)) {
        $fd   = $m['fileMessageData'] ?? [];
        $body = (string)($fd['caption'] ?? '');
        $kind = match ($type) {
            'imageMessage'    => 'image',
            'documentMessage' => 'document',
            'videoMessage'    => 'video',
            default           => 'audio',
        };
        $file = [
            'url'  => (string)($fd['downloadUrl'] ?? ''),
            'name' => (string)($fd['fileName'] ?? ''),
        ];
    } else {
        // Locations, contacts, polls and whatever they add next. Recorded so the
        // thread does not silently skip a turn the customer can see on their phone.
        $kind = 'other';
        $body = '[' . ($type ?: 'unsupported message') . ']';
    }

    return [
        'chat_id'    => $chatId,
        'phone'      => waChatPhone($chatId),
        'name'       => trim((string)($p['senderData']['senderName'] ?? '')),
        'message_id' => (string)($p['idMessage'] ?? ''),
        'type'       => $kind,
        'body'       => $body,
        'file'       => $file,
        'at'         => isset($p['timestamp']) ? (int)$p['timestamp'] : time(),
    ];
}

function waInboundCloud(array $p): ?array
{
    $v = $p['entry'][0]['changes'][0]['value'] ?? null;
    if (!is_array($v) || empty($v['messages'][0])) return null;

    $m    = $v['messages'][0];
    $from = (string)($m['from'] ?? '');
    if ($from === '') return null;

    $type = (string)($m['type'] ?? 'text');
    $kind = match ($type) {
        'text'                 => 'text',
        'image'                => 'image',
        'document'             => 'document',
        'video'                => 'video',
        'audio', 'voice'       => 'audio',
        default                => 'other',
    };
    $body = match ($type) {
        'text'     => (string)($m['text']['body'] ?? ''),
        'image'    => (string)($m['image']['caption'] ?? ''),
        'document' => (string)($m['document']['caption'] ?? ''),
        'video'    => (string)($m['video']['caption'] ?? ''),
        default    => '[' . $type . ']',
    };

    $file = null;
    if (isset($m[$type]['id'])) {
        $file = ['id' => (string)$m[$type]['id'],
                 'name' => (string)($m[$type]['filename'] ?? '')];
    }

    return [
        'chat_id'    => $from,
        'phone'      => waChatPhone($from),
        'name'       => trim((string)($v['contacts'][0]['profile']['name'] ?? '')),
        'message_id' => (string)($m['id'] ?? ''),
        'type'       => $kind,
        'body'       => $body,
        'file'       => $file,
        'at'         => isset($m['timestamp']) ? (int)$m['timestamp'] : time(),
    ];
}

} // function_exists('waProvider')
