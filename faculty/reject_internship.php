<?php

session_start();
include("../config.php");
include("../mail_function.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty')
{
    header("Location: ../login.php");
    exit();
}

$id = intval($_GET['id']);

if(isset($_POST['reject']))
{
    $reason = mysqli_real_escape_string(
    $conn,
    trim($_POST['reason'])
);

    $query = "UPDATE internships
              SET status='Rejected',
                  rejection_reason='$reason'
              WHERE id='$id'";

    mysqli_query($conn, $query);

    /* Get Student Details */

    $studentQuery = mysqli_query($conn,"
        SELECT users.full_name,
               users.email,
               internships.company_name,
               internships.internship_role
        FROM internships
        JOIN users
        ON internships.student_id = users.id
        WHERE internships.id='$id'
    ");

    $student = mysqli_fetch_assoc($studentQuery);

    $name = $student['full_name'];
    $email = $student['email'];
    $company = $student['company_name'];
    $role = $student['internship_role'];

    $subject = "Internship Application Rejected";

    $message = "
    Dear $name,<br><br>

    Your internship application has been rejected by faculty.<br><br>

    <b>Company:</b> $company <br>
    <b>Role:</b> $role <br>
    <b>Status:</b> Rejected <br>
    <b>Reason:</b> $reason <br><br>

    Please login to the MIT Internship Portal for details.<br><br>

    Regards,<br>
    MIT Internship Portal
    ";

    sendPortalMail($email, $subject, $message);

    echo "<script>alert('Internship Rejected');</script>";

    $search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$type = $_GET['internship_type'] ?? '';

echo "<script>
window.location='manage_applications.php?search="
.urlencode($search).
"&status="
.urlencode($status).
"&internship_type="
.urlencode($type)."';
</script>";
}

?>

<!DOCTYPE html>
<html>
<head>

<title>Reject Internship</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>

body{
    background:#f1f5f9;
}

.form-box{
    width:600px;
    margin:auto;
    margin-top:60px;
    background:white;
    padding:30px;
    border-radius:15px;
    box-shadow:0px 2px 10px rgba(0,0,0,0.1);
}

</style>

</head>

<body>

<div class="form-box">

    <h2 class="mb-4 text-danger">
        Reject Internship
    </h2>

    <form method="POST">

        <div class="mb-3">

            <label>Reason for Rejection</label>

            <textarea name="reason"
                      class="form-control"
                      rows="5"
                      required></textarea>

        </div>

        <button type="submit"
                name="reject"
                class="btn btn-danger w-100">

            Reject Internship

        </button>

    </form>

</div>

</body>
</html>