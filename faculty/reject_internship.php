<?php

session_start();
include("../config.php");
include("../mail_function.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty')
{
    header("Location: ../login.php");
    exit();
}

$faculty_id = (int)$_SESSION['user_id'];
$id = intval($_GET['id'] ?? 0);

/* -----------------------------------------------------------------
   AUTHORIZATION CHECK — see faculty/approve.php for full rationale.
   A faculty member may only act on internships belonging to students
   in a division that faculty is actually assigned to.
   ----------------------------------------------------------------- */
function facultyOwnsInternship(mysqli $conn, int $faculty_id, int $internship_id): bool
{
    if($internship_id <= 0)
    {
        return false;
    }

    $faculty_divisions = [];

    $fd_res = mysqli_query(
        $conn,
        "SELECT DISTINCT division FROM faculty_divisions
         WHERE faculty_id='$faculty_id'
           AND division IS NOT NULL
           AND TRIM(division) <> ''"
    );

    if($fd_res)
    {
        while($row = mysqli_fetch_assoc($fd_res))
        {
            $v = trim((string)$row['division']);
            if($v !== '')
            {
                $faculty_divisions[] = $v;
            }
        }
    }

    if(empty($faculty_divisions))
    {
        $fac_res = mysqli_query($conn, "SELECT faculty_division FROM users WHERE id='$faculty_id'");
        $fac_row = mysqli_fetch_assoc($fac_res);
        $legacy = trim((string)($fac_row['faculty_division'] ?? ''));
        if($legacy !== '')
        {
            $faculty_divisions[] = $legacy;
        }
    }

    if(empty($faculty_divisions))
    {
        return false;
    }

    $div_values = array_map(
        fn($d) => "'" . mysqli_real_escape_string($conn, $d) . "'",
        $faculty_divisions
    );
    $div_list = implode(',', $div_values);

    $check = mysqli_query(
        $conn,
        "SELECT internships.id
         FROM internships
         JOIN users ON internships.student_id = users.id
         WHERE internships.id='$internship_id'
           AND users.division IN ($div_list)
         LIMIT 1"
    );

    return $check && mysqli_num_rows($check) > 0;
}

if(!facultyOwnsInternship($conn, $faculty_id, $id))
{
    http_response_code(403);
    die("You are not authorized to reject this internship application.");
}

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
