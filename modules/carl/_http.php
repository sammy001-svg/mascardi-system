<?php
/**
 * One way for Karl to make an HTTP request.
 *
 * Both model providers were called with file_get_contents() and a stream
 * context, which needs allow_url_fopen. Shared hosting very often has that
 * switched off, and when it is there is no wrapper for https:// at all — the
 * call fails before a packet leaves the machine, with "no suitable wrapper
 * could be found" hidden behind an @.
 *
 * That is why the yard could configure a perfectly good Gemini key and still
 * get nothing: WhatsApp reached its provider because the WhatsApp driver uses
 * cURL, and Karl could not reach his because he did not. Same server, same
 * network, two different answers, and nothing on screen to connect them.
 *
 * cURL is present on essentially every PHP host and is not governed by
 * allow_url_fopen, so it is tried first. The stream path stays as a fallback
 * for the rare host with the opposite combination, and if neither is usable
 * the error says so in those words rather than blaming the network.
 */

if (!function_exists('carlHttpPost')) {

/**
 * @param  string[] $headers  full header lines, e.g. 'Content-Type: application/json'
 * @return array{ok:bool, status:int, body:string, error:string}
 */
function carlHttpPost(string $url, array $headers, string $payload, int $timeout = 30): array
{
    $fail = fn(string $why) => ['ok' => false, 'status' => 0, 'body' => '', 'error' => $why];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            // A 400 from the provider is an answer, not a transport failure —
            // it carries the reason, which is the thing worth reporting.
            CURLOPT_FAILONERROR    => false,
        ]);
        $body   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === false) return $fail($err !== '' ? $err : 'The request failed.');
        return ['ok' => true, 'status' => $status, 'body' => (string)$body, 'error' => ''];
    }

    if (!ini_get('allow_url_fopen')) {
        return $fail('This server has neither the cURL extension nor allow_url_fopen, '
                   . 'so PHP cannot make outbound HTTPS requests at all. Ask the host to '
                   . 'enable cURL.');
    }

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => implode("\r\n", $headers),
        'content'       => $payload,
        'timeout'       => $timeout,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        $e = error_get_last();
        return $fail('Could not open a connection: ' . ($e['message'] ?? 'unknown reason'));
    }

    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int)$m[1];
    }
    return ['ok' => true, 'status' => $status, 'body' => (string)$body, 'error' => ''];
}

/** What this server can actually do, for the diagnostics screen. */
function carlHttpCapability(): array
{
    return [
        'curl'      => function_exists('curl_init'),
        'url_fopen' => (bool)ini_get('allow_url_fopen'),
    ];
}

} // function_exists('carlHttpPost')
