<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
include("../config.php");
include("../mail_function.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty')
{
    header("Location: ../login.php");
    exit();
}

$id = $_GET['id'];

$query = "UPDATE internships
          SET status='Approved'
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

$subject = "Internship Application Approved";

$message = "
Dear $name,<br><br>

Congratulations! Your internship application has been approved.<br><br>

<b>Company:</b> $company <br>
<b>Role:</b> $role <br>
<b>Status:</b> Approved <br><br>

Please login to the MIT Internship Portal for more details.<br><br>

Regards,<br>
MIT Internship Portal
";

sendPortalMail($email, $subject, $message);

$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$type = $_GET['internship_type'] ?? '';

header(
    "Location: manage_applications.php?search=" .
    urlencode($search) .
    "&status=" .
    urlencode($status) .
    "&internship_type=" .
    urlencode($type)
);

exit();

?>