<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/mail_service.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$activePage = 'user_management.php';
$adminId = (int)($_SESSION['user_id'] ?? 0);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$successMsg = '';
$errorMsg = '';

// -------------------------------------------------------------
// POST ACTIONS (Add, Edit, Reset Password, Status Toggle, Delete, Faculty Approval)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $postedCsrf)) {
        $errorMsg = 'Security validation failed (Invalid CSRF token).';
    } else {
        $action = trim($_POST['action'] ?? '');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        // 1. ADD USER
        if ($action === 'add_user') {
            $role = in_array($_POST['role'] ?? '', ['student', 'faculty', 'admin']) ? $_POST['role'] : 'student';
            $full_name = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $pwd = $_POST['password'] ?? '';
            $department = trim($_POST['department'] ?? 'Information Technology');
            $division = trim($_POST['division'] ?? '');
            $enrollment_no = trim($_POST['enrollment_no'] ?? '');
            $phone = trim($_POST['phone'] ?? '');

            if ($full_name === '' || $email === '' || $pwd === '') {
                $errorMsg = 'Full name, email, and password are required.';
            } else {
                // Check if email already exists
                $chk = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $chk->bind_param("s", $email);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    $errorMsg = 'A user with this email address already exists.';
                } else {
                    $hash = password_hash($pwd, PASSWORD_DEFAULT);
                    $ins = $conn->prepare("
                        INSERT INTO users (full_name, email, password, role, account_status, email_verified, department, division, enrollment_no, phone, created_at)
                        VALUES (?, ?, ?, ?, 'active', 1, ?, ?, ?, ?, NOW())
                    ");
                    $ins->bind_param("ssssssss", $full_name, $email, $hash, $role, $department, $division, $enrollment_no, $phone);
                    if ($ins->execute()) {
                        $newId = $ins->insert_id;
                        $successMsg = "User <strong>" . h($full_name) . "</strong> created successfully as " . ucfirst($role) . ".";
                        // Audit Log
                        $aud = $conn->prepare("INSERT INTO audit_logs (actor_id, actor_role, action, target_id, target_type, ip_address, user_agent, details) VALUES (?, 'admin', 'create_user', ?, 'user', ?, ?, ?)");
                        $dt = json_encode(['email' => $email, 'role' => $role]);
                        $aud->bind_param("iisss", $adminId, $newId, $ip, $ua, $dt);
                        $aud->execute();
                    } else {
                        $errorMsg = 'Database error: ' . $conn->error;
                    }
                }
            }
        }

        // 2. EDIT USER
        elseif ($action === 'edit_user') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $full_name = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $department = trim($_POST['department'] ?? '');
            $division = trim($_POST['division'] ?? '');
            $enrollment_no = trim($_POST['enrollment_no'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $status = in_array($_POST['account_status'] ?? '', ['active', 'suspended', 'pending_approval', 'rejected']) ? $_POST['account_status'] : 'active';

            if ($userId > 0 && $full_name !== '' && $email !== '') {
                $upd = $conn->prepare("
                    UPDATE users 
                    SET full_name = ?, email = ?, department = ?, division = ?, enrollment_no = ?, phone = ?, account_status = ?
                    WHERE id = ?
                ");
                $upd->bind_param("sssssssi", $full_name, $email, $department, $division, $enrollment_no, $phone, $status, $userId);
                if ($upd->execute()) {
                    $successMsg = "User <strong>" . h($full_name) . "</strong> updated successfully.";
                    $aud = $conn->prepare("INSERT INTO audit_logs (actor_id, actor_role, action, target_id, target_type, ip_address, user_agent, details) VALUES (?, 'admin', 'update_user', ?, 'user', ?, ?, ?)");
                    $dt = json_encode(['updated_fields' => ['name' => $full_name, 'status' => $status]]);
                    $aud->bind_param("iisss", $adminId, $userId, $ip, $ua, $dt);
                    $aud->execute();
                } else {
                    $errorMsg = 'Error updating user: ' . $conn->error;
                }
            }
        }

        // 3. RESET PASSWORD
        elseif ($action === 'reset_password') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $newPwd = $_POST['new_password'] ?? '';
            if ($userId > 0 && strlen($newPwd) >= 6) {
                $hash = password_hash($newPwd, PASSWORD_DEFAULT);
                $upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $upd->bind_param("si", $hash, $userId);
                if ($upd->execute()) {
                    $successMsg = "Password reset successfully for user #$userId.";
                    $aud = $conn->prepare("INSERT INTO audit_logs (actor_id, actor_role, action, target_id, target_type, ip_address, user_agent, details) VALUES (?, 'admin', 'reset_password', ?, 'user', ?, ?, ?)");
                    $dt = json_encode(['target_user' => $userId]);
                    $aud->bind_param("iisss", $adminId, $userId, $ip, $ua, $dt);
                    $aud->execute();
                }
            } else {
                $errorMsg = 'Password must be at least 6 characters.';
            }
        }

        // 4. TOGGLE STATUS (Suspend / Activate)
        elseif ($action === 'toggle_status') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $newStatus = $_POST['target_status'] === 'suspended' ? 'suspended' : 'active';
            if ($userId > 0) {
                $upd = $conn->prepare("UPDATE users SET account_status = ? WHERE id = ?");
                $upd->bind_param("si", $newStatus, $userId);
                if ($upd->execute()) {
                    $successMsg = "Account status updated to <strong>" . ucfirst($newStatus) . "</strong>.";
                    $aud = $conn->prepare("INSERT INTO audit_logs (actor_id, actor_role, action, target_id, target_type, ip_address, user_agent, details) VALUES (?, 'admin', 'toggle_status', ?, 'user', ?, ?, ?)");
                    $dt = json_encode(['status' => $newStatus]);
                    $aud->bind_param("iisss", $adminId, $userId, $ip, $ua, $dt);
                    $aud->execute();
                }
            }
        }

        // 5. DELETE USER
        elseif ($action === 'delete_user') {
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId > 0 && $userId !== $adminId) {
                // Delete user's references
                $del = $conn->prepare("DELETE FROM users WHERE id = ?");
                $del->bind_param("i", $userId);
                if ($del->execute()) {
                    $successMsg = "User #$userId deleted successfully.";
                    $aud = $conn->prepare("INSERT INTO audit_logs (actor_id, actor_role, action, target_id, target_type, ip_address, user_agent, details) VALUES (?, 'admin', 'delete_user', ?, 'user', ?, ?, ?)");
                    $dt = json_encode(['deleted_user_id' => $userId]);
                    $aud->bind_param("iisss", $adminId, $userId, $ip, $ua, $dt);
                    $aud->execute();
                }
            } else {
                $errorMsg = 'Cannot delete your own administrator account.';
            }
        }

        // 6. FACULTY APPROVAL / REJECTION
        elseif ($action === 'faculty_decision') {
            $reqId = (int)($_POST['request_id'] ?? 0);
            $userId = (int)($_POST['user_id'] ?? 0);
            $decision = $_POST['decision'] === 'approve' ? 'approved' : 'rejected';
            $remarks = trim($_POST['remarks'] ?? '');

            if ($reqId > 0 && $userId > 0) {
                $now = date('Y-m-d H:i:s');
                $upReq = $conn->prepare("UPDATE faculty_approval_requests SET status = ?, reviewed_by = ?, admin_remarks = ?, reviewed_at = ? WHERE id = ?");
                $upReq->bind_param("sissi", $decision, $adminId, $remarks, $now, $reqId);
                $upReq->execute();

                $accStatus = $decision === 'approved' ? 'active' : 'rejected';
                $upUsr = $conn->prepare("UPDATE users SET account_status = ?, email_verified = 1 WHERE id = ?");
                $upUsr->bind_param("si", $accStatus, $userId);
                $upUsr->execute();

                // Fetch details for email
                $fq = $conn->query("SELECT full_name, email FROM users WHERE id = $userId LIMIT 1");
                if ($fq && $frow = $fq->fetch_assoc()) {
                    MailService::sendFacultyDecision($frow['email'], $frow['full_name'], $decision, $remarks);
                }

                $successMsg = "Faculty request marked as <strong>" . ucfirst($decision) . "</strong>.";
            }
        }
    }
}

// -------------------------------------------------------------
// FILTERING & PAGINATION QUERY
// -------------------------------------------------------------
$role_tab = trim($_GET['role'] ?? 'all');
$search = trim($_GET['search'] ?? '');
$filter_division = trim($_GET['division'] ?? '');
$filter_status = trim($_GET['status'] ?? '');

// Counts per role
$total_all = 0;
$total_students = 0;
$total_faculty = 0;
$total_admins = 0;

$count_q = @mysqli_query($conn, "SELECT role, COUNT(*) as c FROM users GROUP BY role");
if ($count_q) {
    while ($cr = mysqli_fetch_assoc($count_q)) {
        if ($cr['role'] === 'student') $total_students = (int)$cr['c'];
        elseif ($cr['role'] === 'faculty') $total_faculty = (int)$cr['c'];
        elseif ($cr['role'] === 'admin') $total_admins = (int)$cr['c'];
        $total_all += (int)$cr['c'];
    }
}

// Pending faculty requests
$pending_faculty = [];
$pfr_q = @mysqli_query($conn, "
    SELECT r.id as request_id, r.user_id, r.created_at as request_date, 
           u.full_name, u.email, u.department, u.designation, u.phone, u.employee_id
    FROM faculty_approval_requests r
    JOIN users u ON r.user_id = u.id
    WHERE r.status = 'pending'
    ORDER BY r.id DESC
");
if ($pfr_q) {
    while ($pfr = mysqli_fetch_assoc($pfr_q)) {
        $pending_faculty[] = $pfr;
    }
}

// Distinct divisions for filter
$div_list = [];
$div_q = @mysqli_query($conn, "SELECT DISTINCT division FROM users WHERE division IS NOT NULL AND TRIM(division) != '' ORDER BY division");
if ($div_q) {
    while ($dr = mysqli_fetch_assoc($div_q)) {
        $div_list[] = $dr['division'];
    }
}

// Build query
$where = ["1=1"];
$params = [];
$types = "";

if ($role_tab !== 'all' && in_array($role_tab, ['student', 'faculty', 'admin'])) {
    $where[] = "u.role = ?";
    $params[] = $role_tab;
    $types .= "s";
}

if ($search !== '') {
    $where[] = "(u.full_name LIKE ? OR u.email LIKE ? OR u.enrollment_no LIKE ? OR u.roll_no LIKE ? OR u.employee_id LIKE ?)";
    $lk = "%$search%";
    $params[] = $lk; $params[] = $lk; $params[] = $lk; $params[] = $lk; $params[] = $lk;
    $types .= "sssss";
}

if ($filter_division !== '') {
    $where[] = "u.division = ?";
    $params[] = $filter_division;
    $types .= "s";
}

if ($filter_status !== '') {
    $where[] = "u.account_status = ?";
    $params[] = $filter_status;
    $types .= "s";
}

$where_clause = implode(" AND ", $where);

// Count total filtered
$count_stmt = $conn->prepare("SELECT COUNT(*) as c FROM users u WHERE $where_clause");
if ($types !== '') {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$filtered_count = (int)($count_stmt->get_result()->fetch_assoc()['c'] ?? 0);

// Pagination
$page = max(1, (int)($_GET['p'] ?? 1));
$per_page = 25;
$total_pages = max(1, ceil($filtered_count / $per_page));
$offset = ($page - 1) * $per_page;

$list_sql = "
    SELECT u.id, u.full_name, u.email, u.role, u.account_status, u.email_verified, 
           u.department, u.division, u.enrollment_no, u.roll_no, u.employee_id, 
           u.phone, u.created_at,
           (SELECT COUNT(*) FROM internships WHERE student_id = u.id) as internship_count
    FROM users u
    WHERE $where_clause
    ORDER BY u.id DESC
    LIMIT ?, ?
";
$list_stmt = $conn->prepare($list_sql);
$limit_types = $types . "ii";
$limit_params = array_merge($params, [$offset, $per_page]);
$list_stmt->bind_param($limit_types, ...$limit_params);
$list_stmt->execute();
$user_records = $list_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management — InternHub Admin</title>
    <?php include __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
        .um-container {
            padding: 24px 32px 48px;
            max-width: 1540px;
            margin: 0 auto;
        }
        .um-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 22px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .um-title {
            font-size: 1.6rem;
            font-weight: 800;
            color: #1e1b4b;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .um-subtitle {
            font-size: 0.86rem;
            color: #64748b;
            margin-top: 4px;
        }

        /* Role Tabs */
        .um-tabs {
            display: flex;
            gap: 8px;
            background: #ffffff;
            padding: 6px;
            border-radius: 14px;
            border: 1px solid #eef1f6;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .um-tab {
            padding: 9px 18px;
            border-radius: 10px;
            font-size: 0.86rem;
            font-weight: 600;
            color: #64748b;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }
        .um-tab:hover {
            background: #f8fafc;
            color: #1e1b4b;
        }
        .um-tab.active {
            background: #eddff7;
            color: #3a104f;
            font-weight: 700;
        }
        .um-tab-count {
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.74rem;
            background: rgba(0, 0, 0, 0.06);
            color: inherit;
        }
        .um-tab.active .um-tab-count {
            background: #3a104f;
            color: #ffffff;
        }

        /* Pending Faculty Alert Box */
        .faculty-pending-card {
            background: linear-gradient(135deg, #fff7ed 0%, #ffedd5 100%);
            border: 1px solid #fed7aa;
            border-radius: 16px;
            padding: 20px 24px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        }
        .fpc-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .fpc-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: #fdba74;
            color: #9a3412;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        .fpc-title {
            font-size: 1rem;
            font-weight: 700;
            color: #9a3412;
        }
        .fpc-sub {
            font-size: 0.82rem;
            color: #c2410c;
            margin-top: 2px;
        }

        /* Filter Toolbar */
        .um-toolbar {
            background: #ffffff;
            border-radius: 16px;
            padding: 16px 20px;
            border: 1px solid #eef1f6;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
        }
        .um-search-wrap {
            position: relative;
            flex: 1;
            min-width: 260px;
        }
        .um-search-input {
            width: 100%;
            padding: 10px 14px 10px 38px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            font-size: 0.86rem;
            font-family: inherit;
            outline: none;
            transition: border-color 0.2s;
        }
        .um-search-input:focus {
            border-color: #7c3aed;
        }
        .um-search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 0.9rem;
        }
        .um-filters {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .um-select {
            padding: 9px 14px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            font-size: 0.84rem;
            font-family: inherit;
            color: #334155;
            outline: none;
            background: #ffffff;
        }
        .btn-filter-reset {
            padding: 9px 14px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            color: #64748b;
            font-size: 0.84rem;
            font-weight: 600;
            text-decoration: none;
        }

        /* Table Card */
        .um-table-card {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid #eef1f6;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.03);
            overflow: hidden;
        }
        .um-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }
        .um-table th {
            background: #f8fafc;
            padding: 14px 18px;
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #64748b;
            border-bottom: 1px solid #eef1f6;
        }
        .um-table td {
            padding: 14px 18px;
            font-size: 0.84rem;
            color: #1e1b4b;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        .um-table tr:hover td {
            background: #fafafc;
        }

        .user-chip {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .user-avatar-circle {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: #eddff7;
            color: #3a104f;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.95rem;
            flex-shrink: 0;
        }
        .user-name-title {
            font-weight: 700;
            color: #1e1b4b;
            line-height: 1.2;
        }
        .user-name-sub {
            font-size: 0.74rem;
            color: #64748b;
            margin-top: 2px;
        }

        /* Role Badges */
        .badge-role {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 6px;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: capitalize;
        }
        .role-student { background: #eff6ff; color: #1d4ed8; }
        .role-faculty { background: #f5f3ff; color: #7c3aed; }
        .role-admin { background: #fef2f2; color: #b91c1c; }

        /* Status Badges */
        .badge-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 9px;
            border-radius: 6px;
            font-size: 0.72rem;
            font-weight: 700;
        }
        .status-active { background: #ecfdf5; color: #047857; }
        .status-suspended { background: #fef2f2; color: #be123c; }
        .status-pending { background: #fffbeb; color: #b45309; }

        /* Action Buttons */
        .action-btn-group {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .btn-act {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            color: #475569;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.15s ease;
            font-size: 0.84rem;
        }
        .btn-act:hover {
            border-color: #cbd5e1;
            background: #f1f5f9;
            color: #1e1b4b;
        }
        .btn-act-danger:hover {
            border-color: #fecdd3;
            background: #fff1f2;
            color: #be123c;
        }

        /* Pagination */
        .um-pagination {
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-top: 1px solid #eef1f6;
            font-size: 0.82rem;
            color: #64748b;
        }
        .pag-links {
            display: flex;
            gap: 4px;
        }
        .pag-btn {
            padding: 6px 12px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            color: #475569;
            text-decoration: none;
            font-weight: 600;
        }
        .pag-btn.active {
            background: #3a104f;
            color: #ffffff;
            border-color: #3a104f;
        }
    </style>
</head>
<body>

    <?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

    <div class="um-container">
        
        <!-- Header -->
        <div class="um-header">
            <div>
                <h1 class="um-title"><i class="fa-solid fa-users-gear" style="color: #7c3aed;"></i> User Management</h1>
                <div class="um-subtitle">Complete administrative control over student, faculty, and administrator accounts.</div>
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="button" class="btn btn-primary" style="background: #3a104f; border-color: #3a104f; border-radius: 12px; font-weight: 600; padding: 10px 18px;" onclick="openModal('addUserModal')">
                    <i class="fa-solid fa-user-plus"></i> Add User
                </button>
            </div>
        </div>

        <!-- Feedback Alerts -->
        <?php if ($successMsg): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert" style="border-radius: 12px;">
                <i class="fa-solid fa-circle-check"></i> <?php echo $successMsg; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-radius: 12px;">
                <i class="fa-solid fa-triangle-exclamation"></i> <?php echo $errorMsg; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Pending Faculty Requests Banner (If any) -->
        <?php if (!empty($pending_faculty)): ?>
            <div class="faculty-pending-card">
                <div class="fpc-left">
                    <div class="fpc-icon"><i class="fa-solid fa-user-clock"></i></div>
                    <div>
                        <div class="fpc-title"><?php echo count($pending_faculty); ?> Faculty Registration Request(s) Awaiting Review</div>
                        <div class="fpc-sub">New faculty members registered and require administrator verification before activation.</div>
                    </div>
                </div>
                <div>
                    <button class="btn btn-warning" style="border-radius: 10px; font-weight: 700; font-size: 0.86rem;" onclick="openModal('facultyRequestsModal')">
                        Review Requests (<?php echo count($pending_faculty); ?>)
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <!-- Role Tabs -->
        <div class="um-tabs">
            <a href="user_management.php?role=all" class="um-tab <?php echo $role_tab === 'all' ? 'active' : ''; ?>">
                <i class="fa-solid fa-users"></i> All Users <span class="um-tab-count"><?php echo number_format($total_all); ?></span>
            </a>
            <a href="user_management.php?role=student" class="um-tab <?php echo $role_tab === 'student' ? 'active' : ''; ?>">
                <i class="fa-solid fa-user-graduate"></i> Students <span class="um-tab-count"><?php echo number_format($total_students); ?></span>
            </a>
            <a href="user_management.php?role=faculty" class="um-tab <?php echo $role_tab === 'faculty' ? 'active' : ''; ?>">
                <i class="fa-solid fa-chalkboard-user"></i> Faculty <span class="um-tab-count"><?php echo number_format($total_faculty); ?></span>
            </a>
            <a href="user_management.php?role=admin" class="um-tab <?php echo $role_tab === 'admin' ? 'active' : ''; ?>">
                <i class="fa-solid fa-shield-halved"></i> Administrators <span class="um-tab-count"><?php echo number_format($total_admins); ?></span>
            </a>
        </div>

        <!-- Filter Toolbar -->
        <form method="GET" action="user_management.php" class="um-toolbar">
            <input type="hidden" name="role" value="<?php echo h($role_tab); ?>">
            <div class="um-search-wrap">
                <i class="fa-solid fa-magnifying-glass um-search-icon"></i>
                <input type="text" name="search" class="um-search-input" placeholder="Search by name, email, PRN, or roll number..." value="<?php echo h($search); ?>">
            </div>
            <div class="um-filters">
                <select name="division" class="um-select">
                    <option value="">All Divisions</option>
                    <?php foreach ($div_list as $dv): ?>
                        <option value="<?php echo h($dv); ?>" <?php echo $filter_division === $dv ? 'selected' : ''; ?>><?php echo h($dv); ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="status" class="um-select">
                    <option value="">All Statuses</option>
                    <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="suspended" <?php echo $filter_status === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                    <option value="pending_approval" <?php echo $filter_status === 'pending_approval' ? 'selected' : ''; ?>>Pending</option>
                </select>

                <button type="submit" class="btn btn-sm btn-dark" style="border-radius: 9px; padding: 9px 16px; font-weight: 600;">Apply</button>
                <?php if ($search || $filter_division || $filter_status): ?>
                    <a href="user_management.php?role=<?php echo h($role_tab); ?>" class="btn-filter-reset">Reset</a>
                <?php endif; ?>
            </div>
        </form>

        <!-- User Table Card -->
        <div class="um-table-card">
            <div style="overflow-x: auto;">
                <table class="um-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Email / Contact</th>
                            <th>Department & Division</th>
                            <th>Account Status</th>
                            <th>Joined Date</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($user_records)): ?>
                            <?php foreach ($user_records as $u): ?>
                                <?php 
                                    $initial = strtoupper(substr($u['full_name'] ?: 'U', 0, 1));
                                    $st = $u['account_status'] ?: 'active';
                                    $roleCls = 'role-' . $u['role'];
                                    $statCls = $st === 'active' ? 'status-active' : ($st === 'suspended' ? 'status-suspended' : 'status-pending');
                                ?>
                                <tr>
                                    <td>
                                        <div class="user-chip">
                                            <div class="user-avatar-circle"><?php echo $initial; ?></div>
                                            <div>
                                                <div class="user-name-title"><?php echo h($u['full_name']); ?></div>
                                                <div class="user-name-sub">
                                                    <?php echo h($u['enrollment_no'] ?: $u['roll_no'] ?: $u['employee_id'] ?: 'ID #' . $u['id']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge-role <?php echo $roleCls; ?>">
                                            <?php echo ucfirst($u['role']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600;"><?php echo h($u['email']); ?></div>
                                        <div style="font-size: 0.74rem; color: #64748b;"><?php echo h($u['phone'] ?: 'No phone recorded'); ?></div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600;"><?php echo h($u['department'] ?: 'Information Technology'); ?></div>
                                        <div style="font-size: 0.74rem; color: #64748b;"><?php echo h($u['division'] ?: 'Not assigned'); ?></div>
                                    </td>
                                    <td>
                                        <span class="badge-status <?php echo $statCls; ?>">
                                            <i class="fa-solid fa-circle" style="font-size: 0.45rem;"></i>
                                            <?php echo ucfirst($st); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="font-size: 0.78rem; color: #64748b;">
                                            <?php echo $u['created_at'] ? date('d M Y', strtotime($u['created_at'])) : '-'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-btn-group" style="justify-content: flex-end;">
                                            <!-- Edit Button -->
                                            <button type="button" class="btn-act" title="Edit User" 
                                                    onclick='openEditModal(<?php echo json_encode($u); ?>)'>
                                                <i class="fa-solid fa-pen-to-square"></i>
                                            </button>

                                            <!-- Reset Password -->
                                            <button type="button" class="btn-act" title="Reset Password"
                                                    onclick="openResetPwdModal(<?php echo $u['id']; ?>, '<?php echo addslashes($u['full_name']); ?>')">
                                                <i class="fa-solid fa-key"></i>
                                            </button>

                                            <!-- Suspend / Activate -->
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                <input type="hidden" name="target_status" value="<?php echo $st === 'active' ? 'suspended' : 'active'; ?>">
                                                <button type="submit" class="btn-act" title="<?php echo $st === 'active' ? 'Suspend Account' : 'Reactivate Account'; ?>">
                                                    <i class="fa-solid <?php echo $st === 'active' ? 'fa-ban' : 'fa-circle-check'; ?>" style="color: <?php echo $st === 'active' ? '#d97706' : '#059669'; ?>;"></i>
                                                </button>
                                            </form>

                                            <!-- Delete User -->
                                            <?php if ($u['id'] !== $adminId): ?>
                                                <button type="button" class="btn-act btn-act-danger" title="Delete User"
                                                        onclick="openDeleteModal(<?php echo $u['id']; ?>, '<?php echo addslashes($u['full_name']); ?>')">
                                                    <i class="fa-regular fa-trash-can"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 36px; color: #94a3b8;">
                                    No user accounts matching the specified filters.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Bar -->
            <div class="um-pagination">
                <div>
                    Showing <strong><?php echo min($filtered_count, $offset + 1); ?></strong> to 
                    <strong><?php echo min($filtered_count, $offset + count($user_records)); ?></strong> of 
                    <strong><?php echo number_format($filtered_count); ?></strong> users
                </div>
                <?php if ($total_pages > 1): ?>
                    <div class="pag-links">
                        <?php if ($page > 1): ?>
                            <a href="?role=<?php echo urlencode($role_tab); ?>&search=<?php echo urlencode($search); ?>&division=<?php echo urlencode($filter_division); ?>&status=<?php echo urlencode($filter_status); ?>&p=<?php echo $page - 1; ?>" class="pag-btn">&laquo; Prev</a>
                        <?php endif; ?>

                        <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                            <a href="?role=<?php echo urlencode($role_tab); ?>&search=<?php echo urlencode($search); ?>&division=<?php echo urlencode($filter_division); ?>&status=<?php echo urlencode($filter_status); ?>&p=<?php echo $p; ?>" class="pag-btn <?php echo $p === $page ? 'active' : ''; ?>">
                                <?php echo $p; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?role=<?php echo urlencode($role_tab); ?>&search=<?php echo urlencode($search); ?>&division=<?php echo urlencode($filter_division); ?>&status=<?php echo urlencode($filter_status); ?>&p=<?php echo $page + 1; ?>" class="pag-btn">Next &raquo;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- MODAL: ADD USER -->
    <div class="custom-modal" id="addUserModal" style="display: none;">
        <div class="modal-box" style="max-width: 540px;">
            <div class="modal-head">
                <h3 class="modal-head-title"><i class="fa-solid fa-user-plus"></i> Add New User</h3>
                <button type="button" class="modal-close" onclick="closeModal('addUserModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="add_user">
                <div class="modal-body-scroll">
                    <div class="mb-3">
                        <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">User Role</label>
                        <select name="role" class="form-select" id="newRoleSelect">
                            <option value="student">Student</option>
                            <option value="faculty">Faculty Member</option>
                            <option value="admin">Administrator</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Full Name</label>
                        <input type="text" name="full_name" class="form-control" placeholder="e.g. Dr. Jane Smith or Rahul Sharma" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Email Address</label>
                        <input type="email" name="email" class="form-control" placeholder="name@mituniversity.edu.in" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Temporary Password</label>
                        <input type="password" name="password" class="form-control" placeholder="Initial temporary password" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Department</label>
                            <input type="text" name="department" class="form-control" value="Information Technology">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Division</label>
                            <input type="text" name="division" class="form-control" placeholder="e.g. SY-IT-A or TY-IT-DA">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">PRN / Roll / Emp ID</label>
                            <input type="text" name="enrollment_no" class="form-control" placeholder="e.g. 1032230000">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Contact Phone</label>
                            <input type="text" name="phone" class="form-control" placeholder="+91 9876543210">
                        </div>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-light" onclick="closeModal('addUserModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background: #3a104f; border-color: #3a104f;">Create Account</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: EDIT USER -->
    <div class="custom-modal" id="editUserModal" style="display: none;">
        <div class="modal-box" style="max-width: 540px;">
            <div class="modal-head">
                <h3 class="modal-head-title"><i class="fa-solid fa-pen-to-square"></i> Edit User Account</h3>
                <button type="button" class="modal-close" onclick="closeModal('editUserModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" id="editUserId">
                <div class="modal-body-scroll">
                    <div class="mb-3">
                        <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Full Name</label>
                        <input type="text" name="full_name" id="editFullName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Email Address</label>
                        <input type="email" name="email" id="editEmail" class="form-control" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Department</label>
                            <input type="text" name="department" id="editDepartment" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Division</label>
                            <input type="text" name="division" id="editDivision" class="form-control">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">PRN / Emp ID</label>
                            <input type="text" name="enrollment_no" id="editEnrollment" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Phone</label>
                            <input type="text" name="phone" id="editPhone" class="form-control">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Account Status</label>
                        <select name="account_status" id="editStatus" class="form-select">
                            <option value="active">Active</option>
                            <option value="suspended">Suspended</option>
                            <option value="pending_approval">Pending Approval</option>
                            <option value="rejected">Rejected</option>
                        </select>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-light" onclick="closeModal('editUserModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background: #3a104f; border-color: #3a104f;">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: RESET PASSWORD -->
    <div class="custom-modal" id="resetPwdModal" style="display: none;">
        <div class="modal-box" style="max-width: 440px;">
            <div class="modal-head">
                <h3 class="modal-head-title"><i class="fa-solid fa-key"></i> Reset User Password</h3>
                <button type="button" class="modal-close" onclick="closeModal('resetPwdModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="resetPwdUserId">
                <div class="modal-body-scroll">
                    <p style="font-size: 0.86rem; color: #64748b; margin-bottom: 14px;">
                        Set a new password for <strong id="resetPwdUserName" style="color: #1e1b4b;"></strong>:
                    </p>
                    <div class="mb-3">
                        <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">New Password</label>
                        <input type="password" name="new_password" class="form-control" placeholder="At least 6 characters" minlength="6" required>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-light" onclick="closeModal('resetPwdModal')">Cancel</button>
                    <button type="submit" class="btn btn-warning" style="font-weight: 700;">Update Password</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: DELETE USER -->
    <div class="custom-modal" id="deleteUserModal" style="display: none;">
        <div class="modal-box" style="max-width: 440px;">
            <div class="modal-head">
                <h3 class="modal-head-title" style="color: #be123c;"><i class="fa-solid fa-triangle-exclamation"></i> Confirm Delete</h3>
                <button type="button" class="modal-close" onclick="closeModal('deleteUserModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user_id" id="deleteUserId">
                <div class="modal-body-scroll">
                    <p style="font-size: 0.86rem; color: #64748b;">
                        Are you sure you want to permanently delete the account for <strong id="deleteUserName" style="color: #1e1b4b;"></strong>?
                    </p>
                    <div class="alert alert-danger" style="font-size: 0.78rem; padding: 10px 12px; margin: 0;">
                        This action will remove the user's login access and associated session records.
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-light" onclick="closeModal('deleteUserModal')">Cancel</button>
                    <button type="submit" class="btn btn-danger">Yes, Delete Account</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: FACULTY REGISTRATION REQUESTS -->
    <div class="custom-modal" id="facultyRequestsModal" style="display: none;">
        <div class="modal-box" style="max-width: 760px;">
            <div class="modal-head">
                <h3 class="modal-head-title"><i class="fa-solid fa-user-clock"></i> Faculty Registration Requests</h3>
                <button type="button" class="modal-close" onclick="closeModal('facultyRequestsModal')">&times;</button>
            </div>
            <div class="modal-body-scroll">
                <?php if (!empty($pending_faculty)): ?>
                    <div style="display: flex; flex-direction: column; gap: 14px;">
                        <?php foreach ($pending_faculty as $pf): ?>
                            <div style="border: 1px solid #fed7aa; background: #fffaf0; border-radius: 12px; padding: 16px;">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
                                    <div>
                                        <div style="font-weight: 700; font-size: 0.95rem; color: #1e1b4b;"><?php echo h($pf['full_name']); ?></div>
                                        <div style="font-size: 0.78rem; color: #64748b;"><?php echo h($pf['email']); ?> • Phone: <?php echo h($pf['phone'] ?: 'N/A'); ?></div>
                                        <div style="font-size: 0.76rem; color: #7c3aed; margin-top: 2px;">
                                            Department: <?php echo h($pf['department']); ?> • Designation: <?php echo h($pf['designation'] ?: 'Faculty Member'); ?>
                                        </div>
                                    </div>
                                    <span style="font-size: 0.72rem; color: #94a3b8;"><?php echo date('d M Y', strtotime($pf['request_date'])); ?></span>
                                </div>
                                <form method="POST" style="margin-top: 12px; display: flex; gap: 10px; align-items: center;">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="faculty_decision">
                                    <input type="hidden" name="request_id" value="<?php echo $pf['request_id']; ?>">
                                    <input type="hidden" name="user_id" value="<?php echo $pf['user_id']; ?>">
                                    <input type="text" name="remarks" class="form-control form-control-sm" placeholder="Review remarks (optional)..." style="flex: 1;">
                                    <button type="submit" name="decision" value="approve" class="btn btn-sm btn-success" style="font-weight: 700;">
                                        <i class="fa-solid fa-check"></i> Approve
                                    </button>
                                    <button type="submit" name="decision" value="reject" class="btn btn-sm btn-outline-danger">
                                        <i class="fa-solid fa-xmark"></i> Reject
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p style="text-align: center; color: #94a3b8; padding: 24px;">No pending faculty registration requests.</p>
                <?php endif; ?>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-light" onclick="closeModal('facultyRequestsModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- Layout Close -->
    </main>
    </div>

    <?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>

    <script>
    function openModal(id) {
        const el = document.getElementById(id);
        if (el) el.style.display = 'flex';
    }
    function closeModal(id) {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    }
    function openEditModal(user) {
        document.getElementById('editUserId').value = user.id;
        document.getElementById('editFullName').value = user.full_name || '';
        document.getElementById('editEmail').value = user.email || '';
        document.getElementById('editDepartment').value = user.department || '';
        document.getElementById('editDivision').value = user.division || '';
        document.getElementById('editEnrollment').value = user.enrollment_no || user.roll_no || user.employee_id || '';
        document.getElementById('editPhone').value = user.phone || '';
        document.getElementById('editStatus').value = user.account_status || 'active';
        openModal('editUserModal');
    }
    function openResetPwdModal(id, name) {
        document.getElementById('resetPwdUserId').value = id;
        document.getElementById('resetPwdUserName').textContent = name;
        openModal('resetPwdModal');
    }
    function openDeleteModal(id, name) {
        document.getElementById('deleteUserId').value = id;
        document.getElementById('deleteUserName').textContent = name;
        openModal('deleteUserModal');
    }
    // Close on backdrop click
    document.querySelectorAll('.custom-modal').forEach(m => {
        m.addEventListener('click', function(e) {
            if (e.target === this) this.style.display = 'none';
        });
    });
    </script>
</body>
</html>
