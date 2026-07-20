<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$pageTitle = 'Lead';
$db  = getDB();
try { $db->exec("ALTER TABLE crm_leads ADD COLUMN pinned_car_id INT NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE crm_leads ADD COLUMN campaign VARCHAR(150) NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE crm_leads ADD COLUMN lead_score TINYINT UNSIGNED DEFAULT 0"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE crm_leads ADD COLUMN deposit_amount DECIMAL(15,2) NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE crm_leads ADD COLUMN deposit_date DATE NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE crm_leads ADD COLUMN deposit_notes TEXT NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE crm_leads ADD COLUMN agreed_sale_price DECIMAL(15,2) NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE crm_leads ADD COLUMN due_date DATE NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE crm_leads ADD COLUMN agreement_received_date DATE NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE crm_leads ADD COLUMN import_vehicle_details TEXT NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE crm_leads ADD COLUMN expected_arrival_date DATE NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE crm_leads ADD COLUMN delivered_at DATETIME NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE crm_leads ADD COLUMN id_number VARCHAR(50) NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE crm_leads ADD COLUMN kra_pin VARCHAR(20) NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE crm_leads ADD COLUMN po_box VARCHAR(100) NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE crm_leads ADD COLUMN id_card_front VARCHAR(255) NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE crm_leads ADD COLUMN id_card_back VARCHAR(255) NULL DEFAULT NULL"); } catch (\Throwable $_) {}
// Test drive & car extended fields
try { $db->exec("ALTER TABLE cars ADD COLUMN entry_number VARCHAR(100) NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE cars MODIFY COLUMN status ENUM('in_transit','arrived','in_assessment','in_workshop','completed','sold','delivered','reserved') DEFAULT 'in_transit'"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE crm_test_drives ADD COLUMN driver_id_no VARCHAR(50) NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE crm_test_drives ADD COLUMN kd_number VARCHAR(50) NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE crm_test_drives ADD COLUMN chassis_number VARCHAR(100) NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE crm_test_drives ADD COLUMN entry_number VARCHAR(100) NULL"); } catch (\Throwable $_) {}
require_once __DIR__ . '/crm_helpers.php';
$id  = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/crm/leads.php');

$me  = authUser();
$uid = (int)$me['id'];
$isCrmAgent = ($me['role'] === 'customer_relations');

$lead = $db->prepare("
    SELECT l.*, u.name AS assigned_name, c.name AS client_name
    FROM crm_leads l
    LEFT JOIN users u ON u.id = l.assigned_to
    LEFT JOIN clients c ON c.id = l.client_id
    WHERE l.id = ?
");
$lead->execute([$id]); $lead = $lead->fetch();
if (!$lead) { setFlash('error','Lead not found.'); redirect(BASE_URL.'/modules/crm/leads.php'); }

// Data isolation: CRM agents can only view leads assigned to themselves
if ($isCrmAgent && (int)$lead['assigned_to'] !== $uid) {
    setFlash('error', 'You can only view leads assigned to you.');
    redirect(BASE_URL . '/modules/crm/my_dashboard.php');
}

$pageTitle = $lead['name'];

$stages = [
    'hot'       => ['Hot',       'danger',    'fa-fire'],
    'lukewarm'  => ['Lukewarm',  'warning',   'fa-temperature-half'],
    'cold'      => ['Cold',      'info',      'fa-snowflake'],
    'lost'      => ['Lost',      'secondary', 'fa-circle-xmark'],
    'reserved'     => ['Reserved',     'purple',  'fa-bookmark'],
    'import_order' => ['Import Order', 'warning', 'fa-ship'],
    'delivered'    => ['Delivered',    'success', 'fa-truck'],
];

$activityTypes = [
    'call'       => ['Call',       'fa-phone',        'text-success'],
    'whatsapp'   => ['WhatsApp',   'fa-comment-dots', 'text-success'],
    'email'      => ['Email',      'fa-envelope',     'text-primary'],
    'visit'      => ['Visit',      'fa-location-dot', 'text-warning'],
    'test_drive' => ['Test Drive', 'fa-car-side',     'text-purple'],
    'meeting'    => ['Meeting',    'fa-users',        'text-info'],
    'note'       => ['Note',       'fa-note-sticky',  'text-secondary'],
];

$sourceLabels = [
    'walk_in'    => 'Walk-in',    'referral'   => 'Referral',
    'facebook'   => 'Facebook',   'instagram'  => 'Instagram',
    'website'    => 'Website',    'phone_call' => 'Phone Call',
    'whatsapp'   => 'WhatsApp',   'other'      => 'Other',
];

$salesUsers = $db->query("SELECT id, name FROM users WHERE status='active' ORDER BY name")->fetchAll();

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_lead') {
        if ($me['role'] !== 'super_admin') {
            setFlash('danger', 'Only Super Admin can delete leads.');
            redirect(BASE_URL . '/modules/crm/view_lead.php?id=' . $id);
        }
        try {
            $db->prepare("DELETE FROM crm_activities  WHERE lead_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM crm_test_drives WHERE lead_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM crm_leads        WHERE id      = ?")->execute([$id]);
            setFlash('success', 'Lead "' . $lead['name'] . '" has been deleted.');
        } catch (\Throwable $e) {
            setFlash('danger', 'Delete failed: ' . $e->getMessage());
            redirect(BASE_URL . '/modules/crm/view_lead.php?id=' . $id);
        }
        redirect(BASE_URL . '/modules/crm/leads.php');
    }

    if ($action === 'update_stage' && canWrite('crm')) {
        $newStage   = $_POST['stage']       ?? '';
        $lostReason = trim($_POST['lost_reason'] ?? '') ?: null;
        if (array_key_exists($newStage, $stages)) {
            $db->prepare("UPDATE crm_leads SET stage=?, lost_reason=COALESCE(?,lost_reason), updated_at=NOW() WHERE id=?")
               ->execute([$newStage, $lostReason, $id]);
            if ($newStage === 'delivered') {
                $db->prepare("UPDATE crm_leads SET converted_at=NOW() WHERE id=? AND converted_at IS NULL")->execute([$id]);
            }
            logActivity('update','crm_leads',$id,"Stage changed to: $newStage");
            // Sync car status when lead stage moves through reserved
            $pinnedCarId = (int)($lead['pinned_car_id'] ?? 0);
            if ($pinnedCarId) {
                if ($newStage === 'reserved') {
                    $db->prepare("UPDATE cars SET status='reserved', updated_at=NOW() WHERE id=?")->execute([$pinnedCarId]);
                } elseif ($lead['stage'] === 'reserved') {
                    if ($newStage === 'delivered') {
                        $db->prepare("UPDATE cars SET status='sold', updated_at=NOW() WHERE id=? AND status='reserved'")->execute([$pinnedCarId]);
                    } else {
                        $db->prepare("UPDATE cars SET status='arrived', updated_at=NOW() WHERE id=? AND status='reserved'")->execute([$pinnedCarId]);
                    }
                }
            }
            require_once __DIR__ . '/../../includes/notifications.php';
            if ($newStage === 'delivered') {
                notifyRoles(['admin','sales_manager','general_manager'], 'sale',
                    "Lead Delivered: {$lead['name']}",
                    $lead['interested_in'] ? "Interested in: {$lead['interested_in']}" : '',
                    BASE_URL . '/modules/crm/view_lead.php?id=' . $id
                );
            } elseif ($newStage === 'lost') {
                notifyRoles(['admin','sales_manager'], 'info',
                    "Lead Lost: {$lead['name']}",
                    $lostReason ?: 'No reason recorded',
                    BASE_URL . '/modules/crm/view_lead.php?id=' . $id
                );
            }
            setFlash('success','Stage updated.');
        }
        redirect(BASE_URL.'/modules/crm/view_lead.php?id='.$id);
    }

    if ($action === 'log_activity' && canWrite('crm')) {
        $type      = $_POST['type']           ?? 'note';
        $summary   = trim($_POST['summary']   ?? '');
        $outcome   = trim($_POST['outcome']   ?? '') ?: null;
        $followUp  = trim($_POST['follow_up_date'] ?? '') ?: null;

        if ($summary) {
            $db->prepare("INSERT INTO crm_activities (lead_id, type, summary, outcome, follow_up_date, created_by) VALUES (?,?,?,?,?,?)")
               ->execute([$id, $type, $summary, $outcome, $followUp, authUser()['id']]);

            if ($followUp) {
                $db->prepare("UPDATE crm_leads SET follow_up_date=?, updated_at=NOW() WHERE id=?")->execute([$followUp, $id]);
            }
            setFlash('success','Activity logged.');
        }
        redirect(BASE_URL.'/modules/crm/view_lead.php?id='.$id);
    }

    if ($action === 'update_details') {
        // Everyone with CRM access can edit identity/KYC fields; other fields
        // remain gated behind canWrite('crm') even if posted directly.
        $canEditCrm = canWrite('crm');

        $name     = trim($_POST['name']      ?? '');
        $idNumber = trim($_POST['id_number'] ?? '') ?: null;
        $kraPin   = strtoupper(trim($_POST['kra_pin'] ?? '')) ?: null;
        $poBox    = trim($_POST['po_box']    ?? '') ?: null;

        if ($canEditCrm) {
            $phone        = trim($_POST['phone']          ?? '') ?: null;
            $email        = trim($_POST['email']          ?? '') ?: null;
            $interestedIn = trim($_POST['interested_in']  ?? '') ?: null;
            $budget       = $_POST['budget'] !== '' ? (float)$_POST['budget'] : null;
            $assignedTo   = (int)($_POST['assigned_to']   ?? 0) ?: null;
            $notes        = trim($_POST['notes']          ?? '') ?: null;
            $followUp     = trim($_POST['follow_up_date'] ?? '') ?: null;
            $lostReason   = trim($_POST['lost_reason']    ?? '') ?: null;
            $campaign     = trim($_POST['campaign']       ?? '') ?: null;
        } else {
            $phone        = $lead['phone'];
            $email        = $lead['email'];
            $interestedIn = $lead['interested_in'];
            $budget       = $lead['budget'];
            $assignedTo   = $lead['assigned_to'];
            $notes        = $lead['notes'];
            $followUp     = $lead['follow_up_date'];
            $lostReason   = $lead['lost_reason'];
            $campaign     = $lead['campaign'];
        }

        // ID card front/back — uploadable by anyone who can reach this page.
        $idCardFront = $lead['id_card_front'];
        $idCardBack  = $lead['id_card_back'];
        try {
            if (!empty($_FILES['id_card_front']['name'])) {
                $idCardFront = handleUpload($_FILES['id_card_front'], BASE_PATH . '/uploads/leads', ['jpg','jpeg','png','webp']);
            }
            if (!empty($_FILES['id_card_back']['name'])) {
                $idCardBack = handleUpload($_FILES['id_card_back'], BASE_PATH . '/uploads/leads', ['jpg','jpeg','png','webp']);
            }
        } catch (\Throwable $e) {
            setFlash('danger', 'ID card upload failed: ' . $e->getMessage());
            redirect(BASE_URL.'/modules/crm/view_lead.php?id='.$id);
        }

        if ($name) {
            $prevAssigned = (int)$lead['assigned_to'];
            $db->prepare("UPDATE crm_leads SET name=?,phone=?,email=?,interested_in=?,budget=?,assigned_to=?,notes=?,follow_up_date=?,lost_reason=?,campaign=?,id_number=?,kra_pin=?,po_box=?,id_card_front=?,id_card_back=?,updated_at=NOW() WHERE id=?")
               ->execute([$name,$phone,$email,$interestedIn,$budget,$assignedTo,$notes,$followUp,$lostReason,$campaign,$idNumber,$kraPin,$poBox,$idCardFront,$idCardBack,$id]);
            if ($canEditCrm && $assignedTo && $assignedTo !== $prevAssigned) {
                require_once __DIR__ . '/../../includes/notifications.php';
                createNotification((int)$assignedTo, 'info',
                    "Lead Assigned: {$name}",
                    $interestedIn ? "Interested in: {$interestedIn}" : 'You have been assigned a CRM lead.',
                    BASE_URL . '/modules/crm/view_lead.php?id=' . $id
                );
            }
            setFlash('success','Lead updated.');
        }
        redirect(BASE_URL.'/modules/crm/view_lead.php?id='.$id);
    }

    if ($action === 'convert' && canWrite('crm')) {
        // Convert to client if not already linked
        if (!$lead['client_id']) {
            $db->prepare("INSERT INTO clients (name,phone,email,status) VALUES (?,?,?,'active')")
               ->execute([$lead['name'], $lead['phone'], $lead['email']]);
            $clientId = (int)$db->lastInsertId();
            $db->prepare("UPDATE crm_leads SET client_id=?,stage='delivered',converted_at=NOW(),updated_at=NOW() WHERE id=?")
               ->execute([$clientId, $id]);
            logActivity('create','clients',$clientId,"Converted from CRM lead #{$id}");
            setFlash('success','Lead converted to client. You can now record a sale.');
            redirect(BASE_URL.'/modules/clients/view.php?id='.$clientId);
        }
        redirect(BASE_URL.'/modules/crm/view_lead.php?id='.$id);
    }

    if ($action === 'pin_car' && canWrite('crm')) {
        $carId    = (int)($_POST['car_id'] ?? 0) ?: null;
        $oldCarId = (int)($lead['pinned_car_id'] ?? 0) ?: null;
        $db->prepare("UPDATE crm_leads SET pinned_car_id = ?, updated_at = NOW() WHERE id = ?")
           ->execute([$carId, $id]);
        // If lead is already reserved, keep car status in sync
        if ($lead['stage'] === 'reserved') {
            if ($oldCarId && $oldCarId !== $carId) {
                $db->prepare("UPDATE cars SET status='arrived', updated_at=NOW() WHERE id=? AND status='reserved'")->execute([$oldCarId]);
            }
            if ($carId) {
                $db->prepare("UPDATE cars SET status='reserved', updated_at=NOW() WHERE id=?")->execute([$carId]);
            }
        }
        setFlash('success', $carId ? 'Vehicle linked to lead.' : 'Vehicle link removed.');
        redirect(BASE_URL . '/modules/crm/view_lead.php?id=' . $id);
    }

    if ($action === 'reserve_lead' && canWrite('crm')) {
        $reserveCarId    = (int)($_POST['reserve_car_id'] ?? 0) ?: null;
        $depositAmt      = (float)($_POST['deposit_amount'] ?? 0);
        $depositNotes    = trim($_POST['deposit_notes'] ?? '') ?: null;
        $agreedSalePrice = (float)($_POST['agreed_sale_price'] ?? 0) ?: null;
        $dueDate         = trim($_POST['due_date'] ?? '') ?: null;
        $agreementRecvd  = trim($_POST['agreement_received_date'] ?? '') ?: null;
        $db->prepare("
            UPDATE crm_leads
            SET stage='reserved',
                pinned_car_id           = COALESCE(?, pinned_car_id),
                deposit_amount          = ?,
                deposit_date            = CURDATE(),
                deposit_notes           = ?,
                agreed_sale_price       = ?,
                due_date                = ?,
                agreement_received_date = ?,
                updated_at              = NOW()
            WHERE id = ?
        ")->execute([$reserveCarId, $depositAmt, $depositNotes, $agreedSalePrice, $dueDate, $agreementRecvd, $id]);
        require_once __DIR__ . '/../../includes/notifications.php';
        notifyRoles(['admin','sales_manager','general_manager'], 'sale',
            "Vehicle Reserved: {$lead['name']}",
            "Deposit: " . money($depositAmt) . ($depositNotes ? " — {$depositNotes}" : ''),
            BASE_URL . '/modules/crm/view_lead.php?id=' . $id
        );
        logActivity('update', 'crm_leads', $id, "Lead reserved. Deposit: " . number_format($depositAmt, 2));
        setFlash('success', 'Vehicle reserved. Proforma Invoice and Sales Agreement are ready below.');
        redirect(BASE_URL . '/modules/crm/view_lead.php?id=' . $id);
    }

    if ($action === 'import_order_lead' && canWrite('crm')) {
        $importVehicle = trim($_POST['import_vehicle_details'] ?? '') ?: null;
        $expectedArr   = trim($_POST['expected_arrival_date'] ?? '') ?: null;
        $depositAmt    = (float)($_POST['deposit_amount'] ?? 0);
        $depositNotes  = trim($_POST['deposit_notes'] ?? '') ?: null;
        $agreedPrice   = (float)($_POST['agreed_sale_price'] ?? 0) ?: null;
        $dueDate       = trim($_POST['due_date'] ?? '') ?: null;
        $db->prepare("
            UPDATE crm_leads
            SET stage                  = 'import_order',
                import_vehicle_details = ?,
                expected_arrival_date  = ?,
                deposit_amount         = ?,
                deposit_date           = CURDATE(),
                deposit_notes          = ?,
                agreed_sale_price      = ?,
                due_date               = ?,
                updated_at             = NOW()
            WHERE id = ?
        ")->execute([$importVehicle, $expectedArr, $depositAmt, $depositNotes, $agreedPrice, $dueDate, $id]);
        require_once __DIR__ . '/../../includes/notifications.php';
        notifyRoles(['admin','sales_manager','general_manager'], 'sale',
            "Import Order: {$lead['name']}",
            ($importVehicle ? $importVehicle . ' — ' : '') . "Deposit: " . money($depositAmt),
            BASE_URL . '/modules/crm/view_lead.php?id=' . $id
        );
        logActivity('update', 'crm_leads', $id, "Import order created. Vehicle: " . ($importVehicle ?? '—') . ". Deposit: " . number_format($depositAmt, 2));
        setFlash('success', 'Import Order saved. Documents are ready below.');
        redirect(BASE_URL . '/modules/crm/view_lead.php?id=' . $id);
    }

    if ($action === 'deliver_lead' && canWrite('crm')) {
        $deliveryDate  = trim($_POST['delivery_date']  ?? '') ?: date('Y-m-d');
        $deliveryNotes = trim($_POST['delivery_notes'] ?? '') ?: null;

        $db->prepare("
            UPDATE crm_leads
            SET stage        = 'delivered',
                delivered_at = ?,
                converted_at = COALESCE(converted_at, NOW()),
                updated_at   = NOW()
            WHERE id = ?
        ")->execute([$deliveryDate, $id]);

        // Remove car from inventory: mark delivered and hide from website
        $pinnedCarId = (int)($lead['pinned_car_id'] ?? 0);
        if ($pinnedCarId) {
            $db->prepare("UPDATE cars SET status='delivered', show_on_website=0, updated_at=NOW() WHERE id=?")
               ->execute([$pinnedCarId]);
        }

        if ($deliveryNotes) {
            $db->prepare("INSERT INTO crm_activities (lead_id, type, summary, created_by) VALUES (?,?,?,?)")
               ->execute([$id, 'note', 'Delivery note: ' . $deliveryNotes, $me['id']]);
        }

        require_once __DIR__ . '/../../includes/notifications.php';
        notifyRoles(['admin','sales_manager','general_manager'], 'sale',
            "Vehicle Delivered: {$lead['name']}",
            "Delivery confirmed on " . date('d M Y', strtotime($deliveryDate)),
            BASE_URL . '/modules/crm/view_lead.php?id=' . $id
        );
        logActivity('update', 'crm_leads', $id, "Vehicle delivered on $deliveryDate.");
        setFlash('success', 'Delivery confirmed! Delivery Note is ready below.');
        redirect(BASE_URL . '/modules/crm/view_lead.php?id=' . $id);
    }

    if ($action === 'revoke_reservation' && $me['role'] === 'admin') {
        $pinnedCarId = (int)($lead['pinned_car_id'] ?? 0);
        // Free the car back to available
        if ($pinnedCarId) {
            try {
                $db->prepare("UPDATE cars SET status = 'available', updated_at = NOW() WHERE id = ? AND status = 'reserved'")
                   ->execute([$pinnedCarId]);
            } catch (\Throwable $_) {}
        }
        $db->prepare("
            UPDATE crm_leads
            SET stage                    = 'active',
                pinned_car_id            = NULL,
                deposit_amount           = NULL,
                deposit_date             = NULL,
                deposit_notes            = NULL,
                agreed_sale_price        = NULL,
                due_date                 = NULL,
                agreement_received_date  = NULL,
                updated_at               = NOW()
            WHERE id = ?
        ")->execute([$id]);
        require_once __DIR__ . '/../../includes/notifications.php';
        notifyRoles(['sales_manager','general_manager'], 'alert',
            "Reservation Revoked: {$lead['name']}",
            "Revoked by {$me['name']}. Lead returned to Active.",
            BASE_URL . '/modules/crm/view_lead.php?id=' . $id
        );
        logActivity('update', 'crm_leads', $id, "Reservation revoked by admin ({$me['name']}). Lead reset to active.");
        setFlash('success', 'Reservation revoked. The lead has been returned to Active and the vehicle freed.');
        redirect(BASE_URL . '/modules/crm/view_lead.php?id=' . $id);
    }

    if ($action === 'schedule_test_drive' && canWrite('crm')) {
        $tdDate      = trim($_POST['td_date']         ?? '');
        $tdTime      = trim($_POST['td_time']         ?? '');
        $tdCarId     = (int)($_POST['td_car_id']      ?? 0) ?: null;
        $tdNotes     = trim($_POST['td_notes']        ?? '') ?: null;
        $tdDur       = (int)($_POST['td_duration']    ?? 60);
        $tdDriverId  = trim($_POST['td_driver_id_no'] ?? '') ?: null;
        $tdKdNum     = trim($_POST['td_kd_number']    ?? '') ?: null;
        $tdChassis   = trim($_POST['td_chassis_number'] ?? '') ?: null;
        $tdEntry     = trim($_POST['td_entry_number'] ?? '') ?: null;

        if (!$tdDate || !$tdTime) {
            setFlash('error', 'Date and time are required.');
            redirect(BASE_URL . '/modules/crm/view_lead.php?id=' . $id);
        }
        if (!$tdDriverId || !$tdKdNum) {
            setFlash('error', 'Driver ID number and KD number are required.');
            redirect(BASE_URL . '/modules/crm/view_lead.php?id=' . $id);
        }

        try {
            $db->prepare("CREATE TABLE IF NOT EXISTS crm_test_drives (
                id INT AUTO_INCREMENT PRIMARY KEY,
                lead_id INT NOT NULL, car_id INT NULL,
                scheduled_date DATE NOT NULL, scheduled_time TIME NOT NULL,
                duration_minutes INT DEFAULT 60,
                status ENUM('scheduled','completed','no_show','cancelled') DEFAULT 'scheduled',
                notes TEXT NULL, outcome TEXT NULL,
                created_by INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )")->execute([]);
        } catch (\Throwable $_) {}

        try {
            $db->prepare("
                INSERT INTO crm_test_drives
                    (lead_id, car_id, scheduled_date, scheduled_time, duration_minutes,
                     driver_id_no, kd_number, chassis_number, entry_number, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([$id, $tdCarId, $tdDate, $tdTime, $tdDur,
                         $tdDriverId, $tdKdNum, $tdChassis, $tdEntry, $tdNotes, $uid]);

            $tdNewId = (int)$db->lastInsertId();
            $car = $tdCarId ? $db->prepare("SELECT make, model, registration_number FROM cars WHERE id=?")->execute([$tdCarId]) : null;
            $carDesc = $tdCarId ? (function() use ($db, $tdCarId) {
                $r = $db->prepare("SELECT make, model, registration_number FROM cars WHERE id=?");
                $r->execute([$tdCarId]); $r = $r->fetch();
                return $r ? "{$r['make']} {$r['model']}" . ($r['registration_number'] ? " ({$r['registration_number']})" : '') : '';
            })() : '';

            $db->prepare("INSERT INTO crm_activities (lead_id, type, summary, created_by) VALUES (?,?,?,?)")
               ->execute([$id, 'test_drive',
                   "Test drive scheduled: {$tdDate} at {$tdTime}" . ($carDesc ? " — {$carDesc}" : ''),
                   $uid]);

            setFlash('success', 'Test drive scheduled. <a href="' . BASE_URL . '/modules/crm/test_drive_slip.php?id=' . $tdNewId . '" target="_blank" class="alert-link">Print Slip</a>');
        } catch (\Throwable $e) {
            setFlash('error', 'Could not schedule: ' . $e->getMessage());
        }
        redirect(BASE_URL . '/modules/crm/view_lead.php?id=' . $id);
    }
}

// Load activities
$activities = $db->prepare("
    SELECT a.*, u.name AS by_name
    FROM crm_activities a
    LEFT JOIN users u ON u.id = a.created_by
    WHERE a.lead_id = ?
    ORDER BY a.created_at DESC
");
$activities->execute([$id]); $activities = $activities->fetchAll();

// Upcoming test drives for this lead
$testDrives = [];
try {
    $tdStmt = $db->prepare("
        SELECT td.*,
               c.make, c.model, c.registration_number, c.year AS car_year, c.color AS car_color,
               c.chassis_number AS car_chassis, c.entry_number AS car_entry
        FROM crm_test_drives td
        LEFT JOIN cars c ON c.id = td.car_id
        WHERE td.lead_id = ?
        ORDER BY td.scheduled_date DESC, td.scheduled_time DESC
        LIMIT 20
    ");
    $tdStmt->execute([$id]);
    $testDrives = $tdStmt->fetchAll();
} catch (\Throwable $_) {}

// Load pinned car + all images
$pinnedCar       = null;
$pinnedCarImages = [];
if ($lead['pinned_car_id']) {
    try {
        $s = $db->prepare("SELECT * FROM cars WHERE id = ?");
        $s->execute([$lead['pinned_car_id']]);
        $pinnedCar = $s->fetch() ?: null;

        if ($pinnedCar) {
            $imgQ = $db->prepare("SELECT file_path, is_primary FROM car_images WHERE car_id = ? ORDER BY is_primary DESC, id ASC");
            $imgQ->execute([$lead['pinned_car_id']]);
            $pinnedCarImages = $imgQ->fetchAll();
        }
    } catch (\Throwable $_) {}
}

// Cars available for reservation modal
$availCarsForModal = [];
try {
    $availCarsForModal = $db->query("
        SELECT id, make, model, year, color, registration_number,
               IFNULL(asking_price, 0)  AS asking_price,
               offer_price
        FROM cars WHERE car_type = 'inventory'
        ORDER BY make, model LIMIT 300
    ")->fetchAll();
} catch (\Throwable $_) {}

// Calculate and persist lead score
$score = calculateLeadScore($lead, $db);
try {
    $db->prepare("UPDATE crm_leads SET lead_score = ? WHERE id = ?")->execute([$score, $id]);
} catch (\Throwable $_) {}

[$stageLabel, $stageColor, $stageIcon] = $stages[$lead['stage']] ?? ['Unknown','secondary','fa-circle'];
$isOverdue = $lead['follow_up_date'] && $lead['follow_up_date'] < date('Y-m-d')
             && !in_array($lead['stage'],['lost','delivered']);

// Repeat buyer check — does this lead's phone/email match a previous buyer?
$repeatBuyer = false; $repeatBuyerCount = 0;
try {
    $rbOr = []; $rbP = [];
    if (!empty($lead['phone'])) { $rbOr[] = 'cs.buyer_phone = ?'; $rbP[] = $lead['phone']; }
    if (!empty($lead['email'])) { $rbOr[] = 'cs.buyer_email = ?'; $rbP[] = $lead['email']; }
    if ($rbOr) {
        $rbStmt = $db->prepare("SELECT COUNT(*) FROM car_sales cs WHERE cs.status='active' AND (" . implode(' OR ', $rbOr) . ")");
        $rbStmt->execute($rbP);
        $repeatBuyerCount = (int)$rbStmt->fetchColumn();
        $repeatBuyer = $repeatBuyerCount > 0;
    }
} catch (\Throwable $_) {}

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-user me-2 text-primary"></i><?= e($lead['name']) ?></h5>
        <span class="badge bg-<?= $stageColor ?> me-1"><i class="fa <?= $stageIcon ?> me-1"></i><?= $stageLabel ?></span>
        <?php if ($isOverdue): ?>
        <span class="badge bg-danger">Follow-up overdue</span>
        <?php endif; ?>
        <span class="badge bg-<?= scoreColor($score) ?> ms-1" title="Lead Score">
            <i class="fa fa-star me-1"></i>Score: <?= $score ?>/100
        </span>
        <?php if ($repeatBuyer): ?>
        <span class="badge ms-1" style="background:#6d28d9;color:#fff" title="This contact has bought from us before">
            <i class="fa fa-rotate me-1"></i>Repeat Buyer (<?= $repeatBuyerCount ?> prev. purchase<?= $repeatBuyerCount > 1 ? 's' : '' ?>)
        </span>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($lead['client_id']): ?>
        <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= $lead['client_id'] ?>"
           class="btn btn-sm btn-success">
            <i class="fa fa-user-check me-1"></i>View Client
        </a>
        <a href="<?= BASE_URL ?>/modules/crm/client_history.php?client_id=<?= $lead['client_id'] ?>"
           class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-clock-rotate-left me-1"></i>Comm. History
        </a>
        <a href="<?= BASE_URL ?>/modules/quotations/add.php?client_id=<?= $lead['client_id'] ?>"
           class="btn btn-sm btn-outline-info">
            <i class="fa fa-file-lines me-1"></i>New Quotation
        </a>
        <?php elseif (canWrite('crm') && $lead['stage'] !== 'lost'): ?>
        <a href="<?= BASE_URL ?>/modules/crm/convert_lead.php?id=<?= $id ?>"
           class="btn btn-sm btn-success">
            <i class="fa fa-user-plus me-1"></i>Convert to Client
        </a>
        <?php endif; ?>
        <!-- Quick contact actions -->
        <?php if (canWrite('crm') && !in_array($lead['stage'], ['lost','delivered'])): ?>
        <button type="button" class="btn btn-sm btn-outline-primary"
                data-bs-toggle="modal" data-bs-target="#scheduleTdModal">
            <i class="fa fa-car-side me-1"></i>Test Drive
        </button>
        <?php endif; ?>
        <?php if ($lead['pinned_car_id'] || $lead['interested_in']): ?>
        <a href="<?= BASE_URL ?>/modules/crm/proforma.php?lead_id=<?= $id ?>"
           target="_blank" class="btn btn-sm btn-outline-success">
            <i class="fa fa-file-invoice me-1"></i>Proforma
        </a>
        <?php endif; ?>
        <?php if ($lead['phone']): ?>
        <a href="tel:<?= e($lead['phone']) ?>" class="btn btn-sm btn-outline-success" title="Call">
            <i class="fa fa-phone me-1"></i><?= e($lead['phone']) ?>
        </a>
        <?php
        $waNum = preg_replace('/[^0-9]/', '', $lead['phone']);
        if (str_starts_with($lead['phone'], '0')) $waNum = '254' . substr($waNum, 1);
        $agentName = $me['name'] ?? '';
        $company = getSetting('company_name', 'us');
        $interested = $lead['interested_in'] ?? '';
        $waMsg = "Hello {$lead['name']}! I'm {$agentName} from {$company}." . ($interested ? " Following up on your interest in the {$interested}." : '') . " When would be a good time to connect or visit our showroom?";
        ?>
        <a href="https://wa.me/<?= $waNum ?>?text=<?= rawurlencode($waMsg) ?>"
           target="_blank" class="btn btn-sm btn-outline-success" title="WhatsApp <?= e($lead['phone']) ?>">
            <i class="fab fa-whatsapp me-1"></i><i class="fa fa-phone"></i>
        </a>
        <button type="button" class="btn btn-sm btn-success"
                data-bs-toggle="modal" data-bs-target="#waTemplateModal">
            <i class="fab fa-whatsapp me-1"></i>WhatsApp
        </button>
        <?php endif; ?>
        <a href="leads.php" class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-arrow-left me-1"></i>Back
        </a>
        <?php if ($me['role'] === 'super_admin'): ?>
        <button type="button" class="btn btn-sm btn-danger" id="deleteLeadBtn"
                data-name="<?= e($lead['name']) ?>">
            <i class="fa fa-trash me-1"></i>Delete Lead
        </button>
        <?php endif; ?>
    </div>
</div>

<?php if ($me['role'] === 'super_admin'): ?>
<!-- Delete confirmation modal -->
<div class="modal fade" id="deleteLeadModal" tabindex="-1" aria-labelledby="deleteLeadModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title text-danger" id="deleteLeadModalLabel">
                    <i class="fa fa-triangle-exclamation me-2"></i>Delete Lead
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">You are about to permanently delete:</p>
                <p class="fw-bold fs-5 text-dark mb-3"><?= e($lead['name']) ?></p>
                <div class="alert alert-danger py-2 mb-0" style="font-size:13px">
                    <i class="fa fa-circle-exclamation me-1"></i>
                    This will also delete all activity history and test drives for this lead.
                    <strong>This cannot be undone.</strong>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST">
                    <input type="hidden" name="action"  value="delete_lead">
                    <button type="submit" class="btn btn-danger">
                        <i class="fa fa-trash me-1"></i>Yes, Delete Permanently
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
document.getElementById('deleteLeadBtn').addEventListener('click', function () {
    var modal = new bootstrap.Modal(document.getElementById('deleteLeadModal'));
    modal.show();
});
</script>
<?php endif; ?>

<div class="row g-4">
    <!-- Left: details + stage -->
    <div class="col-lg-4">

        <!-- Stage changer -->
        <?php if (canWrite('crm')): ?>
        <div class="card mb-3">
            <div class="card-header fw-semibold"><i class="fa fa-arrow-right me-2"></i>Move Stage</div>
            <div class="card-body p-2">
                <form method="POST" id="stageForm">
                    <input type="hidden" name="action" value="update_stage">
                    <input type="hidden" name="stage" id="stageInput" value="">
                    <input type="hidden" name="lost_reason" id="lostReasonInput" value="">
                    <div class="d-flex flex-wrap gap-1">
                    <?php foreach ($stages as $sk => [$sl,$sc,$si]): ?>
                        <?php if ($sk === 'lost'): ?>
                        <button type="button"
                                class="btn btn-sm <?= $lead['stage'] === $sk ? 'btn-'.$sc : 'btn-outline-'.$sc ?>"
                                data-bs-toggle="modal" data-bs-target="#lostModal">
                            <i class="fa <?= $si ?> me-1"></i><?= $sl ?>
                        </button>
                        <?php elseif ($sk === 'reserved'): ?>
                        <button type="button"
                                class="btn btn-sm <?= $lead['stage'] === $sk ? 'btn-'.$sc : 'btn-outline-'.$sc ?>"
                                data-bs-toggle="modal" data-bs-target="#reserveModal">
                            <i class="fa <?= $si ?> me-1"></i><?= $sl ?>
                        </button>
                        <?php elseif ($sk === 'import_order'): ?>
                        <button type="button"
                                class="btn btn-sm <?= $lead['stage'] === $sk ? 'btn-'.$sc : 'btn-outline-'.$sc ?>"
                                data-bs-toggle="modal" data-bs-target="#importOrderModal">
                            <i class="fa <?= $si ?> me-1"></i><?= $sl ?>
                        </button>
                        <?php elseif ($sk === 'delivered'): ?>
                        <button type="button"
                                class="btn btn-sm <?= $lead['stage'] === $sk ? 'btn-'.$sc : 'btn-outline-'.$sc ?>"
                                data-bs-toggle="modal" data-bs-target="#deliverModal">
                            <i class="fa <?= $si ?> me-1"></i><?= $sl ?>
                        </button>
                        <?php else: ?>
                        <button type="submit" name="stage" value="<?= $sk ?>"
                                class="btn btn-sm <?= $lead['stage'] === $sk ? 'btn-'.$sc : 'btn-outline-'.$sc ?>">
                            <i class="fa <?= $si ?> me-1"></i><?= $sl ?>
                        </button>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Details -->
        <div class="card mb-3">
            <div class="card-header fw-semibold d-flex justify-content-between">
                <span><i class="fa fa-id-card me-2"></i>Lead Details</span>
            </div>
            <div class="card-body" style="font-size:13.5px">
                <dl class="row mb-0">
                    <dt class="col-5 text-muted">Phone</dt>
                    <dd class="col-7"><?= $lead['phone'] ? e($lead['phone']) : '—' ?></dd>
                    <dt class="col-5 text-muted">Email</dt>
                    <dd class="col-7 small"><?= $lead['email'] ? e($lead['email']) : '—' ?></dd>
                    <dt class="col-5 text-muted">Source</dt>
                    <dd class="col-7"><?= e($sourceLabels[$lead['source']] ?? $lead['source']) ?></dd>
                    <?php if (!empty($lead['campaign'])): ?>
                    <dt class="col-5 text-muted">Campaign</dt>
                    <dd class="col-7">
                        <span class="badge bg-info-subtle text-info border border-info-subtle">
                            <i class="fa fa-bullhorn me-1"></i><?= e($lead['campaign']) ?>
                        </span>
                    </dd>
                    <?php endif; ?>
                    <dt class="col-5 text-muted">Interested In</dt>
                    <dd class="col-7 small"><?= $lead['interested_in'] ? e($lead['interested_in']) : '—' ?></dd>
                    <dt class="col-5 text-muted">Budget</dt>
                    <dd class="col-7 fw-semibold text-success"><?= $lead['budget'] ? money((float)$lead['budget']) : '—' ?></dd>
                    <dt class="col-5 text-muted">Assigned To</dt>
                    <dd class="col-7"><?= e($lead['assigned_name'] ?? '—') ?></dd>
                    <dt class="col-5 text-muted">Follow-up</dt>
                    <dd class="col-7">
                        <?php if ($lead['follow_up_date']): ?>
                        <span class="badge <?= $isOverdue ? 'bg-danger' : 'bg-warning text-dark' ?>">
                            <?= fmtDate($lead['follow_up_date'],'d M Y') ?>
                        </span>
                        <?php else: ?>—<?php endif; ?>
                    </dd>
                    <dt class="col-5 text-muted">Added</dt>
                    <dd class="col-7 small text-muted"><?= fmtDate($lead['created_at'],'d M Y') ?></dd>
                    <?php if ($lead['notes']): ?>
                    <dt class="col-5 text-muted">Notes</dt>
                    <dd class="col-7 small"><?= nl2br(e($lead['notes'])) ?></dd>
                    <?php endif; ?>
                    <?php if ($lead['lost_reason']): ?>
                    <dt class="col-5 text-muted">Lost Reason</dt>
                    <dd class="col-7 small text-danger"><?= e($lead['lost_reason']) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <!-- Linked Vehicle -->
        <?php
        $_primaryImg = null;
        foreach ($pinnedCarImages as $_img) { if ($_img['is_primary']) { $_primaryImg = $_img; break; } }
        if (!$_primaryImg && !empty($pinnedCarImages)) $_primaryImg = $pinnedCarImages[0];
        ?>
        <div class="card mb-3">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center py-2">
                <span><i class="fa fa-car me-2 text-primary"></i>Linked Vehicle</span>
                <?php if ($pinnedCar && canWrite('crm')): ?>
                <form method="POST" id="unpinForm" class="d-inline">
                    <input type="hidden" name="action" value="pin_car">
                    <input type="hidden" name="car_id" value="">
                    <button type="submit" class="btn btn-xs btn-outline-danger"
                            style="font-size:11px;padding:2px 8px"
                            onclick="return confirm('Remove linked vehicle?')">
                        <i class="fa fa-unlink me-1"></i>Unlink
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <?php if ($pinnedCar): ?>
            <!-- ── Rich vehicle display ──────────────────────────────── -->

            <?php if ($_primaryImg): ?>
            <!-- Main image + thumbnails -->
            <div style="background:#111;line-height:0">
                <img id="crmMainImg"
                     src="<?= BASE_URL ?>/uploads/cars/<?= e($_primaryImg['file_path']) ?>"
                     alt="<?= e(($pinnedCar['make'] ?? '') . ' ' . ($pinnedCar['model'] ?? '')) ?>"
                     style="width:100%;height:210px;object-fit:cover;display:block;cursor:zoom-in"
                     loading="lazy" decoding="async"
                     onclick="window.open(this.src,'_blank')">
            </div>
            <?php if (count($pinnedCarImages) > 1): ?>
            <div class="d-flex gap-1 p-2" style="overflow-x:auto;background:#f0f0f0;line-height:0">
                <?php foreach ($pinnedCarImages as $_thumb): ?>
                <img src="<?= BASE_URL ?>/uploads/cars/<?= e($_thumb['file_path']) ?>"
                     alt="" loading="lazy" decoding="async"
                     onclick="document.getElementById('crmMainImg').src=this.src;
                              document.querySelectorAll('.crm-thumb').forEach(function(t){t.style.borderColor='#ccc'});
                              this.style.borderColor='#2563eb'"
                     class="crm-thumb rounded"
                     style="width:58px;height:44px;object-fit:cover;cursor:pointer;flex-shrink:0;
                            border:2px solid <?= $_thumb['is_primary'] ? '#2563eb' : '#ccc' ?>;
                            transition:border-color .15s">
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <div class="d-flex align-items-center justify-content-center bg-light"
                 style="height:120px;border-bottom:1px solid #dee2e6">
                <i class="fa fa-car text-muted" style="font-size:40px;opacity:.35"></i>
            </div>
            <?php endif; ?>

            <!-- Vehicle specs -->
            <div class="p-3" style="font-size:13px">
                <div class="fw-bold mb-1" style="font-size:15px">
                    <?= e(trim(($pinnedCar['year'] ?? '') . ' ' . ($pinnedCar['make'] ?? '') . ' ' . ($pinnedCar['model'] ?? ''))) ?>
                </div>
                <div class="text-muted small mb-2">
                    <?php if (!empty($pinnedCar['registration_number'])): ?>
                    <span class="badge bg-light text-dark border me-1"><?= e($pinnedCar['registration_number']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($pinnedCar['color'])): ?>
                    <span><?= e($pinnedCar['color']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="row g-1 mb-2" style="font-size:12px">
                    <?php
                    $specFields = [
                        'body_type'    => 'Body',
                        'transmission' => 'Gearbox',
                        'fuel_type'    => 'Fuel',
                        'engine_cc'    => 'Engine CC',
                        'mileage'      => 'Mileage',
                    ];
                    foreach ($specFields as $col => $label):
                        $val = $pinnedCar[$col] ?? null;
                        if (empty($val)) continue;
                        if ($col === 'mileage') $val = number_format((int)$val) . ' km';
                    ?>
                    <div class="col-6">
                        <span class="text-muted"><?= $label ?>:</span>
                        <span class="fw-semibold ms-1"><?= e($val) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php
                $salePrice = (float)($pinnedCar['asking_price'] ?? $pinnedCar['selling_price'] ?? 0);
                ?>
                <?php if ($salePrice > 0): ?>
                <div class="d-flex align-items-center justify-content-between mb-2 py-2 border-top border-bottom">
                    <span class="text-muted small">Asking Price</span>
                    <span class="fw-bold text-success" style="font-size:17px"><?= money($salePrice) ?></span>
                </div>
                <?php endif; ?>

                <a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= (int)$pinnedCar['id'] ?>"
                   target="_blank"
                   class="btn btn-sm btn-outline-primary w-100 mt-1">
                    <i class="fa fa-arrow-up-right-from-square me-1"></i>View Full Vehicle Record
                </a>
            </div>

            <?php if (canWrite('crm')): ?>
            <!-- Change vehicle search -->
            <div class="px-3 pb-3 border-top pt-2">
                <div class="small text-muted mb-1 fw-semibold">Change linked vehicle:</div>
                <div style="position:relative">
                    <input type="text" id="carSearchInput" class="form-control form-control-sm"
                           placeholder="Search make, model or registration…" autocomplete="off">
                    <div id="carResults" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:1050;background:#fff;border:1px solid #dee2e6;border-radius:6px;box-shadow:0 6px 20px rgba(0,0,0,.15);max-height:280px;overflow-y:auto;margin-top:2px"></div>
                </div>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <!-- ── No vehicle linked: search-first UI ─────────────────── -->
            <div class="p-3">
                <p class="text-muted small mb-2">
                    <i class="fa fa-search me-1"></i>Search your inventory to link a vehicle to this lead.
                </p>
                <?php if (canWrite('crm')): ?>
                <div style="position:relative">
                    <input type="text" id="carSearchInput" class="form-control form-control-sm"
                           placeholder="Type make, model or registration…" autocomplete="off">
                    <div id="carResults" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:1050;background:#fff;border:1px solid #dee2e6;border-radius:6px;box-shadow:0 6px 20px rgba(0,0,0,.15);max-height:280px;overflow-y:auto;margin-top:2px"></div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- ── Car preview panel (shown on selection) ──────────────── -->
            <?php if (canWrite('crm')): ?>
            <div id="carPreviewPanel" style="display:none;border-top:2px solid #2563eb">
                <div class="px-3 py-2 bg-primary bg-opacity-10 d-flex justify-content-between align-items-center">
                    <span class="fw-semibold text-primary small"><i class="fa fa-eye me-1"></i>Selected Vehicle — confirm to link</span>
                    <button type="button" onclick="clearCarPreview()" class="btn-close" style="font-size:10px"></button>
                </div>
                <div class="d-flex" style="min-height:90px">
                    <div id="previewImgBox" style="width:120px;flex-shrink:0;background:#eee;display:flex;align-items:center;justify-content:center;overflow:hidden">
                        <i class="fa fa-car text-muted fa-2x" id="previewImgIcon"></i>
                        <img id="previewImg" src="" alt="" style="display:none;width:120px;height:100%;object-fit:cover;min-height:90px">
                    </div>
                    <div class="p-2 flex-grow-1" style="font-size:12.5px">
                        <div class="fw-bold" id="previewTitle"></div>
                        <div class="text-muted" id="previewSub"></div>
                        <div class="text-success fw-bold mt-1" id="previewPrice" style="font-size:14px"></div>
                        <div class="text-muted small mt-1" id="previewSpecs"></div>
                    </div>
                </div>
                <div class="px-3 pb-3 pt-2">
                    <form method="POST" id="pinCarForm">
                        <input type="hidden" name="action" value="pin_car">
                        <input type="hidden" name="car_id" id="pinCarId">
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="fa fa-link me-1"></i>Link This Vehicle to Lead
                        </button>
                    </form>
                </div>
            </div>

            <script>
            (function () {
                var inp     = document.getElementById('carSearchInput');
                var res     = document.getElementById('carResults');
                var panel   = document.getElementById('carPreviewPanel');
                var pImg    = document.getElementById('previewImg');
                var pIcon   = document.getElementById('previewImgIcon');
                var pTitle  = document.getElementById('previewTitle');
                var pSub    = document.getElementById('previewSub');
                var pPrice  = document.getElementById('previewPrice');
                var pSpecs  = document.getElementById('previewSpecs');
                var idField = document.getElementById('pinCarId');
                var base    = '<?= BASE_URL ?>';
                var timer;

                if (!inp) return;

                inp.addEventListener('input', function () {
                    clearTimeout(timer);
                    var q = inp.value.trim();
                    if (q.length < 2) { res.style.display = 'none'; return; }
                    timer = setTimeout(function () {
                        fetch(base + '/modules/crm/api/search_cars.php?q=' + encodeURIComponent(q))
                            .then(function (r) { return r.json(); })
                            .then(function (d) {
                                res.innerHTML = '';
                                if (!d.cars || !d.cars.length) {
                                    res.innerHTML = '<div style="padding:10px 12px;font-size:12.5px;color:#6c757d">No vehicles found in inventory</div>';
                                    res.style.display = '';
                                    return;
                                }
                                d.cars.forEach(function (c) {
                                    var row = document.createElement('div');
                                    row.style.cssText = 'display:flex;align-items:center;gap:10px;padding:8px 12px;cursor:pointer;border-bottom:1px solid #f0f0f0;font-size:12.5px';
                                    row.onmouseover = function () { row.style.background = '#f0f6ff'; };
                                    row.onmouseout  = function () { row.style.background = ''; };

                                    // Thumbnail
                                    var thumb = document.createElement('div');
                                    thumb.style.cssText = 'width:54px;height:40px;flex-shrink:0;border-radius:5px;overflow:hidden;background:#e9ecef;display:flex;align-items:center;justify-content:center';
                                    if (c.primary_image) {
                                        var img = document.createElement('img');
                                        img.src = base + '/uploads/cars/' + c.primary_image;
                                        img.style.cssText = 'width:100%;height:100%;object-fit:cover';
                                        img.onerror = function () { thumb.innerHTML = '<i class="fa fa-car" style="color:#adb5bd;font-size:15px"></i>'; };
                                        thumb.appendChild(img);
                                    } else {
                                        thumb.innerHTML = '<i class="fa fa-car" style="color:#adb5bd;font-size:15px"></i>';
                                    }
                                    row.appendChild(thumb);

                                    // Text
                                    var info = document.createElement('div');
                                    info.style.flex = '1';
                                    var title = [c.year, c.make, c.model].filter(Boolean).join(' ');
                                    var sub   = c.reg || '';
                                    var specs = [c.transmission, c.fuel_type].filter(Boolean).join(' · ');
                                    var priceStr = c.price ? '<span style="color:#198754;font-weight:600">KES ' + Number(c.price).toLocaleString() + '</span>' : '';
                                    info.innerHTML =
                                        '<div style="font-weight:600">' + title + '</div>' +
                                        '<div style="color:#6c757d;font-size:11.5px">' + sub + (specs ? ' &nbsp;·&nbsp; ' + specs : '') + (priceStr ? ' &nbsp;·&nbsp; ' + priceStr : '') + '</div>';
                                    row.appendChild(info);

                                    row.addEventListener('click', function () {
                                        showPreview(c);
                                        res.style.display = 'none';
                                        inp.value = '';
                                    });
                                    res.appendChild(row);
                                });
                                res.style.display = '';
                            }).catch(function () {});
                    }, 280);
                });

                function showPreview(c) {
                    idField.value = c.id;
                    pTitle.textContent = [c.year, c.make, c.model].filter(Boolean).join(' ');
                    pSub.textContent   = [c.reg, c.color].filter(Boolean).join(' · ');
                    pPrice.textContent = c.price ? 'KES ' + Number(c.price).toLocaleString() : '';
                    pSpecs.textContent = [c.body_type, c.transmission, c.fuel_type, c.mileage ? Number(c.mileage).toLocaleString() + ' km' : ''].filter(Boolean).join(' · ');

                    if (c.primary_image) {
                        pImg.src          = base + '/uploads/cars/' + c.primary_image;
                        pImg.style.display = '';
                        pIcon.style.display = 'none';
                    } else {
                        pImg.style.display  = 'none';
                        pIcon.style.display = '';
                    }
                    panel.style.display = '';
                    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }

                window.clearCarPreview = function () {
                    panel.style.display = 'none';
                    idField.value = '';
                    pImg.src = '';
                };

                document.addEventListener('click', function (e) {
                    if (res && !res.contains(e.target) && e.target !== inp) {
                        res.style.display = 'none';
                    }
                });
            }());
            </script>
            <?php endif; ?>
        </div>

        <!-- Test Drives -->
        <?php if (!empty($testDrives) || (canWrite('crm') && !in_array($lead['stage'],['lost','delivered']))): ?>
        <div class="card mb-3">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center py-2">
                <span><i class="fa fa-car-side me-2 text-primary"></i>Test Drives</span>
                <?php if (canWrite('crm') && !in_array($lead['stage'],['lost','delivered'])): ?>
                <button class="btn btn-xs btn-primary" style="font-size:11px;padding:2px 8px"
                        data-bs-toggle="modal" data-bs-target="#scheduleTdModal">
                    <i class="fa fa-plus me-1"></i>Schedule
                </button>
                <?php endif; ?>
            </div>
            <?php if ($testDrives): ?>
            <ul class="list-group list-group-flush" style="font-size:12.5px">
                <?php foreach ($testDrives as $td):
                    $tdColors = ['scheduled'=>'primary','completed'=>'success','no_show'=>'danger','cancelled'=>'secondary'];
                    $tdColor  = $tdColors[$td['status']] ?? 'secondary';
                    $chassis  = $td['chassis_number'] ?: $td['car_chassis'] ?? '';
                    $entry    = $td['entry_number']   ?: $td['car_entry']   ?? '';
                ?>
                <li class="list-group-item py-2">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div class="flex-grow-1">
                            <div class="fw-semibold">
                                <?= date('d M Y', strtotime($td['scheduled_date'])) ?>
                                at <?= date('H:i', strtotime($td['scheduled_time'])) ?>
                                <span class="text-muted fw-normal">(<?= $td['duration_minutes'] ?> min)</span>
                            </div>
                            <?php if ($td['make']): ?>
                            <div class="text-muted small">
                                <?= e($td['make'].' '.$td['model']) ?>
                                <?= $td['registration_number'] ? ' · '.e($td['registration_number']) : '' ?>
                                <?= $td['car_year'] ? ' · '.e($td['car_year']) : '' ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($chassis || $entry): ?>
                            <div class="text-muted small">
                                <?php if ($chassis): ?><span title="Chassis">Chassis: <code><?= e($chassis) ?></code></span><?php endif; ?>
                                <?php if ($chassis && $entry): ?> &nbsp;·&nbsp; <?php endif; ?>
                                <?php if ($entry): ?><span title="Entry No">Entry: <code><?= e($entry) ?></code></span><?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($td['driver_id_no'] || $td['kd_number']): ?>
                            <div class="text-muted small">
                                <?php if ($td['driver_id_no']): ?>ID: <?= e($td['driver_id_no']) ?><?php endif; ?>
                                <?php if ($td['driver_id_no'] && $td['kd_number']): ?> &nbsp;·&nbsp; <?php endif; ?>
                                <?php if ($td['kd_number']): ?>KD: <?= e($td['kd_number']) ?><?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($td['outcome']): ?>
                            <div class="text-muted small fst-italic mt-1"><?= e($td['outcome']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex flex-column align-items-end gap-1 flex-shrink-0">
                            <span class="badge bg-<?= $tdColor ?>"><?= ucfirst(str_replace('_',' ',$td['status'])) ?></span>
                            <a href="<?= BASE_URL ?>/modules/crm/test_drive_slip.php?id=<?= $td['id'] ?>"
                               target="_blank" class="btn btn-xs btn-outline-secondary" style="font-size:10px;padding:2px 7px">
                                <i class="fa fa-print me-1"></i>Slip
                            </a>
                        </div>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
            <div class="card-body text-muted small">No test drives scheduled yet.</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Edit details -->
        <?php $canEditCrm = canWrite('crm'); ?>
        <div class="card">
            <div class="card-header fw-semibold"><i class="fa fa-pen me-2"></i>Edit Details</div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_details">
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Full Name</label>
                        <input type="text" name="name" class="form-control form-control-sm" value="<?= e($lead['name']) ?>" required>
                    </div>

                    <?php if (!$canEditCrm): ?>
                    <div class="form-text text-muted mb-2"><i class="fa fa-lock me-1"></i>Contact/assignment fields below are read-only for your role.</div>
                    <?php endif; ?>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Phone</label>
                        <input type="text" name="phone" class="form-control form-control-sm" value="<?= e($lead['phone'] ?? '') ?>" <?= $canEditCrm ? '' : 'disabled' ?>>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Email</label>
                        <input type="email" name="email" class="form-control form-control-sm" value="<?= e($lead['email'] ?? '') ?>" <?= $canEditCrm ? '' : 'disabled' ?>>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Interested In</label>
                        <input type="text" name="interested_in" class="form-control form-control-sm" value="<?= e($lead['interested_in'] ?? '') ?>" <?= $canEditCrm ? '' : 'disabled' ?>>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Budget (KES)</label>
                            <input type="number" name="budget" class="form-control form-control-sm" value="<?= e($lead['budget'] ?? '') ?>" step="1000" <?= $canEditCrm ? '' : 'disabled' ?>>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Follow-up</label>
                            <input type="date" name="follow_up_date" class="form-control form-control-sm" value="<?= e($lead['follow_up_date'] ?? '') ?>" <?= $canEditCrm ? '' : 'disabled' ?>>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Assigned To</label>
                        <select name="assigned_to" class="form-select form-select-sm" <?= $canEditCrm ? '' : 'disabled' ?>>
                            <option value="">— Unassigned —</option>
                            <?php foreach ($salesUsers as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $lead['assigned_to'] == $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Campaign / Ad</label>
                        <input type="text" name="campaign" class="form-control form-control-sm"
                               value="<?= e($lead['campaign'] ?? '') ?>"
                               placeholder="e.g. Facebook Summer Ad, Google Q4…" <?= $canEditCrm ? '' : 'disabled' ?>>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Notes</label>
                        <textarea name="notes" class="form-control form-control-sm" rows="2" <?= $canEditCrm ? '' : 'disabled' ?>><?= e($lead['notes'] ?? '') ?></textarea>
                    </div>
                    <?php if (in_array($lead['stage'],['lost'])): ?>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Lost Reason</label>
                        <input type="text" name="lost_reason" class="form-control form-control-sm" value="<?= e($lead['lost_reason'] ?? '') ?>" placeholder="e.g. Price too high, bought elsewhere…" <?= $canEditCrm ? '' : 'disabled' ?>>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="lost_reason" value="<?= e($lead['lost_reason'] ?? '') ?>">
                    <?php endif; ?>

                    <hr class="my-3">
                    <div class="fw-semibold small text-muted mb-2"><i class="fa fa-id-card me-1"></i>Identity / KYC Details</div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">ID Number</label>
                        <input type="text" name="id_number" class="form-control form-control-sm" value="<?= e($lead['id_number'] ?? '') ?>" placeholder="National ID / Passport No.">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">KRA PIN</label>
                        <input type="text" name="kra_pin" class="form-control form-control-sm text-uppercase" value="<?= e($lead['kra_pin'] ?? '') ?>" oninput="this.value=this.value.toUpperCase()" placeholder="A000000000X">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">P.O. Box</label>
                        <input type="text" name="po_box" class="form-control form-control-sm" value="<?= e($lead['po_box'] ?? '') ?>" placeholder="e.g. 12345-00100, Nairobi">
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">ID Card — Front</label>
                            <?php if (!empty($lead['id_card_front'])): ?>
                            <a href="<?= BASE_URL ?>/uploads/leads/<?= e($lead['id_card_front']) ?>" target="_blank" class="d-block mb-1">
                                <img src="<?= thumbUrl('leads', $lead['id_card_front']) ?>" class="img-thumbnail" style="max-height:70px">
                            </a>
                            <?php endif; ?>
                            <input type="file" name="id_card_front" class="form-control form-control-sm" accept="image/*">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">ID Card — Back</label>
                            <?php if (!empty($lead['id_card_back'])): ?>
                            <a href="<?= BASE_URL ?>/uploads/leads/<?= e($lead['id_card_back']) ?>" target="_blank" class="d-block mb-1">
                                <img src="<?= thumbUrl('leads', $lead['id_card_back']) ?>" class="img-thumbnail" style="max-height:70px">
                            </a>
                            <?php endif; ?>
                            <input type="file" name="id_card_back" class="form-control form-control-sm" accept="image/*">
                        </div>
                    </div>

                    <button class="btn btn-sm btn-primary w-100 mt-1"><i class="fa fa-save me-1"></i>Save Changes</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Right: log activity + activity timeline -->
    <div class="col-lg-8">

        <!-- Log Activity -->
        <?php if (canWrite('crm')): ?>
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-plus me-2 text-success"></i>Log Activity</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="log_activity">
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Type</label>
                            <select name="type" class="form-select form-select-sm">
                                <?php foreach ($activityTypes as $k => [$lbl, $ico, $cls]): ?>
                                <option value="<?= $k ?>"><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Summary <span class="text-danger">*</span></label>
                            <input type="text" name="summary" class="form-control form-control-sm" required
                                   placeholder="e.g. Called client, discussed Land Cruiser pricing…">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Next Follow-up</label>
                            <input type="date" name="follow_up_date" class="form-control form-control-sm">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Outcome / Notes</label>
                            <textarea name="outcome" class="form-control form-control-sm" rows="2"
                                      placeholder="What was the outcome? Any next steps?"></textarea>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-sm btn-success"><i class="fa fa-check me-1"></i>Log Activity</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Reservation Documents (shown only when stage = reserved) -->
        <?php if ($lead['stage'] === 'reserved'): ?>
        <?php
        $depAmt        = (float)($lead['deposit_amount']    ?? 0);
        $depDate       = $lead['deposit_date']               ?? date('Y-m-d');
        $agreedPrice   = (float)($lead['agreed_sale_price']  ?? 0);
        $carIsOffer    = $pinnedCar && !empty($pinnedCar['offer_price']) && (float)$pinnedCar['offer_price'] > 0;
        $carListPrice  = $pinnedCar
            ? ($carIsOffer ? (float)$pinnedCar['offer_price'] : (float)($pinnedCar['asking_price'] ?? 0))
            : 0;
        $effectivePrice = $agreedPrice > 0 ? $agreedPrice : $carListPrice;
        $resDiscount    = ($carListPrice > 0 && $agreedPrice > 0 && $agreedPrice < $carListPrice)
            ? ($carListPrice - $agreedPrice) : 0;
        $resDiscPct     = ($carListPrice > 0 && $resDiscount > 0)
            ? round(($resDiscount / $carListPrice) * 100, 1) : 0;
        $balance        = max(0, $effectivePrice - $depAmt);
        ?>
        <div class="card mb-4" style="border-color:#16a34a;border-width:2px">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center"
                 style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);border-bottom-color:#bbf7d0">
                <span style="color:#15803d">
                    <i class="fa fa-bookmark me-2"></i>Reservation Summary
                </span>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="proforma.php?lead_id=<?= $id ?>" target="_blank"
                       class="btn btn-sm btn-outline-primary">
                        <i class="fa fa-file-invoice me-1"></i>Proforma
                    </a>
                    <a href="sales_agreement.php?lead_id=<?= $id ?>" target="_blank"
                       class="btn btn-sm btn-outline-success">
                        <i class="fa fa-file-signature me-1"></i>Agreement
                    </a>
                    <a href="deposit_receipt.php?lead_id=<?= $id ?>" target="_blank"
                       class="btn btn-sm btn-outline-warning">
                        <i class="fa fa-receipt me-1"></i>Deposit Receipt
                    </a>
                    <a href="sales_receipt.php?lead_id=<?= $id ?>" target="_blank"
                       class="btn btn-sm btn-outline-info">
                        <i class="fa fa-file-invoice-dollar me-1"></i>Sales Receipt
                    </a>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-3 mb-3">
                    <!-- Agreed Sale Price -->
                    <div class="col-md-3">
                        <div class="rounded-3 p-3 text-center" style="background:#eff6ff;border:1px solid #bfdbfe">
                            <div class="text-muted small mb-1">
                                <?= $agreedPrice > 0 ? 'Agreed Sale Price' : 'List Price' ?>
                                <?php if ($carIsOffer): ?>
                                <span class="badge bg-danger ms-1" style="font-size:9px">OFFER</span>
                                <?php endif; ?>
                            </div>
                            <div class="fw-bold" style="font-size:20px;color:#1d4ed8">
                                <?= $effectivePrice > 0 ? money($effectivePrice) : '—' ?>
                            </div>
                            <?php if ($carListPrice > 0 && $agreedPrice > 0 && $agreedPrice != $carListPrice): ?>
                            <div class="text-muted" style="font-size:11px"><s><?= money($carListPrice) ?></s></div>
                            <?php else: ?>
                            <div class="text-muted" style="font-size:11px">
                                <?= $pinnedCar ? e(trim(($pinnedCar['year']??'').' '.($pinnedCar['make']??'').' '.($pinnedCar['model']??''))) : '—' ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <!-- Discount -->
                    <div class="col-md-3">
                        <div class="rounded-3 p-3 text-center" style="background:<?= $resDiscount > 0 ? '#f0fdf4' : '#f8fafc' ?>;border:1px solid <?= $resDiscount > 0 ? '#bbf7d0' : '#e2e8f0' ?>">
                            <div class="text-muted small mb-1">Discount Given</div>
                            <?php if ($resDiscount > 0): ?>
                            <div class="fw-bold text-success" style="font-size:20px"><?= money($resDiscount) ?></div>
                            <div class="text-muted" style="font-size:11px"><?= $resDiscPct ?>% off list price</div>
                            <?php else: ?>
                            <div class="fw-bold text-muted" style="font-size:20px">—</div>
                            <div class="text-muted" style="font-size:11px">No discount</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <!-- Deposit -->
                    <div class="col-md-3">
                        <div class="rounded-3 p-3 text-center" style="background:#f0fdf4;border:1px solid #bbf7d0">
                            <div class="text-muted small mb-1">Deposit Paid</div>
                            <div class="fw-bold text-success" style="font-size:20px"><?= money($depAmt) ?></div>
                            <div class="text-muted" style="font-size:11px"><?= fmtDate($depDate,'d M Y') ?></div>
                        </div>
                    </div>
                    <!-- Balance -->
                    <div class="col-md-3">
                        <div class="rounded-3 p-3 text-center" style="background:#fff7ed;border:1px solid #fed7aa">
                            <div class="text-muted small mb-1">Balance Due</div>
                            <div class="fw-bold" style="font-size:20px;color:#c2410c">
                                <?= $effectivePrice > 0 ? money($balance) : '—' ?>
                            </div>
                            <div class="text-muted" style="font-size:11px">Remaining to complete sale</div>
                        </div>
                    </div>
                </div>
                <div class="small border-top pt-2">
                    <?php if ($lead['deposit_notes']): ?>
                    <div class="text-muted fst-italic mb-1">
                        <i class="fa fa-note-sticky me-1"></i><?= e($lead['deposit_notes']) ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($lead['agreement_received_date'])): ?>
                    <div class="text-success"><i class="fa fa-file-circle-check me-1"></i>Signed agreement received: <?= fmtDate($lead['agreement_received_date'],'d M Y') ?></div>
                    <?php else: ?>
                    <div class="text-muted"><i class="fa fa-file-circle-xmark me-1"></i>Signed agreement not yet received</div>
                    <?php endif; ?>
                </div>
                <div class="border-top pt-3 mt-2 d-flex gap-2 flex-wrap align-items-center">
                    <a href="proforma.php?lead_id=<?= $id ?>" target="_blank"
                       class="btn btn-outline-primary btn-sm">
                        <i class="fa fa-file-invoice me-1"></i>Proforma Invoice
                    </a>
                    <a href="sales_agreement.php?lead_id=<?= $id ?>" target="_blank"
                       class="btn btn-outline-success btn-sm">
                        <i class="fa fa-file-signature me-1"></i>Sales Agreement
                    </a>
                    <a href="deposit_receipt.php?lead_id=<?= $id ?>" target="_blank"
                       class="btn btn-warning btn-sm">
                        <i class="fa fa-receipt me-1"></i>Deposit Receipt
                    </a>
                    <a href="sales_receipt.php?lead_id=<?= $id ?>" target="_blank"
                       class="btn btn-info btn-sm text-white">
                        <i class="fa fa-file-invoice-dollar me-1"></i>Sales Receipt
                    </a>
                    <button type="button" class="btn btn-outline-secondary btn-sm ms-auto"
                            data-bs-toggle="modal" data-bs-target="#reserveModal">
                        <i class="fa fa-pen me-1"></i>Update Reservation
                    </button>
                    <?php if ($me['role'] === 'admin'): ?>
                    <form method="POST" class="ms-2"
                          onsubmit="return confirm('Revoke this reservation?\n\nThis will:\n• Return the lead to Active stage\n• Free the vehicle back to Available\n• Clear all deposit and pricing data\n\nThis cannot be undone.')">
                        <input type="hidden" name="action" value="revoke_reservation">
                        <button type="submit" class="btn btn-danger btn-sm">
                            <i class="fa fa-ban me-1"></i>Revoke Reservation
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Import Order Summary (shown only when stage = import_order) -->
        <?php if ($lead['stage'] === 'import_order'): ?>
        <?php
        $ioDepAmt   = (float)($lead['deposit_amount']       ?? 0);
        $ioDepDate  = $lead['deposit_date']                  ?? date('Y-m-d');
        $ioAgreed   = (float)($lead['agreed_sale_price']     ?? 0);
        $ioBalance  = max(0, $ioAgreed - $ioDepAmt);
        $ioVehicle  = $lead['import_vehicle_details']        ?? '';
        $ioArrival  = $lead['expected_arrival_date']         ?? '';
        $ioDueDate  = $lead['due_date']                      ?? '';
        ?>
        <div class="card mb-4" style="border-color:#d97706;border-width:2px">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2"
                 style="background:linear-gradient(135deg,#fffbeb,#fef3c7);border-bottom-color:#fde68a">
                <span style="color:#92400e">
                    <i class="fa fa-ship me-2"></i>Import Order Summary
                </span>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="proforma.php?lead_id=<?= $id ?>" target="_blank"
                       class="btn btn-sm btn-outline-primary">
                        <i class="fa fa-file-invoice me-1"></i>Proforma
                    </a>
                    <a href="sales_agreement.php?lead_id=<?= $id ?>" target="_blank"
                       class="btn btn-sm btn-outline-success">
                        <i class="fa fa-file-signature me-1"></i>Agreement
                    </a>
                    <a href="deposit_receipt.php?lead_id=<?= $id ?>" target="_blank"
                       class="btn btn-sm btn-outline-warning">
                        <i class="fa fa-receipt me-1"></i>Deposit Receipt
                    </a>
                    <a href="sales_receipt.php?lead_id=<?= $id ?>" target="_blank"
                       class="btn btn-sm btn-outline-info">
                        <i class="fa fa-file-invoice-dollar me-1"></i>Sales Receipt
                    </a>
                </div>
            </div>
            <div class="card-body">
                <?php if ($ioVehicle): ?>
                <div class="mb-3 p-3 rounded-3" style="background:#fffbeb;border:1px solid #fde68a">
                    <div class="text-muted small fw-semibold mb-1">
                        <i class="fa fa-car me-1"></i>Vehicle on Order
                    </div>
                    <div class="fw-bold" style="font-size:15px;color:#92400e"><?= e($ioVehicle) ?></div>
                </div>
                <?php endif; ?>
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <div class="rounded-3 p-3 text-center" style="background:#eff6ff;border:1px solid #bfdbfe">
                            <div class="text-muted small mb-1">Agreed Sale Price</div>
                            <div class="fw-bold" style="font-size:20px;color:#1d4ed8">
                                <?= $ioAgreed > 0 ? money($ioAgreed) : '—' ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="rounded-3 p-3 text-center" style="background:#f0fdf4;border:1px solid #bbf7d0">
                            <div class="text-muted small mb-1">Deposit Paid</div>
                            <div class="fw-bold text-success" style="font-size:20px"><?= money($ioDepAmt) ?></div>
                            <div class="text-muted" style="font-size:11px"><?= fmtDate($ioDepDate,'d M Y') ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="rounded-3 p-3 text-center" style="background:#fff7ed;border:1px solid #fed7aa">
                            <div class="text-muted small mb-1">Balance Due</div>
                            <div class="fw-bold" style="font-size:20px;color:#c2410c">
                                <?= $ioAgreed > 0 ? money($ioBalance) : '—' ?>
                            </div>
                            <?php if ($ioDueDate): ?>
                            <div class="text-muted" style="font-size:11px">by <?= fmtDate($ioDueDate,'d M Y') ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="rounded-3 p-3 text-center" style="background:#fffbeb;border:1px solid #fde68a">
                            <div class="text-muted small mb-1">
                                <i class="fa fa-calendar-check me-1"></i>Expected Arrival
                            </div>
                            <div class="fw-bold" style="font-size:16px;color:#92400e">
                                <?= $ioArrival ? fmtDate($ioArrival,'d M Y') : '—' ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php if ($lead['deposit_notes']): ?>
                <div class="text-muted small fst-italic border-top pt-2 mb-3">
                    <i class="fa fa-note-sticky me-1"></i><?= e($lead['deposit_notes']) ?>
                </div>
                <?php endif; ?>
                <div class="border-top pt-3 mt-2 d-flex gap-2 flex-wrap align-items-center">
                    <a href="proforma.php?lead_id=<?= $id ?>" target="_blank"
                       class="btn btn-outline-primary btn-sm">
                        <i class="fa fa-file-invoice me-1"></i>Proforma Invoice
                    </a>
                    <a href="sales_agreement.php?lead_id=<?= $id ?>" target="_blank"
                       class="btn btn-outline-success btn-sm">
                        <i class="fa fa-file-signature me-1"></i>Sales Agreement
                    </a>
                    <a href="deposit_receipt.php?lead_id=<?= $id ?>" target="_blank"
                       class="btn btn-warning btn-sm">
                        <i class="fa fa-receipt me-1"></i>Deposit Receipt
                    </a>
                    <a href="sales_receipt.php?lead_id=<?= $id ?>" target="_blank"
                       class="btn btn-info btn-sm text-white">
                        <i class="fa fa-file-invoice-dollar me-1"></i>Sales Receipt
                    </a>
                    <button type="button" class="btn btn-outline-secondary btn-sm ms-auto"
                            data-bs-toggle="modal" data-bs-target="#importOrderModal">
                        <i class="fa fa-pen me-1"></i>Update Import Order
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Delivery Summary (shown only when stage = delivered) -->
        <?php if ($lead['stage'] === 'delivered'): ?>
        <?php
        $dlvDate    = $lead['delivered_at']             ?? ($lead['converted_at'] ?? date('Y-m-d'));
        $dlvAgreed  = (float)($lead['agreed_sale_price'] ?? 0);
        $dlvVehicle = $lead['import_vehicle_details']   ?? '';
        ?>
        <div class="card mb-4" style="border-color:#16a34a;border-width:2px">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2"
                 style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);border-bottom-color:#bbf7d0">
                <span style="color:#15803d">
                    <i class="fa fa-truck me-2"></i>Delivery Summary
                    <span class="badge ms-2" style="background:#16a34a;font-size:10px;letter-spacing:.3px">DELIVERED</span>
                </span>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="delivery_note.php?lead_id=<?= $id ?>" target="_blank"
                       class="btn btn-sm btn-success">
                        <i class="fa fa-truck me-1"></i>Delivery Note
                    </a>
                    <a href="sales_receipt.php?lead_id=<?= $id ?>" target="_blank"
                       class="btn btn-sm btn-outline-info">
                        <i class="fa fa-file-invoice-dollar me-1"></i>Sales Receipt
                    </a>
                    <a href="proforma.php?lead_id=<?= $id ?>" target="_blank"
                       class="btn btn-sm btn-outline-primary">
                        <i class="fa fa-file-invoice me-1"></i>Proforma
                    </a>
                    <a href="sales_agreement.php?lead_id=<?= $id ?>" target="_blank"
                       class="btn btn-sm btn-outline-secondary">
                        <i class="fa fa-file-signature me-1"></i>Agreement
                    </a>
                </div>
            </div>
            <div class="card-body">
                <?php if ($dlvVehicle): ?>
                <div class="mb-3 p-3 rounded-3" style="background:#f0fdf4;border:1px solid #bbf7d0">
                    <div class="text-muted small fw-semibold mb-1"><i class="fa fa-car me-1"></i>Vehicle Delivered</div>
                    <div class="fw-bold" style="font-size:15px;color:#15803d"><?= e($dlvVehicle) ?></div>
                </div>
                <?php elseif ($pinnedCar): ?>
                <div class="mb-3 p-3 rounded-3" style="background:#f0fdf4;border:1px solid #bbf7d0">
                    <div class="text-muted small fw-semibold mb-1"><i class="fa fa-car me-1"></i>Vehicle Delivered</div>
                    <div class="fw-bold" style="font-size:15px;color:#15803d">
                        <?= e(trim(($pinnedCar['year']??'').' '.($pinnedCar['make']??'').' '.($pinnedCar['model']??''))) ?>
                        <?php if ($pinnedCar['registration_number']): ?>
                        <span class="text-muted fw-normal" style="font-size:13px"> — <?= e($pinnedCar['registration_number']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <div class="rounded-3 p-3 text-center" style="background:#eff6ff;border:1px solid #bfdbfe">
                            <div class="text-muted small mb-1">Total Sale Price</div>
                            <div class="fw-bold" style="font-size:20px;color:#1d4ed8">
                                <?= $dlvAgreed > 0 ? money($dlvAgreed) : '—' ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="rounded-3 p-3 text-center" style="background:#f0fdf4;border:1px solid #bbf7d0">
                            <div class="text-muted small mb-1"><i class="fa fa-calendar-check me-1"></i>Delivery Date</div>
                            <div class="fw-bold text-success" style="font-size:18px">
                                <?= $dlvDate ? fmtDate(substr($dlvDate,0,10),'d M Y') : '—' ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="rounded-3 p-3 text-center" style="background:#f0fdf4;border:1px solid #bbf7d0">
                            <div class="text-muted small mb-1">Deposit Paid</div>
                            <div class="fw-bold text-success" style="font-size:18px">
                                <?= money((float)($lead['deposit_amount'] ?? 0)) ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="border-top pt-3 mt-2 d-flex gap-2 flex-wrap align-items-center">
                    <a href="delivery_note.php?lead_id=<?= $id ?>" target="_blank"
                       class="btn btn-success btn-sm">
                        <i class="fa fa-truck me-1"></i>Print Delivery Note
                    </a>
                    <a href="sales_receipt.php?lead_id=<?= $id ?>" target="_blank"
                       class="btn btn-info btn-sm text-white">
                        <i class="fa fa-file-invoice-dollar me-1"></i>Sales Receipt
                    </a>
                    <a href="proforma.php?lead_id=<?= $id ?>" target="_blank"
                       class="btn btn-outline-primary btn-sm">
                        <i class="fa fa-file-invoice me-1"></i>Proforma Invoice
                    </a>
                    <a href="sales_agreement.php?lead_id=<?= $id ?>" target="_blank"
                       class="btn btn-outline-secondary btn-sm">
                        <i class="fa fa-file-signature me-1"></i>Sales Agreement
                    </a>
                    <button type="button" class="btn btn-outline-success btn-sm ms-auto"
                            data-bs-toggle="modal" data-bs-target="#deliverModal">
                        <i class="fa fa-pen me-1"></i>Update Delivery
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Activity Timeline -->
        <div class="card">
            <div class="card-header fw-semibold">
                <i class="fa fa-clock-rotate-left me-2 text-primary"></i>Activity Timeline
                <span class="badge bg-secondary ms-1"><?= count($activities) ?></span>
            </div>
            <?php if (empty($activities)): ?>
            <div class="card-body text-center py-4 text-muted">
                <i class="fa fa-clipboard fa-2x mb-2 d-block opacity-25"></i>No activities logged yet.
            </div>
            <?php else: ?>
            <div class="card-body p-0">
                <div class="timeline ps-3 pe-3 pt-3">
                <?php foreach ($activities as $act):
                    [$aLabel, $aIcon, $aClass] = $activityTypes[$act['type']] ?? ['Note','fa-note-sticky','text-secondary'];
                ?>
                <div class="d-flex gap-3 mb-3">
                    <div class="flex-shrink-0" style="width:32px;height:32px;border-radius:50%;background:#f1f5f9;display:flex;align-items:center;justify-content:center">
                        <i class="fa <?= $aIcon ?> <?= $aClass ?>"></i>
                    </div>
                    <div class="flex-grow-1 border-bottom pb-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span class="badge bg-light text-dark border me-1" style="font-size:10px"><?= $aLabel ?></span>
                                <span class="fw-medium" style="font-size:13.5px"><?= e($act['summary']) ?></span>
                            </div>
                            <span class="text-muted small flex-shrink-0 ms-2"><?= fmtDate($act['created_at'],'d M Y, H:i') ?></span>
                        </div>
                        <?php if ($act['outcome']): ?>
                        <div class="text-muted mt-1" style="font-size:12.5px"><?= nl2br(e($act['outcome'])) ?></div>
                        <?php endif; ?>
                        <?php if ($act['follow_up_date']): ?>
                        <div class="mt-1">
                            <span class="badge bg-warning text-dark" style="font-size:10px">
                                <i class="fa fa-calendar me-1"></i>Follow-up: <?= fmtDate($act['follow_up_date'],'d M Y') ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        <div class="text-muted mt-1" style="font-size:11px">
                            By <?= e($act['by_name'] ?? 'System') ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
// Pre-compute prices for the modal
$_modalListPrice    = 0;
$_modalAskingPrice  = 0;
$_modalIsOffer      = false;
$_modalAgreedPrice  = (float)($lead['agreed_sale_price'] ?? 0);
if ($lead['pinned_car_id'] && !empty($availCarsForModal)) {
    foreach ($availCarsForModal as $_rc) {
        if ($_rc['id'] == $lead['pinned_car_id']) {
            $_modalIsOffer    = !empty($_rc['offer_price']) && (float)$_rc['offer_price'] > 0;
            $_modalAskingPrice= (float)$_rc['asking_price'];
            $_modalListPrice  = $_modalIsOffer ? (float)$_rc['offer_price'] : $_modalAskingPrice;
            break;
        }
    }
}
$_modalEffectiveSale = $_modalAgreedPrice > 0 ? $_modalAgreedPrice : $_modalListPrice;
$_modalDiscount      = ($_modalListPrice > 0 && $_modalAgreedPrice > 0 && $_modalAgreedPrice < $_modalListPrice)
    ? ($_modalListPrice - $_modalAgreedPrice) : 0;
$_modalBalance       = max(0, $_modalEffectiveSale - (float)($lead['deposit_amount'] ?? 0));
$_modalDiscPct       = ($_modalListPrice > 0 && $_modalDiscount > 0)
    ? number_format(($_modalDiscount / $_modalListPrice) * 100, 1) : 0;
?>
<!-- Reserve Vehicle Modal -->
<div class="modal fade" id="reserveModal" tabindex="-1" aria-labelledby="reserveModalLabel">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#1e3a8a,#2563eb);color:#fff">
                <h6 class="modal-title fw-bold" id="reserveModalLabel">
                    <i class="fa fa-bookmark me-2"></i>Reserve Vehicle — <?= e($lead['name']) ?>
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="reserve_lead">
                <div class="modal-body">
                    <div class="row g-3">

                        <!-- Vehicle selection -->
                        <div class="col-12">
                            <label class="form-label fw-semibold">
                                <i class="fa fa-car me-1 text-primary"></i>Select Vehicle to Reserve
                                <span class="text-danger">*</span>
                            </label>
                            <select name="reserve_car_id" id="reserveCarSelect" class="form-select" required>
                                <option value="">— Choose vehicle from inventory —</option>
                                <?php foreach ($availCarsForModal as $rc):
                                    $rcLabel    = trim(($rc['year'] ?? '') . ' ' . ($rc['make'] ?? '') . ' ' . ($rc['model'] ?? ''));
                                    if ($rc['registration_number']) $rcLabel .= ' (' . $rc['registration_number'] . ')';
                                    $rcIsOffer  = !empty($rc['offer_price']) && (float)$rc['offer_price'] > 0;
                                    $rcListP    = $rcIsOffer ? (float)$rc['offer_price'] : (float)$rc['asking_price'];
                                    $rcAskingP  = (float)$rc['asking_price'];
                                    $isPinned   = ($lead['pinned_car_id'] == $rc['id']);
                                ?>
                                <option value="<?= $rc['id'] ?>"
                                        data-list-price="<?= $rcListP ?>"
                                        data-asking-price="<?= $rcAskingP ?>"
                                        data-is-offer="<?= $rcIsOffer ? '1' : '0' ?>"
                                        data-label="<?= e($rcLabel) ?>"
                                        <?= $isPinned ? 'selected' : '' ?>>
                                    <?= e($rcLabel) ?>
                                    <?php if ($rcListP > 0): ?>
                                    — KES <?= number_format($rcListP) ?><?= $rcIsOffer ? ' [OFFER]' : '' ?>
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($availCarsForModal)): ?>
                            <div class="form-text text-warning">
                                <i class="fa fa-triangle-exclamation me-1"></i>
                                No inventory vehicles found. Add vehicles under All Cars first.
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Divider -->
                        <div class="col-12"><hr class="my-1"><small class="text-muted fw-semibold text-uppercase" style="letter-spacing:.06em">Pricing</small></div>

                        <!-- List Price (read-only, from inventory) -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">
                                List Price
                                <span id="offerBadge" class="badge bg-danger ms-1" style="font-size:10px;<?= $_modalIsOffer ? '' : 'display:none' ?>">OFFER</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text text-muted small">KES</span>
                                <input type="text" id="reserveListPrice" class="form-control bg-light"
                                       readonly placeholder="Select vehicle"
                                       data-raw="<?= $_modalListPrice ?>"
                                       value="<?= $_modalListPrice > 0 ? number_format($_modalListPrice) : '' ?>">
                            </div>
                            <div class="form-text" id="askingPriceNote" style="<?= ($_modalIsOffer && $_modalAskingPrice > 0) ? '' : 'display:none' ?>">
                                <s class="text-muted">KES <?= number_format($_modalAskingPrice) ?></s>
                                <span class="text-danger ms-1">Offer price active</span>
                            </div>
                        </div>

                        <!-- Agreed Sale Price (editable) -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">
                                Agreed Sale Price
                                <span class="text-muted fw-normal small">(client's negotiated price)</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text text-muted small">KES</span>
                                <input type="number" name="agreed_sale_price" id="reserveSalePrice"
                                       class="form-control" min="0" step="any"
                                       value="<?= $_modalAgreedPrice > 0 ? $_modalAgreedPrice : ($_modalListPrice > 0 ? $_modalListPrice : '') ?>"
                                       placeholder="Enter agreed price">
                            </div>
                            <div class="form-text text-muted">Leave blank to use list price.</div>
                        </div>

                        <!-- Discount (computed) -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Discount Given</label>
                            <input type="text" id="reserveDiscount" class="form-control bg-light fw-semibold"
                                   readonly placeholder="Auto-calculated"
                                   value="<?= $_modalDiscount > 0 ? 'KES ' . number_format($_modalDiscount) . ' (' . $_modalDiscPct . '%)' : '—' ?>"
                                   style="color:<?= $_modalDiscount > 0 ? '#16a34a' : '' ?>">
                        </div>

                        <!-- Divider -->
                        <div class="col-12"><hr class="my-1"><small class="text-muted fw-semibold text-uppercase" style="letter-spacing:.06em">Payment</small></div>

                        <!-- Deposit amount -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                Deposit Amount <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text text-muted small">KES</span>
                                <input type="number" name="deposit_amount" id="reserveDepositAmt"
                                       class="form-control" min="0" step="any" required
                                       value="<?= (float)($lead['deposit_amount'] ?? 0) ?: '' ?>"
                                       placeholder="e.g. 200,000">
                            </div>
                        </div>

                        <!-- Balance (computed from Sale Price - Deposit) -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Balance Due</label>
                            <div class="input-group">
                                <span class="input-group-text text-muted small">KES</span>
                                <input type="text" id="reserveBalance" class="form-control bg-light fw-bold"
                                       readonly placeholder="Auto-calculated"
                                       value="<?= $_modalBalance > 0 ? number_format($_modalBalance) : '' ?>"
                                       style="color:#c2410c">
                            </div>
                            <div class="form-text text-muted">Agreed price minus deposit.</div>
                        </div>

                        <!-- Balance Due Date (manual entry) -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">
                                Balance Due Date
                                <span class="text-muted fw-normal small">(deadline for full payment)</span>
                            </label>
                            <input type="date" name="due_date" class="form-control"
                                   value="<?= e($lead['due_date'] ?? '') ?>">
                        </div>

                        <!-- Sales Agreement Received Date (manual entry) -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">
                                Agreement Received
                                <span class="text-muted fw-normal small">(signed copy received)</span>
                            </label>
                            <input type="date" name="agreement_received_date" class="form-control"
                                   value="<?= e($lead['agreement_received_date'] ?? '') ?>">
                        </div>

                        <!-- Notes -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Deposit Notes <span class="text-muted fw-normal small">(optional)</span></label>
                            <input type="text" name="deposit_notes" class="form-control"
                                   placeholder="e.g. Cash deposit received, M-Pesa ref #…"
                                   value="<?= e($lead['deposit_notes'] ?? '') ?>">
                        </div>

                        <!-- Info banner -->
                        <div class="col-12">
                            <div class="d-flex align-items-start gap-2 p-3 rounded-3"
                                 style="background:#eff6ff;border:1px solid #bfdbfe;font-size:13px">
                                <i class="fa fa-circle-info text-primary mt-1 flex-shrink-0"></i>
                                <div>
                                    Saving this will mark the lead as <strong>Reserved</strong> and generate a
                                    <strong>Proforma Invoice</strong> and <strong>Sales Agreement</strong> based on the agreed sale price.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fa fa-bookmark me-1"></i>Confirm Reservation &amp; Generate Documents
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    var sel        = document.getElementById('reserveCarSelect');
    var listEl     = document.getElementById('reserveListPrice');
    var saleEl     = document.getElementById('reserveSalePrice');
    var discEl     = document.getElementById('reserveDiscount');
    var depEl      = document.getElementById('reserveDepositAmt');
    var balEl      = document.getElementById('reserveBalance');
    var offerBadge = document.getElementById('offerBadge');
    var askNote    = document.getElementById('askingPriceNote');
    if (!sel) return;

    function fmt(n) {
        return n > 0 ? n.toLocaleString('en-KE', {minimumFractionDigits:0, maximumFractionDigits:0}) : '';
    }

    function recompute() {
        var list = parseFloat(listEl.dataset.raw || 0) || 0;
        var sale = parseFloat(saleEl.value)           || 0;
        var dep  = parseFloat(depEl.value)            || 0;

        // Effective sale = agreed price if entered, else fall back to list price
        var effective = sale > 0 ? sale : list;

        // Discount
        var disc    = (list > 0 && sale > 0 && sale < list) ? (list - sale) : 0;
        var discPct = (list > 0 && disc > 0) ? ((disc / list) * 100).toFixed(1) : 0;
        if (disc > 0) {
            discEl.value = 'KES ' + fmt(disc) + ' (' + discPct + '%)';
            discEl.style.color = '#16a34a';
        } else {
            discEl.value = '—';
            discEl.style.color = '';
        }

        // Balance = effective sale price - deposit
        var bal = Math.max(0, effective - dep);
        balEl.value = fmt(bal);
        balEl.style.color = bal > 0 ? '#c2410c' : '#16a34a';
    }

    sel.addEventListener('change', function () {
        var opt      = sel.options[sel.selectedIndex];
        var listP    = parseFloat(opt.dataset.listPrice   || 0);
        var askingP  = parseFloat(opt.dataset.askingPrice || 0);
        var isOffer  = opt.dataset.isOffer === '1';

        listEl.dataset.raw = listP;
        listEl.value       = listP > 0 ? fmt(listP) : '';

        // Offer badge
        if (offerBadge) offerBadge.style.display = isOffer ? '' : 'none';
        if (askNote) {
            if (isOffer && askingP > 0 && askingP !== listP) {
                askNote.innerHTML = '<s class="text-muted">KES ' + fmt(askingP) + '</s> <span class="text-danger ms-1">Offer price active</span>';
                askNote.style.display = '';
            } else {
                askNote.style.display = 'none';
            }
        }

        // Auto-fill sale price with list price so balance calculates immediately
        if (!saleEl.value || parseFloat(saleEl.value) === 0) {
            saleEl.value = listP > 0 ? listP : '';
        }

        recompute();
    });

    saleEl.addEventListener('input', recompute);
    depEl.addEventListener('input',  recompute);
    recompute(); // init on page load
}());
</script>

<!-- Import Order Modal -->
<div class="modal fade" id="importOrderModal" tabindex="-1" aria-labelledby="importOrderModalLabel">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#78350f,#d97706);color:#fff">
                <h6 class="modal-title fw-bold" id="importOrderModalLabel">
                    <i class="fa fa-ship me-2"></i>Import Order — <?= e($lead['name']) ?>
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="import_order_lead">
                <div class="modal-body">
                    <div class="row g-3">

                        <!-- Vehicle Description -->
                        <div class="col-12">
                            <label class="form-label fw-semibold">
                                <i class="fa fa-car me-1 text-warning"></i>Vehicle Details
                                <span class="text-danger">*</span>
                            </label>
                            <textarea name="import_vehicle_details" id="ioVehicleDetails"
                                      class="form-control" rows="2" required
                                      placeholder="e.g. 2024 Toyota Land Cruiser 300 GR Sport, White, Petrol, 3.5L Twin Turbo"><?= e($lead['import_vehicle_details'] ?? '') ?></textarea>
                            <div class="form-text text-muted">Describe the vehicle being ordered — make, model, year, specs, colour.</div>
                        </div>

                        <!-- Divider -->
                        <div class="col-12"><hr class="my-1"><small class="text-muted fw-semibold text-uppercase" style="letter-spacing:.06em">Pricing</small></div>

                        <!-- Agreed Sale Price -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                Agreed Sale Price <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text text-muted small">KES</span>
                                <input type="number" name="agreed_sale_price" id="ioSalePrice"
                                       class="form-control" min="0" step="any" required
                                       value="<?= (float)($lead['agreed_sale_price'] ?? 0) ?: '' ?>"
                                       placeholder="e.g. 8,500,000">
                            </div>
                        </div>

                        <!-- Deposit Amount -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                Deposit Amount <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text text-muted small">KES</span>
                                <input type="number" name="deposit_amount" id="ioDepositAmt"
                                       class="form-control" min="0" step="any" required
                                       value="<?= (float)($lead['deposit_amount'] ?? 0) ?: '' ?>"
                                       placeholder="e.g. 500,000">
                            </div>
                        </div>

                        <!-- Balance (computed) -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Balance Due</label>
                            <div class="input-group">
                                <span class="input-group-text text-muted small">KES</span>
                                <input type="text" id="ioBalance" class="form-control bg-light fw-bold"
                                       readonly placeholder="Auto-calculated" style="color:#c2410c">
                            </div>
                            <div class="form-text text-muted">Agreed price minus deposit.</div>
                        </div>

                        <!-- Balance Due Date -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                Balance Due Date
                                <span class="text-muted fw-normal small">(deadline for full payment)</span>
                            </label>
                            <input type="date" name="due_date" class="form-control"
                                   value="<?= e($lead['due_date'] ?? '') ?>">
                        </div>

                        <!-- Divider -->
                        <div class="col-12"><hr class="my-1"><small class="text-muted fw-semibold text-uppercase" style="letter-spacing:.06em">Import Details</small></div>

                        <!-- Expected Arrival Date -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                <i class="fa fa-calendar-check me-1 text-warning"></i>Expected Arrival Date
                                <span class="text-danger">*</span>
                            </label>
                            <input type="date" name="expected_arrival_date" class="form-control" required
                                   value="<?= e($lead['expected_arrival_date'] ?? '') ?>">
                            <div class="form-text text-muted">Estimated date the vehicle arrives in the country.</div>
                        </div>

                        <!-- Deposit Notes -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Payment Notes <span class="text-muted fw-normal small">(optional)</span></label>
                            <input type="text" name="deposit_notes" class="form-control"
                                   placeholder="e.g. M-Pesa ref #, bank transfer ref…"
                                   value="<?= e($lead['deposit_notes'] ?? '') ?>">
                        </div>

                        <!-- Info banner -->
                        <div class="col-12">
                            <div class="d-flex align-items-start gap-2 p-3 rounded-3"
                                 style="background:#fffbeb;border:1px solid #fde68a;font-size:13px">
                                <i class="fa fa-circle-info text-warning mt-1 flex-shrink-0"></i>
                                <div>
                                    Saving this will mark the lead as <strong>Import Order</strong> and
                                    make the deposit receipt, proforma, and sales agreement available
                                    with the vehicle and pricing details entered above.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fa fa-ship me-1"></i>Save Import Order
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    var saleEl = document.getElementById('ioSalePrice');
    var depEl  = document.getElementById('ioDepositAmt');
    var balEl  = document.getElementById('ioBalance');
    if (!saleEl || !depEl || !balEl) return;

    function fmt(n) {
        return n > 0 ? n.toLocaleString('en-KE', {minimumFractionDigits:0, maximumFractionDigits:0}) : '';
    }

    function recompute() {
        var sale = parseFloat(saleEl.value) || 0;
        var dep  = parseFloat(depEl.value)  || 0;
        var bal  = Math.max(0, sale - dep);
        balEl.value = fmt(bal);
        balEl.style.color = bal > 0 ? '#c2410c' : '#16a34a';
    }

    saleEl.addEventListener('input', recompute);
    depEl.addEventListener('input',  recompute);
    recompute();
}());
</script>

<!-- Deliver Modal -->
<div class="modal fade" id="deliverModal" tabindex="-1" aria-labelledby="deliverModalLabel">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#14532d,#16a34a);color:#fff">
                <h6 class="modal-title fw-bold" id="deliverModalLabel">
                    <i class="fa fa-truck me-2"></i>Confirm Vehicle Delivery — <?= e($lead['name']) ?>
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="deliver_lead">
                <div class="modal-body">
                    <?php
                    $dlvModalCar = $pinnedCar
                        ? trim(($pinnedCar['year']??'').' '.($pinnedCar['make']??'').' '.($pinnedCar['model']??''))
                        : ($lead['import_vehicle_details'] ?? ($lead['interested_in'] ?? ''));
                    ?>
                    <?php if ($dlvModalCar): ?>
                    <div class="mb-3 p-3 rounded-3 fw-semibold" style="background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;font-size:14px">
                        <i class="fa fa-car me-2"></i><?= e($dlvModalCar) ?>
                    </div>
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">
                                <i class="fa fa-calendar-check me-1 text-success"></i>Delivery Date
                                <span class="text-danger">*</span>
                            </label>
                            <input type="date" name="delivery_date" class="form-control" required
                                   value="<?= e($lead['delivered_at'] ? substr($lead['delivered_at'],0,10) : date('Y-m-d')) ?>">
                            <div class="form-text text-muted">Date the vehicle is physically handed over to the buyer.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Delivery Notes <span class="text-muted fw-normal small">(optional)</span></label>
                            <input type="text" name="delivery_notes" class="form-control"
                                   placeholder="e.g. All keys handed over, full tank, spare tyre included…">
                        </div>
                        <div class="col-12">
                            <div class="d-flex align-items-start gap-2 p-3 rounded-3"
                                 style="background:#fef9c3;border:1px solid #fde047;font-size:13px">
                                <i class="fa fa-triangle-exclamation text-warning mt-1 flex-shrink-0"></i>
                                <div>
                                    Confirming delivery will <strong>remove this vehicle from active inventory</strong>
                                    and the website. A printable <strong>Delivery Note</strong> will be available
                                    immediately after saving.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fa fa-truck me-1"></i>Confirm Delivery &amp; Generate Note
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Lost Reason Modal -->
<div class="modal fade" id="lostModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2 border-0">
                <h6 class="modal-title fw-bold"><i class="fa fa-circle-xmark me-2 text-danger"></i>Mark as Lost</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-2">
                <label class="form-label small fw-semibold">Reason (optional)</label>
                <input type="text" id="lostReasonField" class="form-control form-control-sm"
                       placeholder="e.g. Price too high, bought elsewhere…"
                       value="<?= e($lead['lost_reason'] ?? '') ?>">
                <div class="form-text">This will be recorded on the lead for analysis.</div>
            </div>
            <div class="modal-footer py-2 border-0">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-danger" id="confirmLost">
                    <i class="fa fa-circle-xmark me-1"></i>Mark Lost
                </button>
            </div>
        </div>
    </div>
</div>
<script>
document.getElementById('confirmLost') && document.getElementById('confirmLost').addEventListener('click', function () {
    document.getElementById('stageInput').value    = 'lost';
    document.getElementById('lostReasonInput').value = document.getElementById('lostReasonField').value;
    document.getElementById('stageForm').submit();
});
</script>

<!-- Schedule Test Drive Modal -->
<div class="modal fade" id="scheduleTdModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title"><i class="fa fa-car-side me-2 text-primary"></i>Schedule Test Drive — <?= e($lead['name']) ?></h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="schedule_test_drive">
        <div class="modal-body">
          <div class="row g-3">

            <!-- ── Vehicle ─────────────────────────────────────────── -->
            <div class="col-12">
              <label class="form-label fw-semibold">Vehicle <span class="text-danger">*</span></label>
              <select name="td_car_id" id="tdCarSelect" class="form-select form-select-sm" required>
                <option value="">— Select from inventory —</option>
                <?php
                try {
                    $availCars = $db->query("
                        SELECT id, make, model, year, color, registration_number,
                               chassis_number, COALESCE(entry_number,'') AS entry_number
                        FROM cars WHERE status IN ('completed','arrived') ORDER BY make, model LIMIT 200
                    ")->fetchAll();
                    foreach ($availCars as $ac):
                        $sel = ($lead['pinned_car_id'] == $ac['id']) ? 'selected' : '';
                        $label = e(trim($ac['make'].' '.$ac['model'].' '.($ac['year']??'')) . ($ac['registration_number'] ? ' ('.$ac['registration_number'].')' : ''));
                ?>
                <option value="<?= $ac['id'] ?>" <?= $sel ?>
                        data-chassis="<?= e($ac['chassis_number'] ?? '') ?>"
                        data-entry="<?= e($ac['entry_number'] ?? '') ?>"
                        data-reg="<?= e($ac['registration_number'] ?? '') ?>">
                  <?= $label ?>
                </option>
                <?php endforeach; } catch (\Throwable $_) {} ?>
              </select>
            </div>

            <!-- ── Auto-filled vehicle details ────────────────────── -->
            <div class="col-md-6">
              <label class="form-label fw-semibold">Chassis Number</label>
              <input type="text" name="td_chassis_number" id="tdChassisNum"
                     class="form-control form-control-sm" readonly
                     placeholder="Auto-filled from vehicle">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Entry Number</label>
              <input type="text" name="td_entry_number" id="tdEntryNum"
                     class="form-control form-control-sm" readonly
                     placeholder="Auto-filled from vehicle">
            </div>

            <!-- ── Date / Time / Duration ──────────────────────────── -->
            <div class="col-md-4">
              <label class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
              <input type="date" name="td_date" class="form-control form-control-sm"
                     min="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Time <span class="text-danger">*</span></label>
              <input type="time" name="td_time" class="form-control form-control-sm" required value="10:00">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Duration</label>
              <select name="td_duration" class="form-select form-select-sm">
                <option value="30">30 minutes</option>
                <option value="60" selected>1 hour</option>
                <option value="90">1.5 hours</option>
                <option value="120">2 hours</option>
              </select>
            </div>

            <!-- ── Driver details ──────────────────────────────────── -->
            <div class="col-12"><hr class="my-0"><small class="text-muted fw-semibold text-uppercase" style="font-size:10px;letter-spacing:.06em">Driver Details</small></div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Driver National ID No <span class="text-danger">*</span></label>
              <input type="text" name="td_driver_id_no" class="form-control form-control-sm"
                     placeholder="e.g. 12345678" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">KD Number (Driver's Licence) <span class="text-danger">*</span></label>
              <input type="text" name="td_kd_number" class="form-control form-control-sm"
                     placeholder="e.g. KD12345678A" required>
            </div>

            <!-- ── Notes ───────────────────────────────────────────── -->
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="td_notes" class="form-control form-control-sm" rows="2"
                        placeholder="Any special notes for the test drive…"></textarea>
            </div>

          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm">
            <i class="fa fa-calendar-check me-1"></i>Schedule &amp; Save
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Auto-fill chassis + entry number when vehicle is selected
(function () {
    var sel     = document.getElementById('tdCarSelect');
    var chassis = document.getElementById('tdChassisNum');
    var entry   = document.getElementById('tdEntryNum');
    if (!sel) return;
    function fill() {
        var opt = sel.options[sel.selectedIndex];
        chassis.value = opt ? (opt.dataset.chassis || '') : '';
        entry.value   = opt ? (opt.dataset.entry   || '') : '';
    }
    sel.addEventListener('change', fill);
    fill(); // fill on load if car pre-selected
}());
</script>

<!-- WhatsApp Templates Modal -->
<?php if ($lead['phone']): ?>
<?php
// Load templates
$waTemplates = [];
try {
    $waTemplates = $db->query("SELECT * FROM crm_wa_templates WHERE is_active=1 ORDER BY sort_order, category, name")->fetchAll();
} catch (\Throwable $_) {}
$waNum = preg_replace('/[^0-9]/', '', $lead['phone']);
if (str_starts_with($lead['phone'], '0')) $waNum = '254' . substr($waNum, 1);
$agentNameWa = $me['name'] ?? '';
$companyWa = getSetting('company_name', 'us');
?>
<div class="modal fade" id="waTemplateModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h6 class="modal-title"><i class="fab fa-whatsapp me-2"></i>WhatsApp — <?= e($lead['name']) ?></h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?php if ($waTemplates): ?>
        <div class="mb-3">
          <label class="form-label fw-semibold small">Select Template:</label>
          <div class="row g-2">
            <?php foreach ($waTemplates as $tpl): ?>
            <div class="col-md-6">
              <button type="button" class="btn btn-outline-secondary btn-sm w-100 text-start wa-tpl-btn"
                      style="white-space:normal;font-size:12px"
                      data-body="<?= e($tpl['body']) ?>">
                <span class="badge bg-secondary me-1" style="font-size:9px"><?= e(str_replace('_',' ',ucfirst($tpl['category']))) ?></span>
                <?= e($tpl['name']) ?>
              </button>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
        <div class="mb-2">
          <label class="form-label fw-semibold small">Message:</label>
          <textarea id="waMessageBox" class="form-control" rows="5"
                    placeholder="Type your message or select a template above…"><?php
            // Default pre-filled message
            $defMsg = "Hello {$lead['name']}!";
            if ($lead['interested_in']) $defMsg .= " Following up on your interest in the {$lead['interested_in']}.";
            $defMsg .= " — {$agentNameWa}";
            echo e($defMsg);
          ?></textarea>
          <div class="d-flex justify-content-between mt-1">
            <span class="text-muted" style="font-size:11px">Placeholders are auto-filled with lead details</span>
            <span id="waCharCount" class="text-muted" style="font-size:11px">0 chars</span>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <a id="waOpenBtn" href="#" target="_blank" class="btn btn-success btn-sm">
          <i class="fab fa-whatsapp me-1"></i>Open in WhatsApp
        </a>
      </div>
    </div>
  </div>
</div>
<script>
(function(){
  var box   = document.getElementById('waMessageBox');
  var btn   = document.getElementById('waOpenBtn');
  var count = document.getElementById('waCharCount');
  var waNum = '<?= $waNum ?>';
  var replacements = {
    '{name}'   : '<?= addslashes($lead['name']) ?>',
    '{agent}'  : '<?= addslashes($agentNameWa) ?>',
    '{company}': '<?= addslashes($companyWa) ?>',
    '{car}'    : '<?= addslashes($lead['interested_in'] ?? '') ?>',
    '{price}'  : '<?= $lead['budget'] ? 'KES ' . number_format((float)$lead['budget']) : '' ?>',
    '{date}'   : '<?= date('d M Y') ?>',
  };

  function fillPlaceholders(text) {
    Object.keys(replacements).forEach(function(k){ text = text.split(k).join(replacements[k]); });
    return text;
  }

  function updateBtn() {
    var msg = fillPlaceholders(box.value);
    btn.href = 'https://wa.me/' + waNum + '?text=' + encodeURIComponent(msg);
    count.textContent = msg.length + ' chars';
  }

  box.addEventListener('input', updateBtn);

  document.querySelectorAll('.wa-tpl-btn').forEach(function(b){
    b.addEventListener('click', function(){
      box.value = b.getAttribute('data-body');
      document.querySelectorAll('.wa-tpl-btn').forEach(function(x){ x.classList.remove('btn-success','text-white'); x.classList.add('btn-outline-secondary'); });
      b.classList.remove('btn-outline-secondary'); b.classList.add('btn-success','text-white');
      updateBtn();
    });
  });

  updateBtn();

  document.getElementById('waTemplateModal').addEventListener('show.bs.modal', function(){
    document.querySelectorAll('.wa-tpl-btn').forEach(function(x){ x.classList.remove('btn-success','text-white'); x.classList.add('btn-outline-secondary'); });
    updateBtn();
  });
}());
</script>
<?php endif; ?>

<script>
// Move modals to <body> so they escape the page-body animation compositing layer
document.addEventListener('DOMContentLoaded', function () {
    ['scheduleTdModal', 'waTemplateModal', 'lostModal', 'reserveModal', 'importOrderModal', 'deliverModal'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el && el.parentNode !== document.body) document.body.appendChild(el);
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
