<?php 

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty')
{
    header("Location: ../login.php");
    exit();
}

$faculty_id = $_SESSION['user_id'];

/* GET FACULTY DIVISION */

$getFaculty = mysqli_query(
    $conn,
    "SELECT faculty_division FROM users 
     WHERE id='$faculty_id'"
);

$facultyData = mysqli_fetch_assoc($getFaculty);

$division = $facultyData['faculty_division'];

/* DELETE DEADLINE */

if(isset($_GET['delete']))
{
    $id = (int)$_GET['delete'];

    mysqli_query(
        $conn,
        "DELETE FROM weekly_deadlines
         WHERE id='$id'
         AND division='$division'"
    );

    echo "
    <script>
        alert('Deadline Deleted Successfully');
        window.location='manage_deadlines.php';
    </script>
    ";
    exit();
}

/* SAVE DEADLINE */

if(isset($_POST['save_deadline']))
{
    $week_no = $_POST['week_no'];
    $deadline = $_POST['deadline_date'];

    $check = mysqli_query(
        $conn,
        "SELECT * FROM weekly_deadlines
         WHERE division='$division'
         AND week_no='$week_no'"
    );

    if(mysqli_num_rows($check) > 0)
    {
        mysqli_query(
            $conn,
            "UPDATE weekly_deadlines
             SET deadline_date='$deadline'
             WHERE division='$division'
             AND week_no='$week_no'"
        );
    }
    else
    {
        mysqli_query(
            $conn,
            "INSERT INTO weekly_deadlines
            (
                division,
                week_no,
                deadline_date
            )
            VALUES
            (
                '$division',
                '$week_no',
                '$deadline'
            )"
        );
    }

    echo "<script>alert('Deadline Saved Successfully');</script>";
}

/* FETCH DEADLINES */

$deadlines = mysqli_query(
    $conn,
    "SELECT * FROM weekly_deadlines
     WHERE division='$division'
     ORDER BY week_no ASC"
);

?>

<!DOCTYPE html>
<html>
<head>

<title>Manage Deadlines</title>

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
        Weekly Report Deadlines
    </h2>

    <h5 class="mb-4">
        Division: <?php echo $division; ?>
    </h5>

    <form method="POST">

        <div class="row">

            <div class="col-md-4 mb-3">

                <label class="form-label">
                    Week Number
                </label>

                <select name="week_no"
                        class="form-control"
                        required>

                    <option value="">
                        Select Week
                    </option>

                    <?php
                    for($i=1; $i<=4; $i++)
                    {
                    ?>

                    <option value="<?php echo $i; ?>">
                        Week <?php echo $i; ?>
                    </option>

                    <?php
                    }
                    ?>

                </select>

            </div>

            <div class="col-md-4 mb-3">

                <label class="form-label">
                    Deadline Date & Time
                </label>

                <input type="datetime-local"
                       name="deadline_date"
                       class="form-control"
                       required>

            </div>

            <div class="col-md-4 mb-3 d-flex align-items-end">

                <button type="submit"
                        name="save_deadline"
                        class="btn btn-primary w-100">

                    Save Deadline

                </button>

            </div>

        </div>

    </form>

    <hr>

    <h4 class="mb-3">
        Existing Deadlines
    </h4>

    <table class="table table-bordered table-striped">

        <thead class="table-dark">

            <tr>

                <th>Week</th>
                <th>Deadline</th>
                <th>Action</th>

            </tr>

        </thead>

        <tbody>

        <?php

        if(mysqli_num_rows($deadlines) > 0)
        {
            while($row = mysqli_fetch_assoc($deadlines))
            {
        ?>

<tr>

    <td>
        Week <?php echo $row['week_no']; ?>
    </td>

    <td>
<?php

if(
    !empty($row['deadline_date']) &&
    $row['deadline_date'] != '0000-00-00 00:00:00'
)
{
    echo date(
        "d M Y h:i A",
        strtotime($row['deadline_date'])
    );
}
else
{
    echo "<span class='text-muted fw-bold text-secondary'>
            Not Set
          </span>";
}

?>
</td>

    <td>

        <a href="?delete=<?php echo $row['id']; ?>"
           class="btn btn-danger btn-sm"
           onclick="return confirm('Are you sure you want to delete this deadline?')">

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

            <td colspan="2" class="text-center text-danger">
                No deadlines added yet.
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