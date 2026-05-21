<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Server Error — Mascardi System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; font-family: "Segoe UI", system-ui, sans-serif; }
.error-card { background: #fff; padding: 3rem; border-radius: 16px; box-shadow: 0 10px 25px -5px rgba(0,0,0,.1); max-width: 500px; width: 100%; text-align: center; }
.icon-box { width: 80px; height: 80px; background: #fee2e2; color: #dc2626; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; font-size: 2.2rem; }
</style>
</head>
<body>
<div class="error-card">
    <div class="icon-box">&#9888;</div>
    <h3 class="mb-2">Something went wrong</h3>
    <p class="text-muted mb-4">An unexpected error occurred. Our team has been notified.<br>Please try again or contact support if the problem persists.</p>
    <div class="d-grid gap-2">
        <a href="javascript:history.back()" class="btn btn-primary">Go Back</a>
        <a href="/" class="btn btn-outline-secondary">Return to Dashboard</a>
    </div>
    <p class="text-muted mt-3" style="font-size:12px">Reference time: <?= date('Y-m-d H:i:s') ?></p>
</div>
</body>
</html>
