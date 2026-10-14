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

if(!isset($_GET['id']))
{
    header("Location: my_updates.php");
    exit();
}

/* -----------------------------------------------------------------
   REPLACE — sidebar propagation (Phase 2) + one real fix made while
   touching this file: $update_id came straight from $_GET['id'] and
   was concatenated directly into the SQL WHERE clause (SQL
   injection). Cast to int and switched to a prepared statement.
   Output that was previously echoed with no escaping (company_name,
   work_done, update_file filename) now goes through
   htmlspecialchars().
   ----------------------------------------------------------------- */

$update_id = (int)$_GET['id'];

/* FETCH UPDATE */

$stmt = $conn->prepare(
    "SELECT
        weekly_updates.*,
        internships.company_name

     FROM weekly_updates

     INNER JOIN internships
     ON weekly_updates.internship_id = internships.id

     WHERE weekly_updates.id=?
     AND internships.student_id=?"
);
$stmt->bind_param("ii", $update_id, $student_id);
$stmt->execute();
$query = $stmt->get_result();

if(mysqli_num_rows($query) == 0)
{
    header("Location: my_updates.php");
    exit();
}

$data = mysqli_fetch_assoc($query);

$activePage = 'my_updates.php';
if($data['review_status'] == 'Accepted')
{
    echo "
    <script>
    alert('Approved reports cannot be edited');
    window.location='my_updates.php';
    </script>
    ";
    exit();
}

/* UPDATE WORK */

if(isset($_POST['update_work']))
{
    $work_done = mysqli_real_escape_string(
    $conn,
    $_POST['work_done']
);

    $file_name = $data['update_file'];

    /* CHECK NEW FILE */

if(empty($_FILES['update_file']['name']))
{
    echo "
    <script>
    alert('Please upload a new weekly report file');
    window.history.back();
    </script>
    ";
    exit();
}

$max_size = 500 * 1024; // 500 KB

if($_FILES['update_file']['size'] > $max_size)
{
    echo "
    <script>
    alert('Weekly report file must be less than 500 KB');
    window.history.back();
    </script>
    ";
    exit();
}

$file_name =
time() . "_" .
basename($_FILES['update_file']['name']);

$temp_name = $_FILES['update_file']['tmp_name'];

$upload_path =
"../uploads/weekly_reports/".$file_name;

move_uploaded_file(
    $temp_name,
    $upload_path
);

/* DELETE OLD FILE */

if(
    !empty($data['update_file'])
    &&
    file_exists(
        "../uploads/weekly_reports/" .
        $data['update_file']
    )
)
{
    unlink(
        "../uploads/weekly_reports/" .
        $data['update_file']
    );
}

    mysqli_query(
    $conn,
    "UPDATE weekly_updates
SET
work_done='$work_done',
update_file='$file_name',
submitted_at=NOW(),
review_status='Pending',
faculty_feedback=''
WHERE id='$update_id'"
);
    echo "<script>alert('Weekly Work Updated Successfully');</script>";

    echo "<script>
            window.location='my_updates.php';
          </script>";
}

?>

<!DOCTYPE html>
<html>
<head>

<title>Edit Weekly Work</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?php include __DIR__ . '/includes/sidebar_head.php'; ?>

<style>

body{
    background:#f1f5f9;
    margin:0;
}

.page-inner{
    padding:0 24px 40px;
}

.form-box{
    width:800px;
    max-width:100%;
    margin:auto;
    margin-top:24px;
    background:white;
    padding:30px;
    border-radius:15px;
    box-shadow:0px 2px 10px rgba(0,0,0,0.1);
}

</style>

</head>

<body>

<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="page-inner">
<div class="form-box">

    <h2 class="mb-4 text-center">

        Edit Weekly Work

    </h2>

    <div class="mb-4">

        <strong>Company:</strong>

        <?php echo htmlspecialchars($data['company_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>

        <br><br>

        <strong>Week:</strong>

        Week <?php echo (int)$data['week_no']; ?>

    </div>

    <form method="POST" enctype="multipart/form-data">

        <div class="mb-3">

            <label class="form-label">

                Work Done

            </label>

            <textarea name="work_done"
                      class="form-control"
                      rows="8"
                      required><?php echo htmlspecialchars($data['work_done'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>

        </div>

        <div class="mb-3">

            <label class="form-label">

                Current File

            </label>

            <br>

            <?php

            if($data['update_file'] != "")
            {
            ?>

            <a href="../uploads/weekly_reports/<?php echo rawurlencode($data['update_file']); ?>"
               target="_blank">

               View Current File

            </a>

            <?php
            }
            else
            {
                echo "No File Uploaded";
            }

            ?>

        </div>

        <div class="mb-3">

<label class="form-label">
    Upload New File (Required, Max 500 KB)
</label>

            <input type="file"
       name="update_file"
       class="form-control"
       required>

        </div>

        <button type="submit"
        name="update_work"
        class="btn btn-success w-100">

    Resubmit Weekly Work

</button>

    </form>

</div>
</div>

</main>
</div>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>

</body>
</html>