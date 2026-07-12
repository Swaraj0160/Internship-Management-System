<?php

session_start();

require 'config.php';

$otp=$_POST['otp'];

$user_id=$_SESSION['temp_user_id'];

$stmt=$conn->prepare(
"SELECT * FROM login_otp

WHERE user_id=?
AND otp=?
AND expires_at>NOW()"
);

$stmt->bind_param(
"is",
$user_id,
$otp
);

$stmt->execute();

$query=$stmt->get_result();

if($query->num_rows==0)
{
    echo json_encode([
        "status"=>false,
        "message"=>"Invalid OTP"
    ]);
    exit;
}

$stmt2=$conn->prepare(
"SELECT * FROM users
WHERE id=?"
);

$stmt2->bind_param(
"i",
$user_id
);

$stmt2->execute();

$userResult=$stmt2->get_result();

$user=$userResult->fetch_assoc();

session_regenerate_id(true);

$_SESSION['user_id']=$user['id'];
$_SESSION['name']=$user['full_name'];
$_SESSION['role']=$user['role'];

unset($_SESSION['temp_user_id']);
unset($_SESSION['temp_email']);

mysqli_query(
$conn,
"DELETE FROM login_otp
WHERE user_id='$user_id'"
);

echo json_encode([
"status"=>true,
"role"=>$user['role']
]);

?>