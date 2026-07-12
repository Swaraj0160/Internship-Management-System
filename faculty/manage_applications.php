<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty')
{
    header("Location: ../login.php");
    exit();
}

$faculty_id = $_SESSION['user_id'];

$faculty_query = "SELECT * FROM users
                  WHERE id='$faculty_id'";

$faculty_result = mysqli_query($conn, $faculty_query);

$faculty = mysqli_fetch_assoc($faculty_result);

$faculty_division = $faculty['faculty_division'];

$search = "";
$status_filter = "";
$type_filter = "";

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

/* MAIN TABLE QUERY */
$query = "

SELECT
    li.*,
    users.full_name,
    users.enrollment_no,
    users.division,
    users.roll_no

FROM users

JOIN (
    $latest_internship_sql
) li
ON li.student_id = users.id

WHERE users.division='$faculty_division'

";

if(isset($_GET['search']) && $_GET['search'] != "")
{
    $search = mysqli_real_escape_string(
        $conn,
        $_GET['search']
    );

    $query .= " AND (
                    users.full_name LIKE '%$search%'
                    OR li.company_name LIKE '%$search%'
                    OR users.enrollment_no LIKE '%$search%'
                    OR li.internship_type LIKE '%$search%'
                )";
}

if(isset($_GET['status']) && $_GET['status'] != "")
{
    $status_filter = $_GET['status'];

    $query .= " AND li.status='$status_filter'";
}

if(isset($_GET['internship_type']) && $_GET['internship_type'] != "")
{
    $type_filter = mysqli_real_escape_string(
        $conn,
        trim($_GET['internship_type'])
    );

    $query .= "
    AND TRIM(li.internship_type)
        = '$type_filter'
    ";
}

$query .= " ORDER BY
            CAST(TRIM(users.roll_no) AS UNSIGNED) ASC,
            users.full_name ASC";

$result = mysqli_query($conn, $query);

/* COUNT QUERY - SAME LOGIC */
$count_query = "

SELECT COUNT(*) as total

FROM users

JOIN (
    $latest_internship_sql
) li
ON li.student_id = users.id

WHERE users.division='$faculty_division'

";

$count_result = mysqli_query($conn, $count_query);

$count_row = mysqli_fetch_assoc($count_result);

$total_records = $count_row['total'];


/* INTERNSHIP TYPE COUNTS - SAME LATEST RECORD LOGIC */

$total_applications = mysqli_num_rows(mysqli_query($conn,"
SELECT li.id
FROM users
JOIN (
    $latest_internship_sql
) li
ON li.student_id = users.id
WHERE users.division='$faculty_division'
"));

$problem_count = mysqli_num_rows(mysqli_query($conn,"
SELECT li.id
FROM users
JOIN (
    $latest_internship_sql
) li
ON li.student_id = users.id
WHERE users.division='$faculty_division'
AND li.status='Approved'
AND li.internship_type='Problem-based Internship'
"));

$training_count = mysqli_num_rows(mysqli_query($conn,"
SELECT li.id
FROM users
JOIN (
    $latest_internship_sql
) li
ON li.student_id = users.id
WHERE users.division='$faculty_division'
AND li.status='Approved'
AND li.internship_type='Training + Mini Project'
"));

$realworld_count = mysqli_num_rows(mysqli_query($conn,"
SELECT li.id
FROM users
JOIN (
    $latest_internship_sql
) li
ON li.student_id = users.id
WHERE users.division='$faculty_division'
AND li.status='Approved'
AND li.internship_type='Real-world Exposure Internship (Field Work)'
"));

$research_count = mysqli_num_rows(mysqli_query($conn,"
SELECT li.id
FROM users
JOIN (
    $latest_internship_sql
) li
ON li.student_id = users.id
WHERE users.division='$faculty_division'
AND li.status='Approved'
AND li.internship_type='Research Internship'
"));

$international_count = mysqli_num_rows(mysqli_query($conn,"
SELECT li.id
FROM users
JOIN (
    $latest_internship_sql
) li
ON li.student_id = users.id
WHERE users.division='$faculty_division'
AND li.status='Approved'
AND li.internship_type='International Internship / Visit'
"));



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


<style>

body{

    background:#eef3f9;

    font-family:'Poppins',sans-serif;

}

.container-box{
    width:99%;
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

<div class="container-box">

<div class="page-header">

    <div class="d-flex justify-content-between align-items-center flex-wrap">

        <div>

            <a href="dashboard.php" class="btn btn-outline-primary mb-3">
                <i class="bi bi-arrow-left"></i>
                Back to Dashboard
            </a>

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
                <?php echo $faculty_division; ?>

            </span>

        </div>

    </div>

</div>

<div class="row g-3 mb-4">

    <div class="col-md-2">
    <div class="type-card bg-dark">
        <h6>Total Applications</h6>
        <h3><?php echo $total_applications; ?></h3>
    </div>
</div>

    <div class="col-md-2">
        <div class="type-card bg-primary">
            <h6>Problem Based</h6>
            <h3><?php echo $problem_count; ?></h3>
        </div>
    </div>

    <div class="col-md-2">
        <div class="type-card bg-success">
            <h6>Training + Project</h6>
            <h3><?php echo $training_count; ?></h3>
        </div>
    </div>

    <div class="col-md-2">
        <div class="type-card bg-info">
            <h6>Real World</h6>
            <h3><?php echo $realworld_count; ?></h3>
        </div>
    </div>

    <div class="col-md-2">
        <div class="type-card bg-warning text-dark">
            <h6>Research</h6>
            <h3><?php echo $research_count; ?></h3>
        </div>
    </div>

    <div class="col-md-2">
        <div class="type-card bg-danger">
            <h6>International</h6>
            <h3><?php echo $international_count; ?></h3>
        </div>
    </div>

</div>

<form method="GET" class="row mb-4 g-3">

<div class="col-md-4">

<input type="text"
       name="search"
       class="form-control"
       placeholder="Search Student / Company / Enrollment / Internship Type"
       value="<?php echo $search; ?>">

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

<div class="table-responsive" style="overflow-x:hidden;">

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
<?php echo $row['full_name']; ?>
</td>

<td>
<?php echo $row['enrollment_no']; ?>
</td>

<td>
<?php echo $row['division']; ?>
</td>

<td>
<?php echo $row['roll_no']; ?>
</td>

<td>
<?php echo $row['company_name']; ?>
</td>

<td>
<?php echo $row['company_contact']; ?>
</td>

<td>
<?php echo $row['internship_role']; ?>
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

?>

<a href="../uploads/offer_letters/<?php echo $row['division']; ?>/<?php echo $row['offer_letter']; ?>"
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
<?php echo $row['mentor_name']; ?>
</td>

<td>
<?php echo $row['internship_type']; ?>
</td>

<td class="rejection-box">

<?php

if($row['rejection_reason'] == "")
{
    echo "-";
}
else
{
    echo $row['rejection_reason'];
}

?>

</td>

<td>

<?php

if($row['status'] == "Pending")
{
?>

<div class="action-buttons">

<a href="approve.php?id=<?php echo $row['id']; ?>
&search=<?php echo urlencode($search); ?>
&status=<?php echo urlencode($status_filter); ?>
&internship_type=<?php echo urlencode($type_filter); ?>"
class="btn btn-success btn-sm">

Approve

</a>

<a href="reject_internship.php?id=<?php echo $row['id']; ?>
&search=<?php echo urlencode($search); ?>
&status=<?php echo urlencode($status_filter); ?>
&internship_type=<?php echo urlencode($type_filter); ?>"
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

<a href="undo_status.php?id=<?php echo $row['id']; ?>
&search=<?php echo urlencode($search); ?>
&status=<?php echo urlencode($status_filter); ?>
&internship_type=<?php echo urlencode($type_filter); ?>"
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

<a href="undo_status.php?id=<?php echo $row['id']; ?>
&search=<?php echo urlencode($search); ?>
&status=<?php echo urlencode($status_filter); ?>
&internship_type=<?php echo urlencode($type_filter); ?>"
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

</body>
</html>