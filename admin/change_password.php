<?php


session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    header("Location: ../login.php");
    exit();
}

$message = "";

if(isset($_POST['change_password']))
{
    $admin_id = $_SESSION['user_id'];

    $current_password = mysqli_real_escape_string(
        $conn,
        $_POST['current_password']
    );

    $new_password = mysqli_real_escape_string(
        $conn,
        $_POST['new_password']
    );

    $confirm_password = mysqli_real_escape_string(
        $conn,
        $_POST['confirm_password']
    );

    $query = "SELECT password
              FROM users
              WHERE id='$admin_id'";

    $result = mysqli_query($conn,$query);

    $user = mysqli_fetch_assoc($result);

    if($current_password != $user['password'])
    {
        $message = "
        <div class='alert alert-danger'>
            Current Password is Incorrect
        </div>";
    }
    else if($new_password != $confirm_password)
    {
        $message = "
        <div class='alert alert-danger'>
            New Password and Confirm Password do not match
        </div>";
    }
    else
    {
        mysqli_query($conn,"
        UPDATE users
        SET password='$new_password'
        WHERE id='$admin_id'
        ");

        $message = "
        <div class='alert alert-success'>
            Password Changed Successfully
        </div>";
    }
}

?>

<!DOCTYPE html>
<html>

<head>

<title>Admin Change Password</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
rel="stylesheet">

<style>

body{
    background:#f5f5f5;
    font-family:Arial;
}

.password-box{
    width:500px;
    margin:auto;
    margin-top:60px;
    background:white;
    padding:30px;
    border-radius:15px;
    box-shadow:0px 0px 10px rgba(0,0,0,0.1);
}

h2{
    text-align:center;
    margin-bottom:25px;
}

body{
    background:#f5f7fb;
    font-family:Arial, sans-serif;
}

.password-box{
    width:550px;
    margin:40px auto;
    background:#fff;
    padding:35px;
    border-radius:20px;
    box-shadow:0 10px 30px rgba(0,0,0,.08);
}

.page-header{
    margin-bottom:30px;
}

.page-header h2{
    text-align:center;
}

.page-header p{
    text-align:center;
}

.page-header h2{
    margin:0;
    font-size:34px;
    font-weight:700;
}

.page-header p{
    margin:5px 0 0;
    color:#6c757d;
}

.form-control{
    border-radius:10px;
    padding:12px;
}

.btn{
    border-radius:10px;
    font-weight:600;
    padding:10px 20px;
}

label{
    font-weight:600;
}

.alert{
    border-radius:10px;
}

</style>

</head>

<body>

<div class="password-box">

<div class="page-header">

<div>

<a href="dashboard.php"
class="btn btn-primary btn-sm mb-3 px-3">

← Back

</a>

<h2>

Change Password

</h2>

<p>

Update your administrator account password securely.

</p>

</div>

</div>

<?php echo $message; ?>

<form method="POST">

<div class="mb-3">

<label class="form-label">
Current Password *
</label>

<input type="password"
       name="current_password"
       class="form-control"
       placeholder="Enter current password"
       required>

</div>

<div class="mb-3">

<label class="form-label">
New Password *
</label>

<input type="password"
       name="new_password"
       class="form-control"
       placeholder="Enter new password"

       required>

</div>

<div class="mb-3">

<label class="form-label">
Confirm New Password *
</label>

<input type="password"
       name="confirm_password"
       class="form-control"
       placeholder="Re-enter new password"
       required>

</div>

<div class="d-grid mt-4">

<button
type="submit"
name="change_password"
class="btn btn-primary">

<i class="bi bi-key-fill"></i> Change Password

</button>

</div>

</form>

</div>

</body>
</html>
