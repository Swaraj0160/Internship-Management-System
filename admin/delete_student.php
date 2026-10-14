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
    $stmt = $conn->prepare("DELETE FROM users WHERE id=? AND role='student'");
    $stmt->bind_param("i", $id);
    $stmt->execute();
}

if($return !== "")
{
    header("Location: manage_students.php?" . $return);
}
else
{
    header("Location: manage_students.php");
}

exit();

?>
