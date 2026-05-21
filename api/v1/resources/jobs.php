<?php
/** GET /api/v1/jobs  or  GET /api/v1/jobs/{id} */

$db = getDB();

if ($id) {
    $stmt = $db->prepare("SELECT j.*, c.chassis_number, c.make, c.model, m.name AS mechanic_name
                          FROM workshop_jobs j
                          JOIN cars c ON c.id = j.car_id
                          LEFT JOIN mechanics m ON m.id = j.mechanic_id
                          WHERE j.id = ?");
    $stmt->execute([$id]);
    $job = $stmt->fetch();
    if (!$job) apiError(404, "Job #{$id} not found.");
    apiResponse($job);
}

$where  = ['1=1'];
$params = [];
$status = $_GET['status']  ?? '';
$mech   = (int)($_GET['mechanic_id'] ?? 0);
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
$offset = ($page - 1) * $limit;

if ($status) { $where[] = 'j.status = ?';      $params[] = $status; }
if ($mech)   { $where[] = 'j.mechanic_id = ?'; $params[] = $mech; }

$whereStr = implode(' AND ', $where);

$totalStmt = $db->prepare("SELECT COUNT(*) FROM workshop_jobs j WHERE {$whereStr}");
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();

$stmt = $db->prepare("SELECT j.id, j.job_number, j.status, j.priority, j.start_date, j.end_date, j.created_at,
                             c.chassis_number, c.make, c.model,
                             m.name AS mechanic_name
                      FROM workshop_jobs j
                      JOIN cars c ON c.id = j.car_id
                      LEFT JOIN mechanics m ON m.id = j.mechanic_id
                      WHERE {$whereStr} ORDER BY j.created_at DESC LIMIT {$limit} OFFSET {$offset}");
$stmt->execute($params);
$jobs = $stmt->fetchAll();

apiPaginate($jobs, $total, $page, $limit);
