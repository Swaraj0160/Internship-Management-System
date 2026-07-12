<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'student')
{
    header("Location: ../login.php");
    exit();
}

$student_id = $_SESSION['user_id'];
$getDivision = mysqli_query($conn,"
SELECT division
FROM users
WHERE id='$student_id'
");

$userData = mysqli_fetch_assoc($getDivision);

$query = "

SELECT *

FROM internships

WHERE student_id='$student_id'

ORDER BY created_at DESC

";

$result = mysqli_query($conn, $query);

?>

<!DOCTYPE html>
<html>

<head>

<title>My Internships</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>

body{
    background:linear-gradient(135deg,#f8f9ff,#eef3ff);
    font-family:'Inter',sans-serif;
}

.container-box{
    width:96%;
    margin:35px auto;
    background:#fff;
    padding:30px;
    border-radius:22px;
    border:1px solid #e8ecff;
    box-shadow:0 15px 40px rgba(76,81,255,.08);
}

h1{
    font-size:32px;
    font-weight:700;
    color:#243b7d;
    margin-bottom:25px;
}

.badge-approved,
.badge-rejected,
.badge-pending{
    padding:7px 15px;
    border-radius:50px;
    font-size:13px;
    font-weight:600;
}

.badge-approved{
    background:#d1fae5;
    color:#047857;
}

.badge-pending{
    background:#fef3c7;
    color:#b45309;
}

.badge-rejected{
    background:#fee2e2;
    color:#b91c1c;
}

.btn-sm{
    border-radius:8px;
    padding:6px 14px;
    font-weight:600;
}

.btn-warning{
    color:white;
}

.btn-success,
.btn-warning,
.btn-danger,
.btn-primary{
    transition:.3s;
}

.btn-success:hover,
.btn-warning:hover,
.btn-danger:hover,
.btn-primary:hover{
    transform:translateY(-2px);
}

.btn-primary{
    border-radius:10px;
    padding:8px 18px;
    font-weight:600;
    box-shadow:0 5px 15px rgba(13,110,253,.25);
    transition:.3s;
}

.btn-primary:hover{
    transform:translateY(-2px);
}

table{
    font-size:14px;
    border-radius:15px;
    overflow:hidden;
}

.table{
    margin-bottom:0;
}

.table thead{
    background:linear-gradient(90deg,#3b5bdb,#5c7cfa);
    color:white;
}

.table thead th{
    border:none;
    padding:15px;
    font-size:14px;
    font-weight:600;
    vertical-align:middle;
}

.table tbody td{
    padding:14px;
    vertical-align:middle;
}

.table tbody tr{
    transition:.25s;
}

.table tbody tr:hover{
    background:#f7f9ff;
    transform:scale(1.002);
}

</style>

</head>

<body>

<div class="container-box">
   <a href="dashboard.php"
   class="btn btn-primary mb-3">
   ← Back
</a>

    <h1 class="mb-4">
        My Internship Applications
    </h1>

    <table class="table table-bordered table-striped">

        <thead class="table-dark">

            <tr>

                <th>Company</th>
                <th>Role</th>
                <th>Mini Project Title</th>
                <th>Start Date</th>
                <th>End Date</th>
                <th>Mode</th>
                <th>Mentor Name</th>
                <th>Internship Type</th>
                <th>Status</th>
                <th>Applied On</th>
                <th>Offer Letter</th>
                <th>Rejection Reason</th>
                <th>Action</th>

            </tr>

        </thead>

        <tbody>

        <?php

        if(mysqli_num_rows($result) > 0)
        {

            while($row = mysqli_fetch_assoc($result))
            {

        ?>

        <tr>

            <td>
                <?php echo htmlspecialchars($row['company_name']); ?>
            </td>

            <td>
                <?php echo htmlspecialchars($row['internship_role']); ?>
            </td>

            <td>
                <?php echo !empty($row['mini_project_title']) ? htmlspecialchars($row['mini_project_title']) : "-"; ?>
            </td>

            <td>
                <?php echo htmlspecialchars($row['start_date']); ?>
            </td>

            <td>
                <?php echo htmlspecialchars($row['end_date']); ?>
            </td>

            <td>
                <?php echo htmlspecialchars($row['mode']); ?>
            </td>

            <td>
                <?php echo htmlspecialchars($row['mentor_name']); ?>
            </td>

            <td>
                <?php echo htmlspecialchars($row['internship_type']); ?>
            </td>

            <td>

                <?php

                if($row['status'] == 'Approved')
                {
                    echo "<span class='badge-approved'>Approved</span>";
                }
                else if($row['status'] == 'Rejected')
                {
                    echo "<span class='badge-rejected'>Rejected</span>";
                }
                else
                {
                    echo "<span class='badge-pending'>Pending</span>";
                }

                ?>

            </td>

            <td>

                <?php

                echo date('d-m-Y h:i A', strtotime($row['created_at']));

                ?>

            </td>

           <td>

<?php

if($row['offer_letter'] != "")
{

?>

<a href="<?php echo '../uploads/offer_letters/' .
htmlspecialchars($userData['division']) . '/' .
htmlspecialchars(basename($row['offer_letter'])); ?>"
   target="_blank"
   class="btn btn-primary btn-sm">

   View <i class="bi bi-eye-fill"></i>

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

if($row['rejection_reason'] != "")
{
    echo htmlspecialchars($row['rejection_reason']);
}
else
{
    echo "-";
}

?>

</td>

<td>

<?php

if($row['status'] == 'Pending')
{
?>

<a href="apply_internship.php?edit=<?php echo (int)$row['id']; ?>"
   class="btn btn-warning btn-sm">

   Edit

</a>

<?php
}
else if($row['status'] == 'Rejected')
{
?>

<a href="apply_internship.php?edit=<?php echo (int)$row['id']; ?>"
   class="btn btn-danger btn-sm">

   Edit & Resubmit

</a>

<?php
}
else if(
    $row['status'] == 'Approved'
    &&
    empty($row['offer_letter'])
)
{
?>

<a href="apply_internship.php?edit=<?php echo (int)$row['id']; ?>"
   class="btn btn-success btn-sm">

   Upload Offer Letter

</a>
    
<?php
}
else if(
    $row['status'] == 'Approved'
    &&
    !empty($row['offer_letter'])
)
{
?>

<a href="apply_internship.php?edit=<?php echo (int)$row['id']; ?>"
   class="btn btn-warning btn-sm">

   Edit

</a>

<?php
}
else
{
    echo "-";
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

            <td colspan="13" class="text-center">

                <div style="padding:20px;font-size:16px;font-weight:600;color:#6c757d;">
📂 No Internship Applications Found
</div>

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
