<?php
/**
 * Meetings — reminder dispatch.
 *
 * Every participant is reminded twice about every meeting: once a day before,
 * and again half an hour before it starts. Each reminder goes out on two
 * channels — an in-system notification and an email — so nobody depends on
 * having the system open to know a meeting is coming.
 *
 * On not reminding people twice
 * -----------------------------
 * The dispatcher is driven by a cron job, and cron jobs get run late, run twice,
 * overlap, and get triggered by hand. So delivery is not decided by timing: a
 * reminder is sent only if a row for (meeting, person, lead time, channel) can
 * be inserted into meeting_reminders, whose unique key makes the second attempt
 * fail. Running this every minute and running it once an hour both produce
 * exactly one reminder per person per lead time.
 *
 * That also means the send windows below can be generous. A meeting is caught
 * from the moment it falls inside the window, so a cron that missed a run still
 * reminds people — late, but it reminds them, which beats silence.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/notifications.php';

if (!function_exists('meetingReminderLeadTimes')) {

/**
 * The two lead times, as an offset from the meeting start.
 *
 * `soon` is checked first so that a meeting created inside the 24-hour window
 * gets the imminent wording rather than being described as "tomorrow".
 */
function meetingReminderLeadTimes(): array
{
    return [
        'soon' => ['minutes' => 30,       'label' => 'in 30 minutes'],
        'day'  => ['minutes' => 60 * 24,  'label' => 'tomorrow'],
    ];
}

/**
 * Meetings that currently need a given reminder: still scheduled, not yet
 * started, and inside the lead-time window.
 *
 * The `day` window excludes anything closer than the `soon` lead time — a
 * meeting an hour away is imminent, not "tomorrow", and the soon reminder
 * covers it.
 */
function meetingsDueForReminder(PDO $db, string $leadTime): array
{
    $spec = meetingReminderLeadTimes()[$leadTime] ?? null;
    if (!$spec) return [];

    $lower = ($leadTime === 'day') ? meetingReminderLeadTimes()['soon']['minutes'] : 0;

    $sql = "SELECT m.*, u.name AS organiser_name
            FROM meetings m
            LEFT JOIN users u ON u.id = m.organiser_id
            WHERE m.status = 'scheduled'
              AND m.scheduled_start >  DATE_ADD(NOW(), INTERVAL ? MINUTE)
              AND m.scheduled_start <= DATE_ADD(NOW(), INTERVAL ? MINUTE)
            ORDER BY m.scheduled_start";
    try {
        $st = $db->prepare($sql);
        $st->execute([$lower, $spec['minutes']]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $_) { return []; }
}

/** Everyone who should be reminded: the invited participants plus the organiser. */
function meetingReminderRecipients(PDO $db, array $meeting): array
{
    try {
        $st = $db->prepare("
            SELECT DISTINCT u.id, u.name, u.email, p.role
            FROM meeting_participants p
            JOIN users u ON u.id = p.user_id
            WHERE p.meeting_id = ? AND p.invite_status <> 'declined'
              AND (u.status IS NULL OR u.status = 'active')");
        $st->execute([(int)$meeting['id']]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $_) { $rows = []; }

    // The organiser may not have added themselves as a participant.
    $orgId = (int)($meeting['organiser_id'] ?? 0);
    if ($orgId && !in_array($orgId, array_map(fn($r) => (int)$r['id'], $rows), true)) {
        try {
            $o = $db->prepare("SELECT id, name, email FROM users WHERE id = ?
                               AND (status IS NULL OR status = 'active')");
            $o->execute([$orgId]);
            if ($r = $o->fetch(PDO::FETCH_ASSOC)) { $r['role'] = 'chair'; $rows[] = $r; }
        } catch (\Throwable $_) {}
    }
    return $rows;
}

/**
 * Claims one reminder for delivery.
 *
 * Returns true only for the caller that successfully inserted the row, so the
 * message is sent exactly once no matter how many dispatchers are running.
 * Claiming BEFORE sending is deliberate: a crash mid-send costs one missed
 * reminder, whereas claiming afterwards would let a crash re-send on every run.
 */
function meetingReminderClaim(PDO $db, int $meetingId, int $userId, string $leadTime, string $channel): bool
{
    try {
        $st = $db->prepare("INSERT IGNORE INTO meeting_reminders
                            (meeting_id, user_id, lead_time, channel) VALUES (?,?,?,?)");
        $st->execute([$meetingId, $userId, $leadTime, $channel]);
        return $st->rowCount() > 0;
    } catch (\Throwable $_) { return false; }
}

function meetingReminderFailed(PDO $db, int $meetingId, int $userId, string $leadTime, string $channel, string $why): void
{
    try {
        $db->prepare("UPDATE meeting_reminders SET status='failed', error=?
                      WHERE meeting_id=? AND user_id=? AND lead_time=? AND channel=?")
           ->execute([mb_substr($why, 0, 250), $meetingId, $userId, $leadTime, $channel]);
    } catch (\Throwable $_) {}
}

/** Where the meeting is, in words — the venue, the video room, or both. */
function meetingWhere(array $meeting): string
{
    $type = $meeting['meeting_type'] ?? 'physical';
    if ($type === 'virtual') return 'Online — video room';
    $venue = trim((string)($meeting['venue'] ?? ''));
    if ($type === 'hybrid') return ($venue !== '' ? $venue . ' — and online' : 'In person and online');
    return $venue !== '' ? $venue : 'Venue to be confirmed';
}

function meetingReminderEmailBody(array $meeting, array $user, string $leadLabel): string
{
    $url    = rtrim(BASE_URL, '/') . '/modules/meetings/view.php?id=' . (int)$meeting['id'];
    $when   = date('l j F Y \a\t H:i', strtotime($meeting['scheduled_start']));
    $agenda = '';

    try {
        $st = getDB()->prepare("SELECT title FROM meeting_agenda_items
                                WHERE meeting_id = ? ORDER BY position, id LIMIT 12");
        $st->execute([(int)$meeting['id']]);
        $items = $st->fetchAll(PDO::FETCH_COLUMN);
        if ($items) {
            $agenda = '<p style="margin:18px 0 6px;font-weight:600;color:#111">Agenda</p><ol style="margin:0;padding-left:20px;color:#333">';
            foreach ($items as $t) $agenda .= '<li style="margin-bottom:4px">' . htmlspecialchars($t) . '</li>';
            $agenda .= '</ol>';
        }
    } catch (\Throwable $_) {}

    $joinBtn = '';
    if (($meeting['meeting_type'] ?? '') !== 'physical' && !empty($meeting['room_code'])) {
        $room = rtrim(BASE_URL, '/') . '/modules/meetings/room.php?id=' . (int)$meeting['id'];
        $joinBtn = '<a href="' . htmlspecialchars($room) . '" style="display:inline-block;background:#7e22ce;'
                 . 'color:#fff;text-decoration:none;padding:11px 22px;border-radius:8px;font-weight:600;'
                 . 'font-size:14px;margin-right:8px">Join the video room</a>';
    }

    return '<div style="font-family:Segoe UI,Helvetica,Arial,sans-serif;max-width:560px;margin:0 auto">'
        . '<p style="font-size:15px;color:#111">Hi ' . htmlspecialchars($user['name'] ?: 'there') . ',</p>'
        . '<p style="font-size:15px;color:#111">This is a reminder that <strong>'
        . htmlspecialchars($meeting['title']) . '</strong> starts <strong>' . htmlspecialchars($leadLabel)
        . '</strong>.</p>'
        . '<table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:14px;color:#333">'
        . '<tr><td style="padding:6px 0;width:90px;color:#666">When</td><td style="padding:6px 0">' . $when . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#666">Where</td><td style="padding:6px 0">'
        . htmlspecialchars(meetingWhere($meeting)) . '</td></tr>'
        . (!empty($meeting['organiser_name'])
            ? '<tr><td style="padding:6px 0;color:#666">Organiser</td><td style="padding:6px 0">'
              . htmlspecialchars($meeting['organiser_name']) . '</td></tr>' : '')
        . '</table>'
        . $agenda
        . '<p style="margin:22px 0 8px">' . $joinBtn
        . '<a href="' . htmlspecialchars($url) . '" style="display:inline-block;border:1px solid #d1d5db;'
        . 'color:#111;text-decoration:none;padding:11px 22px;border-radius:8px;font-weight:600;font-size:14px">'
        . 'Meeting details</a></p>'
        . '<p style="font-size:12px;color:#888;margin-top:24px">You are receiving this because you are '
        . 'on the participant list for this meeting.</p></div>';
}

/**
 * Sends every reminder currently due.
 *
 * @return array{sent_email:int,sent_system:int,failed:int,meetings:int}
 */
function meetingDispatchReminders(PDO $db, bool $quiet = true): array
{
    $out = ['sent_email' => 0, 'sent_system' => 0, 'failed' => 0, 'meetings' => 0];
    $log = function (string $m) use ($quiet) { if (!$quiet) echo $m . PHP_EOL; };

    foreach (array_keys(meetingReminderLeadTimes()) as $leadTime) {
        $label    = meetingReminderLeadTimes()[$leadTime]['label'];
        $meetings = meetingsDueForReminder($db, $leadTime);
        if ($meetings) $out['meetings'] += count($meetings);

        foreach ($meetings as $meeting) {
            $mid  = (int)$meeting['id'];
            $when = date('D j M, H:i', strtotime($meeting['scheduled_start']));
            $log("  [{$leadTime}] {$meeting['title']} — {$when}");

            foreach (meetingReminderRecipients($db, $meeting) as $user) {
                $uid = (int)$user['id'];

                // In-system notification.
                if (meetingReminderClaim($db, $mid, $uid, $leadTime, 'system')) {
                    try {
                        createNotification(
                            $uid, 'meeting',
                            'Meeting ' . $label . ': ' . $meeting['title'],
                            $when . ' · ' . meetingWhere($meeting),
                            BASE_URL . '/modules/meetings/view.php?id=' . $mid
                        );
                        $out['sent_system']++;
                    } catch (\Throwable $e) {
                        $out['failed']++;
                        meetingReminderFailed($db, $mid, $uid, $leadTime, 'system', $e->getMessage());
                    }
                }

                // Email.
                $email = trim((string)($user['email'] ?? ''));
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    if (meetingReminderClaim($db, $mid, $uid, $leadTime, 'email')) {
                        $subject = 'Reminder: ' . $meeting['title'] . ' — ' . $when;
                        $res = sendMail($email, (string)($user['name'] ?? ''), $subject,
                                        meetingReminderEmailBody($meeting, $user, $label),
                                        'meeting', $mid);
                        if (!empty($res['ok'])) {
                            $out['sent_email']++;
                        } else {
                            $out['failed']++;
                            meetingReminderFailed($db, $mid, $uid, $leadTime, 'email',
                                                  (string)($res['error'] ?? 'send failed'));
                            $log('      email to ' . $email . ' failed: ' . ($res['error'] ?? ''));
                        }
                    }
                }
            }
        }
    }
    return $out;
}

} // function_exists('meetingReminderLeadTimes')
