<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    header("Location: ../login.php");
    exit();
}

$id = (int)($_GET['id'] ?? 0);
$return = $_GET['return'] ?? "";

/* Only allow safe query-string characters so this can never be used
   for header/response manipulation. */
$return = preg_replace('/[^A-Za-z0-9=&_\-\.%]/', '', $return);

if($id > 0)
{
    $stmt = $conn->prepare("DELETE FROM users WHERE id=? AND role='faculty'");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    /* Best-effort cleanup of this faculty's division mapping rows.
       Skipped safely if faculty_divisions' columns differ from
       (faculty_id, division) on your server. */
    $fd = $conn->prepare("DELETE FROM faculty_divisions WHERE faculty_id=?");
    if($fd)
    {
        $fd->bind_param("i", $id);
        $fd->execute();
    }
}

if($return !== "")
{
    header("Location: manage_faculty.php?" . $return);
}
else
{
    header("Location: manage_faculty.php");
}

exit();

?>
