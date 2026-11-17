<?php
session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$activePage = 'internship_oversight.php';
$adminId = (int)($_SESSION['user_id'] ?? 0);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$current_tab = trim($_GET['tab'] ?? 'applications');
$status_filter = trim($_GET['status'] ?? '');
$division_filter = trim($_GET['division'] ?? '');
$search = trim($_GET['search'] ?? '');

$successMsg = '';
$errorMsg = '';

// -------------------------------------------------------------
// POST ACTIONS (Approve/Reject Application, Review Weekly Report)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $postedCsrf)) {
        $errorMsg = 'Security validation failed (Invalid CSRF token).';
    } else {
        $action = trim($_POST['action'] ?? '');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        // 1. UPDATE INTERNSHIP APPLICATION STATUS
        if ($action === 'update_status') {
            $inId = (int)($_POST['internship_id'] ?? 0);
            $newStatus = in_array($_POST['new_status'] ?? '', ['Approved', 'Pending', 'Rejected']) ? $_POST['new_status'] : 'Approved';
            $remarks = trim($_POST['rejection_reason'] ?? '');

            if ($inId > 0) {
                $upd = $conn->prepare("UPDATE internships SET status = ?, rejection_reason = ? WHERE id = ?");
                $upd->bind_param("ssi", $newStatus, $remarks, $inId);
                if ($upd->execute()) {
                    $successMsg = "Internship application #$inId status set to <strong>" . h($newStatus) . "</strong>.";
                    $aud = $conn->prepare("INSERT INTO audit_logs (actor_id, actor_role, action, target_id, target_type, ip_address, user_agent, details) VALUES (?, 'admin', 'update_internship_status', ?, 'internship', ?, ?, ?)");
                    $dt = json_encode(['status' => $newStatus, 'remarks' => $remarks]);
                    $aud->bind_param("iisss", $adminId, $inId, $ip, $ua, $dt);
                    $aud->execute();
                }
            }
        }

        // 2. REVIEW WEEKLY REPORT
        elseif ($action === 'review_weekly_report') {
            $reportId = (int)($_POST['report_id'] ?? 0);
            $revStatus = in_array($_POST['review_status'] ?? '', ['Accepted', 'Rejected']) ? $_POST['review_status'] : 'Accepted';
            $feedback = trim($_POST['faculty_feedback'] ?? '');

            if ($reportId > 0) {
                $upd = $conn->prepare("UPDATE weekly_updates SET review_status = ?, faculty_feedback = ? WHERE id = ?");
                $upd->bind_param("ssi", $revStatus, $feedback, $reportId);
                if ($upd->execute()) {
                    $successMsg = "Weekly report #$reportId review set to <strong>" . h($revStatus) . "</strong>.";
                }
            }
        }
    }
}

// -------------------------------------------------------------
// GLOBAL METRICS & COUNTS
// -------------------------------------------------------------
$total_apps = 0;
$app_approved = 0;
$app_pending = 0;
$app_rejected = 0;

$m_q = @mysqli_query($conn, "SELECT status, COUNT(*) as c FROM internships GROUP BY status");
if ($m_q) {
    while ($r = mysqli_fetch_assoc($m_q)) {
        $st = $r['status'] ?: 'Pending';
        $c = (int)$r['c'];
        $total_apps += $c;
        if ($st === 'Approved') $app_approved = $c;
        elseif ($st === 'Pending') $app_pending = $c;
        elseif ($st === 'Rejected') $app_rejected = $c;
    }
}

// Weekly updates counts
$weekly_total = 0;
$weekly_pending = 0;
$weekly_accepted = 0;
$w_q = @mysqli_query($conn, "SELECT review_status, COUNT(*) as c FROM weekly_updates GROUP BY review_status");
if ($w_q) {
    while ($r = mysqli_fetch_assoc($w_q)) {
        $st = $r['review_status'] ?: 'Pending';
        $c = (int)$r['c'];
        $weekly_total += $c;
        if ($st === 'Pending') $weekly_pending = $c;
        elseif ($st === 'Accepted') $weekly_accepted = $c;
    }
}

// Divisions
$divisions = [];
$dq = @mysqli_query($conn, "SELECT DISTINCT division FROM users WHERE role='student' AND division IS NOT NULL AND TRIM(division) != '' ORDER BY division");
if ($dq) {
    while ($dr = mysqli_fetch_assoc($dq)) {
        $divisions[] = $dr['division'];
    }
}

// Pagination setup
$page = max(1, (int)($_GET['p'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// -------------------------------------------------------------
// TAB 1 & 2: APPLICATIONS / ACTIVE PLACEMENTS QUERY
// -------------------------------------------------------------
if ($current_tab === 'applications' || $current_tab === 'active') {
    $where = ["1=1"];
    $params = [];
    $types = "";

    if ($current_tab === 'active') {
        $where[] = "i.status = 'Approved'";
    } elseif ($status_filter !== '') {
        $where[] = "i.status = ?";
        $params[] = $status_filter;
        $types .= "s";
    }

    if ($division_filter !== '') {
        $where[] = "u.division = ?";
        $params[] = $division_filter;
        $types .= "s";
    }

    if ($search !== '') {
        $where[] = "(u.full_name LIKE ? OR u.enrollment_no LIKE ? OR u.roll_no LIKE ? OR i.company_name LIKE ? OR i.mentor_name LIKE ?)";
        $lk = "%$search%";
        $params[] = $lk; $params[] = $lk; $params[] = $lk; $params[] = $lk; $params[] = $lk;
        $types .= "sssss";
    }

    $where_clause = implode(" AND ", $where);

    $cnt_stmt = $conn->prepare("SELECT COUNT(*) as c FROM internships i JOIN users u ON i.student_id = u.id WHERE $where_clause");
    if ($types !== '') $cnt_stmt->bind_param($types, ...$params);
    $cnt_stmt->execute();
    $tab_count = (int)($cnt_stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $total_pages = max(1, ceil($tab_count / $per_page));

    $data_sql = "
        SELECT i.id, i.student_id, i.company_name, i.internship_type, i.internship_role, 
               i.mentor_name, i.stipend, i.mode, i.start_date, i.end_date, i.status, 
               i.rejection_reason, i.offer_letter, i.created_at,
               u.full_name as student_name, u.enrollment_no, u.roll_no, u.division, u.email as student_email
        FROM internships i
        JOIN users u ON i.student_id = u.id
        WHERE $where_clause
        ORDER BY i.id DESC
        LIMIT ?, ?
    ";
    $data_stmt = $conn->prepare($data_sql);
    $lim_types = $types . "ii";
    $lim_params = array_merge($params, [$offset, $per_page]);
    $data_stmt->bind_param($lim_types, ...$lim_params);
    $data_stmt->execute();
    $applications = $data_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// -------------------------------------------------------------
// TAB 3: WEEKLY REPORTS QUERY
// -------------------------------------------------------------
elseif ($current_tab === 'reports') {
    $where = ["1=1"];
    $params = [];
    $types = "";

    if ($status_filter !== '') {
        $where[] = "w.review_status = ?";
        $params[] = $status_filter;
        $types .= "s";
    }

    if ($division_filter !== '') {
        $where[] = "u.division = ?";
        $params[] = $division_filter;
        $types .= "s";
    }

    if ($search !== '') {
        $where[] = "(u.full_name LIKE ? OR u.enrollment_no LIKE ? OR i.company_name LIKE ? OR w.work_done LIKE ?)";
        $lk = "%$search%";
        $params[] = $lk; $params[] = $lk; $params[] = $lk; $params[] = $lk;
        $types .= "ssss";
    }

    $where_clause = implode(" AND ", $where);

    $cnt_stmt = $conn->prepare("
        SELECT COUNT(*) as c 
        FROM weekly_updates w
        JOIN internships i ON w.internship_id = i.id
        JOIN users u ON i.student_id = u.id
        WHERE $where_clause
    ");
    if ($types !== '') $cnt_stmt->bind_param($types, ...$params);
    $cnt_stmt->execute();
    $tab_count = (int)($cnt_stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $total_pages = max(1, ceil($tab_count / $per_page));

    $data_sql = "
        SELECT w.id, w.week_no, w.work_done, w.update_file, w.faculty_feedback, 
               w.review_status, w.submitted_at,
               i.id as internship_id, i.company_name,
               u.full_name as student_name, u.enrollment_no, u.division
        FROM weekly_updates w
        JOIN internships i ON w.internship_id = i.id
        JOIN users u ON i.student_id = u.id
        WHERE $where_clause
        ORDER BY w.id DESC
        LIMIT ?, ?
    ";
    $data_stmt = $conn->prepare($data_sql);
    $lim_types = $types . "ii";
    $lim_params = array_merge($params, [$offset, $per_page]);
    $data_stmt->bind_param($lim_types, ...$lim_params);
    $data_stmt->execute();
    $weekly_reports = $data_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// -------------------------------------------------------------
// TAB 4: EVALUATIONS & MARKS QUERY
// -------------------------------------------------------------
elseif ($current_tab === 'evaluations') {
    $where = ["1=1"];
    $params = [];
    $types = "";

    if ($division_filter !== '') {
        $where[] = "u.division = ?";
        $params[] = $division_filter;
        $types .= "s";
    }

    if ($search !== '') {
        $where[] = "(u.full_name LIKE ? OR u.enrollment_no LIKE ? OR i.company_name LIKE ?)";
        $lk = "%$search%";
        $params[] = $lk; $params[] = $lk; $params[] = $lk;
        $types .= "sss";
    }

    $where_clause = implode(" AND ", $where);

    $cnt_stmt = $conn->prepare("
        SELECT COUNT(*) as c 
        FROM evaluations ev
        JOIN internships i ON ev.internship_id = i.id
        JOIN users u ON i.student_id = u.id
        WHERE $where_clause
    ");
    if ($types !== '') $cnt_stmt->bind_param($types, ...$params);
    $cnt_stmt->execute();
    $tab_count = (int)($cnt_stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $total_pages = max(1, ceil($tab_count / $per_page));

    $data_sql = "
        SELECT ev.id, ev.ta1_marks, ev.ta2_marks, ev.attendance_marks, 
               ev.final_evaluation_marks, ev.total_marks, ev.remarks, ev.created_at,
               i.company_name, u.full_name as student_name, u.enrollment_no, u.division
        FROM evaluations ev
        JOIN internships i ON ev.internship_id = i.id
        JOIN users u ON i.student_id = u.id
        WHERE $where_clause
        ORDER BY ev.id DESC
        LIMIT ?, ?
    ";
    $data_stmt = $conn->prepare($data_sql);
    $lim_types = $types . "ii";
    $lim_params = array_merge($params, [$offset, $per_page]);
    $data_stmt->bind_param($lim_types, ...$lim_params);
    $data_stmt->execute();
    $evaluations_list = $data_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// -------------------------------------------------------------
// TAB 5: FINAL REPORTS & CERTIFICATES QUERY
// -------------------------------------------------------------
elseif ($current_tab === 'certificates') {
    $where = ["1=1"];
    $params = [];
    $types = "";

    if ($division_filter !== '') {
        $where[] = "u.division = ?";
        $params[] = $division_filter;
        $types .= "s";
    }

    if ($search !== '') {
        $where[] = "(u.full_name LIKE ? OR u.enrollment_no LIKE ? OR i.company_name LIKE ?)";
        $lk = "%$search%";
        $params[] = $lk; $params[] = $lk; $params[] = $lk;
        $types .= "sss";
    }

    $where_clause = implode(" AND ", $where);

    $cnt_stmt = $conn->prepare("
        SELECT COUNT(*) as c 
        FROM final_reports fr
        JOIN internships i ON fr.internship_id = i.id
        JOIN users u ON i.student_id = u.id
        WHERE $where_clause
    ");
    if ($types !== '') $cnt_stmt->bind_param($types, ...$params);
    $cnt_stmt->execute();
    $tab_count = (int)($cnt_stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $total_pages = max(1, ceil($tab_count / $per_page));

    $data_sql = "
        SELECT fr.id, fr.report_file, fr.certificate_file, fr.ppt_file, fr.created_at,
               i.company_name, u.full_name as student_name, u.enrollment_no, u.division
        FROM final_reports fr
        JOIN internships i ON fr.internship_id = i.id
        JOIN users u ON i.student_id = u.id
        WHERE $where_clause
        ORDER BY fr.id DESC
        LIMIT ?, ?
    ";
    $data_stmt = $conn->prepare($data_sql);
    $lim_types = $types . "ii";
    $lim_params = array_merge($params, [$offset, $per_page]);
    $data_stmt->bind_param($lim_types, ...$lim_params);
    $data_stmt->execute();
    $certificates_list = $data_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Internship Oversight — InternHub Admin</title>
    <?php include __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
        .io-container {
            padding: 24px 32px 48px;
            max-width: 1540px;
            margin: 0 auto;
        }
        .io-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 22px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .io-title {
            font-size: 1.6rem;
            font-weight: 800;
            color: #1e1b4b;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .io-subtitle {
            font-size: 0.86rem;
            color: #64748b;
            margin-top: 4px;
        }

        /* 5 Flagship Tabs */
        .io-tabs {
            display: flex;
            gap: 8px;
            background: #ffffff;
            padding: 6px;
            border-radius: 14px;
            border: 1px solid #eef1f6;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .io-tab {
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
        .io-tab:hover { background: #f8fafc; color: #1e1b4b; }
        .io-tab.active { background: #eddff7; color: #3a104f; font-weight: 700; }
        .io-tab-badge {
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.74rem;
            background: rgba(0, 0, 0, 0.06);
        }
        .io-tab.active .io-tab-badge { background: #3a104f; color: #ffffff; }

        /* KPI Mini Strip */
        .io-metrics-strip {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 22px;
        }
        .io-metric-card {
            background: #ffffff;
            border-radius: 14px;
            padding: 16px 20px;
            border: 1px solid #eef1f6;
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .io-mc-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }
        .io-mc-val {
            font-size: 1.4rem;
            font-weight: 800;
            color: #1e1b4b;
            line-height: 1.1;
        }
        .io-mc-lbl {
            font-size: 0.74rem;
            color: #64748b;
            font-weight: 600;
        }

        /* Filter Toolbar */
        .io-toolbar {
            background: #ffffff;
            border-radius: 16px;
            padding: 16px 20px;
            border: 1px solid #eef1f6;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
        }
        .io-search-wrap {
            position: relative;
            flex: 1;
            min-width: 280px;
        }
        .io-search-input {
            width: 100%;
            padding: 10px 14px 10px 38px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            font-size: 0.86rem;
            outline: none;
            transition: border-color 0.2s;
        }
        .io-search-input:focus { border-color: #7c3aed; }
        .io-search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 0.9rem;
        }

        /* Table Card */
        .io-table-card {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid #eef1f6;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.03);
            overflow: hidden;
        }
        .io-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }
        .io-table th {
            background: #f8fafc;
            padding: 14px 18px;
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #64748b;
            border-bottom: 1px solid #eef1f6;
        }
        .io-table td {
            padding: 14px 18px;
            font-size: 0.84rem;
            color: #1e1b4b;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        .io-table tr:hover td { background: #fafafc; }

        /* Status Pills */
        .pill-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 9px;
            border-radius: 6px;
            font-size: 0.72rem;
            font-weight: 700;
        }
        .pill-app { background: #ecfdf5; color: #047857; }
        .pill-pend { background: #fffbeb; color: #b45309; }
        .pill-rej { background: #fef2f2; color: #be123c; }

        /* Action Buttons */
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
            background: #f1f5f9;
            color: #1e1b4b;
        }
    </style>
</head>
<body>

    <?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

    <div class="io-container">
        
        <!-- Header -->
        <div class="io-header">
            <div>
                <h1 class="io-title"><i class="fa-solid fa-briefcase" style="color: #7c3aed;"></i> Internship Oversight</h1>
                <div class="io-subtitle">Centralized oversight for internship applications, active industry placements, weekly logs, and final grading.</div>
            </div>
            <div style="display: flex; gap: 10px;">
                <a href="reports_export_csv.php" class="btn btn-outline-secondary" style="border-radius: 12px; font-weight: 600; font-size: 0.86rem; padding: 10px 18px;">
                    <i class="fa-solid fa-file-export"></i> Export Report
                </a>
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

        <!-- KPI Mini Strip -->
        <div class="io-metrics-strip">
            <div class="io-metric-card">
                <div class="io-mc-icon" style="background: #eddff7; color: #6b21a8;">
                    <i class="fa-solid fa-file-signature"></i>
                </div>
                <div>
                    <div class="io-mc-val"><?php echo number_format($total_apps); ?></div>
                    <div class="io-mc-lbl">Total Applications</div>
                </div>
            </div>
            <div class="io-metric-card">
                <div class="io-mc-icon" style="background: #d1fae5; color: #047857;">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div>
                    <div class="io-mc-val"><?php echo number_format($app_approved); ?></div>
                    <div class="io-mc-lbl">Approved & Active</div>
                </div>
            </div>
            <div class="io-metric-card">
                <div class="io-mc-icon" style="background: #fef3c7; color: #b45309;">
                    <i class="fa-solid fa-clock"></i>
                </div>
                <div>
                    <div class="io-mc-val"><?php echo number_format($app_pending); ?></div>
                    <div class="io-mc-lbl">Pending Review</div>
                </div>
            </div>
            <div class="io-metric-card">
                <div class="io-mc-icon" style="background: #eff6ff; color: #1d4ed8;">
                    <i class="fa-solid fa-list-check"></i>
                </div>
                <div>
                    <div class="io-mc-val"><?php echo number_format($weekly_pending); ?></div>
                    <div class="io-mc-lbl">Weekly Reports Pending</div>
                </div>
            </div>
        </div>

        <!-- 5 Tabs Navigation -->
        <div class="io-tabs">
            <a href="?tab=applications" class="io-tab <?php echo $current_tab === 'applications' ? 'active' : ''; ?>">
                <i class="fa-solid fa-file-lines"></i> Applications 
                <span class="io-tab-badge"><?php echo number_format($total_apps); ?></span>
            </a>
            <a href="?tab=active" class="io-tab <?php echo $current_tab === 'active' ? 'active' : ''; ?>">
                <i class="fa-solid fa-building-user"></i> Active Placements 
                <span class="io-tab-badge"><?php echo number_format($app_approved); ?></span>
            </a>
            <a href="?tab=reports" class="io-tab <?php echo $current_tab === 'reports' ? 'active' : ''; ?>">
                <i class="fa-solid fa-clock-rotate-left"></i> Weekly Reports 
                <span class="io-tab-badge"><?php echo number_format($weekly_total); ?></span>
            </a>
            <a href="?tab=evaluations" class="io-tab <?php echo $current_tab === 'evaluations' ? 'active' : ''; ?>">
                <i class="fa-solid fa-clipboard-check"></i> Evaluations & Marks
            </a>
            <a href="?tab=certificates" class="io-tab <?php echo $current_tab === 'certificates' ? 'active' : ''; ?>">
                <i class="fa-solid fa-certificate"></i> Final Certificates
            </a>
        </div>

        <!-- Filter Toolbar -->
        <form method="GET" action="internship_oversight.php" class="io-toolbar">
            <input type="hidden" name="tab" value="<?php echo h($current_tab); ?>">
            <div class="io-search-wrap">
                <i class="fa-solid fa-magnifying-glass io-search-icon"></i>
                <input type="text" name="search" class="io-search-input" placeholder="Search by student, company, PRN, or mentor..." value="<?php echo h($search); ?>">
            </div>
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <select name="division" class="form-select form-select-sm" style="width: auto; border-radius: 9px;">
                    <option value="">All Divisions</option>
                    <?php foreach ($divisions as $d): ?>
                        <option value="<?php echo h($d); ?>" <?php echo $division_filter === $d ? 'selected' : ''; ?>><?php echo h($d); ?></option>
                    <?php endforeach; ?>
                </select>

                <?php if ($current_tab === 'applications' || $current_tab === 'reports'): ?>
                    <select name="status" class="form-select form-select-sm" style="width: auto; border-radius: 9px;">
                        <option value="">All Statuses</option>
                        <?php if ($current_tab === 'applications'): ?>
                            <option value="Approved" <?php echo $status_filter === 'Approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="Pending" <?php echo $status_filter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="Rejected" <?php echo $status_filter === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <?php else: ?>
                            <option value="Accepted" <?php echo $status_filter === 'Accepted' ? 'selected' : ''; ?>>Accepted</option>
                            <option value="Pending" <?php echo $status_filter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="Rejected" <?php echo $status_filter === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <?php endif; ?>
                    </select>
                <?php endif; ?>

                <button type="submit" class="btn btn-sm btn-dark" style="border-radius: 9px; padding: 7px 16px; font-weight: 600;">Apply</button>
                <?php if ($search || $division_filter || $status_filter): ?>
                    <a href="?tab=<?php echo urlencode($current_tab); ?>" class="btn btn-sm btn-light" style="border-radius: 9px;">Reset</a>
                <?php endif; ?>
            </div>
        </form>

        <!-- CONTENT TAB 1 & 2: APPLICATIONS / ACTIVE PLACEMENTS -->
        <?php if ($current_tab === 'applications' || $current_tab === 'active'): ?>
            <div class="io-table-card">
                <div style="overflow-x: auto;">
                    <table class="io-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Company & Role</th>
                                <th>Type & Mode</th>
                                <th>Mentor</th>
                                <th>Duration</th>
                                <th>Status</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($applications)): ?>
                                <?php foreach ($applications as $app): ?>
                                    <?php 
                                        $st = $app['status'] ?: 'Pending';
                                        $pCls = $st === 'Approved' ? 'pill-app' : ($st === 'Pending' ? 'pill-pend' : 'pill-rej');
                                    ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 700; color: #1e1b4b;"><?php echo h($app['student_name']); ?></div>
                                            <div style="font-size: 0.74rem; color: #64748b;"><?php echo h($app['enrollment_no'] ?: $app['roll_no'] ?: 'PRN N/A'); ?> • <?php echo h($app['division'] ?: '-'); ?></div>
                                        </td>
                                        <td>
                                            <div style="font-weight: 700; color: #1e1b4b;"><?php echo h($app['company_name'] ?: 'Not Specified'); ?></div>
                                            <div style="font-size: 0.74rem; color: #64748b;"><?php echo h($app['internship_role'] ?: 'Intern'); ?></div>
                                        </td>
                                        <td>
                                            <span style="font-size: 0.8rem; font-weight: 600; color: #475569;"><?php echo h($app['internship_type'] ?: 'Industrial'); ?></span>
                                            <div style="font-size: 0.72rem; color: #94a3b8;"><?php echo h($app['mode'] ?: 'In-Office'); ?></div>
                                        </td>
                                        <td>
                                            <div style="font-size: 0.82rem; font-weight: 600;"><?php echo h($app['mentor_name'] ?: 'Assigned Faculty'); ?></div>
                                        </td>
                                        <td>
                                            <span style="font-size: 0.76rem; color: #64748b;">
                                                <?php echo $app['start_date'] ? date('d M', strtotime($app['start_date'])) : '-'; ?> to 
                                                <?php echo $app['end_date'] ? date('d M Y', strtotime($app['end_date'])) : '-'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="pill-status <?php echo $pCls; ?>">
                                                <i class="fa-solid fa-circle" style="font-size: 0.4rem;"></i>
                                                <?php echo h($st); ?>
                                            </span>
                                        </td>
                                        <td style="text-align: right;">
                                            <button type="button" class="btn btn-sm btn-outline-primary" style="border-radius: 8px; font-weight: 600;"
                                                    onclick='openReviewModal(<?php echo json_encode($app); ?>)'>
                                                Review
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 36px; color: #94a3b8;">
                                        No internship records found matching criteria.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- CONTENT TAB 3: WEEKLY REPORTS -->
        <?php elseif ($current_tab === 'reports'): ?>
            <div class="io-table-card">
                <div style="overflow-x: auto;">
                    <table class="io-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Week</th>
                                <th>Company</th>
                                <th>Work Summary</th>
                                <th>Attachment</th>
                                <th>Status</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($weekly_reports)): ?>
                                <?php foreach ($weekly_reports as $wr): ?>
                                    <?php 
                                        $rst = $wr['review_status'] ?: 'Pending';
                                        $rpCls = $rst === 'Accepted' ? 'pill-app' : ($rst === 'Pending' ? 'pill-pend' : 'pill-rej');
                                    ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 700; color: #1e1b4b;"><?php echo h($wr['student_name']); ?></div>
                                            <div style="font-size: 0.74rem; color: #64748b;"><?php echo h($wr['enrollment_no']); ?> • <?php echo h($wr['division']); ?></div>
                                        </td>
                                        <td>
                                            <span style="font-weight: 800; color: #7c3aed; background: #f5f3ff; padding: 3px 8px; border-radius: 6px;">Week <?php echo (int)$wr['week_no']; ?></span>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo h($wr['company_name']); ?></div>
                                        </td>
                                        <td style="max-width: 320px;">
                                            <div style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 0.8rem; color: #475569;">
                                                <?php echo h($wr['work_done']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($wr['update_file']): ?>
                                                <a href="../<?php echo h($wr['update_file']); ?>" target="_blank" class="btn btn-sm btn-light" style="border-radius: 6px; font-size: 0.74rem;">
                                                    <i class="fa-solid fa-paperclip"></i> View File
                                                </a>
                                            <?php else: ?>
                                                <span style="font-size: 0.74rem; color: #94a3b8;">None</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="pill-status <?php echo $rpCls; ?>"><?php echo h($rst); ?></span>
                                        </td>
                                        <td style="text-align: right;">
                                            <button type="button" class="btn btn-sm btn-outline-primary" style="border-radius: 8px; font-weight: 600;"
                                                    onclick='openWeeklyModal(<?php echo json_encode($wr); ?>)'>
                                                Evaluate
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 36px; color: #94a3b8;">No weekly reports found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- CONTENT TAB 4: EVALUATIONS & MARKS -->
        <?php elseif ($current_tab === 'evaluations'): ?>
            <div class="io-table-card">
                <div style="overflow-x: auto;">
                    <table class="io-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Company</th>
                                <th style="text-align: center;">TA1 (25)</th>
                                <th style="text-align: center;">TA2 (25)</th>
                                <th style="text-align: center;">Attendance (10)</th>
                                <th style="text-align: center;">Final Eval (40)</th>
                                <th style="text-align: center;">Total (100)</th>
                                <th>Faculty Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($evaluations_list)): ?>
                                <?php foreach ($evaluations_list as $ev): ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 700; color: #1e1b4b;"><?php echo h($ev['student_name']); ?></div>
                                            <div style="font-size: 0.74rem; color: #64748b;"><?php echo h($ev['enrollment_no']); ?> • <?php echo h($ev['division']); ?></div>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo h($ev['company_name']); ?></div>
                                        </td>
                                        <td style="text-align: center; font-weight: 700;"><?php echo $ev['ta1_marks'] ?? '-'; ?></td>
                                        <td style="text-align: center; font-weight: 700;"><?php echo $ev['ta2_marks'] ?? '-'; ?></td>
                                        <td style="text-align: center; font-weight: 700;"><?php echo $ev['attendance_marks'] ?? '-'; ?></td>
                                        <td style="text-align: center; font-weight: 700;"><?php echo $ev['final_evaluation_marks'] ?? '-'; ?></td>
                                        <td style="text-align: center; font-weight: 800; color: #3a104f; background: #faf5ff;">
                                            <?php echo $ev['total_marks'] ?? '-'; ?>
                                        </td>
                                        <td style="font-size: 0.78rem; color: #64748b;"><?php echo h($ev['remarks'] ?: 'Evaluated'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 36px; color: #94a3b8;">No evaluation marks recorded yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- CONTENT TAB 5: FINAL REPORTS & CERTIFICATES -->
        <?php elseif ($current_tab === 'certificates'): ?>
            <div class="io-table-card">
                <div style="overflow-x: auto;">
                    <table class="io-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Company</th>
                                <th>Final Report</th>
                                <th>Completion Certificate</th>
                                <th>Presentation PPT</th>
                                <th>Submitted Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($certificates_list)): ?>
                                <?php foreach ($certificates_list as $cf): ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 700; color: #1e1b4b;"><?php echo h($cf['student_name']); ?></div>
                                            <div style="font-size: 0.74rem; color: #64748b;"><?php echo h($cf['enrollment_no']); ?> • <?php echo h($cf['division']); ?></div>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo h($cf['company_name']); ?></div>
                                        </td>
                                        <td>
                                            <?php if ($cf['report_file']): ?>
                                                <a href="../<?php echo h($cf['report_file']); ?>" target="_blank" class="btn btn-sm btn-outline-primary" style="border-radius: 6px; font-size: 0.76rem;">
                                                    <i class="fa-solid fa-file-pdf"></i> Report PDF
                                                </a>
                                            <?php else: ?>
                                                <span style="font-size: 0.74rem; color: #94a3b8;">Not Uploaded</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($cf['certificate_file']): ?>
                                                <a href="../<?php echo h($cf['certificate_file']); ?>" target="_blank" class="btn btn-sm btn-outline-success" style="border-radius: 6px; font-size: 0.76rem;">
                                                    <i class="fa-solid fa-certificate"></i> Verified Certificate
                                                </a>
                                            <?php else: ?>
                                                <span style="font-size: 0.74rem; color: #94a3b8;">Not Uploaded</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($cf['ppt_file']): ?>
                                                <a href="../<?php echo h($cf['ppt_file']); ?>" target="_blank" class="btn btn-sm btn-outline-info" style="border-radius: 6px; font-size: 0.76rem;">
                                                    <i class="fa-solid fa-file-powerpoint"></i> Slides
                                                </a>
                                            <?php else: ?>
                                                <span style="font-size: 0.74rem; color: #94a3b8;">None</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size: 0.78rem; color: #64748b;">
                                            <?php echo $cf['created_at'] ? date('d M Y', strtotime($cf['created_at'])) : '-'; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 36px; color: #94a3b8;">No completion certificates uploaded yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div style="margin-top: 24px; display: flex; align-items: center; justify-content: space-between; background: #ffffff; padding: 14px 20px; border-radius: 14px; border: 1px solid #eef1f6;">
                <div style="font-size: 0.82rem; color: #64748b;">
                    Showing page <strong><?php echo $page; ?></strong> of <strong><?php echo $total_pages; ?></strong> (<?php echo number_format($tab_count); ?> total entries)
                </div>
                <div style="display: flex; gap: 4px;">
                    <?php if ($page > 1): ?>
                        <a href="?tab=<?php echo urlencode($current_tab); ?>&search=<?php echo urlencode($search); ?>&division=<?php echo urlencode($division_filter); ?>&status=<?php echo urlencode($status_filter); ?>&p=<?php echo $page - 1; ?>" class="btn btn-sm btn-light">&laquo; Prev</a>
                    <?php endif; ?>
                    <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                        <a href="?tab=<?php echo urlencode($current_tab); ?>&search=<?php echo urlencode($search); ?>&division=<?php echo urlencode($division_filter); ?>&status=<?php echo urlencode($status_filter); ?>&p=<?php echo $p; ?>" class="btn btn-sm <?php echo $p === $page ? 'btn-dark' : 'btn-light'; ?>"><?php echo $p; ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                        <a href="?tab=<?php echo urlencode($current_tab); ?>&search=<?php echo urlencode($search); ?>&division=<?php echo urlencode($division_filter); ?>&status=<?php echo urlencode($status_filter); ?>&p=<?php echo $page + 1; ?>" class="btn btn-sm btn-light">Next &raquo;</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <!-- MODAL: APPLICATION REVIEW -->
    <div class="custom-modal" id="reviewModal" style="display: none;">
        <div class="modal-box" style="max-width: 600px;">
            <div class="modal-head">
                <h3 class="modal-head-title"><i class="fa-solid fa-file-signature"></i> Application Review</h3>
                <button type="button" class="modal-close" onclick="closeModal('reviewModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="internship_id" id="revAppId">
                <div class="modal-body-scroll">
                    <div style="background: #f8fafc; border-radius: 12px; padding: 14px; margin-bottom: 14px;">
                        <div id="revStudentName" style="font-weight: 700; font-size: 1.05rem; color: #1e1b4b;">Student Name</div>
                        <div id="revStudentPRN" style="font-size: 0.78rem; color: #64748b;">PRN • Division</div>
                        <div id="revCompany" style="font-size: 0.9rem; font-weight: 700; color: #7c3aed; margin-top: 6px;">Company Name</div>
                        <div id="revMeta" style="font-size: 0.78rem; color: #475569; margin-top: 2px;">Type • Mentor • Duration</div>
                    </div>

                    <div id="revOfferLetterBox" style="margin-bottom: 14px;">
                        <!-- Offer letter link if available -->
                    </div>

                    <div class="mb-3">
                        <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Decision Status</label>
                        <select name="new_status" id="revNewStatus" class="form-select">
                            <option value="Approved">Approved</option>
                            <option value="Pending">Pending</option>
                            <option value="Rejected">Rejected</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Review Notes / Rejection Reason</label>
                        <textarea name="rejection_reason" id="revRemarks" class="form-control" rows="3" placeholder="Optional review remarks or reason if rejected..."></textarea>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-light" onclick="closeModal('reviewModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background: #3a104f; border-color: #3a104f;">Save Decision</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: WEEKLY REPORT EVALUATION -->
    <div class="custom-modal" id="weeklyModal" style="display: none;">
        <div class="modal-box" style="max-width: 540px;">
            <div class="modal-head">
                <h3 class="modal-head-title"><i class="fa-solid fa-clock-rotate-left"></i> Weekly Report Evaluation</h3>
                <button type="button" class="modal-close" onclick="closeModal('weeklyModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="review_weekly_report">
                <input type="hidden" name="report_id" id="wkReportId">
                <div class="modal-body-scroll">
                    <div style="background: #f8fafc; border-radius: 12px; padding: 14px; margin-bottom: 14px;">
                        <div id="wkStudent" style="font-weight: 700; color: #1e1b4b;">Student Name</div>
                        <div id="wkWeek" style="font-size: 0.8rem; font-weight: 700; color: #7c3aed; margin-top: 2px;">Week #</div>
                        <div id="wkWorkDone" style="font-size: 0.82rem; color: #475569; margin-top: 8px; line-height: 1.4; max-height: 120px; overflow-y: auto; background: #ffffff; padding: 10px; border-radius: 8px; border: 1px solid #eef1f6;">Work done text...</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Review Decision</label>
                        <select name="review_status" id="wkReviewStatus" class="form-select">
                            <option value="Accepted">Accepted</option>
                            <option value="Rejected">Needs Revision (Rejected)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Faculty / Admin Feedback</label>
                        <textarea name="faculty_feedback" id="wkFeedback" class="form-control" rows="3" placeholder="Provide constructive comments to student..."></textarea>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-light" onclick="closeModal('weeklyModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background: #3a104f; border-color: #3a104f;">Submit Evaluation</button>
                </div>
            </form>
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

    function openReviewModal(app) {
        document.getElementById('revAppId').value = app.id;
        document.getElementById('revStudentName').textContent = app.student_name;
        document.getElementById('revStudentPRN').textContent = `${app.enrollment_no || app.roll_no || 'PRN N/A'} • ${app.division || 'No Division'}`;
        document.getElementById('revCompany').textContent = `${app.company_name} — ${app.internship_role || 'Intern'}`;
        document.getElementById('revMeta').textContent = `Type: ${app.internship_type || 'Industrial'} • Mentor: ${app.mentor_name || 'Assigned'} • Mode: ${app.mode || 'In-Office'}`;
        document.getElementById('revNewStatus').value = app.status || 'Approved';
        document.getElementById('revRemarks').value = app.rejection_reason || '';

        const olBox = document.getElementById('revOfferLetterBox');
        if (app.offer_letter) {
            olBox.innerHTML = `
                <a href="../${app.offer_letter}" target="_blank" class="btn btn-sm btn-outline-secondary" style="border-radius: 8px;">
                    <i class="fa-solid fa-paperclip"></i> View Uploaded Offer Letter
                </a>
            `;
        } else {
            olBox.innerHTML = `<span style="font-size: 0.76rem; color: #94a3b8;"><i class="fa-solid fa-file-circle-xmark"></i> No offer letter attached</span>`;
        }

        openModal('reviewModal');
    }

    function openWeeklyModal(wr) {
        document.getElementById('wkReportId').value = wr.id;
        document.getElementById('wkStudent').textContent = `${wr.student_name} (${wr.company_name})`;
        document.getElementById('wkWeek').textContent = `Week ${wr.week_no} Report`;
        document.getElementById('wkWorkDone').textContent = wr.work_done || 'No description provided.';
        document.getElementById('wkReviewStatus').value = wr.review_status === 'Rejected' ? 'Rejected' : 'Accepted';
        document.getElementById('wkFeedback').value = wr.faculty_feedback || '';
        openModal('weeklyModal');
    }

    document.querySelectorAll('.custom-modal').forEach(m => {
        m.addEventListener('click', function(e) {
            if (e.target === this) this.style.display = 'none';
        });
    });
    </script>
</body>
</html>
