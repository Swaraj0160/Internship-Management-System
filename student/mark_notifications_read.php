<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'student')
{
    exit();
}

$student_id = $_SESSION['user_id'];

/* GET STUDENT DIVISION */

$getStudent = mysqli_query(
    $conn,
    "SELECT division
     FROM users
     WHERE id='$student_id'"
);

$studentData = mysqli_fetch_assoc($getStudent);

$division = $studentData['division'];

/* ADMIN NOTIFICATIONS */

$admin_notifications = mysqli_query(
    $conn,
    "SELECT id
     FROM admin_notifications

     WHERE target_role='student'
     OR target_role='all'"
);

while($admin = mysqli_fetch_assoc($admin_notifications))
{
    $notification_id = $admin['id'];

    $check = mysqli_query(
        $conn,
        "SELECT *
         FROM student_notification_reads

         WHERE student_id='$student_id'
         AND notification_type='admin'
         AND notification_id='$notification_id'"
    );

    if(mysqli_num_rows($check) == 0)
    {
        mysqli_query(
            $conn,
            "INSERT INTO student_notification_reads
            (
                student_id,
                notification_type,
                notification_id
            )

            VALUES
            (
                '$student_id',
                'admin',
                '$notification_id'
            )"
        );
    }
}

/* FACULTY NOTIFICATIONS */

$faculty_notifications = mysqli_query(
    $conn,
    "SELECT id
     FROM faculty_notifications

     WHERE division='$division'"
);

while($faculty = mysqli_fetch_assoc($faculty_notifications))
{
    $notification_id = $faculty['id'];

    $check = mysqli_query(
        $conn,
        "SELECT *
         FROM student_notification_reads

         WHERE student_id='$student_id'
         AND notification_type='faculty'
         AND notification_id='$notification_id'"
    );

    if(mysqli_num_rows($check) == 0)
    {
        mysqli_query(
            $conn,
            "INSERT INTO student_notification_reads
            (
                student_id,
                notification_type,
                notification_id
            )

            VALUES
            (
                '$student_id',
                'faculty',
                '$notification_id'
            )"
        );
    }
}

echo "success";

?>