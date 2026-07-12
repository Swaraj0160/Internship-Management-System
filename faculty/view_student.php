<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty')
{
    header("Location: ../login.php");
    exit();
}

$student_id = $_GET['id'];

$student_query = "SELECT *
                  FROM users
                  WHERE id='$student_id'";

$student_result = mysqli_query($conn, $student_query);

$student = mysqli_fetch_assoc($student_result);

$weekly_query = "SELECT weekly_updates.*,
                        internships.company_name
                 FROM weekly_updates
                 JOIN internships
                 ON weekly_updates.internship_id = internships.id
                 WHERE internships.student_id='$student_id'";

$weekly_result = mysqli_query($conn, $weekly_query);

$final_query = "SELECT final_reports.*,
                       internships.company_name
                FROM final_reports
                JOIN internships
                ON final_reports.internship_id = internships.id
                WHERE internships.student_id='$student_id'";

$final_result = mysqli_query($conn, $final_query);

$feedback_query = "SELECT feedback.*,
                          internships.company_name
                   FROM feedback
                   JOIN internships
                   ON feedback.internship_id = internships.id
                   WHERE internships.student_id='$student_id'";

$feedback_result = mysqli_query($conn, $feedback_query);

?>

<!DOCTYPE html>
<html>
<head>

<title>Student Details</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>

body{
    background:#f5f5f5;
}

.container-box{
    width:95%;
    margin:auto;
    margin-top:30px;
    background:white;
    padding:30px;
    border-radius:15px;
}

</style>

</head>

<body>

<div class="container-box">

<h2>
    <?php echo $student['full_name']; ?>
</h2>

<p>
    Enrollment No:
    <?php echo $student['enrollment_no']; ?>
</p>

<p>
    Division:
    <?php echo $student['division']; ?>
</p>

<hr>

<h3 class="mb-3">
    Weekly Reports
</h3>

<table class="table table-bordered">

<thead class="table-dark">

<tr>

<th>Company</th>
<th>Week</th>
<th>Work Done</th>
<th>File</th>

</tr>

</thead>

<tbody>

<?php

while($row = mysqli_fetch_assoc($weekly_result))
{

?>

<tr>

<td><?php echo $row['company_name']; ?></td>

<td><?php echo $row['week_no']; ?></td>

<td><?php echo $row['work_done']; ?></td>

<td>

<a href="../uploads/weekly_reports/<?php echo $row['update_file']; ?>"
target="_blank">

View File

</a>

</td>

</tr>

<?php

}

?>

</tbody>

</table>

<hr>

<h3 class="mb-3">
    Final Reports
</h3>

<table class="table table-bordered">

<thead class="table-dark">

<tr>

<th>Company</th>
<th>Report</th>
<th>Certificate</th>
<th>PPT</th>

</tr>

</thead>

<tbody>

<?php

while($row = mysqli_fetch_assoc($final_result))
{

?>

<tr>

<td><?php echo $row['company_name']; ?></td>

<td>

<a href="../uploads/final_reports/<?php echo $row['report_file']; ?>" target="_blank">
View Report
</a>

</td>

<td>

<a href="../uploads/certificates/<?php echo $row['certificate_file']; ?>" target="_blank">
View Certificate
</a>

</td>

<td>

<a href="../uploads/ppt/<?php echo $row['ppt_file']; ?>" target="_blank">
View PPT
</a>

</td>

</tr>

<?php

}

?>

</tbody>

</table>

<hr>

<h3 class="mb-3">
    Feedback
</h3>

<table class="table table-bordered">

<thead class="table-dark">

<tr>

<th>Company</th>
<th>Week</th>
<th>Remarks</th>
<th>Marks</th>

</tr>

</thead>

<tbody>

<?php

while($row = mysqli_fetch_assoc($feedback_result))
{

?>

<tr>

<td><?php echo $row['company_name']; ?></td>

<td><?php echo $row['week_no']; ?></td>

<td><?php echo $row['remarks']; ?></td>

<td><?php echo $row['marks']; ?></td>

</tr>

<?php

}

?>

</tbody>

</table>

</div>

</body>
</html>