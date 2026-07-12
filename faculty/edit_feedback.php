<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty')
{
    header("Location: ../login.php");
    exit();
}

$id = $_GET['id'];

$query = "SELECT * FROM feedback WHERE id='$id'";
$result = mysqli_query($conn, $query);

$data = mysqli_fetch_assoc($result);

if(isset($_POST['update']))
{
    $remarks = $_POST['remarks'];

    $update = "UPDATE feedback
               SET remarks='$remarks'
               WHERE id='$id'";

    mysqli_query($conn, $update);

    echo "<script>alert('Feedback Updated Successfully');</script>";

    echo "<script>
            window.location='view_updates.php';
          </script>";
}

?>

<!DOCTYPE html>
<html>
<head>

<title>Edit Feedback</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>

body{
    background:#f5f5f5;
}

.form-box{
    width:700px;
    margin:auto;
    margin-top:50px;
    background:white;
    padding:40px;
    border-radius:15px;
}

</style>

</head>

<body>

<div class="form-box">

    <h2 class="text-center mb-4">
        Edit Feedback
    </h2>

    <form method="POST">

        <div class="mb-3">

            <label>Remarks</label>

            <textarea name="remarks"
                      class="form-control"
                      rows="6"
                      required><?php echo $data['remarks']; ?></textarea>

        </div>

        <button type="submit"
                name="update"
                class="btn btn-primary w-100">

            Update Feedback

        </button>

    </form>

</div>

</body>
</html>