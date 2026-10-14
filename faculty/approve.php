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

$faculty_id = (int)$_SESSION['user_id'];
$id = (int)($_GET['id'] ?? 0);

/* -----------------------------------------------------------------
   AUTHORIZATION CHECK
   A faculty member may only approve internships belonging to
   students in a division that faculty is actually assigned to
   (via faculty_divisions, with users.faculty_division as a legacy
   fallback for older single-division records). Without this check
   any logged-in faculty account could approve/reject/undo ANY
   student's internship just by changing the "id" in the URL.
   ----------------------------------------------------------------- */
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

$authorized = false;

if($id > 0 && !empty($faculty_divisions))
{
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
         WHERE internships.id='$id'
           AND users.division IN ($div_list)
         LIMIT 1"
    );

    $authorized = $check && mysqli_num_rows($check) > 0;
}

if(!$authorized)
{
    http_response_code(403);
    die("You are not authorized to approve this internship application.");
}

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
