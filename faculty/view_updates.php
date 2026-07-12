<?php

session_start();
include("../config.php");

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty') {
    header("Location: ../login.php");
    exit();
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function getGraceDeadlineTimestamp(?string $deadlineDate): ?int
{
    if (empty($deadlineDate)) {
        return null;
    }

    return strtotime($deadlineDate);
}

function getWeeklyStatusLabel(
    ?string $submittedAt,
    ?string $deadlineDate,
    ?string $rejectedAt = null
): array
{
    if(empty($submittedAt))
    {
        return [
            'label' => 'No Submission',
            'class' => 'secondary'
        ];
    }

    $submissionTime = strtotime($submittedAt);

    if($submissionTime === false)
    {
        return [
            'label' => 'No Submission',
            'class' => 'secondary'
        ];
    }

    if(empty($deadlineDate))
{
    return [
        'label' => 'On Time',
        'class' => 'success'
    ];
}

    $deadline = strtotime($deadlineDate);

    /* If report was rejected give 24hr resubmission */

    if(!empty($rejectedAt))
    {
        $deadline =
        strtotime($rejectedAt) + 86400;
    }

    if($submissionTime <= $deadline)
    {
        return [
            'label' => 'On Time',
            'class' => 'success'
        ];
    }

    return [
        'label' => 'Late',
        'class' => 'danger'
    ];
}

$faculty_id = $_SESSION['user_id'];

$faculty_query = "SELECT faculty_division,
       full_name
FROM users
WHERE id='$faculty_id'";

$faculty_result = mysqli_query($conn, $faculty_query);
$faculty_data = mysqli_fetch_assoc($faculty_result);

$faculty_division = $faculty_data['faculty_division'];

$search = "";
$week_filter = "";
$status_filter = "";
$return_query = "";

if (isset($_GET['search'])) {
    $return_query .= "&search=" . urlencode($_GET['search']);
}

if (isset($_GET['week'])) {
    $return_query .= "&week=" . urlencode($_GET['week']);
}

if (isset($_GET['status'])) {
    $return_query .= "&status=" . urlencode($_GET['status']);
}

/* SEARCH */

if (isset($_GET['search'])) {
    $search = mysqli_real_escape_string(
        $conn,
        $_GET['search']
    );
}

/* WEEK FILTER */

if (isset($_GET['week'])) {
    $week_filter = mysqli_real_escape_string(
        $conn,
        $_GET['week']
    );
}

if (isset($_GET['status'])) {
    $status_filter = mysqli_real_escape_string(
        $conn,
        $_GET['status']
    );
}

/* SAVE FACULTY FEEDBACK */

if (isset($_POST['save_feedback'])) {
    $update_id = $_POST['update_id'];

    $faculty_feedback = mysqli_real_escape_string(
        $conn,
        $_POST['faculty_feedback']
    );

    $update_query = "UPDATE weekly_updates
                     SET faculty_feedback='$faculty_feedback'
                     WHERE id='$update_id'";

    mysqli_query($conn, $update_query);

    echo "
    <script>
        alert('Feedback Saved Successfully');
        window.location='view_updates.php';
    </script>
    ";
}

/* ACCEPT REPORT */

if (isset($_POST['accept_report'])) {
    $update_id = (int)$_POST['update_id'];

    $faculty_feedback = mysqli_real_escape_string(
        $conn,
        $_POST['faculty_feedback']
    );

    mysqli_query(
        $conn,
        "UPDATE weekly_updates
         SET review_status='Accepted',
             faculty_feedback='$faculty_feedback'
         WHERE id='$update_id'"
    );

    /*
    GET REPORT DETAILS
    */
    $reportQuery = mysqli_query(
        $conn,
        "SELECT *
         FROM weekly_updates
         WHERE id='$update_id'"
    );

    $report = mysqli_fetch_assoc($reportQuery);

    $internship_id = $report['internship_id'];
    $week_no = $report['week_no'];
    $submitted_at = strtotime($report['submitted_at']);

    /*
    GET STUDENT ID
    */
    $internshipQuery = mysqli_query(
        $conn,
        "SELECT student_id
         FROM internships
         WHERE id='$internship_id'"
    );

    $internshipData = mysqli_fetch_assoc($internshipQuery);
    $student_id = $internshipData['student_id'];

    /*
    GET DEADLINE
    */
    /* GET STUDENT DIVISION */

$studentQuery = mysqli_query(
    $conn,
    "SELECT division
     FROM users
     WHERE id='$student_id'"
);

$studentData = mysqli_fetch_assoc($studentQuery);

$student_division = $studentData['division'];

/* GET CORRECT DEADLINE */

$deadlineQuery = mysqli_query(
    $conn,
    "SELECT deadline_date
     FROM weekly_deadlines
     WHERE week_no='$week_no'
     AND division='$student_division'
     LIMIT 1"
);

$deadlineData = mysqli_fetch_assoc($deadlineQuery);
    $marks = 10;

    if ($deadlineData) {
        $deadline_ts =
strtotime($deadlineData['deadline_date']);

if(!empty($report['rejected_at']))
{
    $effective_deadline =
    strtotime($report['rejected_at']) + 86400;
}
else
{
    $effective_deadline =
    $deadline_ts;
}

$seconds_late =
$submitted_at - $effective_deadline;

$days_late =
ceil($seconds_late / 86400);

if($days_late <= 0)
{
    $marks = 10;
}
elseif($days_late == 1)
{
    $marks = 9;
}
elseif($days_late == 2)
{
    $marks = 8;
}
elseif($days_late == 3)
{
    $marks = 7;
}
else
{
    $marks = 5;
}
    }

    /*
    CREATE EVALUATION ROW IF NOT EXISTS
    */
    $checkEval = mysqli_query(
        $conn,
        "SELECT *
         FROM student_evaluation
         WHERE student_id='$student_id'"
    );

    if (mysqli_num_rows($checkEval) == 0) {
        mysqli_query(
            $conn,
            "INSERT INTO student_evaluation
            (
                student_id
            )
            VALUES
            (
                '$student_id'
            )"
        );
    }

    /*
    UPDATE WEEK MARK
    */
    $weekColumn = "week" . $week_no . "_marks";

    mysqli_query(
        $conn,
        "UPDATE student_evaluation
         SET $weekColumn='$marks'
         WHERE student_id='$student_id'"
    );

    /*
    RECALCULATE TOTALS
    */
    mysqli_query(
        $conn,
        "UPDATE student_evaluation
         SET
         weekly_total =
         week1_marks +
         week2_marks +
         week3_marks +
         week4_marks,

         total_marks =
(
    week1_marks +
    week2_marks +
    week3_marks +
    week4_marks +
    ROUND((final_evaluation * 10) / 20)
)

         WHERE student_id='$student_id'"
    );

    echo "
<script>
    alert('Weekly Report Accepted');
    window.location='view_updates.php?success=accepted{$return_query}';
</script>
";
    exit();
}

/* REJECT REPORT */

if (isset($_POST['reject_report'])) {
    $update_id = (int)$_POST['update_id'];

    $faculty_feedback = mysqli_real_escape_string(
        $conn,
        $_POST['faculty_feedback']
    );

    mysqli_query(
        $conn,
        "UPDATE weekly_updates
SET review_status='Rejected',
    faculty_feedback='$faculty_feedback',
    rejected_at=NOW()
WHERE id='$update_id'"
    );

    echo "
<script>
    alert('Weekly Report Rejected');
    window.location='view_updates.php?success=rejected{$return_query}';
</script>
";

    exit();
}

/* UNDO REPORT */

if (isset($_POST['undo_report'])) {
    $update_id = (int)$_POST['update_id'];

    $reportQuery = mysqli_query(
        $conn,
        "SELECT *
         FROM weekly_updates
         WHERE id='$update_id'"
    );

    $report = mysqli_fetch_assoc($reportQuery);

    $internship_id = $report['internship_id'];
    $week_no = $report['week_no'];

    /*
    GET STUDENT ID
    */
    $internshipQuery = mysqli_query(
        $conn,
        "SELECT student_id
         FROM internships
         WHERE id='$internship_id'"
    );

    $internshipData = mysqli_fetch_assoc($internshipQuery);
    $student_id = $internshipData['student_id'];

    /*
    REMOVE WEEK MARKS
    */
    $weekColumn = "week" . $week_no . "_marks";

    mysqli_query(
        $conn,
        "UPDATE student_evaluation
         SET $weekColumn='0'
         WHERE student_id='$student_id'"
    );

    /*
    RECALCULATE TOTALS
    */
    mysqli_query(
        $conn,
        "UPDATE student_evaluation
         SET
         weekly_total =
         week1_marks +
         week2_marks +
         week3_marks +
         week4_marks,

         total_marks =
(
    week1_marks +
    week2_marks +
    week3_marks +
    week4_marks +
    ROUND((final_evaluation * 10) / 20)
)
         WHERE student_id='$student_id'"
    );

    /*
    RESET REPORT STATUS
    */
    mysqli_query(
        $conn,
        "UPDATE weekly_updates
SET review_status='Pending',
    rejected_at=NULL
WHERE id='$update_id'"
    );

    echo "
<script>
    alert('Report Status Undone');
    window.location='view_updates.php?success=undone{$return_query}';
</script>
";

    exit();
}

/* MAIN QUERY */

$query = "

SELECT

weekly_updates.*,

internships.company_name,

users.full_name,
users.enrollment_no,
users.division,
users.roll_no

FROM weekly_updates

JOIN internships
ON weekly_updates.internship_id = internships.id

JOIN users
ON internships.student_id = users.id

WHERE users.division='$faculty_division'

AND
(
    users.full_name LIKE '%$search%'
    OR
    users.enrollment_no LIKE '%$search%'
    OR
    internships.company_name LIKE '%$search%'
)

";

/* APPLY WEEK FILTER */

if ($week_filter != "") {
    $query .= "
    AND weekly_updates.week_no='$week_filter'
    ";
}

/* APPLY REVIEW STATUS FILTER */

if ($status_filter != "") {
    $query .= "
    AND weekly_updates.review_status='$status_filter'
    ";
}

/* SORT BY ROLL NUMBER */

$query .= "
ORDER BY CAST(users.roll_no AS UNSIGNED) ASC
";

$result = mysqli_query($conn, $query);
$summary_query = "

SELECT
COUNT(*) AS total_reports,

SUM(
CASE
WHEN review_status='Accepted'
THEN 1
ELSE 0
END
) AS accepted_count,

SUM(
CASE
WHEN review_status='Rejected'
THEN 1
ELSE 0
END
) AS rejected_count,

SUM(
CASE
WHEN review_status='Pending'
THEN 1
ELSE 0
END
) AS pending_count

FROM weekly_updates

JOIN internships
ON weekly_updates.internship_id = internships.id

JOIN users
ON internships.student_id = users.id

WHERE users.division='$faculty_division'

";

if($week_filter != "")
{
    $summary_query .= "
    AND weekly_updates.week_no='$week_filter'
    ";
}

$summary_result =
mysqli_query($conn,$summary_query);

$summary =
mysqli_fetch_assoc($summary_result);

?>

<!DOCTYPE html>
<html>

<head>

<title>Student Weekly Reports</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>

body{
    background:#eef2f7;
    font-family:'Segoe UI',sans-serif;
}

.container-box{
    width:98%;
    margin:auto;
    margin-top:20px;
    background:#fff;
    padding:25px;
    border-radius:20px;
    box-shadow:0 10px 30px rgba(0,0,0,0.08);
}

/* Header */

.page-title{
    font-size:38px;
    font-weight:700;
    color:#0f172a;
}

.page-subtitle{
    color:#64748b;
    font-size:15px;
}

/* Filter Box */

.filter-box{
    background:white;
    padding:20px;
    border-radius:16px;
    box-shadow:0 4px 20px rgba(0,0,0,0.05);
    margin-bottom:25px;
}

.form-control,
.form-select{
    height:50px;
    border-radius:12px;
    border:1px solid #dbe4ee;
}

.form-control:focus,
.form-select:focus{
    box-shadow:none;
    border-color:#2563eb;
}

/* Summary Cards */

.stat-card{
    border:none;
    border-radius:18px;
    overflow:hidden;
    transition:0.3s;
    color:white;
}

.stat-card:hover{
    transform:translateY(-5px);
}

.stat-card:hover{
    transform:translateY(-4px);
}

.stat-number{
    font-size:34px;
    font-weight:700;
}

.stat-title{
    font-size:14px;
    color:rgba(255,255,255,0.9);
    margin-bottom:8px;
    font-weight:500;
}

/* Table */

.table{
    border-radius:16px;
    overflow:hidden;
}

.table thead{
    background:#0f172a;
    color:white;
}

.table th{
    padding:14px;
    font-size:13px;
    position:sticky;
    top:0;
    z-index:10;
}

.table td{
    vertical-align:top;
    font-size:13px;
    padding:12px;
}

/* Badges */

.badge{
    padding:8px 12px;
    border-radius:30px;
    font-size:12px;
    font-weight:600;
}

/* Feedback */

.feedback-box{
    width:100%;
    min-width:160px;
    height:80px;
    border-radius:12px;
    border:1px solid #dbe4ee;
    resize:none;
}

.feedback-box:focus{
    outline:none;
    border-color:#2563eb;
}

/* Buttons */

.btn{
    border-radius:10px;
}

.btn-primary{
    background:#2563eb;
    border:none;
}

.btn-primary:hover{
    background:#1d4ed8;
}

.small-btn{
    font-size:12px;
    padding:6px 10px;
}

/* Work Preview */

.work-preview{
    max-height:90px;
    overflow:hidden;
    line-height:1.7;
}

.work-preview.expanded{
    max-height:none;
}

.read-more{
    color:#2563eb;
    font-weight:600;
    cursor:pointer;
}

.read-more:hover{
    text-decoration:underline;
}

.table tbody tr{
    transition:0.2s;
}

.table tbody tr:hover{
    background:#f8fafc;
}

.division-badge{
    background:linear-gradient(135deg,#4338ca,#2563eb);
    color:white;
    padding:8px 16px;
    border-radius:10px;
    font-size:13px;
    font-weight:600;
    box-shadow:0 4px 12px rgba(37,99,235,0.25);
}

</style>

</head>

<body>

<div class="container-box">
    
    <a href="dashboard.php" class="btn btn-primary">
            ← Back
        </a>

<div class="d-flex justify-content-between align-items-start mb-4">

    <div class="d-flex align-items-center gap-3">


        <div>

            <h1 class="page-title mb-1">
                Student Weekly Reports
            </h1>

            <div class="page-subtitle">
                Manage internship weekly submissions and evaluations
            </div>

        </div>

    </div>

    <div class="division-badge">
        Division: <?php echo h($faculty_division); ?>
    </div>

</div>

<div class="filter-box">

<form method="GET">
<div class="row g-3">

<div class="col-md-3">
<input type="text"
       name="search"
       class="form-control"
       placeholder="Search Student / Enrollment / Company"
       value="<?php echo h($search); ?>">

</div>

<div class="col-md-2">

<select name="week"
        class="form-select">

<option value="">
    Select Week
</option>

<?php
for ($i = 1; $i <= 4; $i++) {
?>
<option value="<?php echo $i; ?>"
<?php
if ($week_filter == $i) {
    echo "selected";
}
?>
>
Week <?php echo $i; ?>
</option>
<?php
}
?>

</select>

</div>
<!-- STATUS DROPDOWN START -->

<div class="col-md-2">
    
<select name="status" class="form-select">

<option value="">
All Status
</option>

<option value="Pending"
<?php if($status_filter=='Pending') echo 'selected'; ?>>
Pending
</option>

<option value="Accepted"
<?php if($status_filter=='Accepted') echo 'selected'; ?>>
Accepted
</option>

<option value="Rejected"
<?php if($status_filter=='Rejected') echo 'selected'; ?>>
Rejected
</option>

</select>

</div>

<div class="col-md-2">
<button type="submit"
        class="btn btn-primary w-100">

Search

</button>

</div>

<div class="col-md-2">
<a href="view_updates.php"
   class="btn btn-secondary w-100">

Reset

</a>

</div>

</div>

</form>

</div>

<div class="row mb-4">

<div class="col-md-3">

<div class="card stat-card bg-primary">
<div class="card-body text-center">
<div class="stat-title">📄 Total Reports</div>
<div class="stat-number">
<?php echo $summary['total_reports']; ?>
</div>
</div>
</div>

</div>

<div class="col-md-3">

<div class="card stat-card bg-success">
<div class="card-body text-center">
<div class="stat-title">✅ Accepted</div>
<div class="stat-number">
<?php echo $summary['accepted_count']; ?>
</div>
</div>
</div>

</div>

<div class="col-md-3">

<div class="card stat-card bg-danger">
<div class="card-body text-center">
<div class="stat-title">❌ Rejected</div>
<div class="stat-number">
<?php echo $summary['rejected_count']; ?>
</div>
</div>
</div>

</div>

<div class="col-md-3">

<div class="card stat-card bg-warning">
<div class="card-body text-center">
<div class="stat-title">⏳ Pending</div>
<div class="stat-number">
<?php echo $summary['pending_count']; ?>
</div>
</div>
</div>

</div>

</div>

<div class="table-responsive shadow-sm rounded">
        
<table class="table table-bordered table-striped">

<thead>
    
<tr>

<th style="width:60px;">Roll</th>
<th style="width:130px;">Student</th>
<th style="width:150px;">Enrollment</th>
<th style="width:100px;">Company</th>
<th style="width:70px;">Week</th>
<th style="width:300px;">Work Done</th>
<th style="width:150px;">Submission</th>
<th style="width:90px;">Status</th>
<th style="width:100px;">Review</th>
<th style="width:90px;">File</th>
<th style="width:220px;">Faculty Feedback</th>
<th style="width:80px;">Action</th>

</tr>

</thead>

<tbody>

<?php
if (mysqli_num_rows($result) > 0) {
    while ($row = mysqli_fetch_assoc($result)) {
        $deadline_query = "SELECT deadline_date
                   FROM weekly_deadlines
                   WHERE week_no='" . mysqli_real_escape_string($conn, $row['week_no']) . "'
                   AND division='" . mysqli_real_escape_string($conn, $row['division']) . "'
                   LIMIT 1";

        $deadline_result = mysqli_query($conn, $deadline_query);
        $deadline_data = mysqli_fetch_assoc($deadline_result);

        $statusInfo = getWeeklyStatusLabel(
    $row['submitted_at'] ?? null,
    $deadline_data['deadline_date'] ?? null,
    $row['rejected_at'] ?? null
);
?>

<tr>

<td>
<?php echo h($row['roll_no']); ?>
</td>

<td>
<?php echo h($row['full_name']); ?>
</td>

<td>
<?php echo h($row['enrollment_no']); ?>
</td>

<td>
<?php echo h($row['company_name']); ?>
</td>

<td>
Week <?php echo h($row['week_no']); ?>
</td>

<td class="work-column">

<div class="work-preview"
     id="work_<?php echo (int)$row['id']; ?>">

    <?php echo nl2br(h($row['work_done'])); ?>

</div>

<span class="read-more"
      onclick="toggleWork(<?php echo (int)$row['id']; ?>, this)">

Read More

</span>

</td>

<td>

<?php

if ($row['submitted_at'] != '') {
    echo date(
        "d M Y h:i A",
        strtotime($row['submitted_at'])
    );
} else {
    echo "-";
}

?>

</td>

<td>

<span class="badge bg-<?php echo h($statusInfo['class']); ?>">
    <?php echo h($statusInfo['label']); ?>
</span>

</td>

<td>

<?php

if ($row['review_status'] == 'Accepted') {
    echo "<span class='badge bg-success'>
            Accepted
          </span>";
} elseif ($row['review_status'] == 'Rejected') {
    echo "<span class='badge bg-danger'>
            Rejected
          </span>";
} else {
    echo "<span class='badge bg-warning text-dark'>
            Pending
          </span>";
}

?>

</td>

<td>

<?php

if ($row['update_file'] != '') {
?>

<a href="../uploads/weekly_reports/<?php echo h($row['update_file']); ?>"
   target="_blank"
   class="btn btn-primary btn-sm small-btn">

View File

</a>

<?php
} else {
    echo "-";
}
?>

</td>

<td>

<form method="POST">

<textarea name="faculty_feedback"
          class="form-control feedback-box"
          placeholder="Enter feedback"><?php echo h($row['faculty_feedback']); ?></textarea>

<input type="hidden"
       name="update_id"
       value="<?php echo (int)$row['id']; ?>">

</td>

<td>

<div class="d-flex flex-column gap-1">

<?php

if ($row['review_status'] == 'Pending') {
?>

<button type="submit"
        name="accept_report"
        class="btn btn-success btn-sm small-btn">
Accept
</button>

<button type="submit"
        name="reject_report"
        class="btn btn-danger btn-sm small-btn">
Reject
</button>

<?php
} else {
?>

<button type="submit"
        name="undo_report"
        class="btn btn-warning btn-sm small-btn">
Undo
</button>

<?php
}
?>

</div>

</form>

</td>

</tr>

<?php
    }
} else {
?>

<tr>

<td colspan="12"
    class="text-center text-danger">

No weekly reports found.

</td>

</tr>

<?php
}
?>

</tbody>

</table>
    
</div>    

</div>

<script>

function toggleWork(id, element)
{
    let workBox =
        document.getElementById(
            "work_" + id
        );

    if(workBox.classList.contains("expanded"))
    {
        workBox.classList.remove("expanded");
        element.innerHTML = "Read More";
    }
    else
    {
        workBox.classList.add("expanded");
        element.innerHTML = "Show Less";
    }
}

</script>

<script>

document.addEventListener("submit", function()
{
    localStorage.setItem(
        "faculty_scroll_position",
        window.scrollY
    );
});

window.onload = function()
{
    let pos =
    localStorage.getItem(
        "faculty_scroll_position"
    );

    if(pos)
    {
        window.scrollTo(0, pos);
    }
};

</script>
</body>
</html>