<?php
/**
 * What every mail screen needs before it can draw anything.
 *
 * This is the part the Shanfix controller kept private, moved into a file the
 * flat pages can share. The important one is mailBox(): every page gets its
 * mailbox from here, built from the signed-in user's own id, and there is no
 * variant that takes one from a request. That is the privacy model, and it
 * holds because there is nothing to hold — no parameter exists to abuse.
 */

require_once __DIR__ . '/_mailbox.php';

use Mascardi\Mail\Mailbox;

if (!function_exists('mailBox')) {

/** The signed-in person's own mailbox — or off to the setup page. */
function mailBox(): Mailbox
{
    requireLogin();

    if (!mailServerReady()) {
        redirect(BASE_URL . '/modules/mail/setup.php');
    }

    $box = Mailbox::for((int)authUser()['id']);

    if (!$box) {
        redirect(BASE_URL . '/modules/mail/setup.php');
    }

    return $box;
}

/**
 * The folder being looked at.
 *
 * A folder name reaches the server quoted or as a literal, so it cannot inject
 * a command; this only keeps it sensible.
 */
function mailFolderParam(): string
{
    $f = trim((string)($_POST['folder'] ?? ($_GET['folder'] ?? 'INBOX')));

    return $f !== '' && mb_strlen($f) <= 190 ? $f : 'INBOX';
}

/** The mailbox link, with whatever state should survive the round trip. */
function mailUrl(array $q = []): string
{
    $q = array_filter($q, static fn ($v) => $v !== '' && $v !== 0 && $v !== null);

    return BASE_URL . '/modules/mail/index.php' . ($q ? '?' . http_build_query($q) : '');
}

/**
 * The saved password has stopped working — almost always because it was
 * changed in cPanel. Say so, and send them to type it again.
 */
function mailServerRefused(): never
{
    try {
        getDB()->prepare('UPDATE mail_accounts SET last_error = ? WHERE user_id = ?')
               ->execute(['Password refused', (int)authUser()['id']]);
    } catch (\Throwable $e) { /* the message below matters more than the note */ }

    setFlash('danger', 'The mail server no longer accepts your saved password — it may have been '
                     . 'changed. Enter it again.');
    redirect(BASE_URL . '/modules/mail/setup.php');
}

/**
 * Keep what was typed when a send fails.
 *
 * Nobody should have to write an email twice because an attachment was too
 * large. The attachments themselves cannot be kept — they live in the upload
 * temp files, which are gone by the next request — so those have to be chosen
 * again, and the message says so.
 */
function mailKeepDraft(array $in, string $error): never
{
    unset($in['attachments']);
    $_SESSION['mail_draft'] = $in;

    setFlash('danger', $error . (str_contains($error, 'attach') ? '' : ' Your message has been kept below.'));
    redirect(BASE_URL . '/modules/mail/compose.php');
}

/** Fill a reply or a forward from the message being answered. */
function mailPrefill(array $d, array $o, string $mode, string $me, string $folder, int $uid): array
{
    $subject  = $o['subject'];
    $fromLine = trim(($o['from']['name'] ?? '') . ' <' . ($o['from']['email'] ?? '') . '>');
    $when     = $o['date'] ? date('D, j M Y \a\t H:i', $o['date']) : '';

    if ($mode === 'forward') {
        $d['subject'] = preg_match('/^fwd?:/i', $subject) ? $subject : 'Fwd: ' . $subject;
        $d['text'] = "\n\n---------- Forwarded message ----------\n"
            . 'From: ' . $fromLine . "\n"
            . ($when ? 'Date: ' . $when . "\n" : '')
            . 'Subject: ' . $subject . "\n"
            . 'To: ' . implode(', ', array_map(fn ($a) => $a['email'], $o['to'])) . "\n\n"
            . $o['text'];
        $d['fwd_uid']    = $uid;
        $d['fwd_folder'] = $folder;
        // Offered to carry over, but fetched fresh from the original at send
        // time rather than trusted from the form.
        $d['fwd_files'] = array_values(array_filter(array_map(
            fn ($a, $i) => $a['inline'] ? null : ['index' => $i, 'name' => $a['name'], 'size' => $a['size']],
            $o['attachments'], array_keys($o['attachments'])
        )));

        return $d;
    }

    $d['subject']     = preg_match('/^re:/i', $subject) ? $subject : 'Re: ' . $subject;
    $d['in_reply_to'] = $o['message_id'];
    $d['references']  = $o['references'];

    $replyTo = $o['reply_to'][0] ?? $o['from'];
    $to      = [$replyTo];

    if ($mode === 'all') {
        foreach (array_merge($o['to'], $o['cc']) as $a) $to[] = $a;
    }

    // Nobody replies to themselves, and nobody twice.
    $seen = [];
    $d['to'] = implode(', ', array_filter(array_map(function ($a) use ($me, &$seen) {
        if (!$a || strcasecmp($a['email'], $me) === 0 || isset($seen[strtolower($a['email'])])) {
            return null;
        }
        $seen[strtolower($a['email'])] = true;

        return $a['name'] !== '' ? $a['name'] . ' <' . $a['email'] . '>' : $a['email'];
    }, $to)));

    $quoted = implode("\n", array_map(fn ($l) => '> ' . $l, explode("\n", rtrim($o['text']))));
    $d['text'] = "\n\nOn " . $when . ', ' . ($o['from']['name'] ?: ($o['from']['email'] ?? '')) . " wrote:\n" . $quoted;

    return $d;
}

/** A size a person can read. */
function mailSize(int $bytes): string
{
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024) . ' KB';

    return $bytes . ' B';
}

/** How mail clients write a date: time today, day this week, date beyond. */
function mailWhen(?int $ts): string
{
    if (!$ts) return '';

    $now = time();
    if (date('Y-m-d', $ts) === date('Y-m-d', $now)) return date('H:i', $ts);
    if ($ts > $now - 6 * 86400)                     return date('D H:i', $ts);
    if (date('Y', $ts) === date('Y', $now))         return date('j M', $ts);

    return date('j M Y', $ts);
}

/** "Jane Doe" or, failing that, the address. */
function mailWho(array $a): string
{
    return trim((string)($a['name'] ?? '')) !== ''
        ? (string)$a['name']
        : (string)($a['email'] ?? '');
}

} // function_exists('mailBox')
