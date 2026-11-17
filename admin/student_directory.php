<?php
session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$activePage = 'student_directory.php';

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// -------------------------------------------------------------
// AJAX ENDPOINT FOR 360° PORTFOLIO DETAILS
// -------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'student_details') {
    header('Content-Type: application/json; charset=UTF-8');
    $studentId = (int)($_GET['id'] ?? 0);
    
    // Student Core
    $st_stmt = $conn->prepare("
        SELECT id, full_name, email, phone, enrollment_no, roll_no, division, department, 
               year_of_study, semester, specialization, created_at
        FROM users 
        WHERE id = ? AND role = 'student' 
        LIMIT 1
    ");
    $st_stmt->bind_param("i", $studentId);
    $st_stmt->execute();
    $student = $st_stmt->get_result()->fetch_assoc();
    
    if (!$student) {
        echo json_encode(['ok' => false, 'error' => 'Student not found']);
        exit();
    }
    
    // Internship
    $in_stmt = $conn->prepare("
        SELECT id, company_name, internship_type, start_date, end_date, mentor_name, 
               stipend, status, offer_letter, mode, internship_role
        FROM internships 
        WHERE student_id = ? 
        ORDER BY id DESC LIMIT 1
    ");
    $in_stmt->bind_param("i", $studentId);
    $in_stmt->execute();
    $internship = $in_stmt->get_result()->fetch_assoc();
    
    // Skills
    $sk_stmt = $conn->prepare("
        SELECT s.name, ss.proficiency_level
        FROM student_skills ss
        JOIN skills s ON ss.skill_id = s.id
        WHERE ss.student_id = ?
    ");
    $sk_stmt->bind_param("i", $studentId);
    $sk_stmt->execute();
    $skills = $sk_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Projects
    $pr_stmt = $conn->prepare("
        SELECT title, description, github_url, live_url, start_date, end_date
        FROM projects 
        WHERE student_id = ?
        ORDER BY id DESC
    ");
    $pr_stmt->bind_param("i", $studentId);
    $pr_stmt->execute();
    $projects = $pr_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Evaluations
    $eval = null;
    if ($internship) {
        $ev_stmt = $conn->prepare("
            SELECT ta1_marks, ta2_marks, attendance_marks, final_evaluation_marks, total_marks, remarks 
            FROM evaluations 
            WHERE internship_id = ? 
            ORDER BY id DESC LIMIT 1
        ");
        $ev_stmt->bind_param("i", $internship['id']);
        $ev_stmt->execute();
        $eval = $ev_stmt->get_result()->fetch_assoc();
    }
    
    // Weekly reports count
    $updates_count = 0;
    if ($internship) {
        $up_stmt = $conn->prepare("SELECT COUNT(*) as c FROM weekly_updates WHERE internship_id = ?");
        $up_stmt->bind_param("i", $internship['id']);
        $up_stmt->execute();
        $updates_count = (int)($up_stmt->get_result()->fetch_assoc()['c'] ?? 0);
    }
    
    echo json_encode([
        'ok' => true,
        'student' => $student,
        'internship' => $internship,
        'skills' => $skills,
        'projects' => $projects,
        'evaluation' => $eval,
        'updates_count' => $updates_count
    ]);
    exit();
}

// -------------------------------------------------------------
// FILTERING & DIRECTORY LISTING
// -------------------------------------------------------------
$search = trim($_GET['search'] ?? $_GET['q'] ?? '');
$filter_division = trim($_GET['division'] ?? '');
$filter_year = trim($_GET['year'] ?? '');
$filter_status = trim($_GET['status'] ?? '');
$view_mode = trim($_GET['view'] ?? 'grid');

// Aggregate Counts
$total_students = 0;
$c_res = @mysqli_query($conn, "SELECT COUNT(*) as c FROM users WHERE role='student'");
if ($c_res) $total_students = (int)(mysqli_fetch_assoc($c_res)['c'] ?? 0);

$placed_count = 0;
$pending_count = 0;
$rejected_count = 0;
$st_q = @mysqli_query($conn, "
    SELECT i.status, COUNT(*) as c 
    FROM internships i 
    JOIN users u ON i.student_id = u.id 
    WHERE u.role = 'student' 
    GROUP BY i.status
");
if ($st_q) {
    while ($sr = mysqli_fetch_assoc($st_q)) {
        if ($sr['status'] === 'Approved') $placed_count = (int)$sr['c'];
        elseif ($sr['status'] === 'Pending') $pending_count = (int)$sr['c'];
        elseif ($sr['status'] === 'Rejected') $rejected_count = (int)$sr['c'];
    }
}
$not_submitted = max(0, $total_students - ($placed_count + $pending_count + $rejected_count));

// Divisions List
$divisions = [];
$div_q = @mysqli_query($conn, "SELECT DISTINCT division FROM users WHERE role='student' AND division IS NOT NULL AND TRIM(division) != '' ORDER BY division");
if ($div_q) {
    while ($dr = mysqli_fetch_assoc($div_q)) {
        $divisions[] = $dr['division'];
    }
}

// Query Construction
$where = ["u.role = 'student'"];
$params = [];
$types = "";

if ($search !== '') {
    $where[] = "(u.full_name LIKE ? OR u.enrollment_no LIKE ? OR u.roll_no LIKE ? OR u.email LIKE ? OR i.company_name LIKE ?)";
    $lk = "%$search%";
    $params[] = $lk; $params[] = $lk; $params[] = $lk; $params[] = $lk; $params[] = $lk;
    $types .= "sssss";
}

if ($filter_division !== '') {
    $where[] = "u.division = ?";
    $params[] = $filter_division;
    $types .= "s";
}

if ($filter_year !== '') {
    $where[] = "u.year_of_study = ?";
    $params[] = $filter_year;
    $types .= "s";
}

if ($filter_status !== '') {
    if ($filter_status === 'Not Submitted') {
        $where[] = "i.id IS NULL";
    } else {
        $where[] = "i.status = ?";
        $params[] = $filter_status;
        $types .= "s";
    }
}

$where_clause = implode(" AND ", $where);

// Total matching count
$cnt_sql = "
    SELECT COUNT(DISTINCT u.id) as c 
    FROM users u 
    LEFT JOIN (
        SELECT id, student_id, company_name, status, internship_type
        FROM internships
        WHERE id IN (SELECT MAX(id) FROM internships GROUP BY student_id)
    ) i ON i.student_id = u.id
    WHERE $where_clause
";
$cnt_stmt = $conn->prepare($cnt_sql);
if ($types !== '') {
    $cnt_stmt->bind_param($types, ...$params);
}
$cnt_stmt->execute();
$filtered_count = (int)($cnt_stmt->get_result()->fetch_assoc()['c'] ?? 0);

// Pagination
$page = max(1, (int)($_GET['p'] ?? 1));
$per_page = 24;
$total_pages = max(1, ceil($filtered_count / $per_page));
$offset = ($page - 1) * $per_page;

$list_sql = "
    SELECT u.id, u.full_name, u.email, u.enrollment_no, u.roll_no, u.division, u.department, u.year_of_study,
           i.id as internship_id, i.company_name, i.status as internship_status, i.internship_type,
           (SELECT COUNT(*) FROM projects WHERE student_id = u.id) as project_count,
           (SELECT COUNT(*) FROM student_skills WHERE student_id = u.id) as skill_count
    FROM users u 
    LEFT JOIN (
        SELECT id, student_id, company_name, status, internship_type
        FROM internships
        WHERE id IN (SELECT MAX(id) FROM internships GROUP BY student_id)
    ) i ON i.student_id = u.id
    WHERE $where_clause
    ORDER BY u.id DESC
    LIMIT ?, ?
";
$list_stmt = $conn->prepare($list_sql);
$limit_types = $types . "ii";
$limit_params = array_merge($params, [$offset, $per_page]);
$list_stmt->bind_param($limit_types, ...$limit_params);
$list_stmt->execute();
$students = $list_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Directory — InternHub Admin</title>
    <?php include __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
        .sd-container {
            padding: 24px 32px 48px;
            max-width: 1540px;
            margin: 0 auto;
        }
        .sd-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 22px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .sd-title {
            font-size: 1.6rem;
            font-weight: 800;
            color: #1e1b4b;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .sd-subtitle {
            font-size: 0.86rem;
            color: #64748b;
            margin-top: 4px;
        }

        /* Stats Strip */
        .sd-stats-strip {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 22px;
        }
        .sd-stat-card {
            background: #ffffff;
            border-radius: 14px;
            padding: 18px 20px;
            border: 1px solid #eef1f6;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.02);
        }
        .sd-stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
        }
        .sd-stat-val {
            font-size: 1.5rem;
            font-weight: 800;
            color: #1e1b4b;
            line-height: 1.1;
        }
        .sd-stat-lbl {
            font-size: 0.76rem;
            font-weight: 600;
            color: #64748b;
            margin-top: 2px;
        }

        /* Filter Toolbar */
        .sd-toolbar {
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
        .sd-search-wrap {
            position: relative;
            flex: 1;
            min-width: 280px;
        }
        .sd-search-input {
            width: 100%;
            padding: 10px 14px 10px 38px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            font-size: 0.86rem;
            font-family: inherit;
            outline: none;
            transition: border-color 0.2s;
        }
        .sd-search-input:focus { border-color: #7c3aed; }
        .sd-search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 0.9rem;
        }
        .sd-filters {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .sd-select {
            padding: 9px 14px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            font-size: 0.84rem;
            font-family: inherit;
            color: #334155;
            outline: none;
            background: #ffffff;
        }

        /* Student Card Grid */
        .student-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }
        .student-card {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid #eef1f6;
            padding: 22px;
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.03);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            position: relative;
        }
        .student-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 26px rgba(58, 16, 79, 0.08);
        }
        .sc-head {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }
        .sc-avatar {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: #eddff7;
            color: #3a104f;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            font-weight: 800;
            flex-shrink: 0;
        }
        .sc-name {
            font-size: 1rem;
            font-weight: 700;
            color: #1e1b4b;
            line-height: 1.2;
            margin-bottom: 2px;
        }
        .sc-prn {
            font-size: 0.74rem;
            color: #64748b;
        }
        .sc-div-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 700;
            background: #f1f5f9;
            color: #334155;
            margin-top: 4px;
        }

        /* Internship Chip in Card */
        .sc-internship-chip {
            background: #f8fafc;
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 16px;
            border: 1px solid #f1f5f9;
        }
        .sc-ic-label {
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #94a3b8;
            margin-bottom: 4px;
        }
        .sc-ic-title {
            font-size: 0.86rem;
            font-weight: 700;
            color: #1e1b4b;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .sc-status-pill {
            font-size: 0.7rem;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 6px;
        }
        .pill-app { background: #d1fae5; color: #047857; }
        .pill-pend { background: #fef3c7; color: #b45309; }
        .pill-rej { background: #ffe4e6; color: #be123c; }
        .pill-none { background: #f1f5f9; color: #64748b; }

        /* Meta metrics */
        .sc-meta-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.78rem;
            color: #64748b;
            margin-top: auto;
            padding-top: 14px;
            border-top: 1px solid #f8fafc;
        }
        .sc-btn-portfolio {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            font-weight: 700;
            color: #7c3aed;
            background: #f5f3ff;
            padding: 6px 12px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .sc-btn-portfolio:hover {
            background: #eddff7;
            color: #3a104f;
        }

        /* 360° Portfolio Modal Styles */
        .portfolio-modal-box {
            max-width: 820px;
            border-radius: 20px;
        }
        .port-head-banner {
            background: linear-gradient(135deg, #3a104f 0%, #581c87 100%);
            color: #ffffff;
            border-radius: 16px 16px 0 0;
            padding: 24px 28px;
            position: relative;
        }
        .port-section-title {
            font-size: 0.92rem;
            font-weight: 700;
            color: #1e1b4b;
            margin: 18px 0 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
    </style>
</head>
<body>

    <?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

    <div class="sd-container">
        
        <!-- Header -->
        <div class="sd-header">
            <div>
                <h1 class="sd-title"><i class="fa-solid fa-id-card" style="color: #7c3aed;"></i> Student Directory</h1>
                <div class="sd-subtitle">University-wide student discovery, verified credentials, and 360° portfolio oversight.</div>
            </div>
            <div style="display: flex; gap: 10px;">
                <a href="export_students.php" class="btn btn-outline-secondary" style="border-radius: 12px; font-weight: 600; font-size: 0.86rem; padding: 10px 18px;">
                    <i class="fa-solid fa-file-export"></i> Export CSV
                </a>
            </div>
        </div>

        <!-- Stats Strip -->
        <div class="sd-stats-strip">
            <div class="sd-stat-card">
                <div class="sd-stat-icon" style="background: #eddff7; color: #6b21a8;">
                    <i class="fa-solid fa-user-graduate"></i>
                </div>
                <div>
                    <div class="sd-stat-val"><?php echo number_format($total_students); ?></div>
                    <div class="sd-stat-lbl">Total Enrolled Students</div>
                </div>
            </div>
            <div class="sd-stat-card">
                <div class="sd-stat-icon" style="background: #d1fae5; color: #047857;">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div>
                    <div class="sd-stat-val"><?php echo number_format($placed_count); ?></div>
                    <div class="sd-stat-lbl">Approved Placements</div>
                </div>
            </div>
            <div class="sd-stat-card">
                <div class="sd-stat-icon" style="background: #fef3c7; color: #b45309;">
                    <i class="fa-solid fa-clock"></i>
                </div>
                <div>
                    <div class="sd-stat-val"><?php echo number_format($pending_count); ?></div>
                    <div class="sd-stat-lbl">Pending Review</div>
                </div>
            </div>
            <div class="sd-stat-card">
                <div class="sd-stat-icon" style="background: #f1f5f9; color: #64748b;">
                    <i class="fa-solid fa-hourglass-start"></i>
                </div>
                <div>
                    <div class="sd-stat-val"><?php echo number_format($not_submitted); ?></div>
                    <div class="sd-stat-lbl">Not Yet Submitted</div>
                </div>
            </div>
        </div>

        <!-- Filter Toolbar -->
        <form method="GET" action="student_directory.php" class="sd-toolbar">
            <div class="sd-search-wrap">
                <i class="fa-solid fa-magnifying-glass sd-search-icon"></i>
                <input type="text" name="search" class="sd-search-input" placeholder="Search by student name, PRN, email, or company..." value="<?php echo h($search); ?>">
            </div>
            <div class="sd-filters">
                <select name="division" class="sd-select">
                    <option value="">All Divisions</option>
                    <?php foreach ($divisions as $d): ?>
                        <option value="<?php echo h($d); ?>" <?php echo $filter_division === $d ? 'selected' : ''; ?>><?php echo h($d); ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="year" class="sd-select">
                    <option value="">All Years</option>
                    <option value="SY" <?php echo $filter_year === 'SY' ? 'selected' : ''; ?>>Second Year (SY)</option>
                    <option value="TY" <?php echo $filter_year === 'TY' ? 'selected' : ''; ?>>Third Year (TY)</option>
                </select>

                <select name="status" class="sd-select">
                    <option value="">All Placement Statuses</option>
                    <option value="Approved" <?php echo $filter_status === 'Approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="Pending" <?php echo $filter_status === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="Rejected" <?php echo $filter_status === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                    <option value="Not Submitted" <?php echo $filter_status === 'Not Submitted' ? 'selected' : ''; ?>>Not Submitted</option>
                </select>

                <button type="submit" class="btn btn-sm btn-dark" style="border-radius: 9px; padding: 9px 16px; font-weight: 600;">Apply</button>
                <?php if ($search || $filter_division || $filter_year || $filter_status): ?>
                    <a href="student_directory.php" class="btn btn-sm btn-light" style="border-radius: 9px; padding: 9px 14px; color: #64748b;">Reset</a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Students Grid -->
        <?php if (!empty($students)): ?>
            <div class="student-grid">
                <?php foreach ($students as $s): ?>
                    <?php 
                        $init = strtoupper(substr($s['full_name'] ?: 'S', 0, 1));
                        $hasIn = !empty($s['internship_id']);
                        $inSt = $s['internship_status'] ?: 'Not Submitted';
                        $pillCls = $inSt === 'Approved' ? 'pill-app' : ($inSt === 'Pending' ? 'pill-pend' : ($inSt === 'Rejected' ? 'pill-rej' : 'pill-none'));
                    ?>
                    <div class="student-card">
                        <div class="sc-head">
                            <div class="sc-avatar"><?php echo $init; ?></div>
                            <div style="flex: 1; min-width: 0;">
                                <div class="sc-name" title="<?php echo h($s['full_name']); ?>"><?php echo h($s['full_name']); ?></div>
                                <div class="sc-prn"><?php echo h($s['enrollment_no'] ?: $s['roll_no'] ?: 'PRN N/A'); ?></div>
                                <div class="sc-div-badge"><?php echo h($s['division'] ?: 'No Division'); ?></div>
                            </div>
                        </div>

                        <div class="sc-internship-chip">
                            <div class="sc-ic-label">Internship Record</div>
                            <div class="sc-ic-title">
                                <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 170px;">
                                    <?php echo h($s['company_name'] ?: 'No Placement Registered'); ?>
                                </span>
                                <span class="sc-status-pill <?php echo $pillCls; ?>"><?php echo h($inSt); ?></span>
                            </div>
                        </div>

                        <div class="sc-meta-row">
                            <div style="display: flex; gap: 10px; font-size: 0.74rem;">
                                <span><i class="fa-solid fa-code" style="color: #7c3aed;"></i> <?php echo (int)$s['project_count']; ?> Projects</span>
                                <span><i class="fa-solid fa-award" style="color: #059669;"></i> <?php echo (int)$s['skill_count']; ?> Skills</span>
                            </div>
                            <button type="button" class="sc-btn-portfolio" onclick="loadStudent360(<?php echo $s['id']; ?>)">
                                <span>360° Profile</span> <i class="fa-solid fa-chevron-right" style="font-size: 0.7rem;"></i>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <div style="margin-top: 28px; display: flex; align-items: center; justify-content: space-between; background: #ffffff; padding: 16px 20px; border-radius: 14px; border: 1px solid #eef1f6;">
                <div style="font-size: 0.84rem; color: #64748b;">
                    Showing <strong><?php echo min($filtered_count, $offset + 1); ?></strong> to 
                    <strong><?php echo min($filtered_count, $offset + count($students)); ?></strong> of 
                    <strong><?php echo number_format($filtered_count); ?></strong> students
                </div>
                <?php if ($total_pages > 1): ?>
                    <div style="display: flex; gap: 4px;">
                        <?php if ($page > 1): ?>
                            <a href="?search=<?php echo urlencode($search); ?>&division=<?php echo urlencode($filter_division); ?>&year=<?php echo urlencode($filter_year); ?>&status=<?php echo urlencode($filter_status); ?>&p=<?php echo $page - 1; ?>" class="btn btn-sm btn-light">&laquo; Prev</a>
                        <?php endif; ?>
                        <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                            <a href="?search=<?php echo urlencode($search); ?>&division=<?php echo urlencode($filter_division); ?>&year=<?php echo urlencode($filter_year); ?>&status=<?php echo urlencode($filter_status); ?>&p=<?php echo $p; ?>" class="btn btn-sm <?php echo $p === $page ? 'btn-dark' : 'btn-light'; ?>"><?php echo $p; ?></a>
                        <?php endfor; ?>
                        <?php if ($page < $total_pages): ?>
                            <a href="?search=<?php echo urlencode($search); ?>&division=<?php echo urlencode($filter_division); ?>&year=<?php echo urlencode($filter_year); ?>&status=<?php echo urlencode($filter_status); ?>&p=<?php echo $page + 1; ?>" class="btn btn-sm btn-light">Next &raquo;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div style="background: #ffffff; border-radius: 18px; padding: 48px; text-align: center; border: 1px solid #eef1f6;">
                <i class="fa-solid fa-user-slash" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 14px;"></i>
                <h3 style="font-size: 1.1rem; color: #1e1b4b; font-weight: 700;">No Students Found</h3>
                <p style="font-size: 0.86rem; color: #64748b; margin-top: 4px;">No student records match the active filters or search terms.</p>
            </div>
        <?php endif; ?>

    </div>

    <!-- MODAL: 360° STUDENT PORTFOLIO -->
    <div class="custom-modal" id="student360Modal" style="display: none;">
        <div class="modal-box portfolio-modal-box">
            <div class="port-head-banner">
                <button type="button" class="modal-close" style="color: #ffffff; position: absolute; right: 20px; top: 18px;" onclick="closeModal('student360Modal')">&times;</button>
                <div style="display: flex; align-items: center; gap: 16px;">
                    <div id="mAvatar" style="width: 56px; height: 56px; border-radius: 16px; background: rgba(255,255,255,0.2); backdrop-filter: blur(8px); display: flex; align-items: center; justify-content: center; font-size: 1.4rem; font-weight: 800;">S</div>
                    <div>
                        <div id="mFullName" style="font-size: 1.35rem; font-weight: 800; line-height: 1.2;">Student Name</div>
                        <div id="mSubtitle" style="font-size: 0.84rem; opacity: 0.9; margin-top: 2px;">PRN • Division • Department</div>
                    </div>
                </div>
            </div>
            
            <div class="modal-body-scroll" style="padding: 24px 28px;">
                <!-- Academic & Contact Row -->
                <div class="row" style="background: #f8fafc; border-radius: 14px; padding: 14px; margin: 0 0 16px;">
                    <div class="col-md-4">
                        <div style="font-size: 0.72rem; color: #94a3b8; font-weight: 700;">EMAIL</div>
                        <div id="mEmail" style="font-size: 0.84rem; font-weight: 600; color: #1e1b4b;">-</div>
                    </div>
                    <div class="col-md-4">
                        <div style="font-size: 0.72rem; color: #94a3b8; font-weight: 700;">PHONE</div>
                        <div id="mPhone" style="font-size: 0.84rem; font-weight: 600; color: #1e1b4b;">-</div>
                    </div>
                    <div class="col-md-4">
                        <div style="font-size: 0.72rem; color: #94a3b8; font-weight: 700;">YEAR & SEMESTER</div>
                        <div id="mSem" style="font-size: 0.84rem; font-weight: 600; color: #1e1b4b;">-</div>
                    </div>
                </div>

                <!-- Internship Overview -->
                <div class="port-section-title"><i class="fa-solid fa-briefcase" style="color: #7c3aed;"></i> Internship Placement</div>
                <div id="mInternshipBox" style="background: #fdfaf6; border: 1px solid #fed7aa; border-radius: 14px; padding: 16px;">
                    <!-- Populated via JS -->
                </div>

                <!-- Technical Skills -->
                <div class="port-section-title"><i class="fa-solid fa-award" style="color: #059669;"></i> Technical Skills</div>
                <div id="mSkillsBox" style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <!-- Populated via JS -->
                </div>

                <!-- Projects -->
                <div class="port-section-title"><i class="fa-solid fa-code" style="color: #2563eb;"></i> Featured Projects</div>
                <div id="mProjectsBox" style="display: flex; flex-direction: column; gap: 10px;">
                    <!-- Populated via JS -->
                </div>

                <!-- Mentor Evaluation Marks -->
                <div class="port-section-title"><i class="fa-solid fa-clipboard-check" style="color: #d97706;"></i> Faculty Evaluation Marks</div>
                <div id="mEvalBox" style="background: #f8fafc; border-radius: 14px; padding: 14px;">
                    <!-- Populated via JS -->
                </div>
            </div>

            <div class="modal-actions" style="padding: 16px 28px;">
                <button type="button" class="btn btn-light" onclick="closeModal('student360Modal')">Close</button>
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

    function loadStudent360(id) {
        fetch(`student_directory.php?ajax=student_details&id=${id}`)
            .then(res => res.json())
            .then(data => {
                if (!data.ok) {
                    alert('Unable to load student profile.');
                    return;
                }
                const st = data.student;
                const in_rec = data.internship;
                const sk = data.skills;
                const pr = data.projects;
                const ev = data.evaluation;

                document.getElementById('mAvatar').textContent = (st.full_name || 'S').charAt(0).toUpperCase();
                document.getElementById('mFullName').textContent = st.full_name || 'Student';
                document.getElementById('mSubtitle').textContent = `PRN: ${st.enrollment_no || st.roll_no || 'N/A'} • ${st.division || 'No Div'} • ${st.department || 'IT'}`;
                document.getElementById('mEmail').textContent = st.email || '-';
                document.getElementById('mPhone').textContent = st.phone || 'Not recorded';
                document.getElementById('mSem').textContent = `${st.year_of_study || 'SY'} ${st.semester ? '• Sem ' + st.semester : ''}`;

                // Internship Box
                const ibox = document.getElementById('mInternshipBox');
                if (in_rec) {
                    ibox.innerHTML = `
                        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                            <div>
                                <div style="font-size: 1.05rem; font-weight: 700; color: #1e1b4b;">${in_rec.company_name || 'Organization'}</div>
                                <div style="font-size: 0.8rem; color: #64748b; margin-top: 2px;">Type: ${in_rec.internship_type || 'Industrial'} • Mentor: ${in_rec.mentor_name || 'Assigned'}</div>
                                <div style="font-size: 0.76rem; color: #94a3b8; margin-top: 4px;">Duration: ${in_rec.start_date || '-'} to ${in_rec.end_date || '-'}</div>
                            </div>
                            <span class="sc-status-pill ${in_rec.status === 'Approved' ? 'pill-app' : (in_rec.status === 'Pending' ? 'pill-pend' : 'pill-rej')}">
                                ${in_rec.status || 'Pending'}
                            </span>
                        </div>
                    `;
                } else {
                    ibox.innerHTML = `<div style="font-size: 0.84rem; color: #94a3b8; text-align: center; padding: 10px;">No internship submitted for this student.</div>`;
                }

                // Skills Box
                const sbox = document.getElementById('mSkillsBox');
                if (sk && sk.length > 0) {
                    sbox.innerHTML = sk.map(s => `
                        <span style="background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; padding: 4px 10px; border-radius: 8px; font-size: 0.78rem; font-weight: 600;">
                            ${s.name} ${s.proficiency_level ? '(' + s.proficiency_level + ')' : ''}
                        </span>
                    `).join('');
                } else {
                    sbox.innerHTML = `<span style="font-size: 0.82rem; color: #94a3b8;">No skills published yet.</span>`;
                }

                // Projects Box
                const pbox = document.getElementById('mProjectsBox');
                if (pr && pr.length > 0) {
                    pbox.innerHTML = pr.map(p => `
                        <div style="background: #ffffff; border: 1px solid #eef1f6; border-radius: 12px; padding: 12px 14px;">
                            <div style="font-weight: 700; font-size: 0.9rem; color: #1e1b4b;">${p.title}</div>
                            <div style="font-size: 0.8rem; color: #64748b; margin: 3px 0 6px;">${p.description || 'No description provided.'}</div>
                            <div style="display: flex; gap: 10px; font-size: 0.76rem;">
                                ${p.github_url ? `<a href="${p.github_url}" target="_blank" style="color: #7c3aed; font-weight: 600;"><i class="fa-brands fa-github"></i> Repository</a>` : ''}
                                ${p.live_url ? `<a href="${p.live_url}" target="_blank" style="color: #059669; font-weight: 600;"><i class="fa-solid fa-arrow-up-right-from-square"></i> Live Demo</a>` : ''}
                            </div>
                        </div>
                    `).join('');
                } else {
                    pbox.innerHTML = `<div style="font-size: 0.82rem; color: #94a3b8; padding: 6px 0;">No projects added yet.</div>`;
                }

                // Evaluations Box
                const ebox = document.getElementById('mEvalBox');
                if (ev) {
                    ebox.innerHTML = `
                        <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; text-align: center;">
                            <div style="background: #ffffff; border-radius: 10px; padding: 8px;">
                                <div style="font-size: 0.68rem; color: #94a3b8; font-weight: 700;">TA1</div>
                                <div style="font-size: 1.1rem; font-weight: 800; color: #1e1b4b;">${ev.ta1_marks ?? '-'}</div>
                            </div>
                            <div style="background: #ffffff; border-radius: 10px; padding: 8px;">
                                <div style="font-size: 0.68rem; color: #94a3b8; font-weight: 700;">TA2</div>
                                <div style="font-size: 1.1rem; font-weight: 800; color: #1e1b4b;">${ev.ta2_marks ?? '-'}</div>
                            </div>
                            <div style="background: #ffffff; border-radius: 10px; padding: 8px;">
                                <div style="font-size: 0.68rem; color: #94a3b8; font-weight: 700;">ATTENDANCE</div>
                                <div style="font-size: 1.1rem; font-weight: 800; color: #1e1b4b;">${ev.attendance_marks ?? '-'}</div>
                            </div>
                            <div style="background: #ffffff; border-radius: 10px; padding: 8px;">
                                <div style="font-size: 0.68rem; color: #94a3b8; font-weight: 700;">FINAL</div>
                                <div style="font-size: 1.1rem; font-weight: 800; color: #1e1b4b;">${ev.final_evaluation_marks ?? '-'}</div>
                            </div>
                            <div style="background: #eddff7; border-radius: 10px; padding: 8px;">
                                <div style="font-size: 0.68rem; color: #6b21a8; font-weight: 700;">TOTAL</div>
                                <div style="font-size: 1.1rem; font-weight: 800; color: #3a104f;">${ev.total_marks ?? '-'}</div>
                            </div>
                        </div>
                    `;
                } else {
                    ebox.innerHTML = `<div style="font-size: 0.82rem; color: #94a3b8; text-align: center; padding: 6px;">Evaluations have not been conducted yet.</div>`;
                }

                openModal('student360Modal');
            })
            .catch(err => {
                console.error(err);
                alert('Error loading student profile.');
            });
    }

    document.querySelectorAll('.custom-modal').forEach(m => {
        m.addEventListener('click', function(e) {
            if (e.target === this) this.style.display = 'none';
        });
    });
    </script>
</body>
</html>
