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
student_evaluation.final_evaluation

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

$total_students = mysqli_num_rows($result);

function formatMarks($value)
{
    if((float)$value == (int)$value)
    {
        return (int)$value;
    }

    return number_format((float)$value,1);
}

header("Content-Type: application/vnd.ms-excel");
if($division != "")
{
    $filename = "Student_Evaluation_" . $division . ".xls";
}
else
{
    $filename = "Student_Evaluations_All_Divisions.xls";
}

header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

?>
<html>

<head>

<meta charset="UTF-8">

<style>

table{
border-collapse:collapse;
width:100%;
}

th,td{
border:1px solid #000;
padding:6px;
text-align:center;
font-size:12px;
}

th{
background:#d9d9d9;
font-weight:bold;
}

.title{
font-size:18px;
font-weight:bold;
text-align:center;
}

.subtitle{
font-size:14px;
text-align:center;
}

.left{
text-align:left;
}

</style>

</head>

<body>

<table>

<tr>
<td colspan="12" class="title">
MIT Art, Design and Technology University
</td>
</tr>

<tr>
<td colspan="12" class="subtitle">
Student Internship Evaluation Sheet
</td>
</tr>

<tr>
<td colspan="12" class="left">

<b>Division :</b>

<?php
echo ($division=="") ? "All Divisions" : $division;
?>

</td>
</tr>

<tr><td colspan="12"></td></tr>

<tr>

<th>Division</th>
<th>Enrollment No</th>
<th>Roll No</th>
<th>Student Name</th>

<th>Week-1</th>
<th>Week-2</th>
<th>Week-3</th>
<th>Week-4</th>

<th>Week Total (40)</th>

<th>Mentor Evaluation (40)</th>

<th>Final Evaluation (20)</th>

<th>Total (50)</th>

</tr>
<?php

while($row = mysqli_fetch_assoc($result))
{

    $week1 = ($row['week1_marks'] != "") ? $row['week1_marks'] : 0;
    $week2 = ($row['week2_marks'] != "") ? $row['week2_marks'] : 0;
    $week3 = ($row['week3_marks'] != "") ? $row['week3_marks'] : 0;
    $week4 = ($row['week4_marks'] != "") ? $row['week4_marks'] : 0;

    $weekly_total = ($row['weekly_total'] != "") ? $row['weekly_total'] : 0;

    $mentor = ($row['mentor_evaluation'] != "") ? $row['mentor_evaluation'] : 0;

    $final = ($row['final_evaluation'] != "") ? $row['final_evaluation'] : 0;

    /*
    SAME LOGIC AS FACULTY PAGE
    (40 + 40 + 20) / 2 = 50
    */

    $total = ($weekly_total + $mentor + $final) / 2;

    echo "<tr>";

    echo "<td>".$row['division']."</td>";

    echo "<td>".$row['enrollment_no']."</td>";

    echo "<td>".$row['roll_no']."</td>";

    echo "<td style='text-align:left;'>".$row['full_name']."</td>";

    echo "<td>".$week1."</td>";

    echo "<td>".$week2."</td>";

    echo "<td>".$week3."</td>";

    echo "<td>".$week4."</td>";

    echo "<td>".$weekly_total."</td>";

    echo "<td>".$mentor."</td>";

    echo "<td>".$final."</td>";

    echo "<td>".formatMarks($total)." / 50</td>";

    echo "</tr>";

}

?>
</table>

<br><br>

<table style="border:none; width:100%;">

<tr>

<td style="border:none; text-align:left;">

<b>Generated On :</b>

<?php echo date("d-m-Y h:i A"); ?>

</td>

<td style="border:none; text-align:right;">

<b>Total Students :</b>

<?php echo $total_students; ?>

</td>

</tr>

</table>

</body>

</html>