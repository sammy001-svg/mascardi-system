<?php
/**
 * CSV Export endpoint for all report data.
 * Usage: export.php?type=invoices&period=this_month
 * Streams a UTF-8 BOM CSV file for direct Excel opening.
 */
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('reports') || die('Access denied.');

$type   = $_GET['type']   ?? '';
$period = $_GET['period'] ?? 'this_month';

switch ($period) {
    case 'last_month':
        $dateFrom = date('Y-m-01', strtotime('first day of last month'));
        $dateTo   = date('Y-m-t',  strtotime('last day of last month'));
        break;
    case 'last_3_months':
        $dateFrom = date('Y-m-01', strtotime('-2 months'));
        $dateTo   = date('Y-m-d');
        break;
    case 'this_year':
        $dateFrom = date('Y-01-01');
        $dateTo   = date('Y-12-31');
        break;
    case 'custom':
        $dateFrom = $_GET['date_from'] ?? date('Y-m-01');
        $dateTo   = $_GET['date_to']   ?? date('Y-m-d');
        break;
    default:
        $dateFrom = date('Y-m-01');
        $dateTo   = date('Y-m-d');
}

$db = getDB();

// ── Data queries per type ─────────────────────────────────────────────────────
$rows    = [];
$headers = [];
$filename = 'export';

switch ($type) {

    case 'invoices':
        $filename = 'invoices_' . $dateFrom . '_to_' . $dateTo;
        $headers  = ['Invoice #','Vehicle','Chassis','Customer','Date','Due Date','Status','Amount (KES)','Paid (KES)','Balance (KES)'];
        $stmt = $db->prepare("
            SELECT i.invoice_number, CONCAT(c.make,' ',c.model) AS vehicle,
                   c.chassis_number, i.customer_name, i.created_at, i.due_date,
                   i.status, i.total, i.amount_paid, (i.total - i.amount_paid) AS balance
            FROM invoices i JOIN cars c ON c.id = i.car_id
            WHERE DATE(i.created_at) BETWEEN ? AND ?
            ORDER BY i.created_at DESC
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        break;

    case 'profit':
        $filename = 'vehicle_profit_' . $dateFrom . '_to_' . $dateTo;
        $headers  = ['Sale #','Vehicle','Year','Chassis','Buyer','Sale Date','COGS (KES)','Sale Price (KES)','Gross Profit (KES)','Margin %'];
        try {
            $stmt = $db->prepare("
                SELECT cs.sale_number, CONCAT(c.make,' ',c.model), c.year, c.chassis_number,
                       cs.buyer_name, cs.sale_date,
                       ROUND(cc.purchase_price+cc.freight+cc.marine_insurance+cc.port_charges
                           +cc.duty_tax+cc.clearing_fees+cc.transport_to_yard
                           +cc.workshop_costs+cc.other_costs, 2) AS cogs,
                       cs.sale_price,
                       ROUND(cs.sale_price-(cc.purchase_price+cc.freight+cc.marine_insurance+cc.port_charges
                           +cc.duty_tax+cc.clearing_fees+cc.transport_to_yard
                           +cc.workshop_costs+cc.other_costs), 2) AS profit,
                       CASE WHEN cs.sale_price > 0
                           THEN ROUND((cs.sale_price-(cc.purchase_price+cc.freight+cc.marine_insurance+cc.port_charges
                               +cc.duty_tax+cc.clearing_fees+cc.transport_to_yard
                               +cc.workshop_costs+cc.other_costs))/cs.sale_price*100,1)
                           ELSE 0 END AS margin_pct
                FROM car_sales cs JOIN cars c ON c.id=cs.car_id JOIN car_costs cc ON cc.car_id=cs.car_id
                WHERE cs.status='active' AND DATE(cs.sale_date) BETWEEN ? AND ?
                ORDER BY cs.sale_date DESC
            ");
            $stmt->execute([$dateFrom, $dateTo]);
            $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        } catch (\Throwable $e) { $rows = []; }
        break;

    case 'expenses':
        $filename = 'expenses_' . $dateFrom . '_to_' . $dateTo;
        $headers  = ['Date','Category','Description','Amount (KES)','Recorded By'];
        try {
            $stmt = $db->prepare("
                SELECT e.expense_date, e.category, e.description, e.amount, u.name AS recorded_by
                FROM expenses e LEFT JOIN users u ON u.id = e.created_by
                WHERE DATE(e.expense_date) BETWEEN ? AND ?
                ORDER BY e.expense_date DESC
            ");
            $stmt->execute([$dateFrom, $dateTo]);
            $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        } catch (\Throwable $e) { $rows = []; }
        break;

    case 'mechanics':
        $filename = 'mechanic_performance_' . $dateFrom . '_to_' . $dateTo;
        $headers  = ['Mechanic','Specialization','Total Jobs','Completed','Active','Cancelled','Avg Days','On-Time %','Urgent Jobs'];
        $stmt = $db->prepare("
            SELECT m.name, m.specialization,
                   COUNT(j.id), SUM(j.status='completed'),
                   SUM(j.status IN ('in_progress','pending')),
                   SUM(j.status='cancelled'),
                   ROUND(AVG(CASE WHEN j.status='completed' THEN DATEDIFF(j.updated_at,j.created_at) END),1),
                   ROUND(SUM(CASE WHEN j.status='completed' AND (j.end_date IS NULL OR j.updated_at<=j.end_date) THEN 1 ELSE 0 END)
                       /NULLIF(SUM(j.status='completed'),0)*100,1),
                   SUM(CASE WHEN j.priority='urgent' THEN 1 ELSE 0 END)
            FROM mechanics m JOIN workshop_jobs j ON j.mechanic_id=m.id
            WHERE DATE(j.created_at) BETWEEN ? AND ?
            GROUP BY m.id, m.name, m.specialization ORDER BY 4 DESC
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        break;

    case 'sales_reps':
        $filename = 'sales_rep_performance_' . $dateFrom . '_to_' . $dateTo;
        $headers  = ['Sales Person','Role','Cars Sold','Total Revenue (KES)','Avg Sale Price (KES)','Avg Margin %'];
        try {
            $stmt = $db->prepare("
                SELECT u.name, u.role,
                       COUNT(cs.id),
                       ROUND(COALESCE(SUM(cs.sale_price),0),2),
                       ROUND(COALESCE(AVG(cs.sale_price),0),2),
                       ROUND(COALESCE(AVG(CASE WHEN cc.car_id IS NOT NULL AND cs.sale_price>0
                           THEN (cs.sale_price-(cc.purchase_price+cc.freight+cc.marine_insurance
                               +cc.port_charges+cc.duty_tax+cc.clearing_fees+cc.transport_to_yard
                               +cc.workshop_costs+cc.other_costs))/cs.sale_price*100 END),0),1)
                FROM car_sales cs JOIN users u ON u.id=cs.sold_by
                LEFT JOIN car_costs cc ON cc.car_id=cs.car_id
                WHERE cs.status='active' AND DATE(cs.sale_date) BETWEEN ? AND ?
                GROUP BY u.id, u.name, u.role ORDER BY 4 DESC
            ");
            $stmt->execute([$dateFrom, $dateTo]);
            $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        } catch (\Throwable $e) { $rows = []; }
        break;

    case 'revenue_monthly':
        $filename = 'monthly_revenue_12months';
        $headers  = ['Month','Collected (KES)','Invoiced (KES)'];
        $stmt = $db->query("
            SELECT DATE_FORMAT(created_at,'%b %Y'),
                   ROUND(COALESCE(SUM(CASE WHEN status='paid' THEN total END),0),2),
                   ROUND(COALESCE(SUM(total),0),2)
            FROM invoices WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(created_at,'%Y-%m'), DATE_FORMAT(created_at,'%b %Y')
            ORDER BY DATE_FORMAT(created_at,'%Y-%m') ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        break;

    case 'parts_usage':
        $filename = 'parts_usage_' . $dateFrom . '_to_' . $dateTo;
        $headers  = ['Part Name','Category','Request Count','Total Qty'];
        try {
            $stmt = $db->prepare("
                SELECT i.part_name, i.category, COUNT(pri.id), COALESCE(SUM(pri.qty),0)
                FROM parts_request_items pri
                JOIN inventory i ON i.id=pri.inventory_id
                JOIN parts_requests pr ON pr.id=pri.request_id
                WHERE DATE(pr.created_at) BETWEEN ? AND ?
                GROUP BY i.id, i.part_name, i.category ORDER BY 4 DESC
            ");
            $stmt->execute([$dateFrom, $dateTo]);
            $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        } catch (\Throwable $e) { $rows = []; }
        break;

    case 'cars':
        $filename = 'fleet_' . date('Y-m-d');
        $headers  = ['Make','Model','Year','Color','Body Type','Transmission','Fuel','Status','Location','Chassis','Reg No','Asking Price (KES)','Mileage (km)'];
        $stmt = $db->query("
            SELECT c.make, c.model, c.year, c.color, c.body_type, c.transmission,
                   c.fuel_type, c.status, l.name, c.chassis_number,
                   c.registration_number, c.asking_price, c.mileage
            FROM cars c LEFT JOIN locations l ON l.id=c.location_id
            ORDER BY c.make, c.model
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        break;

    default:
        die('Unknown export type.');
}

// ── Stream CSV ────────────────────────────────────────────────────────────────
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
header('Cache-Control: no-cache, no-store');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');

// UTF-8 BOM so Excel opens correctly
fwrite($out, "\xEF\xBB\xBF");

// Header row
fputcsv($out, $headers);

// Data rows — convert null to empty string
foreach ($rows as $row) {
    fputcsv($out, array_map(fn($v) => $v ?? '', $row));
}

fclose($out);
exit;
