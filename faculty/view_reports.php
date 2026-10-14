<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty')
{
    header("Location: ../login.php");
    exit();
}

/* -----------------------------------------------------------------
   REPLACE — sidebar propagation (Phase 2) + one ordering bug fixed
   while wiring this page into the shared sidebar:

   ORDER BY FIELD(users.division, 'IT-DA', 'IT-SMAD') ASC was
   backwards: MySQL's FIELD() returns 0 for any value NOT in the
   list, so every SY-* division (0) sorted BEFORE IT-DA (1) and
   IT-SMAD (2) — the exact opposite of the required
   IT-DA-first / IT-SMAD-second / SY-* order. Replaced with the same
   explicit CASE ordering used everywhere else in the project.

   Also converted the division-scoping + search query to a prepared
   statement (previously used manual mysqli_real_escape_string,
   which was safe but inconsistent with the rest of the project).
   ----------------------------------------------------------------- */

$faculty_id = (int)$_SESSION['user_id'];

/* Get faculty profile + all assigned divisions */
$faculty_query = $conn->prepare("SELECT faculty_division FROM users WHERE id=?");
$faculty_query->bind_param("i", $faculty_id);
$faculty_query->execute();
$faculty_data = $faculty_query->get_result()->fetch_assoc();

/*
 * Multi-division support:
 * Read all divisions assigned through faculty_divisions.
 * Fall back to users.faculty_division for existing single-division faculty.
 */
$faculty_divisions = [];

$division_stmt = $conn->prepare("
    SELECT DISTINCT division
    FROM faculty_divisions
    WHERE faculty_id=?
      AND division IS NOT NULL
      AND TRIM(division) <> ''
    ORDER BY division ASC
");
$division_stmt->bind_param("i", $faculty_id);
$division_stmt->execute();
$division_query = $division_stmt->get_result();

if($division_query)
{
    while($division_row = mysqli_fetch_assoc($division_query))
    {
        $division_value = trim((string)$division_row['division']);

        if($division_value !== '' && !in_array($division_value, $faculty_divisions, true))
        {
            $faculty_divisions[] = $division_value;
        }
    }
}

if(empty($faculty_divisions) && !empty($faculty_data['faculty_division']))
{
    $faculty_divisions[] = trim((string)$faculty_data['faculty_division']);
}

$faculty_division = implode(', ', $faculty_divisions);

$search = trim($_GET['search'] ?? '');

$activePage = 'view_reports.php';

if(empty($faculty_divisions))
{
    $result = false;
}
else
{
    $divPlaceholders = implode(',', array_fill(0, count($faculty_divisions), '?'));

    $query = "
        SELECT

        final_reports.*,

        internships.company_name,
        internships.mini_project_title,

        users.full_name,
        users.enrollment_no,
        users.division,
        users.roll_no

        FROM final_reports

        INNER JOIN internships
        ON final_reports.internship_id=internships.id

        INNER JOIN users
        ON internships.student_id=users.id

        WHERE users.division IN ($divPlaceholders)

        AND
        (
        users.full_name LIKE ?
        OR users.enrollment_no LIKE ?
        OR internships.company_name LIKE ?
        OR internships.mini_project_title LIKE ?
        )

        ORDER BY
            CASE users.division
                WHEN 'IT-DA'   THEN 0
                WHEN 'IT-SMAD' THEN 1
                ELSE 2
            END ASC,
            users.division ASC,
            CAST(users.roll_no AS UNSIGNED) ASC,
            users.full_name ASC
    ";

    $stmt = $conn->prepare($query);

    $types = str_repeat('s', count($faculty_divisions)) . 'ssss';
    $like = '%' . $search . '%';
    $params = array_merge($faculty_divisions, [$like, $like, $like, $like]);

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
}

?>

<!DOCTYPE html>

<html>

<head>

<title>Internship Certificates</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?php include __DIR__ . '/includes/sidebar_head.php'; ?>

<style>

body{
background:#f5f5f5;
margin:0;
}

.page-inner{
padding:0 24px 40px;
}

.container-box{

width:100%;
margin:auto;
margin-top:24px;
background:#fff;
padding:30px;
border-radius:15px;
box-shadow:0 2px 10px rgba(0,0,0,.12);

}

.table th,
.table td{

vertical-align:middle;
white-space:normal;
word-wrap:break-word;
overflow-wrap:break-word;

}

/* SIMPLIFIED (scrolling/layout cleanup): this table previously had no
   width control at all, plus a min-width:260px on the Mini Project Title
   column — on narrower screens that forced the table wider than its box,
   producing a horizontal scrollbar. table-layout:fixed + width:100% on
   the table, together with percentage column widths (see the <th> tags)
   that sum to exactly 100%, means the table can never render wider than
   its container, so it no longer needs to scroll horizontally. Vertical
   scrolling (if the list is long) is untouched. */
.table{
table-layout:fixed;
width:100%;
}

.table-responsive{
overflow-x:hidden;
}

.table td .badge,
.table td .btn-sm,
.table td .badge-updated{
white-space:normal;
word-break:break-word;
}

.badge-updated{

background:#0d6efd;
color:white;
padding:6px 10px;
border-radius:6px;
font-size:12px;

}

.project-title{

font-weight:600;
color:#0d6efd;

}

</style>

</head>

<body>

<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="page-inner">
<div class="container-box">

<h2 class="mb-2">

Internship Certificates

</h2>

<h5 class="mb-4">

Division: <?php echo htmlspecialchars($faculty_division); ?>

</h5>

<form method="GET" class="mb-4">

<div class="row g-3">

<div class="col-md-5">

<input
type="text"
name="search"
class="form-control"
placeholder="Search Student / Enrollment / Company / Project"
value="<?php echo htmlspecialchars($search); ?>">

</div>

<div class="col-md-2">

<button class="btn btn-primary w-100">

Search

</button>

</div>

<div class="col-md-2">

<a href="view_reports.php"
class="btn btn-secondary w-100">

Reset

</a>

</div>

</div>

</form>

<div class="table-responsive">

<table class="table table-bordered table-striped align-middle">

<thead class="table-dark">

<tr>

<th style="width:7%;">Roll No</th>

<th style="width:14%;">Student</th>

<th style="width:12%;">Enrollment No</th>

<th style="width:8%;">Division</th>

<th style="width:12%;">Company</th>

<th style="width:27%;">Mini Project Title</th>

<th style="width:12%;">Certificate</th>

<th style="width:8%;">Status</th>

</tr>

</thead>

<tbody>

<?php

if($result && mysqli_num_rows($result)>0)
{

while($row=mysqli_fetch_assoc($result))
{

?>

<tr>

<td>

<?php echo htmlspecialchars($row['roll_no']); ?>

</td>

<td>

<?php echo htmlspecialchars($row['full_name']); ?>

</td>

<td>

<?php echo htmlspecialchars($row['enrollment_no']); ?>

</td>

<td>

<?php echo htmlspecialchars($row['division']); ?>

</td>

<td>

<?php echo htmlspecialchars($row['company_name']); ?>

</td>

<td>

<?php

if(!empty($row['mini_project_title']))
{

echo '<span class="project-title">'
.htmlspecialchars($row['mini_project_title']).
'</span>';

}
else
{

echo '<span class="text-muted">-</span>';

}

?>

</td>

<td>

<?php

if(!empty($row['certificate_file']))
{

?>

<a
href="../uploads/certificates/<?php echo urlencode($row['division']); ?>/<?php echo rawurlencode(basename($row['certificate_file'])); ?>"
target="_blank"
class="btn btn-success btn-sm">

View Certificate

</a>

<?php

}
else
{

echo "-";

}

?>

</td>

<td>

<?php

if(!empty($row['updated_at']))
{

echo "<span class='badge-updated'>
Updated Certificate
</span>";

}
else
{

echo "<span class='badge bg-success'>
Uploaded
</span>";

}

?>

</td>

</tr>

<?php

}

}
else

{

?>

<tr>

<td colspan="8" class="text-center text-danger">

No certificates uploaded.

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

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>

</body>

</html>