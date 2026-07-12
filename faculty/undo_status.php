<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty')
{
    header("Location: ../login.php");
    exit();
}

$id = intval($_GET['id']);

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