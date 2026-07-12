<?php

session_start();
include("config.php");

if(!isset($_SESSION['temp_user_id']))
{
    header("Location: login.php");
    exit();
}

$message="";

if(isset($_POST['verify']))
{
    $otp = $_POST['otp'];

    $user_id = $_SESSION['temp_user_id'];

    $query = "SELECT * FROM login_otp
              WHERE user_id='$user_id'
              AND otp='$otp'
              AND expires_at > NOW()
              ORDER BY id DESC
              LIMIT 1";

    $result = mysqli_query($conn,$query);

    if(mysqli_num_rows($result)>0)
    {
        // get user details

        $userQuery =
        "SELECT * FROM users
        WHERE id='$user_id'";

        $userResult =
        mysqli_query($conn,$userQuery);

        $user =
        mysqli_fetch_assoc($userResult);

        // create actual login session

        $_SESSION['user_id']
        = $user['id'];

        $_SESSION['name']
        = $user['full_name'];

        $_SESSION['role']
        = $user['role'];

        // remove temporary session

        unset($_SESSION['temp_user_id']);
        unset($_SESSION['temp_email']);

        // remove OTP after successful login

        mysqli_query(
        $conn,
        "DELETE FROM login_otp
        WHERE user_id='$user_id'"
        );
        /* remember device for 2 days */

$token = bin2hex(random_bytes(32));

$expiry = date(
'Y-m-d H:i:s',
strtotime('+2 days')
);

$hashedToken=
hash(
'sha256',
$token
);

mysqli_query(
$conn,
"INSERT INTO trusted_devices
(user_id,token,expires_at)

VALUES
('$user_id','$hashedToken','$expiry')"
);

setcookie(
'trusted_device',
$token,
time()+(60*60*24*2),
"/",
"",
true,
true
);

        // redirect according to role

        if($user['role']=="student")
        {
            header(
            "Location: student/dashboard.php"
            );
        }
        elseif($user['role']=="faculty")
        {
            header(
            "Location: faculty/dashboard.php"
            );
        }
        elseif($user['role']=="admin")
        {
            header(
            "Location: admin/dashboard.php"
            );
        }

        exit();
    }
    else
    {
        $message =
        "Invalid or Expired OTP";
    }
}

?>

<!DOCTYPE html>
<html>
<head>

<title>Verify OTP</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>

body{
background:#6a11cb;
height:100vh;
display:flex;
justify-content:center;
align-items:center;
font-family:Arial;
}

.box{

background:white;
padding:40px;
width:400px;
border-radius:15px;
text-align:center;

}

button{

width:100%;

}

</style>

</head>

<body>

<div class="box">

<h3>Verify Login OTP</h3>

<p>
OTP sent to your email
</p>

<?php
if($message!="")
{
echo "<div class='alert alert-danger'>
$message
</div>";
}
?>

<form method="POST">

<input
type="text"
name="otp"
class="form-control mb-3"
placeholder="Enter OTP"
required>

<button
type="submit"
name="verify"
class="btn btn-primary">

Verify OTP

</button>

</form>

</div>

</body>
</html>