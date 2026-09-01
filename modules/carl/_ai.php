<?php
/**
 * Carl — which model answers, and how to talk to it.
 *
 * Carl was written against Anthropic's Messages API, and the conversation loop
 * in _agent.php was shaped by it: content blocks, tool_use, tool_result. Google's
 * API says the same things in a different shape — parts, functionCall,
 * functionResponse, and "model" where Anthropic says "assistant".
 *
 * Rather than write the loop twice, everything provider-specific lives here and
 * returns one normalised shape:
 *
 *     ['text' => string, 'calls' => [ ['id','name','input'], … ]]
 *
 * so _agent.php neither knows nor cares who answered. Switching provider is a
 * settings change, and the offline matcher still answers when neither is
 * configured — Carl has never depended on a model being reachable.
 *
 * Settings:
 *   ai_provider        'google' or 'anthropic'  (default: google when a Google
 *                      key is present, otherwise anthropic)
 *   google_api_key     from aistudio.google.com
 *   google_model       default gemini-2.5-flash
 */

if (!function_exists('carlAiProvider')) {

/** Which provider to use. An explicit setting wins; otherwise whoever has a key. */
function carlAiProvider(): string
{
    $set = strtolower(trim(getSetting('ai_provider', '')));
    if ($set === 'google' || $set === 'anthropic') return $set;

    if (trim(getSetting('google_api_key', '')) !== '')    return 'google';
    if (trim(getSetting('anthropic_api_key', '')) !== '') return 'anthropic';
    return 'google';
}

/** Google models this build will send. A stray settings value is never passed on. */
function carlGoogleModel(): string
{
    $m = trim(getSetting('google_model', ''));
    $allowed = [
        'gemini-2.5-flash',       // the default — fast, cheap, generous free tier
        'gemini-2.5-pro',         // stronger reasoning, slower
        'gemini-2.0-flash',
        'gemini-2.0-flash-lite',
        'gemini-1.5-flash',
        'gemini-1.5-pro',
    ];
    return in_array($m, $allowed, true) ? $m : 'gemini-2.5-flash';
}

/** Is any model reachable at all? */
function carlAiAvailable(): bool
{
    if (carlAiProvider() === 'google') {
        return trim(getSetting('google_api_key', '')) !== '';
    }
    return function_exists('carlLlmAvailable') && carlLlmAvailable();
}

/**
 * Is the older Anthropic freeform helper usable?
 *
 * carlLlmFreeform() talks to Anthropic directly and, when it fails, returns its
 * own apology as a perfectly valid-looking answer. Callers check only that the
 * answer is non-empty, so with Google selected they would take an Anthropic path
 * that cannot work and hand the apology back as if it were a reply. This is the
 * narrower question those callers actually mean.
 */
function carlLegacyFreeform(): bool
{
    return carlAiProvider() === 'anthropic'
        && function_exists('carlLlmAvailable') && carlLlmAvailable();
}

/** The model actually in use, for diagnostics. */
function carlAiModel(): string
{
    return carlAiProvider() === 'google'
        ? carlGoogleModel()
        : (function_exists('carlLlmModel') ? carlLlmModel() : 'unknown');
}

// ── Tool schemas ─────────────────────────────────────────────────────────────

/**
 * Anthropic's input_schema into Google's Schema.
 *
 * Two differences that matter and are easy to get wrong:
 *   - Google's Schema enum is upper-case (OBJECT, STRING) where Anthropic uses
 *     JSON Schema's lower-case.
 *   - A function that takes no arguments must OMIT parameters entirely. Sending
 *     an empty properties object is rejected, which would take every no-argument
 *     tool down with it.
 */
function carlGeminiSchema(array $schema): ?array
{
    $type = strtoupper((string)($schema['type'] ?? 'object'));
    $out  = ['type' => $type];

    if (isset($schema['description'])) $out['description'] = (string)$schema['description'];
    if (isset($schema['enum']))        $out['enum'] = array_values($schema['enum']);

    if ($type === 'OBJECT') {
        $props = (array)($schema['properties'] ?? []);
        if (!$props) return null;   // no arguments — omit parameters altogether
        $out['properties'] = [];
        foreach ($props as $name => $spec) {
            $child = carlGeminiSchema((array)$spec);
            $out['properties'][$name] = $child ?? ['type' => strtoupper((string)($spec['type'] ?? 'string'))];
            if (isset($spec['description'])) $out['properties'][$name]['description'] = (string)$spec['description'];
            if (isset($spec['enum']))        $out['properties'][$name]['enum'] = array_values($spec['enum']);
        }
        if (!empty($schema['required'])) $out['required'] = array_values($schema['required']);
    }

    if ($type === 'ARRAY' && isset($schema['items'])) {
        $out['items'] = carlGeminiSchema((array)$schema['items']) ?? ['type' => 'STRING'];
    }

    return $out;
}

/** Carl's tool list in Google's functionDeclarations form. */
function carlGeminiTools(array $tools): array
{
    $decls = [];
    foreach ($tools as $t) {
        $d = ['name' => $t['name'], 'description' => $t['description']];
        $params = carlGeminiSchema((array)($t['input_schema'] ?? []));
        if ($params !== null) $d['parameters'] = $params;
        $decls[] = $d;
    }
    return $decls ? [['functionDeclarations' => $decls]] : [];
}

// ── Talking to Google ────────────────────────────────────────────────────────

/**
 * One round with Gemini.
 *
 * $msgs is kept in Google's own "contents" shape by the append helpers below, so
 * nothing has to be translated back and forth mid-conversation.
 */
function carlGeminiRound(string $system, array $msgs, array $tools, int $maxTok = 900): ?array
{
    $key = trim(getSetting('google_api_key', ''));
    if ($key === '') return null;

    $body = [
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'contents'          => array_values($msgs),
        'generationConfig'  => [
            'maxOutputTokens' => $maxTok,
            'temperature'     => 0.7,
        ],
    ];
    $decls = carlGeminiTools($tools);
    if ($decls) $body['tools'] = $decls;

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
         . rawurlencode(carlGoogleModel()) . ':generateContent';

    $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => implode("\r\n", [
            'Content-Type: application/json',
            // The key goes in a header, not the query string: a URL with a
            // credential in it ends up in access logs and proxy caches.
            'x-goog-api-key: ' . $key,
            'Content-Length: ' . strlen($payload),
        ]),
        'content'       => $payload,
        'timeout'       => 30,
        'ignore_errors' => true,
    ]]);

    try {
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            carlAiNoteFailure('Could not reach the Google API at all.');
            return null;
        }
        $j = json_decode($raw, true);
        if (isset($j['error'])) {
            error_log('[Carl Gemini] ' . json_encode($j['error']));
            carlAiNoteFailure((string)($j['error']['message'] ?? 'Google returned an error.'));
            return null;
        }
        if (!is_array($j)) return null;
        carlAiNoteFailure(null);
        return carlGeminiNormalise($j);
    } catch (\Throwable $e) {
        error_log('[Carl Gemini] ' . $e->getMessage());
        carlAiNoteFailure($e->getMessage());
        return null;
    }
}

/** Gemini's candidate into ['text', 'calls', 'parts']. */
function carlGeminiNormalise(array $j): array
{
    $parts = $j['candidates'][0]['content']['parts'] ?? [];
    $text  = '';
    $calls = [];
    $i     = 0;

    foreach ($parts as $p) {
        if (isset($p['text'])) {
            $text .= $p['text'];
        }
        if (isset($p['functionCall'])) {
            $i++;
            $calls[] = [
                // Gemini does not issue call ids; the name is what pairs a
                // response to its call, so we mint an id only for our own logging.
                'id'    => 'gcall_' . $i,
                'name'  => (string)($p['functionCall']['name'] ?? ''),
                'input' => (array)($p['functionCall']['args'] ?? []),
            ];
        }
    }

    return ['text' => trim($text), 'calls' => $calls, 'parts' => $parts];
}

// ── The provider-neutral interface _agent.php uses ───────────────────────────

/** Opening conversation state, in whichever shape the provider wants. */
function carlAiSeed(array $history, string $msg): array
{
    $google = carlAiProvider() === 'google';
    $out    = [];

    foreach (array_slice($history, -8) as $h) {
        $text = trim((string)($h['message'] ?? $h['text'] ?? ''));
        if ($text === '') continue;
        $isUser = ($h['role'] ?? '') === 'user';
        $out[] = $google
            ? ['role' => $isUser ? 'user' : 'model', 'parts' => [['text' => $text]]]
            : ['role' => $isUser ? 'user' : 'assistant', 'content' => $text];
    }

    $out[] = $google
        ? ['role' => 'user', 'parts' => [['text' => $msg]]]
        : ['role' => 'user', 'content' => $msg];

    if (!$google && function_exists('carlLlmDedupeRoles')) $out = carlLlmDedupeRoles($out);
    return $out;
}

/** One round, whoever is answering. Null means fall back to the offline matcher. */
function carlAiRound(string $system, array $msgs, array $tools, int $maxTok = 900): ?array
{
    if (carlAiProvider() === 'google') {
        return carlGeminiRound($system, $msgs, $tools, $maxTok);
    }

    if (!function_exists('carlLlmRequest')) return null;
    $resp = carlLlmRequest($system, $msgs, $maxTok, $tools);
    if (!$resp) return null;

    $blocks = $resp['content'] ?? [];
    $text   = '';
    $calls  = [];
    foreach ($blocks as $b) {
        if (($b['type'] ?? '') === 'text')     $text .= (string)$b['text'];
        if (($b['type'] ?? '') === 'tool_use') {
            $calls[] = ['id' => $b['id'], 'name' => $b['name'], 'input' => (array)($b['input'] ?? [])];
        }
    }
    return ['text' => trim($text), 'calls' => $calls, 'parts' => $blocks];
}

/** Records the model's own turn, so the next round sees what it said. */
function carlAiAppendModelTurn(array &$msgs, array $round): void
{
    if (carlAiProvider() === 'google') {
        $msgs[] = ['role' => 'model', 'parts' => $round['parts'] ?: [['text' => $round['text']]]];
    } else {
        $msgs[] = ['role' => 'assistant', 'content' => $round['parts']];
    }
}

/** Hands tool output back. $results = [ ['id','name','text'], … ] */
function carlAiAppendToolResults(array &$msgs, array $results): void
{
    if (carlAiProvider() === 'google') {
        $parts = [];
        foreach ($results as $r) {
            $parts[] = ['functionResponse' => [
                'name'     => $r['name'],
                // Google expects an object here, not a bare string.
                'response' => ['result' => $r['text']],
            ]];
        }
        $msgs[] = ['role' => 'user', 'parts' => $parts];
        return;
    }

    $blocks = [];
    foreach ($results as $r) {
        $blocks[] = [
            'type'        => 'tool_result',
            'tool_use_id' => $r['id'],
            'content'     => $r['text'],
        ];
    }
    $msgs[] = ['role' => 'user', 'content' => $blocks];
}

// ── Why she is answering plainly ─────────────────────────────────────────────

/**
 * Remembers the last failure so the fallback is not silent.
 *
 * Shares the setting the Anthropic layer already uses, so whichever provider is
 * configured, the notice in Carl's panel says what actually went wrong.
 */
function carlAiNoteFailure(?string $message): void
{
    if (function_exists('carlLlmNoteFailure')) { carlLlmNoteFailure($message); return; }
    try {
        $value = $message === null ? '' : trim($message) . ' | ' . date('Y-m-d H:i');
        getDB()->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES ('carl_llm_last_error', ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        )->execute([$value]);
    } catch (\Throwable $e) { /* diagnostics must never break a reply */ }
}

} // function_exists('carlAiProvider')
