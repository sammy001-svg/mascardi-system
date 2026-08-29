<?php
/**
 * Carl — Claude API layer.
 *
 * All communication with Anthropic lives here and nowhere else. The rest of
 * Carl's code calls these helpers; it does not know whether the answer came
 * from Claude or from the offline fallback.
 *
 * Safety contract
 * ---------------
 * Claude is given only what Carl explicitly passes — figures she has already
 * read from the database plus a list of skill names. It cannot browse, it
 * cannot invent a figure, and it never touches the database. Every call is
 * wrapped so that a network error, a bad key, or a rate-limit silently falls
 * back to the offline path. The user should never see an error that came from
 * this file.
 *
 * Configuration
 * -------------
 * Stored in the settings table under two keys:
 *   anthropic_api_key   — the sk-ant-… key
 *   anthropic_model     — defaults to claude-3-5-haiku-20241022
 */

if (!function_exists('carlLlmAvailable')) {

// ── Configuration ────────────────────────────────────────────────────────────

/** True when a key is present and the LLM layer should be used. */
function carlLlmAvailable(): bool
{
    return trim(getSetting('anthropic_api_key', '')) !== '';
}

/** The model to use — default is Haiku for low latency in real-time chat. */
function carlLlmModel(): string
{
    $m = trim(getSetting('anthropic_model', ''));
    // Whitelist only known Claude models to prevent arbitrary injection.
    $allowed = [
        'claude-3-5-haiku-20241022',
        'claude-3-5-sonnet-20241022',
        'claude-3-haiku-20240307',
        'claude-sonnet-4-5',
        'claude-opus-4-5',
    ];
    return in_array($m, $allowed, true) ? $m : 'claude-3-5-haiku-20241022';
}

// ── HTTP helper ───────────────────────────────────────────────────────────────

/**
 * POST to the Anthropic Messages API and return the decoded response, or null
 * on any error.
 *
 * @param  string  $system   System prompt
 * @param  array   $msgs     [{role:user/assistant, content:string}, …]
 * @param  int     $maxTok   Maximum tokens in the reply
 * @return array|null        Decoded JSON response or null
 */
function carlLlmRequest(string $system, array $msgs, int $maxTok = 512): ?array
{
    $key = trim(getSetting('anthropic_api_key', ''));
    if ($key === '') return null;

    $payload = json_encode([
        'model'      => carlLlmModel(),
        'max_tokens' => $maxTok,
        'system'     => $system,
        'messages'   => $msgs,
    ]);

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => implode("\r\n", [
            'Content-Type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
            'Content-Length: ' . strlen($payload),
        ]),
        'content'       => $payload,
        'timeout'        => 12,          // seconds — keep the panel responsive
        'ignore_errors' => true,
    ]]);

    try {
        $raw = @file_get_contents('https://api.anthropic.com/v1/messages', false, $ctx);
        if ($raw === false) return null;
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    } catch (\Throwable $_) {
        return null;
    }
}

/** Extract the plain text from a Claude Messages response. */
function carlLlmText(?array $resp): string
{
    if (!$resp) return '';
    $content = $resp['content'] ?? [];
    foreach ($content as $block) {
        if (($block['type'] ?? '') === 'text') return trim((string)$block['text']);
    }
    return '';
}

// ── Public helpers ────────────────────────────────────────────────────────────

/**
 * Ask Claude to pick the best skill for an utterance.
 *
 * Returns a valid skill key from $available, or null if Claude cannot decide
 * or is unavailable.
 *
 * @param  string   $utterance   The user's raw message
 * @param  array    $available   [key => label, …] — only skills this user may see
 * @return string|null
 */
function carlLlmPickSkill(string $utterance, array $available): ?string
{
    if (!$available) return null;

    $list = implode("\n", array_map(
        fn($k, $l) => "- $k: $l",
        array_keys($available),
        array_values($available)
    ));

    $system = <<<SYS
You are the intent router for Carl, a business assistant for a car dealership.
You must read the user message and reply with ONLY the single skill key that best matches it.
Reply with exactly one of the keys below and nothing else — no punctuation, no explanation.
If nothing fits, reply with the single word: none

Available skills:
$list
SYS;

    $resp = carlLlmRequest($system, [['role' => 'user', 'content' => $utterance]], 16);
    $pick = trim(strtolower(carlLlmText($resp)));

    // Validate: must be one of the offered keys.
    return isset($available[$pick]) ? $pick : null;
}

/**
 * Rephrase a skill's spoken line to sound more natural and varied.
 *
 * If Claude is unavailable or fails, $original is returned unchanged so the
 * offline behaviour is indistinguishable from the online one.
 *
 * @param  string  $original  The sentence the skill produced
 * @param  string  $name      The user's first name (for personalisation)
 * @return string
 */
function carlLlmPhrase(string $original, string $name): string
{
    if (!carlLlmAvailable()) return $original;

    $system = <<<SYS
You are Carl, a warm, direct, professional AI assistant for a car dealership.
Rephrase the answer below to sound more natural and conversational — same facts, slightly warmer tone.
Keep it concise (under 60 words). Do NOT invent any figures. Reply with only the rephrased sentence.
The user's first name is: $name
SYS;

    $resp = carlLlmRequest($system, [['role' => 'user', 'content' => $original]], 120);
    $text = carlLlmText($resp);
    return $text !== '' ? $text : $original;
}

/**
 * Generate a personalised morning greeting.
 *
 * @param  string  $name        First name
 * @param  string  $partOfDay   morning / afternoon / evening
 * @param  array   $facts       Human-readable facts array, e.g. ['3 new leads', '2 visitors on site']
 * @return string               Greeting sentence, falls back to the offline template on failure.
 */
function carlLlmGreeting(string $name, string $partOfDay, array $facts): string
{
    if (!carlLlmAvailable()) return '';

    $factLines = $facts ? implode(', ', $facts) : 'nothing urgent outstanding';

    $system = <<<SYS
You are Carl, a warm professional assistant at Mascardi Luxury Cars, a car dealership.
Write a brief, friendly opening greeting for a staff member at the start of their session.
Rules:
- Address them by first name.
- Mention the time of day (morning/afternoon/evening).
- Mention 1–2 of the most important facts naturally (don't list them robotically).
- End with a question or offer to help.
- Maximum 55 words. Plain text only — no markdown, no bullet points.
SYS;

    $prompt = "Name: $name\nTime of day: $partOfDay\nBusiness facts: $factLines";
    $resp   = carlLlmRequest($system, [['role' => 'user', 'content' => $prompt]], 120);
    $text   = carlLlmText($resp);
    return $text !== '' ? $text : '';
}

/**
 * Answer a free-form question that didn't match any skill.
 *
 * Carl is given the live business figures and asked to answer naturally. She
 * will politely decline questions she cannot answer from the provided data.
 *
 * @param  string  $utterance   The user's question
 * @param  array   $figures     Output of carlFigures()
 * @param  array   $user        Auth user row
 * @return array                ['say' => string, 'html' => string]
 */
function carlLlmFreeform(string $utterance, array $figures, array $user): array
{
    $fallback = [
        'say'  => 'I did not quite catch that. You can ask me for today\'s briefing, '
                . 'the sales pipeline, stock, visitors, or what needs attention. '
                . 'Say "help" and I will list everything I can do.',
        'html' => '',
    ];

    if (!carlLlmAvailable()) return $fallback;

    // Build a concise snapshot of what Carl actually knows.
    $snap = carlLlmFigureSnapshot($figures);

    $system = <<<SYS
You are Carl, a professional AI assistant for Mascardi Luxury Cars, a car dealership in Kenya.
You have access to the following live business data for today:

$snap

Rules:
- Answer only from the data above. Do NOT invent figures.
- If the question cannot be answered from this data, politely say so and suggest what you CAN help with (briefing, leads, stock, visitors, revenue, advice, navigation).
- Be warm, direct, and concise — under 70 words.
- Plain text only. No markdown, no bullet points.
SYS;

    $resp = carlLlmRequest($system, [['role' => 'user', 'content' => $utterance]], 200);
    $text = carlLlmText($resp);

    if ($text === '') return $fallback;

    return ['say' => $text, 'html' => '', 'skill' => 'freeform', 'done' => true];
}

/**
 * Render the figures array as a concise plain-text snapshot for the LLM.
 * Keeping this separate makes it easy to expand without touching the prompts.
 */
function carlLlmFigureSnapshot(array $f): string
{
    $lines = [
        'Stock (available to sell): '   . ($f['stock_available'] ?? 0),
        'Stock (total on books): '      . ($f['stock_total']     ?? 0),
        'Stock (reserved): '            . ($f['stock_reserved']  ?? 0),
        'Stock (in transit): '          . ($f['stock_transit']   ?? 0),
        'Cars sold this month: '        . ($f['sold_month']      ?? 0),
        'Open leads in pipeline: '      . ($f['leads_total']     ?? 0),
        'New leads today: '             . ($f['leads_new_today'] ?? 0),
        'Leads reserved (deposits): '   . ($f['leads_reserved']  ?? 0),
        'Leads overdue follow-up: '     . ($f['leads_overdue']   ?? 0),
        'Leads with no follow-up set: ' . ($f['leads_nofollow']  ?? 0),
        'Deposits held (KES): '         . number_format($f['deposits_held'] ?? 0),
        'Visitors today: '              . ($f['visitors_today']  ?? 0),
        'Visitors currently on site: '  . ($f['visitors_onsite'] ?? 0),
        'Open workshop job cards: '     . ($f['jobs_open']       ?? 0),
        'Job cards opened today: '      . ($f['jobs_today']      ?? 0),
        'Service bookings today: '      . ($f['bookings_today']  ?? 0),
        'Revenue this month (KES): '    . number_format($f['paid_month'] ?? 0),
        'Revenue today (KES): '         . number_format($f['paid_today'] ?? 0),
        'Unpaid invoices: '             . ($f['invoices_unpaid'] ?? 0),
    ];
    return implode("\n", $lines);
}

} // function_exists('carlLlmAvailable')
