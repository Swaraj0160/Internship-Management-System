<?php

session_start();
include("../config.php");
include("../mail_function.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty')
{
    header("Location: ../login.php");
    exit();
}

$faculty_id = $_SESSION['user_id'];

/* GET FACULTY DIVISION */

$getFaculty = mysqli_query(
    $conn,
    "SELECT faculty_division
     FROM users
     WHERE id='$faculty_id'"
);

$facultyData = mysqli_fetch_assoc($getFaculty);

$division = $facultyData['faculty_division'];

/* ADD NOTIFICATION */

if(isset($_POST['add_notification']))
{
    $title = mysqli_real_escape_string(
        $conn,
        $_POST['title']
    );

$message = $_POST['message'];

$message_db = mysqli_real_escape_string(
    $conn,
    $message
);

    /* FILE UPLOAD */

    $attachment = "";

    if(isset($_FILES['attachment']) &&
       $_FILES['attachment']['name'] != '')
    {
        $attachment = time() . "_" .
        $_FILES['attachment']['name'];

        move_uploaded_file(
            $_FILES['attachment']['tmp_name'],
            "../uploads/notifications/" . $attachment
        );
    }

mysqli_query(
    $conn,
    "INSERT INTO faculty_notifications
    (
        faculty_id,
        division,
        title,
        message,
        attachment
    )

    VALUES
    (
        '$faculty_id',
        '$division',
        '$title',
        '$message_db',
        '$attachment'
    )"
);
    
    /* SEND EMAIL TO STUDENTS */

$students = mysqli_query($conn, "

SELECT DISTINCT
users.full_name,
users.email

FROM users

JOIN internships
ON users.id = internships.student_id

WHERE users.division='$division'
AND internships.status IN ('Pending','Approved')

");

while($student = mysqli_fetch_assoc($students))
{
    $student_name = $student['full_name'];
    $student_email = $student['email'];

    $subject = "New Internship Notification";

    $email_message = "

<h3>MIT Internship Portal Notification</h3>

<p>Dear <b>$student_name</b>,</p>

<p>
A new internship notification has been posted by your faculty.
</p>

<p>
<b>Title:</b> $title
</p>

<p>
<b>Message:</b><br>
".nl2br(htmlspecialchars($message))."
</p>

<hr>

<p>
Please login to the Internship Portal for complete details:
</p>

<p>
<a href='https://mitinternship.online/login.php'
style='background:#0d6efd;
color:white;
padding:10px 15px;
text-decoration:none;
border-radius:5px;'>

Open Internship Portal

</a>
</p>

<br>

<p>
Regards,<br>
<b>MIT Internship Portal</b>
</p>

";

    sendPortalMail(
        $student_email,
        $subject,
        $email_message
    );
}

    echo "<script>alert('Notification Sent Successfully');</script>";
}

/* DELETE */

if(isset($_GET['delete']))
{
    $id = $_GET['delete'];

    $getFile = mysqli_query(
        $conn,
        "SELECT attachment
         FROM faculty_notifications
         WHERE id='$id'"
    );

    $fileData = mysqli_fetch_assoc($getFile);

    if($fileData['attachment'] != '')
    {
        unlink(
            "../uploads/notifications/" .
            $fileData['attachment']
        );
    }

    mysqli_query(
        $conn,
        "DELETE FROM faculty_notifications
         WHERE id='$id'
         AND faculty_id='$faculty_id'"
    );

    echo "<script>
            window.location='manage_notifications.php';
          </script>";
}

/* FETCH */

$notifications = mysqli_query(
    $conn,
    "SELECT * FROM faculty_notifications

     WHERE faculty_id='$faculty_id'

     ORDER BY id DESC"
);

?>

<!DOCTYPE html>
<html>
<head>

<title>Faculty Notifications</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>

body{
    background:#f1f5f9;
}

.container-box{
    width:95%;
    margin:auto;
    margin-top:40px;
    background:white;
    padding:30px;
    border-radius:15px;
    box-shadow:0px 2px 10px rgba(0,0,0,0.1);
}

textarea{
    resize:none;
}

</style>

</head>

<body>

<div class="container-box">
    
    <a href="dashboard.php"
   class="btn btn-primary"
   style="border-radius:8px;margin-bottom:15px;">
   ← Back
</a>

    <h2 class="mb-4">
        Division Notifications
    </h2>

    <h5 class="mb-4">
        Division:
        <?php echo $division; ?>
    </h5>

    <!-- ADD FORM -->

    <form method="POST" enctype="multipart/form-data">

        <div class="mb-3">

            <label class="form-label">
                Notification Title
            </label>

            <input type="text"
                   name="title"
                   class="form-control"
                   required>

        </div>

        <div class="mb-3">

            <label class="form-label">
                Notification Message
            </label>

            <textarea name="message"
                      class="form-control"
                      rows="4"
                      required></textarea>

        </div>

        <div class="mb-3">

            <label class="form-label">
                Attach File (Optional)
            </label>

            <input type="file"
                   name="attachment"
                   class="form-control">

        </div>

        <button type="submit"
                name="add_notification"
                class="btn btn-primary">

            Send Notification

        </button>

    </form>

    <hr class="my-4">

    <!-- TABLE -->

    <h4 class="mb-3">
        Sent Notifications
    </h4>

    <table class="table table-bordered table-striped">

        <thead class="table-dark">

            <tr>

                <th>Title</th>
                <th>Message</th>
                <th>Attachment</th>
                <th>Created At</th>
                <th>Action</th>

            </tr>

        </thead>

        <tbody>

        <?php

        if(mysqli_num_rows($notifications) > 0)
        {
            while($row = mysqli_fetch_assoc($notifications))
            {
            ?>

            <tr>

                <td>
                    <?php echo $row['title']; ?>
                </td>

                <td width="400">
                   <?php echo nl2br(stripslashes($row['message'])); ?>
                </td>

                <td>

                    <?php

                    if($row['attachment'] != '')
                    {
                        ?>

                        <a href="../uploads/notifications/<?php echo $row['attachment']; ?>"
                           target="_blank"
                           class="btn btn-success btn-sm">

                           View File

                        </a>

                        <?php
                    }
                    else
                    {
                        echo "-";
                    }

                    ?>

                </td>

                <td>

                    <?php

                    echo date(
                        "d M Y h:i A",
                        strtotime($row['created_at'])
                    );

                    ?>

                </td>

                <td>

                    <a href="manage_notifications.php?delete=<?php echo $row['id']; ?>"
                       class="btn btn-danger btn-sm"
                       onclick="return confirm('Delete Notification?')">

                       Delete

                    </a>

                </td>

            </tr>

            <?php
            }
        }
        else
        {
            ?>

            <tr>

                <td colspan="5"
                    class="text-center text-danger">

                    No notifications sent yet.

                </td>

            </tr>

            <?php
        }

        ?>

        </tbody>

    </table>

</div>

</body>
</html>