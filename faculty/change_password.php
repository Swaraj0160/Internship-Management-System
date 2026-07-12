<?php
session_start();
include("../config.php");

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty') {
    header("Location: ../login.php");
    exit();
}

$faculty_id = $_SESSION['user_id'];

$query = "SELECT * FROM users WHERE id='$faculty_id'";
$result = mysqli_query($conn, $query);
$user = mysqli_fetch_assoc($result);

if (isset($_POST['change_password'])) {

    $old_password = trim($_POST['old_password']);
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);

    /*
        CHECK OLD PASSWORD
        Works for both hashed and old plain-text passwords
    */
    $password_correct = false;

    if (password_verify($old_password, $user['password'])) {
        $password_correct = true;
    } elseif ($old_password == $user['password']) {
        $password_correct = true;
    }

    if (!$password_correct) {

        echo "<script>alert('Old Password Incorrect');</script>";

    } elseif ($new_password != $confirm_password) {

        echo "<script>alert('New Passwords Do Not Match');</script>";

    } elseif ($old_password == $new_password) {

        echo "<script>alert('New Password cannot be the same as the Old Password');</script>";

    } else {
        if(strlen($new_password) < 6)
{
    echo "<script>alert('Password must be at least 6 characters long.');</script>";
}
else
{
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

    $update = "UPDATE users
               SET password='$hashed_password'
               WHERE id='$faculty_id'";

    if(mysqli_query($conn, $update))
    {
        echo "<script>alert('Password Changed Successfully');</script>";
        echo "<script>window.location.href='change_password.php';</script>";
        exit();
    }
    else
    {
        echo "<script>alert('Failed to Change Password');</script>";
    }
}
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

        $update = "UPDATE users
                   SET password='$hashed_password'
                   WHERE id='$faculty_id'";

        if (mysqli_query($conn, $update)) {

            echo "<script>alert('Password Changed Successfully');</script>";
            echo "<script>window.location.href='change_password.php';</script>";
            exit();

        } else {

            echo "<script>alert('Failed to Change Password');</script>";

        }
    }
}
?>

<!DOCTYPE html>
<html>

<head>

<title>Change Password</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
    :root{
    --bg:#f5f6fa;
    --surface:#fff;
    --text:#1f2430;
    --muted:#6f7690;
    --border:#e4e7ee;
    --purple:#5b3df5;
    --purple-dark:#4631d6;
    --danger:#e25563;
    --radius:14px;
    --shadow:0 10px 25px rgba(0,0,0,.08);
}

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
}

body{
    background:var(--bg);
    font-family:'Inter',sans-serif;
    min-height:100vh;
    padding:18px 40px;
}

/* Back Button */

.page-header{
    width:100%;
    margin-bottom:15px;
}

.back-link{
    display:inline-flex;
    align-items:center;
    gap:8px;
    color:var(--purple);
    text-decoration:none;
    font-weight:600;
    font-size:15px;
    transition:.3s;
}

.back-link:hover{
    color:var(--purple-dark);
    text-decoration:underline;
}

/* Card */

.password-card{
    width:650px;
    max-width:100%;
    margin:0 auto;
    background:#fff;
    border-radius:14px;
    box-shadow:var(--shadow);
    padding:22px 35px;
}

/* Lock Icon */

.card-icon{
    width:48px;
    height:48px;
    margin:0 auto 12px;
    display:flex;
    justify-content:center;
    align-items:center;
    font-size:22px;
    color:var(--purple);
    background:#f1edff;
    border-radius:12px;
}

/* Heading */

.card-title{
    text-align:center;
    font-size:32px;
    font-weight:700;
    margin-bottom:3px;
}

.card-subtitle{
    text-align:center;
    color:var(--muted);
    font-size:15px;
    margin-bottom:22px;
}

/* Form */

.field-group{
    margin-bottom:16px;
}

.field-group label{
    display:block;
    margin-bottom:6px;
    font-size:15px;
    font-weight:600;
}

.input-wrap{
    position:relative;
}

.field-icon{
    position:absolute;
    left:15px;
    top:50%;
    transform:translateY(-50%);
    color:#7b8194;
}

.input-wrap input{
    width:100%;
    height:46px;
    border:1px solid #dfe3eb;
    border-radius:8px;
    padding:0 42px;
    font-size:15px;
    transition:.25s;
}

.input-wrap input:focus{
    outline:none;
    border-color:var(--purple);
    box-shadow:0 0 0 3px rgba(91,61,245,.12);
}

.toggle-visibility{
    position:absolute;
    right:14px;
    top:50%;
    transform:translateY(-50%);
    border:none;
    background:none;
    color:#7b8194;
    cursor:pointer;
}

/* Password Strength */

.strength-bar{
    height:4px;
    background:#eceff5;
    border-radius:20px;
    margin-top:6px;
}

.strength-bar-fill{
    height:100%;
    width:0;
    background:var(--danger);
    border-radius:20px;
}

.hint{
    margin-top:4px;
    font-size:12px;
    color:#6f7690;
}

/* Button */

.submit-btn{
    width:100%;
    height:46px;
    margin-top:10px;
    border:none;
    border-radius:8px;
    background:var(--purple);
    color:#fff;
    font-size:16px;
    font-weight:600;
    transition:.3s;
}

.submit-btn:hover{
    background:var(--purple-dark);
}

/* Security Note */

.security-note{
    margin-top:15px;
    display:flex;
    align-items:flex-start;
    gap:10px;
    padding:10px 12px;
    background:#f8f9ff;
    border-left:4px solid var(--purple);
    border-radius:8px;
    font-size:13px;
}

.security-note i{
    color:var(--purple);
    margin-top:2px;
}

/* Mobile */

@media(max-width:768px){

    body{
        padding:15px;
    }

    .password-card{
        padding:20px;
    }

    .card-title{
        font-size:28px;
    }

}
</style>
</head>

<body>

<div class="page-header">
    <a href="dashboard.php" class="back-link">
        <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
    </a>
</div>

<div class="password-card">
    <div class="card-icon">
        <i class="fa-solid fa-lock"></i>
    </div>
    <h3 class="card-title">Change Password</h3>
    <p class="card-subtitle">Update your account password below</p>

    <form method="POST" id="changePasswordForm" novalidate>
        <div class="field-group">
            <label for="old_password">Old Password</label>
            <div class="input-wrap">
                <i class="fa-solid fa-key field-icon"></i>
                <input type="password"
                       id="old_password"
                       name="old_password"
                       placeholder="Enter your current password"
                       required>
                <button type="button" class="toggle-visibility" data-target="old_password">
                    <i class="fa-solid fa-eye"></i>
                </button>
            </div>
        </div>

        <div class="field-group">
            <label for="new_password">New Password</label>
            <div class="input-wrap">
                <i class="fa-solid fa-lock field-icon"></i>
                <input type="password"
                       id="new_password"
                       name="new_password"
                       placeholder="Create a new password"
                       minlength="6"
                       required>
                <button type="button" class="toggle-visibility" data-target="new_password">
                    <i class="fa-solid fa-eye"></i>
                </button>
            </div>
            <div class="strength-bar"><div class="strength-bar-fill" id="strengthFill"></div></div>
            <div class="hint" id="strengthHint">Use at least 6 characters.</div>
        </div>

        <div class="field-group">
            <label for="confirm_password">Confirm Password</label>
            <div class="input-wrap">
                <i class="fa-solid fa-lock field-icon"></i>
                <input type="password"
                       id="confirm_password"
                       name="confirm_password"
                       placeholder="Re-enter the new password"
                       required>
                <button type="button" class="toggle-visibility" data-target="confirm_password">
                    <i class="fa-solid fa-eye"></i>
                </button>
            </div>
            <div class="hint" id="matchHint">&nbsp;</div>
        </div>

        <button type="submit" name="change_password" class="submit-btn">
            <i class="fa-solid fa-check"></i> Change Password
        </button>
    </form>

    <div class="security-note">
        <i class="fa-solid fa-shield-halved"></i>
        <div>For your security, choose a password you don't use elsewhere and avoid sharing it with anyone.</div>
    </div>
</div>

<script>
document.querySelectorAll('.toggle-visibility').forEach(function(btn){
    btn.addEventListener('click', function(){
        var input = document.getElementById(btn.getAttribute('data-target'));
        var icon = btn.querySelector('i');
        if(input.type === 'password'){
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    });
});

var newPasswordInput = document.getElementById('new_password');
var confirmPasswordInput = document.getElementById('confirm_password');
var strengthFill = document.getElementById('strengthFill');
var strengthHint = document.getElementById('strengthHint');
var matchHint = document.getElementById('matchHint');

function checkStrength(value){
    var score = 0;
    if(value.length >= 6) score++;
    if(value.length >= 10) score++;
    if(/[A-Z]/.test(value) && /[a-z]/.test(value)) score++;
    if(/[0-9]/.test(value)) score++;
    if(/[^A-Za-z0-9]/.test(value)) score++;

    var pct = (score / 5) * 100;
    var color = '#e25563';
    var label = 'Weak';

    if(score >= 4){
        color = '#1f9d5a';
        label = 'Strong';
    } else if(score >= 2){
        color = '#f3b61f';
        label = 'Moderate';
    }

    if(value.length === 0){
        pct = 0;
        label = 'Use at least 6 characters.';
    } else {
        label = label + ' password';
    }

    strengthFill.style.width = pct + '%';
    strengthFill.style.background = color;
    strengthHint.textContent = label;
}

newPasswordInput.addEventListener('input', function(){
    checkStrength(this.value);
    checkMatch();
});

confirmPasswordInput.addEventListener('input', checkMatch);

function checkMatch(){
    if(confirmPasswordInput.value.length === 0){
        matchHint.textContent = '\u00A0';
        matchHint.style.color = 'var(--muted)';
        return;
    }
    if(newPasswordInput.value === confirmPasswordInput.value){
        matchHint.textContent = 'Passwords match';
        matchHint.style.color = '#1f9d5a';
    } else {
        matchHint.textContent = 'Passwords do not match';
        matchHint.style.color = '#e25563';
    }
}
</script>

</body>


</html>