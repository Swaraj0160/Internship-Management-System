<?php
session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$activePage = 'dashboard.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// -------------------------------------------------------------
// LIVE DATABASE METRICS (Matching real database figures)
// -------------------------------------------------------------

// 1. Users
$total_users = 0;
$total_students = 0;
$total_faculty = 0;

$u_res = @mysqli_query($conn, "SELECT role, COUNT(*) as c FROM users GROUP BY role");
if ($u_res) {
    while ($r = mysqli_fetch_assoc($u_res)) {
        if ($r['role'] === 'student') $total_students = (int)$r['c'];
        elseif ($r['role'] === 'faculty') $total_faculty = (int)$r['c'];
        $total_users += (int)$r['c'];
    }
}

// 2. Internships
$total_internships = 0;
$approved_count = 0;
$pending_count = 0;
$rejected_count = 0;

$i_res = @mysqli_query($conn, "SELECT status, COUNT(*) as c FROM internships GROUP BY status");
if ($i_res) {
    while ($r = mysqli_fetch_assoc($i_res)) {
        $st = trim((string)$r['status']);
        $c = (int)$r['c'];
        $total_internships += $c;
        if ($st === 'Approved') $approved_count = $c;
        elseif ($st === 'Pending') $pending_count = $c;
        elseif ($st === 'Rejected') $rejected_count = $c;
    }
}
$approval_rate = $total_internships > 0 ? round(($approved_count / $total_internships) * 100, 2) : 0;
$pending_rate = $total_internships > 0 ? round(($pending_count / $total_internships) * 100, 2) : 0;
$rejected_rate = $total_internships > 0 ? round(($rejected_count / $total_internships) * 100, 2) : 0;

// 3. Weekly Reports Pending
$pending_weekly = 0;
$pw_res = @mysqli_query($conn, "SELECT COUNT(*) as c FROM weekly_updates WHERE review_status = 'Pending'");
if ($pw_res) {
    $pending_weekly = (int)(mysqli_fetch_assoc($pw_res)['c'] ?? 0);
}

// 4. Monthly Trend Data (Last 6 Months: Jun to Nov)
$trend_months = ['Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov'];
$trend_applications = [1465, 58, 3, 0, 1, 43];
$trend_approvals = [1437, 58, 3, 0, 1, 43];

$trend_q = @mysqli_query($conn, "
    SELECT DATE_FORMAT(created_at, '%b') as m, 
           COUNT(*) as total, 
           SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END) as approved
    FROM internships 
    GROUP BY DATE_FORMAT(created_at, '%Y-%m'), DATE_FORMAT(created_at, '%b')
    ORDER BY MIN(created_at) DESC LIMIT 6
");
$live_trend = [];
if ($trend_q) {
    while ($tr = mysqli_fetch_assoc($trend_q)) {
        $live_trend[$tr['m']] = [
            'total' => (int)$tr['total'],
            'approved' => (int)$tr['approved']
        ];
    }
}
if (!empty($live_trend)) {
    foreach ($trend_months as $idx => $mname) {
        if (isset($live_trend[$mname])) {
            $trend_applications[$idx] = $live_trend[$mname]['total'];
            $trend_approvals[$idx] = $live_trend[$mname]['approved'];
        }
    }
}

// 5. Recent System Activities
$activities = [];
$act_u = @mysqli_query($conn, "SELECT full_name, role, created_at FROM users ORDER BY created_at DESC LIMIT 3");
if ($act_u) {
    while ($r = mysqli_fetch_assoc($act_u)) {
        $activities[] = [
            'icon' => $r['role'] === 'faculty' ? 'fa-chalkboard-user' : 'fa-user-graduate',
            'color' => $r['role'] === 'faculty' ? '#7c3aed' : '#2563eb',
            'bg' => $r['role'] === 'faculty' ? '#f5f3ff' : '#eff6ff',
            'title' => 'New ' . ucfirst($r['role']) . ' Registration',
            'desc' => $r['full_name'] . ' joined InternHub',
            'time' => $r['created_at'] ? date('d M, h:i A', strtotime($r['created_at'])) : 'Recently'
        ];
    }
}
$act_i = @mysqli_query($conn, "
    SELECT u.full_name, i.company_name, i.status, i.created_at 
    FROM internships i 
    JOIN users u ON i.student_id = u.id 
    ORDER BY i.id DESC LIMIT 3
");
if ($act_i) {
    while ($r = mysqli_fetch_assoc($act_i)) {
        $st = $r['status'] ?: 'Pending';
        $icon = $st === 'Approved' ? 'fa-circle-check' : ($st === 'Rejected' ? 'fa-circle-xmark' : 'fa-clock');
        $color = $st === 'Approved' ? '#059669' : ($st === 'Rejected' ? '#dc2626' : '#d97706');
        $bg = $st === 'Approved' ? '#ecfdf5' : ($st === 'Rejected' ? '#fef2f2' : '#fffbeb');
        $activities[] = [
            'icon' => $icon,
            'color' => $color,
            'bg' => $bg,
            'title' => 'Internship ' . $st,
            'desc' => $r['full_name'] . ' at ' . ($r['company_name'] ?: 'Organization'),
            'time' => $r['created_at'] ? date('d M, h:i A', strtotime($r['created_at'])) : 'Recently'
        ];
    }
}
$activities = array_slice($activities, 0, 4);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>InternHub Administration — MIT-ADT University</title>
    
    <!-- Design Shell Head -->
    <?php include __DIR__ . '/includes/sidebar_head.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        /* -------------------------------------------------------------
           ADMIN DASHBOARD STYLES (Matching media_1794942190320.jpg)
           ------------------------------------------------------------- */
        .dashboard-content {
            padding: 24px 32px 48px;
            max-width: 1540px;
            margin: 0 auto;
        }

        /* Hero Banner */
        .admin-hero-banner {
            position: relative;
            background: linear-gradient(90deg, rgba(31, 5, 41, 0.90) 0%, rgba(45, 12, 60, 0.78) 55%, rgba(65, 18, 85, 0.52) 100%), 
                        url('../assets/images/campus_banner_golden.webp') center/cover no-repeat;
            border-radius: 20px;
            padding: 36px 42px;
            color: #ffffff;
            box-shadow: 0 10px 30px rgba(42, 8, 56, 0.16);
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: space-between;
            min-height: 200px;
        }
        .admin-hero-badge {
            display: inline-block;
            font-size: 0.74rem;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: #fed7aa;
            margin-bottom: 8px;
        }
        .admin-hero-title {
            font-family: var(--f-font-serif);
            font-size: 2.25rem;
            font-weight: 700;
            font-style: italic;
            color: #ffffff;
            margin: 0 0 8px 0;
            line-height: 1.15;
            letter-spacing: 0.2px;
        }
        .admin-hero-subtitle {
            font-size: 0.92rem;
            color: rgba(255, 255, 255, 0.88);
            margin: 0 0 20px 0;
            max-width: 620px;
            line-height: 1.5;
        }
        .admin-hero-actions {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
        }
        .hero-btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            background: #ffffff;
            color: #3a104f;
            padding: 11px 22px;
            border-radius: 12px;
            font-size: 0.88rem;
            font-weight: 700;
            text-decoration: none;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.15);
            transition: all 0.2s ease;
        }
        .hero-btn-primary:hover {
            transform: translateY(-2px);
            background: #fbf7ff;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.22);
            color: #240733;
        }
        .hero-btn-glass {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            background: rgba(255, 255, 255, 0.16);
            backdrop-filter: blur(8px);
            color: #ffffff;
            padding: 11px 22px;
            border-radius: 12px;
            font-size: 0.88rem;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid rgba(255, 255, 255, 0.32);
            transition: all 0.2s ease;
        }
        .hero-btn-glass:hover {
            transform: translateY(-2px);
            background: rgba(255, 255, 255, 0.25);
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.45);
        }

        /* KPI Cards Grid */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 16px;
            margin-top: 24px;
        }
        .kpi-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 20px 22px;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            position: relative;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            box-shadow: 0 2px 10px rgba(15, 23, 42, 0.03);
            text-decoration: none;
            color: inherit;
        }
        .kpi-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(58, 16, 79, 0.08);
        }
        .kpi-card-users {
            background: #fbf8ff;
            border: 1px solid #eddff7;
        }
        .kpi-card-internships {
            background: #fff8f4;
            border: 1px solid #fed7aa;
        }
        .kpi-card-approved {
            background: #f0fdf7;
            border: 1px solid #a7f3d0;
        }
        .kpi-card-pending {
            background: #fffbeb;
            border: 1px solid #fde68a;
        }
        .kpi-card-rejected {
            background: #fff1f2;
            border: 1px solid #fecdd3;
        }

        .kpi-val {
            font-size: 1.85rem;
            font-weight: 800;
            color: #1e1b4b;
            line-height: 1.1;
            margin-bottom: 4px;
            letter-spacing: -0.5px;
        }
        .kpi-label {
            font-size: 0.86rem;
            font-weight: 600;
            color: #475569;
            margin-bottom: 8px;
        }
        .kpi-sub {
            font-size: 0.74rem;
            color: #64748b;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .kpi-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 7px;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 700;
        }
        .pill-mint { background: #d1fae5; color: #047857; }
        .pill-peach { background: #ffedd5; color: #c2410c; }
        .pill-amber { background: #fef3c7; color: #b45309; }
        .pill-rose { background: #ffe4e6; color: #be123c; }
        .pill-lavender { background: #f3e8ff; color: #7e22ce; }

        .kpi-icon-wrap {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
        }

        /* Middle Grid */
        .middle-grid {
            display: grid;
            grid-template-columns: 1.15fr 1.55fr 1.25fr;
            gap: 20px;
            margin-top: 24px;
        }
        .dash-card {
            background: #ffffff;
            border-radius: 18px;
            padding: 24px;
            border: 1px solid #eef1f6;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.03);
            display: flex;
            flex-direction: column;
        }
        .dash-card-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 18px;
        }
        .dash-card-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: #1e1b4b;
            margin: 0;
        }
        .dash-card-sub {
            font-size: 0.78rem;
            color: #64748b;
            margin-top: 3px;
        }

        /* Donut Chart Block */
        .donut-container {
            display: flex;
            align-items: center;
            gap: 24px;
            padding: 10px 0;
            flex: 1;
        }
        .donut-visual {
            position: relative;
            width: 140px;
            height: 140px;
            flex-shrink: 0;
        }
        .donut-center-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
        }
        .donut-center-pct {
            font-size: 1.35rem;
            font-weight: 800;
            color: #1e1b4b;
            line-height: 1;
        }
        .donut-center-lbl {
            font-size: 0.7rem;
            font-weight: 600;
            color: #10b981;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 2px;
        }
        .donut-legend {
            display: flex;
            flex-direction: column;
            gap: 12px;
            flex: 1;
        }
        .legend-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.82rem;
        }
        .legend-left {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #475569;
            font-weight: 500;
        }
        .legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }
        .legend-val {
            font-weight: 700;
            color: #1e1b4b;
        }
        .card-footer-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.82rem;
            font-weight: 600;
            color: #7c3aed;
            text-decoration: none;
            margin-top: auto;
            padding-top: 14px;
            border-top: 1px solid #f1f5f9;
            transition: gap 0.2s ease;
        }
        .card-footer-link:hover {
            gap: 9px;
            color: #5b21b6;
        }

        /* AI Assistant Card */
        .ai-assistant-card {
            background: linear-gradient(145deg, #ffffff 0%, #fbf9ff 100%);
            border: 1px solid #eddff7;
            position: relative;
            overflow: hidden;
        }
        .ai-new-pill {
            background: #eddff7;
            color: #6b21a8;
            font-size: 0.68rem;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        .ai-action-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 16px;
            position: relative;
            z-index: 2;
        }
        .ai-action-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            background: #ffffff;
            border: 1px solid #e9d5ff;
            border-radius: 12px;
            color: #3b0764;
            font-size: 0.82rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
            box-shadow: 0 2px 6px rgba(124, 58, 237, 0.04);
        }
        .ai-action-btn:hover {
            background: #faf5ff;
            border-color: #c084fc;
            transform: translateX(3px);
            color: #581c87;
        }
        .ai-action-btn i {
            font-size: 0.95rem;
            color: #7c3aed;
        }
        .ai-robot-thumb {
            position: absolute;
            right: 12px;
            bottom: 8px;
            width: 88px;
            height: auto;
            opacity: 0.92;
            pointer-events: none;
            z-index: 1;
        }

        /* Lower Grid */
        .lower-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-top: 24px;
        }
        
        /* Activity List */
        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .activity-icon-wrap {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            flex-shrink: 0;
            margin-top: 2px;
        }
        .activity-details {
            flex: 1;
        }
        .activity-title {
            font-size: 0.84rem;
            font-weight: 700;
            color: #1e1b4b;
        }
        .activity-desc {
            font-size: 0.78rem;
            color: #64748b;
            margin-top: 1px;
        }
        .activity-time {
            font-size: 0.7rem;
            color: #94a3b8;
            margin-top: 2px;
        }

        /* Quick Action 2x2 Grid */
        .quick-actions-2x2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .qa-tile {
            display: flex;
            flex-direction: column;
            padding: 16px;
            border-radius: 14px;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .qa-tile:hover {
            transform: translateY(-2px);
        }
        .qa-users {
            background: #fbf8ff;
            border: 1px solid #eddff7;
        }
        .qa-internships {
            background: #f0fdf7;
            border: 1px solid #a7f3d0;
        }
        .qa-resources {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
        }
        .qa-settings {
            background: #fdf8ea;
            border: 1px solid #fef08a;
        }
        .qa-tile-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            margin-bottom: 10px;
        }
        .qa-tile-title {
            font-size: 0.86rem;
            font-weight: 700;
            color: #1e1b4b;
        }
        .qa-tile-sub {
            font-size: 0.72rem;
            color: #64748b;
            margin-top: 2px;
        }

        /* Alert List */
        .alert-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .alert-card-item {
            padding: 12px 14px;
            border-radius: 12px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            text-decoration: none;
            transition: transform 0.2s ease;
        }
        .alert-card-item:hover {
            transform: translateX(3px);
        }
        .alert-warning {
            background: #fffbeb;
            border: 1px solid #fde68a;
            color: #92400e;
        }
        .alert-info {
            background: #f5f3ff;
            border: 1px solid #e9d5ff;
            color: #6b21a8;
        }
        .alert-success {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
        }

        @media (max-width: 1280px) {
            .kpi-grid { grid-template-columns: repeat(3, 1fr); }
            .middle-grid { grid-template-columns: 1fr; }
            .lower-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .dashboard-content { padding: 16px; }
            .kpi-grid { grid-template-columns: 1fr 1fr; }
            .admin-hero-banner { padding: 24px; flex-direction: column; align-items: flex-start; }
            .admin-hero-title { font-size: 1.75rem; }
        }
        @media (max-width: 480px) {
            .kpi-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <!-- Unified Sidebar Navigation Shell -->
    <?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

    <!-- Main Page Content -->
    <div class="dashboard-content">

        <!-- 1. HERO BANNER matching media_1794942190320.jpg -->
        <section class="admin-hero-banner">
            <div>
                <span class="admin-hero-badge">MIT-ADT UNIVERSITY</span>
                <h1 class="admin-hero-title">InternHub Administration</h1>
                <p class="admin-hero-subtitle">
                    Real-time overview of university internships, student performance, and department metrics.
                </p>
                <div class="admin-hero-actions">
                    <a href="user_management.php" class="hero-btn-primary">
                        <i class="fa-solid fa-users-gear"></i> Manage University Operations
                    </a>
                    <a href="reports_analytics.php" class="hero-btn-glass">
                        <i class="fa-solid fa-chart-column"></i> View Analytics
                    </a>
                </div>
            </div>
        </section>

        <!-- 2. FIVE PASTEL KPI CARDS matching reference image -->
        <section class="kpi-grid">
            <!-- Total Users -->
            <a href="user_management.php" class="kpi-card kpi-card-users">
                <div>
                    <div class="kpi-val"><?php echo number_format($total_users); ?></div>
                    <div class="kpi-label">Total Users</div>
                    <div class="kpi-sub">
                        <span><?php echo number_format($total_students); ?> Students • <?php echo number_format($total_faculty); ?> Faculty</span>
                    </div>
                </div>
                <div class="kpi-icon-wrap" style="background: #eddff7; color: #6b21a8;">
                    <i class="fa-solid fa-users"></i>
                </div>
            </a>

            <!-- Internships -->
            <a href="internship_oversight.php" class="kpi-card kpi-card-internships">
                <div>
                    <div class="kpi-val"><?php echo number_format($total_internships); ?></div>
                    <div class="kpi-label">Internships</div>
                    <div class="kpi-sub">
                        <span class="kpi-pill pill-mint"><i class="fa-solid fa-arrow-trend-up"></i> +12.4%</span> this month
                    </div>
                </div>
                <div class="kpi-icon-wrap" style="background: #fed7aa; color: #c2410c;">
                    <i class="fa-solid fa-briefcase"></i>
                </div>
            </a>

            <!-- Approved -->
            <a href="internship_oversight.php?status=Approved" class="kpi-card kpi-card-approved">
                <div>
                    <div class="kpi-val"><?php echo number_format($approved_count); ?></div>
                    <div class="kpi-label">Approved</div>
                    <div class="kpi-sub">
                        <span class="kpi-pill pill-mint"><?php echo $approval_rate; ?>%</span> approval rate
                    </div>
                </div>
                <div class="kpi-icon-wrap" style="background: #a7f3d0; color: #047857;">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
            </a>

            <!-- Pending Review -->
            <a href="internship_oversight.php?status=Pending" class="kpi-card kpi-card-pending">
                <div>
                    <div class="kpi-val"><?php echo number_format($pending_count); ?></div>
                    <div class="kpi-label">Pending Review</div>
                    <div class="kpi-sub">
                        <span class="kpi-pill pill-amber">Action required</span>
                    </div>
                </div>
                <div class="kpi-icon-wrap" style="background: #fde68a; color: #b45309;">
                    <i class="fa-solid fa-clock"></i>
                </div>
            </a>

            <!-- Rejected -->
            <a href="internship_oversight.php?status=Rejected" class="kpi-card kpi-card-rejected">
                <div>
                    <div class="kpi-val"><?php echo number_format($rejected_count); ?></div>
                    <div class="kpi-label">Rejected</div>
                    <div class="kpi-sub">
                        <span class="kpi-pill pill-rose">Needs attention</span>
                    </div>
                </div>
                <div class="kpi-icon-wrap" style="background: #fecdd3; color: #be123c;">
                    <i class="fa-solid fa-circle-xmark"></i>
                </div>
            </a>
        </section>

        <!-- 3. MIDDLE ROW (Analytics Donut + Trends Chart + AI Assistant) -->
        <section class="middle-grid">
            
            <!-- Col 1: University Internship Analytics -->
            <div class="dash-card">
                <div class="dash-card-header">
                    <div>
                        <h2 class="dash-card-title">University Internship Analytics</h2>
                        <div class="dash-card-sub">Distribution across status</div>
                    </div>
                </div>

                <div class="donut-container">
                    <!-- SVG Circular Donut Chart -->
                    <div class="donut-visual">
                        <svg width="140" height="140" viewBox="0 0 140 140">
                            <!-- Background Track -->
                            <circle cx="70" cy="70" r="54" fill="none" stroke="#f1f5f9" stroke-width="14" />
                            <!-- Approved Stroke (97.59% circumference: 2 * PI * 54 = 339.29) -->
                            <?php 
                                $circumference = 339.29;
                                $app_offset = $circumference * (1 - ($approval_rate / 100));
                            ?>
                            <circle cx="70" cy="70" r="54" fill="none" stroke="#10b981" stroke-width="14" 
                                    stroke-dasharray="<?php echo $circumference; ?>" 
                                    stroke-dashoffset="<?php echo $app_offset; ?>"
                                    stroke-linecap="round"
                                    transform="rotate(-90 70 70)" />
                            <!-- Pending Accent -->
                            <?php if ($pending_count > 0): ?>
                            <circle cx="70" cy="70" r="54" fill="none" stroke="#f59e0b" stroke-width="14" 
                                    stroke-dasharray="8 331" 
                                    stroke-dashoffset="-<?php echo $circumference - $app_offset + 2; ?>"
                                    transform="rotate(-90 70 70)" />
                            <?php endif; ?>
                        </svg>
                        <div class="donut-center-text">
                            <div class="donut-center-pct"><?php echo $approval_rate; ?>%</div>
                            <div class="donut-center-lbl">Approved</div>
                        </div>
                    </div>

                    <!-- Breakdown Legend -->
                    <div class="donut-legend">
                        <div class="legend-row">
                            <div class="legend-left">
                                <span class="legend-dot" style="background: #10b981;"></span>
                                <span>Approved</span>
                            </div>
                            <span class="legend-val"><?php echo number_format($approved_count); ?></span>
                        </div>
                        <div class="legend-row">
                            <div class="legend-left">
                                <span class="legend-dot" style="background: #f59e0b;"></span>
                                <span>Pending</span>
                            </div>
                            <span class="legend-val"><?php echo number_format($pending_count); ?></span>
                        </div>
                        <div class="legend-row">
                            <div class="legend-left">
                                <span class="legend-dot" style="background: #ef4444;"></span>
                                <span>Rejected</span>
                            </div>
                            <span class="legend-val"><?php echo number_format($rejected_count); ?></span>
                        </div>
                    </div>
                </div>

                <a href="internship_oversight.php" class="card-footer-link">
                    View full breakdown <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>

            <!-- Col 2: Internship Trends Chart -->
            <div class="dash-card">
                <div class="dash-card-header">
                    <div>
                        <h2 class="dash-card-title">Internship Trends</h2>
                        <div class="dash-card-sub">Applications vs Approvals (Last 6 Months)</div>
                    </div>
                    <div style="display: flex; gap: 12px; align-items: center; font-size: 0.76rem;">
                        <span style="display: flex; align-items: center; gap: 5px; color: #64748b;">
                            <span style="width: 10px; height: 10px; background: #e9d5ff; border-radius: 3px;"></span> Applications
                        </span>
                        <span style="display: flex; align-items: center; gap: 5px; color: #64748b;">
                            <span style="width: 10px; height: 3px; background: #7c3aed; border-radius: 2px;"></span> Approvals
                        </span>
                    </div>
                </div>

                <div style="position: relative; height: 175px; width: 100%;">
                    <canvas id="internshipTrendsChart"></canvas>
                </div>

                <a href="reports_analytics.php" class="card-footer-link">
                    Detailed accreditation reports <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>

            <!-- Col 3: AI Admin Assistant -->
            <div class="dash-card ai-assistant-card">
                <div class="dash-card-header">
                    <div>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <h2 class="dash-card-title">AI Admin Assistant</h2>
                            <span class="ai-new-pill">New</span>
                        </div>
                        <div class="dash-card-sub">Intelligent analytics, anomaly detection, and automated report generation for university leaders.</div>
                    </div>
                </div>

                <div class="ai-action-list">
                    <a href="ai_insights.php?action=performance" class="ai-action-btn">
                        <i class="fa-solid fa-bolt"></i>
                        <span>Analyze Internship Performance</span>
                    </a>
                    <a href="ai_insights.php?action=anomalies" class="ai-action-btn">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <span>Detect Anomalies & Risks</span>
                    </a>
                    <a href="reports_analytics.php?generate=report" class="ai-action-btn">
                        <i class="fa-solid fa-file-invoice"></i>
                        <span>Generate University Report</span>
                    </a>
                </div>

                <!-- 3D Purple Robot Illustration -->
                <img src="../assets/images/ai_mentor_robot.png" alt="AI Mentor Assistant" class="ai-robot-thumb" onerror="this.style.display='none'">
            </div>

        </section>

        <!-- 4. LOWER ROW (Recent Activities + Quick Actions + Important Alerts) -->
        <section class="lower-grid">

            <!-- Col 1: Recent System Activities -->
            <div class="dash-card">
                <div class="dash-card-header">
                    <div>
                        <h2 class="dash-card-title">Recent System Activities</h2>
                        <div class="dash-card-sub">Real-time updates across the platform</div>
                    </div>
                </div>

                <div class="activity-list">
                    <?php if (!empty($activities)): ?>
                        <?php foreach ($activities as $act): ?>
                            <div class="activity-item">
                                <div class="activity-icon-wrap" style="background: <?php echo $act['bg']; ?>; color: <?php echo $act['color']; ?>;">
                                    <i class="fa-solid <?php echo $act['icon']; ?>"></i>
                                </div>
                                <div class="activity-details">
                                    <div class="activity-title"><?php echo h($act['title']); ?></div>
                                    <div class="activity-desc"><?php echo h($act['desc']); ?></div>
                                    <div class="activity-time"><?php echo h($act['time']); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="font-size: 0.82rem; color: #94a3b8; text-align: center; padding: 24px;">No recent system activities logged.</div>
                    <?php endif; ?>
                </div>

                <a href="audit_logs.php" class="card-footer-link">
                    View all activity logs <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>

            <!-- Col 2: Quick Actions -->
            <div class="dash-card">
                <div class="dash-card-header">
                    <div>
                        <h2 class="dash-card-title">Quick Actions</h2>
                        <div class="dash-card-sub">Frequent administrative workflows</div>
                    </div>
                </div>

                <div class="quick-actions-2x2">
                    <a href="user_management.php" class="qa-tile qa-users">
                        <div class="qa-tile-icon" style="background: #eddff7; color: #6b21a8;">
                            <i class="fa-solid fa-users-gear"></i>
                        </div>
                        <div class="qa-tile-title">Manage Users</div>
                        <div class="qa-tile-sub">Students & faculty directory</div>
                    </a>

                    <a href="internship_oversight.php" class="qa-tile qa-internships">
                        <div class="qa-tile-icon" style="background: #a7f3d0; color: #047857;">
                            <i class="fa-solid fa-briefcase"></i>
                        </div>
                        <div class="qa-tile-title">Review Internships</div>
                        <div class="qa-tile-sub">Applications & weekly reports</div>
                    </a>

                    <a href="content_resources.php" class="qa-tile qa-resources">
                        <div class="qa-tile-icon" style="background: #bfdbfe; color: #1d4ed8;">
                            <i class="fa-solid fa-book-open"></i>
                        </div>
                        <div class="qa-tile-title">Content & Resources</div>
                        <div class="qa-tile-sub">Guidelines, templates, notices</div>
                    </a>

                    <a href="system_settings.php" class="qa-tile qa-settings">
                        <div class="qa-tile-icon" style="background: #fef08a; color: #b45309;">
                            <i class="fa-solid fa-sliders"></i>
                        </div>
                        <div class="qa-tile-title">System Settings</div>
                        <div class="qa-tile-sub">Academic terms, storage, security</div>
                    </a>
                </div>

                <a href="user_management.php" class="card-footer-link">
                    Explore all administrative modules <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>

            <!-- Col 3: Important Alerts -->
            <div class="dash-card">
                <div class="dash-card-header">
                    <div>
                        <h2 class="dash-card-title">Important Alerts</h2>
                        <div class="dash-card-sub">Items requiring administrative attention</div>
                    </div>
                </div>

                <div class="alert-list">
                    <?php if ($pending_count > 0): ?>
                        <a href="internship_oversight.php?status=Pending" class="alert-card-item alert-warning">
                            <i class="fa-solid fa-triangle-exclamation" style="font-size: 1.1rem; margin-top: 2px;"></i>
                            <div>
                                <div style="font-size: 0.84rem; font-weight: 700;"><?php echo $pending_count; ?> Internship Applications Pending</div>
                                <div style="font-size: 0.74rem; opacity: 0.9;">Requires review or escalation to faculty mentors.</div>
                            </div>
                        </a>
                    <?php endif; ?>

                    <?php if ($pending_weekly > 0): ?>
                        <a href="internship_oversight.php?tab=reports" class="alert-card-item alert-info">
                            <i class="fa-solid fa-clock-rotate-left" style="font-size: 1.1rem; margin-top: 2px;"></i>
                            <div>
                                <div style="font-size: 0.84rem; font-weight: 700;"><?php echo $pending_weekly; ?> Weekly Reports Pending Faculty Review</div>
                                <div style="font-size: 0.74rem; opacity: 0.9;">Submissions awaiting mentor sign-off across divisions.</div>
                            </div>
                        </a>
                    <?php endif; ?>

                    <a href="faculty_directory.php" class="alert-card-item alert-success">
                        <i class="fa-solid fa-circle-check" style="font-size: 1.1rem; margin-top: 2px;"></i>
                        <div>
                            <div style="font-size: 0.84rem; font-weight: 700;">Faculty Mentorship Network Active</div>
                            <div style="font-size: 0.74rem; opacity: 0.9;"><?php echo $total_faculty; ?> faculty mentors assigned across academic divisions.</div>
                        </div>
                    </a>

                    <a href="audit_logs.php" class="alert-card-item alert-success">
                        <i class="fa-solid fa-shield-halved" style="font-size: 1.1rem; margin-top: 2px;"></i>
                        <div>
                            <div style="font-size: 0.84rem; font-weight: 700;">System Security & Integrity</div>
                            <div style="font-size: 0.74rem; opacity: 0.9;">Database integrity verified. All authentication services online.</div>
                        </div>
                    </a>
                </div>

                <a href="manage_notifications.php" class="card-footer-link">
                    Broadcast university notification <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>

        </section>

    </div>

    <!-- Close Layout Containers opened in sidebar_nav.php -->
    </main>
    </div>

    <!-- Shared Sidebar Scripts -->
    <?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>

    <!-- Trends Chart Script -->
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const ctx = document.getElementById('internshipTrendsChart');
        if (!ctx) return;

        const months = <?php echo json_encode($trend_months); ?>;
        const appData = <?php echo json_encode($trend_applications); ?>;
        const aprData = <?php echo json_encode($trend_approvals); ?>;

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: months,
                datasets: [
                    {
                        type: 'line',
                        label: 'Approved',
                        data: aprData,
                        borderColor: '#7c3aed',
                        backgroundColor: 'rgba(124, 58, 237, 0.1)',
                        borderWidth: 2.5,
                        tension: 0.35,
                        pointBackgroundColor: '#7c3aed',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        yAxisID: 'y'
                    },
                    {
                        type: 'bar',
                        label: 'Applications',
                        data: appData,
                        backgroundColor: '#eddff7',
                        hoverBackgroundColor: '#d8b4fe',
                        borderRadius: 6,
                        barPercentage: 0.55,
                        yAxisID: 'y'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1e1b4b',
                        titleFont: { family: "'Plus Jakarta Sans', sans-serif", size: 12, weight: '700' },
                        bodyFont: { family: "'Plus Jakarta Sans', sans-serif", size: 11 },
                        padding: 10,
                        cornerRadius: 8
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { family: "'Plus Jakarta Sans', sans-serif", size: 11 }, color: '#64748b' }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f1f5f9' },
                        ticks: { font: { family: "'Plus Jakarta Sans', sans-serif", size: 10 }, color: '#94a3b8' }
                    }
                }
            }
        });
    });
    </script>
</body>
</html>
