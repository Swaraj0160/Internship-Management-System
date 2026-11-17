<?php
/* -----------------------------------------------------------------
   InternHub — Faculty Reports & Analytics Console
   Comprehensive submission velocity, organization breakdown,
   and continuous assessment distribution.
   ----------------------------------------------------------------- */

session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'faculty') {
    header("Location: ../login.php");
    exit();
}

$faculty_id = (int)$_SESSION['user_id'];

// Assigned divisions
$faculty_divisions = [];
$fd_query = mysqli_query($conn, "SELECT DISTINCT division FROM faculty_divisions WHERE faculty_id='$faculty_id' AND division IS NOT NULL AND TRIM(division) <> ''");
if ($fd_query) {
    while ($r = mysqli_fetch_assoc($fd_query)) $faculty_divisions[] = trim($r['division']);
}
$faculty_divisions = array_values(array_unique($faculty_divisions));
$active_division = trim($_GET['division'] ?? ($faculty_divisions[0] ?? 'SY-1'));
$safe_div = mysqli_real_escape_string($conn, $active_division);

// Total students
$total_students = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt FROM users WHERE role='student' AND division='$safe_div'"))['cnt'] ?? 0);

// Status counts
$app_res = mysqli_query($conn, "SELECT i.status, COUNT(*) as cnt FROM internships i JOIN users u ON i.student_id = u.id WHERE u.division='$safe_div' GROUP BY i.status");
$status_counts = ['Approved' => 0, 'Pending' => 0, 'Rejected' => 0];
if ($app_res) {
    while ($r = mysqli_fetch_assoc($app_res)) {
        $st = $r['status'] ?? 'Pending';
        if (isset($status_counts[$st])) $status_counts[$st] = (int)$r['cnt'];
    }
}

// Weekly report submission velocity
$week_counts = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
$wk_res = mysqli_query($conn, "SELECT w.week_no, COUNT(*) as cnt FROM weekly_updates w JOIN internships i ON w.internship_id = i.id JOIN users u ON i.student_id = u.id WHERE u.division='$safe_div' GROUP BY w.week_no");
if ($wk_res) {
    while ($r = mysqli_fetch_assoc($wk_res)) {
        $wn = (int)$r['week_no'];
        if (isset($week_counts[$wn])) $week_counts[$wn] = (int)$r['cnt'];
    }
}

// Top hiring organizations
$top_orgs = [];
$org_res = mysqli_query($conn, "SELECT i.company_name, COUNT(*) as cnt FROM internships i JOIN users u ON i.student_id = u.id WHERE u.division='$safe_div' AND i.company_name IS NOT NULL AND TRIM(i.company_name) <> '' GROUP BY i.company_name ORDER BY cnt DESC LIMIT 6");
if ($org_res) {
    while ($r = mysqli_fetch_assoc($org_res)) $top_orgs[] = $r;
}

$approval_rate = ($total_students > 0) ? round(($status_counts['Approved'] / $total_students) * 100, 1) : 0;

$activePage = 'reports_analytics.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics | InternHub</title>
    <?php include __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
        .analytics-container {
            padding: 0 32px 36px;
        }

        .analytics-header-box {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 22px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .analytics-grid-top {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        .metric-card {
            background: #ffffff;
            border-radius: 14px;
            padding: 18px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
        }

        .metric-card-label {
            font-size: 0.78rem;
            color: #64748b;
            font-weight: 600;
        }

        .metric-card-val {
            font-size: 1.6rem;
            font-weight: 800;
            color: #1e1b4b;
            margin-top: 6px;
        }

        .analytics-panels-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }

        .panel-box {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            padding: 22px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.02);
        }

        .panel-box-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: #1e1b4b;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .progress-bar-wrap {
            margin-bottom: 14px;
        }

        .progress-bar-label {
            display: flex;
            justify-content: space-between;
            font-size: 0.82rem;
            font-weight: 600;
            color: #334155;
            margin-bottom: 6px;
        }

        .progress-track {
            height: 10px;
            background: #f1f5f9;
            border-radius: 999px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 999px;
            background: #7c3aed;
        }

        .org-table {
            width: 100%;
            border-collapse: collapse;
        }

        .org-table td {
            padding: 10px 8px;
            font-size: 0.86rem;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
        }

        @media (max-width: 992px) {
            .analytics-grid-top { grid-template-columns: repeat(2, 1fr); }
            .analytics-panels-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="analytics-container">
    <div class="analytics-header-box">
        <div>
            <h1 style="font-size: 1.6rem; font-weight: 800; color: #1e1b4b;">Reports & Analytics Console</h1>
            <p style="font-size: 0.88rem; color: #64748b; margin-top: 3px;">Submission velocity, organizational distributions, and institutional accreditation metrics.</p>
        </div>

        <form method="GET" action="" style="display: flex; align-items: center; gap: 10px;">
            <select name="division" onchange="this.form.submit()" style="padding: 8px 14px; border-radius: 10px; border: 1px solid #cbd5e1; font-size: 0.85rem; font-family: inherit;">
                <?php foreach ($faculty_divisions as $d): ?>
                    <option value="<?php echo h($d); ?>" <?php echo $active_division === $d ? 'selected' : ''; ?>>Division <?php echo h($d); ?></option>
                <?php endforeach; ?>
            </select>
            <a href="export_students.php" style="padding: 8px 16px; border-radius: 10px; background: #7c3aed; color: #fff; font-size: 0.82rem; font-weight: 600; text-decoration: none;">Export Dataset</a>
        </form>
    </div>

    <!-- Top KPI Cards -->
    <div class="analytics-grid-top">
        <div class="metric-card">
            <div class="metric-card-label">Total Cohort Students</div>
            <div class="metric-card-val"><?php echo $total_students; ?></div>
        </div>
        <div class="metric-card">
            <div class="metric-card-label">Approval Velocity</div>
            <div class="metric-card-val" style="color: #047857;"><?php echo $approval_rate; ?>%</div>
        </div>
        <div class="metric-card">
            <div class="metric-card-label">Approved Applications</div>
            <div class="metric-card-val"><?php echo $status_counts['Approved']; ?></div>
        </div>
        <div class="metric-card">
            <div class="metric-card-label">Pending / In Review</div>
            <div class="metric-card-val" style="color: #b45309;"><?php echo $status_counts['Pending']; ?></div>
        </div>
    </div>

    <!-- Panels Row -->
    <div class="analytics-panels-row">
        <!-- Panel 1: Weekly Submission Velocity -->
        <div class="panel-box">
            <div class="panel-box-title">
                <i class="fa-solid fa-calendar-check" style="color: #7c3aed;"></i>
                <span>Weekly Report Submission Velocity</span>
            </div>

            <?php for ($w = 1; $w <= 4; $w++): ?>
                <?php
                $cnt = $week_counts[$w] ?? 0;
                $pct = $total_students > 0 ? min(100, round(($cnt / $total_students) * 100)) : 0;
                ?>
                <div class="progress-bar-wrap">
                    <div class="progress-bar-label">
                        <span>Week <?php echo $w; ?> Deliverables</span>
                        <span><?php echo $cnt; ?> / <?php echo $total_students; ?> (<?php echo $pct; ?>%)</span>
                    </div>
                    <div class="progress-track">
                        <div class="progress-fill" style="width: <?php echo $pct; ?>%;"></div>
                    </div>
                </div>
            <?php endfor; ?>
        </div>

        <!-- Panel 2: Top Organizations -->
        <div class="panel-box">
            <div class="panel-box-title">
                <i class="fa-solid fa-building" style="color: #2563eb;"></i>
                <span>Leading Organizations & Industry Partners</span>
            </div>

            <table class="org-table">
                <thead>
                    <tr style="text-align: left; font-size: 0.74rem; color: #94a3b8; text-transform: uppercase;">
                        <th>Company Name</th>
                        <th style="text-align: right;">Interns Assigned</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($top_orgs)): ?>
                        <?php foreach ($top_orgs as $org): ?>
                            <tr>
                                <td style="font-weight: 600;"><?php echo h($org['company_name']); ?></td>
                                <td style="text-align: right; font-weight: 700; color: #7c3aed;"><?php echo (int)$org['cnt']; ?> Students</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="2" style="text-align: center; color: #94a3b8; padding: 20px;">No organization records logged yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>
</body>
</html>
