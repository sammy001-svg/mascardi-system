<?php
/**
 * Carl — the one endpoint the panel talks to.
 *
 *   GET                 the conversation so far, plus the greeting if one is due
 *   POST {message}      an utterance; returns what Carl says, shows, and does
 *
 * Intent resolution order
 * -----------------------
 * 1. If a multi-step task is already pending, continue it (e.g. collecting
 *    a phone number for "add lead").
 * 2. Try the offline pattern-matcher (carlMatchSkill) — fast, deterministic,
 *    works with no API key.
 * 3. If the key is configured, let Claude pick the skill. This handles
 *    natural phrasing the offline matcher misses ("what's looking thin?").
 * 4. If no skill matches at all but the LLM is available, send the question
 *    to carlLlmFreeform() — Carl answers from live figures rather than
 *    refusing.
 * 5. Final fallback: the static "I didn't catch that" reply.
 */
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../_skills.php';
require_once __DIR__ . '/../_llm.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$db = getDB();
carlMigrate($db);

$me  = authUser();
$uid = (int)$me['id'];

// The kiosk account is public-facing and has no business asking about margins.
if (authRole() === 'visitor_book') {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

/** Keeps the transcript, so closing the panel does not lose the thread. */
function carlRemember(PDO $db, int $uid, string $role, string $body, ?string $skill = null, ?string $html = null): void
{
    try {
        $db->prepare("INSERT INTO carl_messages (user_id, role, body, skill, html)
                      VALUES (?,?,?,?,?)")
           ->execute([$uid, $role, $body, $skill, $html !== '' ? $html : null]);
        // Keep it short. This is a working conversation, not an archive, and an
        // unbounded transcript is a table that only ever grows.
        $db->prepare("DELETE FROM carl_messages WHERE user_id = ? AND id < (
                          SELECT x.id FROM (
                              SELECT id FROM carl_messages WHERE user_id = ?
                              ORDER BY id DESC LIMIT 1 OFFSET 40
                          ) x)")->execute([$uid, $uid]);
    } catch (\Throwable $_) {}
}

// ── History and the daily greeting ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $history = [];
    try {
        $st = $db->prepare("SELECT role, body, html, skill FROM carl_messages
                            WHERE user_id = ? ORDER BY id DESC LIMIT 20");
        $st->execute([$uid]);
        $history = array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
    } catch (\Throwable $_) {}

    $greeting = null;
    if (!empty($_GET['greet'])) {
        $g = carlGreetingFor($db, $me);
        if ($g) {
            $greeting = ['say' => $g['say'], 'html' => $g['html']];
            carlRemember($db, $uid, 'carl', $g['say'], 'greeting', $g['html']);
        }
    }

    echo json_encode([
        'ok'       => true,
        'name'     => CARL_NAME,
        'user'     => carlFirstName((string)$me['name']),
        'history'  => $history,
        'greeting' => $greeting,
        'pending'  => carlPendingGet($db, $uid) !== null,
        'llm'      => carlLlmAvailable(),       // lets the widget know typing animation should feel longer
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── An utterance ─────────────────────────────────────────────────────────────
$raw = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$msg = trim((string)($raw['message'] ?? ''));

if ($msg === '') {
    echo json_encode(['ok' => false, 'say' => 'I did not hear anything. Try again?']);
    exit;
}
if (mb_strlen($msg) > 500) $msg = mb_substr($msg, 0, 500);

carlRemember($db, $uid, 'user', $msg);

// ── Step 1: continue a multi-step task already in progress ────────────────────
$pending = carlPendingGet($db, $uid);
if ($pending) {
    $res = carlContinue($db, $me, $pending, $msg);
    carlRemember($db, $uid, 'carl', $res['say'], $res['skill'] ?? null, $res['html'] ?? '');
    echo json_encode([
        'ok'    => true,
        'say'   => carlSay($res['say']),
        'html'  => $res['html'] ?? '',
        'skill' => $res['skill'] ?? null,
        'done'  => $res['done'] ?? true,
        'go'    => $res['go'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Step 2: offline pattern-matcher ──────────────────────────────────────────
$skill = carlMatchSkill($msg);

// ── Step 3: LLM intent routing (if offline matcher found nothing) ─────────────
if ($skill === null && carlLlmAvailable()) {
    $availableSkills = array_map(fn($s) => $s['label'], carlSkillsFor());
    $skill = carlLlmPickSkill($msg, $availableSkills);
}

// ── Step 4: run skill or freeform ────────────────────────────────────────────
if ($skill !== null) {
    $res = carlRun($db, $me, $skill, $msg);
    // When the LLM is on, optionally rephrase the spoken line for more warmth —
    // only for the short "say" text, never for the rich HTML panel.
    if (carlLlmAvailable() && isset($res['say']) && strlen($res['say']) < 300) {
        $res['say'] = carlLlmPhrase($res['say'], carlFirstName((string)$me['name']));
    }
} else {
    // Step 4b: freeform — Carl tries to answer from live figures.
    $res = carlSkillUnknown($me, $db);
}

carlRemember($db, $uid, 'carl', $res['say'], $res['skill'] ?? null, $res['html'] ?? '');

echo json_encode([
    'ok'    => true,
    'say'   => carlSay($res['say']),
    'html'  => $res['html'] ?? '',
    'skill' => $res['skill'] ?? null,
    'done'  => $res['done'] ?? true,
    'go'    => $res['go'] ?? null,
], JSON_UNESCAPED_UNICODE);
