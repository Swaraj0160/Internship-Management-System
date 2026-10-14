<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    header("Location: ../login.php");
    exit();
}

/* -----------------------------------------------------------------
   REPLACE — sidebar propagation (batch 2) + fixes found while
   rewiring this page:

   1. SQL injection: division / search / type were all raw-
      concatenated from $_GET into 6 separate queries (4 stat-card
      counts, the type-breakdown query, and the main table query).
      All converted to prepared statements via a small local helper
      (runDivisionQuery) that binds the division param consistently.
   2. XSS: division, full_name, enrollment_no, company_name,
      internship_role, and the offer_letter filename (used both as
      link text and inside the download URL path) were all echoed
      with no escaping. All now go through htmlspecialchars().
   3. Ordering: replaced CAST(SUBSTRING(users.division, 4) AS UNSIGNED)
      (which reads "IT-DA" as substring from position 4 = "A", casting
      to 0 — same problem as the REPLACE()-based tricks elsewhere)
      with the explicit IT-DA-first / IT-SMAD-second / SY-* CASE
      ordering used everywhere else in the project.

   Stat-card numbers, filters, and table columns are otherwise
   unchanged.
     ----------------------------------------------------------------- */

$division = trim($_GET['division'] ?? '');
$search = trim($_GET['search'] ?? '');
$type = trim($_GET['type'] ?? '');
$status = trim($_GET['status'] ?? ''); // NEW — Approved / Pending / Rejected filter

function runCountQuery(mysqli $conn, string $extraSql, string $division): int
{
    $sql = "
        SELECT COUNT(*) AS total
        FROM internships
        JOIN users ON internships.student_id = users.id
        WHERE internships.id IN (
            SELECT MAX(id) FROM internships GROUP BY student_id
        )
        $extraSql
        " . ($division !== '' ? " AND users.division=?" : "") . "
    ";

    $stmt = $conn->prepare($sql);

    if($division !== '')
    {
        $stmt->bind_param("s", $division);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (int)($row['total'] ?? 0);
}

/* ===========================
   Statistics Cards
=========================== */

$total_applications = runCountQuery($conn, "", $division);
$approved_count = runCountQuery($conn, "AND internships.status='Approved'", $division);
$pending_count = runCountQuery($conn, "AND internships.status='Pending'", $division);
$rejected_count = runCountQuery($conn, "AND internships.status='Rejected'", $division);

$type_sql = "
    SELECT
        TRIM(internship_type) AS internship_type,
        COUNT(*) AS total
    FROM internships
    JOIN users ON internships.student_id = users.id
    WHERE internships.id IN (
        SELECT MAX(id) FROM internships GROUP BY student_id
    )
    AND internships.status='Approved'
    " . ($division !== '' ? " AND users.division=?" : "") . "
    GROUP BY TRIM(internship_type)
";

$type_stmt = $conn->prepare($type_sql);

if($division !== '')
{
    $type_stmt->bind_param("s", $division);
}

$type_stmt->execute();
$type_result = $type_stmt->get_result();

$type_counts = [];

while($row = $type_result->fetch_assoc())
{
    $type_counts[$row['internship_type']] = $row['total'];
}

$problem_count = $type_counts['Problem-based Internship'] ?? 0;

$training_count =
($type_counts['Training + Mini Project'] ?? 0)
+
($type_counts['Training + Mini Project Internship'] ?? 0);

$research_count = $type_counts['Research Internship'] ?? 0;

$international_count = $type_counts['International Internship / Visit'] ?? 0;

$realworld_count = $type_counts['Real-world Exposure Internship (Field Work)'] ?? 0;


/* ===========================
   Company Analytics Query
=========================== */
$company_sql = "
    SELECT 
        i.company_name, 
        COUNT(DISTINCT i.student_id) as total_students,
        GROUP_CONCAT(DISTINCT i.internship_role SEPARATOR ', ') as roles_offered,
        GROUP_CONCAT(DISTINCT dc.assigned_domain SEPARATOR ', ') as domains
        
    FROM internships i
    JOIN users u ON i.student_id = u.id
    LEFT JOIN domain_classifications dc ON u.enrollment_no = dc.enrollment_no
    WHERE i.status = 'Approved' 
      AND i.company_name IS NOT NULL 
      AND TRIM(i.company_name) != ''
    GROUP BY i.company_name
    ORDER BY total_students DESC
";
$company_result = mysqli_query($conn, $company_sql);

/* ===========================
   Main Table Query
=========================== */

$where = [];
$params = [];
$types = "";

if($division !== '')
{
    $where[] = "users.division=?";
    $params[] = $division;
    $types .= "s";
}

if($search !== '')
{
    $where[] = "(users.full_name LIKE ? OR users.enrollment_no LIKE ?)";
    $like = "%" . $search . "%";
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}

if($type !== '')
{
    $where[] = "internships.internship_type=?";
    $params[] = $type;
    $types .= "s";
}

if($status !== '' && in_array($status, ['Approved', 'Pending', 'Rejected'], true))
{
    $where[] = "internships.status=?";
    $params[] = $status;
    $types .= "s";
}

$extraWhereSql = count($where) > 0 ? " AND " . implode(" AND ", $where) : "";

$query = "
    SELECT internships.*, users.full_name, users.division, users.enrollment_no
    FROM internships
    JOIN users ON internships.student_id = users.id

    WHERE internships.id IN (
        SELECT MAX(id) FROM internships GROUP BY student_id
    )
    $extraWhereSql

    ORDER BY
        CASE users.division
            WHEN 'IT-DA'   THEN 0
            WHEN 'IT-SMAD' THEN 1
            ELSE 2
        END ASC,
        users.division ASC,
        CAST(users.roll_no AS UNSIGNED) ASC
";

$stmt = $conn->prepare($query);

if(count($params) > 0)
{
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$activePage = 'all_internships.php';

?>

<!DOCTYPE html>
<html>

<head>

<title>All Internships</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet"
href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<?php include __DIR__ . '/includes/sidebar_head.php'; ?>
<style>

*{
    font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;
}

body{
    background:radial-gradient(circle at 0% 0%, #eef2f9 0%, #f1f5f9 40%, #f1f5f9 100%);
    margin:0;
}

.page-inner{
    padding:0 20px 40px;
}

.container-box{
    width:100%;
    margin:30px auto;
    background:#fff;
    padding:36px 38px;
    border-radius:24px;
    box-shadow:0 1px 2px rgba(15,23,42,.04), 0 20px 44px -20px rgba(15,23,42,.14);
    border:1px solid #F1F5F9;
}

.page-header h2{
    font-size:2rem;
    font-weight:800;
    color:#0F172A;
    margin-bottom:6px;
    letter-spacing:-.02em;
}

.page-header p{
    color:#64748B;
    margin:0;
    font-size:15px;
}


/* ===================== Custom Tabs ===================== */
.custom-tabs {
    background: #F1F5F9;
    padding: 5px;
    border-radius: 12px;
    display: inline-flex;
}
.custom-tabs .nav-link {
    color: #64748B;
    font-weight: 600;
    font-size: 14px;
    border-radius: 8px;
    padding: 8px 20px;
    transition: all 0.2s ease;
    border: none;
}
.custom-tabs .nav-link:hover {
    color: #334155;
    background: rgba(255,255,255,0.5);
}
.custom-tabs .nav-link.active {
    background: #ffffff;
    color: #2563EB;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

/* Ensure table cells truncate cleanly */
.table tbody td {
    padding:14px;
    vertical-align:middle;
    border-color:#F1F3F6;
    color:#334155;
}
.table tbody td.intern-type {
    max-width: 180px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.table tbody td.company-name {
    max-width: 150px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-weight: 600;
}

/* ===================== Table ===================== */

.table{
    font-size:14px;
    margin-bottom:0;
}

.table-responsive{
    border:1px solid #ECEFF3;
    border-radius:16px;
    overflow:auto;
    max-height:640px;
    box-shadow:0 1px 2px rgba(15,23,42,.03);
}

.table thead th{
    background:#F8FAFC;
    color:#475569;
    border-bottom:1px solid #E7EAEE;
    box-shadow:0 1px 0 rgba(15,23,42,.04);
    font-weight:700;
    font-size:11.5px;
    letter-spacing:.06em;
    text-transform:uppercase;
    padding:16px 15px;
    white-space:nowrap;
    position:sticky;
    top:0;
    z-index:5;
}


.table tbody td {
    padding:14px;
    vertical-align:middle;
    border-color:#F1F3F6;
    color:#334155;
}

.table tbody td.intern-type {
    max-width: 180px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.table tbody td.company-name {
    max-width: 150px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-weight: 600;
}
    

.table tbody tr{
    transition:background-color .15s ease;
}

.table tbody tr:hover{
    background:#F8FAFC;
}

.badge{
    padding:6px 13px;
    border-radius:20px;
    font-size:11.5px;
    font-weight:700;
    letter-spacing:.02em;
}

.btn-sm{
    font-size:12.5px;
    padding:6px 14px;
    white-space:nowrap;
    border-radius:9px;
    font-weight:600;
    letter-spacing:.01em;
    box-shadow:0 2px 6px -2px rgba(15,23,42,.18);
    transition:transform .15s ease, box-shadow .15s ease;
}

.btn-sm:hover{
    transform:translateY(-1px);
}

.offer-btn{
    white-space:nowrap;
    font-size:12.5px;
    padding:6px 14px;
}

 ===== Professional Buttons ===== 

.btn-primary{
    background:linear-gradient(135deg,#2563eb,#3b82f6);
    border:none;
    border-radius:12px;
    padding:10px 20px;
    font-weight:600;
    transition:all .3s ease;
}

.btn-primary:hover{
    transform:translateY(-2px);
    box-shadow:0 10px 20px rgba(37,99,235,.25);
}

.btn-success{
    background:linear-gradient(135deg,#14965a,#22c55e);
    border:none;
    border-radius:12px;
    padding:10px 20px;
    font-weight:600;
    transition:all .3s ease;
}

.btn-success:hover{
    transform:translateY(-2px);
    box-shadow:0 10px 20px rgba(34,197,94,.25);
}

.btn-secondary{
    border-radius:12px;
    padding:10px 20px;
    transition:all .3s ease;
}

.btn-secondary:hover{
    transform:translateY(-2px);
}

/* ===================== Premium Stat Cards ===================== */

.stat-card{
    --accent:#2563EB;
    --accent-soft:rgba(37,99,235,.16);
    --accent-glow:rgba(37,99,235,.24);
    --accent-border:rgba(37,99,235,.35);

    background:linear-gradient(165deg, #FFFFFF 0%, #FFFFFF 55%, var(--accent-soft) 160%);
    border:1px solid #EAECEF;
    border-top:4px solid var(--accent);
    border-radius:16px;
    padding:15px 16px 14px;
    height:100%;
    min-height:132px;
    display:flex;
    flex-direction:column;
    justify-content:flex-start;
    box-shadow:0 1px 2px rgba(15,23,42,.05), 0 14px 28px -16px rgba(15,23,42,.16);
    transition:transform .3s cubic-bezier(.2,.8,.2,1), box-shadow .3s ease, border-color .3s ease;
    position:relative;
    overflow:hidden;
    animation:cardUp .5s ease;
}

@keyframes cardUp{
    from{
        opacity:0;
        transform:translateY(18px);
    }
    to{
        opacity:1;
        transform:translateY(0);
    }
}

.stat-card::after{
    content:"";
    position:absolute;
    top:-50px;
    right:-50px;
    width:150px;
    height:150px;
    border-radius:50%;
    background:radial-gradient(circle, var(--accent-glow) 0%, rgba(255,255,255,0) 70%);
    pointer-events:none;
}

.stat-card:hover{
    transform:translateY(-7px);
    border-color:var(--accent);
    box-shadow:0 28px 48px -18px var(--accent-glow), 0 10px 22px -8px rgba(15,23,42,.12), inset 0 0 0 1px var(--accent-border);
}

.stat-card:hover .stat-icon{
    transform:scale(1.12) rotate(-2deg);
}

.stat-card .stat-icon{
    width:38px;
    height:38px;
    border-radius:11px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:17px;
    margin-bottom:9px;
    flex-shrink:0;
    background:var(--accent-soft);
    color:var(--accent);
    box-shadow:inset 0 0 0 1px rgba(255,255,255,.5), 0 6px 14px -6px var(--accent-glow);
    transition:transform .3s cubic-bezier(.2,.8,.2,1);
    position:relative;
    z-index:1;
}

.stat-card h6{
    font-size:10.5px;
    font-weight:700;
    letter-spacing:.07em;
    text-transform:uppercase;
    color:#64748B;
    margin-bottom:0;
    position:relative;
    z-index:1;
}

.stat-card h3,
.stat-card .total-count{
    font-size:23px;
    font-weight:800;
    letter-spacing:-.02em;
    line-height:1.05;
    color:var(--accent);
    margin-top:6px;
    margin-bottom:0;
    position:relative;
    z-index:1;
    font-variant-numeric:tabular-nums;
}

 Total Applications 
.total-card{
    --accent:#2563EB;
    --accent-soft:rgba(37,99,235,.16);
    --accent-glow:rgba(37,99,235,.26);
    --accent-border:rgba(37,99,235,.35);
}

 Problem Based 
.problem-card{
    --accent:#4F46E5;
    --accent-soft:rgba(79,70,229,.16);
    --accent-glow:rgba(79,70,229,.26);
    --accent-border:rgba(79,70,229,.35);
}

 Training + Project 
.training-card{
    --accent:#16A34A;
    --accent-soft:rgba(22,163,74,.16);
    --accent-glow:rgba(22,163,74,.26);
    --accent-border:rgba(22,163,74,.35);
}

 Real World 
.real-card{
    --accent:#0891B2;
    --accent-soft:rgba(8,145,178,.16);
    --accent-glow:rgba(8,145,178,.26);
    --accent-border:rgba(8,145,178,.35);
}

 Research 
.research-card{
    --accent:#F59E0B;
    --accent-soft:rgba(245,158,11,.18);
    --accent-glow:rgba(245,158,11,.28);
    --accent-border:rgba(245,158,11,.38);
}

 International 
.international-card{
    --accent:#E11D48;
    --accent-soft:rgba(225,29,72,.16);
    --accent-glow:rgba(225,29,72,.26);
    --accent-border:rgba(225,29,72,.35);
}

/* ===================== Status Breakdown (inside Total card) ===================== */

.status-list{
    margin-top:10px;
    display:flex;
    flex-direction:column;
    gap:4px;
    position:relative;
    z-index:1;
}

.status-item{
    display:flex;
    justify-content:space-between;
    align-items:center;
    font-size:10.5px;
    font-weight:600;
    padding:3px 8px;
    border-radius:7px;
    background:#F8FAFC;
}

.status-item span{
    font-weight:600;
    color:#64748B;
}

.status-item strong{
    font-size:12px;
    font-weight:800;
}

.status-item.approved strong{
    color:#16A34A;
}

.status-item.pending strong{
    color:#F59E0B;
}

.status-item.rejected strong{
    color:#DC2626;
}

/* ===================== Filter Toolbar ===================== */

.search-panel{
    background:linear-gradient(180deg,#FAFBFC 0%,#F6F8FA 100%);
    border:1px solid #E7EAEE;
    border-radius:16px;
    padding:18px 20px;
    margin-bottom:25px;
    box-shadow:none;
}

.filter-toolbar-label{
    display:flex;
    align-items:center;
    gap:8px;
    font-size:12.5px;
    font-weight:700;
    letter-spacing:.04em;
    text-transform:uppercase;
    color:#64748B;
    margin-bottom:14px;
}

.filter-toolbar-label i{
    font-size:15px;
    color:#2563EB;
}

.search-panel .form-control,
.search-panel .form-select{
    height:46px;
    border-radius:11px;
    border:1px solid #DFE3E8;
    box-shadow:none;
    font-size:13.5px;
    color:#1E293B;
    background:#fff;
    transition:border-color .2s ease, box-shadow .2s ease;
}

.search-panel .form-control:focus,
.search-panel .form-select:focus{
    border-color:#93C5FD;
    box-shadow:0 0 0 4px rgba(37,99,235,.10);
}

.search-panel .form-control::placeholder{
    color:#94A3B8;
}

.search-panel .btn{
    height:46px;
    border-radius:11px;
    font-weight:600;
    font-size:13.5px;
}

.search-panel .btn-success{
    box-shadow:0 8px 16px -8px rgba(22,163,74,.45);
}

.search-panel .btn-success:hover{
    box-shadow:0 12px 20px -8px rgba(22,163,74,.5);
}

.search-panel .btn-secondary{
    background:#fff;
    border:1px solid #DFE3E8;
    color:#475569;
}

.search-panel .btn-secondary:hover{
    background:#F1F5F9;
    color:#1E293B;
    box-shadow:none;
}

.select2-results__options{
    max-height:180px !important;
}

.select2-container--default .select2-selection--single{
    height:46px;
    border-radius:11px;
    padding:8px 12px;
    border:1px solid #DFE3E8;
}

.select2-container--default .select2-selection--single .select2-selection__arrow{
    height:44px;
}

</style>

</head>

<body>

<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="page-inner">
<div class="container-box">

<div class="page-header mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">

    <div>
        <h2>All Internships</h2>
        <p>
            Monitor internship applications, approvals and student records.
        </p>
    </div>

    <div>
        <ul class="nav nav-pills custom-tabs" id="internshipTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="students-tab" data-bs-toggle="pill" data-bs-target="#students-pane" type="button" role="tab"><i class="bi bi-people-fill me-2"></i>Students</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="companies-tab" data-bs-toggle="pill" data-bs-target="#companies-pane" type="button" role="tab"><i class="bi bi-buildings-fill me-2"></i>Companies</button>
            </li>
        </ul>
    </div>

</div>

<div class="tab-content" id="internshipTabsContent">
    <div class="tab-pane fade show active" id="students-pane" role="tabpanel">


<div class="row g-4 mb-4">

<div class="col-xl-2 col-lg-4 col-md-6 col-sm-6">

    <div class="stat-card total-card">

        <div class="stat-icon">
            <i class="bi bi-clipboard-data-fill"></i>
        </div>

        <h6>Total Applications</h6>

        <h3 class="total-count">
            <?php echo (int)$total_applications; ?>
        </h3>

        <div class="status-list">

            <div class="status-item approved">
                <span>✔ Approved</span>
                <strong><?php echo (int)$approved_count; ?></strong>
            </div>

            <div class="status-item pending">
                <span>⏳ Pending</span>
                <strong><?php echo (int)$pending_count; ?></strong>
            </div>

            <div class="status-item rejected">
                <span>✖ Rejected</span>
                <strong><?php echo (int)$rejected_count; ?></strong>
            </div>

        </div>

    </div>

</div>

    <div class="col-md-2">
        <div class="stat-card problem-card">
            <div class="stat-icon">
                <i class="bi bi-lightbulb-fill"></i>
            </div>
            <h6>Problem Based</h6>
            <h3><?php echo (int)$problem_count; ?></h3>
        </div>
    </div>

    <div class="col-md-2">
        <div class="stat-card training-card">
            <div class="stat-icon">
                <i class="bi bi-mortarboard-fill"></i>
            </div>
            <h6>Training + Project</h6>
            <h3><?php echo (int)$training_count; ?></h3>
        </div>
    </div>

    <div class="col-md-2">
        <div class="stat-card real-card">
            <div class="stat-icon">
                <i class="bi bi-briefcase-fill"></i>
            </div>
            <h6>Real World</h6>
            <h3><?php echo (int)$realworld_count; ?></h3>
        </div>
    </div>

    <div class="col-md-2">
        <div class="stat-card research-card">
            <div class="stat-icon">
                <i class="bi bi-search-heart-fill"></i>
            </div>
            <h6>Research</h6>
            <h3><?php echo (int)$research_count; ?></h3>
        </div>
    </div>

    <div class="col-md-2">
        <div class="stat-card international-card">
            <div class="stat-icon">
                <i class="bi bi-airplane-fill"></i>
            </div>
            <h6>International</h6>
            <h3><?php echo (int)$international_count; ?></h3>
        </div>
    </div>

</div>

<div class="search-panel">

<div class="filter-toolbar-label">
    <i class="bi bi-sliders"></i>
    Filters
</div>

<form method="GET" class="row g-3 align-items-end">

    <div class="col-md-2">
<select name="division" id="division" class="form-select">
<option value="">All Divisions</option>

<option value="IT-SMAD" <?php if($division=="IT-SMAD") echo "selected"; ?>>IT-SMAD</option>
<option value="IT-DA" <?php if($division=="IT-DA") echo "selected"; ?>>IT-DA</option>
<?php for($i=1; $i<=22; $i++): $sy = "SY-$i"; ?>
<option value="<?php echo $sy; ?>" <?php if($division==$sy) echo "selected"; ?>><?php echo $sy; ?></option>
<?php endfor; ?>

</select>
    </div>

    <div class="col-md-3">
        <input type="search"
       name="search"
       class="form-control"
       placeholder="Search Student Name or Enrollment No"
       value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"
       autocomplete="new-password"
       spellcheck="false">
    </div>

    <div class="col-md-3">

        <select name="type" class="form-select">

<option value="">All Internship Types</option>

<option value="Problem-based Internship"
<?php if($type=="Problem-based Internship") echo "selected"; ?>>
Problem-based Internship
</option>

<option value="Training + Mini Project"
<?php if($type=="Training + Mini Project") echo "selected"; ?>>
Training + Mini Project
</option>

<option value="Real-world Exposure Internship (Field Work)"
<?php if($type=="Real-world Exposure Internship (Field Work)") echo "selected"; ?>>
Real-world Exposure Internship (Field Work)
</option>

<option value="Research Internship"
<?php if($type=="Research Internship") echo "selected"; ?>>
Research Internship
</option>

<option value="International Internship / Visit"
<?php if($type=="International Internship / Visit") echo "selected"; ?>>
International Internship / Visit
</option>

</select>

    </div>

    <!-- NEW: status filter (Approved / Pending / Rejected) -->
    <div class="col-md-2">
        <select name="status" class="form-select">
            <option value="">All Status</option>
            <option value="Approved" <?php if($status=="Approved") echo "selected"; ?>>Approved</option>
            <option value="Pending" <?php if($status=="Pending") echo "selected"; ?>>Pending</option>
            <option value="Rejected" <?php if($status=="Rejected") echo "selected"; ?>>Rejected</option>
        </select>
    </div>

    <div class="col-md-2 d-flex gap-2">

        <button type="submit" class="btn btn-success flex-fill">
            <i class="bi bi-search"></i> Search
        </button>

        <a href="all_internships.php"
           class="btn btn-secondary flex-fill">
            Reset
        </a>

    </div>

</form>

</div>

<div class="table-responsive shadow-sm rounded-4">

<table class="table table-hover align-middle mb-0" style="border-collapse: separate; border-spacing: 0;">

<thead style="background:#F8FAFC; border-bottom:2px solid #E2E8F0;">
<tr>
    <th style="padding:16px; color:#64748B; font-weight:700; font-size:11.5px; text-transform:uppercase; letter-spacing:0.05em; border:none;">Division</th>
    <th style="padding:16px; color:#64748B; font-weight:700; font-size:11.5px; text-transform:uppercase; letter-spacing:0.05em; border:none;">Student</th>
    <th style="padding:16px; color:#64748B; font-weight:700; font-size:11.5px; text-transform:uppercase; letter-spacing:0.05em; border:none;">Enrollment No</th>
    <th style="padding:16px; color:#64748B; font-weight:700; font-size:11.5px; text-transform:uppercase; letter-spacing:0.05em; border:none;">Company</th>
    <th style="padding:16px; color:#64748B; font-weight:700; font-size:11.5px; text-transform:uppercase; letter-spacing:0.05em; border:none;">Role</th>
    <th style="padding:16px; color:#64748B; font-weight:700; font-size:11.5px; text-transform:uppercase; letter-spacing:0.05em; border:none;">Internship Type</th>
    <th style="padding:16px; color:#64748B; font-weight:700; font-size:11.5px; text-transform:uppercase; letter-spacing:0.05em; border:none; text-align:center;">Offer Letter</th>
    <th style="padding:16px; color:#64748B; font-weight:700; font-size:11.5px; text-transform:uppercase; letter-spacing:0.05em; border:none; text-align:center;">Status</th>
</tr>
</thead>

<tbody>
<?php
if(mysqli_num_rows($result) > 0)
{
    while($row = mysqli_fetch_assoc($result))
    {
        $statusBadge = "<span class='badge bg-warning text-dark' style='border-radius:6px; font-weight:600;'><i class='bi bi-clock-history me-1'></i> Pending</span>";
        if($row['status'] == 'Approved') $statusBadge = "<span class='badge bg-success' style='border-radius:6px; font-weight:600; box-shadow:0 2px 4px rgba(25,135,84,0.2); display:block; margin-bottom:4px;'><i class='bi bi-check-circle-fill me-1'></i> Approved</span><a href='generate_certificate.php?id=" . $row['student_id'] . "' target='_blank' class='btn btn-sm btn-outline-primary' style='font-size:11px; padding:2px 6px; border-radius:4px; font-weight:700;'><i class='bi bi-award-fill'></i> Certificate</a>";
        if($row['status'] == 'Rejected') $statusBadge = "<span class='badge bg-danger' style='border-radius:6px; font-weight:600;'><i class='bi bi-x-circle-fill me-1'></i> Rejected</span>";
?>
<tr style="transition: all 0.2s ease;">
    <td style="padding:16px;">
        <span class="badge bg-light text-dark border" style="font-size:12px; font-weight:700; letter-spacing:0.5px; border-radius:6px;"><?php echo htmlspecialchars($row['division'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
    </td>
    <td style="padding:16px;">
        <div style="font-weight:700; color:#0F172A; font-size:14px;"><?php echo htmlspecialchars($row['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
    </td>
    <td style="padding:16px;">
        <div style="color:#64748B; font-size:12.5px; font-family:monospace; font-weight:600; background:#F8FAFC; padding:4px 8px; border-radius:4px; display:inline-block; border:1px solid #E2E8F0;"><?php echo htmlspecialchars($row['enrollment_no'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
    </td>
    <td style="padding:16px; max-width:160px;">
        <div style="font-weight:700; color:#1E293B; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?php echo htmlspecialchars($row['company_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($row['company_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>
        </div>
    </td>
    <td style="padding:16px; max-width:140px;">
        <div style="color:#475569; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?php echo htmlspecialchars($row['internship_role'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            <i class="bi bi-person-workspace me-1 text-muted"></i><?php echo htmlspecialchars($row['internship_role'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>
        </div>
    </td>
    <td style="padding:16px; max-width:180px;">
        <div style="color:#3B82F6; font-size:13px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; background:#EFF6FF; padding:4px 8px; border-radius:6px; display:inline-block;" title="<?php echo htmlspecialchars($row['internship_type'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo ($row['status'] == 'Approved') ? htmlspecialchars($row['internship_type'] ?? 'N/A', ENT_QUOTES, 'UTF-8') : '-'; ?>
        </div>
    </td>
    <td style="padding:16px; text-align:center;">
        <?php
        if($row['status'] == 'Approved' && !empty($row['offer_letter'])) {
            $safeDivision = rawurlencode($row['division']);
            $safeOfferLetter = rawurlencode($row['offer_letter']);
            echo '<a href="../uploads/offer_letters/' . $safeDivision . '/' . $safeOfferLetter . '" target="_blank" class="btn btn-sm btn-primary" style="font-weight:600; border-radius:8px; box-shadow:0 2px 6px rgba(13,110,253,0.3);"><i class="bi bi-file-earmark-pdf-fill me-1"></i> View</a>';
        } else {
            echo '<span class="text-muted" style="font-size:13px;">-</span>';
        }
        ?>
    </td>
    <td style="padding:16px; text-align:center;">
        <?php echo $statusBadge; ?>
    </td>
</tr>
<?php
    }
} else {
    echo '<tr><td colspan="8" class="text-center py-5 text-muted"><i class="bi bi-inbox fs-2 d-block mb-2"></i>No records found matching your filters</td></tr>';
}
?>
</tbody>

</table>

</div> <!-- table-responsive -->
    </div> <!-- end students tab -->

    <!-- COMPANIES TAB -->
    <div class="tab-pane fade" id="companies-pane" role="tabpanel">
        <div class="d-flex justify-content-between align-items-center mb-4 mt-2">
            <h4 class="fw-bold mb-0" style="color:#0F172A;">Top Hiring Partners</h4>
            <a href="export_companies.php" class="btn text-white" style="background:#8b5cf6; border-radius:10px; font-weight:600; padding:10px 20px; box-shadow:0 4px 10px rgba(139,92,246,0.3);">
                <i class="bi bi-file-earmark-spreadsheet-fill me-2"></i> Download Full CSV
            </a>
        </div>
        
        <div class="table-responsive shadow-sm rounded-4" style="border:1px solid #ECEFF3;">
            <table class="table table-hover align-middle mb-0" style="background:#fff;">
                <thead>
                    <tr>
                        <th style="padding:16px 20px; font-size:12px; color:#475569; text-transform:uppercase;">Company Name</th>
                        <th style="padding:16px 20px; font-size:12px; color:#475569; text-transform:uppercase;" class="text-center">Hired</th>
                        <th style="padding:16px 20px; font-size:12px; color:#475569; text-transform:uppercase;">Roles Offered</th>
                        <th style="padding:16px 20px; font-size:12px; color:#475569; text-transform:uppercase;">Tech Domains</th>
                        
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = mysqli_fetch_assoc($company_result)): ?>
                    <tr style="transition:all 0.2s;">
                        <td style="padding:16px 20px; font-weight:700; color:#1E293B; max-width:200px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?php echo htmlspecialchars($row['company_name']); ?>">
                            <?php echo htmlspecialchars($row['company_name']); ?>
                        </td>
                        <td class="text-center" style="padding:16px 20px;">
                            <span class="badge bg-success" style="font-size:14px; padding:6px 12px; border-radius:8px; box-shadow:0 2px 5px rgba(25,135,84,0.3);"><?php echo (int)$row['total_students']; ?></span>
                        </td>
                        <td style="padding:16px 20px;">
                            <?php 
                            $roles = explode(', ', $row['roles_offered'] ?? '');
                            foreach($roles as $r) {
                                if(trim($r)) echo '<span class="badge bg-light text-dark border" style="margin:2px; font-weight:600;"><i class="bi bi-person-badge"></i> '.htmlspecialchars(trim($r)).'</span>';
                            }
                            ?>
                        </td>
                        <td style="padding:16px 20px;">
                            <?php 
                            $domains = explode(', ', $row['domains'] ?? '');
                            foreach($domains as $d) {
                                if(trim($d)) echo '<span class="badge" style="background:#EFF6FF; color:#1D4ED8; border:1px solid #BFDBFE; margin:2px; font-weight:600;"><i class="bi bi-braces text-primary"></i> '.htmlspecialchars(trim($d)).'</span>';
                            }
                            ?>
                        </td>
                        
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div> <!-- end companies tab -->
</div> <!-- end tab content -->

</div> <!-- container-box -->
</div>

</main>
</div>

<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
