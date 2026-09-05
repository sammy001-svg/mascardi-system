<?php
/**
 * Import orders — placing the actual import against a customer's order.
 *
 * An import ORDER is the customer's side: they have asked for a vehicle and paid
 * a deposit, and that lives on the lead. Placing the import is our side: which
 * supplier we bought from, what exactly is coming, and where it has got to.
 *
 * They are kept apart because they move at different speeds and are the
 * responsibility of different people. A customer relations agent takes the order;
 * somebody else places it with a supplier, and an admin has to agree before money
 * is committed abroad.
 */

if (!function_exists('importPlacementStages')) {

/** Where a placed import has got to, in the order it travels. */
function importPlacementStages(): array
{
    return [
        'not_dispatched'   => ['Not yet despatched', 'secondary', 'fa-warehouse'],
        'in_transit'       => ['In transit',         'info',      'fa-ship'],
        'in_mombasa'       => ['In Mombasa',         'primary',   'fa-anchor'],
        'arrived_nairobi'  => ['Arrived in Nairobi', 'success',   'fa-flag-checkered'],
    ];
}

/** Approval states. Nothing moves until an admin has agreed to it. */
function importPlacementApprovals(): array
{
    return [
        'pending'  => ['Awaiting approval', 'warning'],
        'approved' => ['Approved',          'success'],
        'rejected' => ['Rejected',          'danger'],
    ];
}

/** Who may approve a placed import, and who may move it along afterwards. */
function canApproveImportPlacement(): bool
{
    return isSuperAdmin() || hasRole(['admin', 'general_manager']);
}

/**
 * Creates the table once. Inline, like the rest of the system, so a deploy needs
 * no separate step.
 */
function importPlacementsEnsure(PDO $db): bool
{
    static $done = null;
    if ($done !== null) return $done;

    try {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS import_placements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                lead_id INT NOT NULL,
                vehicle_details TEXT NOT NULL,
                supplier VARCHAR(200) NULL,
                supplier_contact VARCHAR(150) NULL,
                purchase_price DECIMAL(15,2) NULL,
                expected_arrival DATE NULL,
                status ENUM('not_dispatched','in_transit','in_mombasa','arrived_nairobi')
                       NOT NULL DEFAULT 'not_dispatched',
                approval_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                approved_by VARCHAR(150) NULL,
                approved_at DATETIME NULL,
                rejection_reason TEXT NULL,
                placed_by VARCHAR(150) NULL,
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_ip_lead (lead_id),
                INDEX idx_ip_status (status),
                INDEX idx_ip_approval (approval_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        return $done = true;
    } catch (\Throwable $e) {
        error_log('importPlacementsEnsure: ' . $e->getMessage());
        return $done = false;
    }
}

/** A placement with its customer and the order it belongs to. */
function importPlacementRows(PDO $db, array $filters = []): array
{
    $where  = ['1=1'];
    $params = [];

    if (!empty($filters['status'])) {
        $where[] = 'p.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['approval'])) {
        $where[] = 'p.approval_status = ?';
        $params[] = $filters['approval'];
    }
    if (!empty($filters['agent_id'])) {
        $where[] = 'l.assigned_to = ?';
        $params[] = (int)$filters['agent_id'];
    }
    if (!empty($filters['q'])) {
        $s = '%' . $filters['q'] . '%';
        $where[] = '(l.name LIKE ? OR cl.name LIKE ? OR p.vehicle_details LIKE ? OR p.supplier LIKE ?)';
        array_push($params, $s, $s, $s, $s);
    }
    $sql = implode(' AND ', $where);

    try {
        // Dates compared in SQL: PHP runs UTC here and MySQL runs EAT, so working
        // "how many days late" out in PHP is three hours adrift.
        $st = $db->prepare("
            SELECT p.*,
                   DATEDIFF(p.expected_arrival, CURDATE()) AS days_away,
                   l.id    AS lead_id,
                   l.name  AS lead_name,
                   l.phone AS lead_phone,
                   l.email AS lead_email,
                   l.deposit_amount,
                   l.agreed_sale_price,
                   l.expected_arrival_date AS promised_to_client,
                   cl.name  AS client_name,
                   cl.phone AS client_phone,
                   u.name   AS agent_name
              FROM import_placements p
              JOIN crm_leads l  ON l.id  = p.lead_id
         LEFT JOIN clients   cl ON cl.id = l.client_id
         LEFT JOIN users     u  ON u.id  = l.assigned_to
             WHERE $sql
          ORDER BY FIELD(p.approval_status,'pending','approved','rejected'),
                   p.expected_arrival IS NULL, p.expected_arrival ASC, p.id DESC
        ");
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('importPlacementRows: ' . $e->getMessage());
        return [];
    }
}

/** Import orders that have no placement yet — what the Place Import form offers. */
function importOrdersAwaitingPlacement(PDO $db, ?int $agentId = null): array
{
    $extra = $agentId ? ' AND l.assigned_to = ' . (int)$agentId : '';
    try {
        return $db->query("
            SELECT l.id, l.name, l.phone, l.import_vehicle_details, l.expected_arrival_date,
                   cl.name AS client_name
              FROM crm_leads l
         LEFT JOIN clients cl ON cl.id = l.client_id
             WHERE l.stage = 'import_order'
               AND NOT EXISTS (
                   SELECT 1 FROM import_placements p
                    WHERE p.lead_id = l.id AND p.approval_status <> 'rejected'
               )
               $extra
          ORDER BY l.updated_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }
}

} // function_exists('importPlacementStages')
