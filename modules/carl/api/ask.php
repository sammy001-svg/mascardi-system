<?php
/**
 * Carl — the one endpoint the panel talks to.
 *
 *   GET                 the conversation so far, plus the greeting if one is due
 *   POST {message}      an utterance; returns what Carl says, shows, and does
 *
 * A task already under way is continued BEFORE intent matching. Otherwise a bare
 * "0712345678" — a perfectly good answer to the question Carl just asked — would
 * be run through the matcher, match nothing, and lose the thread.
 */
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../_skills.php';
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

$pending = carlPendingGet($db, $uid);
if ($pending) {
    $res = carlContinue($db, $me, $pending, $msg);
} else {
    $skill = carlMatchSkill($msg);
    $res = $skill ? carlRun($db, $me, $skill, $msg) : carlSkillUnknown($me);
}

carlRemember($db, $uid, 'carl', $res['say'], $res['skill'] ?? null, $res['html'] ?? '');

echo json_encode([
    'ok'    => true,
    'say'   => carlSay($res['say']),
    'html'  => $res['html'] ?? '',
    'skill' => $res['skill'] ?? null,
    'done'  => $res['done'] ?? true,
    // Present only when Carl was asked to open something; the panel navigates.
    'go'    => $res['go'] ?? null,
], JSON_UNESCAPED_UNICODE);
