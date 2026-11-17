<?php
session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$activePage = 'audit_logs.php';
$adminId = (int)($_SESSION['user_id'] ?? 0);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Ensure audit_logs table exists
@mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `audit_logs` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `actor_id` int(11) DEFAULT NULL,
      `actor_role` varchar(50) DEFAULT NULL,
      `action` varchar(100) NOT NULL,
      `target_id` int(11) DEFAULT NULL,
      `target_type` varchar(50) DEFAULT NULL,
      `ip_address` varchar(45) DEFAULT NULL,
      `user_agent` varchar(255) DEFAULT NULL,
      `details` text DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `actor_id` (`actor_id`),
      KEY `action` (`action`),
      KEY `created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $search = trim($_GET['search'] ?? '');
    $roleFilter = trim($_GET['role'] ?? '');
    $catFilter = trim($_GET['category'] ?? '');

    $where = ["1=1"];
    $params = [];
    $types = "";

    if ($roleFilter !== '') {
        $where[] = "a.actor_role = ?";
        $params[] = $roleFilter;
        $types .= "s";
    }
    if ($catFilter !== '') {
        if ($catFilter === 'user_mgmt') {
            $where[] = "a.action IN ('APPROVE_FACULTY', 'STATUS_CHANGE', 'PASSWORD_RESET', 'CREATE_USER', 'DELETE_USER', 'ASSIGN_DIVISION')";
        } elseif ($catFilter === 'internship') {
            $where[] = "a.action IN ('APPROVE_INTERNSHIP', 'REJECT_INTERNSHIP', 'SUBMIT_EVALUATION', 'UPDATE_WEEKLY')";
        } elseif ($catFilter === 'system') {
            $where[] = "a.action IN ('UPDATE_SETTINGS', 'DISPATCH_BROADCAST', 'GENERATE_REPORT', 'UPLOAD_RESOURCE', 'PIN_POST')";
        } elseif ($catFilter === 'auth') {
            $where[] = "a.action IN ('SYSTEM_LOGIN', 'UNLOCK_ACCOUNT')";
        }
    }
    if ($search !== '') {
        $where[] = "(u.full_name LIKE ? OR u.email LIKE ? OR a.action LIKE ? OR a.target_type LIKE ? OR a.ip_address LIKE ?)";
        $sTerm = "%$search%";
        $params = array_merge($params, [$sTerm, $sTerm, $sTerm, $sTerm, $sTerm]);
        $types .= "sssss";
    }

    $whereClause = implode(" AND ", $where);
    $csvSql = "
        SELECT a.id, a.created_at, a.actor_role, COALESCE(u.full_name, 'System / External') as actor_name, 
               COALESCE(u.email, 'N/A') as actor_email, a.action, a.target_type, a.target_id, a.ip_address, a.details
        FROM audit_logs a
        LEFT JOIN users u ON a.actor_id = u.id
        WHERE $whereClause
        ORDER BY a.created_at DESC
    ";

    $stmt = mysqli_prepare($conn, $csvSql);
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=internhub_audit_logs_' . date('Ymd_His') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Log ID', 'Timestamp', 'Actor Role', 'Actor Name', 'Actor Email', 'Action', 'Target Type', 'Target ID', 'IP Address', 'Payload Details']);
    while ($row = mysqli_fetch_assoc($res)) {
        fputcsv($output, [
            $row['id'],
            $row['created_at'],
            strtoupper($row['actor_role']),
            $row['actor_name'],
            $row['actor_email'],
            $row['action'],
            $row['target_type'],
            $row['target_id'],
            $row['ip_address'],
            $row['details']
        ]);
    }
    fclose($output);
    exit();
}

// Handle Unlock / Clear Failed Login Attempts
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_attempts') {
    if (hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $email = trim($_POST['email'] ?? '');
        if ($email !== '') {
            $stmt = mysqli_prepare($conn, "DELETE FROM login_attempts WHERE email = ?");
            mysqli_stmt_bind_param($stmt, "s", $email);
            mysqli_stmt_execute($stmt);

            // Log this administrative action
            $det = json_encode(['unlocked_email' => $email, 'cleared_by' => 'Administrator']);
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250);
            $logStmt = mysqli_prepare($conn, "INSERT INTO audit_logs (actor_id, actor_role, action, target_type, ip_address, user_agent, details) VALUES (?, 'admin', 'UNLOCK_ACCOUNT', 'login_attempts', ?, ?, ?)");
            mysqli_stmt_bind_param($logStmt, "isss", $adminId, $ip, $ua, $det);
            mysqli_stmt_execute($logStmt);

            $flash = ['type' => 'success', 'msg' => "Login lock cleared for account: $email"];
        }
    }
}

// Metrics
$total_audit_events = 0;
$admin_actions_count = 0;
$successful_logins_count = 0;
$locked_accounts_count = 0;

$r = mysqli_query($conn, "SELECT COUNT(*) as c FROM audit_logs");
if ($r && $row = mysqli_fetch_assoc($r)) $total_audit_events = (int)$row['c'];

$r = mysqli_query($conn, "SELECT COUNT(*) as c FROM audit_logs WHERE actor_role = 'admin'");
if ($r && $row = mysqli_fetch_assoc($r)) $admin_actions_count = (int)$row['c'];

$r = mysqli_query($conn, "SELECT COUNT(*) as c FROM login_logs WHERE status = 'Success'");
if ($r && $row = mysqli_fetch_assoc($r)) $successful_logins_count = (int)$row['c'];

$r = mysqli_query($conn, "SELECT COUNT(*) as c FROM login_attempts WHERE attempts >= 3");
if ($r && $row = mysqli_fetch_assoc($r)) $locked_accounts_count = (int)$row['c'];

// Filter parameters
$currentTab = $_GET['tab'] ?? 'trail'; // 'trail' or 'auth'
$search = trim($_GET['search'] ?? '');
$roleFilter = trim($_GET['role'] ?? '');
$catFilter = trim($_GET['category'] ?? '');
$dateFilter = trim($_GET['date_range'] ?? '');

// Build query for Audit Trail
$where = ["1=1"];
$params = [];
$types = "";

if ($roleFilter !== '') {
    $where[] = "a.actor_role = ?";
    $params[] = $roleFilter;
    $types .= "s";
}
if ($catFilter !== '') {
    if ($catFilter === 'user_mgmt') {
        $where[] = "a.action IN ('APPROVE_FACULTY', 'STATUS_CHANGE', 'PASSWORD_RESET', 'CREATE_USER', 'DELETE_USER', 'ASSIGN_DIVISION')";
    } elseif ($catFilter === 'internship') {
        $where[] = "a.action IN ('APPROVE_INTERNSHIP', 'REJECT_INTERNSHIP', 'SUBMIT_EVALUATION', 'UPDATE_WEEKLY')";
    } elseif ($catFilter === 'system') {
        $where[] = "a.action IN ('UPDATE_SETTINGS', 'DISPATCH_BROADCAST', 'GENERATE_REPORT', 'UPLOAD_RESOURCE', 'PIN_POST')";
    } elseif ($catFilter === 'auth') {
        $where[] = "a.action IN ('SYSTEM_LOGIN', 'UNLOCK_ACCOUNT')";
    }
}
if ($dateFilter === 'today') {
    $where[] = "DATE(a.created_at) = CURDATE()";
} elseif ($dateFilter === '7days') {
    $where[] = "a.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($dateFilter === '30days') {
    $where[] = "a.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
}
if ($search !== '') {
    $where[] = "(u.full_name LIKE ? OR u.email LIKE ? OR a.action LIKE ? OR a.target_type LIKE ? OR a.ip_address LIKE ?)";
    $sTerm = "%$search%";
    $params = array_merge($params, [$sTerm, $sTerm, $sTerm, $sTerm, $sTerm]);
    $types .= "sssss";
}

$whereClause = implode(" AND ", $where);
$trailSql = "
    SELECT a.*, COALESCE(u.full_name, 'System / External') as actor_name, COALESCE(u.email, 'N/A') as actor_email
    FROM audit_logs a
    LEFT JOIN users u ON a.actor_id = u.id
    WHERE $whereClause
    ORDER BY a.created_at DESC
    LIMIT 100
";

$stmt = mysqli_prepare($conn, $trailSql);
if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$trailResult = mysqli_stmt_get_result($stmt);
$auditLogs = [];
while ($row = mysqli_fetch_assoc($trailResult)) {
    $auditLogs[] = $row;
}

// Query for Authentication & Logins
$loginLogs = [];
$loginSql = "
    SELECT l.*, u.full_name
    FROM login_logs l
    LEFT JOIN users u ON l.user_id = u.id
    ORDER BY l.login_time DESC
    LIMIT 50
";
$r = mysqli_query($conn, $loginSql);
if ($r) {
    while ($row = mysqli_fetch_assoc($r)) {
        $loginLogs[] = $row;
    }
}

// Query for Locked Accounts
$lockedAccounts = [];
$lockSql = "SELECT * FROM login_attempts WHERE attempts >= 3 ORDER BY last_attempt DESC";
$r = mysqli_query($conn, $lockSql);
if ($r) {
    while ($row = mysqli_fetch_assoc($r)) {
        $lockedAccounts[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Audit & Security Logs — InternHub Admin</title>
    <?php include __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
        .audit-tabs {
            display: flex;
            gap: 10px;
            border-bottom: 2px solid #e9e5f5;
            margin-bottom: 24px;
        }
        .audit-tab-btn {
            background: none;
            border: none;
            padding: 12px 20px;
            font-size: 0.95rem;
            font-weight: 600;
            color: #6b7280;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .audit-tab-btn:hover {
            color: #3a104f;
        }
        .audit-tab-btn.active {
            color: #3a104f;
            border-bottom-color: #3a104f;
            font-weight: 700;
        }
        .audit-tab-badge {
            font-size: 0.72rem;
            padding: 2px 8px;
            border-radius: 999px;
            background: #ede9fe;
            color: #5b21b6;
        }
        .action-badge {
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 6px;
            letter-spacing: 0.03em;
            display: inline-block;
            font-family: monospace;
        }
        .badge-purple { background: #f3e8ff; color: #6b21a8; border: 1px solid #d8b4fe; }
        .badge-green { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .badge-amber { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .badge-rose { background: #ffe4e6; color: #be123c; border: 1px solid #fecdd3; }
        .badge-blue { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }

        .actor-pill {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .actor-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3a104f, #6b21a8);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: 700;
            flex-shrink: 0;
        }
        .actor-name {
            font-weight: 600;
            font-size: 0.88rem;
            color: #1f2937;
            line-height: 1.2;
        }
        .actor-sub {
            font-size: 0.75rem;
            color: #6b7280;
        }
        .role-tag {
            font-size: 0.68rem;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .role-admin { background: #fae8ff; color: #86198f; }
        .role-faculty { background: #eff6ff; color: #1d4ed8; }
        .role-student { background: #f0fdf4; color: #15803d; }
        .role-system { background: #f3f4f6; color: #374151; }

        .ip-badge {
            font-family: monospace;
            font-size: 0.78rem;
            background: #f8fafc;
            color: #475569;
            padding: 3px 7px;
            border-radius: 4px;
            border: 1px solid #e2e8f0;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

    <div class="admin-content-inner">

        <!-- Breadcrumb & Top Controls -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0" style="font-size: 0.82rem;">
                    <li class="breadcrumb-item"><a href="dashboard.php" style="color: #6b7280; text-decoration: none;">Dashboard</a></li>
                    <li class="breadcrumb-item" style="color: #6b7280;">System</li>
                    <li class="breadcrumb-item active" aria-current="page" style="color: #3a104f; font-weight: 600;">Audit & Logs</li>
                </ol>
            </nav>
            <div class="d-flex gap-2">
                <a href="audit_logs.php?export=csv&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($roleFilter); ?>&category=<?php echo urlencode($catFilter); ?>" class="btn btn-sm btn-outline-secondary" style="border-radius: 8px; font-weight: 600; font-size: 0.82rem;">
                    <i class="fa-solid fa-file-csv me-1 text-success"></i> Export Audit Log (CSV)
                </a>
                <a href="audit_logs.php" class="btn btn-sm btn-outline-secondary" style="border-radius: 8px; font-weight: 600; font-size: 0.82rem;">
                    <i class="fa-solid fa-rotate me-1"></i> Refresh
                </a>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type']; ?> alert-dismissible fade show mb-4" role="alert" style="border-radius: 10px; font-size: 0.9rem;">
                <i class="fa-solid fa-circle-check me-2"></i><?php echo h($flash['msg']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-end mb-4">
            <div>
                <h1 style="font-family: 'Plus Jakarta Sans', sans-serif; font-size: 1.65rem; font-weight: 700; color: #1e1b4b; margin-bottom: 4px;">
                    <i class="fa-solid fa-clipboard-check text-purple me-2" style="color: #3a104f;"></i>Audit & Security Logs
                </h1>
                <p style="color: #6b7280; font-size: 0.88rem; margin-bottom: 0;">
                    Real-time administrative audit trail, authentication logs, and security telemetry across MIT-ADT University.
                </p>
            </div>
        </div>

        <!-- 4 KPI Summary Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="admin-card p-3 d-flex align-items-center gap-3">
                    <div style="width: 48px; height: 48px; border-radius: 12px; background: #eddff7; color: #3a104f; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                        <i class="fa-solid fa-clipboard-check"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.78rem; font-weight: 600; color: #6b7280; text-transform: uppercase;">Total Audit Events</div>
                        <div style="font-size: 1.45rem; font-weight: 800; color: #1e1b4b; line-height: 1.2;"><?php echo number_format($total_audit_events); ?></div>
                        <div style="font-size: 0.72rem; color: #059669; font-weight: 600;"><i class="fa-solid fa-shield-halved me-1"></i>Immutable Ledger</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="admin-card p-3 d-flex align-items-center gap-3">
                    <div style="width: 48px; height: 48px; border-radius: 12px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                        <i class="fa-solid fa-user-shield"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.78rem; font-weight: 600; color: #6b7280; text-transform: uppercase;">Admin Actions</div>
                        <div style="font-size: 1.45rem; font-weight: 800; color: #1e1b4b; line-height: 1.2;"><?php echo number_format($admin_actions_count); ?></div>
                        <div style="font-size: 0.72rem; color: #6b7280;">Super admin operations</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="admin-card p-3 d-flex align-items-center gap-3">
                    <div style="width: 48px; height: 48px; border-radius: 12px; background: #dcfce7; color: #15803d; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                        <i class="fa-solid fa-key"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.78rem; font-weight: 600; color: #6b7280; text-transform: uppercase;">Successful Logins</div>
                        <div style="font-size: 1.45rem; font-weight: 800; color: #1e1b4b; line-height: 1.2;"><?php echo number_format($successful_logins_count); ?></div>
                        <div style="font-size: 0.72rem; color: #15803d; font-weight: 600;">Verified sessions</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="admin-card p-3 d-flex align-items-center gap-3">
                    <div style="width: 48px; height: 48px; border-radius: 12px; background: <?php echo $locked_accounts_count > 0 ? '#ffe4e6' : '#f3f4f6'; ?>; color: <?php echo $locked_accounts_count > 0 ? '#e11d48' : '#6b7280'; ?>; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.78rem; font-weight: 600; color: #6b7280; text-transform: uppercase;">Locked Accounts</div>
                        <div style="font-size: 1.45rem; font-weight: 800; color: <?php echo $locked_accounts_count > 0 ? '#e11d48' : '#1e1b4b'; ?>; line-height: 1.2;"><?php echo number_format($locked_accounts_count); ?></div>
                        <div style="font-size: 0.72rem; color: <?php echo $locked_accounts_count > 0 ? '#e11d48' : '#6b7280'; ?>; font-weight: 600;">
                            <?php echo $locked_accounts_count > 0 ? 'Exceeded 3 attempts' : 'Normal parameters'; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <div class="audit-tabs">
            <a href="audit_logs.php?tab=trail" class="audit-tab-btn <?php echo $currentTab === 'trail' ? 'active' : ''; ?>">
                <i class="fa-solid fa-list-check"></i> System Audit Trail
                <span class="audit-tab-badge"><?php echo number_format(count($auditLogs)); ?></span>
            </a>
            <a href="audit_logs.php?tab=auth" class="audit-tab-btn <?php echo $currentTab === 'auth' ? 'active' : ''; ?>">
                <i class="fa-solid fa-shield-virus"></i> Authentication & Access Security
                <?php if ($locked_accounts_count > 0): ?>
                    <span class="badge bg-danger rounded-pill" style="font-size: 0.7rem;"><?php echo $locked_accounts_count; ?> alert</span>
                <?php else: ?>
                    <span class="audit-tab-badge"><?php echo number_format(count($loginLogs)); ?></span>
                <?php endif; ?>
            </a>
        </div>

        <?php if ($currentTab === 'trail'): ?>
            <!-- Filter Bar -->
            <div class="admin-card p-3 mb-4">
                <form method="GET" action="audit_logs.php" class="row g-2 align-items-center">
                    <input type="hidden" name="tab" value="trail">
                    <div class="col-md-4">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white border-end-0" style="border-radius: 8px 0 0 8px;"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                            <input type="text" name="search" class="form-control border-start-0" placeholder="Search actor, action, target, IP..." value="<?php echo h($search); ?>" style="border-radius: 0 8px 8px 0; font-size: 0.85rem;">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <select name="role" class="form-select form-select-sm" style="border-radius: 8px; font-size: 0.85rem;">
                            <option value="">All Roles</option>
                            <option value="admin" <?php echo $roleFilter === 'admin' ? 'selected' : ''; ?>>Administrators</option>
                            <option value="faculty" <?php echo $roleFilter === 'faculty' ? 'selected' : ''; ?>>Faculty</option>
                            <option value="student" <?php echo $roleFilter === 'student' ? 'selected' : ''; ?>>Students</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="category" class="form-select form-select-sm" style="border-radius: 8px; font-size: 0.85rem;">
                            <option value="">All Categories</option>
                            <option value="user_mgmt" <?php echo $catFilter === 'user_mgmt' ? 'selected' : ''; ?>>User Management</option>
                            <option value="internship" <?php echo $catFilter === 'internship' ? 'selected' : ''; ?>>Internship Oversight</option>
                            <option value="system" <?php echo $catFilter === 'system' ? 'selected' : ''; ?>>System & Content</option>
                            <option value="auth" <?php echo $catFilter === 'auth' ? 'selected' : ''; ?>>Authentication</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="date_range" class="form-select form-select-sm" style="border-radius: 8px; font-size: 0.85rem;">
                            <option value="">All Time</option>
                            <option value="today" <?php echo $dateFilter === 'today' ? 'selected' : ''; ?>>Today</option>
                            <option value="7days" <?php echo $dateFilter === '7days' ? 'selected' : ''; ?>>Last 7 Days</option>
                            <option value="30days" <?php echo $dateFilter === '30days' ? 'selected' : ''; ?>>Last 30 Days</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary w-100" style="background: #3a104f; border-color: #3a104f; border-radius: 8px; font-weight: 600;">
                            Filter
                        </button>
                        <?php if ($search !== '' || $roleFilter !== '' || $catFilter !== '' || $dateFilter !== ''): ?>
                            <a href="audit_logs.php?tab=trail" class="btn btn-sm btn-outline-secondary" style="border-radius: 8px;">Reset</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Audit Trail Table -->
            <div class="admin-card p-0 overflow-hidden mb-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
                        <thead style="background: #faf8fd; border-bottom: 1px solid #ebdff2;">
                            <tr style="color: #6b7280; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em;">
                                <th style="padding: 12px 18px;">Timestamp</th>
                                <th>Actor</th>
                                <th>Action Event</th>
                                <th>Target Entity</th>
                                <th>Origin IP</th>
                                <th class="text-end" style="padding-right: 18px;">Payload</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($auditLogs)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-5">
                                        <i class="fa-solid fa-clipboard-question text-muted mb-2" style="font-size: 2.5rem; color: #cbd5e1;"></i>
                                        <div style="font-weight: 600; color: #4b5563;">No audit records match the current filters.</div>
                                        <div style="font-size: 0.8rem; color: #9ca3af;">Try clearing filters or search keywords.</div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($auditLogs as $log): 
                                    $action = $log['action'];
                                    $badgeClass = 'badge-purple';
                                    if (in_array($action, ['APPROVE_FACULTY', 'APPROVE_INTERNSHIP', 'UNLOCK_ACCOUNT'])) {
                                        $badgeClass = 'badge-green';
                                    } elseif (in_array($action, ['STATUS_CHANGE', 'PASSWORD_RESET'])) {
                                        $badgeClass = 'badge-amber';
                                    } elseif (in_array($action, ['REJECT_INTERNSHIP', 'DELETE_USER'])) {
                                        $badgeClass = 'badge-rose';
                                    } elseif (in_array($action, ['SYSTEM_LOGIN'])) {
                                        $badgeClass = 'badge-blue';
                                    }

                                    $roleTagClass = 'role-system';
                                    if ($log['actor_role'] === 'admin') $roleTagClass = 'role-admin';
                                    elseif ($log['actor_role'] === 'faculty') $roleTagClass = 'role-faculty';
                                    elseif ($log['actor_role'] === 'student') $roleTagClass = 'role-student';

                                    $initials = 'SY';
                                    if (!empty($log['actor_name'])) {
                                        $parts = explode(' ', trim($log['actor_name']));
                                        $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
                                    }
                                    $dateStr = date('M d, Y h:i A', strtotime($log['created_at']));
                                ?>
                                    <tr>
                                        <td style="padding: 14px 18px; white-space: nowrap;">
                                            <div style="font-weight: 600; color: #1e1b4b;"><?php echo h($dateStr); ?></div>
                                            <div style="font-size: 0.72rem; color: #9ca3af;">Log #<?php echo $log['id']; ?></div>
                                        </td>
                                        <td>
                                            <div class="actor-pill">
                                                <div class="actor-avatar"><?php echo h($initials); ?></div>
                                                <div>
                                                    <div class="actor-name">
                                                        <?php echo h($log['actor_name']); ?>
                                                        <span class="role-tag <?php echo $roleTagClass; ?> ms-1"><?php echo h($log['actor_role']); ?></span>
                                                    </div>
                                                    <div class="actor-sub"><?php echo h($log['actor_email']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="action-badge <?php echo $badgeClass; ?>"><?php echo h($action); ?></span>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600; color: #374151; font-size: 0.82rem;">
                                                <?php echo h($log['target_type'] ?: 'N/A'); ?>
                                            </div>
                                            <?php if ($log['target_id']): ?>
                                                <div style="font-size: 0.72rem; color: #6b7280;">ID: <?php echo (int)$log['target_id']; ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="ip-badge"><i class="fa-solid fa-network-wired me-1 text-muted"></i><?php echo h($log['ip_address'] ?: '127.0.0.1'); ?></span>
                                        </td>
                                        <td class="text-end" style="padding-right: 18px;">
                                            <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" style="border-radius: 6px; font-size: 0.78rem;" 
                                                    onclick="viewPayloadModal(<?php echo htmlspecialchars(json_encode([
                                                        'id' => $log['id'],
                                                        'time' => $dateStr,
                                                        'actor' => $log['actor_name'],
                                                        'role' => $log['actor_role'],
                                                        'action' => $log['action'],
                                                        'target' => ($log['target_type'] ?? '') . ' #' . ($log['target_id'] ?? ''),
                                                        'ip' => $log['ip_address'],
                                                        'user_agent' => $log['user_agent'],
                                                        'details' => json_decode($log['details'], true) ?: $log['details']
                                                    ]), ENT_QUOTES, 'UTF-8'); ?>)">
                                                <i class="fa-solid fa-code me-1 text-primary"></i> Details
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($currentTab === 'auth'): ?>

            <!-- Locked Accounts Section if any -->
            <?php if (!empty($lockedAccounts)): ?>
                <div class="admin-card p-3 mb-4" style="border-left: 4px solid #e11d48;">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h5 style="font-size: 1rem; font-weight: 700; color: #9f1239; margin-bottom: 2px;">
                                <i class="fa-solid fa-lock me-2"></i>Accounts with Failed Sign-in Threshold
                            </h5>
                            <p style="color: #6b7280; font-size: 0.82rem; margin-bottom: 0;">
                                Accounts that exceeded the maximum 3 failed attempts and may be locked or undergoing credential attacks.
                            </p>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle table-sm mb-0" style="font-size: 0.85rem;">
                            <thead class="table-light">
                                <tr>
                                    <th>Target Account Email</th>
                                    <th>Failed Attempts</th>
                                    <th>Last Attempt Timestamp</th>
                                    <th>Security Status</th>
                                    <th class="text-end">Administrative Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($lockedAccounts as $lock): ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 700; color: #1e1b4b;"><?php echo h($lock['email']); ?></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-danger rounded-pill px-2"><?php echo (int)$lock['attempts']; ?> attempts</span>
                                        </td>
                                        <td style="color: #4b5563;">
                                            <?php echo date('M d, Y h:i A', strtotime($lock['last_attempt'])); ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                                                <i class="fa-solid fa-ban me-1"></i>Locked
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <form method="POST" action="audit_logs.php" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="action" value="clear_attempts">
                                                <input type="hidden" name="email" value="<?php echo h($lock['email']); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success" style="border-radius: 6px; font-weight: 600; font-size: 0.78rem;">
                                                    <i class="fa-solid fa-unlock me-1"></i> Reset Attempts & Unlock
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Recent Authentication Logins -->
            <div class="admin-card p-0 overflow-hidden mb-4">
                <div class="p-3 border-bottom d-flex justify-content-between align-items-center" style="background: #faf8fd;">
                    <div>
                        <h5 style="font-size: 0.95rem; font-weight: 700; color: #1e1b4b; margin-bottom: 2px;">
                            <i class="fa-solid fa-key me-2 text-primary"></i>Recent Authentication Events
                        </h5>
                        <div style="font-size: 0.78rem; color: #6b7280;">Audit trail of user logins and authentication results across all portals.</div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
                        <thead style="background: #faf8fd; border-bottom: 1px solid #ebdff2;">
                            <tr style="color: #6b7280; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em;">
                                <th style="padding: 12px 18px;">Login Timestamp</th>
                                <th>User Account</th>
                                <th>Authentication Status</th>
                                <th>Client IP Address</th>
                                <th class="text-end" style="padding-right: 18px;">Security Level</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($loginLogs)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5 text-muted">
                                        No recent login logs found in database.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($loginLogs as $ll): 
                                    $isSuccess = strtolower($ll['status']) === 'success';
                                    $timeStr = date('M d, Y h:i A', strtotime($ll['login_time']));
                                ?>
                                    <tr>
                                        <td style="padding: 14px 18px; font-weight: 600; color: #1e1b4b;">
                                            <?php echo h($timeStr); ?>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600; color: #1e1b4b;">
                                                <?php echo h($ll['full_name'] ?: $ll['email']); ?>
                                            </div>
                                            <div style="font-size: 0.75rem; color: #6b7280;"><?php echo h($ll['email']); ?></div>
                                        </td>
                                        <td>
                                            <?php if ($isSuccess): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                                    <i class="fa-solid fa-circle-check me-1"></i> Success
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">
                                                    <i class="fa-solid fa-circle-xmark me-1"></i> Failed
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="ip-badge"><i class="fa-solid fa-network-wired me-1 text-muted"></i><?php echo h($ll['ip_address']); ?></span>
                                        </td>
                                        <td class="text-end" style="padding-right: 18px;">
                                            <span class="badge bg-light text-secondary border">TLS 1.3 / Verified</span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php endif; ?>

    </div>

    <!-- Payload Details Modal -->
    <div class="modal fade" id="payloadModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content" style="border-radius: 14px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.15);">
                <div class="modal-header" style="border-bottom: 1px solid #f1f1f4; padding: 16px 22px;">
                    <div>
                        <h5 class="modal-title" style="font-size: 1.1rem; font-weight: 700; color: #1e1b4b;" id="modalTitle">Audit Event Inspection</h5>
                        <div class="text-muted" style="font-size: 0.78rem;" id="modalSubtitle">Event Telemetry</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="padding: 22px;">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <div class="p-2 border rounded bg-light" style="font-size: 0.82rem;">
                                <div class="text-muted" style="font-size: 0.72rem; text-transform: uppercase;">Actor</div>
                                <div class="fw-bold" id="modalActor">-</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-2 border rounded bg-light" style="font-size: 0.82rem;">
                                <div class="text-muted" style="font-size: 0.72rem; text-transform: uppercase;">Origin IP & User Agent</div>
                                <div class="fw-bold text-truncate" id="modalOrigin">-</div>
                            </div>
                        </div>
                    </div>
                    <div>
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label mb-0" style="font-size: 0.82rem; font-weight: 600; color: #4b5563;">Structured Event Details (JSON)</label>
                        </div>
                        <pre id="modalJson" style="background: #1e1e24; color: #e2e8f0; border-radius: 8px; padding: 14px; font-size: 0.82rem; max-height: 280px; overflow-y: auto; margin-bottom: 0;"></pre>
                    </div>
                </div>
                <div class="modal-footer" style="border-top: 1px solid #f1f1f4; padding: 12px 22px;">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal" style="border-radius: 8px;">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Layout Close -->
    </main>
    </div>

    <?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>
    <script>
        function viewPayloadModal(data) {
            document.getElementById('modalTitle').textContent = 'Audit Event #' + data.id + ' — ' + data.action;
            document.getElementById('modalSubtitle').textContent = data.time + ' • Target: ' + data.target;
            document.getElementById('modalActor').textContent = data.actor + ' (' + data.role.toUpperCase() + ')';
            document.getElementById('modalOrigin').textContent = (data.ip || '127.0.0.1') + ' • ' + (data.user_agent || 'Browser');
            
            var formatted = '';
            if (typeof data.details === 'object' && data.details !== null) {
                formatted = JSON.stringify(data.details, null, 2);
            } else {
                formatted = String(data.details || 'No structured details provided.');
            }
            document.getElementById('modalJson').textContent = formatted;

            var modal = new bootstrap.Modal(document.getElementById('payloadModal'));
            modal.show();
        }
    </script>
</body>
</html>
