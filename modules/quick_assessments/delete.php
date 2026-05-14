<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('quick_assessments') || die('Access denied.');
canEditDelete() || die('Permission denied.');

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect('index.php');

$db = getDB();
$qa = $db->prepare("SELECT * FROM quick_assessments WHERE id = ?");
$qa->execute([$id]);
$assessment = $qa->fetch();

if (!$assessment) {
    setFlash('error', "Assessment not found.");
    redirect('index.php');
}

try {
    $db->prepare("DELETE FROM quick_assessments WHERE id = ?")->execute([$id]);
    setFlash('success', "Assessment {$assessment['assessment_number']} deleted.");
} catch (\Throwable $e) {
    setFlash('error', "Delete failed: " . $e->getMessage());
}

redirect('index.php');
