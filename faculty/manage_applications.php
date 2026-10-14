<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty')
{
    header("Location: ../login.php");
    exit();
}

/* -----------------------------------------------------------------
   REPLACE — Phase 2: sidebar propagation + fixes found while
   rewiring this page:

   1. Ordering bug: the old query used
      FIELD(users.division, 'IT-DA', 'IT-SMAD') ASC — MySQL's FIELD()
      returns 0 for any value NOT in the list and 1/2 for matches, so
      ASC actually sorted every OTHER division (SY-1..22, value 0)
      BEFORE IT-DA and IT-SMAD (values 1 and 2) — the exact opposite
      of "IT-DA first, IT-SMAD second". Replaced with the same
      explicit CASE ordering used everywhere else in the project.
   2. SQL injection: $_GET['status'] was concatenated directly into
      the query ("AND li.status='$status_filter'"). Converted the
      whole filter chain to a prepared statement.
   3. XSS: every $row[...] value (name, enrollment_no, division,
      roll_no, company, contact, role, mentor, internship_type,
      rejection_reason) and the offer_letter path were echoed with no
      escaping. All now go through htmlspecialchars() /
      rawurlencode() for the file path.

   Dual-division logic (faculty_divisions with legacy fallback),
   stat cards, filters, and Approve/Reject/Undo action links are
   otherwise unchanged.
   ----------------------------------------------------------------- */

$faculty_id = (int)$_SESSION['user_id'];

$faculty_query = "SELECT * FROM users WHERE id='$faculty_id'";
$faculty_result = mysqli_query($conn, $faculty_query);
$faculty = mysqli_fetch_assoc($faculty_result);

/* Get all divisions assigned to this faculty account. */
$faculty_divisions = [];

$division_query = "SELECT division
                   FROM faculty_divisions
                   WHERE faculty_id='$faculty_id'
                   ORDER BY division ASC";

$division_result = mysqli_query($conn, $division_query);

if($division_result)
{
    while($division_row = mysqli_fetch_assoc($division_result))
    {
        $division_value = trim($division_row['division']);

        if($division_value !== '' && !in_array($division_value, $faculty_divisions, true))
        {
            $faculty_divisions[] = $division_value;
        }
    }
}

/* Fallback for older faculty accounts still using users.faculty_division. */
if(empty($faculty_divisions) && !empty($faculty['faculty_division']))
{
    $faculty_divisions[] = trim($faculty['faculty_division']);
}

$faculty_division = implode(', ', $faculty_divisions);

if(empty($faculty_divisions))
{
    $faculty_divisions = ['__none__']; // guarantees the IN() below matches nothing
}

$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$type_filter = trim($_GET['internship_type'] ?? '');

/* ONE COMMON LATEST-INTERNSHIP SUBQUERY */
$latest_internship_sql = "
    SELECT i.*
    FROM internships i
    INNER JOIN (
        SELECT student_id, MAX(id) AS max_id
        FROM internships
        GROUP BY student_id
    ) latest
        ON latest.student_id = i.student_id
       AND latest.max_id = i.id
";

$divPlaceholders = implode(',', array_fill(0, count($faculty_divisions), '?'));
$divTypes = str_repeat('s', count($faculty_divisions));

$where = ["users.division IN ($divPlaceholders)"];
$params = $faculty_divisions;
$types = $divTypes;

if($search !== '')
{
    $where[] = "(users.full_name LIKE ? OR li.company_name LIKE ? OR users.enrollment_no LIKE ? OR li.internship_type LIKE ?)";
    $like = "%" . $search . "%";
    array_push($params, $like, $like, $like, $like);
    $types .= "ssss";
}

if($status_filter !== '')
{
    $where[] = "li.status=?";
    $params[] = $status_filter;
    $types .= "s";
}

if($type_filter !== '')
{
    $where[] = "TRIM(li.internship_type)=?";
    $params[] = $type_filter;
    $types .= "s";
}

$whereSql = implode(" AND ", $where);

$query = "
    SELECT
        li.*,
        users.full_name,
        users.enrollment_no,
        users.division,
        users.roll_no

    FROM users

    JOIN ($latest_internship_sql) li
        ON li.student_id = users.id

    WHERE $whereSql

    ORDER BY
        CASE users.division
            WHEN 'IT-DA'   THEN 0
            WHEN 'IT-SMAD' THEN 1
            ELSE 2
        END ASC,
        users.division ASC,
        CAST(TRIM(users.roll_no) AS UNSIGNED) ASC
";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

/* COUNT + TYPE-BREAKDOWN QUERIES — same division scoping, no user input */
function facultyAppCount(mysqli $conn, string $latestSql, array $divisions, string $extraSql = ''): int
{
    $divPlaceholders = implode(',', array_fill(0, count($divisions), '?'));
    $types = str_repeat('s', count($divisions));
    $sql = "
        SELECT COUNT(*) AS total
        FROM users
        JOIN ($latestSql) li ON li.student_id = users.id
        WHERE users.division IN ($divPlaceholders)
        $extraSql
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$divisions);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (int)($row['total'] ?? 0);
}

$total_applications = facultyAppCount($conn, $latest_internship_sql, $faculty_divisions);
$problem_count = facultyAppCount($conn, $latest_internship_sql, $faculty_divisions, "AND li.status='Approved' AND li.internship_type='Problem-based Internship'");
$training_count = facultyAppCount($conn, $latest_internship_sql, $faculty_divisions, "AND li.status='Approved' AND li.internship_type='Training + Mini Project'");
$realworld_count = facultyAppCount($conn, $latest_internship_sql, $faculty_divisions, "AND li.status='Approved' AND li.internship_type='Real-world Exposure Internship (Field Work)'");
$research_count = facultyAppCount($conn, $latest_internship_sql, $faculty_divisions, "AND li.status='Approved' AND li.internship_type='Research Internship'");
$international_count = facultyAppCount($conn, $latest_internship_sql, $faculty_divisions, "AND li.status='Approved' AND li.internship_type='International Internship / Visit'");

$activePage = 'manage_applications.php';

?>


<!DOCTYPE html>
<html>
<head>

<title>Manage Applications</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
rel="stylesheet">

<link rel="stylesheet"
href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">


<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<?php include __DIR__ . '/includes/sidebar_head.php'; ?>


<style>

body{

    background:#eef3f9;

    font-family:'Poppins',sans-serif;
    margin:0;

}

.page-inner{
    padding:0 20px 40px;
}

.container-box{
    width:100%;
    margin:auto;
    margin-top:20px;
    background:white;
    padding:20px;
    border-radius:15px;
    box-shadow:0px 0px 10px rgba(0,0,0,0.1);
}

/* TABLE */

.table{
    width:100%;
    font-size:12px;
    table-layout:auto;
    vertical-align:middle;
}

.table th{
    white-space:nowrap;
    text-align:center;
    vertical-align:middle;
    padding:6px;
    font-size:12px;
}

.table td{
    padding:6px;
    vertical-align:middle;
    word-break:break-word;
}

/* STATUS BADGES */

.badge{
    font-size:10px;
    padding:4px 8px;
}

/* ACTION BUTTONS */

.action-buttons{
    display:flex;
    flex-direction:column;
    gap:4px;
}

.action-buttons .btn{
    font-size:11px;
    padding:3px 6px;
}

/* SMALL UNDO BUTTON */

.text-center .btn-warning.btn-sm,
.text-center .btn-secondary.btn-sm{
    font-size:10px;
    padding:4px 8px;
    width:55px;
    min-width:55px;
    border-radius:6px;
}

/* REJECTION REASON */

.rejection-box{
    max-width:150px;
    font-size:11px;
    word-wrap:break-word;
}

/* INTERNSHIP COUNT CARDS */

.type-card{
    border-radius:12px;
    padding:12px;
    text-align:center;
    color:white;
    font-weight:bold;
    height:100%;
}

.type-card h6{
    font-size:12px;
    margin-bottom:8px;
}

.type-card h3{
    font-size:32px;
    margin:0;
}

/* COMPACT COLUMN WIDTHS */

.table th:nth-child(1),
.table td:nth-child(1){
    width:130px;
}

.table td{
    padding:6px;
    vertical-align:middle;
    word-break:break-word;
}

.table th:nth-child(5),
.table td:nth-child(5){
    width:120px;
}

.table th:nth-child(7),
.table td:nth-child(7){
    width:120px;
}

.table th:nth-child(10),
.table td:nth-child(10){
    width:120px;
}

.table th:nth-child(11),
.table td:nth-child(11){
    width:140px;
}

.table th:nth-child(12),
.table td:nth-child(12){
    width:140px;
}

.table th:nth-child(13),
.table td:nth-child(13){
    width:90px;
}

/* LINKS */

a{
    font-size:12px;
}

/* MOBILE */

@media(max-width:1200px){

    .table{
        font-size:11px;
    }

    .table th,
    .table td{
        padding:4px;
    }

    .type-card h3{
        font-size:24px;
    }
}

@media(max-width:576px){
    .row.g-3.mb-4 > div{
        margin-bottom:8px;
    }
}

.page-header{

    background:#fff;

    padding:30px;

    border-radius:18px;

    margin-bottom:30px;

    box-shadow:0 10px 25px rgba(0,0,0,.08);

}

.page-title{

    font-size:34px;

    font-weight:700;

    color:#1f2937;

    margin-bottom:5px;

}

.page-subtitle{

    color:#6b7280;

    font-size:15px;

    margin:0;

}

.division-badge{

    background:#2563eb;

    color:white;

    padding:12px 22px;

    border-radius:50px;

    font-size:15px;

    font-weight:600;

    display:inline-flex;

    align-items:center;

    gap:8px;

}

.btn-outline-primary{

    border-radius:12px;

    padding:10px 18px;

    font-weight:600;
}

</style>

</head>

<body>

<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="page-inner">
<div class="container-box">

<div class="page-header">

    <div class="d-flex justify-content-between align-items-center flex-wrap">

        <div>

            <h2 class="page-title">
                Internship Applications
            </h2>

            <p class="page-subtitle">
                Manage and review student internship applications.
            </p>

        </div>

        <div>

            <span class="division-badge">

                <i class="bi bi-mortarboard-fill"></i>

                Division :
                <?php echo htmlspecialchars($faculty_division, ENT_QUOTES, 'UTF-8'); ?>

            </span>

        </div>

    </div>

</div>

<div class="row g-3 mb-4">

    <div class="col-md-2">
    <div class="type-card bg-dark">
        <h6>Total Applications</h6>
        <h3><?php echo (int)$total_applications; ?></h3>
    </div>
</div>

    <div class="col-md-2">
        <div class="type-card bg-primary">
            <h6>Problem Based</h6>
            <h3><?php echo (int)$problem_count; ?></h3>
        </div>
    </div>

    <div class="col-md-2">
        <div class="type-card bg-success">
            <h6>Training + Project</h6>
            <h3><?php echo (int)$training_count; ?></h3>
        </div>
    </div>

    <div class="col-md-2">
        <div class="type-card bg-info">
            <h6>Real World</h6>
            <h3><?php echo (int)$realworld_count; ?></h3>
        </div>
    </div>

    <div class="col-md-2">
        <div class="type-card bg-warning text-dark">
            <h6>Research</h6>
            <h3><?php echo (int)$research_count; ?></h3>
        </div>
    </div>

    <div class="col-md-2">
        <div class="type-card bg-danger">
            <h6>International</h6>
            <h3><?php echo (int)$international_count; ?></h3>
        </div>
    </div>

</div>

<form method="GET" class="row mb-4 g-3">

<div class="col-md-4">

<input type="text"
       name="search"
       class="form-control"
       placeholder="Search Student / Company / Enrollment / Internship Type"
       value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">

</div>

<div class="col-md-2">

<select name="status"
        class="form-control">

<option value="">All Status</option>

<option value="Pending"
<?php if($status_filter=="Pending") echo "selected"; ?>>
Pending
</option>

<option value="Approved"
<?php if($status_filter=="Approved") echo "selected"; ?>>
Approved
</option>

<option value="Rejected"
<?php if($status_filter=="Rejected") echo "selected"; ?>>
Rejected
</option>

</select>

</div>

<div class="col-md-3">

<select name="internship_type"
        class="form-control">

<option value="">All Internship Types</option>

<option value="Problem-based Internship"
<?php if($type_filter=="Problem-based Internship") echo "selected"; ?>>
Problem-based Internship
</option>

<option value="Training + Mini Project"
<?php if($type_filter=="Training + Mini Project") echo "selected"; ?>>
Training + Mini Project
</option>

<option value="Real-world Exposure Internship (Field Work)"
<?php if($type_filter=="Real-world Exposure Internship (Field Work)") echo "selected"; ?>>
Real-world Exposure Internship (Field Work)
</option>

<option value="Research Internship"
<?php if($type_filter=="Research Internship") echo "selected"; ?>>
Research Internship
</option>

<option value="International Internship / Visit"
<?php if($type_filter=="International Internship / Visit") echo "selected"; ?>>
International Internship / Visit
</option>

</select>

</div>

<div class="col-md-1">

<button type="submit"
        class="btn btn-primary w-100">

Search

</button>

</div>

<div class="col-md-2">

<a href="manage_applications.php"
class="btn btn-secondary w-100">

Reset

</a>

</div>

</form>

<div class="table-responsive" style="overflow-x:auto;">

<table class="table table-bordered table-hover align-middle">

<thead class="table-dark">

<tr>

<th>Student</th>
<th>Enrollment No</th>
<th>Division</th>
<th>Roll No</th>
<th>Company</th>
<th>Company Contact</th>
<th>Role</th>
<th>Status</th>
<th>Offer Letter</th>
<th>Mentor Name</th>
<th>Internship Type</th>
<th>Rejection Reason</th>
<th>Action</th>

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

<td>
<?php echo htmlspecialchars($row['company_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
</td>

<td>
<?php echo htmlspecialchars($row['company_contact'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
</td>

<td>
<?php echo htmlspecialchars($row['internship_role'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
</td>

<td>

<?php

if($row['status'] == "Approved")
{
    echo "<span class='badge bg-success'>
          Approved
          </span>";
}
else if($row['status'] == "Rejected")
{
    echo "<span class='badge bg-danger'>
          Rejected
          </span>";
}
else
{
    echo "<span class='badge bg-warning text-dark'>
          Pending
          </span>";
}

?>

</td>

<td>

<?php

if($row['offer_letter'] != "")
{
    $safeDivision = rawurlencode($row['division']);
    $safeOfferLetter = rawurlencode($row['offer_letter']);
?>

<a href="../uploads/offer_letters/<?php echo $safeDivision; ?>/<?php echo $safeOfferLetter; ?>"
target="_blank">

View File

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
<?php echo htmlspecialchars($row['mentor_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
</td>

<td>
<?php echo htmlspecialchars($row['internship_type'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
</td>

<td class="rejection-box">

<?php

if($row['rejection_reason'] == "")
{
    echo "-";
}
else
{
    echo htmlspecialchars($row['rejection_reason'], ENT_QUOTES, 'UTF-8');
}

?>

</td>

<td>

<?php

$rowId = (int)$row['id'];
$qs = "search=" . urlencode($search) . "&status=" . urlencode($status_filter) . "&internship_type=" . urlencode($type_filter);

if($row['status'] == "Pending")
{
?>

<div class="action-buttons">

<a href="approve.php?id=<?php echo $rowId; ?>&<?php echo $qs; ?>"
class="btn btn-success btn-sm">

Approve

</a>

<a href="reject_internship.php?id=<?php echo $rowId; ?>&<?php echo $qs; ?>"
class="btn btn-danger btn-sm">
Reject

</a>

</div>

<?php

}
else if($row['status'] == "Approved")
{
?>

<div class="text-center">

<a href="undo_status.php?id=<?php echo $rowId; ?>&<?php echo $qs; ?>"
class="btn btn-warning btn-sm">

Undo

</a>

</div>

<?php

}
else if($row['status'] == "Rejected")
{
?>

<div class="text-center">

<a href="undo_status.php?id=<?php echo $rowId; ?>&<?php echo $qs; ?>"
class="btn btn-secondary btn-sm">

Undo

</a>

</div>

<?php

}

?>

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

document.addEventListener("click", function(e)
{
    if(
        e.target.closest(".btn-success") ||
        e.target.closest(".btn-danger") ||
        e.target.closest(".btn-warning") ||
        e.target.closest(".btn-secondary")
    )
    {
        localStorage.setItem(
            "applications_scroll_position",
            window.scrollY
        );
    }
});

window.onload = function()
{
    let pos =
    localStorage.getItem(
        "applications_scroll_position"
    );

    if(pos)
    {
        window.scrollTo(0, pos);
    }
};

</script>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>

</body>
</html>
