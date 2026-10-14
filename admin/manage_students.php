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

   1. SQL injection: search / division / internship_status /
      internship_type were all string-concatenated raw from $_GET
      into the query. Converted to one prepared statement built from
      a $where[] array (same pattern used in export_students.php).
   2. XSS: every $row[...] value (name, enrollment_no, division,
      roll_no, email, phone, department) and the $search value
      re-echoed into the search box were output with no escaping.
      All now go through htmlspecialchars().
   3. Ordering: replaced the REPLACE(UPPER(division),'SY-','') trick
      (which ties IT-DA and IT-SMAD at 0 against each other) with the
      explicit IT-DA-first / IT-SMAD-second / SY-* CASE ordering used
      elsewhere in the project.

   Filtering behavior, stat cards, and Edit/Delete links are
   otherwise unchanged.
   ----------------------------------------------------------------- */

$search = trim($_GET['search'] ?? '');
$division = trim($_GET['division'] ?? '');
$internship_status = trim($_GET['internship_status'] ?? '');
$internship_type = trim($_GET['internship_type'] ?? '');

$year = trim($_GET['year'] ?? '');

// Fetch dynamic divisions based on current year filter (if any)
$div_query = "SELECT DISTINCT division FROM users WHERE role='student' AND division IS NOT NULL AND TRIM(division) <> ''";
if($year === 'TY') { $div_query .= " AND year_of_study='TY'"; }
else if($year === 'SY') { $div_query .= " AND (year_of_study='SY' OR year_of_study IS NULL OR year_of_study = '')"; }
$div_query .= " ORDER BY division";
$div_res = mysqli_query($conn, $div_query);
$dynamic_divisions = [];
while($dr = mysqli_fetch_assoc($div_res)) {
    $dynamic_divisions[] = $dr['division'];
}

$where = ["users.role='student'"];

if($year !== '') {
    if($year === 'TY') {
        $where[] = "users.year_of_study='TY'";
    } else if($year === 'SY') {
        $where[] = "(users.year_of_study='SY' OR users.year_of_study IS NULL OR users.year_of_study = '')";
    }
}

$params = [];
$types = "";

if($search !== '')
{
    $where[] = "(users.enrollment_no LIKE ? OR users.full_name LIKE ?)";
    $like = "%" . $search . "%";
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}

if($division !== '')
{
    $where[] = "users.division=?";
    $params[] = $division;
    $types .= "s";
}

if($internship_status !== '')
{
    if($internship_status === "Not Submitted")
    {
        $where[] = "internships.id IS NULL";
    }
    else
    {
        $where[] = "internships.status=?";
        $params[] = $internship_status;
        $types .= "s";
    }
}

if($internship_type !== '')
{
    $where[] = "internships.internship_type=?";
    $params[] = $internship_type;
    $types .= "s";
}

$whereSql = implode(" AND ", $where);

$query = "
    SELECT users.*,
           internships.status AS internship_status,
           internships.internship_type

    FROM users

    LEFT JOIN internships
        ON internships.id = (
            SELECT id FROM internships i2
            WHERE i2.student_id = users.id
            ORDER BY i2.id DESC
            LIMIT 1
        )

    WHERE $whereSql

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

$activePage = 'manage_students.php';

?>

<!DOCTYPE html>
<html>
<head>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Students</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.47.0/dist/tabler-icons.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<?php include __DIR__ . '/includes/sidebar_head.php'; ?>


<style>
:root {
    --primary: #4f46e5;
    --primary-glow: rgba(79,70,229,0.25);
    --bg-color: #f3f4f6;
    --text-main: #111827;
    --text-muted: #6b7280;
    --card-bg: rgba(255, 255, 255, 0.95);
}
* {
    font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
}
html, body {
    width: 100%; max-width: 100%; overflow-x: hidden; margin: 0;
    background: #f3f4f6;
    background-image: radial-gradient(circle at 10% 20%, rgba(91, 61, 245, 0.03) 0%, transparent 20%), 
                      radial-gradient(circle at 90% 80%, rgba(16, 185, 129, 0.03) 0%, transparent 20%);
    background-attachment: fixed;
    color: var(--text-main);
}
.page-inner { padding: 32px; }
.container-box {
    background: transparent;
    padding: 0;
    border: none;
    box-shadow: none;
    margin: 0;
}

/* Header */
.page-header {
    display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;
}
.page-header h2 { font-size: 1.6rem; font-weight: 800; color: #111827; margin-bottom: 4px; }
.page-header p { font-size: 0.95rem; color: #6b7280; font-weight: 500; }
.btn-add-student {
    background: linear-gradient(135deg, #4f46e5, #3b82f6);
    color: #fff; border: none; font-weight: 700; border-radius: 12px;
    padding: 12px 24px; box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-flex; align-items: center; gap: 8px;
}
.btn-add-student:hover {
    transform: translateY(-2px); box-shadow: 0 8px 20px rgba(79, 70, 229, 0.35); color:#fff;
}

/* Stat Cards */
.stats-row {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: 24px; margin-bottom: 24px;
}
.stats-row-full {
    display: grid; grid-template-columns: 1fr; margin-bottom: 32px;
}
.stat-card {
    background: var(--card-bg); backdrop-filter: blur(10px);
    border-radius: 20px; padding: 24px; border: 1px solid rgba(255,255,255,0.8);
    box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05);
    transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    display: flex; flex-direction: column; position: relative; overflow: hidden;
    animation: fadeInUp 0.6s ease forwards;
}
.stat-card::before {
    content: ''; position: absolute; inset: 0; z-index: -1;
    background: linear-gradient(135deg, rgba(255,255,255,0.4) 0%, transparent 100%);
}
.stat-card:hover {
    transform: translateY(-6px) scale(1.02);
    box-shadow: 0 20px 40px -10px rgba(0,0,0,0.1);
    border-color: #fff;
}
.stat-icon {
    width: 46px; height: 46px; border-radius: 14px;
    display: flex; align-items: center; justify-content: center; font-size: 1.3rem;
    color: #fff; margin-bottom: 16px; transition: transform 0.3s;
}
.stat-card:hover .stat-icon { transform: scale(1.1) rotate(5deg); }
.stat-title { font-size: 0.85rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
.stat-value { font-size: 2rem; font-weight: 800; color: var(--text-main); line-height: 1; margin-bottom: 6px; }
.stat-desc { font-size: 0.85rem; font-weight: 600; color: #94a3b8; }

.stat-total .stat-icon { background: linear-gradient(135deg, #8b5cf6, #6d28d9); box-shadow: 0 8px 16px rgba(139,92,246,0.3); }
.stat-approved .stat-icon { background: linear-gradient(135deg, #10b981, #059669); box-shadow: 0 8px 16px rgba(16,185,129,0.3); }
.stat-pending .stat-icon { background: linear-gradient(135deg, #f59e0b, #d97706); box-shadow: 0 8px 16px rgba(245,158,11,0.3); }
.stat-rejected .stat-icon { background: linear-gradient(135deg, #ef4444, #dc2626); box-shadow: 0 8px 16px rgba(239,68,68,0.3); }
.stat-notsubmitted .stat-icon { background: linear-gradient(135deg, #475569, #1e293b); box-shadow: 0 8px 16px rgba(71,85,105,0.3); }

/* Main Panel */
.card {
    background: var(--card-bg) !important; backdrop-filter: blur(10px);
    border-radius: 24px !important; border: 1px solid rgba(255,255,255,0.8) !important;
    box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05) !important;
    animation: fadeInUp 0.8s ease forwards; animation-delay: 0.2s; opacity: 0;
}
.card-body { padding: 32px !important; }

/* Filter Toolbar */
.filter-toolbar {
    display: flex; align-items: center; gap: 20px; flex-wrap: wrap; margin-bottom: 24px;
}
.filter-toolbar-label { font-size: 1rem; font-weight: 800; color: var(--text-main); display: flex; align-items: center; gap: 8px; }
.filter-toolbar-label i { color: #4f46e5; }
.search-box { display: flex; flex: 1; gap: 12px; flex-wrap: wrap; }
.form-control, .form-select {
    height: 48px; border-radius: 12px; border: 2px solid #e2e8f0; font-size: 0.95rem; font-weight: 500;
    transition: all 0.2s; background: #fff; box-shadow: none;
}
.form-control:focus, .form-select:focus {
    border-color: #4f46e5; box-shadow: 0 0 0 4px rgba(79,70,229,0.1); outline: none;
}
.filter-input { flex: 1; min-width: 160px; }
.filter-actions { display: flex; gap: 12px; }
.btn-search {
    background: linear-gradient(135deg, #4f46e5, #3b82f6); color: #fff; font-weight: 700; border-radius: 12px;
    padding: 0 24px; border: none; box-shadow: 0 4px 12px rgba(79,70,229,0.2); transition: all 0.3s;
    height: 48px;
}
.btn-search:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(79,70,229,0.3); color:#fff; }
.btn-reset {
    background: #fff; color: #475569; font-weight: 700; border-radius: 12px; border: 2px solid #e2e8f0;
    padding: 0 24px; transition: all 0.3s; height: 48px; display: inline-flex; align-items: center;
}
.btn-reset:hover { background: #f8fafc; border-color: #cbd5e1; transform: translateY(-2px); text-decoration: none;}


/* Table */
.table-responsive {
    border-radius: 16px; border: 1px solid #e2e8f0; overflow-y: auto; overflow-x: hidden; max-height: 640px; box-shadow: 0 4px 6px rgba(0,0,0,0.02);
}
.table { margin-bottom: 0; background: #fff; table-layout: fixed; width: 100%; word-wrap: break-word; }

.table thead th {
    background: #f8fafc; padding: 18px 20px; font-size: 0.8rem; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); border-bottom: 2px solid #e2e8f0;
}
.table td { padding: 18px 20px; font-size: 0.95rem; font-weight: 500; color: #334155; vertical-align: middle; border-bottom: 1px solid #f1f5f9; }
.table tbody tr { transition: background 0.2s; }
.table tbody tr:hover { background: #f8fafc; }
.badge { padding: 6px 12px; border-radius: 8px; font-weight: 700; font-size: 0.8rem; letter-spacing: 0.3px; }
.bg-success { background: rgba(16,185,129,0.1) !important; color: #059669 !important; border: 1px solid rgba(16,185,129,0.2); }
.bg-warning { background: rgba(245,158,11,0.1) !important; color: #d97706 !important; border: 1px solid rgba(245,158,11,0.2); }
.bg-danger { background: rgba(239,68,68,0.1) !important; color: #dc2626 !important; border: 1px solid rgba(239,68,68,0.2); }
.bg-secondary { background: #f1f5f9 !important; color: #475569 !important; border: 1px solid #e2e8f0; }

.btn-sm { font-weight: 700; border-radius: 8px; padding: 6px 14px; font-size: 0.85rem; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
.btn-primary.btn-sm { background: linear-gradient(135deg, #3b82f6, #2563eb); border: none; }
.btn-danger.btn-sm { background: linear-gradient(135deg, #f43f5e, #e11d48); border: none; }

@keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

@media (max-width: 1200px) { .stats-row { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 768px) {
    .stats-row { grid-template-columns: 1fr; }
    .page-inner { padding: 16px; }
    .card-body { padding: 20px !important; }
    .search-box { flex-direction: column; }
    .filter-input { width: 100%; }
    .filter-actions { width: 100%; }
    .btn-search, .btn-reset { flex: 1; }
}
</style>


</head>

<body>

<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="page-inner">
<div class="container-box">

    <div class="page-header">

    <div>

        <h2 class="fw-bold mb-1">
            Manage Students
        </h2>

        <p class="text-muted mb-0">
            Manage student records, internship status and account information.
        </p>

    </div>

    <div class="header-actions">

        <a href="add_student.php"
           class="btn-add-student"
           >
            + Add Student
        </a>

    </div>

</div>

    <div class="card shadow-sm border-0 mb-4"
     style="border-radius:18px;">

<div class="card-body">

<form method="GET">

        <?php

$total_students = 0;
$approved = 0;
$pending = 0;
$rejected = 0;
$notSubmitted = 0;

while($row = mysqli_fetch_assoc($result)) {
    $total_students++;
    if ($row['internship_status'] == 'Approved') $approved++;
    elseif ($row['internship_status'] == 'Pending') $pending++;
    elseif ($row['internship_status'] == 'Rejected') $rejected++;
    else $notSubmitted++;
}

// Reset the result pointer so the table can loop through it again
if(mysqli_num_rows($result) > 0) { mysqli_data_seek($result, 0); }

?>


<div class="stats-row">
    <div class="stat-card stat-total" style="animation-delay: 0.05s">
        <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
        <div class="stat-title">Total Students</div>
        <div class="stat-value"><?php echo number_format($total_students); ?></div>
        <div class="stat-desc">Registered Students</div>
    </div>
    <div class="stat-card stat-approved" style="animation-delay: 0.1s">
        <div class="stat-icon"><i class="bi bi-patch-check-fill"></i></div>
        <div class="stat-title">Approved</div>
        <div class="stat-value"><?php echo number_format($approved); ?></div>
        <div class="stat-desc">Internships Approved</div>
    </div>
    <div class="stat-card stat-pending" style="animation-delay: 0.15s">
        <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
        <div class="stat-title">Pending</div>
        <div class="stat-value"><?php echo number_format($pending); ?></div>
        <div class="stat-desc">Awaiting Review</div>
    </div>
    <div class="stat-card stat-rejected" style="animation-delay: 0.2s">
        <div class="stat-icon"><i class="bi bi-x-circle-fill"></i></div>
        <div class="stat-title">Rejected</div>
        <div class="stat-value"><?php echo number_format($rejected); ?></div>
        <div class="stat-desc">Internships Rejected</div>
    </div>
</div>
<div class="stats-row-full">
    <div class="stat-card stat-notsubmitted" style="animation-delay: 0.25s; flex-direction: row; align-items: center; justify-content: space-between; padding: 24px 32px;">
        <div style="display: flex; align-items: center; gap: 20px;">
            <div class="stat-icon" style="margin-bottom: 0; width: 56px; height: 56px; font-size: 1.6rem;"><i class="bi bi-file-earmark-excel-fill"></i></div>
            <div>
                <div class="stat-title">Not Submitted</div>
                <div class="stat-desc" style="font-size: 0.95rem; color: #94a3b8; font-weight:600">No Internship Submitted</div>
            </div>
        </div>
        <div class="stat-value" style="margin-bottom: 0; font-size: 2.5rem;"><?php echo number_format($notSubmitted); ?></div>
    </div>
</div>
<div class="filter-toolbar">

        <div class="filter-toolbar-label">
            <i class="bi bi-sliders"></i>
            Filters
        </div>

        <div class="search-box">

            <input type="text"
                   name="search"
                   class="form-control filter-input search-input"
                   placeholder="Search Enrollment No"
                   value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">

            
            
            <select name="year" class="form-control filter-input">
                <option value="">All Academic Years</option>
                <option value="SY" <?php if($year=="SY") echo "selected"; ?>>Second Year (SY)</option>
                <option value="TY" <?php if($year=="TY") echo "selected"; ?>>Third Year (TY)</option>
            </select>

<select name="division" class="form-control filter-input division-input">
                <option value="">All Divisions</option>
                <?php foreach($dynamic_divisions as $div): ?>
                    <option value="<?php echo htmlspecialchars($div); ?>" <?php if($division==$div) echo "selected"; ?>>
                        <?php echo htmlspecialchars($div); ?>
                    </option>
                <?php endforeach; ?>
            </select>


            <select name="internship_status"
                    class="form-control filter-input status-input">

                <option value="">
                    All Internship Status
                </option>

                <option value="Approved"
                <?php if($internship_status=="Approved") echo "selected"; ?>>
                Approved
                </option>

                <option value="Pending"
                <?php if($internship_status=="Pending") echo "selected"; ?>>
                Pending
                </option>

                <option value="Rejected"
                <?php if($internship_status=="Rejected") echo "selected"; ?>>
                Rejected
                </option>

                <option value="Not Submitted"
                <?php if($internship_status=="Not Submitted") echo "selected"; ?>>
                Not Submitted
                </option>

            </select>

<select name="internship_type"
        class="form-control filter-input type-input">

    <option value="">
        All Internship Types
    </option>

    <option value="Problem-based Internship"
    <?php if($internship_type=="Problem-based Internship") echo "selected"; ?>>
    Problem-based Internship
    </option>

    <option value="Training + Mini Project"
    <?php if($internship_type=="Training + Mini Project") echo "selected"; ?>>
    Training + Mini Project
    </option>

    <option value="Real-world Exposure Internship (Field Work)"
    <?php if($internship_type=="Real-world Exposure Internship (Field Work)") echo "selected"; ?>>
    Real-world Exposure Internship (Field Work)
    </option>

    <option value="Research Internship"
    <?php if($internship_type=="Research Internship") echo "selected"; ?>>
    Research Internship
    </option>

    <option value="International Internship / Visit"
<?php if($internship_type=="International Internship / Visit") echo "selected"; ?>>
International Internship / Visit
</option>

</select>

            <div class="filter-actions">

            <button type="submit"
                    class="btn btn-success btn-search">

                Search

            </button>

            <a href="manage_students.php"
               class="btn btn-secondary btn-reset">

               Reset

            </a>

            </div>

        </div>

        </div>

</form>

    </div>
</div>

    <div class="table-responsive">

<table class="table table-hover align-middle mb-0">

        <thead style="
background:#F8FAFC;
color:#475569;
border-bottom:1px solid #E7EAEE;
box-shadow:0 1px 0 rgba(15,23,42,.04);
">

            <!-- Column widths below are percentages that sum to exactly
                 100%. Combined with table-layout:fixed on .table (see
                 <style> above), the table can never render wider than its
                 container, which is what removes the horizontal
                 scrollbar this page used to need. -->
            <tr>

                <th style="width:13%;">Name</th>
                <th style="width:12%;">Enrollment No</th>
                <th style="width:8%;">Division</th>
                <th style="width:7%;">Roll No</th>
                <th style="width:12%;">Email</th>
                <th style="width:9%;">Phone</th>
                <th style="width:8%;">Department</th>
                <th style="width:10%;">Internship Status</th>
                <th style="width:11%;">Internship Type</th>
                <th style="width:10%;">Action</th>

            </tr>

        </thead>

        <tbody>

        <?php

        while($row = mysqli_fetch_assoc($result))
        {

        ?>

        <tr>

            <td>
                <?php echo htmlspecialchars($row['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
            </td>

            <td>
                <?php echo htmlspecialchars($row['enrollment_no'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
            </td>

            <td>
                <?php echo htmlspecialchars($row['division'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
            </td>

            <td>
                <?php echo htmlspecialchars($row['roll_no'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
            </td>

            <td class="email-col">
<?php echo htmlspecialchars($row['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
</td>

            <td>
                <?php echo htmlspecialchars($row['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
            </td>

            <td>
                <?php echo htmlspecialchars($row['department'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
            </td>

            <td>

                <?php

                if($row['internship_status'] == "")
                {
                    echo "Not Submitted";
                }
                else
                {
                    if($row['internship_status']=="Approved")
{
    echo '<span class="badge bg-success">Approved</span>';
}
elseif($row['internship_status']=="Pending")
{
    echo '<span class="badge bg-warning text-dark">Pending</span>';
}
elseif($row['internship_status']=="Rejected")
{
    echo '<span class="badge bg-danger">Rejected</span>';
}
else
{
    echo '<span class="badge bg-secondary">Not Submitted</span>';
}
                }

                ?>

            </td>

            <td>

                <?php

                if($row['internship_type'] != "")
{
    echo htmlspecialchars($row['internship_type'], ENT_QUOTES, 'UTF-8');
}
else
{
    echo "-";
}

                ?>

            </td>

            <td>

<div class="d-flex gap-2" style="flex-wrap:wrap;">

<a href="edit_student.php?id=<?php echo (int)$row['id']; ?>&return=<?php echo urlencode($_SERVER['QUERY_STRING']); ?>"
   class="btn btn-primary btn-sm">
    Edit
</a>

<a href="delete_student.php?id=<?php echo (int)$row['id']; ?>&return=<?php echo urlencode($_SERVER['QUERY_STRING']); ?>"
   class="btn btn-danger btn-sm"
   onclick="return confirm('Are you sure you want to delete this student?');">
    Delete
</a>

</div>

</td>

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

<script>
window.addEventListener("beforeunload", function () {
    sessionStorage.setItem("manageStudentsScroll", window.scrollY);
});

window.addEventListener("load", function () {

    const scroll = sessionStorage.getItem("manageStudentsScroll");

    if(scroll !== null)
    {
        window.scrollTo(0, parseInt(scroll));
    }

});
</script>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>

</body>
</html>
