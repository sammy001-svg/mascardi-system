<?php
/** GET /api/v1/invoices  or  GET /api/v1/invoices/{id} */

$db = getDB();

if ($id) {
    $stmt = $db->prepare("SELECT i.*, c.chassis_number, c.make, c.model, c.year FROM invoices i JOIN cars c ON c.id = i.car_id WHERE i.id = ?");
    $stmt->execute([$id]);
    $inv = $stmt->fetch();
    if (!$inv) apiError(404, "Invoice #{$id} not found.");

    $items = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id");
    $items->execute([$id]);
    $inv['items'] = $items->fetchAll();

    $payments = $db->prepare("SELECT payment_number, payment_date, amount, payment_method, status FROM payments WHERE invoice_id = ? ORDER BY payment_date DESC");
    $payments->execute([$id]);
    $inv['payments'] = $payments->fetchAll();

    apiResponse($inv);
}

$where  = ['1=1'];
$params = [];
$status = $_GET['status'] ?? '';
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
$offset = ($page - 1) * $limit;

if ($status) { $where[] = 'i.status = ?'; $params[] = $status; }

$whereStr = implode(' AND ', $where);

$totalStmt = $db->prepare("SELECT COUNT(*) FROM invoices i WHERE {$whereStr}");
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();

$stmt = $db->prepare("SELECT i.id, i.invoice_number, i.date, i.due_date, i.customer_name,
                             i.total, i.amount_paid, (i.total - i.amount_paid) AS balance, i.status,
                             c.chassis_number, c.make, c.model
                      FROM invoices i JOIN cars c ON c.id = i.car_id
                      WHERE {$whereStr} ORDER BY i.created_at DESC LIMIT {$limit} OFFSET {$offset}");
$stmt->execute($params);
$invoices = $stmt->fetchAll();

apiPaginate($invoices, $total, $page, $limit);
