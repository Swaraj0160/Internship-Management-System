<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty')
{
    exit();
}

$faculty_id = $_SESSION['user_id'];

/* ADMIN NOTIFICATIONS */

$notifications = mysqli_query(
    $conn,
    "SELECT id
     FROM admin_notifications

     WHERE target_role='faculty'
     OR target_role='all'"
);

while($row = mysqli_fetch_assoc($notifications))
{
    $notification_id = $row['id'];

    $check = mysqli_query(
        $conn,
        "SELECT *
         FROM faculty_notification_reads

         WHERE faculty_id='$faculty_id'
         AND notification_id='$notification_id'"
    );

    if(mysqli_num_rows($check) == 0)
    {
        mysqli_query(
            $conn,
            "INSERT INTO faculty_notification_reads
            (
                faculty_id,
                notification_id
            )

            VALUES
            (
                '$faculty_id',
                '$notification_id'
            )"
        );
    }
}

echo "success";

?>