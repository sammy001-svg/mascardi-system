<?php
/** GET /api/v1/stats — Dashboard statistics */

$db    = getDB();
$stats = getDashboardStats();

// Add revenue breakdown
$stats['revenue_today']   = (float)$db->query("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status='paid' AND DATE(created_at)=CURDATE()")->fetchColumn();
$stats['revenue_year']    = (float)$db->query("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status='paid' AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();
$stats['outstanding']     = (float)$db->query("SELECT COALESCE(SUM(total-amount_paid),0) FROM invoices WHERE status IN ('unpaid','partial')")->fetchColumn();
$stats['total_clients']   = (int)$db->query("SELECT COUNT(*) FROM clients WHERE status='active'")->fetchColumn();
$stats['active_mechanics']= (int)$db->query("SELECT COUNT(*) FROM mechanics WHERE status='active'")->fetchColumn();

apiResponse($stats);
