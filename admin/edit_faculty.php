<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    header("Location: ../login.php");
    exit();
}

$id = $_GET['id'];
$return = $_GET['return'] ?? "";

$query = "SELECT * FROM users
          WHERE id='$id'";

$result = mysqli_query($conn, $query);

$user = mysqli_fetch_assoc($result);

if(isset($_POST['update_faculty']))
{
    $full_name = $_POST['full_name'];
    $email = $_POST['email'];

    $update = "UPDATE users SET

               full_name='$full_name',
               email='$email'

               WHERE id='$id'";

    mysqli_query($conn, $update);

    echo "<script>alert('Faculty Updated Successfully');</script>";

    if($return != "")
{
    echo "<script>
            window.location='manage_faculty.php?$return';
          </script>";
}
else
{
    echo "<script>
            window.location='manage_faculty.php';
          </script>";
}
}

?>

<!DOCTYPE html>
<html>
<head>

<title>Edit Faculty</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>

body{
    background:#f1f5f9;
}

.form-box{
    width:600px;
    margin:auto;
    margin-top:40px;
    background:white;
    padding:30px;
    border-radius:15px;
}

</style>

</head>

<body>

<div class="form-box">

    <h2 class="mb-4">
        Edit Faculty
    </h2>

    <form method="POST">

        <div class="mb-3">

            <label>Full Name</label>

            <input type="text"
                   name="full_name"
                   class="form-control"
                   value="<?php echo $user['full_name']; ?>"
                   required>

        </div>

        <div class="mb-3">

            <label>Email</label>

            <input type="email"
                   name="email"
                   class="form-control"
                   value="<?php echo $user['email']; ?>"
                   required>

        </div>

        <button type="submit"
                name="update_faculty"
                class="btn btn-success w-100">

            Update Faculty

        </button>

    </form>

</div>

</body>
</html>