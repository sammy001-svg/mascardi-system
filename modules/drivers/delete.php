<?php
require_once __DIR__ . '/../../includes/functions.php';
$id = (int)($_GET['id'] ?? 0);
if ($id) { getDB()->prepare("DELETE FROM drivers WHERE id=?")->execute([$id]); setFlash('success','Driver deleted.'); }
redirect(BASE_URL.'/modules/drivers/index.php');
