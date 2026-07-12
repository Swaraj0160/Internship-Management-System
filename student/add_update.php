<?php

session_start();
include("../config.php");
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'student')
{
    header("Location: ../login.php");
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

/* GET APPROVED INTERNSHIP */

$getApprovedInternship = mysqli_query(
    $conn,
    "SELECT *
     FROM internships
     WHERE student_id='$student_id'
     AND status='Approved'
     LIMIT 1"
);

$internship = mysqli_fetch_assoc($getApprovedInternship);

/* GET INTERNSHIP ID */

$internship_id = 0;

if($internship)
{
    $internship_id = $internship['id'];
}

/* FETCH DEADLINES */

$deadline_result = mysqli_query(
    $conn,
    "SELECT *
     FROM weekly_deadlines
     WHERE division='$division'
     ORDER BY week_no ASC"
);

/* SUBMIT UPDATE */

if(isset($_POST['submit']))
{
    if(!$internship)
    {
        echo "<script>alert('No approved internship found');</script>";
    }
    else
    {
        
$internship_id = $_POST['internship_id'];
$week_no = $_POST['week_no'];
$work_done = mysqli_real_escape_string(
    $conn,
    $_POST['work_done']
);

if(empty($_FILES['update_file']['name']))
{
    echo "<script>alert('Please upload weekly report file');</script>";
}
else
{

$file_name = "";

if(isset($_FILES['update_file']) &&
   $_FILES['update_file']['error'] == 0)
{
    $max_size = 500 * 1024;

    if($_FILES['update_file']['size'] > $max_size)
    {
        die("File exceeds 500KB limit");
    }

    /* ALLOW ONLY PDF */

    $ext = strtolower(
        pathinfo(
            $_FILES['update_file']['name'],
            PATHINFO_EXTENSION
        )
    );

    $mime = mime_content_type(
        $_FILES['update_file']['tmp_name']
    );

    if(
        $ext != 'pdf' ||
        $mime != 'application/pdf'
    )
    {
        die("Only PDF files are allowed");
    }

    $upload_dir = "../uploads/weekly_reports/";

    if(!is_dir($upload_dir))
    {
        die("Upload folder does not exist: ".$upload_dir);
    }

    if(!is_writable($upload_dir))
    {
        die("Upload folder is not writable");
    }

    $file_name = mysqli_real_escape_string(
    $conn,
    time() . "_" .
    basename($_FILES['update_file']['name'])
);

    $temp_name =
        $_FILES['update_file']['tmp_name'];

    $destination =
        $upload_dir . $file_name;

    if(!move_uploaded_file($temp_name, $destination))
    {
        die("File upload failed");
    }
}
else
{
    die(
        "Upload Error Code: ".
        $_FILES['update_file']['error']
    );
}
        /* CHECK DUPLICATE SUBMISSION */

        $check = mysqli_query(
            $conn,
            "SELECT *
             FROM weekly_updates
             WHERE internship_id='$internship_id'
             AND week_no='$week_no'"
        );

        if(mysqli_num_rows($check) > 0)
        {
            echo "<script>alert('Week already submitted');</script>";
        }
        else
        {
            $insert = "INSERT INTO weekly_updates
            (
                internship_id,
                week_no,
                work_done,
                update_file,
                submitted_at
            )

            VALUES
            (
                '$internship_id',
                '$week_no',
                '$work_done',
                '$file_name',
                NOW()
            )";

            if(!mysqli_query($conn, $insert))
{
    die(mysqli_error($conn));
}

echo "<script>alert('Weekly Update Submitted Successfully');</script>";
        }
    }
}
}

?>

<!DOCTYPE html>
<html>
<head>

<title>Weekly Update</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>

body{
    background:#f5f5f5;
}

.form-box{
    width:850px;
    margin:20px auto;
    background:white;
    padding:22px;
    border-radius:18px;
    border:1px solid #e6ebff;
    box-shadow:0 12px 30px rgba(67,97,238,.08);
}

h2{
    font-size:34px;
    font-weight:700;
    margin-bottom:18px;
}

h4{
    font-size:22px;
    margin-bottom:12px;
}

label{
    font-weight:600;
    color:#374151;
    margin-bottom:8px;
}

.form-control,
.form-select{
    height:42px;
    font-size:15px;
}

textarea.form-control{
    min-height:90px;
}

.form-control:focus,
.form-select:focus{
    border-color:#4361ee;
    box-shadow:0 0 0 .2rem rgba(67,97,238,.15);
}

.table{
    border-radius:16px;
    overflow:hidden;
    margin-bottom:0;
}

.table thead{
    background:linear-gradient(90deg,#3346d3,#5c6cff);
    color:white;
}

.table thead th{
    padding:8px 10px;
    font-size:14px;
}

.table tbody td{
    padding:8px 10px;
    font-size:14px;
}

.table tbody tr{
    transition:.25s;
}

.table tbody tr:hover{
    background:#f8faff;
}

.badge{
    padding:4px 10px;
    font-size:11px;
    border-radius:20px;
}

.btn-primary{
    border:none;
    border-radius:12px;
    background:linear-gradient(90deg,#4361ee,#5b74ff);
height:42px;
font-size:15px;
font-weight:600;
    transition:.3s;
    box-shadow:0 10px 20px rgba(67,97,238,.20);
}

.btn-primary:hover{
    transform:translateY(-2px);
    box-shadow:0 15px 30px rgba(67,97,238,.30);
}

hr{
    margin:14px 0;
}

.alert{
    border-radius:15px;
}

::-webkit-file-upload-button{
    border:none;
    background:#4361ee;
    color:white;
    padding:8px 16px;
    border-radius:8px;
    margin-right:10px;
    cursor:pointer;
}

.btn-primary{
    background:linear-gradient(90deg,#4361ee,#5b74ff);
    border:none;
    border-radius:10px;
    padding:8px 18px;
    font-weight:600;
    box-shadow:0 5px 15px rgba(67,97,238,.25);
    transition:.3s;
}

.btn-primary:hover{
    transform:translateY(-2px);
    box-shadow:0 10px 25px rgba(67,97,238,.35);
}

</style>
<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>

<body>
<div style="margin:20px 0 10px 30px;">
    <a href="dashboard.php" class="btn btn-primary btn-sm">
        <i class="fa-solid fa-arrow-left"></i> Back
    </a>
</div>

<div class="form-box">

    <h2 class="mb-4 text-center">
        Weekly Progress Update
    </h2>

    <!-- DEADLINES TABLE -->

    <h4 class="mb-3">
        Weekly Submission Deadlines
    </h4>

    <table class="table table-bordered">

        <thead class="table-dark">

            <tr>

                <th>Week</th>
                <th>Deadline</th>
                <th>Deadline Status</th>
                <th>Submission Status</th>

            </tr>

        </thead>

        <tbody>

        <?php

        if(mysqli_num_rows($deadline_result) > 0)
        {
            while($deadline = mysqli_fetch_assoc($deadline_result))
            {
                $week = $deadline['week_no'];

                $check_submission = mysqli_query(
                    $conn,
                    "SELECT *
                     FROM weekly_updates
                     WHERE internship_id='$internship_id'
                     AND week_no='$week'"
                );

                $submitted = mysqli_num_rows($check_submission);

                ?>

                <tr>

                    <td>
                        Week <?php echo $week; ?>
                    </td>

                    <td>
                        <?php
                        echo date(
                            "d M Y h:i A",
                            strtotime($deadline['deadline_date'])
                        );
                        ?>
                    </td>

                    <td>

                        <?php

                        if(
                            strtotime(date("Y-m-d H:i:s"))
                            > strtotime($deadline['deadline_date'])
                        )
                        {
                            echo "<span class='badge bg-danger'>
                                    Deadline Passed
                                  </span>";
                        }
                        else
                        {
                            echo "<span class='badge bg-success'>
                                    Active
                                  </span>";
                        }

                        ?>

                    </td>

                    <td>

                        <?php

                        if($submitted > 0)
                        {
                            $submission = mysqli_fetch_assoc($check_submission);

                            if(
                                strtotime($submission['submitted_at'])
                                <= strtotime($deadline['deadline_date'])
                            )
                            {
                                echo "<span class='badge bg-success'>
                                        Submitted On Time
                                      </span>";
                            }
                            else
                            {
                                echo "<span class='badge bg-warning text-dark'>
                                        Submitted Late
                                      </span>";
                            }
                        }
                        else
                        {
                            echo "<span class='badge bg-secondary'>
                                    Not Submitted
                                  </span>";
                        }

                        ?>

                    </td>

                </tr>

                <?php
            }
        }
        else
        {
            ?>

            <tr>

                <td colspan="4" class="text-center text-danger">

                    No deadlines added by faculty yet.

                </td>

            </tr>

            <?php
        }

        ?>

        </tbody>

    </table>

    <hr>

    <!-- UPDATE FORM -->

    <form method="POST" enctype="multipart/form-data">

        <div class="mb-2">

            <label>Internship Company</label>

            <?php if($internship) { ?>

            <input type="text"
                   class="form-control"
                   value="<?php echo $internship['company_name']; ?>"
                   readonly>

            <input type="hidden"
                   name="internship_id"
                   value="<?php echo $internship['id']; ?>">

            <?php } else { ?>

            <input type="text"
                   class="form-control"
                   value="No internship approved yet"
                   readonly>

            <?php } ?>

        </div>

        <div class="mb-2">
        

            <label>Week Number</label>

            <select name="week_no"
                    class="form-control"
                    required
                    <?php if(!$internship) echo "disabled"; ?>>

                <option value="">
                    Select Week
                </option>

                <?php

               for($i=1; $i<=4; $i++)
{
    $checkWeek = mysqli_query(
        $conn,
        "SELECT id
         FROM weekly_updates
         WHERE internship_id='$internship_id'
         AND week_no='$i'"
    );

    if(mysqli_num_rows($checkWeek) == 0)
    {
?>
        <option value="<?php echo $i; ?>">
            Week <?php echo $i; ?>
        </option>
<?php
    }
}

                ?>

            </select>

        </div>

        <div class="mb-2">

            <label>Work Done(in 2-3 line)</label>

            <textarea name="work_done"
                      class="form-control"
                      rows="5"
                      required
                      <?php if(!$internship) echo "disabled"; ?>></textarea>

        </div>

        <div class="mb-2">

            <label>
Upload File (1-2 pages file, Max 500 KB)
</label>

            <input type="file"
                   name="update_file"
                   class="form-control"
                   required
                   <?php if(!$internship) echo "disabled"; ?>>

        </div>

        <?php if(!$internship) { ?>

        <div class="alert alert-danger">

            You cannot submit weekly updates until your internship is approved by faculty.

        </div>

        <?php } ?>

        <button type="submit"
                name="submit"
                class="btn btn-primary w-100"
                <?php if(!$internship) echo "disabled"; ?>>

                Submit Update

        </button>

    </form>

</div>

</body>
</html>