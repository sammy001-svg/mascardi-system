<?php
/**
 * HR — shared bootstrap
 *
 * Schema migrations and the staff-directory helpers every HR page depends on.
 *
 * Staff identity
 * --------------
 * The system has three separate people tables — `users` (office staff), and
 * `mechanics` / `drivers` (workshop staff who have no login). HR has to treat
 * all three as employees, so every HR record is keyed on the pair
 * (staff_type, staff_id) — the convention `leave_requests` already used. That
 * avoids merging three tables that the rest of the app depends on separately.
 */

if (!function_exists('hrStaffTypes')) {

/** The three employee sources, in the order HR reads them. */
function hrStaffTypes(): array {
    return [
        'user'     => ['label' => 'Office Staff', 'table' => 'users',     'icon' => 'fa-user-tie'],
        'mechanic' => ['label' => 'Mechanic',     'table' => 'mechanics', 'icon' => 'fa-screwdriver-wrench'],
        'driver'   => ['label' => 'Driver',       'table' => 'drivers',   'icon' => 'fa-id-card'],
    ];
}

function hrDepartments(): array {
    return ['Management','Sales','Customer Relations','Finance','Workshop',
            'Logistics','Inventory','Human Resources','Administration','Other'];
}

function hrContractTypes(): array {
    return ['permanent' => 'Permanent', 'contract' => 'Contract',
            'probation' => 'Probation', 'casual' => 'Casual', 'intern' => 'Intern'];
}

function hrDocumentTypes(): array {
    return [
        'contract'      => 'Employment Contract',
        'id_copy'       => 'National ID',
        'kra_pin'       => 'KRA PIN Certificate',
        'certificate'   => 'Academic / Professional Certificate',
        'licence'       => 'Licence / Permit',
        'good_conduct'  => 'Certificate of Good Conduct',
        'nda'           => 'NDA / Confidentiality',
        'warning'       => 'Disciplinary Letter',
        'other'         => 'Other',
    ];
}

/**
 * Idempotent. Each statement is isolated so one failure (a column that already
 * exists, a table the install has not created yet) cannot abort the rest —
 * matching how the rest of the system migrates on page load.
 */
function hrMigrate(PDO $db): void
{
    // ── users.role ────────────────────────────────────────────────────────────
    // The ENUM had drifted behind the roles the UI offers: 'hr_manager' was
    // selectable in Users → Add but was not a legal value, so with MySQL strict
    // mode off the insert silently stored ''.
    //
    // Widening it is therefore necessary — but it MUST be additive. An earlier
    // version of this migration listed the roles explicitly and omitted
    // 'super_admin', which dropped that value from the column; every account
    // using it was silently blanked, and a blank role grants nothing at all
    // (canAccess() matches no map), which stripped the super admin's entire
    // menu. Never write a literal list here: read what the column already
    // allows and add to it, so no deployment can lose a role this way again.
    try {
        $col = $db->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
        if ($col) {
            $existing = [];
            if (preg_match("/^enum\((.*)\)$/i", trim($col['Type']), $m)) {
                foreach (explode("','", trim($m[1], "'")) as $v) {
                    $v = trim($v, "' ");
                    if ($v !== '') $existing[] = $v;
                }
            }

            // Every role the application actually references.
            $required = [
                'super_admin', 'admin', 'general_manager', 'manager', 'supervisor',
                'finance_manager', 'accountant', 'cashier',
                'sales_manager', 'sales_officer', 'sales_person', 'customer_relations', 'receptionist',
                'workshop_manager', 'mechanic', 'driver',
                'inventory_manager', 'procurement_officer', 'hr_manager',
            ];

            // Union, preserving whatever the column already had first.
            $all = $existing;
            foreach ($required as $r) if (!in_array($r, $all, true)) $all[] = $r;

            if (count($all) !== count($existing)) {
                $list = implode(',', array_map(fn($v) => "'" . str_replace("'", "''", $v) . "'", $all));
                $db->exec("ALTER TABLE users MODIFY COLUMN role ENUM({$list}) NOT NULL DEFAULT 'sales_person'");
            }
        }
    } catch (\Throwable $_) {}

    // Repair accounts blanked by the non-additive widening described above.
    // 'super_admin' is the only role the application references that the bad
    // list omitted, so a blank role can only have come from that value.
    try {
        $col = $db->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
        if ($col && str_contains(strtolower($col['Type']), 'super_admin')) {
            $blank = $db->query("SELECT id, name, username FROM users WHERE role = '' OR role IS NULL")
                        ->fetchAll(PDO::FETCH_ASSOC);
            if ($blank) {
                $db->exec("UPDATE users SET role = 'super_admin' WHERE role = '' OR role IS NULL");
                foreach ($blank as $u) {
                    error_log("hrMigrate: restored blanked role to super_admin for user #{$u['id']} ({$u['username']})");
                    try {
                        logActivity('update', 'users', (int)$u['id'],
                            "Restored role to super_admin — it had been blanked by a schema change that dropped the value");
                    } catch (\Throwable $_) {}
                }
            }
        }
    } catch (\Throwable $_) {}

    // ── attendance had no way to record an office employee ────────────────────
    try {
        $col = $db->query("SHOW COLUMNS FROM attendance_records LIKE 'staff_type'")->fetch(PDO::FETCH_ASSOC);
        if ($col && !str_contains(strtolower($col['Type']), "'user'")) {
            $db->exec("ALTER TABLE attendance_records
                       MODIFY COLUMN staff_type ENUM('user','mechanic','driver') NOT NULL");
        }
    } catch (\Throwable $_) {}

    $tables = [

        // Employment record — the HR-owned facts that have no home in users /
        // mechanics / drivers. One row per employee.
        "CREATE TABLE IF NOT EXISTS hr_employees (
            id INT AUTO_INCREMENT PRIMARY KEY,
            staff_type ENUM('user','mechanic','driver') NOT NULL,
            staff_id INT NOT NULL,
            employee_no VARCHAR(30) NULL,
            job_title VARCHAR(120) NULL,
            department VARCHAR(60) NULL,
            contract_type ENUM('permanent','contract','probation','casual','intern') DEFAULT 'permanent',
            hire_date DATE NULL,
            probation_end DATE NULL,
            contract_end DATE NULL,
            exit_date DATE NULL,
            exit_reason VARCHAR(255) NULL,
            employment_status ENUM('active','probation','suspended','exited') DEFAULT 'active',
            national_id VARCHAR(30) NULL,
            kra_pin VARCHAR(30) NULL,
            nssf_no VARCHAR(30) NULL,
            nhif_no VARCHAR(30) NULL,
            bank_name VARCHAR(80) NULL,
            bank_account VARCHAR(40) NULL,
            next_of_kin VARCHAR(120) NULL,
            next_of_kin_phone VARCHAR(30) NULL,
            next_of_kin_relation VARCHAR(60) NULL,
            notes TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_hr_staff (staff_type, staff_id),
            KEY idx_hr_status (employment_status),
            KEY idx_hr_dept (department)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Contracts, IDs, certificates. expiry_date drives the renewal alerts.
        "CREATE TABLE IF NOT EXISTS hr_documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            staff_type ENUM('user','mechanic','driver') NOT NULL,
            staff_id INT NOT NULL,
            doc_type VARCHAR(40) NOT NULL DEFAULT 'other',
            title VARCHAR(160) NOT NULL,
            file_path VARCHAR(255) NULL,
            issue_date DATE NULL,
            expiry_date DATE NULL,
            notes TEXT NULL,
            uploaded_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_doc_staff (staff_type, staff_id),
            KEY idx_doc_expiry (expiry_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Salary profile. Referenced by modules/payroll/staff.php since it was
        // written, but never actually created — payroll has been dead until now.
        "CREATE TABLE IF NOT EXISTS staff_salaries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            staff_type ENUM('user','mechanic','driver') NOT NULL,
            staff_id INT NOT NULL,
            basic_salary DECIMAL(15,2) NOT NULL DEFAULT 0,
            house_allowance DECIMAL(15,2) NOT NULL DEFAULT 0,
            transport_allow DECIMAL(15,2) NOT NULL DEFAULT 0,
            effective_date DATE NULL,
            status ENUM('active','inactive') DEFAULT 'active',
            notes TEXT NULL,
            created_by INT NULL,
            updated_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_sal_staff (staff_type, staff_id),
            KEY idx_sal_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Column names follow what modules/payroll/* already reads and writes —
        // the tables were simply never created, so the module has been dead.
        "CREATE TABLE IF NOT EXISTS payroll_runs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            run_number VARCHAR(40) NULL,
            period_month TINYINT NOT NULL,
            period_year SMALLINT NOT NULL,
            working_days TINYINT NOT NULL DEFAULT 26,
            status ENUM('draft','approved','paid') DEFAULT 'draft',
            paid_at DATETIME NULL,
            total_gross DECIMAL(15,2) NOT NULL DEFAULT 0,
            total_deductions DECIMAL(15,2) NOT NULL DEFAULT 0,
            total_net DECIMAL(15,2) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_by INT NULL,
            approved_by INT NULL,
            approved_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_period (period_year, period_month),
            KEY idx_run_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS payroll_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            run_id INT NOT NULL,
            staff_type ENUM('user','mechanic','driver') NOT NULL,
            staff_id INT NOT NULL,
            staff_name VARCHAR(150) NOT NULL DEFAULT '',
            basic_salary DECIMAL(15,2) NOT NULL DEFAULT 0,
            house_allowance DECIMAL(15,2) NOT NULL DEFAULT 0,
            transport_allow DECIMAL(15,2) NOT NULL DEFAULT 0,
            other_allowance DECIMAL(15,2) NOT NULL DEFAULT 0,
            other_allow_note VARCHAR(160) NULL,
            gross_pay DECIMAL(15,2) NOT NULL DEFAULT 0,
            paye DECIMAL(15,2) NOT NULL DEFAULT 0,
            nhif DECIMAL(15,2) NOT NULL DEFAULT 0,
            nssf DECIMAL(15,2) NOT NULL DEFAULT 0,
            other_deduction DECIMAL(15,2) NOT NULL DEFAULT 0,
            other_deduct_note VARCHAR(160) NULL,
            total_deductions DECIMAL(15,2) NOT NULL DEFAULT 0,
            net_pay DECIMAL(15,2) NOT NULL DEFAULT 0,
            days_worked DECIMAL(5,1) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_item_run (run_id),
            KEY idx_item_staff (staff_type, staff_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($tables as $sql) {
        try { $db->exec($sql); } catch (\Throwable $_) {}
    }

    // Older installs created payroll_items without the office-staff type.
    foreach (['payroll_items', 'staff_salaries', 'hr_employees', 'hr_documents'] as $t) {
        try {
            $col = $db->query("SHOW COLUMNS FROM `{$t}` LIKE 'staff_type'")->fetch(PDO::FETCH_ASSOC);
            if ($col && !str_contains(strtolower($col['Type']), "'user'")) {
                $db->exec("ALTER TABLE `{$t}`
                           MODIFY COLUMN staff_type ENUM('user','mechanic','driver') NOT NULL");
            }
        } catch (\Throwable $_) {}
    }
}

/**
 * ON DUPLICATE KEY on the register needs a unique index over
 * (staff_type, staff_id, attendance_date) — without it every save appends a
 * second row for the same person and day. Most installs already ship one under
 * some name, so check for an equivalent index before adding another; blindly
 * adding leaves the table carrying two identical unique keys.
 */
function hrEnsureAttendanceKey(PDO $db): void
{
    try {
        $byKey = [];
        foreach ($db->query("SHOW INDEX FROM attendance_records")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ((int)$r['Non_unique'] === 1) continue;
            $byKey[$r['Key_name']][(int)$r['Seq_in_index']] = strtolower($r['Column_name']);
        }
        foreach ($byKey as $cols) {
            ksort($cols);
            if (array_values($cols) === ['staff_type', 'staff_id', 'attendance_date']) return; // already covered
        }
        $db->exec("ALTER TABLE attendance_records
                   ADD UNIQUE KEY uq_att_day (staff_type, staff_id, attendance_date)");
    } catch (\Throwable $_) {}
}

/** Stable key for a (type,id) pair — used as form values and array keys. */
function hrKey(string $type, int $id): string { return $type . '_' . $id; }

/** Parses a staff key back, returning null when it is not a legal pair. */
function hrParseKey(?string $key): ?array {
    if (!$key || !str_contains($key, '_')) return null;
    [$type, $id] = explode('_', $key, 2);
    if (!isset(hrStaffTypes()[$type]) || !ctype_digit($id) || (int)$id < 1) return null;
    return ['type' => $type, 'id' => (int)$id];
}

/**
 * Every employee across the three sources, merged with their HR record.
 *
 * $opts:
 *   include_exited  bool   include people whose employment has ended (default false)
 *   type            string restrict to one staff_type
 *   department      string restrict to one department
 *   search          string name / phone / employee number / job title
 */
function hrStaffDirectory(PDO $db, array $opts = []): array
{
    $includeExited = !empty($opts['include_exited']);
    $onlyType      = $opts['type']       ?? '';
    $dept          = $opts['department'] ?? '';
    $search        = trim((string)($opts['search'] ?? ''));

    // `users` carries no phone in some installs and the active flag has been
    // spelled both `status` and `is_active`. Probe rather than assume — a wrong
    // guess here fails inside the catch below and silently empties the whole
    // directory.
    $userCols = [];
    try {
        foreach ($db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $userCols[strtolower($c['Field'])] = true;
        }
    } catch (\Throwable $_) {}

    if (isset($userCols['status'])) {
        $userActive = "CASE WHEN COALESCE(status,'active') = 'active' THEN 'active' ELSE 'inactive' END";
    } elseif (isset($userCols['is_active'])) {
        $userActive = "CASE WHEN COALESCE(is_active,1) = 1 THEN 'active' ELSE 'inactive' END";
    } else {
        $userActive = "'active'";
    }
    $userPhone = isset($userCols['phone']) ? 'phone' : "''";

    $sources = [
        'user'     => "SELECT id, name, {$userPhone} AS phone, email, 'user' AS staff_type,
                              role AS source_role, {$userActive} AS source_status
                       FROM users",
        'mechanic' => "SELECT id, name, phone, email, 'mechanic' AS staff_type, specialization AS source_role,
                              COALESCE(status,'active') AS source_status
                       FROM mechanics",
        'driver'   => "SELECT id, name, phone, email, 'driver' AS staff_type, license_class AS source_role,
                              COALESCE(status,'active') AS source_status
                       FROM drivers",
    ];

    $rows = [];
    foreach ($sources as $type => $sql) {
        if ($onlyType && $onlyType !== $type) continue;
        try {
            foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $r['id']  = (int)$r['id'];
                $r['key'] = hrKey($type, $r['id']);
                $rows[$r['key']] = $r;
            }
        } catch (\Throwable $_) { /* source table absent in this install */ }
    }
    if (!$rows) return [];

    // Attach the HR record in one query rather than per person.
    try {
        foreach ($db->query("SELECT * FROM hr_employees")->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $k = hrKey($h['staff_type'], (int)$h['staff_id']);
            if (isset($rows[$k])) $rows[$k]['hr'] = $h;
        }
    } catch (\Throwable $_) {}

    try {
        foreach ($db->query("SELECT * FROM staff_salaries WHERE status='active'")->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $k = hrKey($s['staff_type'], (int)$s['staff_id']);
            if (isset($rows[$k])) $rows[$k]['salary'] = $s;
        }
    } catch (\Throwable $_) {}

    $out = [];
    foreach ($rows as $k => $r) {
        $hr = $r['hr'] ?? [];
        $r['hr']            = $hr;
        $r['salary']        = $r['salary'] ?? [];
        $r['employee_no']   = $hr['employee_no'] ?? '';
        $r['job_title']     = $hr['job_title']  ?? '';
        $r['department']    = $hr['department'] ?? '';
        // No HR record yet → fall back to whether the source table calls them
        // active, so new hires appear immediately instead of after data entry.
        $r['emp_status']    = $hr['employment_status']
                              ?? ($r['source_status'] === 'active' ? 'active' : 'exited');
        $r['contract_type'] = $hr['contract_type'] ?? '';
        $r['hire_date']     = $hr['hire_date']     ?? null;
        $r['has_hr_record'] = !empty($hr);
        $r['gross']         = $r['salary']
            ? (float)$r['salary']['basic_salary'] + (float)$r['salary']['house_allowance']
              + (float)$r['salary']['transport_allow']
            : 0.0;

        if (!$includeExited && $r['emp_status'] === 'exited') continue;
        if ($dept && $r['department'] !== $dept) continue;
        if ($search !== '') {
            $hay = strtolower($r['name'] . ' ' . $r['phone'] . ' ' . $r['employee_no']
                              . ' ' . $r['job_title'] . ' ' . $r['department']);
            if (!str_contains($hay, strtolower($search))) continue;
        }
        $out[$k] = $r;
    }

    uasort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $out;
}

/** One employee, or null. Same shape as a hrStaffDirectory() row. */
function hrStaffMember(PDO $db, string $type, int $id): ?array
{
    $all = hrStaffDirectory($db, ['type' => $type, 'include_exited' => true]);
    return $all[hrKey($type, $id)] ?? null;
}

/** Initials for the avatar chips, e.g. "Jane Wanjiku Otieno" → "JO". */
function hrInitials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $parts = array_values(array_filter($parts));
    if (!$parts) return '?';
    $first = mb_substr($parts[0], 0, 1);
    $last  = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';
    return mb_strtoupper($first . $last);
}

/** Deterministic avatar colour so the same person is always the same hue. */
function hrAvatarColor(string $key): string
{
    $palette = ['#2563eb','#7c3aed','#db2777','#dc2626','#ea580c',
                '#ca8a04','#16a34a','#0891b2','#4f46e5','#be123c'];
    return $palette[abs(crc32($key)) % count($palette)];
}

function hrEmploymentBadge(string $status): array
{
    return [
        'active'    => ['Active',    '#16a34a'],
        'probation' => ['Probation', '#ca8a04'],
        'suspended' => ['Suspended', '#dc2626'],
        'exited'    => ['Exited',    '#64748b'],
    ][$status] ?? ['Active', '#16a34a'];
}

} // function_exists guard
