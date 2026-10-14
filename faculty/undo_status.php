<?php

session_start();
include("../config.php");

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
    die("You are not authorized to modify this internship application.");
}

mysqli_query($conn,"
UPDATE internships
SET status='Pending',
    rejection_reason=NULL
WHERE id='$id'
");

$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$type = $_GET['internship_type'] ?? '';

header("Location: manage_applications.php?search=" .
    urlencode($search) .
    "&status=" .
    urlencode($status) .
    "&internship_type=" .
    urlencode($type));

exit();

?>
