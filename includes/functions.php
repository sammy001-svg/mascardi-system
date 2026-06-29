<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/auth.php';

// ── CSRF helpers ─────────────────────────────────────────────
function csrfToken(): string {
    return $_SESSION['csrf_token'] ?? '';
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

function verifyCsrf(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!$token || !hash_equals(csrfToken(), $token)) {
        http_response_code(403);
        die('<!DOCTYPE html><html><head><title>Request Rejected</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>body{display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f8fafc}
        .card{max-width:450px;width:100%;padding:2rem;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.1);text-align:center}</style>
        </head><body><div class="card">
        <i class="fa fa-shield-halved" style="font-size:3rem;color:#dc2626;margin-bottom:1rem"></i>
        <h4>Security Check Failed</h4>
        <p class="text-muted">Your session may have expired. Please go back and try again.</p>
        <a href="javascript:history.back()" class="btn btn-primary">Go Back</a>
        </div></body></html>');
    }
}

// ── Sanitize output ──────────────────────────────────────
function e(string $val): string {
    return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
}

// ── Format currency ──────────────────────────────────────
function money(float $amount): string {
    return 'KES ' . number_format($amount, 2);
}

// ── Format date ──────────────────────────────────────────
function fmtDate(?string $date, string $format = 'd M Y'): string {
    if (!$date) return '—';
    return date($format, strtotime($date));
}

// ── Status badge ─────────────────────────────────────────
function statusBadge(string $status): string {
    $map = [
        'in_transit'     => 'warning',
        'arrived'        => 'info',
        'in_assessment'  => 'primary',
        'in_workshop'    => 'secondary',
        'completed'      => 'success',
        'delivered'      => 'dark',
        'sold'           => 'success',
        'paid_full'      => 'success',
        'financed'       => 'info',
        'active'         => 'success',
        'inactive'       => 'danger',
        'pending'        => 'warning',
        'in_progress'    => 'primary',
        'waiting_parts'  => 'info',
        'on_hold'        => 'secondary',
        'cancelled'      => 'danger',
        'draft'          => 'secondary',
        'sent'           => 'info',
        'approved'       => 'success',
        'rejected'       => 'danger',
        'converted'      => 'dark',
        'unpaid'         => 'danger',
        'partial'        => 'warning',
        'paid'           => 'success',
        'acknowledged'   => 'info',
        'received'       => 'success',
        'good'           => 'success',
        'fair'           => 'warning',
        'poor'           => 'danger',
        'critical'       => 'danger',
        'excellent'      => 'success',
        'high'           => 'danger',
        'urgent'         => 'danger',
        'normal'         => 'primary',
        'low'            => 'secondary',
    ];
    $class = $map[$status] ?? 'secondary';
    $label = ucwords(str_replace('_', ' ', $status));
    return "<span class=\"badge bg-{$class}\">{$label}</span>";
}

// ── Generate next document number ────────────────────────
function nextNumber(string $table, string $column, string $prefix): string {
    $db  = getDB();
    $row = $db->query("SELECT MAX(CAST(SUBSTRING({$column}, " . (strlen($prefix) + 2) . ") AS UNSIGNED)) AS mx FROM {$table}")->fetch();
    $next = ($row['mx'] ?? 0) + 1;
    return $prefix . '-' . str_pad($next, 4, '0', STR_PAD_LEFT);
}

// ── Pagination helper ─────────────────────────────────────
function paginate(int $total, int $page, int $perPage, string $url): string {
    $pages = (int)ceil($total / $perPage);
    if ($pages <= 1) return '';
    $html = '<nav><ul class="pagination pagination-sm mb-0">';
    for ($i = 1; $i <= $pages; $i++) {
        $active = $i === $page ? ' active' : '';
        $html .= "<li class=\"page-item{$active}\"><a class=\"page-link\" href=\"{$url}&page={$i}\">{$i}</a></li>";
    }
    $html .= '</ul></nav>';
    return $html;
}

// ── Redirect helper ───────────────────────────────────────
function redirect(string $url): void {
    header("Location: {$url}");
    exit;
}

// ── Car parts checklist ───────────────────────────────────
function getPartsList(): array {
    return [
        'Exterior' => ['Front Bumper','Rear Bumper','Hood/Bonnet','Trunk/Boot Lid','Left Front Door','Right Front Door','Left Rear Door','Right Rear Door','Left Front Fender','Right Front Fender','Roof','Windshield (Front)','Windshield (Rear)','Left Side Mirror','Right Side Mirror'],
        'Lighting' => ['Headlights (Left)','Headlights (Right)','Tail Lights (Left)','Tail Lights (Right)','Fog Lights (Front)','Fog Lights (Rear)','Turn Signals'],
        'Wheels & Tyres' => ['Front Left Tyre','Front Right Tyre','Rear Left Tyre','Rear Right Tyre','Spare Tyre','Wheel Rims','Wheel Caps'],
        'Interior' => ['Dashboard','Steering Wheel','Seats (Front)','Seats (Rear)','Carpets/Mats','Headliner','Door Panels','Centre Console','Gear Shift'],
        'Electronics' => ['Radio/Infotainment','Air Conditioning','Power Windows','Central Locking','Alarm System','Battery'],
        'Engine & Mechanical' => ['Engine','Transmission/Gearbox','Radiator','Exhaust System','Brake System','Suspension','Steering System','Fuel System'],
        'Documents' => ['Logbook','Insurance','Road Licence','Keys'],
    ];
}

// ── Dashboard counts ──────────────────────────────────────
function getDashboardStats(): array {
    $db = getDB();
    $stats = [];
    $stats['total_cars']         = $db->query("SELECT COUNT(*) FROM cars")->fetchColumn();
    $stats['in_transit']         = $db->query("SELECT COUNT(*) FROM cars WHERE status='in_transit'")->fetchColumn();
    $stats['in_workshop']        = $db->query("SELECT COUNT(*) FROM cars WHERE status='in_workshop'")->fetchColumn();
    $stats['completed']          = $db->query("SELECT COUNT(*) FROM cars WHERE status='completed'")->fetchColumn();
    $stats['open_jobs']          = $db->query("SELECT COUNT(*) FROM workshop_jobs WHERE status NOT IN ('completed','cancelled')")->fetchColumn();
    $stats['unpaid_invoices']    = $db->query("SELECT COUNT(*) FROM invoices WHERE status='unpaid'")->fetchColumn();
    $stats['low_stock']          = $db->query("SELECT COUNT(*) FROM inventory WHERE quantity <= reorder_level")->fetchColumn();
    $stats['pending_lpo']        = $db->query("SELECT COUNT(*) FROM lpo WHERE status IN ('draft','sent')")->fetchColumn();
    $stats['revenue_month']      = $db->query("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status='paid' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();
    return $stats;
}

/**
 * Log system activity to audit_logs table
 */
function logActivity(string $action, string $module, ?int $recordId = null, ?string $details = null, $oldValues = null, $newValues = null): void {
    try {
        $db = getDB();
        $user = authUser();
        $userId = $user ? $user['id'] : null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        $stmt = $db->prepare("INSERT INTO audit_logs (user_id, action, module, record_id, details, old_values, new_values, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $userId,
            $action,
            $module,
            $recordId,
            $details,
            $oldValues ? json_encode($oldValues) : null,
            $newValues ? json_encode($newValues) : null,
            $ip
        ]);
    } catch (Exception $e) {
        error_log("Audit Log Error: " . $e->getMessage());
    }
}
/**
 * Handle file upload with basic security checks
 */
function handleUpload(array $file, string $targetDir, array $allowedTypes = ['jpg', 'jpeg', 'png', 'webp'], int $maxSize = 20971520): string {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'The file exceeds the server\'s maximum upload size (' . ini_get('upload_max_filesize') . '). Ask your administrator to increase upload_max_filesize in php.ini.',
            UPLOAD_ERR_FORM_SIZE  => 'The file exceeds the form\'s maximum size limit.',
            UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_FILE    => 'No file was selected for upload.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server temporary folder is missing. Contact your administrator.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write the file to disk. Check server permissions.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the upload.',
        ];
        $msg = $uploadErrors[$file['error']] ?? 'Unknown upload error (code ' . $file['error'] . ').';
        throw new Exception($msg);
    }

    if ($file['size'] > $maxSize) {
        throw new Exception("File too large. Maximum " . ($maxSize / 1048576) . "MB allowed.");
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedTypes)) {
        throw new Exception("Invalid file type. Allowed: " . implode(', ', $allowedTypes));
    }

    // Server-side MIME type verification (defense against extension spoofing)
    $mimeToExt = [
        'image/jpeg'  => ['jpg', 'jpeg'],
        'image/png'   => ['png'],
        'image/webp'  => ['webp'],
        'image/gif'   => ['gif'],
        'application/pdf' => ['pdf'],
    ];
    if (function_exists('finfo_open')) {
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $allowedMimes = array_filter($mimeToExt, fn($exts) => !empty(array_intersect($exts, $allowedTypes)));
        if (!array_key_exists($mimeType, $allowedMimes)) {
            throw new Exception("File content does not match its extension. Upload rejected.");
        }
    }

    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    // Prevent PHP execution in uploads directory
    if (!file_exists($targetDir . '/.htaccess')) {
        file_put_contents($targetDir . '/.htaccess', "php_flag engine off\nOptions -ExecCGI\nAddHandler cgi-script .php .pl .py .jsp .asp .sh\n");
    }

    $filename = bin2hex(random_bytes(8)) . '.' . $ext;
    $targetPath = $targetDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new Exception("Failed to move uploaded file.");
    }

    // Auto-compress full image + generate thumbnail if GD is available
    if (in_array($ext, ['jpg','jpeg','png','webp','gif'])) {
        _imgProcess($targetPath, $ext, $targetDir);
    }

    return $filename;
}

/**
 * Compress/resize an uploaded image and generate a thumbnail.
 * Full image:  max 1920×1200, quality 82 (JPEG) — typically reduces 5–15 MB → 200–600 KB
 * Thumbnail:   max 480×360,   quality 75        — stored in targetDir/thumbs/
 * Silently skips if GD is unavailable or the image cannot be read.
 */
function _imgProcess(string $filePath, string $ext, string $targetDir): void {
    if (!function_exists('imagecreatetruecolor')) return;
    try {
        $src = _imgLoad($filePath, $ext);
        if (!$src) return;

        $ow = imagesx($src);
        $oh = imagesy($src);

        // ── Full image: resize to max 1920×1200, preserve aspect ────────
        [$nw, $nh] = _imgFit($ow, $oh, 1920, 1200);
        if ($nw < $ow || $nh < $oh) {
            $dst = imagecreatetruecolor($nw, $nh);
            _imgAlpha($dst, $ext);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $ow, $oh);
            _imgSave($dst, $filePath, $ext, 82);
            imagedestroy($dst);
        }

        // ── Thumbnail: max 480×360 ───────────────────────────────────────
        $thumbDir = $targetDir . '/thumbs';
        if (!is_dir($thumbDir)) @mkdir($thumbDir, 0755, true);
        $thumbPath = $thumbDir . '/' . basename($filePath);

        // Re-load (may have been re-saved above)
        $src2 = _imgLoad($filePath, $ext);
        if ($src2) {
            [$tw, $th] = _imgFit(imagesx($src2), imagesy($src2), 480, 360);
            $tdst = imagecreatetruecolor($tw, $th);
            _imgAlpha($tdst, $ext);
            imagecopyresampled($tdst, $src2, 0, 0, 0, 0, $tw, $th, imagesx($src2), imagesy($src2));
            _imgSave($tdst, $thumbPath, $ext, 75);
            imagedestroy($tdst);
            imagedestroy($src2);
        }

        imagedestroy($src);
    } catch (\Throwable $e) {
        // Never fail an upload due to image processing errors
        error_log('[imgProcess] ' . $e->getMessage());
    }
}

function _imgLoad(string $path, string $ext) {
    return match($ext) {
        'jpg','jpeg' => @imagecreatefromjpeg($path),
        'png'        => @imagecreatefrompng($path),
        'webp'       => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        'gif'        => @imagecreatefromgif($path),
        default      => false,
    };
}

function _imgSave($img, string $path, string $ext, int $quality): void {
    match($ext) {
        'jpg','jpeg' => imagejpeg($img, $path, $quality),
        'png'        => imagepng($img, $path, (int)round((100 - $quality) / 10)),
        'webp'       => function_exists('imagewebp') ? imagewebp($img, $path, $quality) : imagejpeg($img, $path, $quality),
        'gif'        => imagegif($img, $path),
        default      => imagejpeg($img, $path, $quality),
    };
}

function _imgAlpha($img, string $ext): void {
    if (in_array($ext, ['png','webp','gif'])) {
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $transparent);
        imagealphablending($img, true);
    }
}

function _imgFit(int $w, int $h, int $maxW, int $maxH): array {
    if ($w <= $maxW && $h <= $maxH) return [$w, $h];
    $ratio = min($maxW / $w, $maxH / $h);
    return [max(1, (int)round($w * $ratio)), max(1, (int)round($h * $ratio))];
}

/**
 * Return the thumbnail URL for an uploaded file, falling back to full if thumb missing.
 * Usage: thumbUrl('cars', $filename)  →  BASE_URL/uploads/cars/thumbs/abc.jpg
 */
function thumbUrl(string $subDir, string $filename): string {
    $thumbFile = BASE_PATH . '/uploads/' . $subDir . '/thumbs/' . $filename;
    if ($filename && file_exists($thumbFile)) {
        return BASE_URL . '/uploads/' . $subDir . '/thumbs/' . $filename;
    }
    return BASE_URL . '/uploads/' . $subDir . '/' . $filename;
}

/**
 * Internal helper to convert number to words without currency wrapper
 */
function _numberToWordsRaw($number): string {
    $hyphen      = '-';
    $conjunction = ' and ';
    $separator   = ', ';
    $dictionary  = array(
        0                   => 'zero',
        1                   => 'one',
        2                   => 'two',
        3                   => 'three',
        4                   => 'four',
        5                   => 'five',
        6                   => 'six',
        7                   => 'seven',
        8                   => 'eight',
        9                   => 'nine',
        10                  => 'ten',
        11                  => 'eleven',
        12                  => 'twelve',
        13                  => 'thirteen',
        14                  => 'fourteen',
        15                  => 'fifteen',
        16                  => 'sixteen',
        17                  => 'seventeen',
        18                  => 'eighteen',
        19                  => 'nineteen',
        20                  => 'twenty',
        30                  => 'thirty',
        40                  => 'forty',
        50                  => 'fifty',
        60                  => 'sixty',
        70                  => 'seventy',
        80                  => 'eighty',
        90                  => 'ninety',
        100                 => 'hundred',
        1000                => 'thousand',
        1000000             => 'million',
        1000000000          => 'billion',
        1000000000000       => 'trillion'
    );

    if (!is_numeric($number)) return '';
    $number = (int)$number;
    if ($number == 0) return $dictionary[0];

    $num_parts = array();
    if ($number >= 1000000000) {
        $billions = floor($number / 1000000000);
        $num_parts[] = _numberToWordsRaw($billions) . ' ' . $dictionary[1000000000];
        $number %= 1000000000;
    }
    if ($number >= 1000000) {
        $millions = floor($number / 1000000);
        $num_parts[] = _numberToWordsRaw($millions) . ' ' . $dictionary[1000000];
        $number %= 1000000;
    }
    if ($number >= 1000) {
        $thousands = floor($number / 1000);
        $num_parts[] = _numberToWordsRaw($thousands) . ' ' . $dictionary[1000];
        $number %= 1000;
    }
    if ($number >= 100) {
        $hundreds = floor($number / 100);
        $num_parts[] = $dictionary[$hundreds] . ' ' . $dictionary[100];
        $number %= 100;
    }
    if ($number > 0) {
        if (count($num_parts) > 0) $num_parts[] = trim($conjunction);
        if ($number < 21) {
            $num_parts[] = $dictionary[$number];
        } else {
            $tens = floor($number / 10) * 10;
            $units = $number % 10;
            $num_parts[] = $dictionary[$tens] . ($units ? $hyphen . $dictionary[$units] : '');
        }
    }
    return implode(' ', $num_parts);
}

/**
 * Convert number to words (KES Currency)
 */
function numberToWords($number): string {
    if (!is_numeric($number)) return '—';
    $number = (float)$number;
    $negative = $number < 0 ? 'Negative ' : '';
    $number = abs($number);

    $whole = floor($number);
    $fraction = round(($number - $whole) * 100);

    $string = $negative . ucwords(_numberToWordsRaw($whole)) . " Shillings";
    
    if ($fraction > 0) {
        $string .= " and " . ucwords(_numberToWordsRaw($fraction)) . " Cents Only";
    } else {
        $string .= " Only";
    }

    return $string;
}
