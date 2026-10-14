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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MIT Internship Portal - Login</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary: #4f46e5;
            --primary-dark: #3730a3;
            --secondary: #ec4899;
            --text-main: #0f172a;
            --text-muted: #475569;
            --border: #e2e8f0;
            --input-bg: #ffffff;
            --focus-ring: rgba(79, 70, 229, 0.15);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background-color: #f8fafc;
            color: var(--text-main);
            overflow-x: hidden;
        }

        .split-layout {
            display: flex;
            min-height: 100vh;
        }

        /* Left: Hero Section */
        .hero-section {
            flex: 1.2;
            position: relative;
            display: flex;
            flex-direction: column;
            padding: 60px 80px;
            color: white;
            background-image: url('assets/images/newbg.png');
            background-size: cover;
            background-position: left center; /* Prevents center-right watermark clipping */
            background-repeat: no-repeat;
        }

        .hero-overlay {
            position: absolute;
            inset: 0;
            /* Carefully tuned gradient: darker on the left for text readability, fading slightly to reveal the campus */
            background: linear-gradient(135deg, rgba(30, 6, 54, 0.85) 0%, rgba(67, 24, 113, 0.7) 60%, rgba(119, 43, 142, 0.4) 100%);
            z-index: 1;
        }

        .hero-brand {
            position: relative;
            z-index: 2;
            font-weight: 700;
            font-size: 1.15rem;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: auto; /* Pushes content down to vertical center */
        }
        
        .hero-brand i {
            font-size: 1.4rem;
            color: #fbcfe8;
        }

        .hero-content {
            position: relative;
            z-index: 2;
            max-width: 540px;
            margin: 40px 0;
        }

        .hero-content h1 {
            font-size: 3.25rem;
            font-weight: 800;
            line-height: 1.15;
            margin-bottom: 20px;
            letter-spacing: -0.02em;
            text-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .hero-content p {
            font-size: 1.1rem;
            line-height: 1.5;
            opacity: 0.9;
            margin-bottom: 0;
            font-weight: 400;
            text-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }

        /* Refined, compact feature cards */
        .hero-features {
            position: relative;
            z-index: 2;
            display: flex;
            gap: 16px;
            margin-top: auto; /* Pushes features to bottom */
            flex-wrap: wrap;
        }

        .feature-card {
            background: rgba(255, 255, 255, 0.06);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.12);
            padding: 16px 20px;
            border-radius: 12px;
            flex: 1;
            min-width: 160px;
            transition: transform 0.3s ease, background 0.3s ease;
        }

        .feature-card:hover {
            transform: translateY(-3px);
            background: rgba(255, 255, 255, 0.1);
        }

        .feature-icon {
            font-size: 1.25rem;
            margin-bottom: 10px;
            color: #fbcfe8;
            opacity: 0.9;
        }

        .feature-card h3 {
            font-size: 0.95rem;
            font-weight: 600;
            margin-bottom: 4px;
            letter-spacing: 0.2px;
        }

        .feature-card p {
            font-size: 0.8rem;
            margin: 0;
            opacity: 0.75;
            line-height: 1.4;
        }

        /* Right: Login Section */
        .login-section {
            width: 100%;
            max-width: 520px;
            display: flex;
            flex-direction: column;
            justify-content: center; /* Vertically centers the login panel */
            padding: 60px 50px;
            position: relative;
            z-index: 10;
            background: #ffffff;
            box-shadow: -15px 0 40px rgba(0,0,0,0.04);
        }

        .login-inner {
            width: 100%;
            max-width: 380px;
            margin: 0 auto;
        }

        .logo-area {
            text-align: center;
            margin-bottom: 35px;
        }

        .logo-area img {
            height: 75px;
            width: auto;
            object-fit: contain;
        }

        .login-header {
            margin-bottom: 35px;
            text-align: center;
        }

        .login-header h2 {
            font-weight: 800;
            font-size: 1.75rem;
            color: var(--text-main);
            margin-bottom: 8px;
            letter-spacing: -0.01em;
        }

        .login-header p {
            color: var(--text-muted);
            font-size: 0.95rem;
            margin: 0;
        }

        .form-label {
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--text-main);
            margin-bottom: 8px;
        }

        .input-group {
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid var(--border);
            transition: all 0.2s ease;
            background: var(--input-bg);
            margin-bottom: 24px;
        }
        
        .input-group:focus-within {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px var(--focus-ring);
        }

        .input-group-text {
            background: transparent;
            border: none;
            color: #94a3b8;
            padding: 0 16px;
        }

        .input-group:focus-within .input-group-text {
            color: var(--primary);
        }

        .form-control {
            border: none;
            padding: 14px 16px 14px 0;
            font-size: 0.95rem;
            color: var(--text-main);
            background: transparent;
            box-shadow: none !important;
        }
        
        .form-control::placeholder {
            color: #94a3b8;
            font-weight: 400;
        }

        .eye-btn {
            cursor: pointer;
            color: #94a3b8;
            transition: color 0.2s;
        }

        .eye-btn:hover {
            color: var(--primary);
        }

        .btn-login {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            font-weight: 600;
            font-size: 1rem;
            margin-top: 8px;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.15);
        }

        .btn-login:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(79, 70, 229, 0.25);
            color: white;
        }
        
        .btn-login:active {
            transform: translateY(1px);
            box-shadow: 0 2px 8px rgba(79, 70, 229, 0.15);
        }

        .login-footer {
            margin-top: 40px;
            text-align: center;
        }

        .login-footer p {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin: 0;
            line-height: 1.6;
            opacity: 0.8;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .hero-section {
                padding: 40px;
            }
            .hero-content h1 {
                font-size: 2.75rem;
            }
        }
        
        @media (max-width: 991px) {
            .split-layout {
                flex-direction: column;
            }
            
            /* On mobile, keep campus image as a compact top header */
            .hero-section {
                flex: none;
                padding: 40px 24px;
                min-height: 35vh;
                justify-content: center;
                background-position: center;
            }
            .hero-brand {
                margin-bottom: 20px;
            }
            .hero-content {
                margin: 0;
            }
            .hero-content h1 {
                font-size: 2rem;
                margin-bottom: 12px;
            }
            .hero-content p {
                font-size: 1rem;
            }
            .hero-features {
                display: none; /* Hide feature cards on small screens to prioritize login */
            }
            
            /* Login panel overlaps slightly and takes full width */
            .login-section {
                max-width: 100%;
                padding: 40px 24px;
                border-radius: 24px 24px 0 0;
                margin-top: -24px;
                box-shadow: 0 -10px 30px rgba(0,0,0,0.08);
                justify-content: flex-start;
            }
            .login-inner {
                max-width: 400px;
            }
            .logo-area {
                margin-bottom: 30px;
            }
            .login-footer {
                margin-top: 40px;
                padding-bottom: 20px;
            }
        }
        
        @media (max-width: 480px) {
            .hero-content h1 {
                font-size: 1.75rem;
            }
            .login-section {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>

<div class="split-layout">
    
    <!-- Left: Campus Hero Section -->
    <div class="hero-section">
        <div class="hero-overlay"></div>
        
        <div class="hero-brand">
            <i class="bi bi-mortarboard-fill"></i> MIT-ADT University
        </div>

        <div class="hero-content">
            <h1>Your Journey From Learning to Real Impact</h1>
            <p>Connect your learning with real-world experience and build your future.</p>
        </div>

        <div class="hero-features">
            <div class="feature-card">
                <div class="feature-icon"><i class="bi bi-briefcase-fill"></i></div>
                <h3>Real Experience</h3>
                <p>Bridge the gap between academia and industry.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><i class="bi bi-graph-up-arrow"></i></div>
                <h3>Career Growth</h3>
                <p>Build a robust portfolio and network.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><i class="bi bi-people-fill"></i></div>
                <h3>Stronger Together</h3>
                <p>Seamless campus collaboration.</p>
            </div>
        </div>
    </div>

    <!-- Right: Login Form Section -->
    <div class="login-section">
        <div class="login-inner">
            <div class="logo-area">
                <img src="assets/images/logo.png" alt="MIT Logo">
            </div>

            <div class="login-header">
                <h2>Welcome Back</h2>
                <p>Please sign in to access your dashboard</p>
            </div>

            <form method="POST">
                <label class="form-label">Email Address</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope-fill"></i></span>
                    <input type="email" name="email" class="form-control" placeholder="Enter your email" required autocomplete="email">
                </div>

                <label class="form-label">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                    <input type="password" name="password" id="password" class="form-control" placeholder="Enter your password" required autocomplete="current-password">
                    <span class="input-group-text eye-btn" onclick="togglePassword()" role="button" aria-label="Toggle password visibility">
                        <i id="eyeIcon" class="bi bi-eye-fill"></i>
                    </span>
                </div>

                <button type="submit" name="login" class="btn-login">
                    Sign In
                </button>
            </form>
            
            <div class="login-footer">
                <p>&copy; <?php echo date('Y'); ?> MIT-ADT University. All Rights Reserved.</p>
                <p>Designed and Developed by <span style="font-weight: 600; color: var(--text-main); opacity: 1;">Swaraj Ingale & Wardhan Darekar</span></p>
            </div>
        </div>
    </div>

</div>

<script>
function togglePassword() {
    const password = document.getElementById("password");
    const eyeIcon = document.getElementById("eyeIcon");

    if (password.type === "password") {
        password.type = "text";
        eyeIcon.classList.replace("bi-eye-fill", "bi-eye-slash-fill");
    } else {
        password.type = "password";
        eyeIcon.classList.replace("bi-eye-slash-fill", "bi-eye-fill");
    }
}
</script>

</body>
</html>
