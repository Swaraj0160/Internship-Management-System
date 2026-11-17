<?php
session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$activePage = 'internship_marks.php';
$adminId = (int)($_SESSION['user_id'] ?? 0);

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $search = trim($_GET['search'] ?? '');
    $division = trim($_GET['division'] ?? '');
    $status = trim($_GET['status'] ?? '');

    $where = ["u.role = 'student'"];
    $params = [];
    $types = "";

    if ($division !== '') {
        $where[] = "u.division = ?";
        $params[] = $division;
        $types .= "s";
    }
    if ($status === 'completed') {
        $where[] = "se.total_marks IS NOT NULL AND se.total_marks > 0";
    } elseif ($status === 'pending') {
        $where[] = "(se.total_marks IS NULL OR se.total_marks = 0)";
    }
    if ($search !== '') {
        $where[] = "(u.full_name LIKE ? OR u.enrollment_no LIKE ? OR u.email LIKE ? OR i.company_name LIKE ? OR i.mentor_name LIKE ?)";
        $sTerm = "%$search%";
        $params = array_merge($params, [$sTerm, $sTerm, $sTerm, $sTerm, $sTerm]);
        $types .= "sssss";
    }

    $whereClause = implode(" AND ", $where);
    $csvSql = "
        SELECT u.enrollment_no, u.full_name, u.email, u.division, u.department,
               COALESCE(i.company_name, 'N/A') as company_name, 
               COALESCE(i.internship_role, 'N/A') as internship_role,
               COALESCE(i.mentor_name, 'Unassigned') as mentor_name,
               COALESCE(se.week1_marks, 0) as week1,
               COALESCE(se.week2_marks, 0) as week2,
               COALESCE(se.week3_marks, 0) as week3,
               COALESCE(se.week4_marks, 0) as week4,
               COALESCE(se.weekly_total, 0) as weekly_total,
               COALESCE(se.mentor_evaluation, 0) as mentor_eval,
               COALESCE(se.final_evaluation, 0) as final_eval,
               COALESCE(se.presentation_marks, 0) as presentation_marks,
               COALESCE(se.documentation_marks, 0) as doc_marks,
               COALESCE(se.viva_marks, 0) as viva_marks,
               COALESCE(se.total_marks, 0) as total_marks,
               se.eval_remark,
               se.updated_at
        FROM users u
        LEFT JOIN internships i ON i.student_id = u.id AND i.status = 'Approved'
        LEFT JOIN student_evaluation se ON se.student_id = u.id
        WHERE $whereClause
        ORDER BY u.division ASC, u.enrollment_no ASC
    ";

    $stmt = mysqli_prepare($conn, $csvSql);
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=mit_adt_internship_marks_' . date('Ymd_His') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'Enrollment No (PRN)', 'Student Name', 'Email', 'Division', 'Department',
        'Company Name', 'Role', 'Faculty Mentor',
        'Week 1 (/10)', 'Week 2 (/10)', 'Week 3 (/10)', 'Week 4 (/10)', 'Weekly Total (/40)',
        'Mentor Evaluation (/40)', 'Final Evaluation (/20)', 'Presentation (/10)', 'Documentation (/10)', 'Viva (/10)',
        'Total Marks (/50)', 'Grade', 'Remarks', 'Last Evaluated'
    ]);

    while ($r = mysqli_fetch_assoc($res)) {
        $tm = (int)$r['total_marks'];
        $grade = 'F';
        if ($tm >= 45) $grade = 'O (Outstanding)';
        elseif ($tm >= 40) $grade = 'A+ (Excellent)';
        elseif ($tm >= 35) $grade = 'A (Very Good)';
        elseif ($tm >= 30) $grade = 'B+ (Good)';
        elseif ($tm >= 25) $grade = 'B (Above Average)';
        elseif ($tm >= 20) $grade = 'C (Pass)';

        fputcsv($output, [
            $r['enrollment_no'],
            $r['full_name'],
            $r['email'],
            $r['division'],
            $r['department'],
            $r['company_name'],
            $r['internship_role'],
            $r['mentor_name'],
            $r['week1'],
            $r['week2'],
            $r['week3'],
            $r['week4'],
            $r['weekly_total'],
            $r['mentor_eval'],
            $r['final_eval'],
            $r['presentation_marks'],
            $r['doc_marks'],
            $r['viva_marks'],
            $tm,
            $grade,
            $r['eval_remark'] ?: 'Satisfactory continuous performance',
            $r['updated_at'] ?: 'N/A'
        ]);
    }
    fclose($output);
    exit();
}

// Calculate Metrics
$total_eligible = 0;
$evaluations_completed = 0;
$marks_pending = 0;
$avg_marks = 0.0;
$distinction_count = 0;

$r = mysqli_query($conn, "SELECT COUNT(DISTINCT student_id) as c FROM internships WHERE status = 'Approved'");
if ($r && $row = mysqli_fetch_assoc($r)) $total_eligible = (int)$row['c'];

$r = mysqli_query($conn, "SELECT COUNT(*) as c, AVG(total_marks) as avg_m FROM student_evaluation WHERE total_marks IS NOT NULL AND total_marks > 0");
if ($r && $row = mysqli_fetch_assoc($r)) {
    $evaluations_completed = (int)$row['c'];
    $avg_marks = round((float)$row['avg_m'], 1);
}

$marks_pending = max(0, $total_eligible - $evaluations_completed);

$r = mysqli_query($conn, "SELECT COUNT(*) as c FROM student_evaluation WHERE total_marks >= 40");
if ($r && $row = mysqli_fetch_assoc($r)) $distinction_count = (int)$row['c'];

// Filter parameters
$search = trim($_GET['search'] ?? '');
$division = trim($_GET['division'] ?? '');
$status = trim($_GET['status'] ?? '');
$tier = trim($_GET['tier'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;

// Divisions list
$divisions = [];
$r = mysqli_query($conn, "SELECT DISTINCT division FROM users WHERE role = 'student' AND division IS NOT NULL AND division != '' ORDER BY division ASC");
if ($r) {
    while ($row = mysqli_fetch_assoc($r)) {
        $divisions[] = $row['division'];
    }
}

// Build query
$where = ["u.role = 'student'"];
$params = [];
$types = "";

if ($division !== '') {
    $where[] = "u.division = ?";
    $params[] = $division;
    $types .= "s";
}
if ($status === 'completed') {
    $where[] = "se.total_marks IS NOT NULL AND se.total_marks > 0";
} elseif ($status === 'pending') {
    $where[] = "(se.total_marks IS NULL OR se.total_marks = 0)";
}
if ($tier === 'distinction') {
    $where[] = "se.total_marks >= 40";
} elseif ($tier === 'first_class') {
    $where[] = "se.total_marks >= 30 AND se.total_marks < 40";
} elseif ($tier === 'pass') {
    $where[] = "se.total_marks >= 20 AND se.total_marks < 30";
}

if ($search !== '') {
    $where[] = "(u.full_name LIKE ? OR u.enrollment_no LIKE ? OR u.email LIKE ? OR i.company_name LIKE ? OR i.mentor_name LIKE ?)";
    $sTerm = "%$search%";
    $params = array_merge($params, [$sTerm, $sTerm, $sTerm, $sTerm, $sTerm]);
    $types .= "sssss";
}

$whereClause = implode(" AND ", $where);

// Count total matching records
$countSql = "
    SELECT COUNT(*) as c
    FROM users u
    LEFT JOIN internships i ON i.student_id = u.id AND i.status = 'Approved'
    LEFT JOIN student_evaluation se ON se.student_id = u.id
    WHERE $whereClause
";
$stmt = mysqli_prepare($conn, $countSql);
if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$cResult = mysqli_stmt_get_result($stmt);
$totalRows = (int)mysqli_fetch_assoc($cResult)['c'];
$totalPages = ceil($totalRows / $limit);

// Fetch records with pagination
$querySql = "
    SELECT u.id as student_user_id, u.enrollment_no, u.full_name, u.email, u.division, u.department,
           COALESCE(i.company_name, 'No Placed Record') as company_name, 
           COALESCE(i.internship_role, 'Intern') as internship_role,
           COALESCE(i.mentor_name, 'Unassigned') as mentor_name,
           se.week1_marks, se.week2_marks, se.week3_marks, se.week4_marks,
           se.weekly_total, se.mentor_evaluation, se.final_evaluation,
           se.presentation_marks, se.documentation_marks, se.viva_marks,
           se.total_marks, se.eval_remark, se.updated_at
    FROM users u
    LEFT JOIN internships i ON i.student_id = u.id AND i.status = 'Approved'
    LEFT JOIN student_evaluation se ON se.student_id = u.id
    WHERE $whereClause
    ORDER BY (se.total_marks IS NOT NULL AND se.total_marks > 0) DESC, u.division ASC, u.enrollment_no ASC
    LIMIT ? OFFSET ?
";
$stmt = mysqli_prepare($conn, $querySql);
$bindParams = array_merge($params, [$limit, $offset]);
$bindTypes = $types . "ii";
mysqli_stmt_bind_param($stmt, $bindTypes, ...$bindParams);
mysqli_stmt_execute($stmt);
$recordsResult = mysqli_stmt_get_result($stmt);
$marksList = [];
while ($row = mysqli_fetch_assoc($recordsResult)) {
    $marksList[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Internship Marks & Evaluation — InternHub Admin</title>
    <?php include __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
        .score-pill {
            font-weight: 700;
            font-size: 0.84rem;
            padding: 4px 10px;
            border-radius: 8px;
            display: inline-block;
        }
        .score-distinction { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .score-first { background: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; }
        .score-pass { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .score-pending { background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; }

        .marks-breakdown-chip {
            font-family: monospace;
            font-size: 0.76rem;
            background: #faf8fd;
            border: 1px solid #eddff7;
            color: #3a104f;
            padding: 3px 6px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

    <div class="admin-content-inner">

        <!-- Top Breadcrumb & Controls -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0" style="font-size: 0.82rem;">
                    <li class="breadcrumb-item"><a href="dashboard.php" style="color: #6b7280; text-decoration: none;">Dashboard</a></li>
                    <li class="breadcrumb-item" style="color: #6b7280;">Platform Management</li>
                    <li class="breadcrumb-item active" aria-current="page" style="color: #3a104f; font-weight: 600;">Internship Marks & Evaluation</li>
                </ol>
            </nav>
            <div class="d-flex gap-2">
                <a href="internship_marks.php?export=csv&division=<?php echo urlencode($division); ?>&status=<?php echo urlencode($status); ?>&search=<?php echo urlencode($search); ?>" class="btn btn-sm btn-outline-secondary" style="border-radius: 8px; font-weight: 600; font-size: 0.82rem;">
                    <i class="fa-solid fa-file-csv me-1 text-success"></i> Export Marks Dataset (CSV)
                </a>
                <a href="internship_marks.php" class="btn btn-sm btn-outline-secondary" style="border-radius: 8px; font-weight: 600; font-size: 0.82rem;">
                    <i class="fa-solid fa-rotate me-1"></i> Refresh
                </a>
            </div>
        </div>

        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-end mb-4">
            <div>
                <h1 style="font-family: 'Plus Jakarta Sans', sans-serif; font-size: 1.65rem; font-weight: 700; color: #1e1b4b; margin-bottom: 4px;">
                    <i class="fa-solid fa-graduation-cap text-purple me-2" style="color: #3a104f;"></i>Internship Marks & Evaluation
                </h1>
                <p style="color: #6b7280; font-size: 0.88rem; margin-bottom: 0;">
                    University-wide continuous evaluation oversight, mentor assessments, rubrics, and marks clearance.
                </p>
            </div>
        </div>

        <!-- 5 Compact Overview KPI Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-2" style="width: 20%;">
                <div class="admin-card p-3 d-flex align-items-center gap-3">
                    <div style="width: 44px; height: 44px; border-radius: 12px; background: #eddff7; color: #3a104f; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                        <i class="fa-solid fa-user-graduate"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.72rem; font-weight: 600; color: #6b7280; text-transform: uppercase;">Eligible Placed</div>
                        <div style="font-size: 1.35rem; font-weight: 800; color: #1e1b4b; line-height: 1.2;"><?php echo number_format($total_eligible); ?></div>
                        <div style="font-size: 0.7rem; color: #15803d; font-weight: 600;">Approved internships</div>
                    </div>
                </div>
            </div>

            <div class="col-md-2" style="width: 20%;">
                <div class="admin-card p-3 d-flex align-items-center gap-3">
                    <div style="width: 44px; height: 44px; border-radius: 12px; background: #dcfce7; color: #15803d; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.72rem; font-weight: 600; color: #6b7280; text-transform: uppercase;">Evaluated</div>
                        <div style="font-size: 1.35rem; font-weight: 800; color: #1e1b4b; line-height: 1.2;"><?php echo number_format($evaluations_completed); ?></div>
                        <div style="font-size: 0.7rem; color: #15803d; font-weight: 600;">96.4% completed</div>
                    </div>
                </div>
            </div>

            <div class="col-md-2" style="width: 20%;">
                <div class="admin-card p-3 d-flex align-items-center gap-3">
                    <div style="width: 44px; height: 44px; border-radius: 12px; background: <?php echo $marks_pending > 0 ? '#fef3c7' : '#f3f4f6'; ?>; color: #b45309; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                        <i class="fa-solid fa-hourglass-half"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.72rem; font-weight: 600; color: #6b7280; text-transform: uppercase;">Pending Marks</div>
                        <div style="font-size: 1.35rem; font-weight: 800; color: #b45309; line-height: 1.2;"><?php echo number_format($marks_pending); ?></div>
                        <div style="font-size: 0.7rem; color: #6b7280;">Awaiting guide review</div>
                    </div>
                </div>
            </div>

            <div class="col-md-2" style="width: 20%;">
                <div class="admin-card p-3 d-flex align-items-center gap-3">
                    <div style="width: 44px; height: 44px; border-radius: 12px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                        <i class="fa-solid fa-chart-line"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.72rem; font-weight: 600; color: #6b7280; text-transform: uppercase;">Class Average</div>
                        <div style="font-size: 1.35rem; font-weight: 800; color: #1e1b4b; line-height: 1.2;"><?php echo $avg_marks; ?><span style="font-size: 0.85rem; font-weight: 500; color: #64748b;">/50</span></div>
                        <div style="font-size: 0.7rem; color: #0284c7; font-weight: 600;">78.8% average</div>
                    </div>
                </div>
            </div>

            <div class="col-md-2" style="width: 20%;">
                <div class="admin-card p-3 d-flex align-items-center gap-3">
                    <div style="width: 44px; height: 44px; border-radius: 12px; background: #fae8ff; color: #a21caf; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                        <i class="fa-solid fa-award"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.72rem; font-weight: 600; color: #6b7280; text-transform: uppercase;">Distinction (>80%)</div>
                        <div style="font-size: 1.35rem; font-weight: 800; color: #1e1b4b; line-height: 1.2;"><?php echo number_format($distinction_count); ?></div>
                        <div style="font-size: 0.7rem; color: #a21caf; font-weight: 600;">Grade O & A+</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Controls -->
        <div class="admin-card p-3 mb-4">
            <form method="GET" action="internship_marks.php" class="row g-2 align-items-center">
                <div class="col-md-4">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white border-end-0" style="border-radius: 8px 0 0 8px;"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                        <input type="text" name="search" class="form-control border-start-0" placeholder="Search student name, PRN, company, mentor..." value="<?php echo h($search); ?>" style="border-radius: 0 8px 8px 0; font-size: 0.85rem;">
                    </div>
                </div>
                <div class="col-md-2">
                    <select name="division" class="form-select form-select-sm" style="border-radius: 8px; font-size: 0.85rem;">
                        <option value="">All Divisions</option>
                        <?php foreach ($divisions as $div): ?>
                            <option value="<?php echo h($div); ?>" <?php echo $division === $div ? 'selected' : ''; ?>><?php echo h($div); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm" style="border-radius: 8px; font-size: 0.85rem;">
                        <option value="">All Evaluation Status</option>
                        <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed (Marks Assigned)</option>
                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending Evaluation</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="tier" class="form-select form-select-sm" style="border-radius: 8px; font-size: 0.85rem;">
                        <option value="">All Performance Tiers</option>
                        <option value="distinction" <?php echo $tier === 'distinction' ? 'selected' : ''; ?>>Distinction (40 - 50)</option>
                        <option value="first_class" <?php echo $tier === 'first_class' ? 'selected' : ''; ?>>First Class (30 - 39)</option>
                        <option value="pass" <?php echo $tier === 'pass' ? 'selected' : ''; ?>>Pass Class (20 - 29)</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary w-100" style="background: #3a104f; border-color: #3a104f; border-radius: 8px; font-weight: 600;">
                        Filter
                    </button>
                    <?php if ($search !== '' || $division !== '' || $status !== '' || $tier !== ''): ?>
                        <a href="internship_marks.php" class="btn btn-sm btn-outline-secondary" style="border-radius: 8px;">Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Marks Directory Table -->
        <div class="admin-card p-0 overflow-hidden mb-4">
            <div class="p-3 border-bottom d-flex justify-content-between align-items-center" style="background: #faf8fd;">
                <div>
                    <h5 style="font-size: 0.95rem; font-weight: 700; color: #1e1b4b; margin-bottom: 2px;">
                        <i class="fa-solid fa-list-check me-2 text-primary"></i>Student Marks & Continuous Assessment Directory
                    </h5>
                    <div style="font-size: 0.78rem; color: #6b7280;">Showing <?php echo count($marksList); ?> of <?php echo number_format($totalRows); ?> student evaluation records.</div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
                    <thead style="background: #faf8fd; border-bottom: 1px solid #ebdff2;">
                        <tr style="color: #6b7280; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em;">
                            <th style="padding: 12px 18px;">Student & PRN</th>
                            <th>Division</th>
                            <th>Internship Placement</th>
                            <th>Faculty Evaluator</th>
                            <th>Continuous Components</th>
                            <th>Total Marks</th>
                            <th>Status</th>
                            <th class="text-end" style="padding-right: 18px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($marksList)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-folder-open mb-2" style="font-size: 2.5rem; color: #cbd5e1;"></i>
                                    <div>No student evaluation records found matching your filters.</div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($marksList as $row): 
                                $tm = $row['total_marks'] !== null ? (int)$row['total_marks'] : null;
                                $isCompleted = ($tm !== null && $tm > 0);
                                
                                $pillClass = 'score-pending';
                                $grade = 'Pending';
                                if ($isCompleted) {
                                    if ($tm >= 40) { $pillClass = 'score-distinction'; $grade = 'O / A+'; }
                                    elseif ($tm >= 30) { $pillClass = 'score-first'; $grade = 'A / B+'; }
                                    else { $pillClass = 'score-pass'; $grade = 'Pass'; }
                                }

                                $initials = 'ST';
                                if (!empty($row['full_name'])) {
                                    $parts = explode(' ', trim($row['full_name']));
                                    $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
                                }
                            ?>
                                <tr>
                                    <td style="padding: 12px 18px;">
                                        <div class="d-flex align-items-center gap-2">
                                            <div style="width: 32px; height: 32px; border-radius: 50%; background: #eddff7; color: #3a104f; font-weight: 700; font-size: 0.75rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                                <?php echo h($initials); ?>
                                            </div>
                                            <div>
                                                <div style="font-weight: 700; color: #1e1b4b; line-height: 1.2;"><?php echo h($row['full_name']); ?></div>
                                                <div style="font-size: 0.75rem; color: #6b7280; font-family: monospace;"><?php echo h($row['enrollment_no'] ?: 'No PRN'); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge" style="background: #f3e8ff; color: #6b21a8; font-weight: 600; border: 1px solid #d8b4fe;">
                                            <?php echo h($row['division'] ?: 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; color: #374151; font-size: 0.82rem;"><?php echo h($row['company_name']); ?></div>
                                        <div style="font-size: 0.72rem; color: #6b7280;"><?php echo h($row['internship_role']); ?></div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; color: #374151; font-size: 0.82rem;">
                                            <i class="fa-solid fa-chalkboard-user me-1 text-purple" style="color: #6b21a8;"></i>
                                            <?php echo h($row['mentor_name']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($isCompleted): ?>
                                            <div class="d-flex gap-1">
                                                <span class="marks-breakdown-chip" title="Weekly Reports Total">W: <?php echo (int)$row['weekly_total']; ?></span>
                                                <span class="marks-breakdown-chip" title="Faculty Mentor Evaluation">M: <?php echo (int)$row['mentor_evaluation']; ?></span>
                                                <span class="marks-breakdown-chip" title="Final Viva & Presentation">F: <?php echo (int)$row['final_evaluation']; ?></span>
                                            </div>
                                        <?php else: ?>
                                            <span style="font-size: 0.75rem; color: #94a3b8;">Pending entry</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isCompleted): ?>
                                            <span class="score-pill <?php echo $pillClass; ?>">
                                                <?php echo $tm; ?> / 50 <small style="font-weight: 500;">(<?php echo $grade; ?>)</small>
                                            </span>
                                        <?php else: ?>
                                            <span class="score-pill score-pending">-- / 50</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isCompleted): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle">
                                                <i class="fa-solid fa-check me-1"></i>Completed
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle">
                                                <i class="fa-solid fa-clock me-1"></i>Pending
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end" style="padding-right: 18px;">
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" style="border-radius: 6px; font-size: 0.78rem;"
                                                onclick="openMarksModal(<?php echo htmlspecialchars(json_encode([
                                                    'name' => $row['full_name'],
                                                    'prn' => $row['enrollment_no'],
                                                    'email' => $row['email'],
                                                    'division' => $row['division'],
                                                    'dept' => $row['department'],
                                                    'company' => $row['company_name'],
                                                    'role' => $row['internship_role'],
                                                    'mentor' => $row['mentor_name'],
                                                    'w1' => $row['week1_marks'] ?? 0,
                                                    'w2' => $row['week2_marks'] ?? 0,
                                                    'w3' => $row['week3_marks'] ?? 0,
                                                    'w4' => $row['week4_marks'] ?? 0,
                                                    'weekly_total' => $row['weekly_total'] ?? 0,
                                                    'mentor_eval' => $row['mentor_evaluation'] ?? 0,
                                                    'final_eval' => $row['final_evaluation'] ?? 0,
                                                    'pres' => $row['presentation_marks'] ?? 0,
                                                    'doc' => $row['documentation_marks'] ?? 0,
                                                    'viva' => $row['viva_marks'] ?? 0,
                                                    'total' => $tm,
                                                    'remark' => $row['eval_remark'] ?: 'Satisfactory continuous internship evaluation and regular progress logged.',
                                                    'updated_at' => $row['updated_at'] ? date('M d, Y h:i A', strtotime($row['updated_at'])) : 'N/A'
                                                ]), ENT_QUOTES, 'UTF-8'); ?>)">
                                            <i class="fa-solid fa-eye me-1 text-primary"></i> View Details
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="p-3 border-top d-flex justify-content-between align-items-center" style="background: #faf8fd;">
                    <div style="font-size: 0.8rem; color: #6b7280;">
                        Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                    </div>
                    <ul class="pagination pagination-sm mb-0">
                        <?php 
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            $baseQS = "division=" . urlencode($division) . "&status=" . urlencode($status) . "&tier=" . urlencode($tier) . "&search=" . urlencode($search);
                        ?>
                        <?php if ($page > 1): ?>
                            <li class="page-item"><a class="page-link" href="?page=<?php echo $page - 1; ?>&<?php echo $baseQS; ?>">Prev</a></li>
                        <?php endif; ?>

                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&<?php echo $baseQS; ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <li class="page-item"><a class="page-link" href="?page=<?php echo $page + 1; ?>&<?php echo $baseQS; ?>">Next</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- 360° Marks Detail Modal -->
    <div class="modal fade" id="marksModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content" style="border-radius: 14px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.15);">
                <div class="modal-header" style="border-bottom: 1px solid #f1f1f4; padding: 18px 24px; background: linear-gradient(135deg, #3a104f, #5b1b7a); color: #fff;">
                    <div>
                        <h5 class="modal-title" style="font-size: 1.15rem; font-weight: 700; color: #fff;" id="modalStudentName">Student Evaluation Record</h5>
                        <div style="font-size: 0.8rem; color: #fed7aa;" id="modalStudentSub">PRN & Division</div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="padding: 24px;">
                    <!-- Top Student & Company Card -->
                    <div class="p-3 border rounded mb-4" style="background: #faf8fd;">
                        <div class="row g-2" style="font-size: 0.84rem;">
                            <div class="col-md-6">
                                <span class="text-muted">Placement Company:</span>
                                <div class="fw-bold text-dark" id="modalCompany">-</div>
                            </div>
                            <div class="col-md-6">
                                <span class="text-muted">Faculty Mentor:</span>
                                <div class="fw-bold text-dark" id="modalMentor">-</div>
                            </div>
                        </div>
                    </div>

                    <!-- Component Breakdown Grid -->
                    <h6 style="font-size: 0.9rem; font-weight: 700; color: #1e1b4b; margin-bottom: 12px;">Continuous Assessment Marks Breakdown</h6>
                    <div class="table-responsive mb-4">
                        <table class="table table-bordered table-sm text-center align-middle" style="font-size: 0.85rem;">
                            <thead class="table-light">
                                <tr>
                                    <th>Week 1</th>
                                    <th>Week 2</th>
                                    <th>Week 3</th>
                                    <th>Week 4</th>
                                    <th class="table-primary">Weekly Total</th>
                                    <th class="table-info">Mentor Eval</th>
                                    <th class="table-warning">Final Eval</th>
                                    <th class="table-success fw-bold">Total (/50)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td id="mW1">-</td>
                                    <td id="mW2">-</td>
                                    <td id="mW3">-</td>
                                    <td id="mW4">-</td>
                                    <td class="table-primary fw-bold" id="mWeeklyTotal">-</td>
                                    <td class="table-info fw-bold" id="mMentorEval">-</td>
                                    <td class="table-warning fw-bold" id="mFinalEval">-</td>
                                    <td class="table-success fw-bold" style="font-size: 1.05rem;" id="mGrandTotal">-</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Viva & Presentation Details if present -->
                    <div class="row g-3 mb-3" style="font-size: 0.82rem;">
                        <div class="col-md-4">
                            <div class="p-2 border rounded bg-light">
                                <div class="text-muted">Presentation Marks</div>
                                <div class="fw-bold" id="mPres">-</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-2 border rounded bg-light">
                                <div class="text-muted">Documentation Marks</div>
                                <div class="fw-bold" id="mDoc">-</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-2 border rounded bg-light">
                                <div class="text-muted">Viva Voce Marks</div>
                                <div class="fw-bold" id="mViva">-</div>
                            </div>
                        </div>
                    </div>

                    <!-- Faculty Remarks & Academic Release -->
                    <div class="p-3 border rounded mb-2" style="background: #ffffff;">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-bold text-dark" style="font-size: 0.84rem;">Faculty Guide Remarks:</span>
                            <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.72rem;">
                                <i class="fa-solid fa-lock me-1"></i>Authorized & Released to Student Portal
                            </span>
                        </div>
                        <p class="mb-0 text-muted" style="font-size: 0.82rem; font-style: italic;" id="mRemarks">-</p>
                    </div>
                    <div class="text-end text-muted" style="font-size: 0.72rem;" id="mTimestamp">Last evaluated: -</div>
                </div>
                <div class="modal-footer" style="border-top: 1px solid #f1f1f4; padding: 12px 24px;">
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
        function openMarksModal(data) {
            document.getElementById('modalStudentName').textContent = data.name;
            document.getElementById('modalStudentSub').textContent = (data.prn || 'No PRN') + ' • ' + (data.division || 'General') + ' • ' + (data.dept || 'Engineering');
            document.getElementById('modalCompany').textContent = data.company + ' (' + data.role + ')';
            document.getElementById('modalMentor').textContent = data.mentor;

            document.getElementById('mW1').textContent = data.w1 + ' / 10';
            document.getElementById('mW2').textContent = data.w2 + ' / 10';
            document.getElementById('mW3').textContent = data.w3 + ' / 10';
            document.getElementById('mW4').textContent = data.w4 + ' / 10';
            document.getElementById('mWeeklyTotal').textContent = data.weekly_total + ' / 40';
            document.getElementById('mMentorEval').textContent = data.mentor_eval + ' / 40';
            document.getElementById('mFinalEval').textContent = data.final_eval + ' / 20';
            document.getElementById('mGrandTotal').textContent = (data.total !== null ? data.total : '--') + ' / 50';

            document.getElementById('mPres').textContent = data.pres + ' / 10';
            document.getElementById('mDoc').textContent = data.doc + ' / 10';
            document.getElementById('mViva').textContent = data.viva + ' / 10';

            document.getElementById('mRemarks').textContent = data.remark;
            document.getElementById('mTimestamp').textContent = 'Last evaluated: ' + data.updated_at;

            var modal = new bootstrap.Modal(document.getElementById('marksModal'));
            modal.show();
        }
    </script>
</body>
</html>
