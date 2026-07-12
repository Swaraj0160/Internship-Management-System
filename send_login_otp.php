<?php

session_start();

require 'config.php';
require 'mail_function.php';

if(!isset($_SESSION['temp_email']))
{
    header("Location: login.php");
    exit();
}

$email=$_SESSION['temp_email'];

$query=mysqli_query(
$conn,
"SELECT * FROM users WHERE email='$email'"
);

if(mysqli_num_rows($query)==0)
{
    die("User not found");
}

$user=mysqli_fetch_assoc($query);

$user_id=$user['id'];

$otp=random_int(
100000,
999999
);

$expiry=date(
'Y-m-d H:i:s',
strtotime('+5 minutes')
);

/* remove previous OTP */

mysqli_query(
$conn,
"DELETE FROM login_otp
WHERE user_id='$user_id'"
);

/* insert new OTP */

mysqli_query(
$conn,
"INSERT INTO login_otp
(user_id,otp,expires_at)

VALUES
('$user_id','$otp','$expiry')"
);

/* send email */

$message="

<h2>MIT Internship Portal</h2>

<p>Your login OTP is:</p>

<h1>$otp</h1>

<p>OTP expires in 5 minutes.</p>

";

sendPortalMail(
$email,
'MIT Internship Login OTP',
$message
);

/* save OTP email log */

mysqli_query(
$conn,
"INSERT INTO otp_logs(email)
VALUES('$email')"
);

/* redirect to OTP page */

header("Location: otp_verify.php");
exit();

?>