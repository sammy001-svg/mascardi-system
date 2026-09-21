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
 * The dialling code, with a sanity check the settings form cannot be trusted for.
 *
 * Somebody typed the company's whole WhatsApp number into the country-code box —
 * an easy thing to do, and nothing pushed back. Every number then entered as
 * 07… became the company number with a mobile stuck on the end, twenty-one
 * digits long, and was refused with "that does not look like a usable phone
 * number" — about the least helpful sentence available, since the number was
 * perfectly fine and the fault was three screens away in a settings field.
 *
 * No country code is longer than four digits, so anything longer is not one.
 */
function waCountryCode(): string
{
    $raw = preg_replace('/\D+/', '', (string)getSetting('wa_country_code', '254')) ?? '';
    if ($raw === '' || strlen($raw) > 4) {
        if ($raw !== '') {
            error_log('waCountryCode: "' . $raw . '" is not a dialling code — using 254. '
                    . 'Check the country code on the WhatsApp setup page.');
        }
        return '254';
    }
    return $raw;
}
/**
 * A phone number as WhatsApp wants it: digits only, full country code, no plus.
 *
 * Kenyan numbers arrive written every way there is — 0712…, +254712…, 254712…,
 * 0112… — and a number that is one character out simply never arrives, with no
 * error to notice. So the shapes are normalised here, once, rather than at each
 * of the places that sends.
 *
 * The yard sells to people abroad, so a number is not assumed to be Kenyan. The
 * rule is the one people actually follow when they write a number down:
 *
 *   A leading + or 00 means the country code is already there. Whatever
 *   follows is used as given, and the local code is never added to it.
 *
 *   A single leading 0 is a trunk prefix, which only has meaning inside a
 *   country, so it is read as local and the yard's own code replaces it.
 *
 *   Nine bare digits is a Kenyan mobile with the 0 left off.
 *
 *   Anything else already carries a country code.
 *
 * The one shape that used to come out wrong is the written form "+44 (0)7911
 * 123456" — how a great many people outside Kenya write their own number. The
 * bracketed 0 is an instruction to the reader, not part of the number, and
 * stripping punctuation first turned it into 4407911123456: a number nobody
 * has. It is removed before the digits are read.
 */
function waNormalisePhone(string $raw): ?string
{
    $raw = trim($raw);

    // "+44 (0)7911 …" — the bracketed trunk prefix is a note to the reader and
    // is dropped, rather than becoming a digit in the middle of the number.
    $raw = preg_replace('/\((\s*0\s*)\)/', '', $raw) ?? $raw;

    // Whether the country code is already present has to be decided before the
    // punctuation goes, because the + is the only thing that says so.
    $international = str_starts_with($raw, '+');

    $d = preg_replace('/\D+/', '', $raw) ?? '';
    if ($d === '') return null;

    // Order matters. "00" has to be tested before the single "0", or
    // 00254712345678 is read as a local number and comes out as
    // 2540254712345678 — long enough to be refused, so the message simply
    // never goes anywhere and nobody is told why.
    if (str_starts_with($d, '00')) {
        $d = substr($d, 2);
        $international = true;
    }

    if (!$international) {
        $cc = waCountryCode();
        // 0712345678 → 254712345678
        if (str_starts_with($d, '0'))      $d = $cc . substr($d, 1);
        // 712345678 (nine digits, no leading zero) → 254712345678
        elseif (strlen($d) === 9)          $d = $cc . $d;
    }

    // E.164 allows up to fifteen digits. The floor is eight rather than ten
    // because some countries' full international numbers are genuinely that
    // short, and refusing them meant the yard simply could not message anyone
    // there. Short enough to be a typo is still refused.
    return (strlen($d) >= 8 && strlen($d) <= 15) ? $d : null;
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

/**
 * The provider's settings, fetched once per request.
 *
 * Both the status band and the receiving panel want this, and providers rate
 * limit. Asking twice on one page load earned a 429 on the second call, which
 * made the receiving panel report a problem that did not exist — the worst kind
 * of diagnostic, because it sends somebody to fix something that was fine.
 */
function waGreenSettings(bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    return $cache = waHttp('GET', waGreenUrl('getSettings'));
}
/** The base of a Green API call. */
function waGreenUrl(string $method): string
{
    $c = waConfig();
    return $c['host'] . '/waInstance' . $c['instance'] . '/' . $method . '/' . $c['token'];
}

/**
 * Does this number have WhatsApp?
 *
 * Not every phone number does, and one that does not is the commonest reason a
 * message to a real, correctly typed number never arrives. The provider will
 * usually take the request anyway, so from here it looks like any other send
 * until it quietly fails — which is how "not delivered" ends up on screen with
 * no reason beside it.
 *
 * Asked only when something has already gone wrong, or when a person is about
 * to start a conversation. It is a paid call on most plans and there is no
 * sense spending one on every message to a customer we talk to daily.
 *
 * @return array{known:bool, exists:bool, error:string}
 */
function waDriverCheckNumber(string $phone): array
{
    $none = ['known' => false, 'exists' => false, 'error' => ''];
    $n = waNormalisePhone($phone);
    if ($n === null) return array_merge($none, ['error' => 'That is not a usable phone number.']);
    if (!waConfigured()) return $none;

    // Only the bridge can answer this. Meta has no equivalent that a phone
    // number token may call, so the official API simply does not know.
    if (waProvider() === 'cloud') return $none;

    $r = waHttp('POST', waGreenUrl('checkWhatsapp'), [
        'json'    => ['phoneNumber' => (int)$n],
        'timeout' => 20,
    ]);
    if (!$r['ok']) return array_merge($none, ['error' => $r['error']]);
    if (!array_key_exists('existsWhatsapp', $r['data'])) return $none;

    return ['known' => true, 'exists' => (bool)$r['data']['existsWhatsapp'], 'error' => ''];
}

/**
 * Why a send failed, in words somebody can act on.
 *
 * The provider's own message is kept — it is the truth of what happened — but
 * on its own it is often a status code, and the single most likely cause is one
 * it never mentions: the number does not have WhatsApp. So that is checked and
 * said plainly.
 */
function waSendFailureReason(string $chatId, string $providerSaid): string
{
    $said = trim($providerSaid);

    try {
        $c = waDriverCheckNumber(waChatPhone($chatId));
        if ($c['known'] && !$c['exists']) {
            return 'That number does not have WhatsApp, so the message cannot be delivered. '
                 . 'Check the number, or reach them another way.'
                 . ($said !== '' ? ' (The provider said: ' . $said . ')' : '');
        }
    } catch (\Throwable $e) {
        // The check is a courtesy. Its failure must not replace the real reason.
    }

    return $said !== '' ? $said : 'The provider would not say why.';
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
        $s = waGreenSettings();
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

/**
 * What the provider has been told to call, and whether it matches us.
 *
 * "Connected but nothing arrives" is the commonest WhatsApp fault there is, and
 * it looks identical to a working connection from every screen that only asks
 * whether the phone is linked. The phone being linked is what lets us SEND.
 * Receiving is a separate setting on the provider's side, pointing at a URL on
 * this server, and nothing on this server can see it unless it asks.
 *
 * @return array{known:bool, url:string, token_set:bool, incoming:bool, error:string}
 */
function waDriverWebhook(): array
{
    $none = ['known' => false, 'url' => '', 'token_set' => false, 'incoming' => false, 'error' => ''];
    if (!waConfigured()) return $none;

    if (waProvider() === 'cloud') {
        // Meta keeps the callback against the app, not the phone number, and it
        // is not readable with a phone-number token. Nothing to compare.
        return ['known' => false, 'url' => '', 'token_set' => false, 'incoming' => true,
                'error' => 'Meta holds the callback against the app, so it cannot be read from here.'];
    }

    $r = waGreenSettings();
    // array_merge, not +. The + operator keeps the LEFT value where a key
    // exists in both, so $none's empty error silently replaced the real one
    // and the panel reported "the provider would not say" for every fault.
    if (!$r['ok']) return array_merge($none, ['error' => $r['error']]);

    return [
        'known'     => true,
        'url'       => (string)($r['data']['webhookUrl'] ?? ''),
        'token_set' => trim((string)($r['data']['webhookUrlToken'] ?? '')) !== '',
        'incoming'  => in_array(strtolower((string)($r['data']['incomingWebhook'] ?? '')), ['yes', 'on'], true),
        'error'     => '',
    ];
}

/**
 * Point the provider back at this system.
 *
 * The secret travels as the provider's own webhook token, which it sends as an
 * Authorization header, rather than being glued onto the URL. Same protection,
 * and the address stays something a person can read back over the phone.
 */
function waDriverSetWebhook(string $url, string $token): array
{
    if (!waConfigured()) return ['ok' => false, 'error' => 'WhatsApp is not connected.'];

    if (waProvider() === 'cloud') {
        return ['ok' => false, 'error' => 'The callback for the official API is set in the Meta '
                                        . 'app dashboard, not from here.'];
    }

    $r = waHttp('POST', waGreenUrl('setSettings'), [
        'json' => [
            'webhookUrl'            => $url,
            'webhookUrlToken'       => $token,
            // Without these the provider accepts the URL and calls it for
            // nothing, which looks configured and behaves exactly like broken.
            'incomingWebhook'       => 'yes',
            'outgoingMessageWebhook'=> 'yes',
            'stateWebhook'          => 'yes',
        ],
        'timeout' => 30,
    ]);
    return ['ok' => $r['ok'], 'error' => $r['error']];
}
/** Drop the link so a different phone can be scanned. */
function waDriverLogout(): array
{
    if (waProvider() === 'cloud') {
        return ['ok' => false, 'error' => 'The official API has no link to drop — '
                                        . 'the number is verified with Meta, not scanned.'];
    }
    $r = waHttp('GET', waGreenUrl('logout'));
    // The cached settings are now stale by definition.
    if ($r['ok']) waGreenSettings(true);
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

    $r  = waHttp('POST', waGreenUrl('sendMessage'), [
        'json' => ['chatId' => $chatId, 'message' => $text],
    ]);
    $id = (string)($r['data']['idMessage'] ?? '');

    // A 200 carrying no message id is the provider accepting the request and
    // doing nothing with it — which was being recorded as a successful send, so
    // the message showed a tick, sat in the thread, and had never existed. An
    // id is the only evidence it was really taken.
    if ($r['ok'] && $id === '') {
        return ['ok' => false, 'id' => '',
                'error' => waSendFailureReason($chatId, 'the provider accepted the request but '
                                                      . 'returned no message id')];
    }
    if (!$r['ok']) {
        return ['ok' => false, 'id' => '', 'error' => waSendFailureReason($chatId, $r['error'])];
    }
    return ['ok' => true, 'error' => '', 'id' => $id];
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
    $id = (string)($r['data']['idMessage'] ?? '');
    if ($r['ok'] && $id === '') {
        return ['ok' => false, 'id' => '',
                'error' => waSendFailureReason($chatId, 'the provider accepted the file but '
                                                      . 'returned no message id')];
    }
    if (!$r['ok']) {
        return ['ok' => false, 'id' => '', 'error' => waSendFailureReason($chatId, $r['error'])];
    }
    return ['ok' => true, 'error' => '', 'id' => $id];
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

// ── History ──────────────────────────────────────────────────────────────────

/**
 * The chats the linked phone already knows about.
 *
 * The yard did not start using WhatsApp the day this system was connected. Years
 * of conversation sit on that phone, and a shared inbox that begins at zero is
 * one nobody trusts — the first thing anyone does is check whether the thread
 * they remember is in it.
 *
 * @return array<int,array{chat_id:string,name:string}>
 */
function waDriverChats(): array
{
    if (!waConfigured() || waProvider() === 'cloud') return [];

    $r = waHttp('GET', waGreenUrl('getChats'), ['timeout' => 60]);
    if (!$r['ok'] || !is_array($r['data'])) return [];

    $out = [];
    foreach ($r['data'] as $c) {
        $id = (string)($c['id'] ?? '');
        // People only, as everywhere else: groups are not client conversations.
        if ($id === '' || !str_ends_with($id, '@c.us')) continue;
        $out[] = ['chat_id' => $id, 'name' => trim((string)($c['name'] ?? ''))];
    }
    return $out;
}

/**
 * One chat's past messages, in the same shape the webhook produces.
 *
 * The history endpoint does NOT return what the webhook returns — the fields sit
 * at the top level instead of nested, and direction is a word rather than being
 * implied. Normalising here means waRecordInbound() and everything downstream
 * cannot tell the difference between a message that arrived live and one that
 * was pulled in afterwards, which is the point.
 *
 * @return array<int,array>  oldest first
 */
function waDriverHistory(string $chatId, int $count = 100): array
{
    if (!waConfigured() || waProvider() === 'cloud') return [];

    $r = waHttp('POST', waGreenUrl('getChatHistory'), [
        'json'    => ['chatId' => $chatId, 'count' => max(1, min(1000, $count))],
        'timeout' => 90,
    ]);
    if (!$r['ok'] || !is_array($r['data'])) return [];

    $out = [];
    foreach ($r['data'] as $m) {
        if (!is_array($m)) continue;
        $type = (string)($m['typeMessage'] ?? '');
        $kind = 'text';
        $body = '';
        $file = null;

        if ($type === 'textMessage' || $type === 'extendedTextMessage' || $type === 'quotedMessage') {
            // The provider puts the words in a different place depending on the
            // kind of message and on which endpoint returned it — history uses
            // extendedTextMessage, the webhook uses extendedTextMessageData, and
            // a reply to a message uses the quoted form. Reading only the first
            // of those filed a customer's reply as an empty line.
            $body = (string)(
                   $m['textMessage']
                ?? $m['extendedTextMessage']['text']
                ?? $m['extendedTextMessageData']['text']
                ?? $m['quotedMessage']['textMessage']
                ?? ''
            );
        } elseif (in_array($type, ['imageMessage','documentMessage','videoMessage','audioMessage'], true)) {
            $kind = match ($type) {
                'imageMessage'    => 'image',
                'documentMessage' => 'document',
                'videoMessage'    => 'video',
                default           => 'audio',
            };
            $body = (string)($m['caption'] ?? '');
            $file = ['url' => (string)($m['downloadUrl'] ?? ''), 'name' => (string)($m['fileName'] ?? '')];
        } else {
            $kind = 'other';
            $body = '[' . ($type ?: 'unsupported message') . ']';
        }

        $out[] = [
            'chat_id'    => $chatId,
            'phone'      => waChatPhone($chatId),
            'name'       => trim((string)($m['senderName'] ?? '')),
            'message_id' => (string)($m['idMessage'] ?? ''),
            'direction'  => ((string)($m['type'] ?? 'incoming')) === 'outgoing' ? 'out' : 'in',
            'type'       => $kind,
            'body'       => $body,
            'file'       => $file,
            'at'         => (int)($m['timestamp'] ?? time()),
        ];
    }

    // Oldest first, so a thread reads the way a conversation happened.
    usort($out, fn($a, $b) => $a['at'] <=> $b['at']);
    return $out;
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
