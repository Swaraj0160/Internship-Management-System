<?php
/* -----------------------------------------------------------------
   InternHub — Internship Oversight Console
   Consolidates Applications, Active Internships, Weekly Reports,
   Student Work Showcase, and Completed Internships into a unified workspace.
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

// Assigned divisions
$faculty_divisions = [];
$fd_query = mysqli_query(
    $conn,
    "SELECT DISTINCT division FROM faculty_divisions
     WHERE faculty_id='$faculty_id' AND division IS NOT NULL AND TRIM(division) <> ''
     ORDER BY division ASC"
);

if ($fd_query) {
    while ($div_row = mysqli_fetch_assoc($fd_query)) {
        $v = trim((string)$div_row['division']);
        if ($v !== '') $faculty_divisions[] = $v;
    }
}

$legacy_division = trim((string)($faculty['faculty_division'] ?? ''));
if (empty($faculty_divisions) && $legacy_division !== '') {
    $faculty_divisions[] = $legacy_division;
}
$faculty_divisions = array_values(array_unique($faculty_divisions));

$division_sql_values = array_map(fn($div) => "'" . mysqli_real_escape_string($conn, $div) . "'", $faculty_divisions);
$division_list_sql = implode(',', $division_sql_values);
$faculty_division = !empty($faculty_divisions) ? implode(', ', $faculty_divisions) : 'Faculty Guide';
$div_cond = !empty($division_list_sql) ? "u.division IN ($division_list_sql)" : "1=0";

// Current Active Tab
$tab = $_GET['tab'] ?? 'applications';
if (!in_array($tab, ['applications', 'active', 'reports', 'showcase', 'completed'])) {
    $tab = 'applications';
}

// Search and filter parameters
$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$week_filter = (int)($_GET['week'] ?? 0);

// Handle inline POST feedback submit for weekly report
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_report_feedback'])) {
    $update_id = (int)($_POST['update_id'] ?? 0);
    $feedback_text = trim($_POST['feedback'] ?? '');
    if ($update_id > 0) {
        $upd_stmt = mysqli_prepare($conn, "UPDATE weekly_updates SET faculty_feedback = ? WHERE id = ?");
        if ($upd_stmt) {
            mysqli_stmt_bind_param($upd_stmt, "si", $feedback_text, $update_id);
            mysqli_stmt_execute($upd_stmt);
            mysqli_stmt_close($upd_stmt);
            $success_msg = "Feedback saved successfully.";
        }
    }
}

// Tab 1: Applications Data Query
$apps = [];
if ($tab === 'applications') {
    $where = ["u.role='student'", $div_cond];
    if ($search !== '') {
        $s = mysqli_real_escape_string($conn, $search);
        $where[] = "(u.full_name LIKE '%$s%' OR u.roll_no LIKE '%$s%' OR i.company_name LIKE '%$s%')";
    }
    if ($status_filter !== '') {
        $st = mysqli_real_escape_string($conn, $status_filter);
        $where[] = "i.status='$st'";
    }

    $where_sql = implode(' AND ', $where);
    $app_q = mysqli_query(
        $conn,
        "SELECT i.*, u.full_name, u.roll_no, u.division, u.department, u.email
         FROM internships i
         JOIN users u ON i.student_id = u.id
         WHERE $where_sql
         ORDER BY i.created_at DESC, i.id DESC"
    );
    if ($app_q) {
        while ($r = mysqli_fetch_assoc($app_q)) $apps[] = $r;
    }
}

// Tab 2: Active Internships
$active_internships = [];
if ($tab === 'active') {
    $where_active = "u.role='student' AND $div_cond AND i.status='Approved'";
    if ($search !== '') {
        $s = mysqli_real_escape_string($conn, $search);
        $where_active .= " AND (u.full_name LIKE '%$s%' OR i.company_name LIKE '%$s%')";
    }
    $act_q = mysqli_query(
        $conn,
        "SELECT i.*, u.full_name, u.roll_no, u.division, u.department,
                (SELECT COUNT(*) FROM weekly_updates w JOIN internships wi ON w.internship_id = wi.id WHERE wi.student_id = u.id) as reports_count
         FROM internships i
         JOIN users u ON i.student_id = u.id
         WHERE $where_active
         ORDER BY u.roll_no ASC"
    );
    if ($act_q) {
        while ($r = mysqli_fetch_assoc($act_q)) $active_internships[] = $r;
    }
}

// Tab 3: Weekly Reports
$weekly_reports = [];
if ($tab === 'reports') {
    $where_rep = ["$div_cond"];
    if ($week_filter > 0) {
        $where_rep[] = "w.week_no='$week_filter'";
    }
    if ($search !== '') {
        $s = mysqli_real_escape_string($conn, $search);
        $where_rep[] = "(u.full_name LIKE '%$s%' OR u.roll_no LIKE '%$s%')";
    }
    $where_rep_sql = implode(' AND ', $where_rep);
    $rep_q = mysqli_query(
        $conn,
        "SELECT w.*, u.full_name, u.roll_no, u.division, i.company_name
         FROM weekly_updates w
         JOIN internships i ON w.internship_id = i.id
         JOIN users u ON i.student_id = u.id
         WHERE $where_rep_sql
         ORDER BY w.week_no DESC, w.submitted_at DESC
         LIMIT 60"
    );
    if ($rep_q) {
        while ($r = mysqli_fetch_assoc($rep_q)) $weekly_reports[] = $r;
    }
}

// Tab 4: Showcase Work
$showcase_items = [];
if ($tab === 'showcase') {
    $sc_q = mysqli_query(
        $conn,
        "SELECT u.id as student_id, u.full_name, u.roll_no, u.division, i.company_name, i.title, i.certificate_file
         FROM internships i
         JOIN users u ON i.student_id = u.id
         WHERE $div_cond AND i.status='Approved'
         ORDER BY u.full_name ASC"
    );
    if ($sc_q) {
        while ($r = mysqli_fetch_assoc($sc_q)) $showcase_items[] = $r;
    }
}

// Tab 5: Completed Internships
$completed_items = [];
if ($tab === 'completed') {
    $comp_q = mysqli_query(
        $conn,
        "SELECT i.*, u.full_name, u.roll_no, u.division,
                se.total_marks, se.mentor_evaluation, se.final_evaluation
         FROM internships i
         JOIN users u ON i.student_id = u.id
         LEFT JOIN student_evaluation se ON se.student_id = u.id
         WHERE $div_cond AND i.status='Approved'
         ORDER BY u.roll_no ASC"
    );
    if ($comp_q) {
        while ($r = mysqli_fetch_assoc($comp_q)) $completed_items[] = $r;
    }
}

$activePage = 'internship_oversight.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Internship Oversight | InternHub — MIT-ADT University</title>
    <?php include __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
        .oversight-container {
            padding: 0 32px 36px;
        }

        .page-header-box {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 22px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .page-title-group h1 {
            font-size: 1.6rem;
            font-weight: 800;
            color: #1e1b4b;
            letter-spacing: -0.02em;
        }

        .page-title-group p {
            font-size: 0.88rem;
            color: #64748b;
            margin-top: 3px;
        }

        /* 5 Flagship Navigation Tabs */
        .oversight-tabs {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #ffffff;
            padding: 6px;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            margin-bottom: 24px;
            overflow-x: auto;
            box-shadow: 0 2px 8px rgba(0,0,0,0.02);
        }

        .tab-link {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 18px;
            font-size: 0.86rem;
            font-weight: 600;
            color: #64748b;
            text-decoration: none;
            border-radius: 10px;
            white-space: nowrap;
            transition: all 0.2s ease;
        }

        .tab-link:hover {
            color: #1e1b4b;
            background: #f8fafc;
        }

        .tab-link.active {
            background: #7c3aed;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.25);
        }

        .tab-link.active i {
            color: #ffffff;
        }

        /* Filter Row */
        .filter-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            background: #ffffff;
            padding: 14px 18px;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filter-search-box {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 8px 14px;
            flex: 1;
            max-width: 380px;
        }

        .filter-search-box input {
            border: none;
            outline: none;
            background: transparent;
            font-size: 0.85rem;
            color: #1e1b4b;
            width: 100%;
            font-family: inherit;
        }

        .filter-pills {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .filter-pill {
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 600;
            text-decoration: none;
            color: #64748b;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            transition: all 0.15s ease;
        }

        .filter-pill:hover {
            border-color: #cbd5e1;
            color: #1e1b4b;
        }

        .filter-pill.active {
            background: #1e1b4b;
            border-color: #1e1b4b;
            color: #ffffff;
        }

        /* Modern Table */
        .custom-table-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 16px rgba(0,0,0,0.02);
            overflow: hidden;
        }

        .oversight-table {
            width: 100%;
            border-collapse: collapse;
        }

        .oversight-table th {
            background: #f8fafc;
            padding: 14px 18px;
            font-size: 0.74rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #64748b;
            border-bottom: 1px solid #e2e8f0;
            text-align: left;
        }

        .oversight-table td {
            padding: 14px 18px;
            font-size: 0.86rem;
            color: #334155;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .oversight-table tr:hover {
            background: #fafafc;
        }

        .student-flex {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .student-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, #7c3aed, #4f46e5);
            color: #ffffff;
            font-weight: 700;
            font-size: 0.88rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        /* Action Buttons */
        .btn-action-green {
            background: #10b981;
            color: #ffffff;
            border: none;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.76rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .btn-action-green:hover { background: #059669; }

        .btn-action-red {
            background: #ef4444;
            color: #ffffff;
            border: none;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.76rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .btn-action-red:hover { background: #dc2626; }

        .btn-action-undo {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.76rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .btn-action-undo:hover { background: #e2e8f0; color: #1e1b4b; }

        /* Report Cards */
        .reports-grid {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .report-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .report-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 12px;
        }

        .report-body-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .report-box {
            background: #f8fafc;
            border-radius: 10px;
            padding: 12px;
            border: 1px solid #f1f5f9;
        }

        .report-label {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 4px;
        }

        .feedback-form {
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }

        .feedback-input {
            flex: 1;
            padding: 10px;
            border-radius: 10px;
            border: 1px solid #cbd5e1;
            font-size: 0.84rem;
            font-family: inherit;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 0.74rem;
            font-weight: 600;
        }

        .status-approved { background: #d1fae5; color: #047857; }
        .status-pending { background: #fef3c7; color: #b45309; }
        .status-rejected { background: #fee2e2; color: #b91c1c; }
    </style>
</head>
<body>

<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="oversight-container">
    <!-- Header Box -->
    <div class="page-header-box">
        <div class="page-title-group">
            <h1>Internship Oversight Console</h1>
            <p>Supervise student internship applications, continuous weekly progress, and completion credentials.</p>
        </div>
        <div style="display: flex; gap: 10px;">
            <a href="export_students.php" style="display: flex; align-items: center; gap: 8px; background: #ffffff; border: 1px solid #cbd5e1; padding: 8px 16px; border-radius: 10px; font-size: 0.82rem; font-weight: 600; color: #334155; text-decoration: none;">
                <i class="fa-solid fa-file-export"></i>
                <span>Export Data</span>
            </a>
        </div>
    </div>

    <!-- 5 Flagship Navigation Tabs -->
    <div class="oversight-tabs">
        <a href="?tab=applications" class="tab-link <?php echo $tab === 'applications' ? 'active' : ''; ?>">
            <i class="fa-solid fa-file-signature"></i>
            <span>Applications (<?php echo count($apps); ?>)</span>
        </a>
        <a href="?tab=active" class="tab-link <?php echo $tab === 'active' ? 'active' : ''; ?>">
            <i class="fa-solid fa-briefcase"></i>
            <span>Active Internships</span>
        </a>
        <a href="?tab=reports" class="tab-link <?php echo $tab === 'reports' ? 'active' : ''; ?>">
            <i class="fa-solid fa-calendar-check"></i>
            <span>Weekly Reports</span>
        </a>
        <a href="?tab=showcase" class="tab-link <?php echo $tab === 'showcase' ? 'active' : ''; ?>">
            <i class="fa-solid fa-diagram-project"></i>
            <span>Student Work Showcase</span>
        </a>
        <a href="?tab=completed" class="tab-link <?php echo $tab === 'completed' ? 'active' : ''; ?>">
            <i class="fa-solid fa-certificate"></i>
            <span>Completed Internships</span>
        </a>
    </div>

    <!-- TAB 1: APPLICATIONS -->
    <?php if ($tab === 'applications'): ?>
        <div class="filter-bar">
            <form method="GET" action="" style="display: flex; gap: 12px; flex: 1;">
                <input type="hidden" name="tab" value="applications">
                <div class="filter-search-box">
                    <i class="fa-solid fa-magnifying-glass" style="color: #94a3b8;"></i>
                    <input type="text" name="search" placeholder="Search by name, roll no, or company..." value="<?php echo h($search); ?>">
                </div>
                <button type="submit" style="padding: 8px 16px; border-radius: 10px; background: #7c3aed; color: #fff; border: none; font-weight: 600; font-size: 0.82rem; cursor: pointer;">Search</button>
            </form>

            <div class="filter-pills">
                <a href="?tab=applications" class="filter-pill <?php echo $status_filter === '' ? 'active' : ''; ?>">All Statuses</a>
                <a href="?tab=applications&status=Pending" class="filter-pill <?php echo $status_filter === 'Pending' ? 'active' : ''; ?>">Pending</a>
                <a href="?tab=applications&status=Approved" class="filter-pill <?php echo $status_filter === 'Approved' ? 'active' : ''; ?>">Approved</a>
                <a href="?tab=applications&status=Rejected" class="filter-pill <?php echo $status_filter === 'Rejected' ? 'active' : ''; ?>">Rejected</a>
            </div>
        </div>

        <div class="custom-table-card">
            <table class="oversight-table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Division</th>
                        <th>Company & Role</th>
                        <th>Duration / Dates</th>
                        <th>Offer Letter</th>
                        <th>Status</th>
                        <th style="text-align: right;">Authorization Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($apps)): ?>
                        <?php foreach ($apps as $a): ?>
                            <?php
                            $st = $a['status'] ?? 'Pending';
                            $st_class = match($st) {
                                'Approved' => 'status-approved',
                                'Rejected' => 'status-rejected',
                                default => 'status-pending'
                            };
                            ?>
                            <tr id="app-row-<?php echo (int)$a['id']; ?>">
                                <td>
                                    <div class="student-flex">
                                        <div class="student-avatar"><?php echo strtoupper(substr($a['full_name'] ?? 'S', 0, 1)); ?></div>
                                        <div>
                                            <div style="font-weight: 700; color: #1e1b4b;"><?php echo h($a['full_name']); ?></div>
                                            <div style="font-size: 0.72rem; color: #64748b;"><?php echo h($a['roll_no'] ?: 'PRN: N/A'); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span style="font-weight: 600; color: #475569;"><?php echo h($a['division']); ?></span>
                                </td>
                                <td>
                                    <div style="font-weight: 600; color: #1e1b4b;"><?php echo h($a['company_name'] ?: 'Not Specified'); ?></div>
                                    <div style="font-size: 0.72rem; color: #64748b;"><?php echo h($a['internship_type'] ?: 'Industrial Training'); ?></div>
                                </td>
                                <td>
                                    <div style="font-size: 0.80rem;"><?php echo h($a['start_date'] ? date('d M Y', strtotime($a['start_date'])) : 'N/A'); ?></div>
                                    <div style="font-size: 0.72rem; color: #64748b;">to <?php echo h($a['end_date'] ? date('d M Y', strtotime($a['end_date'])) : 'N/A'); ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($a['offer_letter'])): ?>
                                        <a href="../uploads/offer_letters/<?php echo rawurlencode($a['division']); ?>/<?php echo rawurlencode($a['offer_letter']); ?>" target="_blank" style="color: #7c3aed; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                                            <i class="fa-solid fa-file-pdf"></i>
                                            <span>View Letter</span>
                                        </a>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-size: 0.75rem;">Not Uploaded</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-pill <?php echo $st_class; ?>">
                                        <i class="fa-solid <?php echo $st === 'Approved' ? 'fa-check' : ($st === 'Rejected' ? 'fa-xmark' : 'fa-clock'); ?>" style="font-size: 0.65rem;"></i>
                                        <?php echo h($st); ?>
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    <?php if ($st === 'Pending'): ?>
                                        <button type="button" class="btn-action-green" onclick="handleApprove(<?php echo (int)$a['id']; ?>)">Approve</button>
                                        <button type="button" class="btn-action-red" onclick="handleReject(<?php echo (int)$a['id']; ?>)">Reject</button>
                                    <?php else: ?>
                                        <button type="button" class="btn-action-undo" onclick="handleUndo(<?php echo (int)$a['id']; ?>)">Undo to Pending</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7" style="text-align: center; color: #94a3b8; padding: 32px;">No internship applications found matching your criteria.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- TAB 2: ACTIVE INTERNSHIPS -->
    <?php if ($tab === 'active'): ?>
        <div class="custom-table-card">
            <table class="oversight-table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Division</th>
                        <th>Organization</th>
                        <th>Industry Mentor</th>
                        <th>Reports Filed</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($active_internships)): ?>
                        <?php foreach ($active_internships as $act): ?>
                            <tr>
                                <td>
                                    <div class="student-flex">
                                        <div class="student-avatar"><?php echo strtoupper(substr($act['full_name'] ?? 'S', 0, 1)); ?></div>
                                        <div>
                                            <div style="font-weight: 700; color: #1e1b4b;"><?php echo h($act['full_name']); ?></div>
                                            <div style="font-size: 0.72rem; color: #64748b;"><?php echo h($act['roll_no']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><span style="font-weight: 600;"><?php echo h($act['division']); ?></span></td>
                                <td>
                                    <div style="font-weight: 600;"><?php echo h($act['company_name']); ?></div>
                                    <div style="font-size: 0.72rem; color: #64748b;"><?php echo h($act['internship_type']); ?></div>
                                </td>
                                <td>
                                    <div style="font-weight: 500;"><?php echo h($act['mentor_name'] ?: 'Mentor Not Assigned'); ?></div>
                                    <div style="font-size: 0.72rem; color: #64748b;"><?php echo h($act['company_contact'] ?: 'No Contact'); ?></div>
                                </td>
                                <td>
                                    <span style="background: #ede9fe; color: #7c3aed; font-weight: 700; padding: 2px 8px; border-radius: 999px; font-size: 0.75rem;">
                                        <?php echo (int)$act['reports_count']; ?> Submitted
                                    </span>
                                </td>
                                <td>
                                    <a href="student_portfolios.php?id=<?php echo (int)$act['student_id']; ?>" style="color: #7c3aed; font-weight: 600; text-decoration: none; font-size: 0.82rem;">
                                        View Portfolio &rarr;
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" style="text-align: center; color: #94a3b8; padding: 32px;">No active internships currently logged.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- TAB 3: WEEKLY REPORTS -->
    <?php if ($tab === 'reports'): ?>
        <div class="filter-bar">
            <div class="filter-pills">
                <a href="?tab=reports" class="filter-pill <?php echo $week_filter === 0 ? 'active' : ''; ?>">All Weeks</a>
                <a href="?tab=reports&week=1" class="filter-pill <?php echo $week_filter === 1 ? 'active' : ''; ?>">Week 1</a>
                <a href="?tab=reports&week=2" class="filter-pill <?php echo $week_filter === 2 ? 'active' : ''; ?>">Week 2</a>
                <a href="?tab=reports&week=3" class="filter-pill <?php echo $week_filter === 3 ? 'active' : ''; ?>">Week 3</a>
                <a href="?tab=reports&week=4" class="filter-pill <?php echo $week_filter === 4 ? 'active' : ''; ?>">Week 4</a>
            </div>
        </div>

        <div class="reports-grid">
            <?php if (!empty($weekly_reports)): ?>
                <?php foreach ($weekly_reports as $wr): ?>
                    <div class="report-card">
                        <div class="report-header">
                            <div>
                                <h3 style="font-size: 1.0rem; font-weight: 700; color: #1e1b4b;"><?php echo h($wr['full_name']); ?> (<?php echo h($wr['division']); ?>)</h3>
                                <div style="font-size: 0.75rem; color: #64748b;"><?php echo h($wr['company_name'] ?: 'Company Project'); ?> • Submitted: <?php echo date('d M Y, h:i A', strtotime($wr['submitted_at'])); ?></div>
                            </div>
                            <span style="background: #ede9fe; color: #7c3aed; font-weight: 700; padding: 4px 12px; border-radius: 999px; font-size: 0.80rem;">
                                Week <?php echo (int)$wr['week_no']; ?> Report
                            </span>
                        </div>

                        <div class="report-body-grid">
                            <div class="report-box">
                                <div class="report-label">Work Done & Key Learnings</div>
                                <p style="font-size: 0.84rem; line-height: 1.5; color: #334155;"><?php echo nl2br(h($wr['work_done'] ?? 'Regular development and testing conducted.')); ?></p>
                            </div>
                            <div class="report-box">
                                <div class="report-label">Submission Status</div>
                                <p style="font-size: 0.84rem; line-height: 1.5; color: #334155;"><?php echo h($wr['submission_status'] ?: 'Submitted for Faculty Review'); ?></p>
                            </div>
                        </div>

                        <!-- Feedback Form -->
                        <form method="POST" action="" class="feedback-form">
                            <input type="hidden" name="update_id" value="<?php echo (int)$wr['id']; ?>">
                            <input type="text" name="feedback" id="feedback-input-<?php echo (int)$wr['id']; ?>" class="feedback-input" placeholder="Enter constructive faculty feedback..." value="<?php echo h($wr['faculty_feedback'] ?? ''); ?>">
                            <button type="button" onclick="suggestFeedback(<?php echo (int)$wr['id']; ?>, '<?php echo addslashes($wr['full_name']); ?>', <?php echo (int)$wr['week_no']; ?>)" style="padding: 10px 14px; border-radius: 10px; background: #ede9fe; color: #7c3aed; border: 1px solid #d8b4fe; font-size: 0.80rem; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                                <i class="fa-solid fa-wand-magic-sparkles"></i>
                                <span>AI Suggest</span>
                            </button>
                            <button type="submit" name="submit_report_feedback" style="padding: 10px 18px; border-radius: 10px; background: #7c3aed; color: #fff; border: none; font-size: 0.80rem; font-weight: 600; cursor: pointer;">
                                Save Feedback
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="background: #fff; border-radius: 14px; border: 1px solid #e2e8f0; padding: 40px; text-align: center; color: #94a3b8;">
                    No weekly reports found for the selected criteria.
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- TAB 4: SHOWCASE -->
    <?php if ($tab === 'showcase'): ?>
        <div class="custom-table-card">
            <table class="oversight-table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Division</th>
                        <th>Project / Internship Title</th>
                        <th>Deliverables / Certificate</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($showcase_items)): ?>
                        <?php foreach ($showcase_items as $sc): ?>
                            <tr>
                                <td>
                                    <div class="student-flex">
                                        <div class="student-avatar"><?php echo strtoupper(substr($sc['full_name'], 0, 1)); ?></div>
                                        <div>
                                            <div style="font-weight: 700; color: #1e1b4b;"><?php echo h($sc['full_name']); ?></div>
                                            <div style="font-size: 0.72rem; color: #64748b;"><?php echo h($sc['roll_no']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><span style="font-weight: 600;"><?php echo h($sc['division']); ?></span></td>
                                <td>
                                    <div style="font-weight: 600;"><?php echo h($sc['title'] ?: $sc['company_name'] . ' Technical Project'); ?></div>
                                    <div style="font-size: 0.72rem; color: #64748b;"><?php echo h($sc['company_name']); ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($sc['certificate_file'])): ?>
                                        <a href="../uploads/certificates/<?php echo rawurlencode($sc['division']); ?>/<?php echo rawurlencode($sc['certificate_file']); ?>" target="_blank" style="color: #7c3aed; font-weight: 600; text-decoration: none;">
                                            <i class="fa-solid fa-file-pdf"></i> View Certificate
                                        </a>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-size: 0.75rem;">Under Progress</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="student_portfolios.php?id=<?php echo (int)$sc['student_id']; ?>" style="color: #7c3aed; font-weight: 600; text-decoration: none; font-size: 0.82rem;">
                                        Full Showcase &rarr;
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" style="text-align: center; color: #94a3b8; padding: 32px;">No showcase submissions logged.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- TAB 5: COMPLETED INTERNSHIPS -->
    <?php if ($tab === 'completed'): ?>
        <div class="custom-table-card">
            <table class="oversight-table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Division</th>
                        <th>Organization</th>
                        <th>Continuous Score</th>
                        <th>Final Evaluation</th>
                        <th>Sign-Off Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($completed_items)): ?>
                        <?php foreach ($completed_items as $ci): ?>
                            <tr>
                                <td>
                                    <div class="student-flex">
                                        <div class="student-avatar"><?php echo strtoupper(substr($ci['full_name'], 0, 1)); ?></div>
                                        <div>
                                            <div style="font-weight: 700; color: #1e1b4b;"><?php echo h($ci['full_name']); ?></div>
                                            <div style="font-size: 0.72rem; color: #64748b;"><?php echo h($ci['roll_no']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><span style="font-weight: 600;"><?php echo h($ci['division']); ?></span></td>
                                <td>
                                    <div style="font-weight: 600;"><?php echo h($ci['company_name']); ?></div>
                                    <div style="font-size: 0.72rem; color: #64748b;"><?php echo h($ci['internship_type']); ?></div>
                                </td>
                                <td>
                                    <span style="font-weight: 700; color: #1e1b4b;"><?php echo h($ci['total_marks'] ?: 'Pending'); ?> / 100</span>
                                </td>
                                <td>
                                    <span style="font-size: 0.80rem; color: #64748b;"><?php echo h($ci['mentor_evaluation'] ?: 'Completed'); ?></span>
                                </td>
                                <td>
                                    <span class="status-pill status-approved"><i class="fa-solid fa-check"></i> Authorized</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" style="text-align: center; color: #94a3b8; padding: 32px;">No completed internships records found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
function handleApprove(id) {
    if (!confirm('Authorize and approve this internship application?')) return;
    const fd = new FormData();
    fd.append('action', 'quick_approve');
    fd.append('internship_id', id);

    fetch('api/faculty_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                alert(d.message);
                location.reload();
            } else {
                alert(d.error || 'Approval failed.');
            }
        });
}

function handleReject(id) {
    const reason = prompt('Please enter constructive feedback/reason for rejection:');
    if (!reason || !reason.trim()) {
        alert('Rejection feedback is required to guide the student.');
        return;
    }
    const fd = new FormData();
    fd.append('action', 'quick_reject');
    fd.append('internship_id', id);
    fd.append('reason', reason.trim());

    fetch('api/faculty_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                alert(d.message);
                location.reload();
            } else {
                alert(d.error || 'Rejection failed.');
            }
        });
}

function handleUndo(id) {
    if (!confirm('Revert this application status back to Pending?')) return;
    const fd = new FormData();
    fd.append('action', 'undo_status');
    fd.append('internship_id', id);

    fetch('api/faculty_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                alert(d.message);
                location.reload();
            } else {
                alert(d.error || 'Revert failed.');
            }
        });
}

function suggestFeedback(updateId, studentName, weekNo) {
    const input = document.getElementById('feedback-input-' + updateId);
    input.value = 'Generating AI feedback suggestion...';
    fetch(`api/ai_mentor.php?action=generate_feedback&student_name=${encodeURIComponent(studentName)}&week_no=${weekNo}`)
        .then(r => r.json())
        .then(d => {
            input.value = d.feedback || 'Good engineering progress demonstrated this week.';
        })
        .catch(() => {
            input.value = 'Solid technical execution this week. Continue verifying test cases and industry mentor sign-off.';
        });
}
</script>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>
</body>
</html>
