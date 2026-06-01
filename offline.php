<?php
/**
 * Offline fallback page — served by the service worker when the network
 * is unavailable and no cached version of the requested page exists.
 * Must work entirely without CDN assets (inlined CSS only).
 */
$appName = 'Mascardi Car Yard';
if (file_exists(__DIR__ . '/config/app.php')) {
    try {
        require_once __DIR__ . '/config/app.php';
        if (function_exists('getDB')) {
            $row = getDB()->query("SELECT setting_value FROM settings WHERE setting_key='company_name' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if ($row) $appName = $row['setting_value'];
        }
    } catch (Exception $e) {}
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>You're Offline — <?= htmlspecialchars($appName) ?></title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  html, body { height: 100%; margin: 0; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 60%, #2563eb 100%);
    display: flex; align-items: center; justify-content: center;
    min-height: 100vh;
    padding: 24px;
    padding-bottom: calc(24px + env(safe-area-inset-bottom));
  }
  .card {
    background: rgba(255,255,255,0.07);
    -webkit-backdrop-filter: blur(20px);
    backdrop-filter: blur(20px);
    border: 1px solid rgba(255,255,255,0.15);
    border-radius: 24px;
    padding: 48px 40px;
    text-align: center;
    max-width: 440px;
    width: 100%;
    box-shadow: 0 32px 64px rgba(0,0,0,0.4);
  }
  .icon-wrap {
    width: 88px; height: 88px;
    border-radius: 50%;
    background: rgba(255,255,255,0.1);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 28px;
    font-size: 40px;
  }
  h1 {
    font-size: 26px; font-weight: 800;
    color: #fff; margin: 0 0 12px;
    letter-spacing: -0.5px;
  }
  p {
    font-size: 15px; color: rgba(255,255,255,0.65);
    margin: 0 0 32px; line-height: 1.6;
  }
  .btn-group { display: flex; flex-direction: column; gap: 12px; }
  .btn {
    display: flex; align-items: center; justify-content: center;
    gap: 8px; padding: 14px 24px;
    border-radius: 12px; border: none;
    font-size: 15px; font-weight: 600;
    cursor: pointer; text-decoration: none;
    transition: transform 0.15s, box-shadow 0.15s;
  }
  .btn:active { transform: scale(0.97); }
  .btn-primary {
    background: #2563eb; color: #fff;
    box-shadow: 0 4px 16px rgba(37,99,235,0.45);
  }
  .btn-primary:hover { background: #1d4ed8; box-shadow: 0 6px 20px rgba(37,99,235,0.55); }
  .btn-secondary {
    background: rgba(255,255,255,0.1); color: rgba(255,255,255,0.85);
    border: 1px solid rgba(255,255,255,0.2);
  }
  .btn-secondary:hover { background: rgba(255,255,255,0.18); }
  .branding {
    margin-top: 36px;
    font-size: 12px; color: rgba(255,255,255,0.35);
    display: flex; align-items: center; justify-content: center; gap: 8px;
  }
  .brand-dot {
    width: 20px; height: 20px;
    border-radius: 6px;
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 10px; color: #fff; font-weight: 800;
  }
  .tips {
    margin-top: 24px;
    background: rgba(255,255,255,0.05);
    border-radius: 12px;
    padding: 16px;
    text-align: left;
  }
  .tips h3 { font-size: 12px; text-transform: uppercase; letter-spacing: 1px; color: rgba(255,255,255,0.4); margin: 0 0 10px; font-weight: 700; }
  .tips ul  { margin: 0; padding: 0 0 0 18px; }
  .tips li  { font-size: 13px; color: rgba(255,255,255,0.55); margin-bottom: 5px; }
</style>
</head>
<body>
<div class="card">
  <div class="icon-wrap">📶</div>
  <h1>You're Offline</h1>
  <p>No internet connection detected. Some pages may have been cached and are still available.</p>

  <div class="btn-group">
    <button class="btn btn-primary" onclick="window.location.reload()">
      🔄 Try Again
    </button>
    <a class="btn btn-secondary" href="javascript:history.back()">
      ← Go Back
    </a>
  </div>

  <div class="tips">
    <h3>While you're offline</h3>
    <ul>
      <li>Previously visited pages may still load</li>
      <li>Check your Wi-Fi or mobile data</li>
      <li>New data requires an internet connection</li>
    </ul>
  </div>

  <div class="branding">
    <span class="brand-dot">M</span>
    <?= htmlspecialchars($appName) ?>
  </div>
</div>

<script>
  // Auto-retry when connection comes back
  window.addEventListener('online', () => window.location.reload());
</script>
</body>
</html>
