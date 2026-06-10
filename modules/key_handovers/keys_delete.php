<?php
require_once __DIR__ . '/../../includes/functions.php';
requireWrite('key_handovers');
$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/key_handovers/index.php?tab=keys');
$key = $db->prepare("SELECT id, key_label FROM car_keys WHERE id=?");
$key->execute([$id]);
$key = $key->fetch();
if (!$key) { setFlash('error', 'Key not found.'); redirect(BASE_URL . '/modules/key_handovers/index.php?tab=keys'); }
$db->prepare("DELETE FROM car_keys WHERE id=?")->execute([$id]);
setFlash('success', "Key '{$key['key_label']}' removed from register.");
redirect(BASE_URL . '/modules/key_handovers/index.php?tab=keys');
