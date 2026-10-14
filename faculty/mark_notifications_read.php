<?php

session_start();
include("../config.php");

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'faculty') {
    exit();
}

// Cast to int before use in SQL — closes the injection gap on $faculty_id.
$faculty_id = (int) $_SESSION['user_id'];

/* -----------------------------------------------------------------
   MARK ALL UNREAD ADMIN NOTIFICATIONS AS READ FOR THIS FACULTY
   -----------------------------------------------------------------
   Original version looped over every matching admin_notifications
   row and ran a SELECT (and sometimes an INSERT) per row — an N+1
   query pattern. This does the same "insert only if not already
   marked read" logic in a single query using INSERT ... SELECT with
   a NOT EXISTS guard, so it scales the same way regardless of how
   many notifications exist.
   ----------------------------------------------------------------- */
$sql = "
    INSERT INTO faculty_notification_reads (faculty_id, notification_id)
    SELECT '$faculty_id', an.id
    FROM admin_notifications an
    WHERE (an.target_role = 'faculty' OR an.target_role = 'all')
      AND NOT EXISTS (
          SELECT 1
          FROM faculty_notification_reads fnr
          WHERE fnr.faculty_id = '$faculty_id'
            AND fnr.notification_id = an.id
      )
";

$result = mysqli_query($conn, $sql);

// Keep the exact same plain-text response the dashboard's JS already
// expects (it only checks that the request completed, not the body).
echo $result ? "success" : "error";

?>