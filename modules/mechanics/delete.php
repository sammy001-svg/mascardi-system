<?php
require_once __DIR__ . '/../../includes/functions.php';
$id = (int)($_GET['id']??0);
if($id){getDB()->prepare("DELETE FROM mechanics WHERE id=?")->execute([$id]);setFlash('success','Mechanic deleted.');}
redirect(BASE_URL.'/modules/mechanics/index.php');
