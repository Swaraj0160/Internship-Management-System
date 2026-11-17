<?php
session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$activePage = 'reports_analytics.php';

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// -------------------------------------------------------------
// LIVE ACCREDITATION METRICS (NAAC & NIRF)
// -------------------------------------------------------------
$total_students = 0;
$approved_students = 0;

$u_res = @mysqli_query($conn, "SELECT COUNT(*) as c FROM users WHERE role = 'student'");
if ($u_res) $total_students = (int)(mysqli_fetch_assoc($u_res)['c'] ?? 0);

$i_res = @mysqli_query($conn, "SELECT COUNT(*) as c FROM internships WHERE status = 'Approved'");
if ($i_res) $approved_students = (int)(mysqli_fetch_assoc($i_res)['c'] ?? 0);

$naac_clearance_rate = $total_students > 0 ? round(($approved_students / $total_students) * 100, 2) : 0;

// Division Breakdown
$division_reports = [];
$d_res = @mysqli_query($conn, "
    SELECT u.division,
           COUNT(*) as enrolled,
           SUM(CASE WHEN i.status = 'Approved' THEN 1 ELSE 0 END) as approved,
           SUM(CASE WHEN i.status = 'Pending' THEN 1 ELSE 0 END) as pending,
           SUM(CASE WHEN i.status = 'Rejected' THEN 1 ELSE 0 END) as rejected
    FROM users u
    LEFT JOIN (
        SELECT student_id, status FROM internships
        WHERE id IN (SELECT MAX(id) FROM internships GROUP BY student_id)
    ) i ON i.student_id = u.id
    WHERE u.role = 'student' AND u.division IS NOT NULL AND TRIM(u.division) != ''
    GROUP BY u.division
    ORDER BY u.division ASC
");
if ($d_res) {
    while ($dr = mysqli_fetch_assoc($d_res)) {
        $en = (int)$dr['enrolled'];
        $app = (int)$dr['approved'];
        $dr['compliance_rate'] = $en > 0 ? round(($app / $en) * 100, 1) : 0;
        $division_reports[] = $dr;
    }
}

// Top Hiring Organizations
$top_companies = [];
$c_res = @mysqli_query($conn, "
    SELECT company_name, COUNT(*) as hires
    FROM internships
    WHERE status = 'Approved' AND company_name IS NOT NULL AND TRIM(company_name) != ''
    GROUP BY company_name
    ORDER BY hires DESC
    LIMIT 6
");
if ($c_res) {
    while ($cr = mysqli_fetch_assoc($c_res)) {
        $top_companies[] = $cr;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Insights — InternHub Admin</title>
    <?php include __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
        .rep-container {
            padding: 24px 32px 48px;
            max-width: 1540px;
            margin: 0 auto;
        }
        .rep-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 22px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .rep-title {
            font-size: 1.6rem;
            font-weight: 800;
            color: #1e1b4b;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .rep-subtitle {
            font-size: 0.86rem;
            color: #64748b;
            margin-top: 4px;
        }

        /* Accreditation Overview Cards */
        .naac-banner {
            background: linear-gradient(135deg, #1e1b4b 0%, #31135e 50%, #4a1570 100%);
            border-radius: 20px;
            padding: 30px 34px;
            color: #ffffff;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 10px 30px rgba(30, 27, 75, 0.2);
            flex-wrap: wrap;
            gap: 20px;
        }
        .nb-left { max-width: 650px; }
        .nb-badge {
            background: rgba(254, 215, 170, 0.2);
            color: #fed7aa;
            font-size: 0.74rem;
            font-weight: 700;
            letter-spacing: 1px;
            padding: 3px 10px;
            border-radius: 6px;
            text-transform: uppercase;
            display: inline-block;
            margin-bottom: 8px;
        }
        .nb-title {
            font-family: var(--f-font-serif);
            font-size: 1.85rem;
            font-weight: 700;
            font-style: italic;
            margin: 0 0 6px 0;
        }
        .nb-sub {
            font-size: 0.88rem;
            opacity: 0.88;
            line-height: 1.5;
        }
        .nb-score-box {
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.25);
            border-radius: 16px;
            padding: 20px 28px;
            text-align: center;
        }
        .nb-score-val {
            font-size: 2.4rem;
            font-weight: 800;
            color: #34d399;
            line-height: 1;
        }
        .nb-score-lbl {
            font-size: 0.74rem;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-top: 4px;
            opacity: 0.9;
        }

        /* 3 Stats Strip */
        .rep-stats-strip {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        .rep-stat-card {
            background: #ffffff;
            border-radius: 14px;
            padding: 18px 20px;
            border: 1px solid #eef1f6;
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .rep-stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
        }
        .rep-stat-val {
            font-size: 1.5rem;
            font-weight: 800;
            color: #1e1b4b;
            line-height: 1.1;
        }
        .rep-stat-lbl {
            font-size: 0.76rem;
            font-weight: 600;
            color: #64748b;
            margin-top: 2px;
        }

        /* Table Card */
        .rep-table-card {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid #eef1f6;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.03);
            overflow: hidden;
            margin-bottom: 24px;
        }
        .rep-table-head {
            padding: 20px 24px;
            border-bottom: 1px solid #eef1f6;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .rep-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.84rem;
        }
        .rep-table th {
            background: #f8fafc;
            padding: 14px 18px;
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #64748b;
            border-bottom: 1px solid #eef1f6;
        }
        .rep-table td {
            padding: 14px 18px;
            border-bottom: 1px solid #f1f5f9;
            color: #1e1b4b;
        }
    </style>
</head>
<body>

    <?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

    <div class="rep-container">
        
        <!-- Header -->
        <div class="rep-header">
            <div>
                <h1 class="rep-title"><i class="fa-solid fa-chart-column" style="color: #7c3aed;"></i> Reports & Accreditation Insights</h1>
                <div class="rep-subtitle">NAAC Criteria 1.3.4, NIRF placement metrics, and institutional audit compliance records.</div>
            </div>
            <div style="display: flex; gap: 10px;">
                <a href="reports_export_csv.php" class="btn btn-outline-secondary" style="border-radius: 12px; font-weight: 600; font-size: 0.86rem; padding: 10px 18px;">
                    <i class="fa-solid fa-file-csv"></i> Export NAAC CSV
                </a>
                <button type="button" class="btn btn-primary" style="background: #3a104f; border-color: #3a104f; border-radius: 12px; font-weight: 600; font-size: 0.86rem; padding: 10px 18px;" onclick="window.print()">
                    <i class="fa-solid fa-print"></i> Print Official Report
                </button>
            </div>
        </div>

        <!-- NAAC Criteria 1.3.4 Executive Banner -->
        <div class="naac-banner">
            <div class="nb-left">
                <span class="nb-badge">Accreditation Criterion 1.3.4 Compliance</span>
                <h2 class="nb-title">University Internship & Experiential Learning Metric</h2>
                <p class="nb-sub">
                    Percentage of students undertaking project work/field work/internships across MIT-ADT University during the current academic cycle. Continuous automated audit verified.
                </p>
            </div>
            <div class="nb-score-box">
                <div class="nb-score-val"><?php echo $naac_clearance_rate; ?>%</div>
                <div class="nb-score-lbl">Compliance Clearance</div>
            </div>
        </div>

        <!-- 3 Stats Cards -->
        <div class="rep-stats-strip">
            <div class="rep-stat-card">
                <div class="rep-stat-icon" style="background: #eddff7; color: #6b21a8;">
                    <i class="fa-solid fa-user-graduate"></i>
                </div>
                <div>
                    <div class="rep-stat-val"><?php echo number_format($total_students); ?></div>
                    <div class="rep-stat-lbl">Total Enrolled Cohort</div>
                </div>
            </div>
            <div class="rep-stat-card">
                <div class="rep-stat-icon" style="background: #d1fae5; color: #047857;">
                    <i class="fa-solid fa-award"></i>
                </div>
                <div>
                    <div class="rep-stat-val"><?php echo number_format($approved_students); ?></div>
                    <div class="rep-stat-lbl">Verified Placements</div>
                </div>
            </div>
            <div class="rep-stat-card">
                <div class="rep-stat-icon" style="background: #eff6ff; color: #1d4ed8;">
                    <i class="fa-solid fa-building"></i>
                </div>
                <div>
                    <div class="rep-stat-val"><?php echo count($top_companies); ?>+</div>
                    <div class="rep-stat-lbl">Corporate Hiring Partners</div>
                </div>
            </div>
        </div>

        <!-- Division-by-Division Compliance Table -->
        <div class="rep-table-card">
            <div class="rep-table-head">
                <div>
                    <h3 style="font-size: 1.05rem; font-weight: 700; color: #1e1b4b; margin: 0;">Academic Division Compliance Breakdown</h3>
                    <div style="font-size: 0.78rem; color: #64748b; margin-top: 2px;">Enrolled cohort vs verified placements across all university divisions</div>
                </div>
            </div>
            <div style="overflow-x: auto;">
                <table class="rep-table">
                    <thead>
                        <tr>
                            <th>Academic Division</th>
                            <th>Total Students</th>
                            <th>Approved Placements</th>
                            <th>Pending Verification</th>
                            <th>Compliance Rate</th>
                            <th>Accreditation Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($division_reports)): ?>
                            <?php foreach ($division_reports as $dr): ?>
                                <tr>
                                    <td style="font-weight: 700; color: #1e1b4b;"><?php echo h($dr['division']); ?></td>
                                    <td><?php echo number_format($dr['enrolled']); ?></td>
                                    <td style="font-weight: 600; color: #059669;"><?php echo number_format($dr['approved']); ?></td>
                                    <td style="color: #d97706;"><?php echo (int)$dr['pending']; ?></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <span style="font-weight: 800; color: #1e1b4b; width: 44px;"><?php echo $dr['compliance_rate']; ?>%</span>
                                            <div style="flex: 1; max-width: 140px; height: 6px; background: #f1f5f9; border-radius: 4px; overflow: hidden;">
                                                <div style="width: <?php echo min(100, $dr['compliance_rate']); ?>%; height: 100%; background: #10b981; border-radius: 4px;"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($dr['compliance_rate'] >= 90): ?>
                                            <span style="background: #ecfdf5; color: #047857; font-size: 0.72rem; font-weight: 700; padding: 3px 8px; border-radius: 6px;">
                                                <i class="fa-solid fa-check"></i> NAAC Compliant
                                            </span>
                                        <?php else: ?>
                                            <span style="background: #fffbeb; color: #b45309; font-size: 0.72rem; font-weight: 700; padding: 3px 8px; border-radius: 6px;">
                                                <i class="fa-solid fa-clock"></i> In Progress
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- Layout Close -->
    </main>
    </div>

    <?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>
</body>
</html>
