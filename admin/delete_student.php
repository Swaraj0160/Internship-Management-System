<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    header("Location: ../login.php");
    exit();
}

$id = (int)$_GET['id'];
$return = $_GET['return'] ?? "";

mysqli_query($conn, "DELETE FROM users WHERE id=$id");

if($return != "")
{
    header("Location: manage_students.php?$return");
}
else
{
    header("Location: manage_students.php");
}
exit();
exit();

$query = "DELETE FROM users
          WHERE id='$id'";

mysqli_query($conn, $query);

header("Location: manage_students.php");
exit();

?>