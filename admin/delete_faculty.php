<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    header("Location: ../login.php");
    exit();
}

$id = $_GET['id'];
$return = $_GET['return'] ?? "";

$query = "DELETE FROM users
          WHERE id='$id'";

mysqli_query($conn, $query);

if($return != "")
{
    header("Location: manage_faculty.php?$return");
}
else
{
    header("Location: manage_faculty.php");
}
exit();


?>