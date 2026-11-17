<?php
/**
 * InternHub — Student Dashboard
 * Exact pixel-faithful reproduction of approved reference: media_1794927520336.jpg
 */

session_start();
require_once __DIR__ . '/../config.php';

// Authentication & Role Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../login.php");
    exit;
}

$student_id = (int)$_SESSION['user_id'];
$activePage = 'dashboard.php';

// Helper functions
if (!function_exists('h')) {
    function h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

// 1. Fetch Student User Details
$stmt = $conn->prepare("SELECT id, full_name, email, phone, division, department, enrollment_no, roll_no, specialization, profile_photo FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    session_destroy();
    header("Location: ../login.php");
    exit;
}

$student_name = $student['full_name'] ?: 'Student';
$student_division = $student['division'] ?: 'SY-4';
$student_department = $student['department'] ?: 'SOC';
$enrollment_no = $student['enrollment_no'] ?: 'ADT24SOCB1260';
$student_roll = $student['roll_no'] ?: '';
$student_email = $student['email'] ?: '';
$student_phone = $student['phone'] ?: '';
$profile_photo = trim($student['profile_photo'] ?? '');

if (!empty($profile_photo) && file_exists(__DIR__ . "/../uploads/profile_photos/" . $profile_photo)) {
    $profile_image = "../uploads/profile_photos/" . rawurlencode($profile_photo);
} else {
    $profile_image = "";
}
$student_initial = strtoupper(substr(trim($student_name), 0, 1)) ?: 'S';

// 2. Fetch Internship Data
$current_internship = null;
$approved_internship = null;

$int_stmt = $conn->prepare("SELECT * FROM internships WHERE student_id = ? ORDER BY id DESC LIMIT 1");
$int_stmt->bind_param("i", $student_id);
$int_stmt->execute();
$current_internship = $int_stmt->get_result()->fetch_assoc();
$int_stmt->close();

$app_stmt = $conn->prepare("SELECT * FROM internships WHERE student_id = ? AND status = 'Approved' ORDER BY id DESC LIMIT 1");
$app_stmt->bind_param("i", $student_id);
$app_stmt->execute();
$approved_internship = $app_stmt->get_result()->fetch_assoc();
$app_stmt->close();

$active_internship = $approved_internship ?: $current_internship;
$active_internship_id = (int)($active_internship['id'] ?? 0);
$internship_status = $active_internship['status'] ?? 'None';

// 3. Metric Counts
$total_applications = 0;
$approved_count = 0;
$pending_count = 0;
$total_work = 0;

$t_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM internships WHERE student_id = ?");
$t_stmt->bind_param("i", $student_id);
$t_stmt->execute();
$total_applications = (int)($t_stmt->get_result()->fetch_assoc()['total'] ?? 0);
$t_stmt->close();

$a_stmt = $conn->prepare("SELECT COUNT(*) AS approved FROM internships WHERE student_id = ? AND status = 'Approved'");
$a_stmt->bind_param("i", $student_id);
$a_stmt->execute();
$approved_count = (int)($a_stmt->get_result()->fetch_assoc()['approved'] ?? 0);
$a_stmt->close();

$p_stmt = $conn->prepare("SELECT COUNT(*) AS pending FROM internships WHERE student_id = ? AND (status = 'Pending' OR status = '' OR status IS NULL)");
$p_stmt->bind_param("i", $student_id);
$p_stmt->execute();
$pending_count = (int)($p_stmt->get_result()->fetch_assoc()['pending'] ?? 0);
$p_stmt->close();

// Weekly updates submitted count
$submitted_weeks = [];
if ($active_internship_id > 0) {
    $w_stmt = $conn->prepare("SELECT week_no, review_status, work_done FROM weekly_updates WHERE internship_id = ? ORDER BY week_no ASC");
    $w_stmt->bind_param("i", $active_internship_id);
    $w_stmt->execute();
    $w_res = $w_stmt->get_result();
    while ($w_row = $w_res->fetch_assoc()) {
        $wn = (int)$w_row['week_no'];
        $submitted_weeks[$wn] = $w_row;
    }
    $w_stmt->close();
}
$total_work = count($submitted_weeks);

// Calculate progress percentage based on 4-week required curriculum
$total_target_weeks = 4;
$progress_percentage = min(100, round(($total_work / $total_target_weeks) * 100));
$stroke_dashoffset = round(283 - (283 * $progress_percentage / 100)); // 2 * pi * r (r=45) = ~283

// 4. Calendar Calculations (Current Week)
$today = new DateTime();
$dayOfWeek = (int)$today->format('N'); // 1 (Mon) through 7 (Sun)
$startOfWeek = clone $today;
$startOfWeek->modify('-' . ($dayOfWeek - 1) . ' days');

$weekDays = [];
for ($i = 0; $i < 7; $i++) {
    $d = clone $startOfWeek;
    $d->modify("+{$i} days");
    $weekDays[] = [
        'day_short' => $d->format('D'),
        'day_num' => (int)$d->format('j'),
        'is_today' => ($d->format('Y-m-d') === $today->format('Y-m-d')),
        'date_str' => $d->format('Y-m-d')
    ];
}
$current_month_year = $today->format('M Y');

// Next upcoming submission deadline reminder
$next_due_week = min(5, $total_work + 1);
$next_due_date = clone $startOfWeek;
$next_due_date->modify('+6 days'); // Sunday of current week
$next_due_date_str = $next_due_date->format('j M Y');

// 5. Notifications
$notifications_list = [];
$notif_res = mysqli_query($conn, "
    SELECT 'admin' AS source, id, title, message, attachment, created_at
    FROM admin_notifications
    WHERE target_role='student' OR target_role='all'
    UNION ALL
    SELECT 'faculty' AS source, id, 'Faculty Update' AS title, message, attachment, created_at
    FROM faculty_notifications
    WHERE division='{$student_division}'
    ORDER BY created_at DESC
    LIMIT 5
");
if ($notif_res) {
    while ($nrow = mysqli_fetch_assoc($notif_res)) {
        $notifications_list[] = $nrow;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard — InternHub | MIT-ADT University</title>
    <?php require_once __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
    /* Specific Dashboard Styles */
    .dashboard-container {
        padding: 0 32px 32px;
        display: flex;
        flex-direction: column;
        gap: 22px;
    }

    /* Hero Banner */
    .hero-banner-card {
        width: 100%;
        min-height: 195px;
        border-radius: 20px;
        background: #351046 url('../assets/images/campus_banner_golden.webp') right center / cover no-repeat;
        box-shadow: 0 10px 28px rgba(46, 13, 60, 0.22);
        display: flex;
        align-items: center;
        padding: 24px 34px;
        position: relative;
        overflow: hidden;
    }

    .hero-banner-content {
        max-width: 58%;
        z-index: 2;
    }

    .hero-pill-badge {
        display: inline-block;
        padding: 3px 12px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.15);
        border: 1px solid rgba(255, 255, 255, 0.25);
        color: #ffffff;
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        margin-bottom: 8px;
    }

    .hero-title {
        font-family: var(--ih-font-serif);
        font-size: 2.05rem;
        font-weight: 700;
        color: #ffffff;
        line-height: 1.15;
        margin-bottom: 6px;
    }

    .hero-title em {
        font-style: italic;
        color: #fbbf24;
        font-weight: 600;
    }

    .hero-subtitle {
        font-size: 0.82rem;
        color: rgba(255, 255, 255, 0.88);
        line-height: 1.45;
        margin-bottom: 18px;
    }

    .hero-actions-row {
        display: flex;
        align-items: center;
        gap: 14px;
        flex-wrap: wrap;
    }

    .hero-btn-primary {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 22px;
        background: #ffffff;
        color: #43185b;
        font-weight: 700;
        font-size: 0.84rem;
        border-radius: 999px;
        text-decoration: none;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.18);
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .hero-btn-primary:hover {
        background: #f8fafc;
        transform: translateY(-2px);
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.25);
        color: #311042;
    }

    .hero-btn-secondary {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 9px 20px;
        background: rgba(255, 255, 255, 0.18);
        border: 1px solid rgba(255, 255, 255, 0.4);
        color: #ffffff;
        font-weight: 600;
        font-size: 0.84rem;
        border-radius: 999px;
        text-decoration: none;
        backdrop-filter: blur(4px);
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .hero-btn-secondary:hover {
        background: rgba(255, 255, 255, 0.28);
        color: #ffffff;
        transform: translateY(-2px);
    }

    /* Metric Cards Grid */
    .metric-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 18px;
    }

    .metric-card {
        border-radius: 18px;
        padding: 16px 18px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        text-decoration: none;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.03);
        border: 1px solid rgba(0, 0, 0, 0.04);
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
    }

    .metric-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.07);
    }

    .metric-card-left {
        display: flex;
        align-items: center;
        gap: 14px;
    }

    .metric-icon-box {
        width: 44px;
        height: 44px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.15rem;
        color: #ffffff;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
    }

    .metric-info {
        display: flex;
        flex-direction: column;
    }

    .metric-label {
        font-size: 0.82rem;
        font-weight: 700;
        letter-spacing: -0.01em;
    }

    .metric-number {
        font-size: 1.6rem;
        font-weight: 800;
        line-height: 1.1;
        margin: 2px 0;
    }

    .metric-sub {
        font-size: 0.68rem;
        opacity: 0.75;
        font-weight: 500;
    }

    .metric-chevron-circle {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.72rem;
        opacity: 0.85;
    }

    /* Pastel Variants */
    .metric-purple {
        background-color: #f7f3ff;
    }
    .metric-purple .metric-icon-box { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }
    .metric-purple .metric-label { color: #5b21b6; }
    .metric-purple .metric-number { color: #3b0764; }
    .metric-purple .metric-sub { color: #6d28d9; }
    .metric-purple .metric-chevron-circle { background: #ede9fe; color: #5b21b6; }

    .metric-green {
        background-color: #f0fdf4;
    }
    .metric-green .metric-icon-box { background: linear-gradient(135deg, #22c55e, #16a34a); }
    .metric-green .metric-label { color: #15803d; }
    .metric-green .metric-number { color: #14532d; }
    .metric-green .metric-sub { color: #16a34a; }
    .metric-green .metric-chevron-circle { background: #dcfce7; color: #15803d; }

    .metric-amber {
        background-color: #fffbeb;
    }
    .metric-amber .metric-icon-box { background: linear-gradient(135deg, #f59e0b, #d97706); }
    .metric-amber .metric-label { color: #b45309; }
    .metric-amber .metric-number { color: #78350f; }
    .metric-amber .metric-sub { color: #b45309; }
    .metric-amber .metric-chevron-circle { background: #fef3c7; color: #b45309; }

    .metric-blue {
        background-color: #f0f9ff;
    }
    .metric-blue .metric-icon-box { background: linear-gradient(135deg, #38bdf8, #0284c7); }
    .metric-blue .metric-label { color: #0369a1; }
    .metric-blue .metric-number { color: #0c4a6e; }
    .metric-blue .metric-sub { color: #0284c7; }
    .metric-blue .metric-chevron-circle { background: #e0f2fe; color: #0369a1; }

    /* Middle Row Grid (3 Columns) */
    .middle-grid {
        display: grid;
        grid-template-columns: 1.1fr 1.25fr 1fr;
        gap: 20px;
    }

    .dashboard-panel-card {
        background: #ffffff;
        border-radius: 20px;
        padding: 20px 22px;
        box-shadow: 0 4px 18px rgba(0, 0, 0, 0.035);
        border: 1px solid rgba(226, 232, 240, 0.7);
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }

    .panel-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 16px;
    }

    .panel-title {
        font-size: 1.05rem;
        font-weight: 800;
        color: #1e1b4b;
        letter-spacing: -0.01em;
    }

    .status-pill-green {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 12px;
        border-radius: 999px;
        background: #dcfce7;
        color: #15803d;
        font-size: 0.72rem;
        font-weight: 700;
    }

    .status-pill-purple {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 12px;
        border-radius: 999px;
        background: #ede9fe;
        color: #6d28d9;
        font-size: 0.72rem;
        font-weight: 700;
    }

    /* Profile Overview Card Content */
    .profile-overview-body {
        display: flex;
        align-items: center;
        gap: 16px;
        margin-bottom: 14px;
    }

    .profile-overview-avatar {
        width: 62px;
        height: 62px;
        border-radius: 50%;
        background: #ede9fe;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.4rem;
        font-weight: 800;
        color: #6d28d9;
        box-shadow: 0 4px 14px rgba(0,0,0,0.08);
        border: 2px solid #ffffff;
        overflow: hidden;
        flex-shrink: 0;
    }

    .profile-overview-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .profile-overview-meta {
        display: flex;
        flex-direction: column;
        gap: 3px;
    }

    .profile-name-bold {
        font-size: 1.05rem;
        font-weight: 800;
        color: #1e1b4b;
        letter-spacing: -0.01em;
    }

    .profile-enrollment-text {
        font-size: 0.78rem;
        color: #64748b;
        font-weight: 600;
    }

    .profile-badges-row {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-top: 4px;
    }

    .profile-mini-pill {
        padding: 2px 9px;
        border-radius: 999px;
        background: #ede9fe;
        color: #5b21b6;
        font-size: 0.68rem;
        font-weight: 700;
    }

    .profile-contact-row {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 10px 0;
        border-top: 1px solid #f1f5f9;
        font-size: 0.76rem;
        color: #475569;
        margin-bottom: 12px;
        flex-wrap: wrap;
    }

    .profile-contact-item {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .profile-contact-item i {
        color: #7c3aed;
    }

    .btn-view-profile-outline {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        width: 100%;
        padding: 9px;
        border-radius: 999px;
        border: 1.5px solid #d8b4fe;
        background: #ffffff;
        color: #581c87;
        font-size: 0.82rem;
        font-weight: 700;
        text-decoration: none;
        transition: all 0.2s ease;
    }

    .btn-view-profile-outline:hover {
        background: #f5f3ff;
        border-color: #7c3aed;
        color: #43185b;
        transform: translateY(-1px);
    }

    /* Weekly Progress Card Content */
    .weekly-progress-body {
        display: flex;
        align-items: center;
        gap: 24px;
        padding: 10px 0;
    }

    .progress-gauge-box {
        position: relative;
        width: 110px;
        height: 110px;
        flex-shrink: 0;
    }

    .progress-gauge-svg {
        transform: rotate(-90deg);
        width: 100%;
        height: 100%;
    }

    .progress-gauge-bg {
        fill: none;
        stroke: #f1f5f9;
        stroke-width: 10;
    }

    .progress-gauge-val {
        fill: none;
        stroke: url(#progressGradient);
        stroke-width: 10;
        stroke-linecap: round;
        stroke-dasharray: 283;
        transition: stroke-dashoffset 0.8s ease-in-out;
    }

    .progress-gauge-text {
        position: absolute;
        inset: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
    }

    .progress-gauge-percent {
        font-size: 1.35rem;
        font-weight: 800;
        color: #1e1b4b;
        line-height: 1;
    }

    .progress-gauge-label {
        font-size: 0.65rem;
        color: #64748b;
        font-weight: 600;
        margin-top: 3px;
    }

    .weekly-checklist {
        display: flex;
        flex-direction: column;
        gap: 9px;
        flex: 1;
    }

    .checklist-item {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 0.82rem;
        font-weight: 600;
        color: #1e293b;
    }

    .checklist-badge-done {
        width: 20px;
        height: 20px;
        border-radius: 50%;
        background: #22c55e;
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.62rem;
        flex-shrink: 0;
    }

    .checklist-badge-pending {
        width: 20px;
        height: 20px;
        border-radius: 50%;
        background: #e2e8f0;
        color: #94a3b8;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.62rem;
        flex-shrink: 0;
    }

    /* Calendar & Reminders Widget */
    .calendar-strip-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 12px;
    }

    .calendar-month-text {
        font-size: 0.84rem;
        font-weight: 700;
        color: #1e1b4b;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .calendar-nav-arrow {
        color: #94a3b8;
        font-size: 0.75rem;
        cursor: pointer;
    }

    .weekdays-strip {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 6px;
        text-align: center;
        margin-bottom: 16px;
    }

    .weekday-col {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 4px;
    }

    .weekday-name {
        font-size: 0.66rem;
        font-weight: 600;
        color: #64748b;
    }

    .weekday-num {
        width: 30px;
        height: 30px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.78rem;
        font-weight: 700;
        color: #1e293b;
        transition: all 0.15s ease;
    }

    .weekday-num.active {
        background: #5b21b6;
        color: #ffffff;
        box-shadow: 0 4px 10px rgba(91, 33, 182, 0.35);
    }

    .reminders-divider {
        height: 1px;
        background: #f1f5f9;
        margin-bottom: 14px;
    }

    .reminders-title {
        font-size: 0.84rem;
        font-weight: 800;
        color: #1e1b4b;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .reminders-title i {
        color: #7c3aed;
    }

    .reminder-pill-card {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 10px 14px;
        background: #f5f3ff;
        border-radius: 14px;
        text-decoration: none;
        border: 1px solid #ede9fe;
        transition: all 0.2s ease;
    }

    .reminder-pill-card:hover {
        background: #ede9fe;
        transform: translateY(-1px);
    }

    .reminder-left {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .reminder-icon {
        width: 32px;
        height: 32px;
        border-radius: 10px;
        background: #7c3aed;
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.85rem;
    }

    .reminder-text-bold {
        font-size: 0.78rem;
        font-weight: 700;
        color: #1e1b4b;
    }

    .reminder-sub {
        font-size: 0.68rem;
        color: #64748b;
        font-weight: 500;
    }

    .reminder-chevron {
        font-size: 0.75rem;
        color: #7c3aed;
    }

    /* Responsive */
    @media (max-width: 1200px) {
        .metric-grid { grid-template-columns: repeat(2, 1fr); }
        .middle-grid { grid-template-columns: 1fr; }
        .hero-banner-content { max-width: 85%; }
    }

    @media (max-width: 768px) {
        .dashboard-container { padding: 0 16px 24px; }
        .hero-banner-card { padding: 20px; }
        .hero-title { font-size: 1.55rem; }
        .metric-grid { grid-template-columns: 1fr; }
    }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/includes/sidebar_nav.php'; ?>

<!-- Dashboard Content Container -->
<div class="dashboard-container">

    <!-- 1. Panoramic Hero Banner -->
    <section class="hero-banner-card">
        <div class="hero-banner-content">
            <span class="hero-pill-badge">MIT-ADT UNIVERSITY</span>
            <h1 class="hero-title">Your Internship Journey <em>Starts Here</em></h1>
            <p class="hero-subtitle">Apply for opportunities, submit your work, track your progress and earn your internship certification — all in one place.</p>
            <div class="hero-actions-row">
                <a href="my_internship.php?tab=apply" class="hero-btn-primary">
                    <i class="fa-solid fa-arrow-right"></i>
                    <span>Apply for Internship</span>
                </a>
                <a href="my_internship.php?tab=updates" class="hero-btn-secondary">
                    <i class="fa-regular fa-file-lines"></i>
                    <span>Submit Your Work</span>
                </a>
            </div>
        </div>
    </section>

    <!-- 2. Four Data-Driven Pastel Metric Cards -->
    <section class="metric-grid">
        <!-- Card 1: Total Applications -->
        <a href="my_internship.php?tab=applications" class="metric-card metric-purple">
            <div class="metric-card-left">
                <div class="metric-icon-box">
                    <i class="fa-solid fa-file-lines"></i>
                </div>
                <div class="metric-info">
                    <span class="metric-label">Total Applications</span>
                    <span class="metric-number"><?php echo $total_applications; ?></span>
                    <span class="metric-sub">All applications</span>
                </div>
            </div>
            <div class="metric-chevron-circle">
                <i class="fa-solid fa-chevron-right"></i>
            </div>
        </a>

        <!-- Card 2: Approved Applications -->
        <a href="my_internship.php?tab=applications" class="metric-card metric-green">
            <div class="metric-card-left">
                <div class="metric-icon-box">
                    <i class="fa-solid fa-check"></i>
                </div>
                <div class="metric-info">
                    <span class="metric-label">Approved</span>
                    <span class="metric-number"><?php echo $approved_count; ?></span>
                    <span class="metric-sub">Applications approved</span>
                </div>
            </div>
            <div class="metric-chevron-circle">
                <i class="fa-solid fa-chevron-right"></i>
            </div>
        </a>

        <!-- Card 3: Pending Applications -->
        <a href="my_internship.php?tab=applications" class="metric-card metric-amber">
            <div class="metric-card-left">
                <div class="metric-icon-box">
                    <i class="fa-solid fa-clock"></i>
                </div>
                <div class="metric-info">
                    <span class="metric-label">Pending</span>
                    <span class="metric-number"><?php echo $pending_count; ?></span>
                    <span class="metric-sub">Awaiting review</span>
                </div>
            </div>
            <div class="metric-chevron-circle">
                <i class="fa-solid fa-chevron-right"></i>
            </div>
        </a>

        <!-- Card 4: Weekly Work Submitted -->
        <a href="my_internship.php?tab=updates" class="metric-card metric-blue">
            <div class="metric-card-left">
                <div class="metric-icon-box">
                    <i class="fa-solid fa-calendar-check"></i>
                </div>
                <div class="metric-info">
                    <span class="metric-label">Weekly Work Submitted</span>
                    <span class="metric-number"><?php echo $total_work; ?></span>
                    <span class="metric-sub">This week submissions</span>
                </div>
            </div>
            <div class="metric-chevron-circle">
                <i class="fa-solid fa-chevron-right"></i>
            </div>
        </a>
    </section>

    <!-- 3. Middle Row: Profile Overview, Weekly Progress, Calendar & Reminders -->
    <section class="middle-grid">
        <!-- Card 1: Profile Overview -->
        <div class="dashboard-panel-card">
            <div>
                <div class="panel-header">
                    <span class="panel-title">Profile Overview</span>
                    <?php if ($internship_status === 'Approved'): ?>
                        <span class="status-pill-green"><i class="fa-solid fa-circle-check"></i> Internship Approved</span>
                    <?php elseif ($internship_status === 'Pending'): ?>
                        <span class="status-pill-purple"><i class="fa-solid fa-clock"></i> Review Pending</span>
                    <?php else: ?>
                        <span class="status-pill-purple"><i class="fa-solid fa-user-graduate"></i> Active Student</span>
                    <?php endif; ?>
                </div>

                <div class="profile-overview-body">
                    <div class="profile-overview-avatar">
                        <?php if (!empty($profile_image)): ?>
                            <img src="<?php echo h($profile_image); ?>" alt="Student Photo">
                        <?php else: ?>
                            <span><?php echo h($student_initial); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="profile-overview-meta">
                        <div class="profile-name-bold"><?php echo h($student_name); ?></div>
                        <div class="profile-enrollment-text"><?php echo h($enrollment_no); ?></div>
                        <div class="profile-badges-row">
                            <?php if (!empty($student_division)): ?>
                                <span class="profile-mini-pill"><?php echo h($student_division); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($student_department)): ?>
                                <span class="profile-mini-pill"><?php echo h($student_department); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($student_roll)): ?>
                                <span class="profile-mini-pill"><?php echo h($student_roll); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="profile-contact-row">
                    <?php if (!empty($student_email)): ?>
                        <div class="profile-contact-item">
                            <i class="fa-regular fa-envelope"></i>
                            <span><?php echo h($student_email); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($student_phone)): ?>
                        <div class="profile-contact-item">
                            <i class="fa-solid fa-phone"></i>
                            <span><?php echo h($student_phone); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <a href="my_portfolio.php" class="btn-view-profile-outline">
                <i class="fa-regular fa-user"></i>
                <span>View Full Profile</span>
                <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <!-- Card 2: Weekly Progress -->
        <div class="dashboard-panel-card">
            <div>
                <div class="panel-header">
                    <span class="panel-title">Weekly Progress</span>
                    <span class="status-pill-purple"><?php echo $progress_percentage; ?>% Completed</span>
                </div>

                <div class="weekly-progress-body">
                    <!-- Circular Gauge -->
                    <div class="progress-gauge-box">
                        <svg class="progress-gauge-svg" viewBox="0 0 100 100">
                            <defs>
                                <linearGradient id="progressGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                                    <stop offset="0%" stop-color="#8b5cf6" />
                                    <stop offset="100%" stop-color="#6d28d9" />
                                </linearGradient>
                            </defs>
                            <circle class="progress-gauge-bg" cx="50" cy="50" r="45"></circle>
                            <circle class="progress-gauge-val" cx="50" cy="50" r="45" style="stroke-dashoffset: <?php echo $stroke_dashoffset; ?>;"></circle>
                        </svg>
                        <div class="progress-gauge-text">
                            <span class="progress-gauge-percent"><?php echo $progress_percentage; ?>%</span>
                            <span class="progress-gauge-label">Complete</span>
                        </div>
                    </div>

                    <!-- Week by week checklist -->
                    <div class="weekly-checklist">
                        <?php for ($w = 1; $w <= 4; $w++): ?>
                            <?php $isDone = isset($submitted_weeks[$w]); ?>
                            <div class="checklist-item">
                                <?php if ($isDone): ?>
                                    <span class="checklist-badge-done"><i class="fa-solid fa-check"></i></span>
                                    <span>Week <?php echo $w; ?> – Submitted</span>
                                <?php else: ?>
                                    <span class="checklist-badge-pending"><i class="fa-regular fa-clock"></i></span>
                                    <span style="color:#64748b; font-weight:500;">Week <?php echo $w; ?> – Pending</span>
                                <?php endif; ?>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <!-- Soft bottom decoration -->
            <div style="height: 4px; background: linear-gradient(90deg, #ede9fe, transparent); border-radius: 999px;"></div>
        </div>

        <!-- Card 3: Calendar & Reminders -->
        <div class="dashboard-panel-card">
            <div>
                <div class="calendar-strip-header">
                    <span class="panel-title">This Week</span>
                    <span class="calendar-month-text">
                        <?php echo $current_month_year; ?>
                        <span class="calendar-nav-arrow"><i class="fa-solid fa-chevron-left"></i> <i class="fa-solid fa-chevron-right"></i></span>
                    </span>
                </div>

                <!-- Weekdays Mon-Sun Strip -->
                <div class="weekdays-strip">
                    <?php foreach ($weekDays as $wd): ?>
                        <div class="weekday-col">
                            <span class="weekday-name"><?php echo $wd['day_short']; ?></span>
                            <span class="weekday-num <?php echo $wd['is_today'] ? 'active' : ''; ?>">
                                <?php echo $wd['day_num']; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="reminders-divider"></div>

                <div class="reminders-title">
                    <i class="fa-regular fa-calendar"></i>
                    <span>Upcoming / Reminders</span>
                </div>

                <!-- Reminder Card -->
                <a href="my_internship.php?tab=updates" class="reminder-pill-card">
                    <div class="reminder-left">
                        <div class="reminder-icon">
                            <i class="fa-regular fa-calendar-check"></i>
                        </div>
                        <div>
                            <div class="reminder-text-bold">Weekly Work Submission (Week <?php echo $next_due_week; ?>)</div>
                            <div class="reminder-sub">Due: <?php echo $next_due_date_str; ?></div>
                        </div>
                    </div>
                    <i class="fa-solid fa-chevron-right reminder-chevron"></i>
                </a>
</div>

</main>
</div>

<?php require_once __DIR__ . '/includes/sidebar_scripts.php'; ?>
</body>
</html>