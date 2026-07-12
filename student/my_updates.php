<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'student')
{
    header("Location: ../login.php");
    exit();
}

$student_id = $_SESSION['user_id'];

/* FETCH STUDENT DIVISION */

$getStudent = mysqli_query(
    $conn,
    "SELECT division
     FROM users
     WHERE id='$student_id'"
);

$studentData = mysqli_fetch_assoc($getStudent);

$division = $studentData['division'];

/* FETCH STUDENT WEEKLY UPDATES */

$query = mysqli_query(
    $conn,
    "SELECT
weekly_updates.*,
internships.company_name,
internships.internship_role,
weekly_deadlines.deadline_date

     FROM weekly_updates

     INNER JOIN internships
     ON weekly_updates.internship_id = internships.id

    

     LEFT JOIN weekly_deadlines
     ON weekly_deadlines.week_no = weekly_updates.week_no
     AND weekly_deadlines.division = '$division'

     WHERE internships.student_id='$student_id'

     ORDER BY weekly_updates.week_no ASC"
);

?>

<!DOCTYPE html>
<html>
<head>

<title>My Weekly Work</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>

body{
    background:linear-gradient(135deg,#f7f9ff,#edf3ff);
    font-family:'Inter',sans-serif;
    color:#1f2937;
}

.container-box{
    width:99%;
    margin:25px auto;
    background:#fff;
    padding:25px;
    border-radius:22px;
    border:1px solid #e6ebff;
    box-shadow:0 20px 45px rgba(70,95,255,.08);
}

.page-title{
    font-size:38px;
    font-weight:700;
    color:#243b7d;
}

.back-btn{
    border-radius:10px;
    padding:8px 18px;
    font-weight:600;
    margin-bottom:25px;
}

.add-btn{
    border-radius:10px;
    padding:9px 18px;
    font-weight:600;
    transition:.3s;
    box-shadow:0 10px 25px rgba(67,97,238,.15);
}

.add-btn:hover{
    transform:translateY(-2px);
}

.table{
    margin-top:18px;
    border-radius:18px;
    overflow:hidden;
}

.table thead{
    background:linear-gradient(90deg,#3d4ee8,#5976ff);
}

.table thead th{
    color:#fff;
    border:none;
    padding:9px 8px;
    font-size:13px;
    white-space:nowrap;
    text-align:center;
}

.table tbody td{
    padding:8px;
    vertical-align:middle;
    border-color:#edf2ff;
    font-size:13px;
}

.table tbody tr:hover{
    background:#f8faff;
}

.work-cell{
    width:230px;
    max-width:230px;
    white-space:normal;
    line-height:1.45;
    color:#374151;
    font-size:13px;
}

.feedback{
    width:150px;
    max-width:150px;
    font-size:13px;
    line-height:1.4;
}

.file-link{
    text-decoration:none;
    font-size:13px;
    font-weight:600;
    white-space:nowrap;
}
.file-link:hover{
    color:#1d4ed8;
}

.badge-status{
    display:inline-block;
    padding:4px 10px;
    border-radius:20px;
    font-size:11px;
    font-weight:600;
}

.badge{
    font-size:11px !important;
    padding:5px 9px !important;
}

.on-time{
    background:#dcfce7;
    color:#15803d;
}

.late{
    background:#fee2e2;
    color:#dc2626;
}

.pending{
    background:#fef3c7;
    color:#a16207;
}

.btn-warning,
.btn-success,
.btn-danger{
    border-radius:8px;
    font-weight:600;
    padding:5px 10px;
    font-size:12px;
}

.table-responsive{
    border-radius:18px;
    overflow:auto;
}

.read-more{
    color:#2563eb;
    font-size:12px;
    font-weight:600;
    text-decoration:none;
    cursor:pointer;
}

.read-more:hover{
    text-decoration:underline;
}

.work-cell{
    width:240px;
    max-width:240px;
    line-height:1.55;
    white-space:normal;
}


</style>

</head>

<body>

<div class="container-box">
    <a href="dashboard.php" class="btn btn-primary back-btn">
   ← Back
</a>
    <div class="d-flex justify-content-between align-items-center mb-4">

<h1 class="page-title">
    My Weekly Work
</h1>

        <a href="add_update.php"
           class="btn btn-primary add-btn">

            + Add Weekly Update

        </a>

    </div>

    <div class="table-responsive">

<table class="table table-hover align-middle mb-0">

        <thead class="table-dark">

            <tr>

                <th>Week</th>
                <th>Company</th>
                <th>Role</th>
                <th width="220">Work Done</th>
                <th>Submitted At</th>
                <th>Status</th>
                <th>Review Status</th>
                <th>File</th>
                <th>Faculty Feedback</th>
                <th>Action</th>

            </tr>

        </thead>

        <tbody>

        <?php

        if(mysqli_num_rows($query) > 0)
        {
            while($row = mysqli_fetch_assoc($query))
            {

                $status = "Late Submission";
                $status_class = "late";

                if(!empty($row['deadline_date']))
                {
                    if(
                        strtotime($row['submitted_at'])
                        <= strtotime($row['deadline_date'])
                    )
                    {
                        $status = "Submitted On Time";
                        $status_class = "on-time";
                    }
                }
                else
                {
                    $status = "No Deadline";
                    $status_class = "pending";
                }

                ?>

                <tr>

                    <td>
                        Week <?php echo $row['week_no']; ?>
                    </td>

                    <td style="width:150px;font-size:13px;">
    <?php echo $row['company_name']; ?>
</td>

                    <td style="width:150px;font-size:13px;">
    <?php echo $row['internship_role']; ?>
</td>

                    <td class="work-cell">

<?php
$text = $row['work_done'];
$short = strlen($text) > 100 ? substr($text,0,100)."..." : $text;
?>

<div id="short<?php echo $row['id']; ?>">
    <?php echo nl2br($short); ?>

    <?php if(strlen($text) > 100){ ?>

        <br>

        <a href="javascript:void(0)"
           class="read-more"
           onclick="toggleWork(<?php echo $row['id']; ?>)">

           Read More

        </a>

    <?php } ?>

</div>

<div
id="full<?php echo $row['id']; ?>"
style="display:none;">

<?php echo nl2br($text); ?>

<br>

<a href="javascript:void(0)"
class="read-more"
onclick="toggleWork(<?php echo $row['id']; ?>)">

Show Less

</a>

</div>

</td>

                    <td>

                        <?php

                        echo date(
                            "d M Y h:i A",
                            strtotime($row['submitted_at'])
                        );

                        ?>

                    </td>

                   <td>

    <span class="badge-status <?php echo $status_class; ?>">

        <?php echo $status; ?>

    </span>

</td>

<td>

<?php

if($row['review_status'] == 'Accepted')
{
    echo "<span class='badge bg-success'>
            Accepted
          </span>";
}
elseif($row['review_status'] == 'Rejected')
{
    echo "<span class='badge bg-danger'>
            Rejected
          </span>";
}
else
{
    echo "<span class='badge bg-warning text-dark'>
            Pending Review
          </span>";
}

?>

</td>

<td>
    <?php

  if($row['update_file'] != "")
   {
      ?>

     <a
class="file-link"
href="../uploads/weekly_reports/<?php echo $row['update_file']; ?>"
target="_blank">

<i class="fa fa-file-pdf"></i>
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

                    <td class="feedback">
                        
                        <?php
if($row['faculty_feedback'] != "")
{
    echo $row['faculty_feedback'];
}
else
{
    echo "<span class='text-muted'>
            No Feedback Yet
          </span>";
}

                        ?>

                    </td>

                    <td>

<?php

if(
    $row['review_status'] == 'Pending'
    ||
    $row['review_status'] == 'Rejected'
    ||
    empty($row['review_status'])
)
{
?>

<a href="edit_update.php?id=<?php echo $row['id']; ?>"
   class="btn btn-warning btn-sm shadow-sm">

   <i class="fa fa-edit"></i>
   Edit Report

</a>

<?php
}
elseif($row['review_status'] == 'Accepted')
{
?>

<span class="badge rounded-pill bg-success px-3 py-2">

    Approved - Locked

</span>

<?php
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

                <td colspan="10"
                    class="text-center text-danger">

                    No weekly work submitted yet.

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

function toggleWork(id)
{
    const shortDiv=document.getElementById("short"+id);
    const fullDiv=document.getElementById("full"+id);

    if(fullDiv.style.display==="none")
    {
        fullDiv.style.display="block";
        shortDiv.style.display="none";
    }
    else
    {
        fullDiv.style.display="none";
        shortDiv.style.display="block";
    }
}

</script>

</body>
</html>