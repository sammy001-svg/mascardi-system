<?php
/**
 * Staff Clock-in Terminal
 * Phase 10: Attendance Clock-in/out via PIN
 * 
 * Public page designed to be left open on a shared workshop tablet.
 */
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();
$today = date('Y-m-d');

// Load active staff with PINs
try {
    $mechanics = $db->query("SELECT id, 'mechanic' AS stype, name, pin FROM mechanics WHERE status='active' AND pin IS NOT NULL")->fetchAll();
    $drivers   = $db->query("SELECT id, 'driver' AS stype, name, pin FROM drivers WHERE status='active' AND pin IS NOT NULL")->fetchAll();
    $staff = array_merge($mechanics, $drivers);
    usort($staff, fn($a,$b) => strcmp($a['name'], $b['name']));
} catch (\Throwable $e) { $staff = []; }

// Handle API PIN submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    $staffKey = $_POST['staff_key'] ?? '';
    $pin      = $_POST['pin'] ?? '';
    $action   = $_POST['action'] ?? ''; // clock_in or clock_out

    if (!$staffKey || !$pin || !in_array($action, ['clock_in','clock_out'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid request.']);
        exit;
    }

    [$staffType, $staffId] = explode('_', $staffKey);
    
    // Verify PIN
    $valid = false;
    $staffName = '';
    foreach ($staff as $s) {
        if ($s['stype'] === $staffType && $s['id'] == $staffId && $s['pin'] === $pin) {
            $valid = true;
            $staffName = $s['name'];
            break;
        }
    }

    if (!$valid) {
        echo json_encode(['success' => false, 'error' => 'Incorrect PIN.']);
        exit;
    }

    $timeNow = date('H:i:s');
    
    if ($action === 'clock_in') {
        // If clocking in after 08:15, mark as late
        $status = ($timeNow > '08:15:00') ? 'late' : 'present';
        
        $stmt = $db->prepare("
            INSERT INTO attendance_records (staff_type, staff_id, attendance_date, status, clock_in)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE clock_in = IF(clock_in IS NULL, ?, clock_in), status = IF(status='absent', ?, status)
        ");
        $stmt->execute([$staffType, $staffId, $today, $status, $timeNow, $timeNow, $status]);
        
        echo json_encode(['success' => true, 'message' => "Welcome, $staffName! Clocked IN at " . substr($timeNow, 0, 5)]);
    } else {
        // Clock out
        $stmt = $db->prepare("
            UPDATE attendance_records
            SET clock_out = ?
            WHERE staff_type = ? AND staff_id = ? AND attendance_date = ?
        ");
        $stmt->execute([$timeNow, $staffType, $staffId, $today]);
        
        // If they didn't clock in, create a record just with clock out
        if ($stmt->rowCount() === 0) {
            $ins = $db->prepare("
                INSERT INTO attendance_records (staff_type, staff_id, attendance_date, status, clock_out)
                VALUES (?, ?, ?, 'present', ?)
            ");
            $ins->execute([$staffType, $staffId, $today, $timeNow]);
        }
        
        echo json_encode(['success' => true, 'message' => "Goodbye, $staffName! Clocked OUT at " . substr($timeNow, 0, 5)]);
    }
    exit;
}

// Get today's attendance to know who is clocked in
$todayRecs = [];
try {
    $tr = $db->query("SELECT staff_type, staff_id, clock_in, clock_out FROM attendance_records WHERE attendance_date='$today'")->fetchAll();
    foreach ($tr as $r) {
        $todayRecs[$r['staff_type'].'_'.$r['staff_id']] = $r;
    }
} catch (\Throwable $e) {}

$pageTitle = 'Terminal | Mascardi';
$hideSidebar = true;
$hideTopbar  = true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= $pageTitle ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            background: #0f172a;
            color: #f8fafc;
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .clock-header {
            padding: 24px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .clock-time {
            font-size: 42px;
            font-weight: 800;
            line-height: 1;
            font-variant-numeric: tabular-nums;
            letter-spacing: -1px;
            background: linear-gradient(135deg, #60a5fa, #a78bfa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .clock-date {
            font-size: 16px;
            color: #94a3b8;
            font-weight: 500;
        }
        
        .main-container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .terminal-box {
            background: #1e293b;
            border-radius: 24px;
            width: 100%;
            max-width: 480px;
            padding: 32px;
            box-shadow: 0 24px 64px rgba(0,0,0,0.4);
            border: 1px solid rgba(255,255,255,0.05);
            text-align: center;
        }
        
        .staff-select-btn {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            color: #fff;
            padding: 16px 20px;
            border-radius: 16px;
            font-size: 18px;
            font-weight: 600;
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .staff-select-btn:hover {
            background: rgba(255,255,255,0.08);
            border-color: rgba(255,255,255,0.2);
        }
        
        /* PIN Pad */
        .pin-display {
            font-size: 32px;
            letter-spacing: 16px;
            font-weight: 800;
            height: 48px;
            margin-bottom: 24px;
            color: #fff;
            display: flex;
            justify-content: center;
            align-items: center;
            font-variant-numeric: tabular-nums;
        }
        .pin-display .dot {
            width: 14px; height: 14px;
            border-radius: 50%;
            background: #334155;
            margin: 0 8px;
            transition: background 0.15s;
        }
        .pin-display .dot.filled { background: #3b82f6; box-shadow: 0 0 12px rgba(59,130,246,0.6); }
        .pin-display .dot.error { background: #ef4444; box-shadow: 0 0 12px rgba(239,68,68,0.6); }
        
        .numpad {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 24px;
            max-width: 320px;
            margin-left: auto;
            margin-right: auto;
        }
        .num-btn {
            background: rgba(255,255,255,0.05);
            border: none;
            border-radius: 50%;
            width: 72px;
            height: 72px;
            margin: 0 auto;
            font-size: 28px;
            font-weight: 600;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.1s, transform 0.1s;
        }
        .num-btn:active {
            background: rgba(255,255,255,0.15);
            transform: scale(0.92);
        }
        .num-btn.action-del { background: rgba(239,68,68,0.1); color: #ef4444; font-size: 20px; }
        .num-btn.action-del:active { background: rgba(239,68,68,0.2); }
        
        /* Action Buttons */
        .action-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .action-btn {
            padding: 16px;
            border-radius: 16px;
            border: none;
            font-size: 18px;
            font-weight: 700;
            color: #fff;
            cursor: pointer;
            transition: all 0.2s;
            opacity: 0.5;
            pointer-events: none;
        }
        .action-btn.active { opacity: 1; pointer-events: auto; }
        .action-btn.active:active { transform: scale(0.96); }
        
        .btn-in  { background: #10b981; box-shadow: 0 8px 24px rgba(16,185,129,0.25); }
        .btn-out { background: #f59e0b; box-shadow: 0 8px 24px rgba(245,158,11,0.25); }
        
        /* Staff Modal */
        .modal-content {
            background: #1e293b;
            color: #fff;
            border-radius: 20px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .modal-header { border-bottom: 1px solid rgba(255,255,255,0.1); }
        .btn-close-white { filter: invert(1) grayscale(100%) brightness(200%); }
        .staff-list {
            max-height: 400px;
            overflow-y: auto;
        }
        .staff-item {
            padding: 14px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            transition: background 0.1s;
        }
        .staff-item:hover { background: rgba(255,255,255,0.05); }
        .staff-item-name { font-weight: 600; font-size: 16px; }
        .staff-item-role { font-size: 12px; color: #94a3b8; background: rgba(255,255,255,0.1); padding: 2px 8px; border-radius: 10px; }
        .staff-status { font-size: 11px; padding: 3px 8px; border-radius: 12px; font-weight: 700; }
        .status-in { background: rgba(16,185,129,0.2); color: #34d399; }
        .status-out { background: rgba(245,158,11,0.2); color: #fbbf24; }

        /* Toast */
        #termToast {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%) translateY(-100px);
            background: #fff;
            color: #0f172a;
            padding: 16px 24px;
            border-radius: 16px;
            font-weight: 700;
            font-size: 18px;
            box-shadow: 0 16px 48px rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            gap: 12px;
            z-index: 9999;
            transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        #termToast.show { transform: translateX(-50%) translateY(0); }
        #termToast.success { color: #10b981; border: 2px solid #10b981; }
        #termToast.error   { color: #ef4444; border: 2px solid #ef4444; }
    </style>
</head>
<body>

    <!-- Header -->
    <div class="clock-header">
        <div>
            <div style="font-size:20px;font-weight:800;letter-spacing:-0.5px">MASCARDI<span style="color:#3b82f6">.</span></div>
            <div style="font-size:12px;color:#94a3b8">Attendance Terminal</div>
        </div>
        <div class="text-end">
            <div class="clock-time" id="clockTime">00:00:00</div>
            <div class="clock-date"><?= date('l, j F Y') ?></div>
        </div>
    </div>

    <!-- Main Terminal -->
    <div class="main-container">
        <div class="terminal-box">
            
            <div class="staff-select-btn" data-bs-toggle="modal" data-bs-target="#staffModal">
                <span id="selectedStaffName">Tap to select your name</span>
                <i class="fa fa-chevron-down opacity-50"></i>
            </div>

            <div class="pin-display" id="pinDisplay">
                <div class="dot"></div>
                <div class="dot"></div>
                <div class="dot"></div>
                <div class="dot"></div>
            </div>

            <div class="numpad" id="numpad">
                <button class="num-btn" onclick="addPin('1')">1</button>
                <button class="num-btn" onclick="addPin('2')">2</button>
                <button class="num-btn" onclick="addPin('3')">3</button>
                <button class="num-btn" onclick="addPin('4')">4</button>
                <button class="num-btn" onclick="addPin('5')">5</button>
                <button class="num-btn" onclick="addPin('6')">6</button>
                <button class="num-btn" onclick="addPin('7')">7</button>
                <button class="num-btn" onclick="addPin('8')">8</button>
                <button class="num-btn" onclick="addPin('9')">9</button>
                <div></div>
                <button class="num-btn" onclick="addPin('0')">0</button>
                <button class="num-btn action-del" onclick="delPin()"><i class="fa fa-delete-left"></i></button>
            </div>

            <div class="action-row">
                <button class="action-btn btn-in" id="btnClockIn" onclick="submitAction('clock_in')">
                    <i class="fa fa-arrow-right-to-bracket me-2"></i>CLOCK IN
                </button>
                <button class="action-btn btn-out" id="btnClockOut" onclick="submitAction('clock_out')">
                    CLOCK OUT<i class="fa fa-arrow-right-from-bracket ms-2"></i>
                </button>
            </div>

        </div>
    </div>

    <!-- Toast Notification -->
    <div id="termToast">
        <i class="fa fa-circle-check" id="termToastIcon"></i>
        <span id="termToastMsg">Message</span>
    </div>

    <!-- Staff Selection Modal -->
    <div class="modal fade" id="staffModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Select Staff Member</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0 staff-list">
                    <?php if (empty($staff)): ?>
                        <div class="p-4 text-center text-muted">No staff configured with PINs.</div>
                    <?php endif; ?>
                    <?php foreach ($staff as $s): 
                        $key = $s['stype'].'_'.$s['id'];
                        $rec = $todayRecs[$key] ?? null;
                        $isIn = $rec && $rec['clock_in'] && !$rec['clock_out'];
                        $isOut = $rec && $rec['clock_out'];
                    ?>
                    <div class="staff-item" onclick="selectStaff('<?= $key ?>', '<?= addslashes($s['name']) ?>', <?= $isIn ? 'true' : 'false' ?>)" data-bs-dismiss="modal">
                        <div>
                            <div class="staff-item-name"><?= htmlspecialchars($s['name']) ?></div>
                            <span class="staff-item-role"><?= ucfirst($s['stype']) ?></span>
                        </div>
                        <?php if ($isIn): ?>
                            <span class="staff-status status-in">IN (<?= substr($rec['clock_in'],0,5) ?>)</span>
                        <?php elseif ($isOut): ?>
                            <span class="staff-status status-out">OUT (<?= substr($rec['clock_out'],0,5) ?>)</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Clock
        function updateClock() {
            var now = new Date();
            var h = now.getHours().toString().padStart(2, '0');
            var m = now.getMinutes().toString().padStart(2, '0');
            var s = now.getSeconds().toString().padStart(2, '0');
            document.getElementById('clockTime').textContent = h + ':' + m + ':' + s;
        }
        setInterval(updateClock, 1000);
        updateClock();

        // State
        var currentStaffKey = '';
        var currentPin = '';

        function selectStaff(key, name, isCurrentlyIn) {
            currentStaffKey = key;
            document.getElementById('selectedStaffName').textContent = name;
            currentPin = '';
            updatePinDisplay();
            
            // Smart active buttons based on current status
            var btnIn = document.getElementById('btnClockIn');
            var btnOut = document.getElementById('btnClockOut');
            
            btnIn.classList.remove('active');
            btnOut.classList.remove('active');
            
            // Highlight the logical next action if they start typing a PIN
        }

        function addPin(num) {
            if (!currentStaffKey) {
                var sBtn = document.querySelector('.staff-select-btn');
                sBtn.style.transform = 'scale(1.05)';
                sBtn.style.borderColor = '#ef4444';
                setTimeout(()=> { sBtn.style.transform = 'scale(1)'; sBtn.style.borderColor = 'rgba(255,255,255,0.1)'; }, 200);
                return;
            }
            if (currentPin.length < 4) {
                currentPin += num;
                updatePinDisplay();
            }
        }

        function delPin() {
            if (currentPin.length > 0) {
                currentPin = currentPin.slice(0, -1);
                updatePinDisplay();
            }
        }

        function updatePinDisplay() {
            var dots = document.querySelectorAll('.pin-display .dot');
            dots.forEach((dot, index) => {
                if (index < currentPin.length) {
                    dot.classList.add('filled');
                    dot.classList.remove('error');
                } else {
                    dot.classList.remove('filled', 'error');
                }
            });

            // Activate buttons when PIN is 4 digits
            if (currentPin.length === 4) {
                document.getElementById('btnClockIn').classList.add('active');
                document.getElementById('btnClockOut').classList.add('active');
            } else {
                document.getElementById('btnClockIn').classList.remove('active');
                document.getElementById('btnClockOut').classList.remove('active');
            }
        }

        function submitAction(action) {
            if (currentPin.length !== 4 || !currentStaffKey) return;
            
            // Send AJAX
            var formData = new FormData();
            formData.append('staff_key', currentStaffKey);
            formData.append('pin', currentPin);
            formData.append('action', action);

            fetch('', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    resetTerminal();
                    // Reload page after 3 seconds to update staff list statuses
                    setTimeout(() => window.location.reload(), 3000);
                } else {
                    showToast(data.error || 'Error', 'error');
                    // Shake PIN
                    var dots = document.querySelectorAll('.pin-display .dot.filled');
                    dots.forEach(dot => dot.classList.add('error'));
                    setTimeout(() => {
                        currentPin = '';
                        updatePinDisplay();
                    }, 500);
                }
            })
            .catch(() => showToast('Network Error', 'error'));
        }

        function resetTerminal() {
            currentStaffKey = '';
            currentPin = '';
            document.getElementById('selectedStaffName').textContent = 'Tap to select your name';
            updatePinDisplay();
        }

        function showToast(msg, type) {
            var toast = document.getElementById('termToast');
            var icon  = document.getElementById('termToastIcon');
            var text  = document.getElementById('termToastMsg');
            
            toast.className = 'show ' + type;
            icon.className = type === 'success' ? 'fa fa-circle-check' : 'fa fa-triangle-exclamation';
            text.textContent = msg;

            setTimeout(() => {
                toast.className = '';
            }, 3000);
        }
    </script>
</body>
</html>
