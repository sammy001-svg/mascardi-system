<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin(); requireRole('admin');
$id=(int)($_GET['id']??0);
if($id){getDB()->prepare("DELETE FROM car_assessments WHERE id=?")->execute([$id]);setFlash('success','Assessment deleted.');}
redirect(BASE_URL.'/modules/assessments/index.php');
