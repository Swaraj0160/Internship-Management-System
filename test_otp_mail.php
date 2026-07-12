<?php

require 'mail_function.php';

$email="noreply@mitinternship.online";

$subject="OTP Test";

$message="
<h2>MIT Internship Portal</h2>
<p>Your OTP is:</p>
<h1>123456</h1>
";

if(sendPortalMail($email,$subject,$message))
{
    echo "Email Sent Successfully";
}
else
{
    echo "Failed";
}
?>