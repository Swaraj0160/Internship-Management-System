<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

$conn = mysqli_connect(
    "localhost",
    "u570725762_portal_user",
    "Wdcsi@2026",
    "u570725762_internship"
);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

echo "Database Connected Successfully";
?>