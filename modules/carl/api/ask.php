<?php
/**
 * Carl — the one endpoint the panel talks to.
 *
 *   GET                 the conversation so far, plus the greeting if one is due
 *   POST {message}      an utterance; returns what Carl says, shows, and does
 *
 * Intent resolution order
 * -----------------------
 * 1. If a multi-step task is already pending, continue it (e.g. "add lead").
 * 2. Try the offline pattern-matcher (carlMatchSkill) — fast, deterministic,
 *    works with no API key.
 * 3. If the key is configured and offline matcher found nothing, let Claude
 *    pick the skill. Handles natural phrasing the offline matcher misses.
 * 4. If still no skill match, send the whole question to carlLlmFreeform()
 *    with the live figures and recent history for context. Carl answers
 *    conversationally rather than refusing.
 * 5. Final static fallback (no API key, truly unrecognised question).
 *
 * What was removed vs the original
 * ---------------------------------
 * carlLlmPhrase() — the "rephrase this skill answer" step — was removed.
 * It made a second API call on every single reply, doubling latency, and
 * often made structured skill answers worse by stripping numbers or
 * introducing a stilted rephrased tone. Carl's skill answers are already
 * well-written. Claude is reserved for what it is actually needed for:
 * intent routing and freeform conversation.
 */
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../_skills.php';
require_once __DIR__ . '/../_llm.php';
require_once __DIR__ . '/../_agent.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$db = getDB();
carlMigrate($db);

$me  = authUser();
$uid = (int)$me['id'];

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
        $db->prepare("DELETE FROM carl_messages WHERE user_id = ? AND id < (
                          SELECT x.id FROM (
                              SELECT id FROM carl_messages WHERE user_id = ?
                              ORDER BY id DESC LIMIT 1 OFFSET 40
                          ) x)")->execute([$uid, $uid]);
    } catch (\Throwable $_) {}
}

/** Fetch the last N turns for this user — used to give Claude conversation context. */
function carlRecentHistory(PDO $db, int $uid, int $turns = 8): array
{
    try {
        $st = $db->prepare("SELECT role, body FROM carl_messages
                            WHERE user_id = ? ORDER BY id DESC LIMIT ?");
        $st->execute([$uid, $turns]);
        return array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
    } catch (\Throwable $_) { return []; }
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
        'llm'      => carlAiAvailable(),
        // Only to those who can do something about it. A salesperson seeing
        // "the account is out of credit" learns nothing they can act on.
        'notice'   => (isSuperAdmin() || authRole() === 'admin')
                        ? carlLlmExplain(carlLlmLastError()) : '',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── An utterance ─────────────────────────────────────────────────────────────
// Read from the JSON body — the widget posts application/json, so $_POST is empty.
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

// ── Step 2: decide who answers ───────────────────────────────────────────────
//
// Every message used to go to the API first, including "hello" and "how many
// cars do we have" — questions the offline matcher answers correctly on its own,
// and which no model needs to be paid for. At Opus prices that is most of the
// bill spent on the questions that needed it least, and it is a large part of
// how the credit balance ran down.
//
// So the matcher goes first now. When it recognises the question with
// confidence, Carl answers from the database for nothing. The API is kept for
// what it is genuinely better at: phrasing the matcher does not cover, and
// follow-ups that only make sense against what was just said.
$skill   = carlMatchSkill($msg);
$history = carlRecentHistory($db, $uid, 8);

if (carlNeedsModel($msg, $skill, $history)) {
    // Claude answers, with the tools in _agent.php doing the looking-up, so the
    // prose is hers but every figure in it came out of the database. If no key is
    // configured, or the call fails, we fall through — Carl still works without
    // the API, she is simply more literal.
    $res = carlConverse($db, $me, $msg, $history);
    if ($res !== null) {
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
}

// ── Step 3: answer from the database ─────────────────────────────────────────
if ($skill !== null) {
    // A skill matched — run the deterministic, DB-backed handler.
    // No rephrasing step: the skill answers are already well-written and
    // adding a second Claude call just adds latency and risks distorting numbers.
    $res = carlRun($db, $me, $skill, $msg);
} else {
    $res = carlSkillUnknown($me, $db, $msg, $history);
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
