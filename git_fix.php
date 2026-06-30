<?php
// TEMPORARY — delete this file immediately after running it once
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['run'] ?? '') === 'yes') {
    $dir = escapeshellarg(__DIR__);
    $git = '/usr/local/cpanel/3rdparty/bin/git';

    $cmds = [
        'Fetch from GitHub' => "$git -C $dir fetch origin 2>&1",
        'Reset to origin/master' => "$git -C $dir reset --hard origin/master 2>&1",
    ];

    echo '<h3 style="color:green">Running git fix...</h3><pre style="background:#1e1e1e;color:#d4d4d4;padding:20px;border-radius:8px;font-size:13px">';
    foreach ($cmds as $label => $cmd) {
        echo "<b style='color:#4ec9b0'>▶ {$label}</b>\n";
        echo shell_exec($cmd) . "\n";
    }
    echo '</pre>';
    echo '<p style="color:green;font-weight:bold">✓ Done. Please <a href="?">refresh</a> and then <strong>delete this file</strong> immediately.</p>';
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Git Fix</title>
<style>
body { font-family: sans-serif; max-width: 500px; margin: 80px auto; padding: 20px; }
.box { background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 20px; }
button { background: #dc3545; color: #fff; border: none; padding: 12px 28px; border-radius: 6px; font-size: 16px; cursor: pointer; margin-top: 16px; }
button:hover { background: #b02a37; }
code { background: #f8f9fa; padding: 2px 6px; border-radius: 4px; font-size: 13px; }
</style>
</head>
<body>
<div class="box">
    <h3>⚠️ Git Divergence Fix</h3>
    <p>This will run:</p>
    <ol>
        <li><code>git fetch origin</code></li>
        <li><code>git reset --hard origin/master</code></li>
    </ol>
    <p>It forces the server to match GitHub exactly. <strong>Delete this file immediately after use.</strong></p>
    <form method="POST">
        <input type="hidden" name="run" value="yes">
        <button type="submit">Run Fix Now</button>
    </form>
</div>
</body>
</html>
