<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    header("Location: ../login.php");
    exit();
}

/* -----------------------------------------------------------------
   REPLACE — sidebar propagation + security fixes found while wiring
   this page into the shared sidebar:

   1. SQL injection: INSERT used raw $_POST['target_role'] string-
      concatenated into the query; DELETE used raw $_GET['delete']
      concatenated into both the SELECT (attachment lookup) and the
      DELETE itself. All three converted to prepared statements.
   2. XSS: title, message (via nl2br), and the attachment filename
      (used both as link text/href) were echoed with no escaping.
      All now go through htmlspecialchars(). The uploaded attachment
      filename is also re-sanitized on upload (previously only
      time()+basename(), which still allowed most special characters
      through into the stored filename/DB value).
   ----------------------------------------------------------------- */

if(!file_exists("../uploads/notifications"))
{
    mkdir("../uploads/notifications", 0777, true);
}

/* ADD NOTIFICATION */

if(isset($_POST['add_notification']))
{
    $title = trim((string)($_POST['title'] ?? ''));
    $message = trim((string)($_POST['message'] ?? ''));

    $allowed_roles = ['all', 'student', 'faculty'];
    $target_role = in_array($_POST['target_role'] ?? '', $allowed_roles, true) ? $_POST['target_role'] : 'all';

    /* FILE UPLOAD */

    $attachment = "";

    if(isset($_FILES['attachment']) && $_FILES['attachment']['name'] != "")
    {
        $safe_name = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', basename($_FILES['attachment']['name']));
        $attachment = time() . "_" . $safe_name;

        $temp_name = $_FILES['attachment']['tmp_name'];

        move_uploaded_file(
            $temp_name,
            "../uploads/notifications/" . $attachment
        );
    }

    $stmt = $conn->prepare(
        "INSERT INTO admin_notifications (title, message, target_role, attachment)
         VALUES (?, ?, ?, ?)"
    );
    $stmt->bind_param("ssss", $title, $message, $target_role, $attachment);
    $stmt->execute();

    echo "<script>alert('Notification Added Successfully');</script>";
}

/* DELETE NOTIFICATION */

if(isset($_GET['delete']))
{
    $id = (int)$_GET['delete'];

    /* DELETE FILE */

    $stmt = $conn->prepare("SELECT attachment FROM admin_notifications WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $file_data = $stmt->get_result()->fetch_assoc();

    if($file_data && $file_data['attachment'] != "")
    {
        $file_path = "../uploads/notifications/" . $file_data['attachment'];

        if(file_exists($file_path))
        {
            unlink($file_path);
        }
    }

    /* DELETE RECORD */

    $stmt = $conn->prepare("DELETE FROM admin_notifications WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    echo "<script>
            window.location='manage_notifications.php';
          </script>";
    exit();
}

/* FETCH NOTIFICATIONS */

$notifications = mysqli_query(
    $conn,
    "SELECT *
     FROM admin_notifications
     ORDER BY id DESC"
);

$activePage = 'manage_notifications.php';

?>

<!DOCTYPE html>
<html>
<head>

<title>Manage Notifications</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<?php include __DIR__ . '/includes/sidebar_head.php'; ?>

<style>

body{
    background:#f1f5f9;
    font-family:Arial;
    margin:0;
}

.page-inner{
    padding:0 24px 40px;
}

.container-box{
    width:100%;
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

.table td{
    vertical-align:middle;
}

.badge-role{
    padding:8px 14px;
    border-radius:8px;
    font-size:13px;
}

.card{
    border-radius:18px;
}

.table-responsive{
    border-radius:15px;
    overflow:hidden;
}

.table tbody tr:hover{
    background:#f8fafc;
    transition:.25s;
}

.form-control{
    border-radius:10px;
}

.btn{
    border-radius:10px;
}

h2{
    font-weight:700;
}

label{
    font-weight:600;
}

</style>

</head>

<body>

<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="page-inner">
<div class="container-box">

<div class="d-flex justify-content-between align-items-center mb-4">

<div>

<h2 class="fw-bold mb-1">
Manage Notifications
</h2>

<p class="text-muted mb-0">
Create and manage announcements for students and faculty.
</p>

</div>

</div>


    <!-- ADD FORM -->

    <div class="card shadow-sm border-0 mb-4"
style="border-radius:18px;">

<div class="card-body">

<form method="POST"
enctype="multipart/form-data">

        <div class="row">

            <div class="col-md-4 mb-3">

                <label class="form-label">
                    Notification Title
                </label>

                <input type="text"
                       name="title"
                       class="form-control"
                       required>

            </div>

            <div class="col-md-3 mb-3">

                <label class="form-label">
                    Send To
                </label>

                <select name="target_role"
                        class="form-control"
                        required>

                    <option value="all">
                        All
                    </option>

                    <option value="student">
                        Students
                    </option>

                    <option value="faculty">
                        Faculty
                    </option>

                </select>

            </div>

            <div class="col-md-5 mb-3">

                <label class="form-label">
                    Attachment (Optional)
                </label>

                <input type="file"
                       name="attachment"
                       class="form-control">

            </div>

        </div>

        <div class="mb-3">

            <label class="form-label">
                Notification Message
            </label>

            <textarea name="message"
                      class="form-control"
                      rows="5"
                      placeholder="Type notification message here..."
                      required></textarea>

        </div>

        <button type="submit"
                name="add_notification"
                class="btn btn-success px-4">

            <i class="fa fa-plus"></i>

            Add Notification

        </button>

    </form>

</div>

</div>

    <!-- TABLE -->

    <h4 class="fw-bold mb-3">
Existing Notifications
</h4>

    <div class="table-responsive">

        <table class="table table-hover align-middle">

            <thead style="background:#1f2937;color:white;">

                <tr>

                    <th>Title</th>
                    <th width="350">Message</th>
                    <th>Target</th>
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
                        <?php echo htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8'); ?>
                    </td>

                    <td>
                        <?php echo nl2br(htmlspecialchars($row['message'], ENT_QUOTES, 'UTF-8')); ?>
                    </td>

                    <td>

                        <?php

                        if($row['target_role'] == "all")
                        {
                            echo "<span class='badge bg-dark badge-role'>
                                    All
                                  </span>";
                        }
                        else if($row['target_role'] == "student")
                        {
                            echo "<span class='badge bg-primary badge-role'>
                                    Students
                                  </span>";
                        }
                        else
                        {
                            echo "<span class='badge bg-success badge-role'>
                                    Faculty
                                  </span>";
                        }

                        ?>

                    </td>

                    <td>

                        <?php

                        if($row['attachment'] != "")
                        {
                            $safe_attachment = htmlspecialchars($row['attachment'], ENT_QUOTES, 'UTF-8');
                        ?>

                        <a href="../uploads/notifications/<?php echo $safe_attachment; ?>"
                           target="_blank"
                           class="btn btn-sm btn-outline-primary">

                           <i class="fa fa-download"></i>

                           Download

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

                        echo htmlspecialchars(
                            date("d M Y h:i A", strtotime($row['created_at'])),
                            ENT_QUOTES, 'UTF-8'
                        );

                        ?>

                    </td>

                    <td>

                        <a href="manage_notifications.php?delete=<?php echo (int)$row['id']; ?>"
                           class="btn btn-sm btn-outline-danger"
                           onclick="return confirm('Delete Notification?')">

                           <i class="fa fa-trash"></i>

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

                    <td colspan="6"
                        class="text-center text-danger">

                        No notifications added yet.

                    </td>

                </tr>

                <?php
            }

            ?>

            </tbody>

        </table>

    </div>

</div>
</div>

</main>
</div>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>

</body>
</html>
