<?php

session_start();
include("config.php");

if(isset($_POST['login']))
{
    $email=mysqli_real_escape_string(
$conn,
trim($_POST['email'])
);

$password=$_POST['password'];

$stmt = $conn->prepare(
"SELECT * FROM login_attempts
WHERE email = ?"
);

$stmt->bind_param(
"s",
$email
);

$stmt->execute();

$check = $stmt->get_result();

if(mysqli_num_rows($check)>0)
{
    $data=mysqli_fetch_assoc($check);

    if(
    $data['attempts']>=5 &&
    strtotime($data['last_attempt'])
    > strtotime('-10 minutes')
    )
    {
        die(
        "<script>
        alert('Too many attempts. Try after 10 minutes');
        window.location='login.php';
        </script>"
        );
    }
}

$stmt=$conn->prepare(
"SELECT * FROM users
WHERE email=?"
);

$stmt->bind_param(
"s",
$email
);

$stmt->execute();

$result=$stmt->get_result();

    if(mysqli_num_rows($result)>0)
    {
        $row = mysqli_fetch_assoc($result);

        /* PASSWORD CHECK */

        $storedPassword = $row['password'];

        $passwordMatched = false;

        // Support old plain passwords + future hashed passwords

        if(password_verify($password,$storedPassword))
        {
            $passwordMatched = true;
        }
        elseif($password == $storedPassword)
        {
            $passwordMatched = true;
        }

        if($passwordMatched)
{
    // clear login attempts

    $stmt=$conn->prepare(
    "DELETE FROM login_attempts
    WHERE email=?"
    );

    $stmt->bind_param(
    "s",
    $email
    );

    $stmt->execute();

    // if OTP enabled → go OTP route

    if($row['auth_enabled']==1)
{
    $skipOTP=false;

    if(isset($_COOKIE['trusted_device']))
    {
        $token=
hash(
'sha256',
$_COOKIE['trusted_device']
);

        $stmt = $conn->prepare(
"SELECT *
FROM trusted_devices
WHERE user_id = ?
AND token = ?
AND expires_at > NOW()"
);

$stmt->bind_param(
"is",
$row['id'],
$token
);

$stmt->execute();

$check = $stmt->get_result();

        if(mysqli_num_rows($check)>0)
        {
            $skipOTP=true;
        }
    }

    if(!$skipOTP)
    {
        $_SESSION['temp_user_id']
        = $row['id'];

        $_SESSION['temp_email']
        = $row['email'];

        header(
        "Location: send_login_otp.php"
        );

        exit();
    }
}

    // normal login

    session_regenerate_id(true);

    $_SESSION['user_id']
    = $row['id'];

    $_SESSION['name']
    = $row['full_name'];

    $_SESSION['role']
    = $row['role'];

    if($row['role']=="student")
    {
        header(
        "Location: student/dashboard.php"
        );
    }
    elseif($row['role']=="faculty")
    {
        header(
        "Location: faculty/dashboard.php"
        );
    }
    elseif($row['role']=="admin")
    {
        header(
        "Location: admin/dashboard.php"
        );
    }

    exit();
}
        else
        {
            $stmt=$conn->prepare(
"INSERT INTO login_attempts
(email,attempts,last_attempt)

VALUES
(?,1,NOW())

ON DUPLICATE KEY UPDATE
attempts=attempts+1,
last_attempt=NOW()"
);

$stmt->bind_param(
"s",
$email
);

$stmt->execute();

echo "<script>
alert('Invalid Password');
</script>";
        }
    }
    else
    {
        echo "<script>
        alert('User Not Found');
        </script>";
    }
}

?>

<!DOCTYPE html>
<html>
<head>

<title>MIT Internship Portal</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet"
href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
}

body{
    font-family:'Segoe UI',sans-serif;
    overflow:hidden;
}

.login-page{
    width:100%;
    height:100vh;
    position:relative;
}

.background-image{
    position:absolute;
    inset:0;

    background:url('assets/images/logo3.png.jpeg')
    center center no-repeat;

    background-size:cover;
}

.background-overlay{
    position:absolute;
    inset:0;

    background:
    linear-gradient(
    90deg,
    rgba(0,0,0,.15),
    rgba(70,0,120,.45),
    rgba(40,0,90,.65));
}

.login-card{

    position:absolute;

    right:4%;

    top:50%;

    transform:translateY(-50%);

    width:520px;

    padding:45px;

    border-radius:30px;

    background:
    rgba(255,255,255,.12);

    backdrop-filter:
    blur(20px);

    border:
    1px solid rgba(255,255,255,.25);

    box-shadow:
    0 20px 50px rgba(0,0,0,.25);

    color:white;
}

.logo-area{
    text-align:center;
    margin-bottom:20px;
}

.logo-area img{
    width:180px;
    height:auto;
}

.login-card h2{
    text-align:center;
    font-size:36px;
    margin-bottom:10px;
}

.subtext{
    text-align:center;
    margin-bottom:35px;
    color:#eee;
}

label{
    display:block;
    margin-bottom:8px;
    font-weight:600;
}

.form-control{
    height:58px;
    border:none;
    font-size:16px;
    box-shadow:none;
}

.form-control:focus{
    box-shadow:none;
    border:none;
}

.input-group-text{
    background:white;
}

.eye-btn{
    cursor:pointer;
    width:55px;
    justify-content:center;
}

.eye-btn i{
    font-size:18px;
}

.btn-login{

    width:100%;
    height:60px;

    border:none;

    border-radius:16px;

    color:white;

    font-size:20px;

    font-weight:700;

    letter-spacing:.5px;

    background:
    linear-gradient(
    90deg,
    #7c3aed,
    #ec4899,
    #f59e0b);

    transition:.3s;
}

.btn-login:hover{

    transform:translateY(-2px);

    box-shadow:
    0 10px 25px rgba(236,72,153,.35);
}

.features{

    display:flex;

    gap:10px;

    margin-top:25px;
}

.feature-box{

    flex:1;

    text-align:center;

    padding:16px 10px;

    border-radius:14px;

    background:#ffffff;

    border:1px solid #e5e7eb;

    color:#374151;

    font-size:13px;

    transition:.3s;

    box-shadow:
    0 8px 20px rgba(0,0,0,.08);
}

.feature-box:hover{

    transform:translateY(-5px);

    box-shadow:
    0 12px 25px rgba(0,0,0,.15);
}

.feature-icon{
    font-size:18px;
    margin-bottom:8px;
}

.feature-title{
    font-size:13px;
    font-weight:600;
    margin-bottom:4px;
    color:#1f2937;
}

.feature-box small{
    font-size:11px;
    color:#6b7280;
}

@media(max-width:768px){

.login-card{

    width:92%;

    left:4%;

    right:4%;

    transform:translateY(-50%);
}

.features{
    flex-direction:column;
}



.feature-icon{
    font-size:18px;
    margin-bottom:8px;
}

.feature-title{
    font-size:13px;
    font-weight:600;
    margin-bottom:4px;
    color:#1f2937;
}

.feature-box:hover{
    transform:translateY(-5px);
    box-shadow:
    0 12px 25px rgba(0,0,0,.15);
}
}
</style>

</head>

<body>

<div class="login-page">

    <div class="background-image"></div>

    <div class="background-overlay"></div>

    <div class="login-card">

        <div class="logo-area">
<img src="assets/images/logo.png" alt="MIT Logo">
</div>

        <h2>Welcome</h2>

        <p class="subtext">
    Sign in to continue to the MIT Internship Portal
</p>

        <form method="POST">

            <label>Email Address</label>

            <div class="input-group mb-3">
                <span class="input-group-text">📧</span>
               <input
    type="email"
    name="email"
    class="form-control"
    placeholder="Enter your email address"
    required>
            </div>

            <label>Password</label>

<div class="input-group mb-4">

    <span class="input-group-text">
        <i class="bi bi-lock-fill"></i>
    </span>

    <input
        type="password"
        name="password"
        id="password"
        class="form-control"
        placeholder="Enter your password"
        required>

    <span
        class="input-group-text eye-btn"
        onclick="togglePassword()">

        <i
            id="eyeIcon"
            class="bi bi-eye-fill">
        </i>

    </span>

</div>

            <button
                type="submit"
                name="login"
                class="btn-login">
                Sign In →
            </button>

        </form>

<div class="features">

    <div class="feature-box">
        <div class="feature-icon">🛡</div>
        <div class="feature-title">Secure Connection</div>
        <small>Your data is protected</small>
    </div>

    <div class="feature-box">
        <div class="feature-icon">🎓</div>
        <div class="feature-title">Trusted Platform</div>
        <small>Students • Faculty • Admins</small>
    </div>

    <div class="feature-box">
        <div class="feature-icon">🚀</div>
        <div class="feature-title">Empowering Futures</div>
        <small>Together we build tomorrow</small>
    </div>

</div>

    </div>

</div>

<script>

function togglePassword(){

    let password =
    document.getElementById("password");

    let eyeIcon =
    document.getElementById("eyeIcon");

    if(password.type === "password")
    {
        password.type = "text";

        eyeIcon.classList.remove("bi-eye-fill");
        eyeIcon.classList.add("bi-eye-slash-fill");
    }
    else
    {
        password.type = "password";

        eyeIcon.classList.remove("bi-eye-slash-fill");
        eyeIcon.classList.add("bi-eye-fill");
    }
}

</script>

</body>
</html>