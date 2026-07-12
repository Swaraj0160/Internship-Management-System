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

function calculateWeeklyMarks($submittedAt, $deadlineDate)
{
    if(empty($submittedAt))
    {
        return 0; // not submitted
    }

    $submitted_ts = strtotime($submittedAt);
    $deadline_ts   = strtotime($deadlineDate);

    if($submitted_ts <= $deadline_ts)
    {
        return 10;
    }

    $delay_seconds = $submitted_ts - $deadline_ts;
    $delay_days = (int) ceil($delay_seconds / 86400);

    if($delay_days <= 1)
    {
        return 9;
    }
    elseif($delay_days == 2)
    {
        return 8;
    }
    elseif($delay_days == 3)
    {
        return 7;
    }
    else
    {
        return 5;
    }
}

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

/* LOAD DEADLINES INTO ARRAY */
$deadlines = [];
while($d = mysqli_fetch_assoc($deadline_result))
{
    $deadlines[$d['week_no']] = $d;
}

/* LOAD WEEKLY SUBMISSIONS FOR THIS INTERNSHIP */
$submissions = [];
if($internship_id > 0)
{
    $submission_result = mysqli_query(
        $conn,
        "SELECT *
         FROM weekly_updates
         WHERE internship_id='$internship_id'
         ORDER BY week_no ASC"
    );

    while($s = mysqli_fetch_assoc($submission_result))
    {
        $submissions[$s['week_no']] = $s;
    }
}

/* CALCULATE WEEKLY MARKS */
$weekly_marks = [];
$weekly_total = 0;

for($i = 1; $i <= 4; $i++)
{
    $submittedAt = $submissions[$i]['submitted_at'] ?? '';
    $deadlineDate = $deadlines[$i]['deadline_date'] ?? '';

    $marks = 0;

    if($submittedAt != '' && $deadlineDate != '')
    {
        $marks = calculateWeeklyMarks($submittedAt, $deadlineDate);
    }

    $weekly_marks[$i] = $marks;
    $weekly_total += $marks;
}

/* SUBMIT UPDATE */
if(isset($_POST['submit']))
{
    if(!$internship)
    {
        echo "<script>alert('No approved internship found');</script>";
    }
    else
    {
        $internship_id = $internship['id'];
        $week_no = $_POST['week_no'];
        $work_done = mysqli_real_escape_string($conn, $_POST['work_done']);

        if(empty($_FILES['update_file']['name']))
        {
            echo "<script>alert('Please upload weekly report file');</script>";
        }
        else
        {
            $file_name = "";

            if(isset($_FILES['update_file']) && $_FILES['update_file']['error'] == 0)
            {
                $max_size = 500 * 1024;

                if($_FILES['update_file']['size'] > $max_size)
                {
                    echo "<script>alert('Weekly report file must be less than 500 KB'); window.location.href='add_update.php';</script>";
                    exit();
                }

                $upload_dir = "../uploads/weekly_reports/";

                if(!is_dir($upload_dir))
                {
                    mkdir($upload_dir, 0777, true);
                }

                if(!is_writable($upload_dir))
                {
                    die("Upload folder is not writable");
                }

                $file_name = time() . "_" . basename($_FILES['update_file']['name']);
                $temp_name = $_FILES['update_file']['tmp_name'];
                $destination = $upload_dir . $file_name;

                if(!move_uploaded_file($temp_name, $destination))
                {
                    die("File upload failed");
                }
            }
            else
            {
                die("Upload Error Code: " . $_FILES['update_file']['error']);
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

                echo "<script>alert('Weekly Update Submitted Successfully'); window.location='add_update.php';</script>";
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
            margin:auto;
            margin-top:40px;
            background:white;
            padding:30px;
            border-radius:15px;
            box-shadow:0px 2px 10px rgba(0,0,0,0.1);
        }

        .marks-box{
            background:#f8fafc;
            border:1px solid #e2e8f0;
            border-radius:12px;
            padding:16px;
        }
    </style>
</head>
<body>
<br>
<a href="dashboard.php" class="btn btn-primary mb-3">← Back</a>

<div class="form-box">

    <h2 class="mb-4 text-center">Weekly Progress Update</h2>

    <h4 class="mb-3">Weekly Submission Deadlines</h4>

    <table class="table table-bordered">
        <thead class="table-dark">
            <tr>
                <th>Week</th>
                <th>Deadline</th>
                <th>Submission Time</th>
                <th>Delay</th>
                <th>Marks</th>
            </tr>
        </thead>
        <tbody>
        <?php if(count($deadlines) > 0) { ?>
            <?php for($i=1; $i<=4; $i++) { ?>
                <tr>
                    <td>Week <?php echo $i; ?></td>
                    <td>
                        <?php
                        echo !empty($deadlines[$i]['deadline_date'])
                            ? date("d M Y h:i A", strtotime($deadlines[$i]['deadline_date']))
                            : "-";
                        ?>
                    </td>
                    <td>
                        <?php
                        echo !empty($submissions[$i]['submitted_at'])
                            ? date("d M Y h:i A", strtotime($submissions[$i]['submitted_at']))
                            : "Not Submitted";
                        ?>
                    </td>
                    <td>
                        <?php
                        if(!empty($deadlines[$i]['deadline_date']) && !empty($submissions[$i]['submitted_at']))
                        {
                            $delay_seconds = strtotime($submissions[$i]['submitted_at']) - strtotime($deadlines[$i]['deadline_date']);
                            if($delay_seconds <= 0)
                            {
                                echo "On Time";
                            }
                            else
                            {
                                echo ceil($delay_seconds / 86400) . " day(s) late";
                            }
                        }
                        else
                        {
                            echo "-";
                        }
                        ?>
                    </td>
                    <td>
                        <strong><?php echo $weekly_marks[$i]; ?></strong>
                    </td>
                </tr>
            <?php } ?>
        <?php } else { ?>
            <tr>
                <td colspan="5" class="text-center text-danger">
                    No deadlines added by faculty yet.
                </td>
            </tr>
        <?php } ?>
        </tbody>
    </table>

    <div class="marks-box mb-4">
        <h5 class="mb-2">Weekly Total: <?php echo $weekly_total; ?> / 40</h5>
        <small class="text-muted">
            Marks are automatically calculated from submission delay.
        </small>
    </div>

    <hr>

    <form method="POST" enctype="multipart/form-data">

        <div class="mb-3">
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

        <div class="mb-3">
            <label>Week Number</label>

            <select name="week_no"
                    class="form-control"
                    required
                    <?php if(!$internship) echo "disabled"; ?>>

                <option value="">Select Week</option>

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

        <div class="mb-3">
            <label>Work Done (in 2-3 lines)</label>

            <textarea name="work_done"
                      class="form-control"
                      rows="5"
                      required
                      <?php if(!$internship) echo "disabled"; ?>></textarea>
        </div>

        <div class="mb-3">
            <label>Upload File (1-2 pages file, Max 500 KB)</label>

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