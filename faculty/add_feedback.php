<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty')
{
    header("Location: ../login.php");
    exit();
}

$faculty_id = $_SESSION['user_id'];

$faculty_query = "SELECT faculty_division
                  FROM users
                  WHERE id='$faculty_id'";

$faculty_result = mysqli_query($conn, $faculty_query);

$faculty_data = mysqli_fetch_assoc($faculty_result);

$faculty_division = $faculty_data['faculty_division'];

$query = "SELECT internships.id,
                 internships.company_name,
                 users.full_name
          FROM internships
          JOIN users
          ON internships.student_id = users.id
          WHERE users.division='$faculty_division'
          AND internships.status='Approved'";

$result = mysqli_query($conn, $query);

if(isset($_POST['submit']))
{
    $internship_id = $_POST['internship_id'];
    $week_no = $_POST['week_no'];
    $remarks = $_POST['remarks'];

    $insert = "INSERT INTO feedback
    (
        internship_id,
        week_no,
        remarks
    )

    VALUES
    (
        '$internship_id',
        '$week_no',
        '$remarks'
    )";

    mysqli_query($conn, $insert);

    echo "<script>alert('Feedback Submitted Successfully');</script>";
}

?>

<!DOCTYPE html>
<html>
<head>

<title>Add Feedback</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>

body{
    background:#f5f5f5;
}

.form-box{
    width:750px;
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

    <h1 class="text-center mb-4">
        Internship Evaluation
    </h1>

    <form method="POST">

        <div class="mb-3">

            <label>Select Student Internship</label>

            <select name="internship_id"
                    class="form-control"
                    required>

                <?php

                while($row = mysqli_fetch_assoc($result))
                {

                ?>

                <option value="<?php echo $row['id']; ?>">

                    <?php echo $row['full_name']; ?>
                    -
                    <?php echo $row['company_name']; ?>

                </option>

                <?php

                }

                ?>

            </select>

        </div>

        <div class="mb-3">

            <label>Week Number</label>

            <input type="number"
                   name="week_no"
                   class="form-control"
                   required>

        </div>

        <div class="mb-3">

            <label>Remarks</label>

            <textarea name="remarks"
                      class="form-control"
                      rows="5"
                      required></textarea>

        </div>

        <button type="submit"
                name="submit"
                class="btn btn-primary w-100">

            Submit Feedback

        </button>

    </form>

</div>

</body>
</html>