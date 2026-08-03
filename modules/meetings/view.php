<?php
/**
 * Meetings — the meeting itself.
 *
 * Agenda, minutes and the deliverables agreed. Everything is on one page on
 * purpose: minutes get written while the meeting runs, and an action captured
 * on a different screen is an action that never gets captured.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_bootstrap.php';
requireLogin();
canAccess('meetings') || redirect(BASE_URL . '/index.php');

$db = getDB();
meetingsMigrate($db);
meetingAutoStatus($db);

$me   = authUser();
$meId = (int)$me['id'];
$id   = (int)($_GET['id'] ?? 0);

$st = $db->prepare("SELECT m.*, u.name AS organiser_name FROM meetings m
                    LEFT JOIN users u ON u.id = m.organiser_id WHERE m.id = ?");
$st->execute([$id]);
$meeting = $st->fetch(PDO::FETCH_ASSOC);
if (!$meeting) { setFlash('error', 'That meeting no longer exists.'); redirect(BASE_URL . '/modules/meetings/index.php'); }

if (!meetingCanView($db, $meeting, $meId)) {
    setFlash('error', 'You were not invited to that meeting.');
    redirect(BASE_URL . '/modules/meetings/index.php');
}
$canEdit = meetingCanEdit($db, $meeting, $meId);
$errors  = [];

// ── Actions ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // Anyone invited can respond to their own invitation.
    if ($action === 'rsvp') {
        $r = in_array($_POST['rsvp'] ?? '', ['accepted','declined'], true) ? $_POST['rsvp'] : 'invited';
        try {
            $db->prepare("UPDATE meeting_participants SET invite_status=? WHERE meeting_id=? AND user_id=?")
               ->execute([$r, $id, $meId]);
            setFlash('success', $r === 'accepted' ? 'You are marked as attending.' : 'You are marked as not attending.');
        } catch (\Throwable $_) {}
        redirect(BASE_URL . '/modules/meetings/view.php?id=' . $id);
    }

    // Deliverable progress — the owner updates their own, editors update any.
    if ($action === 'update_action') {
        $aid    = (int)($_POST['action_id'] ?? 0);
        $status = $_POST['status'] ?? 'pending';
        if (!isset(meetingActionStatuses()[$status])) $status = 'pending';
        try {
            $s = $db->prepare("SELECT * FROM meeting_actions WHERE id=? AND meeting_id=?");
            $s->execute([$aid, $id]);
            $act = $s->fetch(PDO::FETCH_ASSOC);
            if (!$act) {
                setFlash('error', 'That item no longer exists.');
            } elseif (!$canEdit && (int)$act['assigned_to'] !== $meId) {
                setFlash('error', 'You can only update items assigned to you.');
            } else {
                $done = $status === 'done';
                $db->prepare("UPDATE meeting_actions
                              SET status=?, progress_note=?,
                                  completed_at = " . ($done ? 'COALESCE(completed_at, NOW())' : 'NULL') . ",
                                  completed_by = " . ($done ? '?' : 'NULL') . "
                              WHERE id=?")
                   ->execute($done
                       ? [$status, trim($_POST['progress_note'] ?? '') ?: null, $meId, $aid]
                       : [$status, trim($_POST['progress_note'] ?? '') ?: null, $aid]);
                logActivity('update', 'meetings', $id, "Deliverable '{$act['title']}' set to {$status}");
                setFlash('success', 'Updated.');
            }
        } catch (\Throwable $e) {
            error_log('meetings/view update_action: ' . $e->getMessage());
            setFlash('error', 'Could not update that item.');
        }
        redirect(BASE_URL . '/modules/meetings/view.php?id=' . $id . '#actions');
    }

    if ($canEdit) {
        // Save minutes + per-item discussion notes together.
        if ($action === 'save_minutes') {
            try {
                $db->prepare("UPDATE meetings SET minutes=?, minutes_by=?, minutes_at=NOW() WHERE id=?")
                   ->execute([trim($_POST['minutes'] ?? '') ?: null, $meId, $id]);
                $u = $db->prepare("UPDATE meeting_agenda_items SET discussion=?, status=? WHERE id=? AND meeting_id=?");
                foreach (($_POST['discussion'] ?? []) as $itemId => $text) {
                    $text = trim((string)$text);
                    $stt  = ($_POST['item_status'][$itemId] ?? 'pending');
                    if (!in_array($stt, ['pending','discussed','deferred'], true)) $stt = 'pending';
                    $u->execute([$text ?: null, $stt, (int)$itemId, $id]);
                }
                logActivity('update', 'meetings', $id, 'Saved minutes');
                setFlash('success', 'Minutes saved.');
            } catch (\Throwable $e) {
                error_log('meetings/view save_minutes: ' . $e->getMessage());
                setFlash('error', 'Could not save the minutes.');
            }
            redirect(BASE_URL . '/modules/meetings/view.php?id=' . $id . '#minutes');
        }

        if ($action === 'add_action') {
            $title = trim($_POST['title'] ?? '');
            $owner = (int)($_POST['assigned_to'] ?? 0) ?: null;
            $due   = trim($_POST['due_date'] ?? '') ?: null;
            $prio  = in_array($_POST['priority'] ?? '', ['low','normal','high'], true) ? $_POST['priority'] : 'normal';
            $item  = (int)($_POST['agenda_item_id'] ?? 0) ?: null;
            if ($title === '') {
                $errors[] = 'Describe what needs to be done.';
            } else {
                try {
                    $db->prepare("INSERT INTO meeting_actions
                            (meeting_id,agenda_item_id,title,detail,assigned_to,due_date,priority,created_by)
                         VALUES (?,?,?,?,?,?,?,?)")
                       ->execute([$id, $item, $title, trim($_POST['detail'] ?? '') ?: null,
                                  $owner, $due, $prio, $meId]);
                    logActivity('create', 'meetings', $id, "Added deliverable: {$title}");

                    if ($owner && $owner !== $meId) {
                        try {
                            require_once __DIR__ . '/../../includes/notifications.php';
                            createNotification($owner, 'meeting', 'New deliverable assigned to you', $title
                                . ($due ? ' — due ' . date('j M Y', strtotime($due)) : ''),
                                BASE_URL . '/modules/meetings/view.php?id=' . $id . '#actions');
                        } catch (\Throwable $_) {}
                    }
                    setFlash('success', 'Deliverable added.');
                    redirect(BASE_URL . '/modules/meetings/view.php?id=' . $id . '#actions');
                } catch (\Throwable $e) {
                    error_log('meetings/view add_action: ' . $e->getMessage());
                    $errors[] = 'Could not add that deliverable.';
                }
            }
        }

        if ($action === 'set_status') {
            $s = $_POST['status'] ?? '';
            if (isset(meetingStatuses()[$s])) {
                try {
                    $extra = $s === 'in_progress' ? ', actual_start = COALESCE(actual_start, NOW())'
                           : ($s === 'completed' ? ', actual_end = COALESCE(actual_end, NOW())' : '');
                    $db->prepare("UPDATE meetings SET status = ?{$extra} WHERE id = ?")->execute([$s, $id]);
                    logActivity('update', 'meetings', $id, "Meeting marked {$s}");
                } catch (\Throwable $_) {}
            }
            redirect(BASE_URL . '/modules/meetings/view.php?id=' . $id);
        }

        if ($action === 'delete_action') {
            try {
                $db->prepare("DELETE FROM meeting_actions WHERE id=? AND meeting_id=?")
                   ->execute([(int)($_POST['action_id'] ?? 0), $id]);
                setFlash('success', 'Deliverable removed.');
            } catch (\Throwable $_) {}
            redirect(BASE_URL . '/modules/meetings/view.php?id=' . $id . '#actions');
        }
    }
}

// ── Data ──────────────────────────────────────────────────────────────────────
$participants = [];
try {
    $s = $db->prepare("SELECT p.*, u.name FROM meeting_participants p
                       JOIN users u ON u.id = p.user_id
                       WHERE p.meeting_id = ? ORDER BY FIELD(p.role,'chair','secretary','attendee'), u.name");
    $s->execute([$id]);
    $participants = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {}
$myPart = null;
foreach ($participants as $p) if ((int)$p['user_id'] === $meId) $myPart = $p;

$agenda = [];
try {
    $s = $db->prepare("SELECT a.*, u.name AS presenter_name FROM meeting_agenda_items a
                       LEFT JOIN users u ON u.id = a.presenter_id
                       WHERE a.meeting_id = ? ORDER BY a.position, a.id");
    $s->execute([$id]);
    $agenda = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {}

$actions = [];
try {
    $s = $db->prepare("SELECT a.*, u.name AS owner_name, ai.title AS item_title
                       FROM meeting_actions a
                       LEFT JOIN users u ON u.id = a.assigned_to
                       LEFT JOIN meeting_agenda_items ai ON ai.id = a.agenda_item_id
                       WHERE a.meeting_id = ?
                       ORDER BY FIELD(a.status,'blocked','pending','in_progress','done','cancelled'),
                                (a.due_date IS NULL), a.due_date");
    $s->execute([$id]);
    $actions = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {}

$summary = meetingActionSummary($db, $id);
[$sl, $sc] = meetingStatuses()[$meeting['status']] ?? ['Scheduled', '#2563eb'];
$type = meetingTypes()[$meeting['meeting_type']] ?? ['In person', 'fa-building'];
$isVirtual = $meeting['meeting_type'] !== 'physical' && $meeting['room_code'];

$pageTitle = $meeting['title'];
include __DIR__ . '/../../includes/header.php';
?>
<style>
.mv-hero{ background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg);
    box-shadow:var(--sh-sm); padding:20px 22px; margin-bottom:16px; }
.mv-hero h1{ font-size:22px; font-weight:800; letter-spacing:-.4px; margin:0 0 4px; color:var(--text); }
.mv-meta{ font-size:12.5px; color:var(--text-2,#64748b); display:flex; gap:16px; flex-wrap:wrap; margin-top:8px; }
.mv-meta i{ margin-right:5px; opacity:.75; }
.mv-tag{ font-size:11px; font-weight:700; padding:3px 11px; border-radius:20px; white-space:nowrap; }

.mv-cols{ display:grid; grid-template-columns:minmax(0,2fr) minmax(0,1fr); gap:16px; align-items:start; }
@media(max-width:992px){ .mv-cols{ grid-template-columns:minmax(0,1fr); } }

.mv-card{ background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); box-shadow:var(--sh-sm); margin-bottom:16px; overflow:hidden; }
.mv-head{ padding:12px 16px; border-bottom:1px solid var(--border); background:var(--surface-alt);
    display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
.mv-title{ font-size:13.5px; font-weight:700; color:var(--text); margin:0; display:flex; align-items:center; gap:8px; }
.mv-title i{ color:var(--brand); }
.mv-body{ padding:16px; }

.ag-item{ padding:14px 16px; border-bottom:1px solid var(--border); }
.ag-item:last-child{ border-bottom:0; }
.ag-top{ display:flex; align-items:flex-start; gap:10px; }
.ag-n{ width:24px; height:24px; border-radius:50%; flex:0 0 24px; background:var(--brand-soft); color:var(--brand);
    font-size:11px; font-weight:800; display:flex; align-items:center; justify-content:center; }
.ag-t{ font-size:13.5px; font-weight:700; color:var(--text); }
.ag-d{ font-size:12px; color:var(--text-2,#64748b); margin-top:2px; }

.pp{ display:flex; align-items:center; gap:9px; padding:9px 0; border-bottom:1px solid var(--border); }
.pp:last-child{ border-bottom:0; }
.pp-av{ width:30px; height:30px; border-radius:50%; flex:0 0 30px; color:#fff; font-size:11px; font-weight:800;
    display:flex; align-items:center; justify-content:center; }
.pp-n{ font-size:12.5px; font-weight:600; color:var(--text); }
.pp-r{ font-size:11px; color:var(--text-2,#64748b); }

.ac{ padding:12px 16px; border-bottom:1px solid var(--border); }
.ac:last-child{ border-bottom:0; }
.ac-t{ font-size:13.5px; font-weight:700; color:var(--text); }
.ac-m{ font-size:11.5px; color:var(--text-2,#64748b); margin-top:3px; display:flex; gap:12px; flex-wrap:wrap; }
.ac-overdue{ color:#dc2626; font-weight:700; }
.mv-empty{ text-align:center; padding:32px 20px; color:var(--text-2,#64748b); font-size:13px; }
.mv-empty i{ font-size:28px; opacity:.3; display:block; margin-bottom:9px; }
.form-label{ font-size:12px; font-weight:600; color:var(--text); margin-bottom:4px; }
.mv-prog{ height:8px; border-radius:5px; background:var(--surface-alt); border:1px solid var(--border); overflow:hidden; }
.mv-prog > i{ display:block; height:100%; background:#16a34a; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <a href="<?= BASE_URL ?>/modules/meetings/index.php" class="btn btn-sm btn-outline-secondary">
        <i class="fa fa-arrow-left me-1"></i>All meetings
    </a>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($isVirtual): ?>
        <a href="<?= BASE_URL ?>/modules/meetings/room.php?id=<?= $id ?>" class="btn btn-sm btn-success">
            <i class="fa fa-video me-1"></i>Join Video Room
        </a>
        <?php endif; ?>
        <?php if ($canEdit): ?>
        <a href="<?= BASE_URL ?>/modules/meetings/edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-primary">
            <i class="fa fa-pen me-1"></i>Edit
        </a>
        <?php if ($meeting['status'] === 'scheduled'): ?>
        <form method="POST" class="d-inline">
            <?= csrfField() ?><input type="hidden" name="action" value="set_status">
            <input type="hidden" name="status" value="in_progress">
            <button class="btn btn-sm btn-success"><i class="fa fa-play me-1"></i>Start</button>
        </form>
        <?php elseif ($meeting['status'] === 'in_progress'): ?>
        <form method="POST" class="d-inline">
            <?= csrfField() ?><input type="hidden" name="action" value="set_status">
            <input type="hidden" name="status" value="completed">
            <button class="btn btn-sm btn-primary"><i class="fa fa-flag-checkered me-1"></i>End meeting</button>
        </form>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger py-2">
    <ul class="mb-0 small ps-3"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="mv-hero">
    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
        <div style="min-width:0">
            <h1><?= e($meeting['title']) ?></h1>
            <?php if ($meeting['purpose']): ?>
            <div style="font-size:13px;color:var(--text-2,#64748b)"><?= nl2br(e($meeting['purpose'])) ?></div>
            <?php endif; ?>
            <div class="mv-meta">
                <span><i class="fa fa-calendar-day"></i><?= date('D j M Y, H:i', strtotime($meeting['scheduled_start'])) ?>
                    <?= $meeting['scheduled_end'] ? ' – ' . date('H:i', strtotime($meeting['scheduled_end'])) : '' ?></span>
                <span><i class="fa <?= $type[1] ?>"></i><?= $type[0] ?></span>
                <?php if ($meeting['venue']): ?><span><i class="fa fa-location-dot"></i><?= e($meeting['venue']) ?></span><?php endif; ?>
                <?php if ($meeting['organiser_name']): ?><span><i class="fa fa-user-tie"></i><?= e($meeting['organiser_name']) ?></span><?php endif; ?>
            </div>
        </div>
        <span class="mv-tag" style="background:<?= $sc ?>1f;color:<?= $sc ?>"><?= $sl ?></span>
    </div>

    <?php if ($myPart && $meeting['status'] === 'scheduled'): ?>
    <div class="d-flex align-items-center gap-2 mt-3 pt-3 flex-wrap" style="border-top:1px solid var(--border)">
        <span class="small text-muted">Will you attend?</span>
        <?php foreach (['accepted' => ['Yes', 'success'], 'declined' => ['No', 'outline-secondary']] as $k => $b): ?>
        <form method="POST" class="d-inline">
            <?= csrfField() ?><input type="hidden" name="action" value="rsvp">
            <input type="hidden" name="rsvp" value="<?= $k ?>">
            <button class="btn btn-xs btn-<?= $myPart['invite_status'] === $k ? $b[1] : 'outline-secondary' ?>">
                <?= $b[0] ?>
            </button>
        </form>
        <?php endforeach; ?>
        <?php if ($myPart['invite_status'] !== 'invited'): ?>
        <span class="small text-muted">— you said <?= $myPart['invite_status'] === 'accepted' ? 'yes' : 'no' ?></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<div class="mv-cols">
    <div>
        <!-- ── Agenda + minutes ─────────────────────────────────────────── -->
        <form method="POST" id="minutes">
            <?= csrfField() ?><input type="hidden" name="action" value="save_minutes">

            <div class="mv-card">
                <div class="mv-head">
                    <h2 class="mv-title"><i class="fa fa-list-ol"></i>Agenda &amp; Minutes</h2>
                    <?php if ($canEdit): ?>
                    <button class="btn btn-xs btn-primary"><i class="fa fa-floppy-disk me-1"></i>Save minutes</button>
                    <?php endif; ?>
                </div>

                <?php if (!$agenda): ?>
                    <div class="mv-empty">
                        <i class="fa fa-list-ol"></i>No agenda items.
                        <?php if ($canEdit): ?><br><a href="<?= BASE_URL ?>/modules/meetings/edit.php?id=<?= $id ?>">Add some</a>.<?php endif; ?>
                    </div>
                <?php else: foreach ($agenda as $n => $a): ?>
                <div class="ag-item">
                    <div class="ag-top">
                        <span class="ag-n"><?= $n + 1 ?></span>
                        <div style="flex:1;min-width:0">
                            <div class="ag-t"><?= e($a['title']) ?></div>
                            <div class="ag-d">
                                <?= $a['detail'] ? e($a['detail']) . ' &middot; ' : '' ?>
                                <?= $a['presenter_name'] ? 'Led by ' . e($a['presenter_name']) : 'No lead set' ?>
                                <?= $a['duration_min'] ? ' &middot; ' . (int)$a['duration_min'] . ' min' : '' ?>
                            </div>
                        </div>
                        <?php if ($canEdit): ?>
                        <select name="item_status[<?= (int)$a['id'] ?>]" class="form-select form-select-sm" style="width:auto">
                            <?php foreach (['pending'=>'Not yet','discussed'=>'Discussed','deferred'=>'Deferred'] as $k=>$l): ?>
                            <option value="<?= $k ?>" <?= $a['status'] === $k ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <span class="mv-tag" style="background:var(--surface-alt);color:var(--text-2,#64748b)">
                            <?= ['pending'=>'Not yet','discussed'=>'Discussed','deferred'=>'Deferred'][$a['status']] ?? '' ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="mt-2" style="padding-left:34px">
                        <?php if ($canEdit): ?>
                        <textarea name="discussion[<?= (int)$a['id'] ?>]" rows="2" class="form-control form-control-sm"
                                  placeholder="What was discussed and decided…"><?= e((string)$a['discussion']) ?></textarea>
                        <?php elseif ($a['discussion']): ?>
                        <div style="font-size:12.5px;color:var(--text);background:var(--surface-alt);
                                    border:1px solid var(--border);border-radius:var(--r);padding:9px 11px">
                            <?= nl2br(e($a['discussion'])) ?>
                        </div>
                        <?php else: ?>
                        <div class="text-muted" style="font-size:12px;font-style:italic">No notes recorded.</div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; endif; ?>

                <div class="mv-body" style="border-top:1px solid var(--border)">
                    <label class="form-label">General minutes / notes</label>
                    <?php if ($canEdit): ?>
                    <textarea name="minutes" rows="5" class="form-control form-control-sm"
                              placeholder="Anything outside the agenda — attendance notes, decisions, next meeting…"><?= e((string)$meeting['minutes']) ?></textarea>
                    <?php elseif ($meeting['minutes']): ?>
                    <div style="font-size:13px;color:var(--text)"><?= nl2br(e($meeting['minutes'])) ?></div>
                    <?php else: ?>
                    <div class="text-muted" style="font-size:12.5px;font-style:italic">No minutes recorded yet.</div>
                    <?php endif; ?>
                    <?php if ($meeting['minutes_at']): ?>
                    <div class="form-text" style="font-size:11px">
                        Last saved <?= date('j M Y, H:i', strtotime($meeting['minutes_at'])) ?>.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <!-- ── Deliverables ─────────────────────────────────────────────── -->
        <div class="mv-card" id="actions">
            <div class="mv-head">
                <h2 class="mv-title"><i class="fa fa-list-check"></i>Deliverables</h2>
                <span class="small text-muted">
                    <?= $summary['done'] ?> of <?= $summary['total'] ?> done<?= $summary['overdue'] ? ' · ' . $summary['overdue'] . ' overdue' : '' ?>
                </span>
            </div>

            <?php if ($summary['total']): ?>
            <div style="padding:12px 16px 0">
                <div class="mv-prog"><i style="width:<?= round($summary['done'] / max(1, $summary['total']) * 100) ?>%"></i></div>
            </div>
            <?php endif; ?>

            <?php if (!$actions): ?>
                <div class="mv-empty"><i class="fa fa-clipboard-list"></i>Nothing agreed yet.</div>
            <?php else: foreach ($actions as $a):
                [$asl, $asc] = meetingActionStatuses()[$a['status']] ?? ['Pending', '#f59e0b'];
                $open    = in_array($a['status'], ['pending','in_progress','blocked'], true);
                $overdue = $open && $a['due_date'] && strtotime($a['due_date']) < strtotime('today');
                $mine    = (int)$a['assigned_to'] === $meId;
            ?>
            <div class="ac">
                <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                    <div style="min-width:0;flex:1">
                        <div class="ac-t"><?= e($a['title']) ?></div>
                        <?php if ($a['detail']): ?>
                        <div style="font-size:12px;color:var(--text-2,#64748b);margin-top:2px"><?= nl2br(e($a['detail'])) ?></div>
                        <?php endif; ?>
                        <div class="ac-m">
                            <span><i class="fa fa-user"></i><?= e($a['owner_name'] ?: 'Unassigned') ?><?= $mine ? ' (you)' : '' ?></span>
                            <?php if ($a['due_date']): ?>
                            <span class="<?= $overdue ? 'ac-overdue' : '' ?>">
                                <i class="fa fa-calendar"></i>due <?= date('j M Y', strtotime($a['due_date'])) ?><?= $overdue ? ' — overdue' : '' ?>
                            </span>
                            <?php endif; ?>
                            <?php if ($a['item_title']): ?><span><i class="fa fa-list-ol"></i><?= e($a['item_title']) ?></span><?php endif; ?>
                            <?php if ($a['priority'] === 'high'): ?><span style="color:#dc2626;font-weight:700">High priority</span><?php endif; ?>
                            <?php if ($a['completed_at']): ?>
                            <span style="color:#16a34a"><i class="fa fa-check"></i>done <?= date('j M', strtotime($a['completed_at'])) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($a['progress_note']): ?>
                        <div style="font-size:11.5px;color:var(--text-2,#64748b);font-style:italic;margin-top:4px">
                            “<?= e($a['progress_note']) ?>”
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="mv-tag" style="background:<?= $asc ?>1f;color:<?= $asc ?>"><?= $asl ?></span>
                        <?php if ($canEdit || $mine): ?>
                        <button class="btn btn-xs btn-outline-primary" type="button"
                                data-bs-toggle="collapse" data-bs-target="#upd<?= (int)$a['id'] ?>">
                            <i class="fa fa-pen"></i>
                        </button>
                        <?php endif; ?>
                        <?php if ($canEdit): ?>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Remove this deliverable?')">
                            <?= csrfField() ?><input type="hidden" name="action" value="delete_action">
                            <input type="hidden" name="action_id" value="<?= (int)$a['id'] ?>">
                            <button class="btn btn-xs btn-outline-danger"><i class="fa fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($canEdit || $mine): ?>
                <div class="collapse mt-2" id="upd<?= (int)$a['id'] ?>">
                    <form method="POST" class="row g-2 align-items-end">
                        <?= csrfField() ?><input type="hidden" name="action" value="update_action">
                        <input type="hidden" name="action_id" value="<?= (int)$a['id'] ?>">
                        <div class="col-sm-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select form-select-sm">
                                <?php foreach (meetingActionStatuses() as $k => $lbl): ?>
                                <option value="<?= $k ?>" <?= $a['status'] === $k ? 'selected' : '' ?>><?= $lbl[0] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-7">
                            <label class="form-label">Progress note</label>
                            <input type="text" name="progress_note" class="form-control form-control-sm"
                                   value="<?= e((string)$a['progress_note']) ?>" placeholder="Where this has got to">
                        </div>
                        <div class="col-sm-2">
                            <button class="btn btn-sm btn-primary w-100">Update</button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; endif; ?>

            <?php if ($canEdit): ?>
            <div class="mv-body" style="border-top:1px solid var(--border);background:var(--surface-alt)">
                <form method="POST" class="row g-2 align-items-end">
                    <?= csrfField() ?><input type="hidden" name="action" value="add_action">
                    <div class="col-md-4">
                        <label class="form-label">What needs doing</label>
                        <input type="text" name="title" class="form-control form-control-sm" required placeholder="Deliverable">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Owner</label>
                        <select name="assigned_to" class="form-select form-select-sm">
                            <option value="">Unassigned</option>
                            <?php foreach ($participants as $p): ?>
                            <option value="<?= (int)$p['user_id'] ?>"><?= e($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Due</label>
                        <input type="date" name="due_date" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Agenda item</label>
                        <select name="agenda_item_id" class="form-select form-select-sm">
                            <option value="">—</option>
                            <?php foreach ($agenda as $n => $a): ?>
                            <option value="<?= (int)$a['id'] ?>"><?= ($n + 1) . '. ' . e(mb_substr($a['title'], 0, 28)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <button class="btn btn-sm btn-primary w-100"><i class="fa fa-plus"></i></button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Right column ─────────────────────────────────────────────────── -->
    <div>
        <?php if ($isVirtual): ?>
        <div class="mv-card">
            <div class="mv-head"><h2 class="mv-title"><i class="fa fa-video"></i>Virtual Room</h2></div>
            <div class="mv-body">
                <p class="small text-muted mb-2">Runs in the browser — nothing to install.</p>
                <a href="<?= BASE_URL ?>/modules/meetings/room.php?id=<?= $id ?>" class="btn btn-success w-100">
                    <i class="fa fa-video me-1"></i>Join Video Room
                </a>
                <div class="form-text mt-2" style="font-size:11px">
                    Room code <code><?= e($meeting['room_code']) ?></code>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="mv-card">
            <div class="mv-head">
                <h2 class="mv-title"><i class="fa fa-users"></i>Participants</h2>
                <span class="small text-muted"><?= count($participants) ?></span>
            </div>
            <div class="mv-body">
                <?php if (!$participants): ?>
                    <div class="mv-empty"><i class="fa fa-user-slash"></i>Nobody invited.</div>
                <?php else: foreach ($participants as $p):
                    $ic = ['accepted' => ['#16a34a','Attending'], 'declined' => ['#dc2626','Not attending'],
                           'invited' => ['#94a3b8','Invited']][$p['invite_status']] ?? ['#94a3b8','Invited'];
                ?>
                <div class="pp">
                    <span class="pp-av" style="background:<?= meetingAvatarColor((int)$p['user_id']) ?>">
                        <?= e(meetingInitials($p['name'])) ?>
                    </span>
                    <div style="flex:1;min-width:0">
                        <div class="pp-n text-truncate"><?= e($p['name']) ?><?= (int)$p['user_id'] === $meId ? ' (you)' : '' ?></div>
                        <div class="pp-r"><?= e(meetingParticipantRoles()[$p['role']] ?? 'Attendee') ?></div>
                    </div>
                    <span class="mv-tag" style="background:<?= $ic[0] ?>1f;color:<?= $ic[0] ?>"><?= $ic[1] ?></span>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div class="mv-card">
            <div class="mv-head"><h2 class="mv-title"><i class="fa fa-chart-simple"></i>Follow-up</h2></div>
            <div class="mv-body">
                <?php foreach ([['Total agreed', $summary['total'], 'var(--text)'],
                                ['Completed', $summary['done'], '#16a34a'],
                                ['Still open', $summary['open'], '#f59e0b'],
                                ['Overdue', $summary['overdue'], '#dc2626']] as [$lbl, $val, $col]): ?>
                <div class="d-flex justify-content-between align-items-center py-2"
                     style="border-bottom:1px solid var(--border);font-size:13px">
                    <span class="text-muted"><?= $lbl ?></span>
                    <strong style="color:<?= $col ?>"><?= $val ?></strong>
                </div>
                <?php endforeach; ?>
                <a href="<?= BASE_URL ?>/modules/meetings/actions.php?meeting_id=<?= $id ?>"
                   class="btn btn-sm btn-outline-primary w-100 mt-3">
                    <i class="fa fa-list-check me-1"></i>Track all deliverables
                </a>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
