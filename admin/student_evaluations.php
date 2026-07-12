<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    header("Location: ../login.php");
    exit();
}

$division = isset($_GET['division']) ? $_GET['division'] : '';

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

WHERE users.role='student'
";

if($division != '')
{
    $query .= " AND users.division='$division'";
}



$query .= "
ORDER BY
CAST(
REPLACE(
UPPER(users.division),
'SY-',
''
) AS UNSIGNED
) ASC,
CAST(users.roll_no AS UNSIGNED) ASC
";
$result = mysqli_query($conn,$query);
function formatMarks($value)
{
    if((float)$value == (int)$value)
    {
        return (int)$value;
    }

    return number_format((float)$value,1);
}

?>
<!DOCTYPE html>
<html>
<head>

<title>Student Evaluations</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>

body{
    background:#f5f5f5;
}

.container-box{
    width:98%;
    margin:auto;
    margin-top:20px;
    background:white;
    padding:20px;
    border-radius:15px;
}

</style>

</head>

<body>

<div class="container-box">

<div class="d-flex justify-content-between align-items-center mb-4">

    <a href="dashboard.php"
       class="btn btn-primary">
        ← Back
    </a>

    <h3 class="fw-bold m-0">
        Student Evaluations
    </h3>

    <div class="d-flex gap-2">

        <button
        type="button"
        onclick="window.print()"
        class="btn btn-success">

        🖨️ Print Evaluation Sheet

        </button>

        <a href="export_student_evaluations.php<?php echo ($division!='') ? '?division='.$division : ''; ?>"
           class="btn btn-primary">

            📊 Export Excel

        </a>

    </div>

</div>

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
 ORDER BY CAST(
     REPLACE(
         UPPER(division),
         'SY-',
         ''
     ) AS UNSIGNED
 ) ASC"
);

while($d = mysqli_fetch_assoc($divisions))
{
?>

<option value="<?php echo $d['division']; ?>"
<?php if($division==$d['division']) echo "selected"; ?>>

<?php echo $d['division']; ?>

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

$evaluated =
(
$row['week1_marks'] !== NULL
||
$row['week2_marks'] !== NULL
||
$row['week3_marks'] !== NULL
||
$row['week4_marks'] !== NULL
||
$row['mentor_evaluation'] !== NULL
||
$row['final_evaluation'] !== NULL
);

?>

<tr>

<td>
<?php echo $row['division']; ?>
</td>

<td>
<?php echo $row['enrollment_no']; ?>
</td>

<td>
<?php echo $row['roll_no']; ?>
</td>

<td>
<?php echo $row['full_name']; ?>
</td>

<td>
<?php echo ($row['week1_marks']!="") ? $row['week1_marks'] : "-"; ?>
</td>

<td>
<?php echo ($row['week2_marks']!="") ? $row['week2_marks'] : "-"; ?>
</td>

<td>
<?php echo ($row['week3_marks']!="") ? $row['week3_marks'] : "-"; ?>
</td>

<td>
<?php echo ($row['week4_marks']!="") ? $row['week4_marks'] : "-"; ?>
</td>

<td>
<?php echo ($row['weekly_total']!="") ? $row['weekly_total'] : "-"; ?>
</td>

<td>
<?php echo ($row['mentor_evaluation']!="") ? $row['mentor_evaluation'] : "-"; ?>
</td>

<td>
<?php echo ($row['final_evaluation']!="") ? $row['final_evaluation'] : "-"; ?>
</td>

<td>

<?php

$weekly_disp =
($row['weekly_total'] != "")
? $row['weekly_total']
: 0;

$mentor_disp =
($row['mentor_evaluation'] != "")
? $row['mentor_evaluation']
: 0;

$final_disp =
($row['final_evaluation'] != "")
? $row['final_evaluation']
: 0;

/*
TOTAL OUT OF 100
(Weekly 40 + Mentor 40 + Final 20)
DISPLAY OUT OF 50
*/

$total_disp =
($weekly_disp + $mentor_disp + $final_disp) / 2;

echo formatMarks($total_disp)." / 50";

?>

</td>


</tr>

<?php
}
?>

</tbody>

</table>

</div>

</body>
</html>
