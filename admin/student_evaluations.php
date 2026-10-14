<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    header("Location: ../login.php");
    exit();
}

/* -----------------------------------------------------------------
   REPLACE — sidebar propagation pilot + two correctness fixes found
   while wiring this page into the shared sidebar:

   1. SQL injection: the division filter was concatenated directly
      into the query ("... AND users.division='$division'") with the
      raw $_GET value. Converted to a prepared statement.
   2. XSS: every $row[...] value was echoed with no escaping
      (division, enrollment_no, roll_no, full_name, week marks).
      All output now goes through htmlspecialchars().

   Ordering: the old REPLACE(UPPER(division),'SY-','') trick casts
   "IT-DA" and "IT-SMAD" both to 0 (non-numeric strings cast to 0 in
   MySQL), so the two IT divisions were tied/unordered against each
   other. Replaced with the same explicit
   IT-DA-first / IT-SMAD-second / SY-* CASE ordering used everywhere
   else in the project.

   The evaluation marks formula itself is UNCHANGED:
   Total = (weekly_total + mentor_evaluation + final_evaluation) / 2,
   displayed out of 50 — exactly as before.
   ----------------------------------------------------------------- */

require_once __DIR__ . '/../lib/EvaluationDataProvider.php';

$division = trim($_GET['division'] ?? '');

/* Section J (data-quality audit): reuse the shared provider purely to
   detect malformed marks values (e.g. "Oct-50") for the divisions
   currently in view, so they can be reported to the admin here
   instead of silently showing as 0 with no explanation. This does
   NOT replace the query below, which still drives the on-screen
   table exactly as before. */
$eval_warnings = EvaluationDataProvider::getRows($conn, $division !== '' ? [$division] : [])['warnings'];

$where = ["users.role='student'"];
$params = [];
$types = "";

if($division !== '')
{
    $where[] = "users.division=?";
    $params[] = $division;
    $types .= "s";
}

$whereSql = implode(" AND ", $where);

$query = "
    SELECT
        users.id,
        users.division,
        users.enrollment_no,
        users.roll_no,
        users.full_name,

        student_evaluation.week1_marks,
        student_evaluation.week2_marks,
        student_evaluation.week3_marks,
        student_evaluation.week4_marks,
        student_evaluation.weekly_total,
        student_evaluation.mentor_evaluation,
        student_evaluation.final_evaluation,
        student_evaluation.total_marks

    FROM users

    LEFT JOIN student_evaluation
        ON users.id = student_evaluation.student_id

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

    return number_format((float)$value,1);
}

$activePage = 'student_evaluations.php';

?>
<!DOCTYPE html>
<html>
<head>

<title>Student Evaluations</title>

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

</style>

</head>

<body>

<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="page-inner">
<div class="container-box">

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">

    <h3 class="fw-bold m-0">
        Student Evaluations
    </h3>

    <div class="d-flex gap-2">

        <!-- REPLACE (post-implementation audit): removed the separate
             "Print Evaluation Sheet" (window.print()) button. Per your
             instruction, window.print() must not remain a PDF
             generation path at all. "Download PDF" below (the same
             export_student_evaluations_pdf.php dedicated PDF exporter)
             is now the single, authoritative PDF path — it produces a
             real, downloadable .pdf, not a browser print dialog. -->

        <a href="export_student_evaluations.php<?php echo ($division!=='') ? '?division='.urlencode($division) : ''; ?>"
           class="btn btn-primary">

            📊 Export Excel

        </a>

        <a href="export_student_evaluations_pdf.php<?php echo ($division!=='') ? '?division='.urlencode($division) : ''; ?>"
           class="btn btn-danger">

            📄 Download PDF

        </a>

    </div>

</div>

<?php if (!empty($eval_warnings)): ?>
<div class="alert alert-danger" role="alert">
    <strong>⚠ Data Quality Warning:</strong>
    <?php echo count($eval_warnings); ?> evaluation value(s) in the database could not be read as numbers and are being shown as 0 below and in exports until corrected. These are NOT deleted or overwritten — please correct them directly for the affected student(s):
    <ul class="mb-0 mt-2">
        <?php foreach ($eval_warnings as $w): ?>
            <li>
                <?php echo htmlspecialchars($w['student'], ENT_QUOTES, 'UTF-8'); ?>
                (<?php echo htmlspecialchars($w['enrollment_no'], ENT_QUOTES, 'UTF-8'); ?>,
                <?php echo htmlspecialchars($w['division'], ENT_QUOTES, 'UTF-8'); ?>)
                — <?php echo htmlspecialchars($w['field'], ENT_QUOTES, 'UTF-8'); ?>:
                database has <code><?php echo htmlspecialchars($w['raw_value'], ENT_QUOTES, 'UTF-8'); ?></code>, expected a number.
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="GET" class="row mb-4">

<div class="col-md-3">

<select name="division"
        class="form-control">

<option value="">
    All Divisions
</option>

<?php

$divisions = mysqli_query(
    $conn,
    "SELECT DISTINCT division
     FROM users
     WHERE role='student'
     ORDER BY
         CASE division
             WHEN 'IT-DA'   THEN 0
             WHEN 'IT-SMAD' THEN 1
             ELSE 2
         END ASC,
         division ASC"
);

while($d = mysqli_fetch_assoc($divisions))
{
?>

<option value="<?php echo htmlspecialchars($d['division'], ENT_QUOTES, 'UTF-8'); ?>"
<?php if($division===$d['division']) echo "selected"; ?>>

<?php echo htmlspecialchars($d['division'], ENT_QUOTES, 'UTF-8'); ?>

</option>

<?php
}
?>

</select>

</div>


<div class="col-md-2">

<button class="btn btn-success">
Filter
</button>

</div>

</form>

<div class="table-responsive">

<table class="table table-bordered table-striped">

<thead class="table-dark">

<tr>

<th>Division</th>
<th>Enrollment No</th>
<th>Roll No</th>
<th>Student Name</th>

<th>W1</th>
<th>W2</th>
<th>W3</th>
<th>W4</th>

<th>Week Total (40)</th>
<th>Mentor Eval (40)</th>
<th>Final Eval (20)</th>
<th>Total (50)</th>


</tr>

</thead>

<tbody>

<?php

while($row = mysqli_fetch_assoc($result))
{

?>

<tr>

<td>
<?php echo htmlspecialchars($row['division'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
</td>

<td>
<?php echo htmlspecialchars($row['enrollment_no'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
</td>

<td>
<?php echo htmlspecialchars($row['roll_no'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
</td>

<td>
<?php echo htmlspecialchars($row['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
</td>

<td>
<?php $v = formatMarks($row['week1_marks']); echo $v !== null ? htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') : "-"; ?>
</td>

<td>
<?php $v = formatMarks($row['week2_marks']); echo $v !== null ? htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') : "-"; ?>
</td>

<td>
<?php $v = formatMarks($row['week3_marks']); echo $v !== null ? htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') : "-"; ?>
</td>

<td>
<?php $v = formatMarks($row['week4_marks']); echo $v !== null ? htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') : "-"; ?>
</td>

<td>
<?php $v = formatMarks($row['weekly_total']); echo $v !== null ? htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') : "-"; ?>
</td>

<td>
<?php $v = formatMarks($row['mentor_evaluation']); echo $v !== null ? htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') : "-"; ?>
</td>

<td>
<?php $v = formatMarks($row['final_evaluation']); echo $v !== null ? htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') : "-"; ?>
</td>

<td>

<?php

$weekly_disp = ($row['weekly_total'] !== null && $row['weekly_total'] !== '') ? (float)$row['weekly_total'] : 0;
$mentor_disp = ($row['mentor_evaluation'] !== null && $row['mentor_evaluation'] !== '') ? (float)$row['mentor_evaluation'] : 0;
$final_disp  = ($row['final_evaluation'] !== null && $row['final_evaluation'] !== '') ? (float)$row['final_evaluation'] : 0;

/*
TOTAL OUT OF 100
(Weekly 40 + Mentor 40 + Final 20)
DISPLAY OUT OF 50
*/

$total_disp = ($weekly_disp + $mentor_disp + $final_disp) / 2;

echo htmlspecialchars(formatMarks($total_disp) . " / 50", ENT_QUOTES, 'UTF-8');

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

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>

</body>
</html>