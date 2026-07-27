<?php
/**
 * Notification helper functions.
 * All functions silently swallow exceptions so they never break the main request.
 */

function createNotification(int $userId, string $type, string $title, string $message = '', string $link = ''): void {
    try {
        getDB()->prepare(
            "INSERT INTO notifications (user_id, type, title, message, link) VALUES (?,?,?,?,?)"
        )->execute([$userId, $type, $title ?: 'Notification', $message, $link]);
    } catch (\Throwable $e) { error_log('notifications: createNotification: ' . $e->getMessage()); }
}

function notifyRoles(array $roles, string $type, string $title, string $message = '', string $link = ''): void {
    try {
        $db     = getDB();
        $ph     = implode(',', array_fill(0, count($roles), '?'));
        $stmt   = $db->prepare("SELECT id FROM users WHERE role IN ({$ph}) AND status='active'");
        $stmt->execute($roles);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $uid) {
            createNotification((int)$uid, $type, $title, $message, $link);
        }
    } catch (\Throwable $e) { error_log('notifications: notifyRoles: ' . $e->getMessage()); }
}

function getUnreadNotificationCount(int $userId): int {
    try {
        $s = getDB()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
        $s->execute([$userId]);
        return (int)$s->fetchColumn();
    } catch (\Throwable $e) { error_log('notifications: getUnreadNotificationCount: ' . $e->getMessage()); return 0; }
}

function getRecentNotifications(int $userId, int $limit = 20): array {
    try {
        $s = getDB()->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT ?");
        $s->execute([$userId, $limit]);
        return $s->fetchAll();
    } catch (\Throwable $e) { error_log('notifications: getRecentNotifications: ' . $e->getMessage()); return []; }
}

/**
 * CRM Delivery Protocol items where the ball is currently in the given role's
 * court — used to render an actionable dashboard banner, since the bell alone
 * gets missed. Mirrors the exact handoff points that call notifyRoles() inside
 * modules/crm/view_lead.php's dp_action handler.
 *
 * $assignedToUserId scopes results to leads assigned to that user — required
 * for customer_relations, since agents can only open leads assigned to them
 * (view_lead.php redirects otherwise); pass 0 for roles with company-wide access.
 */
function getPendingDeliveryProtocolActions(string $role, int $assignedToUserId = 0): array {
    $items = [];
    try {
        $sql = "
            SELECT dp.*, l.id AS lead_id, l.name AS lead_name
            FROM crm_delivery_protocol dp
            JOIN crm_leads l ON l.id = dp.lead_id
            WHERE l.stage NOT IN ('lost','delivered')
        ";
        if ($assignedToUserId > 0) {
            $sql .= " AND l.assigned_to = " . (int)$assignedToUserId;
        }
        $rows = getDB()->query($sql)->fetchAll();
    } catch (\Throwable $e) { return []; }

    foreach ($rows as $r) {
        $link = BASE_URL . '/modules/crm/view_lead.php?id=' . $r['lead_id'] . '&dp_open=1';
        $add  = function (string $step, string $message) use (&$items, $r, $link) {
            $items[] = ['lead_id' => $r['lead_id'], 'lead_name' => $r['lead_name'], 'step' => $step, 'message' => $message, 'link' => $link];
        };

        if ($role === 'super_admin') {
            if (!empty($r['s1_moved_at']) && empty($r['s1_approved_at'])) {
                $add('B3 Reservation', '20% deposit confirmed — approve to reserve the vehicle.');
            }
            if (!empty($r['s3_requested_at']) && empty($r['s3_completed_at'])) {
                $add('Registration & Payment', 'Register the vehicle and confirm full payment.');
            }
            if (!empty($r['s6_requested_at']) && empty($r['s6_approved_at'])) {
                $add('Delivery Note', 'Confirm so Customer Relations can print the delivery note.');
            }
        }
        if ($role === 'supervisor' && !empty($r['s6_requested_at']) && empty($r['s6_approved_at'])) {
            $add('Delivery Note', 'Confirm so Customer Relations can print the delivery note.');
        }
        if ($role === 'sales_person') {
            if (!empty($r['s2_service_at']) && empty($r['s2_confirmed_at'])) {
                $add('Confirm Workshop Move', 'Confirm and move the reserved vehicle to the workshop.');
            }
            if (!empty($r['s4_requested_at']) && empty($r['s4_confirmed_at'])) {
                $add('Pre-Delivery Inspection', 'Confirm and carry out the PDI.');
            }
        }
        if ($role === 'workshop_manager' && !empty($r['s2_confirmed_at']) && empty($r['s2_workshop_done_at'])) {
            $add('Vehicle Incoming', 'Reserved vehicle confirmed for workshop service.');
        }
        if ($role === 'customer_relations') {
            if (!empty($r['s2_workshop_done_at']) && empty($r['s3_requested_at'])) {
                $add('Workshop Complete', 'Vehicle checked out — proceed to Registration & Payment.');
            }
            if (!empty($r['s4_completed_at']) && empty($r['s5_confirmed_at'])) {
                $add('PDI Completed', 'Proceed to the Delivery Experience step.');
            }
        }
    }
    return $items;
}
