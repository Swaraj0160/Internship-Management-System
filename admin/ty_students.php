<?php

session_start();
include("../config.php");

/* 
=========================================================
   SAFE DEPLOYMENT / MIGRATION MECHANISM FOR AI DOMAINS
=========================================================
*/
$checkTable = mysqli_query($conn, "SHOW TABLES LIKE 'domain_classifications'");
if (mysqli_num_rows($checkTable) === 0) {
    mysqli_query($conn, "
        CREATE TABLE domain_classifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            enrollment_no VARCHAR(100) NOT NULL UNIQUE,
            assigned_domain VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");

    $jsonPath = __DIR__ . '/../domain_mapping.json';
    if (file_exists($jsonPath)) {
        $jsonContent = file_get_contents($jsonPath);
        $mapping = json_decode($jsonContent, true);
        if (is_array($mapping)) {
            $stmt = $conn->prepare("INSERT IGNORE INTO domain_classifications (enrollment_no, assigned_domain) VALUES (?, ?)");
            foreach ($mapping as $enroll => $domain) {
                $stmt->bind_param("ss", $enroll, $domain);
                $stmt->execute();
            }
            $stmt->close();
        }
    } else {
        die("
            <div style='font-family:sans-serif; max-width:650px; margin:50px auto; padding:25px; border:2px solid #dc2626; border-radius:10px; background:#fef2f2; color:#991b1b;'>
                <h2 style='margin-top:0;'>Deployment Error: Classification Data Missing</h2>
                <p>The <strong>domain_classifications</strong> database table does not exist on this server, and the initial AI data source (<code>domain_mapping.json</code>) could not be found to perform the import.</p>
                <p><strong>How to fix this instantly on Hostinger:</strong></p>
                <ol style='line-height:1.6;'>
                    <li>Upload <code>domain_mapping.json</code> from your local project to the root folder of Hostinger.</li>
                    <li>Refresh this page. The system will automatically create the database table and securely import all 1,300+ document classifications.</li>
                    <li>Once imported, you may safely delete the JSON file from the server.</li>
                </ol>
                <p style='font-size:0.9em; margin-bottom:0;'><em>This safety mechanism ensures domain classifications are properly integrated into the production database without requiring manual SQL queries.</em></p>
            </div>
        ");
    }
}


if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    header("Location: ../login.php");
    exit();
}

$search = trim($_GET['search'] ?? '');
$specialization = trim($_GET['specialization'] ?? '');
$class_division = trim($_GET['class_division'] ?? '');
$batch = trim($_GET['batch'] ?? '');
$academic_year = trim($_GET['academic_year'] ?? '');
$semester = trim($_GET['semester'] ?? '');
$marks_range = trim($_GET['marks_range'] ?? '');
$min_total = trim($_GET['min_total'] ?? '');
$max_total = trim($_GET['max_total'] ?? '');
$performance = trim($_GET['performance'] ?? '');
$internship_type = trim($_GET['internship_type'] ?? '');
$company_name = trim($_GET['company_name'] ?? '');
$internship_status = trim($_GET['internship_status'] ?? '');

/* Dynamic, DB-sourced filter options — never hardcoded */
$academic_year_options = [];
$ay_result = mysqli_query($conn,
    "SELECT DISTINCT academic_year
     FROM users
     WHERE role='student' AND year_of_study='TY' AND academic_year IS NOT NULL AND academic_year <> ''
     ORDER BY academic_year DESC");
while($row = mysqli_fetch_assoc($ay_result))
{
    $academic_year_options[] = $row['academic_year'];
}

$semester_options = [];
$sem_result = mysqli_query($conn,
    "SELECT DISTINCT semester
     FROM users
     WHERE role='student' AND year_of_study='TY' AND semester IS NOT NULL AND semester <> ''
     ORDER BY semester ASC");
while($row = mysqli_fetch_assoc($sem_result))
{
    $semester_options[] = $row['semester'];
}

$specialization_options = [];
$spec_result = mysqli_query($conn,
    "SELECT DISTINCT specialization
     FROM users
     WHERE role='student' AND year_of_study='TY' AND specialization IS NOT NULL AND specialization <> ''
     ORDER BY specialization ASC");
while($row = mysqli_fetch_assoc($spec_result))
{
    $specialization_options[] = $row['specialization'];
}

$class_division_options = [];
$class_result = mysqli_query($conn,
    "SELECT DISTINCT class_division
     FROM users
     WHERE role='student' AND year_of_study='TY' AND class_division IS NOT NULL AND class_division <> ''
     ORDER BY
        CASE SUBSTRING_INDEX(SUBSTRING_INDEX(class_division, '-', 2), '-', -1)
            WHEN 'CORE' THEN 1
            WHEN 'CC' THEN 2
            WHEN 'CSF' THEN 3
            WHEN 'AIEC' THEN 4
            WHEN 'BDCE' THEN 5
            WHEN 'AIA' THEN 6
            WHEN 'DA' THEN 7
            WHEN 'SMAD' THEN 8
            ELSE 9
        END ASC,
        CAST(SUBSTRING_INDEX(class_division, '-', -1) AS UNSIGNED) ASC");
while($row = mysqli_fetch_assoc($class_result))
{
    $class_division_options[] = $row['class_division'];
}

$batch_options = [];
$batch_result = mysqli_query($conn,
    "SELECT DISTINCT batch
     FROM users
     WHERE role='student' AND year_of_study='TY' AND batch IS NOT NULL AND batch <> ''
     ORDER BY batch ASC");
while($row = mysqli_fetch_assoc($batch_result))
{
    $batch_options[] = $row['batch'];
}

/* Dynamic internship filter options — never hardcoded */
$internship_type_options = [];
$type_result = mysqli_query($conn,
    "SELECT DISTINCT internship_type FROM internships
     WHERE internship_type IS NOT NULL AND TRIM(internship_type) <> ''
     ORDER BY internship_type ASC");
while($row = mysqli_fetch_assoc($type_result))
{
    $internship_type_options[] = $row['internship_type'];
}

$company_options = [];
$company_result = mysqli_query($conn,
    "SELECT DISTINCT company_name FROM internships
     WHERE company_name IS NOT NULL AND TRIM(company_name) <> ''
     ORDER BY company_name ASC");
while($row = mysqli_fetch_assoc($company_result))
{
    $company_options[] = $row['company_name'];
}

$internship_status_options = [];
$status_result = mysqli_query($conn,
    "SELECT DISTINCT status FROM internships
     WHERE status IS NOT NULL AND TRIM(status) <> ''
     ORDER BY status ASC");
while($row = mysqli_fetch_assoc($status_result))
{
    $internship_status_options[] = $row['status'];
}

$where = ["users.role='student'", "users.year_of_study='TY'"];
$params = [];
$types = "";

if($search !== '')
{
    $where[] = "(users.enrollment_no LIKE ? OR users.full_name LIKE ? OR users.ty_roll_no LIKE ?)";
    $like = "%" . $search . "%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}

if($academic_year !== '')
{
    $where[] = "users.academic_year=?";
    $params[] = $academic_year;
    $types .= "s";
}

if($semester !== '')
{
    $where[] = "users.semester=?";
    $params[] = $semester;
    $types .= "s";
}

if($specialization !== '')
{
    $where[] = "users.specialization=?";
    $params[] = $specialization;
    $types .= "s";
}

if($class_division !== '')
{
    $where[] = "users.class_division=?";
    $params[] = $class_division;
    $types .= "s";
}

if($batch !== '')
{
    $where[] = "users.batch=?";
    $params[] = $batch;
    $types .= "s";
}

/* Additional evaluation and internship filters */
$totalExpression = "((COALESCE(student_evaluation.weekly_total,0) + COALESCE(student_evaluation.mentor_evaluation,0) + COALESCE(student_evaluation.final_evaluation,0)) / 2)";

$rangeMap = [
    '0-10' => [$totalExpression . " >= 0", $totalExpression . " <= 10"],
    '10-20' => [$totalExpression . " > 10", $totalExpression . " <= 20"],
    '20-30' => [$totalExpression . " > 20", $totalExpression . " <= 30"],
    '30-40' => [$totalExpression . " > 30", $totalExpression . " <= 40"],
    '40-50' => [$totalExpression . " > 40", $totalExpression . " <= 50"],
    '40plus' => [$totalExpression . " >= 40", null],
    'below30' => [$totalExpression . " < 30", null]
];

if(isset($rangeMap[$marks_range]))
{
    foreach($rangeMap[$marks_range] as $condition)
    {
        if($condition !== null) $where[] = $condition;
    }
}

if($min_total !== '' && is_numeric($min_total))
{
    $where[] = $totalExpression . " >= ?";
    $params[] = (float)$min_total;
    $types .= "d";
}

if($max_total !== '' && is_numeric($max_total))
{
    $where[] = $totalExpression . " <= ?";
    $params[] = (float)$max_total;
    $types .= "d";
}

if($performance === 'evaluated')
    $where[] = "COALESCE(student_evaluation.final_evaluation,0) > 0";
elseif($performance === 'missing_final')
    $where[] = "COALESCE(student_evaluation.final_evaluation,0) = 0";
elseif($performance === 'high')
    $where[] = $totalExpression . " >= 40";
elseif($performance === 'average')
    $where[] = $totalExpression . " >= 30 AND " . $totalExpression . " < 40";
elseif($performance === 'low')
    $where[] = $totalExpression . " < 30";

if($internship_type !== '')
{
    $where[] = "latest_internship.internship_type=?";
    $params[] = $internship_type;
    $types .= "s";
}

if($company_name !== '')
{
    $where[] = "latest_internship.company_name=?";
    $params[] = $company_name;
    $types .= "s";
}

if($internship_status !== '')
{
    $where[] = "latest_internship.status=?";
    $params[] = $internship_status;
    $types .= "s";
}

$whereSql = implode(" AND ", $where);

require_once __DIR__ . '/../lib/TYEvaluationDataProvider.php';

/* Detect malformed evaluation marks for TY students */
$eval_warnings = TYEvaluationDataProvider::getWarnings($conn, $academic_year, $semester, $specialization, $class_division, $batch, $search);

/* Main data query */
$query = "
    SELECT
        users.id,
        users.enrollment_no,
        users.ty_roll_no,
        users.full_name,
        users.academic_year,
        users.semester,
        users.class_division,
        users.specialization,
        users.batch,
        student_evaluation.week1_marks,
        student_evaluation.week2_marks,
        student_evaluation.week3_marks,
        student_evaluation.week4_marks,
        student_evaluation.weekly_total,
        student_evaluation.mentor_evaluation,
        student_evaluation.final_evaluation,
        latest_internship.company_name AS internship_company,
        latest_internship.internship_type AS internship_type,
        latest_internship.status AS internship_status,
        latest_internship.internship_role AS internship_role,
        latest_internship.mini_project_title AS mini_project_title,
        domain_classifications.assigned_domain

    FROM users

    LEFT JOIN domain_classifications ON users.enrollment_no = domain_classifications.enrollment_no

    LEFT JOIN student_evaluation ON users.id = student_evaluation.student_id

    LEFT JOIN (
        SELECT i.*
        FROM internships i
        INNER JOIN (
            SELECT student_id, MAX(id) AS max_id
            FROM internships
            GROUP BY student_id
        ) latest ON i.id = latest.max_id
    ) AS latest_internship ON users.id = latest_internship.student_id

    WHERE $whereSql

    ORDER BY
        CASE SUBSTRING_INDEX(SUBSTRING_INDEX(users.class_division, '-', 2), '-', -1)
            WHEN 'CORE' THEN 1
            WHEN 'CC' THEN 2
            WHEN 'CSF' THEN 3
            WHEN 'AIEC' THEN 4
            WHEN 'BDCE' THEN 5
            WHEN 'AIA' THEN 6
            WHEN 'DA' THEN 7
            WHEN 'SMAD' THEN 8
            ELSE 9
        END ASC,
        CAST(SUBSTRING_INDEX(users.class_division, '-', -1) AS UNSIGNED) ASC,
        users.batch ASC,
        CAST(users.ty_roll_no AS UNSIGNED) ASC
";

$stmt = $conn->prepare($query);

if(count($params) > 0)
{
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

function formatMarks($value)
{
    if($value === null || $value === '')
    {
        return null;
    }

    if((float)$value == (int)$value)
    {
        return (int)$value;
    }

    return number_format((float)$value, 1);
}

$activePage = 'ty_students.php';


?>
<!DOCTYPE html>
<html>
<head>

<title>Third Year Students - Evaluations</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?php include __DIR__ . '/includes/sidebar_head.php'; ?>

<style>

body{
    background:#f5f5f5;
    margin:0;
}

.container-box{
    width:100%;
    margin:auto;
    background:white;
    padding:20px;
    border-radius:15px;
}

.page-inner{
    padding:0 24px 40px;
}

/* COMPACT FILTER SECTION */
.filter-section {
    background: #f8fafc;
    padding: 12px 16px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    margin-bottom: 20px;
}
.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 8px 12px;
    align-items: flex-end;
}
.filter-grid > div {
    display: flex;
    flex-direction: column;
}
.filter-label {
    font-size: 0.75rem;
    font-weight: 600;
    color: #475569;
    margin-bottom: 3px;
    white-space: nowrap;
}
.filter-control {
    height: 32px;
    font-size: 0.85rem;
    padding: 2px 8px;
    border: 1px solid #cbd5e1;
    border-radius: 4px;
    box-shadow: none;
}
.filter-control:focus {
    border-color: #3b82f6;
    outline: none;
}
.filter-actions {
    display: flex;
    gap: 8px;
    margin-top: 12px;
    justify-content: flex-end;
    border-top: 1px solid #e2e8f0;
    padding-top: 10px;
}
.btn-compact {
    height: 32px;
    padding: 0 16px;
    font-size: 0.85rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border-radius: 4px;
}
.search-span {
    grid-column: span 2;
}
@media (max-width: 768px) {
    .search-span { grid-column: span 1; }
    .filter-grid { grid-template-columns: repeat(auto-fill, minmax(100%, 1fr)); }
}

 .stats-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(150px,1fr));
    gap:12px;
    margin-bottom:20px;
}
.stat-card{
    background:#fff;
    border:1px solid #e5e5e5;
    border-radius:10px;
    padding:14px 16px;
}
.stat-card .label{font-size:.78rem;color:#666;margin-bottom:4px;}
.stat-card .value{font-size:1.25rem;font-weight:700;}
.breakdown-box{
    background:#f9f9f9;
    border:1px solid #e8e8e8;
    border-radius:10px;
    padding:14px 16px;
    margin-bottom:20px;
}
.breakdown-title{font-weight:700;margin-bottom:10px;}
.breakdown-item{
    display:inline-flex;
    align-items:center;
    gap:6px;
    margin:4px 12px 4px 0;
    padding:5px 9px;
    background:#fff;
    border:1px solid #e5e5e5;
    border-radius:20px;
    font-size:.85rem;
}

/* Internship domain analytics */
.domain-analytics{
    background:#fff;
    border:1px solid #e5e5e5;
    border-radius:12px;
    padding:18px;
    margin-bottom:20px;
}
.domain-analytics-head{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:14px;
}
.domain-analytics-title{
    font-size:1.05rem;
    font-weight:700;
    margin:0;
}
.domain-analytics-subtitle{
    color:#666;
    font-size:.84rem;
    margin:3px 0 0;
}
.domain-chart-wrap{
    position:relative;
    width:100%;
    height:330px;
}
.domain-table{
    margin-top:16px;
}
.domain-table td,.domain-table th{
    vertical-align:middle;
}
.domain-count{
    font-weight:700;
}
</style>


<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
</head>

<body>

<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="page-inner">
<div class="container-box">

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">

    <h3 class="fw-bold m-0">
        Third Year Students — Evaluations
    </h3>

    <div class="d-flex gap-2">

        <a href="export_ty_students_excel.php<?php
            $qs = [];
            if($search !== '') $qs[] = 'search=' . urlencode($search);
            if($academic_year !== '') $qs[] = 'academic_year=' . urlencode($academic_year);
            if($semester !== '') $qs[] = 'semester=' . urlencode($semester);
            if($specialization !== '') $qs[] = 'specialization=' . urlencode($specialization);
            if($class_division !== '') $qs[] = 'class_division=' . urlencode($class_division);
            if($batch !== '') $qs[] = 'batch=' . urlencode($batch);
            echo count($qs) > 0 ? '?' . implode('&', $qs) : '';
        ?>"
           class="btn btn-primary">
            📊 Export Excel
        </a>

        <a href="export_ty_students_csv.php<?php
            $qs = [];
            if($search !== '') $qs[] = 'search=' . urlencode($search);
            if($academic_year !== '') $qs[] = 'academic_year=' . urlencode($academic_year);
            if($semester !== '') $qs[] = 'semester=' . urlencode($semester);
            if($specialization !== '') $qs[] = 'specialization=' . urlencode($specialization);
            if($class_division !== '') $qs[] = 'class_division=' . urlencode($class_division);
            if($batch !== '') $qs[] = 'batch=' . urlencode($batch);
            echo count($qs) > 0 ? '?' . implode('&', $qs) : '';
        ?>"
           class="btn btn-info">
            📋 Export CSV
        </a>

        <a href="export_ty_students_pdf.php<?php
            $qs = [];
            if($search !== '') $qs[] = 'search=' . urlencode($search);
            if($academic_year !== '') $qs[] = 'academic_year=' . urlencode($academic_year);
            if($semester !== '') $qs[] = 'semester=' . urlencode($semester);
            if($specialization !== '') $qs[] = 'specialization=' . urlencode($specialization);
            if($class_division !== '') $qs[] = 'class_division=' . urlencode($class_division);
            if($batch !== '') $qs[] = 'batch=' . urlencode($batch);
            echo count($qs) > 0 ? '?' . implode('&', $qs) : '';
        ?>"
           class="btn btn-danger">
            📄 Download PDF
        </a>

    </div>

</div>

<?php if (!empty($eval_warnings)): ?>
<div class="alert alert-warning" role="alert">
    <strong>⚠ Data Quality Alert:</strong>
    <?php echo count($eval_warnings); ?> evaluation value(s) could not be read as numbers and are being shown as 0. Please correct these values directly:
    <ul class="mb-0 mt-2" style="font-size: 0.9rem;">
        <?php foreach ($eval_warnings as $w): ?>
            <li>
                <?php echo htmlspecialchars($w['student'], ENT_QUOTES, 'UTF-8'); ?>
                (<?php echo htmlspecialchars($w['enrollment_no'], ENT_QUOTES, 'UTF-8'); ?>)
                — <?php echo htmlspecialchars($w['field'], ENT_QUOTES, 'UTF-8'); ?>: <code><?php echo htmlspecialchars($w['raw_value'], ENT_QUOTES, 'UTF-8'); ?></code>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="filter-section">
<form method="GET" class="filter-form">
    <div class="filter-grid">
        <div class="search-span">
            <label class="filter-label">Search</label>
            <input type="text" name="search" class="filter-control w-100" placeholder="Name, Enrollment No, Roll No" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
        </div>

        <?php if(!empty($academic_year_options)): ?>
        <div>
            <label class="filter-label">Academic Year</label>
            <select name="academic_year" class="filter-control">
                <option value="">All Years</option>
                <?php foreach($academic_year_options as $opt): ?>
                    <option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>" <?php if($academic_year===$opt) echo "selected"; ?>><?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <?php if(!empty($semester_options)): ?>
        <div>
            <label class="filter-label">Semester</label>
            <select name="semester" class="filter-control">
                <option value="">All Sems</option>
                <?php foreach($semester_options as $opt): ?>
                    <option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>" <?php if($semester===$opt) echo "selected"; ?>><?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <div>
            <label class="filter-label">Specialization</label>
            <select name="specialization" class="filter-control">
                <option value="">All Specializations</option>
                <?php foreach($specialization_options as $opt): ?>
                    <option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>" <?php if($specialization===$opt) echo "selected"; ?>><?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="filter-label">TY Class</label>
            <select name="class_division" class="filter-control">
                <option value="">All Classes</option>
                <?php foreach($class_division_options as $opt): ?>
                    <option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>" <?php if($class_division===$opt) echo "selected"; ?>><?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="filter-label">Batch</label>
            <select name="batch" class="filter-control">
                <option value="">All Batches</option>
                <?php foreach($batch_options as $opt): ?>
                    <option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>" <?php if($batch===$opt) echo "selected"; ?>><?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="filter-label">Marks Range</label>
            <select name="marks_range" class="filter-control">
                <option value="">All Marks</option>
                <option value="0-10" <?php if($marks_range==='0-10') echo "selected"; ?>>0–10</option>
                <option value="10-20" <?php if($marks_range==='10-20') echo "selected"; ?>>10–20</option>
                <option value="20-30" <?php if($marks_range==='20-30') echo "selected"; ?>>20–30</option>
                <option value="30-40" <?php if($marks_range==='30-40') echo "selected"; ?>>30–40</option>
                <option value="40-50" <?php if($marks_range==='40-50') echo "selected"; ?>>40–50</option>
                <option value="40plus" <?php if($marks_range==='40plus') echo "selected"; ?>>40+</option>
                <option value="below30" <?php if($marks_range==='below30') echo "selected"; ?>>Below 30</option>
            </select>
        </div>

        <div>
            <label class="filter-label">Min Total</label>
            <input type="number" step="0.1" min="0" max="50" name="min_total" class="filter-control" value="<?php echo htmlspecialchars($min_total, ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. 30">
        </div>

        <div>
            <label class="filter-label">Max Total</label>
            <input type="number" step="0.1" min="0" max="50" name="max_total" class="filter-control" value="<?php echo htmlspecialchars($max_total, ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. 45">
        </div>

        <div>
            <label class="filter-label">Performance</label>
            <select name="performance" class="filter-control">
                <option value="">All</option>
                <option value="evaluated" <?php if($performance==='evaluated') echo "selected"; ?>>Evaluated</option>
                <option value="missing_final" <?php if($performance==='missing_final') echo "selected"; ?>>Missing Final</option>
                <option value="high" <?php if($performance==='high') echo "selected"; ?>>High (40+)</option>
                <option value="average" <?php if($performance==='average') echo "selected"; ?>>Avg (30–39.9)</option>
                <option value="low" <?php if($performance==='low') echo "selected"; ?>>Low (&lt;30)</option>
            </select>
        </div>

        <?php if(!empty($internship_type_options)): ?>
        <div>
            <label class="filter-label">Internship Type</label>
            <select name="internship_type" class="filter-control">
                <option value="">All Types</option>
                <?php foreach($internship_type_options as $opt): ?>
                    <option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>" <?php if($internship_type===$opt) echo "selected"; ?>><?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <?php if(!empty($company_options)): ?>
        <div>
            <label class="filter-label">Company</label>
            <select name="company_name" class="filter-control">
                <option value="">All Companies</option>
                <?php foreach($company_options as $opt): ?>
                    <option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>" <?php if($company_name===$opt) echo "selected"; ?>><?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <?php if(!empty($internship_status_options)): ?>
        <div>
            <label class="filter-label">Status</label>
            <select name="internship_status" class="filter-control">
                <option value="">All Statuses</option>
                <?php foreach($internship_status_options as $opt): ?>
                    <option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>" <?php if($internship_status===$opt) echo "selected"; ?>><?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </div>

    <div class="filter-actions">
        <a href="ty_students.php" class="btn btn-secondary btn-compact">↺ Reset</a>
        <button type="submit" class="btn btn-success btn-compact">🔍 Filter</button>
    </div>
</form>
</div>

<?php
/* Summary statistics for the currently filtered students */
$filtered_rows = [];
mysqli_data_seek($result, 0);
while($stat_row = mysqli_fetch_assoc($result))
{
    $w = ($stat_row['weekly_total'] !== null && $stat_row['weekly_total'] !== '') ? (float)$stat_row['weekly_total'] : 0;
    $m = ($stat_row['mentor_evaluation'] !== null && $stat_row['mentor_evaluation'] !== '') ? (float)$stat_row['mentor_evaluation'] : 0;
    $f = ($stat_row['final_evaluation'] !== null && $stat_row['final_evaluation'] !== '') ? (float)$stat_row['final_evaluation'] : 0;
    $stat_row['_total'] = ($w + $m + $f) / 2;
    $filtered_rows[] = $stat_row;
}

$student_count = count($filtered_rows);
$evaluated_count = 0;
$missing_final_count = 0;
$sum_total = $sum_weekly = $sum_mentor = $sum_final = 0;
$internship_student_count = 0;
$company_counts = [];
$type_counts = [];
$status_counts = [];

foreach($filtered_rows as $fr)
{
    $f = (float)($fr['final_evaluation'] ?? 0);
    if($f > 0) $evaluated_count++;
    else $missing_final_count++;

    $sum_total += $fr['_total'];
    $sum_weekly += (float)($fr['weekly_total'] ?? 0);
    $sum_mentor += (float)($fr['mentor_evaluation'] ?? 0);
    $sum_final += $f;

    $ic = trim((string)($fr['internship_company'] ?? ''));
    $it = trim((string)($fr['internship_type'] ?? ''));
    $is = trim((string)($fr['internship_status'] ?? ''));

    if($ic !== '' || $it !== '' || $is !== '') $internship_student_count++;
    if($ic !== '') $company_counts[$ic] = ($company_counts[$ic] ?? 0) + 1;
    if($it !== '') $type_counts[$it] = ($type_counts[$it] ?? 0) + 1;
    if($is !== '') $status_counts[$is] = ($status_counts[$is] ?? 0) + 1;
}

arsort($company_counts);
arsort($type_counts);
arsort($status_counts);

$avg_total = $student_count ? $sum_total / $student_count : 0;
$avg_weekly = $student_count ? $sum_weekly / $student_count : 0;
$avg_mentor = $student_count ? $sum_mentor / $student_count : 0;
$avg_final = $student_count ? $sum_final / $student_count : 0;

$highest_total = 0;
foreach($filtered_rows as $fr) $highest_total = max($highest_total, $fr['_total']);

/* Domain-wise internship analytics using the production DB table */
$domain_counts = [];
foreach($filtered_rows as $fr)
{
    $hasInternship = !empty($fr['internship_company']) || !empty($fr['internship_type']) || !empty($fr['internship_role']);
    
    if (!$hasInternship) {
        $domain = 'No Internship';
    } else {
        if (!empty($fr['assigned_domain'])) {
            $domain = $fr['assigned_domain'];
        } else {
            $domain = 'Needs Verification';
        }
    }

    if ($domain !== 'No Internship') {
        $domain_counts[$domain] = ($domain_counts[$domain] ?? 0) + 1;
    }
}
arsort($domain_counts);

$domain_chart_labels = array_keys($domain_counts);
$domain_chart_values = array_values($domain_counts);
?>

<div class="stats-grid">
    <div class="stat-card"><div class="label">Students</div><div class="value"><?php echo $student_count; ?></div></div>
    <div class="stat-card"><div class="label">Evaluated</div><div class="value"><?php echo $evaluated_count; ?></div></div>
    <div class="stat-card"><div class="label">Missing Final</div><div class="value"><?php echo $missing_final_count; ?></div></div>
    <div class="stat-card"><div class="label">Average Total</div><div class="value"><?php echo number_format($avg_total, 1); ?> / 50</div></div>
    <div class="stat-card"><div class="label">Internship Students</div><div class="value"><?php echo $internship_student_count; ?></div></div>
    <div class="stat-card"><div class="label">Highest Total</div><div class="value"><?php echo number_format($highest_total, 1); ?> / 50</div></div>
</div>

<div class="breakdown-box">
    <div class="breakdown-title">Evaluation Averages</div>
    <span class="breakdown-item">Weekly: <strong><?php echo number_format($avg_weekly, 1); ?>/40</strong></span>
    <span class="breakdown-item">Mentor: <strong><?php echo number_format($avg_mentor, 1); ?>/40</strong></span>
    <span class="breakdown-item">Final: <strong><?php echo number_format($avg_final, 1); ?>/20</strong></span>
</div>

<?php if(!empty($domain_counts)): ?>
<div class="domain-analytics">
    <div class="domain-analytics-head">
        <div>
            <h5 class="domain-analytics-title">Internship Domain Analytics</h5>
            <p class="domain-analytics-subtitle">Domain-wise distribution derived from document-based AI classification (offer letters).</p>
        </div>
    </div>

    <div class="domain-chart-wrap">
        <canvas id="internshipDomainChart"></canvas>
    </div>

    <div class="table-responsive domain-table">
        <table class="table table-sm table-bordered mb-0">
            <thead class="table-light">
                <tr>
                    <th>Internship Domain</th>
                    <th>Students</th>
                    <th>Share</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($domain_counts as $domain => $count): ?>
                <tr>
                    <td><?php echo htmlspecialchars($domain, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="domain-count"><?php echo $count; ?></td>
                    <td><?php echo number_format((($internship_student_count > 0) ? ($count / $internship_student_count) * 100 : 0), 1); ?>%</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const canvas = document.getElementById('internshipDomainChart');
    if (!canvas || typeof Chart === 'undefined') return;

    const labels = <?php echo json_encode($domain_chart_labels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    const values = <?php echo json_encode($domain_chart_values); ?>;

    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Students',
                data: values,
                borderWidth: 1,
                backgroundColor: 'rgba(79, 70, 229, 0.7)',
                borderColor: 'rgba(79, 70, 229, 1)'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return ' ' + context.raw + ' students';
                        }
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: { precision: 0 },
                    title: { display: true, text: 'Number of students' }
                },
                y: {
                    ticks: { autoSkip: false }
                }
            }
        }
    });
});
</script>
<?php endif; ?>

<?php if(!empty($type_counts) || !empty($company_counts) || !empty($status_counts)): ?>
<div class="breakdown-box">
    <div class="breakdown-title">Internship Breakdown</div>
    <?php if(!empty($type_counts)): ?>
        <div><strong>Types:</strong>
        <?php foreach($type_counts as $label => $count): ?>
            <span class="breakdown-item"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?> <strong><?php echo $count; ?></strong></span>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if(!empty($company_counts)): ?>
        <div class="mt-2"><strong>Top Companies:</strong>
        <?php $shown = 0; foreach($company_counts as $label => $count): if($shown++ >= 10) break; ?>
            <span class="breakdown-item"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?> <strong><?php echo $count; ?></strong></span>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if(!empty($status_counts)): ?>
        <div class="mt-2"><strong>Status:</strong>
        <?php foreach($status_counts as $label => $count): ?>
            <span class="breakdown-item"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?> <strong><?php echo $count; ?></strong></span>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="table-responsive">

<table class="table table-bordered table-striped table-sm">

<thead class="table-dark">

<tr>

<th>Enrollment No</th>
<th>TY Roll No</th>
<th>Student Name</th>
<th>TY Class</th>
<th>Specialization</th>
<th>Batch</th>
<th>W1</th>
<th>W2</th>
<th>W3</th>
<th>W4</th>
<th>Week Total (40)</th>
<th>Mentor (40)</th>
<th>Final (20)</th>
<th>Total (50)</th>
<th>Internship Type</th>
<th>Company</th>
<th>Internship Status</th>

</tr>

</thead>

<tbody>

<?php

foreach($filtered_rows as $row)
{

$weekly_total_val = ($row['weekly_total'] !== null && $row['weekly_total'] !== '') ? (float)$row['weekly_total'] : 0;
$mentor_val = ($row['mentor_evaluation'] !== null && $row['mentor_evaluation'] !== '') ? (float)$row['mentor_evaluation'] : 0;
$final_val = ($row['final_evaluation'] !== null && $row['final_evaluation'] !== '') ? (float)$row['final_evaluation'] : 0;
$total_val = ($weekly_total_val + $mentor_val + $final_val) / 2;

?>

<tr>

<td><?php echo htmlspecialchars($row['enrollment_no'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
<td><?php echo htmlspecialchars($row['ty_roll_no'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
<td><?php echo htmlspecialchars($row['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
<td><?php echo htmlspecialchars($row['class_division'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
<td><small><?php echo htmlspecialchars($row['specialization'] ?? '', ENT_QUOTES, 'UTF-8'); ?></small></td>
<td><?php echo htmlspecialchars($row['batch'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>

<td>
<?php
$v = formatMarks($row['week1_marks']);
echo $v !== null ? htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') : "—";
?>
</td>

<td>
<?php
$v = formatMarks($row['week2_marks']);
echo $v !== null ? htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') : "—";
?>
</td>

<td>
<?php
$v = formatMarks($row['week3_marks']);
echo $v !== null ? htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') : "—";
?>
</td>

<td>
<?php
$v = formatMarks($row['week4_marks']);
echo $v !== null ? htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') : "—";
?>
</td>

<td><?php echo htmlspecialchars(formatMarks($row['weekly_total']) !== null ? (string)formatMarks($row['weekly_total']) : "—", ENT_QUOTES, 'UTF-8'); ?></td>
<td><?php echo htmlspecialchars(formatMarks($row['mentor_evaluation']) !== null ? (string)formatMarks($row['mentor_evaluation']) : "—", ENT_QUOTES, 'UTF-8'); ?></td>
<td><?php echo htmlspecialchars(formatMarks($row['final_evaluation']) !== null ? (string)formatMarks($row['final_evaluation']) : "—", ENT_QUOTES, 'UTF-8'); ?></td>
<td><strong><?php echo htmlspecialchars(formatMarks($total_val) . " / 50", ENT_QUOTES, 'UTF-8'); ?></strong></td>
<td><?php echo htmlspecialchars($row['internship_type'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
<td><?php echo htmlspecialchars($row['internship_company'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
<td><?php echo htmlspecialchars($row['internship_status'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>

</tr>

<?php
}
?>

</tbody>

</table>

</div>

</div>
</div>

</main>
</div>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>

</body>
</html>
