<?php
/**
 * Meetings — schedule a new meeting or edit an existing one.
 *
 * Details, participants and the agenda are saved together: a meeting invitation
 * that arrives without an agenda tends to produce a meeting without a purpose.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_bootstrap.php';
requireLogin();
canWrite('meetings') || redirect(BASE_URL . '/modules/meetings/index.php');

$db = getDB();
meetingsMigrate($db);

$me   = authUser();
$meId = (int)$me['id'];
$id   = (int)($_GET['id'] ?? 0);
$errors = [];

$meeting = null;
if ($id) {
    $st = $db->prepare("SELECT * FROM meetings WHERE id = ?");
    $st->execute([$id]);
    $meeting = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$meeting) { setFlash('error', 'That meeting no longer exists.'); redirect(BASE_URL . '/modules/meetings/index.php'); }
    if (!meetingCanEdit($db, $meeting, $meId)) {
        setFlash('error', 'Only the organiser, chair or secretary can change this meeting.');
        redirect(BASE_URL . '/modules/meetings/view.php?id=' . $id);
    }
}

$users = meetingInvitableUsers($db);

// ── Save ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $title   = trim($_POST['title'] ?? '');
    $purpose = trim($_POST['purpose'] ?? '');
    $type    = isset(meetingTypes()[$_POST['meeting_type'] ?? '']) ? $_POST['meeting_type'] : 'physical';
    $venue   = trim($_POST['venue'] ?? '');
    $start   = trim($_POST['scheduled_start'] ?? '');
    $end     = trim($_POST['scheduled_end'] ?? '');
    $organiser = (int)($_POST['organiser_id'] ?? $meId) ?: $meId;

    if ($title === '') $errors[] = 'Give the meeting a title.';
    if ($start === '' || !strtotime($start)) $errors[] = 'Set when the meeting starts.';
    if ($end !== '' && strtotime($end) && strtotime($end) <= strtotime($start)) {
        $errors[] = 'The end time must be after the start time.';
    }
    if ($type === 'physical' && $venue === '') $errors[] = 'Give a venue for an in-person meeting.';

    // ── Recurrence (only offered when creating) ──────────────────────────────
    $repFreq   = $meeting ? '' : (string)($_POST['repeat_freq'] ?? '');
    if (!isset(meetingFrequencies()[$repFreq])) $repFreq = '';
    $repDays   = meetingParseWeekdays(implode(',', array_map('intval', $_POST['repeat_days'] ?? [])));
    $repMonthD = (int)($_POST['repeat_monthday'] ?? 0);
    // "No end date" leaves the field disabled, so an absent value means forever
    // rather than an empty date that could be read as "ends immediately".
    $repEndsOn = (($_POST['repeat_end_mode'] ?? 'never') === 'on')
               ? trim((string)($_POST['repeat_ends_on'] ?? '')) : '';

    if ($repFreq === 'weekly' && !$repDays) {
        $errors[] = 'Choose which days of the week the meeting repeats on.';
    }
    if ($repFreq === 'monthly' && ($repMonthD < 1 || $repMonthD > 31)) {
        $errors[] = 'Choose which day of the month the meeting repeats on (1–31).';
    }
    if ($repFreq && ($_POST['repeat_end_mode'] ?? '') === 'on') {
        if ($repEndsOn === '' || !strtotime($repEndsOn)) {
            $errors[] = 'Set the date the repeating meeting should stop, or choose "No end date".';
        } elseif ($start !== '' && strtotime($repEndsOn) < strtotime(date('Y-m-d', strtotime($start)))) {
            $errors[] = 'The repeat end date cannot be before the first meeting.';
        }
    }

    // Participants
    $partIds  = array_values(array_unique(array_map('intval', $_POST['participants'] ?? [])));
    $partRole = $_POST['participant_role'] ?? [];

    // Agenda — rows with a blank title are simply ignored, so the operator can
    // leave spare inputs empty rather than having to delete them.
    $agenda = [];
    foreach (($_POST['agenda_title'] ?? []) as $i => $t) {
        $t = trim((string)$t);
        if ($t === '') continue;
        $agenda[] = [
            'title'        => $t,
            'detail'       => trim((string)($_POST['agenda_detail'][$i] ?? '')),
            'presenter_id' => (int)($_POST['agenda_presenter'][$i] ?? 0) ?: null,
            'duration_min' => (int)($_POST['agenda_duration'][$i] ?? 0) ?: null,
        ];
    }

    if (!$errors) {
        try {
            $db->beginTransaction();

            $startSql = date('Y-m-d H:i:s', strtotime($start));
            $endSql   = ($end !== '' && strtotime($end)) ? date('Y-m-d H:i:s', strtotime($end)) : null;

            if ($meeting) {
                $db->prepare("UPDATE meetings SET title=?, purpose=?, meeting_type=?, venue=?,
                              scheduled_start=?, scheduled_end=?, organiser_id=? WHERE id=?")
                   ->execute([$title, $purpose ?: null, $type, $venue ?: null,
                              $startSql, $endSql, $organiser, $id]);
                // A meeting that becomes virtual later still needs a room.
                if ($type !== 'physical' && empty($meeting['room_code'])) {
                    $db->prepare("UPDATE meetings SET room_code=? WHERE id=?")->execute([meetingRoomCode(), $id]);
                }
                logActivity('update', 'meetings', $id, "Updated meeting: {$title}");
            } else {
                $db->prepare("INSERT INTO meetings (title,purpose,meeting_type,venue,room_code,
                              scheduled_start,scheduled_end,status,organiser_id,created_by)
                              VALUES (?,?,?,?,?,?,?, 'scheduled', ?, ?)")
                   ->execute([$title, $purpose ?: null, $type, $venue ?: null,
                              $type === 'physical' ? null : meetingRoomCode(),
                              $startSql, $endSql, $organiser, $meId]);
                $id = (int)$db->lastInsertId();
                logActivity('create', 'meetings', $id, "Scheduled meeting: {$title}");
            }

            // ── Participants ──────────────────────────────────────────────────
            // The organiser is always in the room, whether or not they ticked
            // their own name.
            if (!in_array($organiser, $partIds, true)) $partIds[] = $organiser;

            $existing = [];
            $ex = $db->prepare("SELECT user_id FROM meeting_participants WHERE meeting_id = ?");
            $ex->execute([$id]);
            foreach ($ex->fetchAll(PDO::FETCH_COLUMN) as $uid) $existing[] = (int)$uid;

            $ins = $db->prepare("INSERT INTO meeting_participants (meeting_id,user_id,role)
                                 VALUES (?,?,?) ON DUPLICATE KEY UPDATE role = VALUES(role)");
            foreach ($partIds as $uid) {
                if ($uid < 1) continue;
                $r = $partRole[$uid] ?? 'attendee';
                if (!isset(meetingParticipantRoles()[$r])) $r = 'attendee';
                if ($uid === $organiser && $r === 'attendee') $r = 'chair';
                $ins->execute([$id, $uid, $r]);
            }
            // Removing someone drops their invitation but leaves any deliverable
            // already assigned to them intact — that is still their job to do.
            $drop = array_diff($existing, $partIds);
            if ($drop) {
                $in = implode(',', array_fill(0, count($drop), '?'));
                $db->prepare("DELETE FROM meeting_participants WHERE meeting_id = ? AND user_id IN ({$in})")
                   ->execute(array_merge([$id], array_values($drop)));
            }

            // ── Agenda ────────────────────────────────────────────────────────
            // Notes already written against an item must survive a re-save, so
            // items are matched by id where one was submitted.
            $keepIds = array_values(array_filter(array_map('intval', $_POST['agenda_id'] ?? [])));
            if ($keepIds) {
                $in = implode(',', array_fill(0, count($keepIds), '?'));
                $db->prepare("DELETE FROM meeting_agenda_items WHERE meeting_id = ? AND id NOT IN ({$in})")
                   ->execute(array_merge([$id], $keepIds));
            } else {
                $db->prepare("DELETE FROM meeting_agenda_items WHERE meeting_id = ?")->execute([$id]);
            }

            $pos = 0;
            $insA = $db->prepare("INSERT INTO meeting_agenda_items
                    (meeting_id,position,title,detail,presenter_id,duration_min) VALUES (?,?,?,?,?,?)");
            $updA = $db->prepare("UPDATE meeting_agenda_items
                    SET position=?, title=?, detail=?, presenter_id=?, duration_min=? WHERE id=? AND meeting_id=?");
            foreach (($_POST['agenda_title'] ?? []) as $i => $t) {
                $t = trim((string)$t);
                if ($t === '') continue;
                $pos++;
                $rowId    = (int)($_POST['agenda_id'][$i] ?? 0);
                $detail   = trim((string)($_POST['agenda_detail'][$i] ?? '')) ?: null;
                $pres     = (int)($_POST['agenda_presenter'][$i] ?? 0) ?: null;
                $dur      = (int)($_POST['agenda_duration'][$i] ?? 0) ?: null;
                if ($rowId) $updA->execute([$pos, $t, $detail, $pres, $dur, $rowId, $id]);
                else        $insA->execute([$id, $pos, $t, $detail, $pres, $dur]);
            }

            // ── Recurring series ──────────────────────────────────────────────
            // Created last, inside the same transaction, because the meeting just
            // saved becomes the template every later occurrence is cloned from —
            // it needs its participants and agenda already in place.
            $seriesId = 0;
            if ($repFreq) {
                $firstDate = date('Y-m-d', strtotime($startSql));
                $durMin    = $endSql
                    ? max(0, (int)round((strtotime($endSql) - strtotime($startSql)) / 60))
                    : null;

                $db->prepare("INSERT INTO meeting_series
                        (frequency, weekdays, month_day, time_of_day, duration_min,
                         starts_on, ends_on, template_meeting_id, created_by)
                        VALUES (?,?,?,?,?,?,?,?,?)")
                   ->execute([
                       $repFreq,
                       $repFreq === 'weekly'  ? implode(',', $repDays) : null,
                       $repFreq === 'monthly' ? $repMonthD : null,
                       date('H:i:s', strtotime($startSql)),
                       $durMin,
                       $firstDate,
                       $repEndsOn !== '' ? date('Y-m-d', strtotime($repEndsOn)) : null,
                       $id,
                       $meId,
                   ]);
                $seriesId = (int)$db->lastInsertId();

                // The meeting that was just saved is the first occurrence.
                $db->prepare("UPDATE meetings SET series_id=?, occurrence_date=? WHERE id=?")
                   ->execute([$seriesId, $firstDate, $id]);
            }

            $db->commit();

            // Fill in the rest of the calendar. Outside the transaction: this can
            // create a hundred rows, and a slow write should not hold the lock on
            // a meeting that is already safely saved.
            $madeCount = 0;
            if ($seriesId) {
                try { $madeCount = meetingSeriesMaterialise($db, $seriesId); }
                catch (\Throwable $e) { error_log('meetings/series: ' . $e->getMessage()); }
            }

            // Tell people they are expected. Sent after the commit so a failed
            // save never produces an invitation to a meeting that does not exist.
            try {
                require_once __DIR__ . '/../../includes/notifications.php';
                $when = date('D j M, H:i', strtotime($startSql));
                foreach ($partIds as $uid) {
                    if ($uid === $meId) continue;
                    createNotification($uid, 'meeting',
                        ($meeting ? 'Meeting updated: ' : 'Meeting invitation: ') . $title,
                        $when . ($venue ? ' · ' . $venue : ''),
                        BASE_URL . '/modules/meetings/view.php?id=' . $id);
                }
            } catch (\Throwable $_) {}

            if ($seriesId) {
                $sr = $db->prepare("SELECT * FROM meeting_series WHERE id=?");
                $sr->execute([$seriesId]);
                $srow = $sr->fetch(PDO::FETCH_ASSOC) ?: [];
                setFlash('success', 'Recurring meeting scheduled — ' . meetingSeriesDescribe($srow)
                    . '. ' . ($madeCount + 1) . ' date' . ($madeCount === 0 ? '' : 's') . ' on the calendar'
                    . (empty($srow['ends_on']) ? ' so far; more are added automatically.' : '.'));
            } else {
                setFlash('success', $meeting ? 'Meeting updated.' : 'Meeting scheduled and everyone invited.');
            }
            redirect(BASE_URL . '/modules/meetings/view.php?id=' . $id);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('meetings/edit: ' . $e->getMessage());
            $errors[] = 'Could not save the meeting: ' . $e->getMessage();
        }
    }
}

// ── Current values for the form ──────────────────────────────────────────────
$curParticipants = [];
$curAgenda = [];
if ($id && $meeting) {
    try {
        $st = $db->prepare("SELECT user_id, role FROM meeting_participants WHERE meeting_id = ?");
        $st->execute([$id]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $curParticipants[(int)$r['user_id']] = $r['role'];

        $st = $db->prepare("SELECT * FROM meeting_agenda_items WHERE meeting_id = ? ORDER BY position, id");
        $st->execute([$id]);
        $curAgenda = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $_) {}
}

$v = static function (string $field, $default = '') use ($meeting) {
    if (isset($_POST[$field])) return $_POST[$field];
    return $meeting[$field] ?? $default;
};

$pageTitle = $meeting ? 'Edit Meeting' : 'Schedule Meeting';
include __DIR__ . '/../../includes/header.php';
?>
<style>
.me-card{ background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); box-shadow:var(--sh-sm); margin-bottom:16px; overflow:hidden; }
.me-card-head{ padding:12px 16px; border-bottom:1px solid var(--border); background:var(--surface-alt);
    display:flex; align-items:center; justify-content:space-between; gap:10px; }
.me-card-title{ font-size:13.5px; font-weight:700; color:var(--text); margin:0; display:flex; align-items:center; gap:8px; }
.me-card-title i{ color:var(--brand); }
.me-card-body{ padding:16px; }
.form-label{ font-size:12px; font-weight:600; color:var(--text); margin-bottom:4px; }

.me-people{ display:grid; grid-template-columns:repeat(auto-fill,minmax(230px,1fr)); gap:8px; }
.me-person{ display:flex; align-items:center; gap:9px; padding:8px 10px; border:1px solid var(--border);
    border-radius:var(--r); background:var(--surface-alt); cursor:pointer; }
.me-person:hover{ border-color:var(--brand); }
.me-person input{ margin:0; }
.me-av{ width:28px; height:28px; border-radius:50%; flex:0 0 28px; color:#fff; font-size:10.5px; font-weight:800;
    display:flex; align-items:center; justify-content:center; }
.me-person-n{ font-size:12.5px; font-weight:600; color:var(--text); line-height:1.2; }
.me-person-r{ font-size:10.5px; color:var(--text-2,#64748b); }
.me-person select{ margin-left:auto; font-size:11px; padding:2px 4px; border-radius:5px;
    border:1px solid var(--border); background:var(--surface); color:var(--text); }

.ag-row{ display:grid; grid-template-columns:26px minmax(0,2fr) minmax(0,2fr) minmax(0,1.2fr) 84px 34px;
    gap:8px; align-items:start; padding:9px 0; border-bottom:1px solid var(--border); }
.ag-row:last-of-type{ border-bottom:0; }
@media(max-width:900px){ .ag-row{ grid-template-columns:1fr; } }
.ag-num{ width:24px; height:24px; border-radius:50%; background:var(--brand-soft); color:var(--brand);
    font-size:11px; font-weight:800; display:flex; align-items:center; justify-content:center; margin-top:5px; }
.ag-head{ display:grid; grid-template-columns:26px minmax(0,2fr) minmax(0,2fr) minmax(0,1.2fr) 84px 34px;
    gap:8px; font-size:10.5px; font-weight:800; text-transform:uppercase; letter-spacing:.5px;
    color:var(--text-2,#64748b); padding-bottom:6px; border-bottom:1px solid var(--border); }
@media(max-width:900px){ .ag-head{ display:none; } }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 style="font-size:21px;font-weight:800;letter-spacing:-.4px;margin:0;color:var(--text)">
        <i class="fa fa-calendar-plus me-2" style="color:var(--brand)"></i><?= $meeting ? 'Edit Meeting' : 'Schedule a Meeting' ?>
    </h1>
    <a href="<?= $meeting ? BASE_URL . '/modules/meetings/view.php?id=' . $id : BASE_URL . '/modules/meetings/index.php' ?>"
       class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Cancel</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger py-2">
    <ul class="mb-0 small ps-3"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form method="POST">
    <?= csrfField() ?>

    <div class="me-card">
        <div class="me-card-head"><h2 class="me-card-title"><i class="fa fa-circle-info"></i>Details</h2></div>
        <div class="me-card-body">
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">Title <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control form-control-sm" required
                           value="<?= e($v('title')) ?>" placeholder="e.g. Weekly Sales Review">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Organiser</label>
                    <select name="organiser_id" class="form-select form-select-sm">
                        <?php foreach ($users as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= (int)$v('organiser_id', $meId) === (int)$u['id'] ? 'selected' : '' ?>>
                            <?= e($u['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Purpose</label>
                    <textarea name="purpose" rows="2" class="form-control form-control-sm"
                              placeholder="What this meeting is for"><?= e($v('purpose')) ?></textarea>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Format</label>
                    <select name="meeting_type" id="meetingType" class="form-select form-select-sm">
                        <?php foreach (meetingTypes() as $k => $t): ?>
                        <option value="<?= $k ?>" <?= $v('meeting_type', 'physical') === $k ? 'selected' : '' ?>><?= $t[0] ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text" style="font-size:11px">Virtual and hybrid get a video room in the system.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Venue</label>
                    <input type="text" name="venue" id="venueInput" class="form-control form-control-sm"
                           value="<?= e($v('venue')) ?>" placeholder="e.g. Boardroom">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Starts <span class="text-danger">*</span></label>
                    <input type="datetime-local" name="scheduled_start" class="form-control form-control-sm" required
                           value="<?= e($meeting && !isset($_POST['scheduled_start'])
                                   ? date('Y-m-d\TH:i', strtotime($meeting['scheduled_start']))
                                   : ($_POST['scheduled_start'] ?? date('Y-m-d\TH:i', strtotime('+1 hour')))) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Ends</label>
                    <input type="datetime-local" name="scheduled_end" class="form-control form-control-sm"
                           value="<?= e($meeting && !isset($_POST['scheduled_end']) && $meeting['scheduled_end']
                                   ? date('Y-m-d\TH:i', strtotime($meeting['scheduled_end']))
                                   : ($_POST['scheduled_end'] ?? '')) ?>">
                </div>
            </div>
        </div>
    </div>

    <?php /* ── Recurrence ──────────────────────────────────────────────────────
         Only offered when creating. Changing the rule of a running series is a
         different operation — it has to decide what happens to occurrences that
         already have minutes and deliverables against them — so an existing
         series is managed from the meeting page instead. */ ?>
    <?php if (!$meeting): ?>
    <div class="me-card">
        <div class="me-card-head">
            <h2 class="me-card-title"><i class="fa fa-repeat"></i>Repeat</h2>
            <span class="small text-muted">For meetings that run on a schedule</span>
        </div>
        <div class="me-card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Repeats</label>
                    <select name="repeat_freq" id="repeatFreq" class="form-select form-select-sm">
                        <option value="">Does not repeat</option>
                        <?php foreach (meetingFrequencies() as $k => $lbl): ?>
                        <option value="<?= $k ?>" <?= ($_POST['repeat_freq'] ?? '') === $k ? 'selected' : '' ?>>
                            <?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-9 rp-when rp-weekly" style="display:none">
                    <label class="form-label">On these days</label>
                    <div class="d-flex gap-2 flex-wrap">
                        <?php $selDays = array_map('intval', $_POST['repeat_days'] ?? []); ?>
                        <?php foreach (meetingWeekdayNames() as $n => $dayName): ?>
                        <label class="rp-day">
                            <input type="checkbox" name="repeat_days[]" value="<?= $n ?>"
                                   <?= in_array($n, $selDays, true) ? 'checked' : '' ?>>
                            <span><?= e(substr($dayName, 0, 3)) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="col-md-3 rp-when rp-monthly" style="display:none">
                    <label class="form-label">Day of the month</label>
                    <input type="number" name="repeat_monthday" min="1" max="31"
                           class="form-control form-control-sm"
                           value="<?= e($_POST['repeat_monthday'] ?? '') ?>" placeholder="e.g. 15">
                    <div class="form-text" style="font-size:11px">
                        The 29th–31st falls on the last day in shorter months.
                    </div>
                </div>

                <div class="col-12 rp-when" style="display:none"><hr class="my-1"></div>

                <div class="col-md-4 rp-when" style="display:none">
                    <label class="form-label">Until</label>
                    <div class="btn-group w-100" role="group">
                        <input type="radio" class="btn-check" name="repeat_end_mode" id="rpEndNever" value="never"
                               <?= ($_POST['repeat_end_mode'] ?? 'never') === 'never' ? 'checked' : '' ?>>
                        <label class="btn btn-outline-secondary btn-sm" for="rpEndNever">No end date</label>
                        <input type="radio" class="btn-check" name="repeat_end_mode" id="rpEndOn" value="on"
                               <?= ($_POST['repeat_end_mode'] ?? '') === 'on' ? 'checked' : '' ?>>
                        <label class="btn btn-outline-secondary btn-sm" for="rpEndOn">Ends on</label>
                    </div>
                </div>
                <div class="col-md-3 rp-when" style="display:none">
                    <label class="form-label">End date</label>
                    <input type="date" name="repeat_ends_on" id="rpEndsOn" class="form-control form-control-sm"
                           value="<?= e($_POST['repeat_ends_on'] ?? '') ?>" disabled>
                </div>
                <div class="col-md-5 rp-when" style="display:none">
                    <div class="alert alert-light border py-2 mb-0" style="font-size:12px">
                        <i class="fa fa-circle-info me-1"></i>
                        <span id="rpSummary" class="text-muted">Pick the days this meeting runs on.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
    .rp-day{ cursor:pointer; user-select:none; }
    .rp-day input{ position:absolute; opacity:0; width:0; height:0; }
    .rp-day span{
        display:inline-flex; align-items:center; justify-content:center;
        min-width:52px; padding:7px 10px; border:1px solid var(--border,#dee2e6);
        border-radius:8px; font-size:12.5px; font-weight:600; transition:.12s;
    }
    .rp-day input:checked + span{ background:#7e22ce; border-color:#7e22ce; color:#fff; }
    .rp-day input:focus-visible + span{ outline:2px solid #7e22ce; outline-offset:2px; }
    </style>
    <?php endif; ?>

    <div class="me-card">
        <div class="me-card-head">
            <h2 class="me-card-title"><i class="fa fa-users"></i>Who is attending</h2>
            <span class="small text-muted">The organiser is always included</span>
        </div>
        <div class="me-card-body">
            <div class="me-people">
                <?php foreach ($users as $u):
                    $uid = (int)$u['id'];
                    $checked = isset($_POST['participants'])
                        ? in_array($uid, array_map('intval', $_POST['participants']), true)
                        : isset($curParticipants[$uid]);
                    $prole = $_POST['participant_role'][$uid] ?? ($curParticipants[$uid] ?? 'attendee');
                ?>
                <label class="me-person">
                    <input type="checkbox" name="participants[]" value="<?= $uid ?>" <?= $checked ? 'checked' : '' ?>>
                    <span class="me-av" style="background:<?= meetingAvatarColor($uid) ?>"><?= e(meetingInitials($u['name'])) ?></span>
                    <span style="min-width:0">
                        <span class="me-person-n d-block text-truncate"><?= e($u['name']) ?></span>
                        <span class="me-person-r"><?= e(ucwords(str_replace('_', ' ', (string)$u['role']))) ?></span>
                    </span>
                    <select name="participant_role[<?= $uid ?>]" onclick="event.stopPropagation()">
                        <?php foreach (meetingParticipantRoles() as $rk => $rl): ?>
                        <option value="<?= $rk ?>" <?= $prole === $rk ? 'selected' : '' ?>><?= $rl ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="me-card">
        <div class="me-card-head">
            <h2 class="me-card-title"><i class="fa fa-list-ol"></i>Agenda</h2>
            <button type="button" class="btn btn-xs btn-outline-primary" id="addAgenda">
                <i class="fa fa-plus me-1"></i>Add item
            </button>
        </div>
        <div class="me-card-body">
            <div class="ag-head">
                <span></span><span>Item</span><span>Detail</span><span>Led by</span><span>Minutes</span><span></span>
            </div>
            <div id="agendaRows">
                <?php
                $rows = $curAgenda;
                if (isset($_POST['agenda_title'])) {
                    $rows = [];
                    foreach ($_POST['agenda_title'] as $i => $t) {
                        $rows[] = ['id' => $_POST['agenda_id'][$i] ?? '', 'title' => $t,
                                   'detail' => $_POST['agenda_detail'][$i] ?? '',
                                   'presenter_id' => $_POST['agenda_presenter'][$i] ?? '',
                                   'duration_min' => $_POST['agenda_duration'][$i] ?? ''];
                    }
                }
                if (!$rows) $rows = [['id'=>'','title'=>'','detail'=>'','presenter_id'=>'','duration_min'=>'']];
                foreach ($rows as $n => $r): ?>
                <div class="ag-row">
                    <span class="ag-num"><?= $n + 1 ?></span>
                    <div>
                        <input type="hidden" name="agenda_id[]" value="<?= e((string)($r['id'] ?? '')) ?>">
                        <input type="text" name="agenda_title[]" class="form-control form-control-sm"
                               value="<?= e((string)$r['title']) ?>" placeholder="Agenda item">
                    </div>
                    <div><input type="text" name="agenda_detail[]" class="form-control form-control-sm"
                                value="<?= e((string)($r['detail'] ?? '')) ?>" placeholder="Optional detail"></div>
                    <div>
                        <select name="agenda_presenter[]" class="form-select form-select-sm">
                            <option value="">—</option>
                            <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>" <?= (int)($r['presenter_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>>
                                <?= e($u['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div><input type="number" name="agenda_duration[]" min="0" class="form-control form-control-sm"
                                value="<?= e((string)($r['duration_min'] ?? '')) ?>" placeholder="15"></div>
                    <div><button type="button" class="btn btn-sm btn-outline-danger ag-del" title="Remove"><i class="fa fa-xmark"></i></button></div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="form-text mt-2" style="font-size:11px">
                Minutes are written against these items during the meeting, so the record follows the same order.
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mb-4">
        <button class="btn btn-primary"><i class="fa fa-floppy-disk me-1"></i><?= $meeting ? 'Save changes' : 'Schedule &amp; invite' ?></button>
        <a href="<?= $meeting ? BASE_URL . '/modules/meetings/view.php?id=' . $id : BASE_URL . '/modules/meetings/index.php' ?>"
           class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>

<script>
(function () {
    var wrap = document.getElementById('agendaRows');

    function renumber() {
        wrap.querySelectorAll('.ag-row').forEach(function (r, i) {
            var n = r.querySelector('.ag-num'); if (n) n.textContent = i + 1;
        });
    }

    document.getElementById('addAgenda').addEventListener('click', function () {
        var first = wrap.querySelector('.ag-row');
        var row = first.cloneNode(true);
        row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
        row.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; });
        wrap.appendChild(row);
        renumber();
        row.querySelector('input[name="agenda_title[]"]').focus();
    });

    wrap.addEventListener('click', function (e) {
        var btn = e.target.closest('.ag-del');
        if (!btn) return;
        // Always leave one row behind, otherwise there is nothing to clone from
        // and no way to add an item back.
        if (wrap.querySelectorAll('.ag-row').length === 1) {
            btn.closest('.ag-row').querySelectorAll('input').forEach(function (i) { i.value = ''; });
            return;
        }
        btn.closest('.ag-row').remove();
        renumber();
    });

    // Venue is only meaningful when people are physically somewhere.
    var type = document.getElementById('meetingType');
    var venue = document.getElementById('venueInput');
    function syncVenue() {
        var virtual = type.value === 'virtual';
        venue.placeholder = virtual ? 'Not needed for a virtual meeting' : 'e.g. Boardroom';
        venue.disabled = virtual;
    }
    type.addEventListener('change', syncVenue);
    syncVenue();

    // ── Recurrence ──────────────────────────────────────────────────────────
    var freq = document.getElementById('repeatFreq');
    if (freq) {
        var endsOn  = document.getElementById('rpEndsOn');
        var summary = document.getElementById('rpSummary');
        var startEl = document.querySelector('input[name="scheduled_start"]');
        var NAMES   = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

        function checkedDays() {
            return Array.prototype.slice
                .call(document.querySelectorAll('input[name="repeat_days[]"]:checked'))
                .map(function (c) { return parseInt(c.value, 10); })
                .sort(function (a, b) { return a - b; });
        }

        function describe() {
            var f = freq.value;
            if (!f) return '';
            var time = startEl && startEl.value ? startEl.value.slice(11, 16) : '';
            var what;
            if (f === 'daily') {
                what = 'Every day';
            } else if (f === 'weekly') {
                var d = checkedDays().map(function (n) { return NAMES[n]; });
                if (!d.length) return 'Pick at least one day.';
                what = d.length === 1 ? 'Every ' + d[0]
                     : 'Every ' + d.slice(0, -1).join(', ') + ' & ' + d[d.length - 1];
            } else {
                var md = document.querySelector('input[name="repeat_monthday"]');
                var n  = md && md.value ? parseInt(md.value, 10) : 0;
                if (!n) return 'Pick a day of the month.';
                what = 'Monthly on day ' + n;
            }
            if (time) what += ' at ' + time;
            var onMode = document.getElementById('rpEndOn');
            if (onMode && onMode.checked) {
                what += endsOn.value ? ', until ' + endsOn.value : ', until — pick an end date';
            } else {
                what += ', with no end date';
            }
            return what;
        }

        function sync() {
            var on = !!freq.value;
            document.querySelectorAll('.rp-when').forEach(function (el) {
                var show = on && (
                    (!el.classList.contains('rp-weekly')  || freq.value === 'weekly') &&
                    (!el.classList.contains('rp-monthly') || freq.value === 'monthly')
                );
                el.style.display = show ? '' : 'none';
            });
            var byDate = document.getElementById('rpEndOn');
            // A disabled input is not submitted, which is what keeps an empty
            // date from being read as "ends today" when "No end date" is chosen.
            endsOn.disabled = !(on && byDate && byDate.checked);
            if (summary) summary.textContent = describe() || 'Pick the days this meeting runs on.';
        }

        freq.addEventListener('change', sync);
        document.addEventListener('change', function (e) {
            if (e.target.name === 'repeat_days[]' || e.target.name === 'repeat_end_mode' ||
                e.target.name === 'repeat_monthday' || e.target.name === 'repeat_ends_on' ||
                e.target.name === 'scheduled_start') sync();
        });
        document.addEventListener('input', function (e) {
            if (e.target.name === 'repeat_monthday') sync();
        });
        sync();
    }
}());
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
