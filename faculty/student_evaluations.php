<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty')
{
    header("Location: ../login.php");
    exit();
}

$faculty_id = $_SESSION['user_id'];

$faculty_query = "
SELECT faculty_division,
       full_name
FROM users
WHERE id='$faculty_id'
";

$faculty_result = mysqli_query($conn,$faculty_query);
$faculty_data = mysqli_fetch_assoc($faculty_result);

$faculty_division = $faculty_data['faculty_division'];

/* SAVE MARKS */

if(isset($_POST['save_marks']))
{
    foreach($_POST['final'] as $student_id => $final_raw)
{
    $student_id = (int)$student_id;

    $eval = mysqli_fetch_assoc(
    mysqli_query(
        $conn,
        "SELECT *
         FROM student_evaluation
         WHERE student_id='$student_id'"
    )
);

$week1 = $eval['week1_marks'] ?? 0;
$week2 = $eval['week2_marks'] ?? 0;
$week3 = $eval['week3_marks'] ?? 0;
$week4 = $eval['week4_marks'] ?? 0;

$mentor_raw =
$_POST['mentor'][$student_id] ?? 0;

$final_raw = (int)$final_raw;
$mentor_raw = (int)$mentor_raw;

if($final_raw < 0) $final_raw = 0;
if($final_raw > 20) $final_raw = 20;

if($mentor_raw < 0) $mentor_raw = 0;
if($mentor_raw > 40) $mentor_raw = 40;

$weekly_total =
$week1 +
$week2 +
$week3 +
$week4;

/*
TOTAL OUT OF 100
(Week Total 40 + Mentor Evaluation 40 + Final Evaluation 20)
SCALED DOWN TO 50
*/

$total_marks =
round(
    ($weekly_total + $mentor_raw + $final_raw) / 2
);

        $check = mysqli_query(
            $conn,
            "SELECT *
             FROM student_evaluation
             WHERE student_id='$student_id'"
        );

        if(mysqli_num_rows($check) > 0)
        {
            mysqli_query(
                $conn,
                "UPDATE student_evaluation
                 SET
                 week1_marks='$week1',
                 week2_marks='$week2',
                 week3_marks='$week3',
                 week4_marks='$week4',
                 weekly_total='$weekly_total',
                 mentor_evaluation='$mentor_raw',
                 final_evaluation='$final_raw',
                 total_marks='$total_marks'
                 WHERE student_id='$student_id'"
            );
        }
        else
        {
            mysqli_query(
                $conn,
                "INSERT INTO student_evaluation
                (
                    student_id,
                    week1_marks,
                    week2_marks,
                    week3_marks,
                    week4_marks,
                    weekly_total,
                    mentor_evaluation,
                    final_evaluation,
                    total_marks
                )
                VALUES
                (
                    '$student_id',
                    '$week1',
                    '$week2',
                    '$week3',
                    '$week4',
                    '$weekly_total',
                    '$mentor_raw',
                    '$final_raw',
                    '$total_marks'
                )"
            );
        }
    }

    echo "
    <script>
    alert('Marks Saved Successfully');
    window.location='student_evaluations.php';
    </script>
    ";
}

/* FETCH STUDENTS */

$query = "

SELECT

users.id,
users.roll_no,
users.full_name,
users.enrollment_no,

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
AND users.division='$faculty_division'

ORDER BY CAST(users.roll_no AS UNSIGNED) ASC

";

$result = mysqli_query($conn,$query);

/*
FORMAT A MARKS VALUE FOR DISPLAY
SHOWS A DECIMAL ONLY WHEN THE VALUE IS NOT A WHOLE NUMBER
(e.g. 15 -> "15", 7.5 -> "7.5")
*/

function formatMarks($value)
{
    if((float)$value == (int)$value)
    {
        return (int)$value;
    }

    return number_format((float)$value, 1);
}

?>

<!DOCTYPE html>
<html>

<head>

<title>Student Evaluation</title>

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

table{
    font-size:12px;
}

select,
input[type=number]
{
    min-width:55px;
}

/* HEADER */

.print-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:8px;
}

.college-section{
    flex:1;
    text-align:center;
}

.college-section h2{
    font-size:24px;
    margin:0;
    font-weight:bold;
}

.college-section h4{
    font-size:16px;
    margin:0;
}

.faculty-section{
    min-width:250px;
    text-align:right;
    font-size:13px;
}

.college-logo{
    width:160px;
    height:auto;
}

.college-section{
    flex:1;
    text-align:center;
}

.college-section h2{
    font-size:14px;
}

.college-section h4{
    font-size:10px;
}

.faculty-section{
    min-width:250px;
    text-align:right;
    font-size:13px;
}

/* FOOTER */

.footer-print{
    display:none;
}
.print-only{
    display:none;
}
.footer-left,
.footer-right{
    margin-bottom:2px;
}

@page{
    margin:10mm;
}

/* PRINT SETTINGS */

@media print
{
    h1.mb-4{
    display:none;
}

tbody::after{
    content:"";
    display:block;
    height:50px;
}

select{
    border:none !important;
    background:none !important;
    appearance:none !important;
    -webkit-appearance:none !important;
    -moz-appearance:none !important;
    text-align:center;
}

input[type=number]{
    border:none !important;
    background:none !important;
    text-align:center;
}
    
    select{
    appearance:none !important;
    -webkit-appearance:none !important;
    -moz-appearance:none !important;
}
    
.table{
    margin-bottom:0 !important;
}

hr{
    margin:3px 0 !important;
}
    
    tbody tr{
        page-break-inside: auto;
    }
    
@page{
    size:A4 landscape;
    margin:10mm 10mm 30mm 10mm;
}
    thead{
        display:table-header-group;
    }

    tr{
        page-break-inside:auto;
    }


    body{
        background:white;
        font-size:7px;
    }

    .btn{
        display:none !important;
    }
    
    .no-print{
    display:none !important;
}

.print-only{
    display:inline !important;
    font-weight:bold;
}

    .container-box{
        width:100%;
        margin:0;
        padding:0;
        box-shadow:none;
    }

    .college-logo{
        width:120px;
        height:auto;
    }

    .college-section h2{
        font-size:16px;
    }

    .college-section h4{
        font-size:12px;
    }

    .faculty-section{
        font-size:10px;
    }

table{
    font-size:9.6px !important;
    margin-bottom:20px !important;
}
    
table th,
table td{
    padding:0px 2px !important;
    line-height:0.9;
}

   select,
input[type=number]
{
    width:40px !important;
    min-width:40px !important;
}
    
.footer-print{
    display:none !important;
    position:fixed;
    bottom:0;
    left:0;
    right:0;
    font-size:8px;

    border-top:1px solid #000;
    padding-top:4px;
}

    .footer-left{
        float:left;
    }

    .footer-right{
        float:right;
    }
}

</style>

<script>

function calculateRow(row)
{
    let weeklyTotal =
    parseFloat(
        row.querySelector('.weeklytotal').innerText
    ) || 0;

    let mentorEval =
    parseInt(
        row.querySelector('.mentoreval').value
    ) || 0;

    let finalEval =
    parseInt(
        row.querySelector('.finaleval').value
    ) || 0;

    /*
    TOTAL OUT OF 100
    (Week Total 40 + Mentor Evaluation 40 + Final Evaluation 20)
    SCALED DOWN TO 50
    */

    let totalRaw =
    weeklyTotal + mentorEval + finalEval;

    let totalScaled =
    totalRaw / 2;

    let displayValue =
    (totalScaled % 1 === 0)
        ? totalScaled
        : totalScaled.toFixed(1);

    row.querySelector('.totalmarks').innerHTML =
displayValue + " / 50";
}

</script>    
    
</head>

<body>

<div class="container-box">

<div class="d-flex justify-content-between align-items-center mb-3">

<div>

<a href="dashboard.php"
class="btn btn-primary">
← Back
</a>

</div>

<div class="d-flex gap-2">

<button
type="button"
onclick="window.print()"
class="btn btn-success">

🖨️ Print Evaluation Sheet

</button>

<a href="export_student_evaluations.php"
class="btn btn-primary">

📊 Export Excel

</a>

</div>

</div>
    
    <div class="print-header">

    <div>
        <img src="../assets/images/logo.png"
             class="college-logo">
    </div>

    <div class="college-section">

        <h2>
            MIT Art, Design and Technology University
        </h2>

        <h4>
            Student Internship Evaluation Sheet
        </h4>

    </div>

    <div class="faculty-section">

        <b>Division:</b>
        <?php echo $faculty_division; ?>

        <br>

        <b>Internship Class Coordinator</b>
        <?php echo $faculty_data['full_name']; ?>

    </div>

</div>

<hr>

<h1 class="mb-4">
Student Evaluation
</h1>

<form method="POST">

<table class="table table-bordered table-striped">

<thead class="table-dark">

<tr>

<th>Enrollment No</th>
<th>Roll No</th>
<th>Student Name</th>

<th>Week-1</th>
<th>Week-2</th>
<th>Week-3</th>
<th>Week-4</th>

<th>Week Total (40 Marks)</th>

<th>Mentor Evaluation (40 Marks)</th>

<th>Final Evaluation (20 Marks)</th>

<th>Total Marks (50)</th>

</tr>

</thead>

<tbody>

<?php

$count = 0;

while($row = mysqli_fetch_assoc($result))
{
    $count++;
?>
<tr>

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
<?php echo ($row['week1_marks']!="") ? $row['week1_marks'] : 0; ?>
</td>

<td>
<?php echo ($row['week2_marks']!="") ? $row['week2_marks'] : 0; ?>
</td>

<td>
<?php echo ($row['week3_marks']!="") ? $row['week3_marks'] : 0; ?>
</td>

<td>
<?php echo ($row['week4_marks']!="") ? $row['week4_marks'] : 0; ?>
</td>

<td class="weeklytotal">

<?php
echo ($row['weekly_total']!="")
? $row['weekly_total']
: "0";
?>

</td>

<td>

<input type="number"
name="mentor[<?php echo $row['id']; ?>]"
class="form-control mentoreval no-print"
min="0"
max="40"
value="<?php echo ($row['mentor_evaluation'] != "") ? $row['mentor_evaluation'] : 0; ?>"
oninput="calculateRow(this.closest('tr'))">

<span class="print-only">
<?php echo ($row['mentor_evaluation']!="") ? $row['mentor_evaluation'] : 0; ?>
</span>

</td>

<td>

<input type="number"
name="final[<?php echo $row['id']; ?>]"
class="form-control finaleval no-print"
min="0"
max="20"
value="<?php echo ($row['final_evaluation'] != "") ? $row['final_evaluation'] : 0; ?>"
oninput="calculateRow(this.closest('tr'))">

<span class="print-only">
<?php echo ($row['final_evaluation']!="") ? $row['final_evaluation'] : 0; ?>
</span>

</td>

<td class="totalmarks">

<?php

$weekly_disp = ($row['weekly_total'] != "") ? $row['weekly_total'] : 0;
$mentor_disp = ($row['mentor_evaluation'] != "") ? $row['mentor_evaluation'] : 0;
$final_disp = ($row['final_evaluation'] != "") ? $row['final_evaluation'] : 0;

/*
TOTAL OUT OF 100 (Week Total 40 + Mentor 40 + Final 20)
SCALED DOWN TO 50 -- COMPUTED LIVE SO IT'S ALWAYS
ACCURATE EVEN BEFORE "Save All Marks" IS CLICKED
*/

$total_disp = ($weekly_disp + $mentor_disp + $final_disp) / 2;

echo formatMarks($total_disp)." / 50";

?>

</td>

</tr>
    
    <?php

?>

<?php

}

?>

</tbody>

</table>

<button type="submit"
name="save_marks"
class="btn btn-success">

Save All Marks

</button>

</form>

</div>
    
    <div class="footer-print">

    <div class="footer-left">
        MIT Art, Design and Technology University, Rajbaugh, Loni Kalbhor, Pune - 412201.
    </div>

    <div class="footer-right">
        Page :
    </div>

</div>

</body>
</html>
