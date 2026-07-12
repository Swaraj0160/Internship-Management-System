<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty')
{
    header("Location: ../login.php");
    exit();
}

$faculty_id = $_SESSION['user_id'];

$getFaculty = mysqli_query($conn,
"SELECT faculty_division
 FROM users
 WHERE id='$faculty_id'");

$faculty = mysqli_fetch_assoc($getFaculty);

$division = $faculty['faculty_division'];

$query = "

SELECT 
users.full_name,
users.enrollment_no,
users.roll_no,
users.phone,
users.division,

internships.company_name,
internships.company_contact,
internships.mentor_name,
internships.internship_type,
internships.status

FROM users

LEFT JOIN internships
ON internships.id = (
    SELECT MAX(i2.id)
    FROM internships i2
    WHERE i2.student_id = users.id
)

WHERE users.role='student'
AND users.division='$division'

ORDER BY CAST(users.roll_no AS UNSIGNED) ASC

";

$result = mysqli_query($conn, $query);

?>

<!DOCTYPE html>
<html>
<head>

<title>Division Report</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>

body{
    background:#f1f5f9;
}

.container-box{
    width:95%;
    margin:auto;
    margin-top:40px;
    background:white;
    padding:30px;
    border-radius:15px;
}

@media print{

    .no-print{
        display:none;
    }

}

</style>

</head>

<body>

<div class="container-box">
    
    <a href="dashboard.php"
   class="btn btn-primary"
   style="border-radius:8px;margin-bottom:15px;">
   ← Back
</a>

    <h2 class="mb-4">
        Division Internship Report - <?php echo $division; ?>
    </h2>

    <div class="mb-3 no-print">

        <button onclick="window.print()"
                class="btn btn-dark">

            Print Report

        </button>

        <a href="download_excel.php"
           class="btn btn-success">

           Download Excel

        </a>

    </div>

    <table class="table table-bordered table-striped">

        <thead class="table-dark">

            <tr>

                <th>Roll No</th>
                <th>Enrollment No</th>
                <th>Student Name</th>
                <th>Phone</th>
                <th>Status</th>
                <th>Internship Type</th>
                <th>Mentor Name</th>
                <th>Company</th>
                <th>Company Contact</th>

            </tr>

        </thead>

        <tbody>

        <?php

        while($row = mysqli_fetch_assoc($result))
        {

        ?>

        <tr>

            <td><?php echo $row['roll_no']; ?></td>

            <td><?php echo $row['enrollment_no']; ?></td>

            <td><?php echo $row['full_name']; ?></td>

            <td><?php echo $row['phone']; ?></td>

            <td>

                <?php

                if($row['status'] == 'Approved')
                {
                    echo "<span class='badge bg-success'>Approved</span>";
                }
                elseif($row['status'] == 'Pending')
                {
                    echo "<span class='badge bg-warning text-dark'>Pending</span>";
                }
                elseif($row['status'] == 'Rejected')
                {
                    echo "<span class='badge bg-danger'>Rejected</span>";
                }
                else
                {
                    echo "<span class='badge bg-secondary'>Not Applied</span>";
                }

                ?>

            </td>

            <td>
                <?php echo $row['internship_type'] ? $row['internship_type'] : '-'; ?>
            </td>

            <td>
                <?php echo $row['mentor_name'] ? $row['mentor_name'] : '-'; ?>
            </td>

            <td>
                <?php echo $row['company_name'] ? $row['company_name'] : '-'; ?>
            </td>

            <td>
                <?php echo $row['company_contact'] ? $row['company_contact'] : '-'; ?>
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