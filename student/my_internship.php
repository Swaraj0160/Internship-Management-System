<?php
/**
 * InternHub — Unified Internship Workspace (my_internship.php)
 * Consolidates: Overview, Apply, My Applications, Weekly Reports, Marks & Evaluation, Final Certificate
 */

session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/StorageManager.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../login.php");
    exit;
}

$student_id = (int)$_SESSION['user_id'];
$activePage = 'my_internship.php';
$tab = $_GET['tab'] ?? 'overview';
$validTabs = ['overview', 'apply', 'applications', 'updates', 'marks', 'certificate'];
if (!in_array($tab, $validTabs)) $tab = 'overview';

$message = '';
$messageType = '';

// Helper function
if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

// 1. Fetch Student Info
$stmt = $conn->prepare("SELECT full_name, email, phone, division, department, enrollment_no FROM users WHERE id = ?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

$student_division = $student['division'] ?? 'SY-4';
$student_department = $student['department'] ?? 'SOC';
$enrollment_no = $student['enrollment_no'] ?? '';
$student_name = $student['full_name'] ?? 'Student';

// 2. Fetch Active and Approved Internships
$approved_stmt = $conn->prepare("SELECT * FROM internships WHERE student_id = ? AND status = 'Approved' ORDER BY id DESC LIMIT 1");
$approved_stmt->bind_param("i", $student_id);
$approved_stmt->execute();
$approved_internship = $approved_stmt->get_result()->fetch_assoc();
$approved_stmt->close();

$latest_stmt = $conn->prepare("SELECT * FROM internships WHERE student_id = ? ORDER BY id DESC LIMIT 1");
$latest_stmt->bind_param("i", $student_id);
$latest_stmt->execute();
$latest_internship = $latest_stmt->get_result()->fetch_assoc();
$latest_stmt->close();

$active_internship = $approved_internship ?: $latest_internship;
$active_id = (int)($active_internship['id'] ?? 0);

// 3. Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Action: Apply / Edit Internship
    if ($action === 'apply_internship') {
        $company_name = trim($_POST['company_name'] ?? '');
        $company_contact = trim($_POST['company_contact'] ?? '');
        $internship_role = trim($_POST['internship_role'] ?? '');
        $internship_type = trim($_POST['internship_type'] ?? 'External');
        $mini_project_title = trim($_POST['mini_project_title'] ?? '');
        $mentor_name = trim($_POST['mentor_name'] ?? '');
        $stipend = trim($_POST['stipend'] ?? 'Unpaid');
        $mode = in_array($_POST['mode'] ?? '', ['Online', 'Offline', 'Hybrid']) ? $_POST['mode'] : 'Hybrid';
        $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;

        if (empty($company_name) || empty($internship_role)) {
            $message = 'Company Name and Internship Role are required fields.';
            $messageType = 'danger';
        } else {
            // Check offer letter upload
            $offer_letter_name = $active_internship['offer_letter'] ?? null;
            if (isset($_FILES['offer_letter']) && $_FILES['offer_letter']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['offer_letter']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'])) {
                    $uploadDir = __DIR__ . '/../uploads/offer_letters/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    $newFilename = time() . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $_FILES['offer_letter']['name']);
                    if (move_uploaded_file($_FILES['offer_letter']['tmp_name'], $uploadDir . $newFilename)) {
                        $offer_letter_name = $newFilename;
                    }
                }
            }

            // Check if student already has an active pending or approved application
            if ($approved_internship) {
                // If already approved, allow updating project title and mentor notes only
                $upStmt = $conn->prepare("UPDATE internships SET mini_project_title = ?, mentor_name = ?, company_contact = ? WHERE id = ? AND student_id = ?");
                $upStmt->bind_param("sssii", $mini_project_title, $mentor_name, $company_contact, $approved_internship['id'], $student_id);
                $upStmt->execute();
                $upStmt->close();
                $message = 'Internship project details updated successfully!';
                $messageType = 'success';
            } else {
                // Insert new application
                $inStmt = $conn->prepare("
                    INSERT INTO internships (student_id, company_name, company_contact, internship_role, mini_project_title, internship_type, mentor_name, stipend, mode, start_date, end_date, offer_letter, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')
                ");
                $inStmt->bind_param("isssssssssss", $student_id, $company_name, $company_contact, $internship_role, $mini_project_title, $internship_type, $mentor_name, $stipend, $mode, $start_date, $end_date, $offer_letter_name);
                if ($inStmt->execute()) {
                    $message = 'Internship application submitted successfully! Awaiting faculty approval.';
                    $messageType = 'success';
                    $tab = 'applications';
                } else {
                    $message = 'Failed to submit application: ' . $conn->error;
                    $messageType = 'danger';
                }
                $inStmt->close();
            }
        }
    }

    // Action: Submit Weekly Update
    elseif ($action === 'submit_update') {
        $week_no = (int)($_POST['week_no'] ?? 1);
        $work_done = trim($_POST['work_done'] ?? '');
        $internship_id = (int)($_POST['internship_id'] ?? $active_id);

        if (empty($work_done)) {
            $message = 'Work done description cannot be empty.';
            $messageType = 'danger';
        } elseif ($internship_id <= 0) {
            $message = 'You must have an active internship application before submitting weekly reports.';
            $messageType = 'danger';
        } else {
            $update_file_name = null;
            if (isset($_FILES['update_file']) && $_FILES['update_file']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['update_file']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['pdf', 'doc', 'docx', 'zip'])) {
                    $wDir = __DIR__ . '/../uploads/weekly_reports/';
                    if (!is_dir($wDir)) mkdir($wDir, 0755, true);
                    $newFilename = time() . '_Week' . $week_no . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $_FILES['update_file']['name']);
                    if (move_uploaded_file($_FILES['update_file']['tmp_name'], $wDir . $newFilename)) {
                        $update_file_name = $newFilename;
                    }
                }
            }

            // Check if week already submitted
            $chkWeek = $conn->prepare("SELECT id FROM weekly_updates WHERE internship_id = ? AND week_no = ?");
            $chkWeek->bind_param("ii", $internship_id, $week_no);
            $chkWeek->execute();
            $existingWeek = $chkWeek->get_result()->fetch_assoc();
            $chkWeek->close();

            if ($existingWeek) {
                // Update
                $upW = $conn->prepare("UPDATE weekly_updates SET work_done = ?, update_file = COALESCE(?, update_file), review_status = 'Pending', submitted_at = NOW() WHERE id = ?");
                $upW->bind_param("ssi", $work_done, $update_file_name, $existingWeek['id']);
                $upW->execute();
                $upW->close();
                $message = "Week {$week_no} report updated and resubmitted for faculty review!";
                $messageType = 'success';
            } else {
                // Insert
                $inW = $conn->prepare("INSERT INTO weekly_updates (internship_id, week_no, work_done, update_file, review_status, submitted_at) VALUES (?, ?, ?, ?, 'Pending', NOW())");
                $inW->bind_param("iiss", $internship_id, $week_no, $work_done, $update_file_name);
                $inW->execute();
                $inW->close();
                $message = "Week {$week_no} report submitted successfully!";
                $messageType = 'success';
            }
            $tab = 'updates';
        }
    }

    // Action: Final Certificate / Report Upload
    elseif ($action === 'submit_final_report') {
        if ($active_id <= 0) {
            $message = 'Active internship required.';
            $messageType = 'danger';
        } else {
            $cert_file = null;
            $report_file = null;
            $uploadDir = __DIR__ . '/../uploads/final_reports/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            if (isset($_FILES['certificate_file']) && $_FILES['certificate_file']['error'] === UPLOAD_ERR_OK) {
                $cName = time() . '_cert_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $_FILES['certificate_file']['name']);
                if (move_uploaded_file($_FILES['certificate_file']['tmp_name'], $uploadDir . $cName)) {
                    $cert_file = $cName;
                }
            }

            if (isset($_FILES['report_file']) && $_FILES['report_file']['error'] === UPLOAD_ERR_OK) {
                $rName = time() . '_rep_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $_FILES['report_file']['name']);
                if (move_uploaded_file($_FILES['report_file']['tmp_name'], $uploadDir . $rName)) {
                    $report_file = $rName;
                }
            }

            $chkF = $conn->prepare("SELECT id FROM final_reports WHERE internship_id = ?");
            $chkF->bind_param("i", $active_id);
            $chkF->execute();
            $existF = $chkF->get_result()->fetch_assoc();
            $chkF->close();

            if ($existF) {
                $upF = $conn->prepare("UPDATE final_reports SET certificate_file = COALESCE(?, certificate_file), report_file = COALESCE(?, report_file), updated_at = NOW() WHERE id = ?");
                $upF->bind_param("ssi", $cert_file, $report_file, $existF['id']);
                $upF->execute();
                $upF->close();
                $message = 'Final completion documents updated successfully!';
                $messageType = 'success';
            } else {
                $inF = $conn->prepare("INSERT INTO final_reports (internship_id, certificate_file, report_file, created_at) VALUES (?, ?, ?, NOW())");
                $inF->bind_param("iss", $active_id, $cert_file, $report_file);
                $inF->execute();
                $inF->close();
                $message = 'Final completion certificate and report submitted!';
                $messageType = 'success';
            }
            $tab = 'certificate';
        }
    }

    // Refresh active internship after submission
    $latest_stmt = $conn->prepare("SELECT * FROM internships WHERE student_id = ? ORDER BY id DESC LIMIT 1");
    $latest_stmt->bind_param("i", $student_id);
    $latest_stmt->execute();
    $latest_internship = $latest_stmt->get_result()->fetch_assoc();
    $latest_stmt->close();
    $active_internship = $approved_internship ?: $latest_internship;
    $active_id = (int)($active_internship['id'] ?? 0);
}

// 4. Load Data for Tabs
// Applications history
$apps_list = [];
$apps_stmt = $conn->prepare("SELECT * FROM internships WHERE student_id = ? ORDER BY id DESC");
$apps_stmt->bind_param("i", $student_id);
$apps_stmt->execute();
$a_res = $apps_stmt->get_result();
while ($r = $a_res->fetch_assoc()) $apps_list[] = $r;
$apps_stmt->close();

// Weekly updates
$weekly_updates = [];
if ($active_id > 0) {
    $u_stmt = $conn->prepare("SELECT * FROM weekly_updates WHERE internship_id = ? ORDER BY week_no ASC");
    $u_stmt->bind_param("i", $active_id);
    $u_stmt->execute();
    $u_res = $u_stmt->get_result();
    while ($ur = $u_res->fetch_assoc()) $weekly_updates[(int)$ur['week_no']] = $ur;
    $u_stmt->close();
}

// Evaluations
$evaluation = null;
if ($active_id > 0) {
    $ev_stmt = $conn->prepare("SELECT * FROM evaluations WHERE internship_id = ? LIMIT 1");
    $ev_stmt->bind_param("i", $active_id);
    $ev_stmt->execute();
    $evaluation = $ev_stmt->get_result()->fetch_assoc();
    $ev_stmt->close();
}

// Final reports
$final_report = null;
if ($active_id > 0) {
    $fr_stmt = $conn->prepare("SELECT * FROM final_reports WHERE internship_id = ? LIMIT 1");
    $fr_stmt->bind_param("i", $active_id);
    $fr_stmt->execute();
    $final_report = $fr_stmt->get_result()->fetch_assoc();
    $fr_stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Internship — InternHub | MIT-ADT University</title>
    <?php require_once __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
    .workspace-wrapper {
        padding: 0 32px 32px;
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    /* Subnavigation Tab Bar */
    .workspace-nav-tabs {
        display: flex;
        align-items: center;
        gap: 8px;
        background: #ffffff;
        padding: 6px;
        border-radius: 999px;
        border: 1px solid rgba(226, 232, 240, 0.8);
        box-shadow: 0 2px 10px rgba(0,0,0,0.03);
        overflow-x: auto;
    }

    .workspace-nav-tab {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 20px;
        border-radius: 999px;
        font-size: 0.84rem;
        font-weight: 600;
        color: #475569;
        text-decoration: none;
        white-space: nowrap;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .workspace-nav-tab i {
        font-size: 0.95rem;
    }

    .workspace-nav-tab:hover {
        background: #f8fafc;
        color: #6d28d9;
    }

    .workspace-nav-tab.active {
        background: #5b21b6;
        color: #ffffff !important;
        box-shadow: 0 4px 12px rgba(91, 33, 182, 0.3);
    }

    .workspace-nav-tab.active i {
        color: #ffffff !important;
    }

    /* Content Cards */
    .workspace-card {
        background: #ffffff;
        border-radius: 20px;
        padding: 26px 30px;
        border: 1px solid rgba(226, 232, 240, 0.8);
        box-shadow: 0 4px 20px rgba(0,0,0,0.035);
    }

    .workspace-card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 22px;
        padding-bottom: 14px;
        border-bottom: 1px solid #f1f5f9;
    }

    .workspace-card-title {
        font-size: 1.25rem;
        font-weight: 800;
        color: #1e1b4b;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .workspace-card-title i {
        color: #7c3aed;
    }

    /* Form Styles */
    .form-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
    }

    .form-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .form-group.full-width {
        grid-column: span 2;
    }

    .form-label {
        font-size: 0.82rem;
        font-weight: 700;
        color: #334155;
    }

    .form-control {
        width: 100%;
        padding: 10px 14px;
        border-radius: 12px;
        border: 1px solid #cbd5e1;
        font-family: inherit;
        font-size: 0.88rem;
        color: #1e293b;
        background: #ffffff;
        outline: none;
        transition: all 0.2s ease;
    }

    .form-control:focus {
        border-color: #7c3aed;
        box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.12);
    }

    textarea.form-control {
        min-height: 110px;
        resize: vertical;
    }

    .btn-submit {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 11px 26px;
        border-radius: 999px;
        background: linear-gradient(135deg, #7c3aed, #5b21b6);
        color: #ffffff;
        font-weight: 700;
        font-size: 0.88rem;
        border: none;
        cursor: pointer;
        box-shadow: 0 4px 14px rgba(91, 33, 182, 0.28);
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .btn-submit:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(91, 33, 182, 0.38);
    }

    .btn-ai-sparkle {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px;
        border-radius: 999px;
        background: #ede9fe;
        color: #6d28d9;
        font-size: 0.78rem;
        font-weight: 700;
        border: 1px solid #ddd6fe;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .btn-ai-sparkle:hover {
        background: #ddd6fe;
        color: #4c1d95;
    }

    /* Status Badges */
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 12px;
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 700;
    }
    .badge-approved { background: #dcfce7; color: #15803d; }
    .badge-pending  { background: #fef3c7; color: #b45309; }
    .badge-rejected { background: #fee2e2; color: #b91c1c; }

    /* Tables */
    .workspace-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
    }

    .workspace-table th {
        text-align: left;
        padding: 12px 14px;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        color: #64748b;
        border-bottom: 2px solid #f1f5f9;
        letter-spacing: 0.05em;
    }

    .workspace-table td {
        padding: 14px;
        font-size: 0.85rem;
        color: #1e293b;
        border-bottom: 1px solid #f1f5f9;
    }

    /* Alert Banner */
    .alert-banner {
        padding: 12px 18px;
        border-radius: 14px;
        font-size: 0.86rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 20px;
    }
    .alert-success { background: #dcfce7; color: #14532d; border: 1px solid #bbf7d0; }
    .alert-danger  { background: #fee2e2; color: #7f1d1d; border: 1px solid #fecaca; }

    /* Timeline Cards */
    .timeline-list {
        display: flex;
        flex-direction: column;
        gap: 18px;
    }

    .timeline-card {
        border-radius: 16px;
        border: 1px solid #e2e8f0;
        padding: 18px 22px;
        background: #f8fafc;
        transition: all 0.2s ease;
    }

    .timeline-card.accepted {
        background: #ffffff;
        border-left: 5px solid #22c55e;
    }

    .timeline-card.pending {
        background: #ffffff;
        border-left: 5px solid #f59e0b;
    }

    .timeline-card.rejected {
        background: #ffffff;
        border-left: 5px solid #ef4444;
    }

    .timeline-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 10px;
    }

    .timeline-week-title {
        font-size: 0.96rem;
        font-weight: 800;
        color: #1e1b4b;
    }

    .timeline-body-text {
        font-size: 0.85rem;
        color: #334155;
        line-height: 1.5;
        white-space: pre-wrap;
    }

    .faculty-feedback-box {
        margin-top: 12px;
        padding: 12px 16px;
        background: #f1f5f9;
        border-radius: 12px;
        font-size: 0.82rem;
        color: #1e293b;
        border-left: 3px solid #7c3aed;
    }

    /* Marks Breakdown */
    .marks-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 16px;
        margin-top: 16px;
    }

    .mark-box {
        padding: 18px;
        border-radius: 16px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        text-align: center;
    }

    .mark-val {
        font-size: 1.8rem;
        font-weight: 800;
        color: #5b21b6;
    }

    .mark-label {
        font-size: 0.78rem;
        font-weight: 700;
        color: #64748b;
        margin-top: 4px;
    }

    @media (max-width: 900px) {
        .workspace-wrapper { padding: 0 16px 24px; }
        .form-grid { grid-template-columns: 1fr; }
        .form-group.full-width { grid-column: span 1; }
        .marks-grid { grid-template-columns: 1fr; }
    }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="workspace-wrapper">

    <!-- Subnavigation Tabs -->
    <nav class="workspace-nav-tabs">
        <a href="?tab=overview" class="workspace-nav-tab <?php echo $tab === 'overview' ? 'active' : ''; ?>">
            <i class="fa-solid fa-chart-pie"></i>
            <span>Overview</span>
        </a>
        <a href="?tab=apply" class="workspace-nav-tab <?php echo $tab === 'apply' ? 'active' : ''; ?>">
            <i class="fa-solid fa-file-circle-plus"></i>
            <span>Apply / Edit</span>
        </a>
        <a href="?tab=applications" class="workspace-nav-tab <?php echo $tab === 'applications' ? 'active' : ''; ?>">
            <i class="fa-solid fa-list-check"></i>
            <span>My Applications</span>
        </a>
        <a href="?tab=updates" class="workspace-nav-tab <?php echo $tab === 'updates' ? 'active' : ''; ?>">
            <i class="fa-solid fa-calendar-days"></i>
            <span>Weekly Reports</span>
        </a>
        <a href="?tab=marks" class="workspace-nav-tab <?php echo $tab === 'marks' ? 'active' : ''; ?>">
            <i class="fa-solid fa-award"></i>
            <span>Marks & Evaluation</span>
        </a>
        <a href="?tab=certificate" class="workspace-nav-tab <?php echo $tab === 'certificate' ? 'active' : ''; ?>">
            <i class="fa-solid fa-stamp"></i>
            <span>Final Certificate</span>
        </a>
    </nav>

    <?php if (!empty($message)): ?>
        <div class="alert-banner alert-<?php echo $messageType; ?>">
            <i class="fa-solid <?php echo $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
            <span><?php echo h($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- TAB 1: OVERVIEW -->
    <?php if ($tab === 'overview'): ?>
        <div class="workspace-card">
            <div class="workspace-card-header">
                <div class="workspace-card-title">
                    <i class="fa-solid fa-briefcase"></i>
                    <span>Active Internship Overview</span>
                </div>
                <?php if ($approved_internship): ?>
                    <span class="status-badge badge-approved"><i class="fa-solid fa-check"></i> Approved</span>
                <?php elseif ($latest_internship): ?>
                    <span class="status-badge badge-pending"><i class="fa-solid fa-clock"></i> <?php echo h($latest_internship['status']); ?></span>
                <?php else: ?>
                    <span class="status-badge badge-pending">No Active Application</span>
                <?php endif; ?>
            </div>

            <?php if ($active_internship): ?>
                <div class="form-grid">
                    <div class="form-group">
                        <span class="form-label">Company Name</span>
                        <div style="font-size:1.15rem; font-weight:800; color:#1e1b4b;"><?php echo h($active_internship['company_name']); ?></div>
                    </div>
                    <div class="form-group">
                        <span class="form-label">Internship Role</span>
                        <div style="font-size:1.05rem; font-weight:700; color:#5b21b6;"><?php echo h($active_internship['internship_role'] ?: 'Software Intern'); ?></div>
                    </div>
                    <div class="form-group">
                        <span class="form-label">Work Mode & Type</span>
                        <div style="font-size:0.92rem; font-weight:600; color:#334155;">
                            <?php echo h($active_internship['mode'] ?: 'Hybrid'); ?> • <?php echo h($active_internship['internship_type'] ?: 'External'); ?> (Stipend: <?php echo h($active_internship['stipend'] ?: 'Unpaid'); ?>)
                        </div>
                    </div>
                    <div class="form-group">
                        <span class="form-label">Duration</span>
                        <div style="font-size:0.92rem; font-weight:600; color:#334155;">
                            <?php echo h($active_internship['start_date'] ?: 'N/A'); ?> to <?php echo h($active_internship['end_date'] ?: 'N/A'); ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <span class="form-label">Assigned Faculty / Mentor</span>
                        <div style="font-size:0.92rem; font-weight:600; color:#334155;"><?php echo h($active_internship['mentor_name'] ?: 'Department Assigned Mentor'); ?></div>
                    </div>
                    <div class="form-group">
                        <span class="form-label">Capstone / Mini-Project Title</span>
                        <div style="font-size:0.92rem; font-weight:600; color:#334155;"><?php echo h($active_internship['mini_project_title'] ?: 'Not Specified'); ?></div>
                    </div>
                    <?php if (!empty($active_internship['offer_letter'])): ?>
                        <div class="form-group full-width">
                            <span class="form-label">Offer Letter</span>
                            <div>
                                <a href="../uploads/offer_letters/<?php echo rawurlencode($active_internship['offer_letter']); ?>" target="_blank" class="btn-ai-sparkle">
                                    <i class="fa-regular fa-file-pdf"></i>
                                    <span>Download Uploaded Offer Letter</span>
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div style="display:flex; gap:12px; margin-top:24px;">
                    <a href="?tab=updates" class="btn-submit">
                        <i class="fa-solid fa-calendar-days"></i>
                        <span>Go to Weekly Reports (<?php echo count($weekly_updates); ?> submitted)</span>
                    </a>
                    <a href="?tab=apply" class="btn-ai-sparkle" style="padding:10px 20px; font-size:0.86rem;">
                        <i class="fa-solid fa-pen-to-square"></i>
                        <span>Edit Details</span>
                    </a>
                </div>
            <?php else: ?>
                <div style="text-align:center; padding:40px 0;">
                    <i class="fa-solid fa-briefcase" style="font-size:3rem; color:#cbd5e1; margin-bottom:14px;"></i>
                    <h3 style="font-size:1.15rem; color:#1e1b4b; margin-bottom:6px;">No Internship Application on Record</h3>
                    <p style="font-size:0.85rem; color:#64748b; margin-bottom:18px;">Submit your internship details to begin your weekly reporting and academic evaluation.</p>
                    <a href="?tab=apply" class="btn-submit">
                        <i class="fa-solid fa-arrow-right"></i>
                        <span>Start Internship Application</span>
                    </a>
                </div>
            <?php endif; ?>
        </div>

    <!-- TAB 2: APPLY / EDIT FORM -->
    <?php elseif ($tab === 'apply'): ?>
        <div class="workspace-card">
            <div class="workspace-card-header">
                <div class="workspace-card-title">
                    <i class="fa-solid fa-file-circle-plus"></i>
                    <span><?php echo $approved_internship ? 'Edit Active Internship' : 'Internship Application Form'; ?></span>
                </div>
            </div>

            <form action="?tab=apply" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="apply_internship">

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Company Name *</label>
                        <input type="text" name="company_name" class="form-control" required value="<?php echo h($active_internship['company_name'] ?? ''); ?>" placeholder="e.g. Tata Consultancy Services, Infosys, Tech Mahindra">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Internship Role / Domain *</label>
                        <input type="text" name="internship_role" class="form-control" required value="<?php echo h($active_internship['internship_role'] ?? ''); ?>" placeholder="e.g. Full-Stack Developer Intern, Cloud Engineer">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Work Mode</label>
                        <select name="mode" class="form-control">
                            <option value="Hybrid" <?php echo ($active_internship['mode'] ?? '') === 'Hybrid' ? 'selected' : ''; ?>>Hybrid</option>
                            <option value="Offline" <?php echo ($active_internship['mode'] ?? '') === 'Offline' ? 'selected' : ''; ?>>Offline (On-Site)</option>
                            <option value="Online" <?php echo ($active_internship['mode'] ?? '') === 'Online' ? 'selected' : ''; ?>>Online (Remote)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Stipend Details</label>
                        <input type="text" name="stipend" class="form-control" value="<?php echo h($active_internship['stipend'] ?? ''); ?>" placeholder="e.g. ₹15,000 / month, or Unpaid">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Company Contact / HR Email</label>
                        <input type="text" name="company_contact" class="form-control" value="<?php echo h($active_internship['company_contact'] ?? ''); ?>" placeholder="e.g. hr@company.com or +91 9876543210">
                    </div>

                    <div class="form-group">
                        <label class="form-label">External Mentor Name</label>
                        <input type="text" name="mentor_name" class="form-control" value="<?php echo h($active_internship['mentor_name'] ?? ''); ?>" placeholder="Industry Mentor Full Name">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-control" value="<?php echo h($active_internship['start_date'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">End Date</label>
                        <input type="date" name="end_date" class="form-control" value="<?php echo h($active_internship['end_date'] ?? ''); ?>">
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label">Mini Project / Capstone Title</label>
                        <input type="text" name="mini_project_title" class="form-control" value="<?php echo h($active_internship['mini_project_title'] ?? ''); ?>" placeholder="Title of project developed during internship">
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label">Offer Letter / NOC (PDF or Image, max 5MB)</label>
                        <input type="file" name="offer_letter" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                        <?php if (!empty($active_internship['offer_letter'])): ?>
                            <small style="color:#64748b; margin-top:4px;">Currently attached: <strong><?php echo h($active_internship['offer_letter']); ?></strong></small>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="margin-top:24px;">
                    <button type="submit" class="btn-submit">
                        <i class="fa-solid fa-paper-plane"></i>
                        <span><?php echo $approved_internship ? 'Save Changes' : 'Submit Internship Application'; ?></span>
                    </button>
                </div>
            </form>
        </div>

    <!-- TAB 3: MY APPLICATIONS HISTORY -->
    <?php elseif ($tab === 'applications'): ?>
        <div class="workspace-card">
            <div class="workspace-card-header">
                <div class="workspace-card-title">
                    <i class="fa-solid fa-list-check"></i>
                    <span>Submitted Applications History</span>
                </div>
                <a href="?tab=apply" class="btn-ai-sparkle">
                    <i class="fa-solid fa-plus"></i>
                    <span>New Application</span>
                </a>
            </div>

            <?php if (!empty($apps_list)): ?>
                <table class="workspace-table">
                    <thead>
                        <tr>
                            <th>Company</th>
                            <th>Role</th>
                            <th>Mode</th>
                            <th>Dates</th>
                            <th>Status</th>
                            <th>Offer Letter</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($apps_list as $app): ?>
                            <tr>
                                <td style="font-weight:700; color:#1e1b4b;"><?php echo h($app['company_name']); ?></td>
                                <td><?php echo h($app['internship_role'] ?: 'Intern'); ?></td>
                                <td><?php echo h($app['mode'] ?: 'Hybrid'); ?></td>
                                <td><?php echo h($app['start_date']); ?> to <?php echo h($app['end_date']); ?></td>
                                <td>
                                    <?php if ($app['status'] === 'Approved'): ?>
                                        <span class="status-badge badge-approved"><i class="fa-solid fa-check"></i> Approved</span>
                                    <?php elseif ($app['status'] === 'Rejected'): ?>
                                        <span class="status-badge badge-rejected"><i class="fa-solid fa-xmark"></i> Rejected</span>
                                    <?php else: ?>
                                        <span class="status-badge badge-pending"><i class="fa-solid fa-clock"></i> Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($app['offer_letter'])): ?>
                                        <a href="../uploads/offer_letters/<?php echo rawurlencode($app['offer_letter']); ?>" target="_blank" style="color:#7c3aed; font-weight:600; text-decoration:none;">
                                            <i class="fa-regular fa-file-pdf"></i> View
                                        </a>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;">None</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="?tab=apply" style="color:#5b21b6; font-weight:600; font-size:0.82rem;">Details</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="text-align:center; padding:30px 0; color:#64748b;">No applications submitted yet.</p>
            <?php endif; ?>
        </div>

    <!-- TAB 4: WEEKLY REPORTS & AI POLISHER -->
    <?php elseif ($tab === 'updates'): ?>
        <div class="workspace-card">
            <div class="workspace-card-header">
                <div class="workspace-card-title">
                    <i class="fa-solid fa-calendar-days"></i>
                    <span>Weekly Reports & Updates</span>
                </div>
                <span class="status-badge badge-approved"><?php echo count($weekly_updates); ?> of 4 Weeks Submitted</span>
            </div>

            <!-- New Submission Form -->
            <div style="background:#f8fafc; border-radius:16px; padding:20px; border:1px solid #e2e8f0; margin-bottom:28px;">
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
                    <div style="font-size:0.95rem; font-weight:800; color:#1e1b4b;">Submit Weekly Work</div>
                    <button type="button" class="btn-ai-sparkle" id="btnAiPolishReport">
                        <i class="fa-solid fa-wand-magic-sparkles"></i>
                        <span>✨ AI Polish Report</span>
                    </button>
                </div>

                <form action="?tab=updates" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="submit_update">
                    <input type="hidden" name="internship_id" value="<?php echo $active_id; ?>">

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Week Number *</label>
                            <select name="week_no" id="reportWeekNo" class="form-control" required>
                                <?php for ($w = 1; $w <= 8; $w++): ?>
                                    <option value="<?php echo $w; ?>" <?php echo (count($weekly_updates) + 1 == $w) ? 'selected' : ''; ?>>Week <?php echo $w; ?> <?php echo isset($weekly_updates[$w]) ? '(Update Existing)' : ''; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Attachment (PDF / DOCX, max 5MB)</label>
                            <input type="file" name="update_file" class="form-control" accept=".pdf,.doc,.docx,.zip">
                        </div>

                        <div class="form-group full-width">
                            <label class="form-label">Work Done & Key Learnings *</label>
                            <textarea name="work_done" id="reportWorkDone" class="form-control" placeholder="Describe the technical tasks completed, tools used, and problem-solving milestones achieved this week..." required></textarea>
                            <small style="color:#64748b; margin-top:4px;">Tip: Type rough notes or bullet points, then click <strong>✨ AI Polish Report</strong> to refine into academic engineering format.</small>
                        </div>
                    </div>

                    <div style="margin-top:16px;">
                        <button type="submit" class="btn-submit">
                            <i class="fa-solid fa-paper-plane"></i>
                            <span>Submit Report</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Existing Weekly Timeline -->
            <div class="timeline-list">
                <?php for ($w = 1; $w <= 4; $w++): ?>
                    <?php if (isset($weekly_updates[$w])): ?>
                        <?php $rep = $weekly_updates[$w]; $status = strtolower($rep['review_status'] ?? 'pending'); ?>
                        <div class="timeline-card <?php echo in_array($status, ['accepted', 'approved']) ? 'accepted' : ($status === 'rejected' ? 'rejected' : 'pending'); ?>">
                            <div class="timeline-header">
                                <span class="timeline-week-title">Week <?php echo $w; ?> Report</span>
                                <?php if (in_array($status, ['accepted', 'approved'])): ?>
                                    <span class="status-badge badge-approved"><i class="fa-solid fa-check"></i> Accepted</span>
                                <?php elseif ($status === 'rejected'): ?>
                                    <span class="status-badge badge-rejected"><i class="fa-solid fa-xmark"></i> Rejected</span>
                                <?php else: ?>
                                    <span class="status-badge badge-pending"><i class="fa-solid fa-clock"></i> Pending Faculty Review</span>
                                <?php endif; ?>
                            </div>
                            <div class="timeline-body-text"><?php echo h($rep['work_done']); ?></div>

                            <?php if (!empty($rep['update_file'])): ?>
                                <div style="margin-top:10px;">
                                    <a href="../uploads/weekly_reports/<?php echo rawurlencode($rep['update_file']); ?>" target="_blank" style="color:#7c3aed; font-size:0.82rem; font-weight:600; text-decoration:none;">
                                        <i class="fa-solid fa-paperclip"></i> View Attached Report File
                                    </a>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($rep['faculty_feedback'])): ?>
                                <div class="faculty-feedback-box">
                                    <strong><i class="fa-regular fa-comment-dots"></i> Faculty Feedback:</strong><br>
                                    <?php echo nl2br(h($rep['faculty_feedback'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
        </div>

    <!-- TAB 5: EVALUATION & MARKS -->
    <?php elseif ($tab === 'marks'): ?>
        <div class="workspace-card">
            <div class="workspace-card-header">
                <div class="workspace-card-title">
                    <i class="fa-solid fa-award"></i>
                    <span>Continuous Assessment & Marks</span>
                </div>
            </div>

            <?php if ($evaluation): ?>
                <div class="marks-grid">
                    <div class="mark-box">
                        <div class="mark-val"><?php echo (int)($evaluation['ta1_marks'] ?? 0); ?></div>
                        <div class="mark-label">TA1 Marks</div>
                    </div>
                    <div class="mark-box">
                        <div class="mark-val"><?php echo (int)($evaluation['ta2_marks'] ?? 0); ?></div>
                        <div class="mark-label">TA2 Marks</div>
                    </div>
                    <div class="mark-box">
                        <div class="mark-val"><?php echo (int)($evaluation['attendance_marks'] ?? 0); ?></div>
                        <div class="mark-label">Attendance & Discipline</div>
                    </div>
                    <div class="mark-box">
                        <div class="mark-val"><?php echo (int)($evaluation['final_evaluation_marks'] ?? 0); ?></div>
                        <div class="mark-label">Final Presentation & Viva</div>
                    </div>
                    <div class="mark-box" style="grid-column: span 2; background:#ede9fe;">
                        <div class="mark-val" style="color:#4c1d95; font-size:2.4rem;"><?php echo (int)($evaluation['total_marks'] ?? 0); ?> / 100</div>
                        <div class="mark-label" style="color:#5b21b6; font-size:0.9rem;">Total Internship Score</div>
                    </div>
                </div>

                <?php if (!empty($evaluation['remarks'])): ?>
                    <div class="faculty-feedback-box" style="margin-top:24px;">
                        <strong><i class="fa-solid fa-pen-nib"></i> Faculty Evaluator Remarks:</strong><br>
                        <?php echo nl2br(h($evaluation['remarks'])); ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div style="text-align:center; padding:36px 0; color:#64748b;">
                    <i class="fa-solid fa-hourglass-half" style="font-size:2.5rem; color:#cbd5e1; margin-bottom:12px;"></i>
                    <h4 style="font-size:1.05rem; color:#1e1b4b; margin-bottom:4px;">Evaluation Pending</h4>
                    <p style="font-size:0.84rem;">Your assigned faculty mentor will grade your continuous reports and final evaluation upon completion.</p>
                </div>
            <?php endif; ?>
        </div>

    <!-- TAB 6: FINAL CERTIFICATE -->
    <?php elseif ($tab === 'certificate'): ?>
        <div class="workspace-card">
            <div class="workspace-card-header">
                <div class="workspace-card-title">
                    <i class="fa-solid fa-stamp"></i>
                    <span>Final Internship Completion Certificate</span>
                </div>
            </div>

            <?php if ($final_report && (!empty($final_report['certificate_file']) || !empty($final_report['report_file']))): ?>
                <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:16px; padding:20px; margin-bottom:24px;">
                    <div style="font-weight:700; color:#166534; font-size:1rem; margin-bottom:8px;">
                        <i class="fa-solid fa-circle-check"></i> Completion Documents on File
                    </div>
                    <p style="font-size:0.84rem; color:#15803d; margin-bottom:14px;">Your final completion certificate has been uploaded to the university archives.</p>
                    <div style="display:flex; gap:12px;">
                        <?php if (!empty($final_report['certificate_file'])): ?>
                            <a href="../uploads/final_reports/<?php echo rawurlencode($final_report['certificate_file']); ?>" target="_blank" class="btn-ai-sparkle">
                                <i class="fa-regular fa-file-pdf"></i>
                                <span>View Certificate</span>
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($final_report['report_file'])): ?>
                            <a href="../uploads/final_reports/<?php echo rawurlencode($final_report['report_file']); ?>" target="_blank" class="btn-ai-sparkle">
                                <i class="fa-solid fa-book"></i>
                                <span>View Final Report</span>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form action="?tab=certificate" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="submit_final_report">

                <div class="form-grid">
                    <div class="form-group full-width">
                        <label class="form-label">Upload Company Completion Certificate (PDF / Image) *</label>
                        <input type="file" name="certificate_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label">Upload Comprehensive Final Internship Report (PDF)</label>
                        <input type="file" name="report_file" class="form-control" accept=".pdf,.docx">
                    </div>
                </div>

                <div style="margin-top:20px;">
                    <button type="submit" class="btn-submit">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        <span>Upload Completion Documents</span>
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>

</div>

</main>
</div>

<?php require_once __DIR__ . '/includes/sidebar_scripts.php'; ?>

<!-- Script for AI Polish Report in Weekly Reports Tab -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    var polishBtn = document.getElementById('btnAiPolishReport');
    var workDoneInput = document.getElementById('reportWorkDone');
    var weekNoInput = document.getElementById('reportWeekNo');

    if (polishBtn && workDoneInput) {
        polishBtn.addEventListener('click', function () {
            var rawText = workDoneInput.value.trim();
            if (!rawText) {
                alert('Please enter some brief tasks or notes first, then click AI Polish.');
                workDoneInput.focus();
                return;
            }

            var origHtml = polishBtn.innerHTML;
            polishBtn.disabled = true;
            polishBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Polishing...';

            fetch('api/ai_assistant.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'polish_weekly_report',
                    week_no: weekNoInput ? weekNoInput.value : 1,
                    tasks_done: rawText
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                polishBtn.disabled = false;
                polishBtn.innerHTML = origHtml;
                if (res.success && res.polished_report) {
                    workDoneInput.value = res.polished_report;
                } else {
                    alert(res.error || 'AI Polish encountered an error.');
                }
            })
            .catch(function(err) {
                polishBtn.disabled = false;
                polishBtn.innerHTML = origHtml;
                alert('Error connecting to AI service.');
            });
        });
    }
});
</script>
</body>
</html>
