<?php
/** GET /api/v1/payments  or  GET /api/v1/payments/{id} */

$db = getDB();

if ($id) {
    $stmt = $db->prepare("SELECT p.*, i.invoice_number FROM payments p LEFT JOIN invoices i ON i.id = p.invoice_id WHERE p.id = ?");
    $stmt->execute([$id]);
    $pay = $stmt->fetch();
    if (!$pay) apiError(404, "Payment #{$id} not found.");
    apiResponse($pay);
}

$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
$offset = ($page - 1) * $limit;
$method = $_GET['method'] ?? '';

$where  = ['1=1'];
$params = [];
if ($method) { $where[] = 'p.payment_method = ?'; $params[] = $method; }
$whereStr = implode(' AND ', $where);

$totalStmt = $db->prepare("SELECT COUNT(*) FROM payments p WHERE {$whereStr}");
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();

$stmt = $db->prepare("SELECT p.id, p.payment_number, p.payment_date, p.amount, p.payment_method, p.status,
                             p.mpesa_code, i.invoice_number
                      FROM payments p LEFT JOIN invoices i ON i.id = p.invoice_id
                      WHERE {$whereStr} ORDER BY p.payment_date DESC LIMIT {$limit} OFFSET {$offset}");
$stmt->execute($params);
$payments = $stmt->fetchAll();

apiPaginate($payments, $total, $page, $limit);
