<?php
/**
 * InternHub — Discover Students Directory (discover.php)
 * Premium Campus Talent Discovery: Real metrics, multi-field search,
 * advanced filters, student portfolios, project showcases, and privacy protection.
 */

session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../login.php");
    exit;
}

$student_id = (int)$_SESSION['user_id'];
$activePage = 'discover.php';

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

// -------------------------------------------------------------
// Live Real-Data Campus Metrics (No fake numbers)
// -------------------------------------------------------------
$metric_discoverable = 0;
$m1 = mysqli_query($conn, "SELECT COUNT(*) AS c FROM users u LEFT JOIN student_profiles sp ON u.id = sp.user_id WHERE u.role = 'student' AND COALESCE(sp.is_public, 1) = 1");
if ($m1) $metric_discoverable = (int)(mysqli_fetch_assoc($m1)['c'] ?? 0);

$metric_portfolios = 0;
$m2 = mysqli_query($conn, "SELECT COUNT(*) AS c FROM student_profiles WHERE is_public = 1");
if ($m2) $metric_portfolios = (int)(mysqli_fetch_assoc($m2)['c'] ?? 0);

$metric_projects = 0;
$m3 = mysqli_query($conn, "SELECT COUNT(*) AS c FROM projects");
if ($m3) $metric_projects = (int)(mysqli_fetch_assoc($m3)['c'] ?? 0);

$metric_departments = 0;
$m4 = mysqli_query($conn, "SELECT COUNT(DISTINCT department) AS c FROM users WHERE role = 'student' AND department != ''");
if ($m4) $metric_departments = (int)(mysqli_fetch_assoc($m4)['c'] ?? 0);

// -------------------------------------------------------------
// Search & Filter Parameters
// -------------------------------------------------------------
$q = trim($_GET['q'] ?? '');
$deptFilter = trim($_GET['dept'] ?? '');
$divFilter = trim($_GET['div'] ?? '');
$hasProjects = isset($_GET['projects']) && $_GET['projects'] === '1';
$hasPortfolio = isset($_GET['portfolio']) && $_GET['portfolio'] === '1';
$sortBy = trim($_GET['sort'] ?? 'active');

$validSorts = ['active', 'projects', 'name', 'newest'];
if (!in_array($sortBy, $validSorts)) $sortBy = 'active';

// Pagination Setup (24 cards per page)
$perPage = 24;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// -------------------------------------------------------------
// Build Query Clauses
// -------------------------------------------------------------
$whereClauses = ["u.role = 'student'", "COALESCE(sp.is_public, 1) = 1"];
$params = [];
$types = '';

if (!empty($q)) {
    $whereClauses[] = "(
        u.full_name LIKE ? OR 
        u.enrollment_no LIKE ? OR 
        u.specialization LIKE ? OR 
        sp.headline LIKE ? OR 
        sp.bio LIKE ? OR 
        EXISTS (
            SELECT 1 FROM student_skills ss2 
            JOIN skills sk2 ON ss2.skill_id = sk2.id 
            WHERE ss2.student_id = u.id AND sk2.name LIKE ?
        ) OR 
        EXISTS (
            SELECT 1 FROM projects pr2 
            WHERE pr2.student_id = u.id AND pr2.title LIKE ?
        )
    )";
    $likeQ = '%' . $q . '%';
    $params[] = $likeQ;
    $params[] = $likeQ;
    $params[] = $likeQ;
    $params[] = $likeQ;
    $params[] = $likeQ;
    $params[] = $likeQ;
    $params[] = $likeQ;
    $types .= 'sssssss';
}

if (!empty($deptFilter)) {
    $whereClauses[] = "u.department = ?";
    $params[] = $deptFilter;
    $types .= 's';
}

if (!empty($divFilter)) {
    $whereClauses[] = "u.division = ?";
    $params[] = $divFilter;
    $types .= 's';
}

if ($hasProjects) {
    $whereClauses[] = "(SELECT COUNT(*) FROM projects p_chk WHERE p_chk.student_id = u.id) > 0";
}

if ($hasPortfolio) {
    $whereClauses[] = "sp.is_public = 1";
}

$whereSql = implode(' AND ', $whereClauses);

// Count total matching records for pagination
$countQuery = "
    SELECT COUNT(*) AS total
    FROM users u
    LEFT JOIN student_profiles sp ON u.id = sp.user_id
    WHERE {$whereSql}
";
$cntStmt = $conn->prepare($countQuery);
if (!empty($params)) {
    $cntStmt->bind_param($types, ...$params);
}
$cntStmt->execute();
$totalRecords = (int)($cntStmt->get_result()->fetch_assoc()['total'] ?? 0);
$cntStmt->close();
$totalPages = max(1, ceil($totalRecords / $perPage));

// Determine Sorting Order
switch ($sortBy) {
    case 'projects':
        $orderSql = "projects_count DESC, u.full_name ASC";
        break;
    case 'name':
        $orderSql = "u.full_name ASC";
        break;
    case 'newest':
        $orderSql = "u.id DESC";
        break;
    case 'active':
    default:
        $orderSql = "projects_count DESC, COALESCE(sp.updated_at, u.created_at) DESC, u.id DESC";
        break;
}

// Fetch Page Records
$query = "
    SELECT u.id, u.full_name, u.department, u.division, u.enrollment_no, u.specialization, u.profile_photo,
           sp.headline, sp.bio, sp.is_public,
           (SELECT COUNT(*) FROM projects p WHERE p.student_id = u.id) AS projects_count,
           (SELECT COUNT(*) FROM achievements a WHERE a.student_id = u.id) AS achievements_count,
           (SELECT GROUP_CONCAT(sk.name SEPARATOR ', ') FROM student_skills ss JOIN skills sk ON ss.skill_id = sk.id WHERE ss.student_id = u.id) AS top_skills,
           (SELECT p.title FROM projects p WHERE p.student_id = u.id ORDER BY p.is_featured DESC, p.id DESC LIMIT 1) AS featured_project_title
    FROM users u
    LEFT JOIN student_profiles sp ON u.id = sp.user_id
    WHERE {$whereSql}
    ORDER BY {$orderSql}
    LIMIT ? OFFSET ?
";

$fetchParams = $params;
$fetchTypes = $types . 'ii';
$fetchParams[] = $perPage;
$fetchParams[] = $offset;

$stmt = $conn->prepare($query);
if (!empty($fetchParams)) {
    $stmt->bind_param($fetchTypes, ...$fetchParams);
}
$stmt->execute();
$res = $stmt->get_result();
$students = [];
while ($row = $res->fetch_assoc()) {
    $students[] = $row;
}
$stmt->close();

// Distinct Departments for filter dropdown
$deptsRes = mysqli_query($conn, "SELECT DISTINCT department FROM users WHERE role = 'student' AND department != '' ORDER BY department ASC");
$departmentsList = [];
if ($deptsRes) {
    while ($d = mysqli_fetch_row($deptsRes)) $departmentsList[] = $d[0];
}

// Top Divisions for filter dropdown
$divsRes = mysqli_query($conn, "SELECT DISTINCT division FROM users WHERE role = 'student' AND division != '' ORDER BY division ASC LIMIT 25");
$divisionsList = [];
if ($divsRes) {
    while ($dv = mysqli_fetch_row($divsRes)) $divisionsList[] = $dv[0];
}

// Helper to preserve filter query parameters across pagination
function build_filter_url($newPage, $q, $dept, $div, $proj, $port, $sort) {
    $params = ['page' => $newPage];
    if (!empty($q)) $params['q'] = $q;
    if (!empty($dept)) $params['dept'] = $dept;
    if (!empty($div)) $params['div'] = $div;
    if ($proj) $params['projects'] = '1';
    if ($port) $params['portfolio'] = '1';
    if ($sort !== 'active') $params['sort'] = $sort;
    return 'discover.php?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Discover Campus Talent — InternHub | MIT-ADT University</title>
    <?php require_once __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
    .discover-wrapper {
        padding: 0 32px 36px;
        display: flex;
        flex-direction: column;
        gap: 24px;
    }

    /* 1. Header Banner & Live Metrics */
    .discover-header-card {
        background: #ffffff;
        border-radius: 20px;
        padding: 24px 28px;
        border: 1px solid rgba(226, 232, 240, 0.85);
        box-shadow: 0 4px 18px rgba(0, 0, 0, 0.035);
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    .discover-header-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        flex-wrap: wrap;
    }

    .discover-header-left {
        max-width: 680px;
    }

    .discover-badge-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #ede9fe;
        color: #6d28d9;
        font-size: 0.72rem;
        font-weight: 700;
        padding: 3px 12px;
        border-radius: 999px;
        margin-bottom: 8px;
        letter-spacing: 0.04em;
    }

    .discover-title {
        font-size: 1.45rem;
        font-weight: 800;
        color: #1e1b4b;
        letter-spacing: -0.01em;
        margin-bottom: 4px;
    }

    .discover-sub {
        font-size: 0.88rem;
        color: #64748b;
        line-height: 1.5;
    }

    /* Live Metric Strip */
    .metrics-pills-row {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 14px;
        padding-top: 18px;
        border-top: 1px solid #f1f5f9;
    }

    .metric-stat-box {
        background: #faf8fd;
        border-radius: 14px;
        padding: 12px 16px;
        border: 1px solid rgba(226, 232, 240, 0.7);
        display: flex;
        align-items: center;
        gap: 12px;
        transition: transform 0.15s ease;
    }

    .metric-stat-box:hover {
        transform: translateY(-2px);
    }

    .metric-stat-icon {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        flex-shrink: 0;
    }

    .stat-purple { background: #ede9fe; color: #6d28d9; }
    .stat-green  { background: #dcfce7; color: #15803d; }
    .stat-amber  { background: #fef3c7; color: #b45309; }
    .stat-blue   { background: #e0f2fe; color: #0369a1; }

    .stat-meta {
        display: flex;
        flex-direction: column;
    }

    .stat-number {
        font-size: 1.15rem;
        font-weight: 800;
        color: #1e1b4b;
        line-height: 1.1;
    }

    .stat-label {
        font-size: 0.72rem;
        font-weight: 600;
        color: #64748b;
        margin-top: 2px;
    }

    /* 2. Search & Advanced Filter Section */
    .filter-panel-card {
        background: #ffffff;
        border-radius: 20px;
        padding: 20px 24px;
        border: 1px solid rgba(226, 232, 240, 0.85);
        box-shadow: 0 4px 18px rgba(0, 0, 0, 0.03);
    }

    .filter-form {
        display: flex;
        flex-direction: column;
        gap: 14px;
    }

    .filter-main-row {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }

    .search-input-wrapper {
        flex: 1;
        min-width: 280px;
        position: relative;
    }

    .search-input-field {
        width: 100%;
        padding: 10px 42px 10px 18px;
        border-radius: 999px;
        border: 1px solid #cbd5e1;
        background: #ffffff;
        font-family: inherit;
        font-size: 0.86rem;
        color: #1e293b;
        outline: none;
        transition: border 0.15s ease;
    }

    .search-input-field:focus {
        border-color: #7c3aed;
        box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
    }

    .search-field-icon {
        position: absolute;
        right: 16px;
        top: 50%;
        transform: translateY(-50%);
        color: #64748b;
        font-size: 0.9rem;
    }

    .filter-select {
        padding: 9px 16px;
        border-radius: 999px;
        border: 1px solid #cbd5e1;
        background: #ffffff;
        font-family: inherit;
        font-size: 0.82rem;
        color: #334155;
        font-weight: 600;
        outline: none;
        cursor: pointer;
    }

    .btn-apply-filter {
        padding: 9px 22px;
        border-radius: 999px;
        background: #5b21b6;
        color: #ffffff;
        font-size: 0.84rem;
        font-weight: 700;
        border: none;
        cursor: pointer;
        transition: background 0.15s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .btn-apply-filter:hover {
        background: #43185b;
    }

    .filter-sub-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding-top: 12px;
        border-top: 1px solid #f8fafc;
        flex-wrap: wrap;
    }

    .filter-checkboxes-group {
        display: flex;
        align-items: center;
        gap: 16px;
        flex-wrap: wrap;
    }

    .filter-checkbox-label {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        font-size: 0.8rem;
        font-weight: 600;
        color: #475569;
        cursor: pointer;
        user-select: none;
    }

    .filter-checkbox-label input {
        accent-color: #7c3aed;
        cursor: pointer;
    }

    .filter-status-info {
        display: flex;
        align-items: center;
        gap: 12px;
        font-size: 0.82rem;
        color: #64748b;
    }

    .btn-clear-filters {
        color: #ef4444;
        font-weight: 700;
        text-decoration: none;
        font-size: 0.8rem;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .btn-clear-filters:hover {
        text-decoration: underline;
    }

    /* 3. Students Directory Grid */
    .students-directory-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 22px;
    }

    .student-talent-card {
        background: #ffffff;
        border-radius: 20px;
        padding: 24px;
        border: 1px solid rgba(226, 232, 240, 0.85);
        box-shadow: 0 4px 18px rgba(0, 0, 0, 0.03);
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1);
        position: relative;
    }

    .student-talent-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 10px 28px rgba(91, 33, 182, 0.1);
        border-color: rgba(124, 58, 237, 0.3);
    }

    .card-top-header {
        display: flex;
        align-items: flex-start;
        gap: 14px;
        margin-bottom: 12px;
    }

    .student-avatar-box {
        width: 52px;
        height: 52px;
        border-radius: 50%;
        background: #ede9fe;
        color: #6d28d9;
        font-weight: 800;
        font-size: 1.2rem;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        flex-shrink: 0;
        border: 2px solid #ffffff;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
    }

    .student-avatar-box img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .card-title-block {
        flex: 1;
        min-width: 0;
    }

    .student-name-text {
        font-size: 1.02rem;
        font-weight: 800;
        color: #1e1b4b;
        line-height: 1.25;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .student-id-row {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-top: 3px;
        font-size: 0.74rem;
        color: #64748b;
    }

    .academic-pill {
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 0.68rem;
        font-weight: 700;
    }

    .pill-dept { background: #ede9fe; color: #6d28d9; }
    .pill-div  { background: #f1f5f9; color: #475569; }

    .student-headline-text {
        font-size: 0.82rem;
        color: #475569;
        font-weight: 600;
        line-height: 1.45;
        margin: 6px 0 14px;
        min-height: 38px;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .student-headline-text.not-set {
        color: #94a3b8;
        font-style: italic;
        font-weight: 500;
    }

    /* Skills Tags */
    .card-skills-row {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
        margin-bottom: 14px;
        min-height: 26px;
    }

    .card-skill-tag {
        padding: 3px 9px;
        border-radius: 999px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        color: #334155;
        font-size: 0.72rem;
        font-weight: 600;
    }

    /* Project & Achievement Highlight */
    .card-highlight-box {
        background: #faf8fd;
        border-radius: 12px;
        padding: 10px 12px;
        border: 1px solid #f1f5f9;
        margin-bottom: 16px;
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .highlight-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-size: 0.72rem;
        font-weight: 700;
        color: #6d28d9;
    }

    .highlight-title {
        font-size: 0.78rem;
        color: #1e1b4b;
        font-weight: 600;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* Card Footer & Action */
    .card-footer-action {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding-top: 14px;
        border-top: 1px solid #f1f5f9;
        gap: 10px;
    }

    .portfolio-status-indicator {
        font-size: 0.74rem;
        color: #10b981;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .portfolio-status-indicator.no-pub {
        color: #94a3b8;
        font-weight: 500;
    }

    .btn-card-portfolio {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 7px 16px;
        border-radius: 999px;
        background: #5b21b6;
        color: #ffffff;
        font-size: 0.8rem;
        font-weight: 700;
        text-decoration: none;
        transition: all 0.15s ease;
    }

    .btn-card-portfolio:hover {
        background: #43185b;
        transform: translateX(2px);
        color: #ffffff;
    }

    /* 4. Empty State Card */
    .empty-directory-card {
        grid-column: 1 / -1;
        background: #ffffff;
        border-radius: 20px;
        padding: 60px 30px;
        text-align: center;
        border: 1px solid #e2e8f0;
    }

    .empty-directory-icon {
        width: 72px;
        height: 72px;
        border-radius: 50%;
        background: #f5f3ff;
        color: #7c3aed;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
        margin: 0 auto 16px;
    }

    .empty-dir-title {
        font-size: 1.2rem;
        font-weight: 800;
        color: #1e1b4b;
        margin-bottom: 6px;
    }

    .empty-dir-sub {
        font-size: 0.88rem;
        color: #64748b;
        max-width: 440px;
        margin: 0 auto 20px;
    }

    /* 5. Pagination Bar */
    .pagination-bar {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        margin-top: 10px;
        flex-wrap: wrap;
    }

    .page-link-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 38px;
        height: 38px;
        padding: 0 12px;
        border-radius: 999px;
        border: 1px solid #cbd5e1;
        background: #ffffff;
        color: #475569;
        font-size: 0.84rem;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.15s ease;
    }

    .page-link-btn:hover {
        background: #ede9fe;
        color: #6d28d9;
        border-color: #c4b5fd;
    }

    .page-link-btn.active {
        background: #5b21b6;
        color: #ffffff !important;
        border-color: #5b21b6;
        box-shadow: 0 4px 10px rgba(91, 33, 182, 0.25);
    }

    .page-link-btn.disabled {
        opacity: 0.4;
        pointer-events: none;
    }

    @media (max-width: 900px) {
        .discover-wrapper { padding: 0 16px 24px; }
        .metrics-pills-row { grid-template-columns: repeat(2, 1fr); }
        .filter-main-row { flex-direction: column; align-items: stretch; }
        .filter-sub-row { flex-direction: column; align-items: stretch; gap: 14px; }
    }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="discover-wrapper">

    <!-- 1. Header Banner & Live Real-Data Metrics -->
    <div class="discover-header-card">
        <div class="discover-header-top">
            <div class="discover-header-left">
                <span class="discover-badge-pill">
                    <i class="fa-solid fa-compass"></i>
                    <span>STUDENT TALENT DIRECTORY</span>
                </span>
                <h1 class="discover-title">Discover Your Campus</h1>
                <p class="discover-sub">Explore talented students, discover inspiring projects and connect with the people building amazing things at MIT-ADT.</p>
            </div>
        </div>

        <div class="metrics-pills-row">
            <div class="metric-stat-box">
                <div class="metric-stat-icon stat-purple">
                    <i class="fa-solid fa-user-graduate"></i>
                </div>
                <div class="stat-meta">
                    <span class="stat-number"><?php echo number_format($metric_discoverable); ?></span>
                    <span class="stat-label">Discoverable Students</span>
                </div>
            </div>

            <div class="metric-stat-box">
                <div class="metric-stat-icon stat-green">
                    <i class="fa-solid fa-id-card"></i>
                </div>
                <div class="stat-meta">
                    <span class="stat-number"><?php echo number_format($metric_portfolios); ?></span>
                    <span class="stat-label">Published Portfolios</span>
                </div>
            </div>

            <div class="metric-stat-box">
                <div class="metric-stat-icon stat-amber">
                    <i class="fa-solid fa-laptop-code"></i>
                </div>
                <div class="stat-meta">
                    <span class="stat-number"><?php echo number_format($metric_projects); ?></span>
                    <span class="stat-label">Featured Projects</span>
                </div>
            </div>

            <div class="metric-stat-box">
                <div class="metric-stat-icon stat-blue">
                    <i class="fa-solid fa-building-columns"></i>
                </div>
                <div class="stat-meta">
                    <span class="stat-number"><?php echo number_format($metric_departments); ?></span>
                    <span class="stat-label">Departments</span>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. Search & Advanced Filter Card -->
    <div class="filter-panel-card">
        <form action="discover.php" method="GET" class="filter-form">
            <div class="filter-main-row">
                <div class="search-input-wrapper">
                    <input type="text" name="q" class="search-input-field" value="<?php echo h($q); ?>" placeholder="Search students, skills, projects or technologies...">
                    <i class="fa-solid fa-magnifying-glass search-field-icon"></i>
                </div>

                <select name="dept" class="filter-select" aria-label="Department Filter">
                    <option value="">All Departments</option>
                    <?php foreach ($departmentsList as $dItem): ?>
                        <option value="<?php echo h($dItem); ?>" <?php echo $deptFilter === $dItem ? 'selected' : ''; ?>><?php echo h($dItem); ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="div" class="filter-select" aria-label="Division Filter">
                    <option value="">All Divisions</option>
                    <?php foreach ($divisionsList as $dvItem): ?>
                        <option value="<?php echo h($dvItem); ?>" <?php echo $divFilter === $dvItem ? 'selected' : ''; ?>><?php echo h($dvItem); ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="sort" class="filter-select" aria-label="Sort Order">
                    <option value="active" <?php echo $sortBy === 'active' ? 'selected' : ''; ?>>Recently Active</option>
                    <option value="projects" <?php echo $sortBy === 'projects' ? 'selected' : ''; ?>>Most Projects</option>
                    <option value="name" <?php echo $sortBy === 'name' ? 'selected' : ''; ?>>Name (A-Z)</option>
                    <option value="newest" <?php echo $sortBy === 'newest' ? 'selected' : ''; ?>>Recently Joined</option>
                </select>

                <button type="submit" class="btn-apply-filter">
                    <i class="fa-solid fa-filter"></i>
                    <span>Apply</span>
                </button>
            </div>

            <div class="filter-sub-row">
                <div class="filter-checkboxes-group">
                    <label class="filter-checkbox-label">
                        <input type="checkbox" name="projects" value="1" <?php echo $hasProjects ? 'checked' : ''; ?> onchange="this.form.submit();">
                        <span>Has Published Projects</span>
                    </label>
                    <label class="filter-checkbox-label">
                        <input type="checkbox" name="portfolio" value="1" <?php echo $hasPortfolio ? 'checked' : ''; ?> onchange="this.form.submit();">
                        <span>Public Portfolio Available</span>
                    </label>
                </div>

                <div class="filter-status-info">
                    <span>Showing <strong><?php echo count($students); ?></strong> of <strong><?php echo number_format($totalRecords); ?></strong> students</span>
                    <?php if (!empty($q) || !empty($deptFilter) || !empty($divFilter) || $hasProjects || $hasPortfolio || $sortBy !== 'active'): ?>
                        <a href="discover.php" class="btn-clear-filters">
                            <i class="fa-solid fa-xmark"></i>
                            <span>Clear All</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <!-- 3. Students Directory Grid -->
    <div class="students-directory-grid">
        <?php if (!empty($students)): ?>
            <?php foreach ($students as $stu): ?>
                <?php
                $photo = trim($stu['profile_photo'] ?? '');
                $photoPath = (!empty($photo) && file_exists(__DIR__ . '/../uploads/profile_photos/' . $photo))
                    ? '../uploads/profile_photos/' . rawurlencode($photo)
                    : '';
                $initial = strtoupper(substr(trim($stu['full_name'] ?? 'S'), 0, 1));
                $headline = trim($stu['headline'] ?? '');
                $specialization = trim($stu['specialization'] ?? '');
                $projCount = (int)$stu['projects_count'];
                $achieveCount = (int)$stu['achievements_count'];
                $hasPublicPortfolio = ((int)($stu['is_public'] ?? 1) === 1);
                ?>
                <div class="student-talent-card">
                    <div>
                        <!-- Header with Avatar and Names -->
                        <div class="card-top-header">
                            <div class="student-avatar-box">
                                <?php if (!empty($photoPath)): ?>
                                    <img src="<?php echo h($photoPath); ?>" alt="<?php echo h($stu['full_name']); ?>">
                                <?php else: ?>
                                    <span><?php echo $initial; ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="card-title-block">
                                <h3 class="student-name-text" title="<?php echo h($stu['full_name']); ?>"><?php echo h($stu['full_name']); ?></h3>
                                <div class="student-id-row">
                                    <?php if (!empty($stu['department'])): ?>
                                        <span class="academic-pill pill-dept"><?php echo h($stu['department']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($stu['division'])): ?>
                                        <span class="academic-pill pill-div"><?php echo h($stu['division']); ?></span>
                                    <?php endif; ?>
                                    <span><?php echo h($stu['enrollment_no']); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Professional Headline -->
                        <?php if (!empty($headline)): ?>
                            <div class="student-headline-text"><?php echo h($headline); ?></div>
                        <?php elseif (!empty($specialization)): ?>
                            <div class="student-headline-text"><?php echo h($specialization); ?></div>
                        <?php else: ?>
                            <div class="student-headline-text not-set">Professional introduction not added yet.</div>
                        <?php endif; ?>

                        <!-- Skills Matrix Tags -->
                        <div class="card-skills-row">
                            <?php if (!empty($stu['top_skills'])): ?>
                                <?php
                                $skillsArr = array_slice(explode(',', $stu['top_skills']), 0, 4);
                                foreach ($skillsArr as $sk):
                                ?>
                                    <span class="card-skill-tag"><?php echo h(trim($sk)); ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span style="font-size:0.7rem; color:#94a3b8;">Skills in progress</span>
                            <?php endif; ?>
                        </div>

                        <!-- Highlight Box: Projects or Achievements -->
                        <?php if ($projCount > 0 && !empty($stu['featured_project_title'])): ?>
                            <div class="card-highlight-box">
                                <div class="highlight-top">
                                    <span><i class="fa-solid fa-laptop-code"></i> Featured Project</span>
                                    <span><?php echo $projCount; ?> Total</span>
                                </div>
                                <div class="highlight-title" title="<?php echo h($stu['featured_project_title']); ?>">
                                    <?php echo h($stu['featured_project_title']); ?>
                                </div>
                            </div>
                        <?php elseif ($achieveCount > 0): ?>
                            <div class="card-highlight-box">
                                <div class="highlight-top">
                                    <span><i class="fa-solid fa-trophy"></i> Achievements</span>
                                    <span><?php echo $achieveCount; ?> Recognized</span>
                                </div>
                                <div class="highlight-title">Recognized University Contributor</div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Footer Action Strip -->
                    <div class="card-footer-action">
                        <?php if ($hasPublicPortfolio): ?>
                            <span class="portfolio-status-indicator">
                                <i class="fa-solid fa-circle-check"></i>
                                <span>Verified Profile</span>
                            </span>
                        <?php else: ?>
                            <span class="portfolio-status-indicator no-pub">
                                <span>Student Profile</span>
                            </span>
                        <?php endif; ?>

                        <a href="../portfolio.php?id=<?php echo $stu['id']; ?>" target="_blank" class="btn-card-portfolio">
                            <span>View Portfolio</span>
                            <i class="fa-solid fa-arrow-right"></i>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>

        <?php else: ?>
            <div class="empty-directory-card">
                <div class="empty-directory-icon">
                    <i class="fa-solid fa-user-slash"></i>
                </div>
                <h3 class="empty-dir-title">No Students Found</h3>
                <p class="empty-dir-sub">No students match your current search terms or filter criteria. Try adjusting keywords or clearing active filters.</p>
                <a href="discover.php" class="btn-apply-filter" style="text-decoration:none;">
                    <i class="fa-solid fa-arrow-rotate-left"></i>
                    <span>Reset All Filters</span>
                </a>
            </div>
        <?php endif; ?>
    </div>

    <!-- 4. Pagination Controls -->
    <?php if ($totalPages > 1): ?>
        <nav class="pagination-bar" aria-label="Directory Pagination">
            <a href="<?php echo h(build_filter_url($page - 1, $q, $deptFilter, $divFilter, $hasProjects, $hasPortfolio, $sortBy)); ?>" 
               class="page-link-btn <?php echo ($page <= 1) ? 'disabled' : ''; ?>" aria-label="Previous Page">
                <i class="fa-solid fa-chevron-left"></i>
            </a>

            <?php
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);

            if ($startPage > 1) {
                echo '<a href="' . h(build_filter_url(1, $q, $deptFilter, $divFilter, $hasProjects, $hasPortfolio, $sortBy)) . '" class="page-link-btn">1</a>';
                if ($startPage > 2) echo '<span style="color:#94a3b8; padding:0 4px;">...</span>';
            }

            for ($pNum = $startPage; $pNum <= $endPage; $pNum++) {
                $isCur = ($pNum === $page);
                echo '<a href="' . h(build_filter_url($pNum, $q, $deptFilter, $divFilter, $hasProjects, $hasPortfolio, $sortBy)) . '" class="page-link-btn ' . ($isCur ? 'active' : '') . '">' . $pNum . '</a>';
            }

            if ($endPage < $totalPages) {
                if ($endPage < $totalPages - 1) echo '<span style="color:#94a3b8; padding:0 4px;">...</span>';
                echo '<a href="' . h(build_filter_url($totalPages, $q, $deptFilter, $divFilter, $hasProjects, $hasPortfolio, $sortBy)) . '" class="page-link-btn">' . $totalPages . '</a>';
            }
            ?>

            <a href="<?php echo h(build_filter_url($page + 1, $q, $deptFilter, $divFilter, $hasProjects, $hasPortfolio, $sortBy)); ?>" 
               class="page-link-btn <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>" aria-label="Next Page">
                <i class="fa-solid fa-chevron-right"></i>
            </a>
        </nav>
    <?php endif; ?>

</div>

</main>
</div>

<?php require_once __DIR__ . '/includes/sidebar_scripts.php'; ?>
</body>
</html>
