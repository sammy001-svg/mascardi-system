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

            $db->commit();

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

            setFlash('success', $meeting ? 'Meeting updated.' : 'Meeting scheduled and everyone invited.');
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
}());
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
