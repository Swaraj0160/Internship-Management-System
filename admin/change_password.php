<?php
session_start();
include("../config.php");
if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    header("Location: ../login.php");
    exit();
}

/* REPLACE — sidebar propagation (batch 3), surgical: only the shared
   sidebar shell was added. Password-change logic is unchanged. */
$activePage = 'change_password.php';

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
    $password_correct = false;

if(password_verify($current_password, $user['password']))
{
    $password_correct = true;
}
elseif($current_password == $user['password'])
{
    // For old accounts whose password is still stored in plain text
    $password_correct = true;
}

if(!$password_correct)
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
    else if($current_password == $new_password)
{
    $message = "
    <div class='alert alert-danger'>
        New Password cannot be the same as the Current Password
    </div>";
}
else if(strlen($new_password) < 6)
{
    $message = "
    <div class='alert alert-danger'>
        Password must be at least 6 characters long.
    </div>";
}
    else
    {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

mysqli_query($conn,"
UPDATE users
SET password='$hashed_password'
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
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Change Password</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<?php include __DIR__ . '/includes/sidebar_head.php'; ?>

<style>
body{ padding:0 !important; }
.page-wrap{ padding:24px 25px 60px; }

/* ==================================================================
   Design tokens copied 1:1 from the portal's premium pages (Final
   Internship Certificate, Profile, Apply Internship) so this admin
   page shares the same visual language. Purely presentational — none
   of these classes are read by, or drive, any PHP logic. $message is
   still echoed exactly where the backend puts it; only how
   .alert / .alert-success / .alert-danger look has changed.
================================================================== */

*, *::before, *::after { box-sizing: border-box; }

body{
    background:#f5f7fb;
    font-family:'Inter',system-ui,-apple-system,"Segoe UI",sans-serif;
    color:#0f172a;
    min-height:100vh;
    padding:28px 25px 60px;
}

.page-wrap{
    max-width:1200px;
    margin:0 auto;
}

/* ---------- Page header ---------- */
.page-header{
    margin-bottom:18px;
}

.btn-back{
    display:inline-flex;
    align-items:center;
    gap:8px;
    background:linear-gradient(90deg,#2563eb,#3b82f6);
    color:#fff;
    padding:11px 22px;
    border-radius:999px;
    text-decoration:none;
    font-size:16px;
    font-weight:600;
    transition:transform .15s ease, box-shadow .15s ease;
    border:none;
    box-shadow:0 6px 16px rgba(37,99,235,.28);
}

.btn-back i{
    font-size:18px;
}

.btn-back:hover{
    color:#fff;
    transform:translateY(-1px);
    box-shadow:0 8px 20px rgba(37,99,235,.35);
}

/* ---------- Page title ---------- */
.page-title{
    margin-bottom:22px;
}

.page-title h1{
    font-size:38px;
    line-height:1.15;
    font-weight:800;
    color:#0f172a;
    margin-bottom:6px;
    letter-spacing:-.01em;
}

.page-title p{
    font-size:17px;
    margin-bottom:0;
    color:#64748b;
}

/* ---------- Card ---------- */
.form-box{
    width:100%;
    background:#fff;
    border-radius:20px;
    border:1px solid #eef1f6;
    overflow:hidden;
    box-shadow:0 18px 45px rgba(15,23,42,.06);
    animation:fadeIn .35s ease;
}

@keyframes fadeIn{
    from{ opacity:0; transform:translateY(6px); }
    to{ opacity:1; transform:translateY(0); }
}

.card-header-strip{
    height:5px;
    background:linear-gradient(90deg, #2563eb 0%, #7c3aed 100%);
}

.card-inner{
    padding:32px;
}

/* ---------- Card title ---------- */
.card-title{
    display:flex;
    align-items:center;
    gap:14px;
    margin-bottom:24px;
}

.card-title-icon{
    width:56px;
    height:56px;
    border-radius:15px;
    background:#ede9fe;
    display:flex;
    align-items:center;
    justify-content:center;
    flex-shrink:0;
}

.card-title-icon i{
    font-size:26px;
    color:#7c3aed;
}

.card-title h2{
    font-size:22px;
    font-weight:700;
    color:#0f172a;
    margin:0;
    line-height:1.3;
}

.card-title p{
    font-size:14px;
    color:#94a3b8;
    margin:2px 0 0;
}

/* ---------- Alerts (backend still controls when/which appears) ---------- */
.alert{
    border-radius:14px;
    padding:14px 18px 14px 46px;
    position:relative;
    font-size:14.5px;
    font-weight:600;
    margin-bottom:22px;
    line-height:1.5;
}

.alert::before{
    position:absolute;
    left:18px;
    top:50%;
    transform:translateY(-50%);
    font-size:16px;
    font-weight:800;
}

.alert-success{
    background:#f0fdf4;
    color:#15803d;
    border:1px solid #bbf7d0;
    border-left:4px solid #16a34a;
}

.alert-success::before{
    content:"\2713";
    color:#16a34a;
}

.alert-danger{
    background:#fef2f2;
    color:#b91c1c;
    border:1px solid #fecaca;
    border-left:4px solid #dc2626;
}

.alert-danger::before{
    content:"\2715";
    color:#dc2626;
}

/* ---------- Fields ---------- */
.field{
    margin-bottom:20px;
}

.field label{
    font-size:15px;
    font-weight:600;
    color:#334155;
    margin-bottom:8px;
    display:block;
}

.input-wrap{
    position:relative;
}

.field-icon{
    position:absolute;
    left:16px;
    top:50%;
    transform:translateY(-50%);
    color:#94a3b8;
    font-size:17px;
    transition:color .15s ease;
    pointer-events:none;
    display:flex;
}

.input-wrap:focus-within .field-icon{
    color:#7c3aed;
}

.input-wrap input{
    width:100%;
    height:50px;
    border:1px solid #e5e7eb;
    border-radius:10px;
    padding:0 46px;
    font-size:15px;
    color:#0f172a;
    background:#fff;
    outline:none;
    transition:border-color .15s, box-shadow .15s;
}

.input-wrap input:focus{
    border-color:#7c3aed;
    box-shadow:0 0 0 3px rgba(124,58,237,.1);
}

.toggle-visibility{
    position:absolute;
    right:10px;
    top:50%;
    transform:translateY(-50%);
    border:none;
    background:none;
    color:#94a3b8;
    cursor:pointer;
    padding:7px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:8px;
    transition:color .15s ease, background .15s ease;
}

.toggle-visibility i{
    font-size:18px;
}

.toggle-visibility:hover{
    color:#7c3aed;
    background:#f4edff;
}

/* ---------- Password strength ---------- */
.strength-bar{
    height:6px;
    background:#eef1f6;
    border-radius:999px;
    margin-top:10px;
    overflow:hidden;
}

.strength-bar-fill{
    height:100%;
    width:0;
    background:#e25563;
    border-radius:999px;
    transition:width .25s ease, background .25s ease;
}

.hint{
    margin-top:6px;
    font-size:13px;
    color:#94a3b8;
    font-weight:500;
    display:flex;
    align-items:center;
    gap:5px;
}

.hint i{
    font-size:14px;
}

/* ---------- Submit button ---------- */
.btn-submit{
    width:100%;
    height:54px;
    background:linear-gradient(90deg,#7c3aed,#4f46e5);
    border:none;
    color:#fff;
    font-size:17px;
    font-weight:700;
    border-radius:12px;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    transition:transform .15s ease, box-shadow .15s ease;
    box-shadow:0 10px 24px rgba(79,70,229,.25);
    margin-top:8px;
    cursor:pointer;
}

.btn-submit:hover{
    transform:translateY(-1px);
    box-shadow:0 12px 28px rgba(79,70,229,.32);
    color:#fff;
}

/* ---------- Sidebar info card (desktop two-column layout) ---------- */
.info-card{
    background:#f5f3ff;
    border-radius:16px;
    padding:24px;
    height:100%;
}

.info-card h3{
    color:#4f46e5;
    font-size:19px;
    margin-bottom:16px;
    font-weight:700;
    display:flex;
    align-items:center;
    gap:8px;
}

.info-card h4{
    color:#4f46e5;
    font-size:16px;
    margin-top:20px;
    margin-bottom:8px;
    font-weight:700;
    display:flex;
    align-items:center;
    gap:8px;
}

.info-card ul{
    padding-left:20px;
    margin-bottom:0;
}

.info-card li{
    margin-bottom:10px;
    font-size:14.5px;
    line-height:1.5;
    color:#475569;
}

.info-card p{
    font-size:14.5px;
    color:#64748b;
    line-height:1.5;
    margin:0;
}

.info-card hr{
    border-color:#e0e0fa;
    margin:18px 0 0;
}

/* ---------- Responsive ---------- */
@media (max-width: 767px){
    .page-title h1{ font-size:30px; }
    .card-inner{ padding:22px; }
}

@media (max-width: 480px){
    body{ padding:20px 16px 48px; }
}

</style>

</head>
<body>

<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="page-wrap">

    <div class="page-title">
        <h1>Change Administrator Password</h1>
        <p>Update your administrator account password securely.</p>
    </div>

    <div class="form-box">

        <div class="card-header-strip"></div>

        <div class="card-inner">

        <div class="row g-4 align-items-start">

        <div class="col-lg-8">

            <div class="card-title">
                <div class="card-title-icon">
                    <i class="ti ti-shield-lock" aria-hidden="true"></i>
                </div>
                <div>
                    <h2>Password Security</h2>
                    <p>Enter your current password and create a secure new password.</p>
                </div>
            </div>

            <?php echo $message; ?>

            <form method="POST" id="adminChangePasswordForm" novalidate>

                <div class="field">
                    <label for="current_password">Current Password *</label>
                    <div class="input-wrap">
                        <i class="ti ti-lock field-icon" aria-hidden="true"></i>
                        <input type="password"
                               id="current_password"
                               name="current_password"
                               placeholder="Enter current password"
                               required>
                        <button type="button" class="toggle-visibility" data-target="current_password">
                            <i class="ti ti-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>

                <div class="field">
                    <label for="new_password">New Password *</label>
                    <div class="input-wrap">
                        <i class="ti ti-key field-icon" aria-hidden="true"></i>
                        <input type="password"
                               id="new_password"
                               name="new_password"
                               placeholder="Enter new password"
                               required>
                        <button type="button" class="toggle-visibility" data-target="new_password">
                            <i class="ti ti-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div class="strength-bar"><div class="strength-bar-fill" id="strengthFill"></div></div>
                    <div class="hint" id="strengthHint">Use at least 8 characters.</div>
                </div>

                <div class="field">
                    <label for="confirm_password">Confirm New Password *</label>
                    <div class="input-wrap">
                        <i class="ti ti-shield-lock field-icon" aria-hidden="true"></i>
                        <input type="password"
                               id="confirm_password"
                               name="confirm_password"
                               placeholder="Re-enter new password"
                               required>
                        <button type="button" class="toggle-visibility" data-target="confirm_password">
                            <i class="ti ti-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div class="hint" id="matchHint">&nbsp;</div>
                </div>

                <button type="submit" name="change_password" class="btn-submit">
                    <i class="ti ti-lock" style="font-size:16px" aria-hidden="true"></i>
                    Change Password
                </button>

            </form>

        </div>

        <div class="col-lg-4">

            <div class="info-card">

                <h3>
                    <i class="ti ti-info-circle"></i>
                    Security Tips
                </h3>

                <ul>
                    <li>Use at least 8 characters.</li>
                    <li>Include uppercase, lowercase, numbers, and symbols.</li>
                    <li>Don't reuse old passwords.</li>
                    <li>Never share your password.</li>
                </ul>

                <hr>

                <h4>
                    <i class="ti ti-help-circle"></i>
                    Need Help?
                </h4>

                <p>
                    If you're locked out or unsure about the password
                    requirements, contact the system administrator.
                </p>

            </div>

        </div>

        </div>

        </div>
    </div>

</div><!-- /.page-wrap -->

</main>
</div>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>

<script>

/* Show / hide password — frontend only, does not touch PHP/POST handling */
document.querySelectorAll('.toggle-visibility').forEach(function(btn){
    btn.addEventListener('click', function(){
        var input = document.getElementById(btn.getAttribute('data-target'));
        var icon = btn.querySelector('i');
        if(input.type === 'password'){
            input.type = 'text';
            icon.classList.remove('ti-eye');
            icon.classList.add('ti-eye-off');
        } else {
            input.type = 'password';
            icon.classList.remove('ti-eye-off');
            icon.classList.add('ti-eye');
        }
    });
});

/* Password strength meter — frontend only, does not affect backend validation */
var newPasswordInput = document.getElementById('new_password');
var confirmPasswordInput = document.getElementById('confirm_password');
var strengthFill = document.getElementById('strengthFill');
var strengthHint = document.getElementById('strengthHint');
var matchHint = document.getElementById('matchHint');

function checkStrength(value){
    var score = 0;
    if(value.length >= 8) score++;
    if(value.length >= 12) score++;
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
        label = 'Medium';
    }

    if(value.length === 0){
        pct = 0;
        strengthHint.innerHTML = 'Use at least 8 characters.';
    } else {
        strengthHint.innerHTML = label + ' password';
    }

    strengthFill.style.width = pct + '%';
    strengthFill.style.background = color;
}

newPasswordInput.addEventListener('input', function(){
    checkStrength(this.value);
    checkMatch();
});

confirmPasswordInput.addEventListener('input', checkMatch);

/* Live password-match indicator — frontend only */
function checkMatch(){
    if(confirmPasswordInput.value.length === 0){
        matchHint.innerHTML = '&nbsp;';
        return;
    }
    if(newPasswordInput.value === confirmPasswordInput.value){
        matchHint.innerHTML = '<i class="ti ti-check" style="color:#1f9d5a"></i> <span style="color:#1f9d5a">Passwords Match</span>';
    } else {
        matchHint.innerHTML = '<i class="ti ti-x" style="color:#e25563"></i> <span style="color:#e25563">Passwords Do Not Match</span>';
    }
}

</script>

</body>
</html>