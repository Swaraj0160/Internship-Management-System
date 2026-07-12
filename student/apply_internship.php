<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'student')
{
    header("Location: ../login.php");
    exit();
}

$student_id = $_SESSION['user_id'];

/* EDIT MODE */

$edit_mode = false;
$edit_data = null;

if(isset($_GET['edit']))
{
    $edit_id = (int)$_GET['edit'];

    $edit_query = mysqli_query(
        $conn,
        "SELECT *
         FROM internships
         WHERE id='$edit_id'
         AND student_id='$student_id'"
    );

    if(mysqli_num_rows($edit_query) > 0)
    {
        $edit_mode = true;
        $edit_data = mysqli_fetch_assoc($edit_query);
    }
}

/*
ONCE AN INTERNSHIP IS APPROVED, ALL FIELDS ARE LOCKED
EXCEPT MINI PROJECT TITLE
*/

$is_locked_edit =
$edit_mode && $edit_data['status'] == 'Approved';


/*
CHECK IF STUDENT ALREADY HAS APPROVED INTERNSHIP
*/

$check_query = "

SELECT *
FROM internships
WHERE student_id='$student_id'
ORDER BY id DESC
LIMIT 1

";

$check_result = mysqli_query($conn, $check_query);

$existing_application =
mysqli_fetch_assoc($check_result);

$application_exists =
($existing_application) ? true : false;

if(isset($_POST['submit']))
{
    /*
    LOCKED EDIT MODE (APPROVED INTERNSHIP)
    ONLY mini_project_title CAN BE UPDATED.
    NO OTHER COLUMN, NO FILE UPLOAD, NO STATUS CHANGE.
    */

    if($is_locked_edit)
    {
        $locked_edit_id = (int)$edit_data['id'];

        $mini_project_title = mysqli_real_escape_string($conn, $_POST['mini_project_title']);

        $query = "

        UPDATE internships

        SET mini_project_title='$mini_project_title'

        WHERE id='$locked_edit_id'
        AND student_id='$student_id'
        AND status='Approved'

        ";

        mysqli_query($conn, $query);

        echo "
        <script>
        alert('Mini Project Title Updated Successfully');
        window.location.href='my_internships.php';
        </script>
        ";

        exit();
    }

    if($application_exists && !$edit_mode)
    {
        $status = $existing_application['status'];

        if($status == 'Pending')
        {
            echo "
            <script>
            alert('Your internship application is under review.');
            window.location='my_internships.php';
            </script>
            ";
            exit();
        }

        if($status == 'Rejected')
        {
            echo "
            <script>
            alert('Your application was rejected. Please edit and resubmit it from My Applications.');
            window.location='my_internships.php';
            </script>
            ";
            exit();
        }

        if($status == 'Approved')
        {
            echo "
            <script>
            alert('You already have an approved internship.');
            window.location='dashboard.php';
            </script>
            ";
            exit();
        }
    }

    $company_name = mysqli_real_escape_string($conn, $_POST['company_name']);

    $company_contact = mysqli_real_escape_string($conn, $_POST['company_contact']);

    $internship_role = mysqli_real_escape_string($conn, $_POST['internship_role']);

    $mini_project_title = mysqli_real_escape_string($conn, $_POST['mini_project_title']);

    $start_date = mysqli_real_escape_string($conn, $_POST['start_date']);

    $end_date = mysqli_real_escape_string($conn, $_POST['end_date']);

    $stipend = mysqli_real_escape_string($conn, $_POST['stipend']);

    $mode = mysqli_real_escape_string($conn, $_POST['mode']);

    $mentor_name = mysqli_real_escape_string($conn, $_POST['mentor_name']);

    $internship_type = mysqli_real_escape_string($conn, $_POST['internship_type']);

    

/*
FILE UPLOAD
*/

$getStudent = mysqli_query($conn,"
SELECT full_name,enrollment_no,division
FROM users
WHERE id='$student_id'
");

$student = mysqli_fetch_assoc($getStudent);

$full_name = strtoupper(
    preg_replace('/[^A-Za-z0-9]/','_',$student['full_name'])
);

$enrollment = $student['enrollment_no'];
$division = $student['division'];
$base_name = $enrollment."_".$full_name;


$extension = strtolower(
    pathinfo(
        $_FILES['offer_letter']['name'],
        PATHINFO_EXTENSION
    )
);

$allowed_types = ['pdf'];

if(!in_array($extension, $allowed_types))
{
    die("Only PDF files are allowed.");
}

$max_size = 500 * 1024; // 500 KB

if($_FILES['offer_letter']['size'] > $max_size)
{
    echo "
    <script>
    alert('Offer Letter size must be less than 500 KB');
    window.location.href='apply_internship.php';
    </script>
    ";
    exit();
}

$upload_dir = "../uploads/offer_letters/".$division."/";

if(!file_exists($upload_dir))
{
    mkdir($upload_dir,0777,true);
}

$file_name =
$enrollment."_".$full_name.".".$extension;

$temp_name = $_FILES['offer_letter']['tmp_name'];

if(!move_uploaded_file(
    $temp_name,
    $upload_dir.$file_name
))
{
    die("File Upload Failed");
}
    
if(isset($_POST['edit_id']))
{
    $old_extension = '';

    $oldQuery = mysqli_query($conn,"
        SELECT offer_letter
        FROM internships
        WHERE id='".(int)$_POST['edit_id']."'
        AND student_id='$student_id'
    ");

    if($oldRow = mysqli_fetch_assoc($oldQuery))
    {
        $oldFile =
        "../uploads/offer_letters/".$division."/".
        $oldRow['offer_letter'];

        if(
            file_exists($oldFile) &&
            basename($oldFile) != $file_name
        )
        {
            unlink($oldFile);
        }
    }
}

    
    /*
    REMOVE OLD REJECTED RECORDS
    */

if(isset($_POST['edit_id']))
{
    $edit_id = (int)$_POST['edit_id'];

    $statusQuery = mysqli_query($conn,"
    SELECT status,rejection_reason
    FROM internships
    WHERE id='$edit_id'
    AND student_id='$student_id'
    ");

    $statusRow = mysqli_fetch_assoc($statusQuery);

    if($statusRow['status'] == 'Rejected')
    {
        $newStatus = 'Pending';
        $newReason = '';
    }
    else
    {
        $newStatus = $statusRow['status'];
        $newReason = $statusRow['rejection_reason'];
    }

    $query = "

UPDATE internships

SET

company_name='$company_name',
company_contact='$company_contact',
internship_role='$internship_role',
mini_project_title='$mini_project_title',
start_date='$start_date',
end_date='$end_date',
stipend='$stipend',
mode='$mode',
mentor_name='$mentor_name',
internship_type='$internship_type',
offer_letter='$file_name',
status='$newStatus',
rejection_reason='$newReason'

WHERE id='$edit_id'
AND student_id='$student_id'

    ";

    mysqli_query($conn,$query);

    echo "

    <script>

    alert('Application Updated Successfully');

    window.location.href='my_internships.php';

    </script>

    ";

    exit();
}
else
{
    $query = "

    INSERT INTO internships
    (
        student_id,
        company_name,
        company_contact,
        internship_role,
        mini_project_title,
        start_date,
        end_date,
        stipend,
        mode,
        mentor_name,
        internship_type,
        offer_letter,
        status
    )

    VALUES
    (
        '$student_id',
        '$company_name',
        '$company_contact',
        '$internship_role',
        '$mini_project_title',
        '$start_date',
        '$end_date',
        '$stipend',
        '$mode',
        '$mentor_name',
        '$internship_type',
        '$file_name',
        'Pending'
    )

    ";

    mysqli_query($conn,$query);

    echo "

    <script>

    alert('Internship Applied Successfully');

    window.location.href='dashboard.php';

    </script>

    ";

    exit();
}

    echo "

    <script>

    alert('Internship Applied Successfully');

    window.location.href='dashboard.php';

    </script>

    ";
}

?>

<!DOCTYPE html>
<html>
<head>

<title>Apply Internship</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>

body{
    background:#f5f5f5;
}

.form-box{
    width:700px;
    margin:auto;
    margin-top:40px;
    background:white;
    padding:30px;
    border-radius:15px;
    box-shadow:0px 0px 10px rgba(0,0,0,0.1);
}

.locked-field{
    background:#f1f3f5 !important;
    color:#495057;
}

</style>

</head>

<body><br>
        <a href="dashboard.php"
   class="btn btn-primary mb-3">
   ← Back
</a>

<div class="form-box">
    

    <h2 class="mb-4 text-center">

<?php
if($is_locked_edit)
{
    echo "Update Mini Project Title";
}
elseif($edit_mode)
{
    echo "Edit & Resubmit Internship Application";
}
else
{
    echo "Internship Application Form";
}
?>

</h2>

<?php

if($is_locked_edit)
{

?>

<div class="alert alert-success text-center">
    Your internship is Approved. Only the
    <strong>Mini Project Title</strong> can be updated now.
</div>

<form method="POST">

<input type="hidden"
       name="edit_id"
       value="<?php echo (int)$edit_data['id']; ?>">

    <div class="mb-3">
        <label>Company Name</label>
        <input type="text"
       class="form-control locked-field"
       value="<?php echo htmlspecialchars($edit_data['company_name'] ?? ''); ?>"
       readonly disabled>
    </div>

    <div class="mb-3">
        <label>Internship Role</label>
        <input type="text"
       class="form-control locked-field"
       value="<?php echo htmlspecialchars($edit_data['internship_role'] ?? ''); ?>"
       readonly disabled>
    </div>

    <div class="row">

        <div class="col-md-6">
            <div class="mb-3">
                <label>Start Date</label>
                <input type="text"
       class="form-control locked-field"
       value="<?php echo htmlspecialchars($edit_data['start_date'] ?? ''); ?>"
       readonly disabled>
            </div>
        </div>

        <div class="col-md-6">
            <div class="mb-3">
                <label>End Date</label>
                <input type="text"
       class="form-control locked-field"
       value="<?php echo htmlspecialchars($edit_data['end_date'] ?? ''); ?>"
       readonly disabled>
            </div>
        </div>

    </div>

    <div class="mb-3">
        <label>Mentor Name</label>
        <input type="text"
       class="form-control locked-field"
       value="<?php echo htmlspecialchars($edit_data['mentor_name'] ?? ''); ?>"
       readonly disabled>
    </div>

    <div class="mb-3">
        <label>Internship Type</label>
        <input type="text"
       class="form-control locked-field"
       value="<?php echo htmlspecialchars($edit_data['internship_type'] ?? ''); ?>"
       readonly disabled>
    </div>

    <div class="mb-3">
        <label>Mode</label>
        <input type="text"
       class="form-control locked-field"
       value="<?php echo htmlspecialchars($edit_data['mode'] ?? ''); ?>"
       readonly disabled>
    </div>

    <div class="mb-3">

        <label>Mini Project Title</label>

        <input type="text"
       name="mini_project_title"
       class="form-control"
       placeholder="Enter Mini Project Title"
       value="<?php echo htmlspecialchars($edit_data['mini_project_title'] ?? ''); ?>"
       required>

    </div>

    <button type="submit"
        name="submit"
        class="btn btn-primary w-100">
        Update Mini Project Title
    </button>

</form>

<?php

}
elseif($application_exists && !$edit_mode)
{

    if($existing_application['status']=='Pending')
    {
?>

<div class="alert alert-warning text-center">
    Your internship application is under review.
    Please check My Applications.
</div>

<?php
    }
    elseif($existing_application['status']=='Rejected')
    {
?>

<div class="alert alert-danger text-center">
    Your internship application was rejected.
    Please edit and resubmit it from My Applications.
</div>

<?php
    }
    elseif($existing_application['status']=='Approved')
    {
?>

<div class="alert alert-success text-center">
    Internship already approved.
    You cannot apply for another internship.
</div>

<?php
    }

}
else
{

?>

<form method="POST" enctype="multipart/form-data">
    <?php if($edit_mode){ ?>

<input type="hidden"
       name="edit_id"
       value="<?php echo htmlspecialchars($edit_data['id'] ?? ''); ?>">

<?php } ?>

    <div class="mb-3">

        <label>Company Name</label>

        <input type="text"
       name="company_name"
       class="form-control"
       value="<?php echo $edit_mode ? htmlspecialchars($edit_data['company_name'] ?? '') : ''; ?>"
       required>

    </div>

    <div class="mb-3">

        <label>Company Contact Number</label>

        <input type="text"
       name="company_contact"
       class="form-control"
       placeholder="9876543210"
       value="<?php echo $edit_mode ? htmlspecialchars($edit_data['company_contact'] ?? '') : ''; ?>"
       required>

    </div>

    <div class="mb-3">

        <label>Internship Role</label>

        <input type="text"
       name="internship_role"
       class="form-control"
       value="<?php echo $edit_mode ? htmlspecialchars($edit_data['internship_role'] ?? '') : ''; ?>"
       required>

    </div>

    <div class="mb-3">

        <label>Mini Project Title</label>

        <input type="text"
       name="mini_project_title"
       class="form-control"
       placeholder="Enter Mini Project Title"
       value="<?php echo $edit_mode ? htmlspecialchars($edit_data['mini_project_title'] ?? '') : ''; ?>"
       required>

    </div>

    <div class="row">

        <div class="col-md-6">

            <div class="mb-3">

                <label>Start Date</label>

                <input type="date"
       name="start_date"
       class="form-control"
       value="<?php echo $edit_mode ? htmlspecialchars($edit_data['start_date'] ?? '') : ''; ?>"
       required>

            </div>

        </div>

        <div class="col-md-6">

            <div class="mb-3">

                <label>End Date</label>

                <input type="date"
       name="end_date"
       class="form-control"
       value="<?php echo $edit_mode ? htmlspecialchars($edit_data['end_date'] ?? '') : ''; ?>"
       required>

            </div>

        </div>

    </div>

    <div class="mb-3">

        <label>Stipend</label>

        <input type="text"
       name="stipend"
       class="form-control"
       value="<?php echo $edit_mode ? htmlspecialchars($edit_data['stipend'] ?? '') : ''; ?>">

    </div>

    <div class="mb-3">

        <label>Mentor Name</label>

        <input type="text"
       name="mentor_name"
       class="form-control"
       placeholder="Enter Mentor Name"
       value="<?php echo $edit_mode ? htmlspecialchars($edit_data['mentor_name'] ?? '') : ''; ?>"
       required>

    </div>

    <div class="mb-3">

        <label>Internship Type</label>

       <select name="internship_type"
        class="form-control"
        required>

<option value="">Select Internship Type</option>

<option value="Problem-based Internship"
<?php if($edit_mode && $edit_data['internship_type']=="Problem-based Internship") echo "selected"; ?>>
Problem-based Internship
</option>

<option value="Training + Mini Project"
<?php if($edit_mode && $edit_data['internship_type']=="Training + Mini Project") echo "selected"; ?>>
Training + Mini Project
</option>

<option value="Real-world Exposure Internship (Field Work)"
<?php if($edit_mode && $edit_data['internship_type']=="Real-world Exposure Internship (Field Work)") echo "selected"; ?>>
Real-world Exposure Internship (Field Work)
</option>

<option value="Research Internship"
<?php if($edit_mode && $edit_data['internship_type']=="Research Internship") echo "selected"; ?>>
Research Internship
</option>

<option value="International Internship / Visit (Short-term programs)"
<?php if($edit_mode && $edit_data['internship_type']=="International Internship / Visit (Short-term programs)") echo "selected"; ?>>
International Internship / Visit (Short-term programs)
</option>

</select>

    </div>

    <div class="mb-3">

        <label>Mode</label>

        <select name="mode"
        class="form-control"
        required>

<option value="">Select Mode</option>

<option value="Online"
<?php if($edit_mode && $edit_data['mode']=="Online") echo "selected"; ?>>
Online
</option>

<option value="Offline"
<?php if($edit_mode && $edit_data['mode']=="Offline") echo "selected"; ?>>
Offline
</option>

<option value="Hybrid"
<?php if($edit_mode && $edit_data['mode']=="Hybrid") echo "selected"; ?>>
Hybrid
</option>

</select>

    </div>

    <div class="mb-3">

<label>
Upload Offer Letter
<small class="text-danger">
(Max 500 KB | PDF Only)
</small>
</label>

<input type="file"
       name="offer_letter"
       class="form-control"
       accept=".pdf"
       required>
       
    </div>

    <button type="submit"
        name="submit"
        class="btn btn-primary w-100">

<?php
if($edit_mode)
{
    echo "Update Application";
}
else
{
    echo "Submit Application";
}
?>

</button>

</form>

<?php

}

?>

</div>

</body>
</html>
