<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canEditDelete() || die('Admin only.');
$db = getDB();
$id = (int)($_GET['id'] ?? 0); if (!$id) redirect('index.php');
$job = $db->prepare("SELECT * FROM dispatch_jobs WHERE id=?"); $job->execute([$id]); $job=$job->fetch();
if (!$job) { setFlash('error','Not found.'); redirect('index.php'); }
if (in_array($job['status'], ['en_route'])) {
    setFlash('error','Cannot delete a job that is en route.'); redirect('view.php?id='.$id);
}
$db->prepare("DELETE FROM dispatch_jobs WHERE id=?")->execute([$id]);
logActivity('delete','dispatch',$id,"Deleted dispatch job {$job['job_number']}");
setFlash('success','Dispatch job deleted.');
redirect('index.php');
