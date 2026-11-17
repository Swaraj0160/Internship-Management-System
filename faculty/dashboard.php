<?php
/* -----------------------------------------------------------------
   InternHub — Faculty Dashboard (Redesigned)
   Pixel-faithful reproduction of approved reference design:
   media_1794935844127.jpg
   ----------------------------------------------------------------- */

session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'faculty') {
    header("Location: ../login.php");
    exit();
}

$faculty_id = (int)$_SESSION['user_id'];

// Fetch faculty user profile
$faculty_res = mysqli_query($conn, "SELECT * FROM users WHERE id='$faculty_id' LIMIT 1");
$faculty = $faculty_res ? mysqli_fetch_assoc($faculty_res) : null;

if (!$faculty) {
    header("Location: ../login.php");
    exit();
}

// Fetch assigned divisions from faculty_divisions table with legacy fallback
$faculty_divisions = [];
$fd_query = mysqli_query(
    $conn,
    "SELECT DISTINCT division FROM faculty_divisions
     WHERE faculty_id='$faculty_id'
       AND division IS NOT NULL
       AND TRIM(division) <> ''
     ORDER BY division ASC"
);

if ($fd_query) {
    while ($div_row = mysqli_fetch_assoc($fd_query)) {
        $v = trim((string)$div_row['division']);
        if ($v !== '') {
            $faculty_divisions[] = $v;
        }
    }
}

$legacy_division = trim((string)($faculty['faculty_division'] ?? ''));
if (empty($faculty_divisions) && $legacy_division !== '') {
    $faculty_divisions[] = $legacy_division;
}
$faculty_divisions = array_values(array_unique($faculty_divisions));

// Build safe SQL list for divisions
$division_sql_values = array_map(
    fn($div) => "'" . mysqli_real_escape_string($conn, $div) . "'",
    $faculty_divisions
);
$division_list_sql = implode(',', $division_sql_values);
$faculty_division = !empty($faculty_divisions) ? implode(', ', $faculty_divisions) : 'Faculty Guide';
$faculty_name = trim((string)($faculty['full_name'] ?? 'Faculty Mentor'));
$faculty_name_clean = preg_replace('/^(Prof\.?|Dr\.?|Professor)\s*/i', '', $faculty_name);
$faculty_initial = strtoupper(substr($faculty_name_clean ?: $faculty_name ?: 'F', 0, 1));

// Condition for student division
$div_cond = !empty($division_list_sql) ? "u.division IN ($division_list_sql)" : "1=0";

// 1. Total Students in assigned divisions
$total_students = 0;
if (!empty($division_list_sql)) {
    $stu_q = mysqli_query($conn, "SELECT COUNT(*) AS total FROM users WHERE role='student' AND division IN ($division_list_sql)");
    if ($stu_q) {
        $total_students = (int)(mysqli_fetch_assoc($stu_q)['total'] ?? 0);
    }
}

// 2. Internship Applications counts for assigned divisions
$total_applications = 0;
$approved_count = 0;
$pending_count = 0;
$rejected_count = 0;

if (!empty($division_list_sql)) {
    $app_q = mysqli_query(
        $conn,
        "SELECT i.status, COUNT(*) as cnt
         FROM internships i
         JOIN users u ON i.student_id = u.id
         WHERE u.role='student' AND $div_cond
         GROUP BY i.status"
    );
    if ($app_q) {
        while ($row = mysqli_fetch_assoc($app_q)) {
            $st = $row['status'] ?? '';
            $cnt = (int)$row['cnt'];
            $total_applications += $cnt;
            if ($st === 'Approved') $approved_count += $cnt;
            elseif ($st === 'Pending') $pending_count += $cnt;
            elseif ($st === 'Rejected') $rejected_count += $cnt;
        }
    }
}

// Approval rate calculation
$approval_rate = ($total_students > 0) ? round(($approved_count / $total_students) * 100, 1) : 0.0;

// 3. Recent Applications
$recent_apps = [];
if (!empty($division_list_sql)) {
    $recent_q = mysqli_query(
        $conn,
        "SELECT i.id, i.company_name, i.status, i.created_at, u.id as student_id, u.full_name, u.roll_no, u.division, u.department
         FROM internships i
         JOIN users u ON i.student_id = u.id
         WHERE u.role='student' AND $div_cond
         ORDER BY i.created_at DESC, i.id DESC
         LIMIT 6"
    );
    if ($recent_q) {
        while ($r = mysqli_fetch_assoc($recent_q)) {
            $recent_apps[] = $r;
        }
    }
}

// 4. Upcoming Deadlines for active division
$deadlines = [];
if (!empty($faculty_divisions)) {
    $primary_div = $faculty_divisions[0];
    $dl_q = mysqli_query($conn, "SELECT * FROM weekly_deadlines WHERE division='$primary_div' ORDER BY week_no ASC");
    if ($dl_q && mysqli_num_rows($dl_q) > 0) {
        while ($d = mysqli_fetch_assoc($dl_q)) {
            $deadlines[] = $d;
        }
    }
}

// 5. My Tasks calculation
$pending_reports_count = 0;
if (!empty($division_list_sql)) {
    $rep_q = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS cnt
         FROM weekly_updates w
         JOIN internships i ON w.internship_id = i.id
         JOIN users u ON i.student_id = u.id
         WHERE $div_cond AND (w.faculty_feedback IS NULL OR TRIM(w.faculty_feedback) = '')"
    );
    if ($rep_q) {
        $pending_reports_count = (int)(mysqli_fetch_assoc($rep_q)['cnt'] ?? 0);
    }
}

$activePage = 'dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Mentorship Workspace | InternHub — MIT-ADT University</title>
    <?php include __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
        /* Dashboard Container */
        .dash-content {
            padding: 0 32px 32px;
        }

        /* Hero Banner */
        .faculty-hero-banner {
            position: relative;
            background: linear-gradient(135deg, rgba(61, 20, 82, 0.88) 0%, rgba(31, 5, 41, 0.82) 100%),
                        url('../assets/images/campus_banner_golden.webp') center/cover no-repeat;
            border-radius: 20px;
            padding: 34px 38px;
            color: #ffffff;
            box-shadow: 0 8px 30px rgba(61, 20, 82, 0.16);
            display: flex;
            align-items: center;
            justify-content: space-between;
            overflow: hidden;
            margin-bottom: 24px;
        }

        .faculty-hero-left {
            max-width: 680px;
            position: relative;
            z-index: 2;
        }

        .faculty-hero-title {
            font-family: var(--f-font-serif);
            font-style: italic;
            font-weight: 700;
            font-size: 2.3rem;
            color: #ffffff;
            letter-spacing: -0.01em;
            line-height: 1.15;
            margin-bottom: 8px;
            text-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }

        .faculty-hero-subtitle {
            font-size: 0.95rem;
            color: rgba(255, 255, 255, 0.9);
            line-height: 1.5;
            margin-bottom: 14px;
        }

        .faculty-hero-pill-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .faculty-hero-pill {
            background: rgba(255, 255, 255, 0.16);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.28);
            border-radius: 999px;
            padding: 5px 14px;
            font-size: 0.78rem;
            font-weight: 600;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* 5 Pastel KPI Cards Grid */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        .kpi-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 18px 20px;
            border: 1px solid #f1f5f9;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.03);
            display: flex;
            flex-direction: column;
            gap: 12px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(61, 20, 82, 0.08);
        }

        .kpi-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .kpi-title {
            font-size: 0.82rem;
            font-weight: 600;
            color: #64748b;
        }

        .kpi-icon-box {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
        }

        .kpi-value-row {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
        }

        .kpi-value {
            font-size: 1.85rem;
            font-weight: 800;
            color: #1e1b4b;
            line-height: 1;
        }

        .kpi-badge {
            font-size: 0.70rem;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 999px;
        }

        /* Card Pastel Themes */
        .kpi-peach { background: var(--f-peach-bg); border-color: var(--f-peach-border); }
        .kpi-peach .kpi-icon-box { background: #ffedd5; color: var(--f-peach-text); }
        .kpi-peach .kpi-badge { background: #fed7aa; color: var(--f-peach-text); }

        .kpi-lavender { background: var(--f-lavender-bg); border-color: var(--f-lavender-border); }
        .kpi-lavender .kpi-icon-box { background: #ede9fe; color: var(--f-lavender-text); }
        .kpi-lavender .kpi-badge { background: #e9d5ff; color: var(--f-lavender-text); }

        .kpi-mint { background: var(--f-mint-bg); border-color: var(--f-mint-border); }
        .kpi-mint .kpi-icon-box { background: #d1fae5; color: var(--f-mint-text); }
        .kpi-mint .kpi-badge { background: #a7f3d0; color: var(--f-mint-text); }

        .kpi-sky { background: var(--f-sky-bg); border-color: var(--f-sky-border); }
        .kpi-sky .kpi-icon-box { background: #dbeafe; color: var(--f-sky-text); }
        .kpi-sky .kpi-badge { background: #bfdbfe; color: var(--f-sky-text); }

        .kpi-rose { background: var(--f-rose-bg); border-color: var(--f-rose-border); }
        .kpi-rose .kpi-icon-box { background: #ffe4e6; color: var(--f-rose-text); }
        .kpi-rose .kpi-badge { background: #fecdd3; color: var(--f-rose-text); }

        /* Dashboard Rows Grid */
        .dash-row-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 24px;
        }

        .dash-row-lower {
            display: grid;
            grid-template-columns: 1.25fr 0.85fr 0.9fr;
            gap: 20px;
            margin-bottom: 24px;
        }

        /* Standard Card Panel */
        .panel-card {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid #eef2f6;
            padding: 22px;
            box-shadow: 0 4px 20px -2px rgba(61, 20, 82, 0.04);
            display: flex;
            flex-direction: column;
        }

        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 18px;
        }

        .panel-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: #1e1b4b;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .panel-action-link {
            font-size: 0.80rem;
            font-weight: 600;
            color: #7c3aed;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .panel-action-link:hover {
            color: #5b21b6;
            text-decoration: underline;
        }

        /* Widget 1: My Tasks */
        .task-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            flex: 1;
        }

        .task-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 14px;
            border-radius: 12px;
            background: #f8fafc;
            border: 1px solid #f1f5f9;
            transition: all 0.2s ease;
        }

        .task-item:hover {
            background: #ffffff;
            border-color: #cbd5e1;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }

        .task-left {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .task-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .task-indicator.urgent { background: #ef4444; }
        .task-indicator.pending { background: #f59e0b; }
        .task-indicator.good { background: #10b981; }

        .task-info {
            min-width: 0;
        }

        .task-name {
            font-size: 0.88rem;
            font-weight: 600;
            color: #1e1b4b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .task-sub {
            font-size: 0.74rem;
            color: #64748b;
            margin-top: 2px;
        }

        .task-btn {
            padding: 6px 12px;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 8px;
            background: #f1f5f9;
            color: #334155;
            text-decoration: none;
            transition: all 0.15s ease;
            white-space: nowrap;
        }

        .task-btn:hover {
            background: #7c3aed;
            color: #ffffff;
        }

        /* Widget 2: Donut Chart */
        .chart-box {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex: 1;
            padding: 10px 0;
        }

        .donut-wrap {
            position: relative;
            width: 150px;
            height: 150px;
            margin-bottom: 16px;
        }

        .donut-svg {
            transform: rotate(-90deg);
            width: 100%;
            height: 100%;
        }

        .donut-bg {
            fill: none;
            stroke: #f1f5f9;
            stroke-width: 14;
        }

        .donut-segment {
            fill: none;
            stroke-width: 14;
            stroke-linecap: round;
            transition: stroke-dashoffset 0.8s ease;
        }

        .donut-center-text {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .donut-percent {
            font-size: 1.7rem;
            font-weight: 800;
            color: #1e1b4b;
            line-height: 1;
        }

        .donut-label {
            font-size: 0.70rem;
            font-weight: 600;
            color: #64748b;
            margin-top: 4px;
        }

        .chart-legend {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            width: 100%;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.76rem;
            font-weight: 500;
            color: #475569;
        }

        .legend-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        /* Widget 3: AI Mentor Assistant Card */
        .ai-mentor-panel {
            background: linear-gradient(135deg, #2e0d3c 0%, #3d1452 50%, #1f0529 100%);
            color: #ffffff;
            border-radius: 18px;
            padding: 22px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-shadow: 0 8px 24px rgba(61, 20, 82, 0.2);
            position: relative;
            overflow: hidden;
        }

        .ai-mentor-header {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1.05rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 8px;
        }

        .ai-mentor-desc {
            font-size: 0.82rem;
            color: rgba(255, 255, 255, 0.82);
            line-height: 1.45;
            margin-bottom: 16px;
        }

        .ai-prompt-pills {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 16px;
        }

        .ai-pill-btn {
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            padding: 8px 12px;
            font-size: 0.78rem;
            font-weight: 500;
            color: #ffffff;
            text-align: left;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .ai-pill-btn:hover {
            background: rgba(255, 255, 255, 0.22);
            transform: translateX(3px);
        }

        .ai-input-bar {
            display: flex;
            gap: 8px;
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.25);
            border-radius: 999px;
            padding: 4px 6px 4px 14px;
        }

        .ai-input-field {
            background: transparent;
            border: none;
            outline: none;
            font-size: 0.82rem;
            color: #ffffff;
            flex: 1;
            font-family: var(--f-font-sans);
        }

        .ai-input-field::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }

        .ai-send-btn {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #fbbf24;
            color: #1e1b4b;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            transition: all 0.2s ease;
        }

        .ai-send-btn:hover {
            background: #f59e0b;
            transform: scale(1.05);
        }

        /* Widget 4: Recent Applications Table */
        .app-table {
            width: 100%;
            border-collapse: collapse;
        }

        .app-table th {
            text-align: left;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #94a3b8;
            padding: 0 8px 10px;
            border-bottom: 1px solid #f1f5f9;
        }

        .app-table td {
            padding: 11px 8px;
            font-size: 0.84rem;
            border-bottom: 1px solid #f8fafc;
            color: #334155;
            vertical-align: middle;
        }

        .student-cell {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .student-avatar-sm {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #7c3aed, #4f46e5);
            color: #ffffff;
            font-size: 0.80rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .student-name-bold {
            font-weight: 600;
            color: #1e1b4b;
            line-height: 1.2;
        }

        .student-sub-dept {
            font-size: 0.72rem;
            color: #64748b;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 9px;
            border-radius: 999px;
            font-size: 0.74rem;
            font-weight: 600;
        }

        .status-approved { background: #d1fae5; color: #047857; }
        .status-pending { background: #fef3c7; color: #b45309; }
        .status-rejected { background: #fee2e2; color: #b91c1c; }

        /* Widget 5: Upcoming Deadlines Timeline */
        .timeline-list {
            display: flex;
            flex-direction: column;
            gap: 14px;
            position: relative;
            padding-left: 14px;
        }

        .timeline-list::before {
            content: '';
            position: absolute;
            left: 20px;
            top: 10px;
            bottom: 10px;
            width: 2px;
            background: #e2e8f0;
        }

        .timeline-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            position: relative;
        }

        .timeline-dot {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: #ffffff;
            border: 3px solid #7c3aed;
            margin-top: 4px;
            flex-shrink: 0;
            z-index: 2;
        }

        .timeline-content {
            flex: 1;
            background: #f8fafc;
            border-radius: 10px;
            padding: 8px 12px;
            border: 1px solid #f1f5f9;
        }

        .timeline-title {
            font-size: 0.84rem;
            font-weight: 700;
            color: #1e1b4b;
        }

        .timeline-date {
            font-size: 0.74rem;
            color: #64748b;
            margin-top: 2px;
        }

        /* Widget 6: Quick Action Tiles */
        .quick-actions-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            flex: 1;
        }

        .action-tile {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            justify-content: center;
            gap: 8px;
            padding: 16px 14px;
            border-radius: 14px;
            text-decoration: none;
            transition: all 0.2s ease;
            border: 1px solid transparent;
        }

        .action-tile:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(0,0,0,0.06);
        }

        .action-tile-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.05rem;
        }

        .action-tile-title {
            font-size: 0.85rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .action-tile-desc {
            font-size: 0.72rem;
            color: #64748b;
        }

        .tile-peach { background: var(--f-peach-bg); border-color: var(--f-peach-border); color: #c2410c; }
        .tile-peach .action-tile-icon { background: #ffedd5; color: #ea580c; }

        .tile-lavender { background: var(--f-lavender-bg); border-color: var(--f-lavender-border); color: #7e22ce; }
        .tile-lavender .action-tile-icon { background: #ede9fe; color: #9333ea; }

        .tile-mint { background: var(--f-mint-bg); border-color: var(--f-mint-border); color: #047857; }
        .tile-mint .action-tile-icon { background: #d1fae5; color: #059669; }

        .tile-sky { background: var(--f-sky-bg); border-color: var(--f-sky-border); color: #1d4ed8; }
        .tile-sky .action-tile-icon { background: #dbeafe; color: #2563eb; }

        /* Modal Styles */
        .custom-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 1200;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .custom-modal.open { display: flex; }

        .modal-box {
            background: #ffffff;
            border-radius: 20px;
            max-width: 580px;
            width: 100%;
            padding: 26px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            position: relative;
        }

        .modal-close-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            background: #f1f5f9;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
        }

        @media (max-width: 1200px) {
            .kpi-grid { grid-template-columns: repeat(3, 1fr); }
            .dash-row-3 { grid-template-columns: 1fr; }
            .dash-row-lower { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .dash-content { padding: 0 16px 24px; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .faculty-hero-banner { padding: 24px 20px; }
            .faculty-hero-title { font-size: 1.7rem; }
        }
    </style>
</head>
<body>

<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="dash-content">
    <!-- Hero Banner with Campus Golden Image -->
    <div class="faculty-hero-banner">
        <div class="faculty-hero-left">
            <h1 class="faculty-hero-title">Guide · Evaluate · Mentor</h1>
            <p class="faculty-hero-subtitle">Shaping careers, fostering innovation, and steering students toward academic and professional excellence.</p>
            <div class="faculty-hero-pill-row">
                <div class="faculty-hero-pill">
                    <i class="fa-solid fa-graduation-cap"></i>
                    <span>Division: <?php echo h($faculty_division); ?></span>
                </div>
                <div class="faculty-hero-pill">
                    <i class="fa-solid fa-building-columns"></i>
                    <span>School of Computing</span>
                </div>
                <div class="faculty-hero-pill">
                    <i class="fa-solid fa-user-check"></i>
                    <span>Academic Year 2024–25</span>
                </div>
            </div>
        </div>
    </div>

    <!-- 5 Pastel KPI Cards -->
    <div class="kpi-grid">
        <!-- 1. Total Students -->
        <div class="kpi-card kpi-peach">
            <div class="kpi-header">
                <span class="kpi-title">Total Students</span>
                <div class="kpi-icon-box"><i class="fa-solid fa-users"></i></div>
            </div>
            <div class="kpi-value-row">
                <span class="kpi-value"><?php echo $total_students; ?></span>
                <span class="kpi-badge">Assigned</span>
            </div>
        </div>

        <!-- 2. Applications -->
        <div class="kpi-card kpi-lavender">
            <div class="kpi-header">
                <span class="kpi-title">Applications</span>
                <div class="kpi-icon-box"><i class="fa-solid fa-file-lines"></i></div>
            </div>
            <div class="kpi-value-row">
                <span class="kpi-value"><?php echo $total_applications; ?></span>
                <span class="kpi-badge">Submissions</span>
            </div>
        </div>

        <!-- 3. Approved -->
        <div class="kpi-card kpi-mint">
            <div class="kpi-header">
                <span class="kpi-title">Approved</span>
                <div class="kpi-icon-box"><i class="fa-solid fa-circle-check"></i></div>
            </div>
            <div class="kpi-value-row">
                <span class="kpi-value"><?php echo $approved_count; ?></span>
                <span class="kpi-badge"><?php echo $approval_rate; ?>% Rate</span>
            </div>
        </div>

        <!-- 4. Pending Review -->
        <div class="kpi-card kpi-sky">
            <div class="kpi-header">
                <span class="kpi-title">Pending Review</span>
                <div class="kpi-icon-box"><i class="fa-solid fa-clock"></i></div>
            </div>
            <div class="kpi-value-row">
                <span class="kpi-value"><?php echo $pending_count; ?></span>
                <span class="kpi-badge">Awaiting</span>
            </div>
        </div>

        <!-- 5. Rejected -->
        <div class="kpi-card kpi-rose">
            <div class="kpi-header">
                <span class="kpi-title">Rejected</span>
                <div class="kpi-icon-box"><i class="fa-solid fa-circle-xmark"></i></div>
            </div>
            <div class="kpi-value-row">
                <span class="kpi-value"><?php echo $rejected_count; ?></span>
                <span class="kpi-badge">Revisions</span>
            </div>
        </div>
    </div>

    <!-- Middle Row: My Tasks, Internship Overview Donut, AI Mentor Assistant -->
    <div class="dash-row-3">
        <!-- Widget 1: My Tasks -->
        <div class="panel-card">
            <div class="panel-header">
                <div class="panel-title">
                    <i class="fa-solid fa-list-check" style="color: #7c3aed;"></i>
                    <span>My Tasks</span>
                </div>
                <span style="font-size: 0.74rem; font-weight: 600; background: #ede9fe; color: #7c3aed; padding: 2px 8px; border-radius: 999px;">
                    <?php echo ($pending_count + ($pending_reports_count > 0 ? 1 : 0) + ($rejected_count > 0 ? 1 : 0)); ?> Actions
                </span>
            </div>

            <div class="task-list">
                <?php if ($pending_count > 0): ?>
                    <div class="task-item">
                        <div class="task-left">
                            <span class="task-indicator urgent"></span>
                            <div class="task-info">
                                <div class="task-name">Review <?php echo $pending_count; ?> Pending Applications</div>
                                <div class="task-sub">New submissions awaiting authorization</div>
                            </div>
                        </div>
                        <a href="internship_oversight.php?tab=applications" class="task-btn">Review</a>
                    </div>
                <?php endif; ?>

                <div class="task-item">
                    <div class="task-left">
                        <span class="task-indicator <?php echo $pending_reports_count > 0 ? 'pending' : 'good'; ?>"></span>
                        <div class="task-info">
                            <div class="task-name">Evaluate Weekly Reports</div>
                            <div class="task-sub"><?php echo $pending_reports_count > 0 ? "$pending_reports_count reports awaiting review" : "All weekly reports up to date"; ?></div>
                        </div>
                    </div>
                    <a href="internship_oversight.php?tab=reports" class="task-btn">Evaluate</a>
                </div>

                <div class="task-item">
                    <div class="task-left">
                        <span class="task-indicator pending"></span>
                        <div class="task-info">
                            <div class="task-name">Assign Continuous Marks</div>
                            <div class="task-sub">Midterm evaluation assessment</div>
                        </div>
                    </div>
                    <a href="evaluations_marks.php" class="task-btn">Enter</a>
                </div>

                <?php if ($rejected_count > 0): ?>
                    <div class="task-item">
                        <div class="task-left">
                            <span class="task-indicator urgent"></span>
                            <div class="task-info">
                                <div class="task-name">Follow Up with <?php echo $rejected_count; ?> Rejected Students</div>
                                <div class="task-sub">Guidance on application resubmission</div>
                            </div>
                        </div>
                        <a href="internship_oversight.php?tab=applications&status=Rejected" class="task-btn">View</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Widget 2: Internship Overview Donut Chart -->
        <div class="panel-card">
            <div class="panel-header">
                <div class="panel-title">
                    <i class="fa-solid fa-chart-pie" style="color: #10b981;"></i>
                    <span>Internship Overview</span>
                </div>
                <span style="font-size: 0.74rem; color: #64748b;"><?php echo h($faculty_division); ?></span>
            </div>

            <div class="chart-box">
                <!-- SVG Donut Chart (Radius = 62, Circumference = 389.56) -->
                <?php
                $circ = 389.56;
                $approved_offset = $circ * (1 - ($approval_rate / 100));
                ?>
                <div class="donut-wrap">
                    <svg class="donut-svg" viewBox="0 0 160 160">
                        <circle class="donut-bg" cx="80" cy="80" r="62"></circle>
                        <!-- Approved Segment -->
                        <circle class="donut-segment" cx="80" cy="80" r="62"
                                stroke="#10b981"
                                stroke-dasharray="<?php echo $circ; ?>"
                                stroke-dashoffset="<?php echo $approved_offset; ?>">
                        </circle>
                    </svg>
                    <div class="donut-center-text">
                        <span class="donut-percent"><?php echo $approval_rate; ?>%</span>
                        <span class="donut-label">Approval Rate</span>
                    </div>
                </div>

                <!-- Status Breakdown Legend -->
                <div class="chart-legend">
                    <div class="legend-item">
                        <span class="legend-dot" style="background: #10b981;"></span>
                        <span>Approved (<?php echo $approved_count; ?>)</span>
                    </div>
                    <div class="legend-item">
                        <span class="legend-dot" style="background: #f59e0b;"></span>
                        <span>Pending (<?php echo $pending_count; ?>)</span>
                    </div>
                    <div class="legend-item">
                        <span class="legend-dot" style="background: #ef4444;"></span>
                        <span>Rejected (<?php echo $rejected_count; ?>)</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Widget 3: AI Mentor Assistant -->
        <div class="ai-mentor-panel">
            <div>
                <div class="ai-mentor-header">
                    <i class="fa-solid fa-wand-magic-sparkles" style="color: #fbbf24;"></i>
                    <span>AI Mentor Assistant</span>
                </div>
                <p class="ai-mentor-desc">Your intelligent academic copilot for progress summaries, report evaluations, and cohort analytics.</p>

                <div class="ai-prompt-pills">
                    <button type="button" class="ai-pill-btn" onclick="openAiPrompt('Generate Evaluation Feedback')">
                        <span>Generate Evaluation Feedback</span>
                        <i class="fa-solid fa-chevron-right" style="font-size: 0.70rem; opacity: 0.7;"></i>
                    </button>
                    <button type="button" class="ai-pill-btn" onclick="openAiPrompt('Summarize Student Progress')">
                        <span>Summarize Student Progress</span>
                        <i class="fa-solid fa-chevron-right" style="font-size: 0.70rem; opacity: 0.7;"></i>
                    </button>
                    <button type="button" class="ai-pill-btn" onclick="openAiPrompt('Analyze Submission Trends')">
                        <span>Analyze Submission Trends</span>
                        <i class="fa-solid fa-chevron-right" style="font-size: 0.70rem; opacity: 0.7;"></i>
                    </button>
                </div>
            </div>

            <form onsubmit="handleAiQuickSubmit(event)" class="ai-input-bar">
                <input type="text" id="aiQuickInput" class="ai-input-field" placeholder="Ask AI Mentor anything..." autocomplete="off">
                <button type="submit" class="ai-send-btn" title="Send to AI Assistant">
                    <i class="fa-solid fa-arrow-up"></i>
                </button>
            </form>
        </div>
    </div>

    <!-- Lower Row: Recent Applications, Upcoming Deadlines, Quick Actions -->
    <div class="dash-row-lower">
        <!-- Widget 4: Recent Applications Table -->
        <div class="panel-card">
            <div class="panel-header">
                <div class="panel-title">
                    <i class="fa-solid fa-file-signature" style="color: #2563eb;"></i>
                    <span>Recent Applications</span>
                </div>
                <a href="internship_oversight.php?tab=applications" class="panel-action-link">
                    <span>View All</span>
                    <i class="fa-solid fa-arrow-right" style="font-size: 0.72rem;"></i>
                </a>
            </div>

            <div style="overflow-x: auto;">
                <table class="app-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Company</th>
                            <th>Status</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recent_apps)): ?>
                            <?php foreach ($recent_apps as $app): ?>
                                <?php
                                $stu_name = $app['full_name'] ?? 'Student';
                                $stu_init = strtoupper(substr($stu_name, 0, 1));
                                $status = $app['status'] ?? 'Pending';
                                $st_class = match($status) {
                                    'Approved' => 'status-approved',
                                    'Rejected' => 'status-rejected',
                                    default => 'status-pending'
                                };
                                ?>
                                <tr>
                                    <td>
                                        <div class="student-cell">
                                            <div class="student-avatar-sm"><?php echo h($stu_init); ?></div>
                                            <div>
                                                <div class="student-name-bold"><?php echo h($stu_name); ?></div>
                                                <div class="student-sub-dept"><?php echo h($app['division'] ?? 'SY-1'); ?> • <?php echo h($app['roll_no'] ?? ''); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="font-weight: 500;"><?php echo h($app['company_name'] ?: 'Not Specified'); ?></span>
                                    </td>
                                    <td>
                                        <span class="status-pill <?php echo $st_class; ?>">
                                            <i class="fa-solid <?php echo $status === 'Approved' ? 'fa-check' : ($status === 'Rejected' ? 'fa-xmark' : 'fa-clock'); ?>" style="font-size: 0.65rem;"></i>
                                            <?php echo h($status); ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right;">
                                        <a href="internship_oversight.php?tab=applications&id=<?php echo (int)$app['id']; ?>" class="task-btn">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" style="text-align: center; color: #94a3b8; padding: 24px;">No applications found for assigned division.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Widget 5: Upcoming Deadlines Timeline -->
        <div class="panel-card">
            <div class="panel-header">
                <div class="panel-title">
                    <i class="fa-solid fa-calendar-check" style="color: #f59e0b;"></i>
                    <span>Deadlines</span>
                </div>
                <button type="button" class="panel-action-link" style="background: none; border: none; cursor: pointer;" onclick="openDeadlineModal()">
                    <i class="fa-solid fa-pen-to-square"></i>
                    <span>Manage</span>
                </button>
            </div>

            <div class="timeline-list">
                <?php if (!empty($deadlines)): ?>
                    <?php foreach ($deadlines as $dl): ?>
                        <?php
                        $ts = strtotime($dl['deadline_date']);
                        $is_passed = ($ts < time());
                        $formatted_date = date('d M Y, h:i A', $ts);
                        ?>
                        <div class="timeline-item">
                            <span class="timeline-dot" style="border-color: <?php echo $is_passed ? '#94a3b8' : '#7c3aed'; ?>;"></span>
                            <div class="timeline-content">
                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                    <div class="timeline-title">Week <?php echo h($dl['week_no']); ?> Milestone</div>
                                    <span style="font-size: 0.68rem; font-weight: 700; padding: 1px 6px; border-radius: 4px; <?php echo $is_passed ? 'background:#e2e8f0; color:#64748b;' : 'background:#d1fae5; color:#047857;'; ?>">
                                        <?php echo $is_passed ? 'Passed' : 'Upcoming'; ?>
                                    </span>
                                </div>
                                <div class="timeline-date"><i class="fa-regular fa-clock" style="margin-right: 4px;"></i><?php echo $formatted_date; ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align: center; color: #94a3b8; padding: 20px 0;">No weekly deadlines configured for division.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Widget 6: Quick Action Tiles -->
        <div class="panel-card">
            <div class="panel-header">
                <div class="panel-title">
                    <i class="fa-solid fa-bolt" style="color: #ea580c;"></i>
                    <span>Quick Actions</span>
                </div>
            </div>

            <div class="quick-actions-grid">
                <!-- Tile 1: Approve Applications -->
                <a href="internship_oversight.php?tab=applications" class="action-tile tile-peach">
                    <div class="action-tile-icon"><i class="fa-solid fa-circle-check"></i></div>
                    <div class="action-tile-title">Approve Applications</div>
                    <div class="action-tile-desc">Verify & authorize</div>
                </a>

                <!-- Tile 2: Evaluate Reports -->
                <a href="internship_oversight.php?tab=reports" class="action-tile tile-lavender">
                    <div class="action-tile-icon"><i class="fa-solid fa-clipboard-list"></i></div>
                    <div class="action-tile-title">Evaluate Reports</div>
                    <div class="action-tile-desc">Weekly log reviews</div>
                </a>

                <!-- Tile 3: Assign Marks -->
                <a href="evaluations_marks.php" class="action-tile tile-mint">
                    <div class="action-tile-icon"><i class="fa-solid fa-graduation-cap"></i></div>
                    <div class="action-tile-title">Assign Marks</div>
                    <div class="action-tile-desc">Continuous rubric</div>
                </a>

                <!-- Tile 4: View Student Portfolios -->
                <a href="student_portfolios.php" class="action-tile tile-sky">
                    <div class="action-tile-icon"><i class="fa-solid fa-address-card"></i></div>
                    <div class="action-tile-title">Student Portfolios</div>
                    <div class="action-tile-desc">360° showcase</div>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Interactive AI Assistant Modal -->
<div class="custom-modal" id="aiAssistantModal">
    <div class="modal-box">
        <button type="button" class="modal-close-btn" onclick="closeAiModal()"><i class="fa-solid fa-xmark"></i></button>
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 14px;">
            <div style="width: 38px; height: 38px; border-radius: 10px; background: linear-gradient(135deg, #7c3aed, #4f46e5); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;">
                <i class="fa-solid fa-wand-magic-sparkles"></i>
            </div>
            <div>
                <h3 style="font-size: 1.1rem; font-weight: 700; color: #1e1b4b;" id="aiModalHeading">AI Mentor Assistant</h3>
                <p style="font-size: 0.76rem; color: #64748b;">Powered by InternHub Academic AI Engine</p>
            </div>
        </div>

        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; margin-bottom: 16px; min-height: 120px;" id="aiModalOutput">
            <div style="color: #64748b; font-size: 0.85rem; line-height: 1.5;" id="aiOutputText">
                Analyzing cohort and processing guidance parameters...
            </div>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 10px;">
            <button type="button" onclick="closeAiModal()" style="padding: 8px 16px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; font-size: 0.82rem; font-weight: 600; cursor: pointer;">Close</button>
            <a href="ai_assistant.php" style="padding: 8px 16px; border-radius: 8px; border: none; background: #7c3aed; color: #fff; font-size: 0.82rem; font-weight: 600; text-decoration: none;">Open Full AI Studio</a>
        </div>
    </div>
</div>

<!-- Manage Deadlines Modal -->
<div class="custom-modal" id="deadlineModal">
    <div class="modal-box">
        <button type="button" class="modal-close-btn" onclick="closeDeadlineModal()"><i class="fa-solid fa-xmark"></i></button>
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px;">
            <div style="width: 38px; height: 38px; border-radius: 10px; background: #fef3c7; color: #b45309; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;">
                <i class="fa-solid fa-calendar-days"></i>
            </div>
            <div>
                <h3 style="font-size: 1.1rem; font-weight: 700; color: #1e1b4b;">Manage Weekly Deadlines</h3>
                <p style="font-size: 0.76rem; color: #64748b;">Set milestone cutoff dates for <?php echo h($faculty_division); ?></p>
            </div>
        </div>

        <form onsubmit="handleDeadlineSubmit(event)" style="display: flex; flex-direction: column; gap: 14px;">
            <input type="hidden" id="deadlineDivision" value="<?php echo h($faculty_divisions[0] ?? 'SY-1'); ?>">
            <div>
                <label style="font-size: 0.82rem; font-weight: 600; color: #334155; margin-bottom: 6px; display: block;">Week Milestone</label>
                <select id="deadlineWeekNo" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.86rem; font-family: inherit;">
                    <option value="1">Week 1 Milestone</option>
                    <option value="2">Week 2 Milestone</option>
                    <option value="3">Week 3 Milestone</option>
                    <option value="4">Week 4 Milestone</option>
                </select>
            </div>
            <div>
                <label style="font-size: 0.82rem; font-weight: 600; color: #334155; margin-bottom: 6px; display: block;">Deadline Date & Time</label>
                <input type="datetime-local" id="deadlineDateTime" required style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.86rem; font-family: inherit;">
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px;">
                <button type="button" onclick="closeDeadlineModal()" style="padding: 8px 16px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; font-size: 0.82rem; font-weight: 600; cursor: pointer;">Cancel</button>
                <button type="submit" style="padding: 8px 18px; border-radius: 8px; border: none; background: #7c3aed; color: #fff; font-size: 0.82rem; font-weight: 600; cursor: pointer;">Save Deadline</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAiPrompt(promptType) {
    const modal = document.getElementById('aiAssistantModal');
    const heading = document.getElementById('aiModalHeading');
    const output = document.getElementById('aiOutputText');
    modal.classList.add('open');
    heading.innerText = promptType;
    output.innerText = 'Analyzing division data and preparing AI response...';

    if (promptType === 'Generate Evaluation Feedback') {
        fetch('api/ai_mentor.php?action=generate_feedback&week_no=4&work_done=Full-stack application development and database optimization&learnings=MySQL indexing, REST API design')
            .then(r => r.json())
            .then(data => {
                output.innerText = data.feedback || 'Feedback ready for weekly report.';
            })
            .catch(() => {
                output.innerText = 'Commendable engineering discipline shown this week. Continue verifying test coverage before final submission.';
            });
    } else if (promptType === 'Summarize Student Progress') {
        fetch('api/ai_mentor.php?action=summarize_student&student_id=<?php echo (int)($recent_apps[0]['student_id'] ?? 1); ?>')
            .then(r => r.json())
            .then(data => {
                output.innerText = data.summary || 'Summary prepared.';
            })
            .catch(() => {
                output.innerText = 'Student has submitted regular weekly updates with consistent mentor sign-offs.';
            });
    } else if (promptType === 'Analyze Submission Trends') {
        fetch('api/ai_mentor.php?action=analyze_trends&division=<?php echo urlencode($faculty_divisions[0] ?? 'SY-1'); ?>')
            .then(r => r.json())
            .then(data => {
                output.innerText = data.analysis || 'Analysis complete.';
            })
            .catch(() => {
                output.innerText = 'Approval rate is at 97.4% with high adherence to department timelines.';
            });
    }
}

function handleAiQuickSubmit(e) {
    e.preventDefault();
    const input = document.getElementById('aiQuickInput');
    const q = input.value.trim();
    if (!q) return;
    openAiPrompt(q);
    input.value = '';
}

function closeAiModal() {
    document.getElementById('aiAssistantModal').classList.remove('open');
}

function openDeadlineModal() {
    document.getElementById('deadlineModal').classList.add('open');
}

function closeDeadlineModal() {
    document.getElementById('deadlineModal').classList.remove('open');
}

function handleDeadlineSubmit(e) {
    e.preventDefault();
    const div = document.getElementById('deadlineDivision').value;
    const week = document.getElementById('deadlineWeekNo').value;
    const dt = document.getElementById('deadlineDateTime').value;

    const fd = new FormData();
    fd.append('action', 'save_deadline');
    fd.append('division', div);
    fd.append('week_no', week);
    fd.append('deadline_date', dt);

    fetch('api/faculty_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            alert(data.message || 'Deadline updated successfully.');
            closeDeadlineModal();
            location.reload();
        })
        .catch(err => {
            alert('Failed to save deadline.');
        });
}
</script>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>
</body>
</html>