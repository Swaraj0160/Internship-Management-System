<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty')
{
    header("Location: ../login.php");
    exit();
}

$id = $_GET['id'];

$query = "SELECT evaluations.*,
                 users.full_name,
                 users.enrollment_no,
                 internships.company_name
          FROM evaluations
          JOIN internships
          ON evaluations.internship_id = internships.id
          JOIN users
          ON internships.student_id = users.id
          WHERE evaluations.id='$id'";

$result = mysqli_query($conn, $query);

$row = mysqli_fetch_assoc($result);

if(isset($_POST['update']))
{
    $ta1 = $_POST['ta1'];
    $ta2 = $_POST['ta2'];
    $attendance = $_POST['attendance'];
    $final_eval = $_POST['final_eval'];

    $total =
        $ta1 +
        $ta2 +
        $attendance +
        $final_eval;

    $update_query = "UPDATE evaluations
                     SET
                     ta1_marks='$ta1',
                     ta2_marks='$ta2',
                     attendance_marks='$attendance',
                     final_evaluation_marks='$final_eval',
                     total_marks='$total'
                     WHERE id='$id'";

    mysqli_query($conn, $update_query);

    echo "<script>
            alert('Evaluation Updated Successfully');
            window.location='student_evaluations.php';
          </script>";
}

?>

<!DOCTYPE html>
<html>
<head>

<title>Edit Evaluation</title>

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
    padding:40px;
    border-radius:15px;
}

</style>

</head>

<body>

<div class="form-box">

<h2 class="text-center mb-4">
    Edit Student Evaluation
</h2>

<div class="mb-3">
    <strong>Student:</strong>
    <?php echo $row['full_name']; ?>
</div>

<div class="mb-3">
    <strong>Enrollment No:</strong>
    <?php echo $row['enrollment_no']; ?>
</div>

<div class="mb-3">
    <strong>Company:</strong>
    <?php echo $row['company_name']; ?>
</div>

<form method="POST">

    <h4 class="mt-4">TA-1</h4>

    <div class="mb-3">

        <label>TA-1 Marks (Out of 10)</label>

        <input type="number"
               name="ta1"
               class="form-control"
               min="0"
               max="10"
               value="<?php echo isset($row['ta1_marks']) ? $row['ta1_marks'] : ''; ?>"
               required>

    </div>

    <h4 class="mt-4">TA-2</h4>

    <div class="mb-3">

        <label>TA-2 Marks (Out of 10)</label>

        <input type="number"
               name="ta2"
               class="form-control"
               min="0"
               max="10"
               value="<?php echo isset($row['ta2_marks']) ? $row['ta2_marks'] : ''; ?>"
               required>

    </div>

    <h4 class="mt-4">Attendance</h4>

    <div class="mb-3">

        <label>Attendance Marks (Out of 5)</label>

        <input type="number"
               name="attendance"
               class="form-control"
               min="0"
               max="5"
               value="<?php echo isset($row['attendance_marks']) ? $row['attendance_marks'] : ''; ?>"
               required>

    </div>

    <h4 class="mt-4">Final Evaluation</h4>

    <div class="mb-3">

        <label>Final Evaluation Marks (Out of 25)</label>

        <input type="number"
               name="final_eval"
               class="form-control"
               min="0"
               max="25"
               value="<?php echo isset($row['final_evaluation_marks']) ? $row['final_evaluation_marks'] : ''; ?>"
               required>

    </div>

    <button type="submit"
            name="update"
            class="btn btn-warning w-100">

        Update Evaluation

    </button>

</form>

</div>

</body>
</html>