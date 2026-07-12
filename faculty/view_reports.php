<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty')
{
    header("Location: ../login.php");
    exit();
}

$faculty_id = $_SESSION['user_id'];

$faculty_query = "SELECT faculty_division
                  FROM users
                  WHERE id='$faculty_id'";

$faculty_result = mysqli_query($conn,$faculty_query);

$faculty_data = mysqli_fetch_assoc($faculty_result);

$faculty_division = $faculty_data['faculty_division'];

$search="";

if(isset($_GET['search']))
{
    $search=mysqli_real_escape_string($conn,$_GET['search']);
}

$query="

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

WHERE users.division='$faculty_division'

AND
(
users.full_name LIKE '%$search%'
OR users.enrollment_no LIKE '%$search%'
OR internships.company_name LIKE '%$search%'
OR internships.mini_project_title LIKE '%$search%'
)

ORDER BY CAST(users.roll_no AS UNSIGNED)

";

$result=mysqli_query($conn,$query);

?>

<!DOCTYPE html>

<html>

<head>

<title>Internship Certificates</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>

body{
background:#f5f5f5;
}

.container-box{

width:95%;
margin:auto;
margin-top:40px;
background:#fff;
padding:30px;
border-radius:15px;
box-shadow:0 2px 10px rgba(0,0,0,.12);

}

.table th,
.table td{

vertical-align:middle;

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

<div class="container-box">

<a href="dashboard.php" class="btn btn-primary mb-4">

← Back

</a>

<h2 class="mb-4">

Internship Certificates

</h2>

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

<th>Roll No</th>

<th>Student</th>

<th>Enrollment No</th>

<th>Division</th>

<th>Company</th>

<th style="min-width:260px;">Mini Project Title</th>

<th>Certificate</th>

<th>Status</th>

</tr>

</thead>

<tbody>

<?php

if(mysqli_num_rows($result)>0)
{

while($row=mysqli_fetch_assoc($result))
{

?>

<tr>

<td>

<?php echo $row['roll_no']; ?>

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
href="../uploads/certificates/<?php echo urlencode($row['division']); ?>/<?php echo basename($row['certificate_file']); ?>"
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

</body>

</html>