<?php

session_start();
include("config.php");

$loginError = "";

if(isset($_POST['login']))
{
    $email = mysqli_real_escape_string($conn, trim($_POST['email']));
    $password = $_POST['password'];
    $selectedRole = isset($_POST['role']) ? strtolower(trim($_POST['role'])) : 'student';

    $stmt = $conn->prepare("SELECT * FROM login_attempts WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $check = $stmt->get_result();

    if(mysqli_num_rows($check) > 0)
    {
        $data = mysqli_fetch_assoc($check);
        if($data['attempts'] >= 5 && strtotime($data['last_attempt']) > strtotime('-10 minutes'))
        {
            $loginError = "Too many failed attempts. Please try again after 10 minutes.";
        }
    }

    if(empty($loginError))
    {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if(mysqli_num_rows($result) > 0)
        {
            $row = mysqli_fetch_assoc($result);
            $storedPassword = $row['password'];
            $passwordMatched = false;

            // Support both password_verify and legacy plain text fallback
            if(password_verify($password, $storedPassword))
            {
                $passwordMatched = true;
            }
            elseif($password == $storedPassword)
            {
                $passwordMatched = true;
            }

            if($passwordMatched)
            {
                // Verify that user role matches selected role tab if explicitly chosen
                if(!empty($selectedRole) && in_array($selectedRole, ['student', 'faculty', 'admin']))
                {
                    if($row['role'] !== $selectedRole)
                    {
                        $loginError = "This account is registered as " . ucfirst($row['role']) . ". Please switch to the " . ucfirst($row['role']) . " tab to sign in.";
                    }
                }

                if(empty($loginError))
                {
                    // Clear failed attempts
                    $stmt = $conn->prepare("DELETE FROM login_attempts WHERE email = ?");
                    $stmt->bind_param("s", $email);
                    $stmt->execute();

                    // Remember device / cookie handling if checked
                    if(!empty($_POST['remember']))
                    {
                        $deviceToken = bin2hex(random_bytes(32));
                        $hashedToken = hash('sha256', $deviceToken);
                        $expiry = date('Y-m-d H:i:s', strtotime('+30 days'));

                        $remStmt = $conn->prepare("INSERT INTO trusted_devices (user_id, token, expires_at) VALUES (?, ?, ?)");
                        if($remStmt)
                        {
                            $remStmt->bind_param("iss", $row['id'], $hashedToken, $expiry);
                            $remStmt->execute();
                        }

                        setcookie('trusted_device', $deviceToken, time() + (86400 * 30), "/", "", false, true);
                    }

                    // Multi-factor authentication flow if enabled
                    if($row['auth_enabled'] == 1)
                    {
                        $skipOTP = false;

                        if(isset($_COOKIE['trusted_device']))
                        {
                            $token = hash('sha256', $_COOKIE['trusted_device']);
                            $stmt = $conn->prepare("SELECT * FROM trusted_devices WHERE user_id = ? AND token = ? AND expires_at > NOW()");
                            $stmt->bind_param("is", $row['id'], $token);
                            $stmt->execute();
                            $check = $stmt->get_result();

                            if(mysqli_num_rows($check) > 0)
                            {
                                $skipOTP = true;
                            }
                        }

                        if(!$skipOTP)
                        {
                            $_SESSION['temp_user_id'] = $row['id'];
                            $_SESSION['temp_email'] = $row['email'];
                            header("Location: send_login_otp.php");
                            exit();
                        }
                    }

                    // Authenticated successfully -> Initialize session
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $row['id'];
                    $_SESSION['name'] = $row['full_name'];
                    $_SESSION['role'] = $row['role'];

                    if($row['role'] == "student")
                    {
                        header("Location: student/dashboard.php");
                    }
                    elseif($row['role'] == "faculty")
                    {
                        header("Location: faculty/dashboard.php");
                    }
                    elseif($row['role'] == "admin")
                    {
                        header("Location: admin/dashboard.php");
                    }
                    exit();
                }
            }
            else
            {
                // Record failed login attempt
                $stmt = $conn->prepare("INSERT INTO login_attempts (email, attempts, last_attempt) VALUES (?, 1, NOW()) ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = NOW()");
                $stmt->bind_param("s", $email);
                $stmt->execute();

                $loginError = "Invalid password. Please check your credentials and try again.";
            }
        }
        else
        {
            $loginError = "No account found with this email address.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>InternHub — MIT-ADT University</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/images/mit_adt_crest.png">
    
    <!-- Bootstrap 5.3 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <!-- Google Fonts: Inter for UI & Playfair Display for editorial italics -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@1,600;1,700&family=Inter:wght@400;500;600;700;800&family=Playfair+Display:ital,wght@1,500;1,600;1,700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary-purple: #4f2d93;
            --primary-gradient: linear-gradient(135deg, #512da8 0%, #3e1f82 100%);
            --primary-hover: linear-gradient(135deg, #5b32a8 0%, #46238f 100%);
            --badge-purple: #4a2789;
            --lavender-light: #eeddff;
            --lavender-accent: #d8b4fe;
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --border-light: #e2e8f0;
            --card-bg: #ffffff;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
            width: 100%;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: var(--text-dark);
            background-color: #1a0b2e;
            overflow-x: hidden;
        }

        /* Full-Viewport Landing Page with Campus Photography */
        .portal-viewport {
            position: relative;
            min-height: 100vh;
            width: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            background-color: #1a0b2e;
            background-image: url('assets/images/campus_bg_reference.webp'), url('assets/images/campus_bg_reference.jpg');
            background-size: cover;
            background-position: center center;
            background-repeat: no-repeat;
            padding: clamp(14px, 2.5vh, 24px) clamp(20px, 4vw, 48px);
            z-index: 1;
            overflow: hidden;
        }

        /* Subtle dark gradient overlay behind left-side text to ensure high contrast without dimming campus */
        .portal-viewport::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, rgba(12, 6, 24, 0.72) 0%, rgba(18, 9, 36, 0.48) 45%, rgba(20, 10, 40, 0.12) 70%, transparent 100%);
            z-index: 1;
            pointer-events: none;
        }

        /* Decorative Lavender Corner Curves (matching reference) */
        .corner-accents {
            position: absolute;
            top: 0;
            right: 0;
            bottom: 0;
            width: 44%;
            max-width: 580px;
            height: 100%;
            pointer-events: none;
            z-index: 2;
            overflow: hidden;
        }

        .accent-swoosh {
            width: 100%;
            height: 100%;
            filter: drop-shadow(0 0 40px rgba(168, 85, 247, 0.15));
        }

        /* Top Bar: University Branding & Navigation Pills */
        .top-navbar {
            position: relative;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            padding-bottom: 12px;
        }

        .university-branding {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: #ffffff;
        }

        .brand-crest {
            width: 48px;
            height: 48px;
            object-fit: contain;
            filter: drop-shadow(0 2px 8px rgba(0,0,0,0.3));
        }

        .brand-details {
            margin-left: 12px;
            display: flex;
            flex-direction: column;
        }

        .brand-title {
            font-size: 1.15rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            line-height: 1.1;
            color: #ffffff;
            font-family: 'Inter', serif;
            border-bottom: 1px solid rgba(255, 255, 255, 0.65);
            padding-bottom: 2px;
            display: inline-block;
        }

        .brand-university {
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 1px;
            line-height: 1.2;
            color: #ffffff;
            margin-top: 2px;
        }

        .brand-location {
            font-size: 0.65rem;
            font-weight: 500;
            letter-spacing: 1.5px;
            color: rgba(255, 255, 255, 0.85);
            line-height: 1.1;
        }

        .brand-separator {
            width: 1px;
            height: 38px;
            background: rgba(255, 255, 255, 0.45);
            margin: 0 16px;
        }

        .brand-tagline {
            display: flex;
            flex-direction: column;
            font-size: 0.76rem;
            font-weight: 500;
            color: rgba(255, 255, 255, 0.95);
            line-height: 1.35;
        }

        /* Top-Right Navigation Pills */
        .top-pills {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .pill-portal {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.88);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            color: #2e155c;
            border: 1px solid rgba(255, 255, 255, 0.95);
            padding: 7px 18px;
            border-radius: 9999px;
            font-size: 0.82rem;
            font-weight: 600;
            text-decoration: none;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            transition: all 0.25s ease;
        }

        .pill-portal i {
            color: #4a2789;
            font-size: 0.95rem;
        }

        .pill-portal:hover {
            background: #ffffff;
            color: #1e0942;
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.14);
        }

        .pill-internhub {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--primary-purple);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 7px 18px;
            border-radius: 9999px;
            font-size: 0.82rem;
            font-weight: 600;
            text-decoration: none;
            box-shadow: 0 4px 14px rgba(79, 45, 147, 0.35);
            transition: all 0.25s ease;
        }

        .pill-internhub:hover {
            background: #5b34a6;
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(79, 45, 147, 0.45);
        }

        /* Main Workspace: 2-Column Split Layout */
        .main-workspace {
            position: relative;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 40px;
            margin: auto 0;
            padding: 20px 0;
            width: 100%;
        }

        /* Left Promotional Section (approx. 67% Desktop Width) */
        .hero-section {
            flex: 1.4;
            max-width: 680px;
            color: #ffffff;
            display: flex;
            flex-direction: column;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--badge-purple);
            color: #ffffff;
            padding: 5px 13px;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.3px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
            width: fit-content;
            margin-bottom: clamp(14px, 2.2vh, 22px);
        }

        .hero-badge i {
            font-size: 0.85rem;
        }

        .hero-heading {
            font-size: clamp(2.3rem, 3.8vw, 3.5rem);
            font-weight: 700;
            line-height: 1.15;
            color: #ffffff;
            letter-spacing: -0.02em;
            text-shadow: 0 2px 16px rgba(0, 0, 0, 0.35);
            margin-bottom: clamp(12px, 1.8vh, 18px);
        }

        .hero-heading .serif-italic {
            font-family: 'Playfair Display', 'Cormorant Garamond', Georgia, serif;
            font-style: italic;
            font-weight: 600;
            color: var(--lavender-light);
            text-shadow: 0 2px 14px rgba(124, 58, 237, 0.35);
            letter-spacing: 0.01em;
        }

        .hero-description {
            font-size: clamp(0.92rem, 1.1vw, 1.05rem);
            line-height: 1.5;
            color: rgba(255, 255, 255, 0.92);
            max-width: 480px;
            text-shadow: 0 1px 8px rgba(0, 0, 0, 0.3);
            margin-bottom: clamp(18px, 2.6vh, 28px);
            font-weight: 400;
        }

        /* 3 Feature Blocks with Thin Vertical Separators */
        .features-row {
            display: flex;
            align-items: flex-start;
            gap: 0;
            margin-top: 6px;
        }

        .feature-item {
            display: flex;
            flex-direction: column;
            padding-right: clamp(16px, 2vw, 28px);
        }

        .feature-icon-wrapper {
            font-size: 1.35rem;
            color: var(--lavender-accent);
            margin-bottom: 8px;
            line-height: 1;
            filter: drop-shadow(0 2px 6px rgba(0, 0, 0, 0.25));
        }

        .feature-title {
            font-size: 0.88rem;
            font-weight: 600;
            color: #ffffff;
            line-height: 1.32;
            letter-spacing: 0.2px;
            text-shadow: 0 1px 6px rgba(0, 0, 0, 0.3);
        }

        .feature-separator {
            width: 1px;
            height: 42px;
            background: rgba(255, 255, 255, 0.26);
            margin: 4px clamp(14px, 1.6vw, 24px) 0 0;
            flex-shrink: 0;
        }

        .accent-dash {
            width: 36px;
            height: 2px;
            background: rgba(255, 255, 255, 0.45);
            margin-top: clamp(14px, 2vh, 22px);
            border-radius: 2px;
        }

        /* Right Section: Prominent White Login Card */
        .login-card-container {
            flex: 0 0 clamp(380px, 28vw, 425px);
            display: flex;
            justify-content: flex-end;
            position: relative;
            z-index: 10;
        }

        .login-card {
            width: 100%;
            max-width: 425px;
            background: #ffffff;
            border-radius: 26px;
            border: 1px solid rgba(255, 255, 255, 0.9);
            box-shadow: 0 20px 50px -10px rgba(25, 6, 52, 0.28), 0 0 0 1px rgba(124, 58, 237, 0.05);
            padding: clamp(22px, 3vh, 30px) clamp(22px, 2.4vw, 32px) clamp(16px, 2vh, 22px) clamp(22px, 2.4vw, 32px);
            position: relative;
        }

        /* Architectural Dome Illustration in Upper-Right Corner */
        .card-dome-art {
            position: absolute;
            top: clamp(18px, 2.4vh, 24px);
            right: clamp(18px, 2.2vw, 26px);
            width: clamp(64px, 5vw, 74px);
            height: auto;
            opacity: 0.88;
            pointer-events: none;
            filter: drop-shadow(0 2px 4px rgba(109, 40, 217, 0.1));
        }

        .card-header-area {
            margin-bottom: clamp(12px, 1.8vh, 18px);
        }

        .card-pretitle {
            font-size: 0.98rem;
            font-weight: 600;
            color: #1e293b;
            line-height: 1.2;
        }

        .card-main-title {
            font-size: clamp(1.85rem, 2.4vw, 2.15rem);
            font-weight: 800;
            color: #3b1d77;
            letter-spacing: -0.02em;
            line-height: 1.12;
            margin: 2px 0 4px 0;
        }

        .card-subtitle {
            font-size: 0.82rem;
            color: var(--text-muted);
            margin: 0;
            font-weight: 400;
        }

        /* Role Selector (Horizontal Segmented Control) */
        .role-selector {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 9999px;
            padding: 3px;
            display: flex;
            gap: 4px;
            margin-bottom: clamp(12px, 1.8vh, 18px);
        }

        .role-pill {
            flex: 1;
            border: none;
            background: transparent;
            padding: 6px 8px;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 600;
            color: #475569;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            cursor: pointer;
            transition: all 0.22s ease;
        }

        .role-pill i {
            font-size: 0.88rem;
        }

        .role-pill:hover:not(.active) {
            color: #1e293b;
            background: rgba(0, 0, 0, 0.03);
        }

        .role-pill.active {
            background: linear-gradient(135deg, #532fa0 0%, #401f82 100%);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(79, 45, 147, 0.28);
        }

        /* Input Controls & Labels */
        .form-group-custom {
            margin-bottom: clamp(10px, 1.4vh, 14px);
        }

        .field-label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 5px;
        }

        .input-box {
            display: flex;
            align-items: center;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #ffffff;
            padding: 0 14px;
            height: clamp(40px, 4.8vh, 44px);
            transition: all 0.2s ease;
        }

        .input-box:focus-within {
            border-color: #6d28d9;
            box-shadow: 0 0 0 3px rgba(109, 40, 217, 0.12);
        }

        .input-icon {
            color: #94a3b8;
            font-size: 1rem;
            display: flex;
            align-items: center;
            flex-shrink: 0;
            transition: color 0.2s;
        }

        .input-box:focus-within .input-icon {
            color: #6d28d9;
        }

        .field-control {
            border: none;
            outline: none;
            background: transparent;
            width: 100%;
            font-size: 0.88rem;
            color: #0f172a;
            padding: 0 10px;
        }

        .field-control::placeholder {
            color: #94a3b8;
            font-weight: 400;
        }

        .eye-toggle-btn {
            cursor: pointer;
            color: #94a3b8;
            display: flex;
            align-items: center;
            padding: 4px;
            transition: color 0.2s;
        }

        .eye-toggle-btn:hover {
            color: #6d28d9;
        }

        /* Options Row: Remember Me & Forgot Password */
        .form-options-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: clamp(10px, 1.4vh, 15px) 0 clamp(12px, 1.8vh, 18px) 0;
            font-size: 0.8rem;
        }

        .checkbox-container {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: #475569;
            cursor: pointer;
            user-select: none;
            font-weight: 500;
        }

        .checkbox-container input[type="checkbox"] {
            width: 15px;
            height: 15px;
            border-radius: 4px;
            border: 1px solid #cbd5e1;
            cursor: pointer;
            accent-color: #5b32a8;
        }

        .forgot-link {
            color: #512da8;
            font-weight: 600;
            text-decoration: none;
            transition: color 0.2s;
        }

        .forgot-link:hover {
            color: #3b1d77;
            text-decoration: underline;
        }

        /* Sign In Button */
        .btn-signin {
            width: 100%;
            height: clamp(42px, 5.2vh, 46px);
            border: none;
            border-radius: 12px;
            background: var(--primary-gradient);
            color: #ffffff;
            font-size: 0.92rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            cursor: pointer;
            box-shadow: 0 6px 18px rgba(79, 45, 147, 0.32);
            transition: all 0.22s ease;
        }

        .btn-signin i {
            font-size: 1.05rem;
            transition: transform 0.2s ease;
        }

        .btn-signin:hover {
            background: var(--primary-hover);
            box-shadow: 0 8px 24px rgba(79, 45, 147, 0.42);
            transform: translateY(-1px);
        }

        .btn-signin:hover i {
            transform: translateX(3px);
        }

        .btn-signin:active {
            transform: translateY(1px);
            box-shadow: 0 2px 8px rgba(79, 45, 147, 0.25);
        }

        /* Error Notification Banner inside Card */
        .login-alert-banner {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
            border-radius: 10px;
            padding: 9px 12px;
            font-size: 0.8rem;
            font-weight: 500;
            line-height: 1.35;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .login-alert-banner i {
            font-size: 1.05rem;
            color: #dc2626;
            flex-shrink: 0;
        }

        /* Card Footer: Security & Copyright */
        .card-footer-area {
            margin-top: clamp(12px, 1.8vh, 18px);
            text-align: center;
        }

        .security-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            font-size: 0.74rem;
            font-weight: 500;
            color: #475569;
            margin-bottom: 4px;
        }

        .security-badge i {
            color: #6d28d9;
            font-size: 0.85rem;
        }

        .copyright-text {
            font-size: 0.68rem;
            color: #94a3b8;
            margin: 0;
            line-height: 1.4;
        }

        /* Forgot Password Modal */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 6, 32, 0.65);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-overlay.active {
            display: flex;
        }

        .forgot-modal-box {
            background: #ffffff;
            border-radius: 20px;
            width: 100%;
            max-width: 420px;
            padding: 28px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.25);
            animation: modalPop 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
        }

        @keyframes modalPop {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }

        .modal-close-btn {
            position: absolute;
            top: 16px;
            right: 18px;
            background: #f1f5f9;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #64748b;
            transition: all 0.2s;
        }

        .modal-close-btn:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        /* Responsive Breakpoints */
        @media (max-width: 1200px) {
            .portal-viewport {
                padding: 20px 32px;
            }
            .hero-heading {
                font-size: 2.8rem;
            }
            .feature-separator {
                margin: 6px 16px 0 0;
            }
            .feature-item {
                padding-right: 16px;
            }
            .login-card-container {
                flex: 0 0 390px;
            }
        }

        @media (max-width: 992px) {
            .portal-viewport {
                padding: 20px;
                min-height: 100vh;
                height: auto;
            }
            .main-workspace {
                flex-direction: column;
                align-items: center;
                gap: 36px;
                margin: 30px 0;
            }
            .hero-section {
                max-width: 100%;
                text-align: center;
                align-items: center;
            }
            .hero-description {
                max-width: 100%;
            }
            .features-row {
                justify-content: center;
            }
            .login-card-container {
                width: 100%;
                max-width: 440px;
                justify-content: center;
                flex: none;
            }
            .login-card {
                max-width: 100%;
            }
            .accent-dash {
                display: none;
            }
        }

        @media (max-width: 680px) {
            .top-navbar {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
            }
            .top-pills {
                width: 100%;
                justify-content: flex-start;
            }
            .brand-separator, .brand-tagline {
                display: none;
            }
            .features-row {
                flex-wrap: wrap;
                gap: 20px;
            }
            .feature-separator {
                display: none;
            }
            .feature-item {
                padding: 0;
                align-items: center;
                text-align: center;
                min-width: 90px;
            }
            .login-card {
                padding: 26px 20px 22px 20px;
            }
            .card-dome-art {
                width: 60px;
                top: 20px;
                right: 20px;
            }
        }
    </style>
</head>
<body>

<div class="portal-viewport">
    
    <!-- Decorative Lavender Corner Curves (matching reference) -->
    <div class="corner-accents" aria-hidden="true">
        <svg class="accent-swoosh" viewBox="0 0 600 900" fill="none" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none">
            <defs>
                <linearGradient id="lavenderGradTop" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" stop-color="#eeddff" stop-opacity="0.85" />
                    <stop offset="60%" stop-color="#c4b5fd" stop-opacity="0.65" />
                    <stop offset="100%" stop-color="#a78bfa" stop-opacity="0.3" />
                </linearGradient>
                <linearGradient id="lavenderGradBottom" x1="0%" y1="100%" x2="100%" y2="0%">
                    <stop offset="0%" stop-color="#d8b4fe" stop-opacity="0.9" />
                    <stop offset="50%" stop-color="#c084fc" stop-opacity="0.6" />
                    <stop offset="100%" stop-color="#eeddff" stop-opacity="0.25" />
                </linearGradient>
            </defs>
            <!-- Top Right Organic Curve -->
            <path d="M600,0 L380,0 C390,140 450,220 540,260 C570,273 590,278 600,285 Z" fill="url(#lavenderGradTop)" />
            <!-- Bottom Right Organic Wave -->
            <path d="M600,480 C500,520 320,620 380,780 C410,860 480,900 600,900 Z" fill="url(#lavenderGradBottom)" />
        </svg>
    </div>

    <!-- Top Bar: University Branding & Navigation Pills -->
    <header class="top-navbar">
        <a href="login.php" class="university-branding" aria-label="MIT-ADT University">
            <img src="assets/images/mit_adt_crest.png" alt="MIT-ADT University Crest" class="brand-crest">
            <div class="brand-details">
                <span class="brand-title">MIT-ADT</span>
                <span class="brand-university">UNIVERSITY</span>
                <span class="brand-location">PUNE, INDIA</span>
            </div>
            <div class="brand-separator"></div>
            <div class="brand-tagline">
                <span>A Leap Towards</span>
                <span>World-Class Education</span>
            </div>
        </a>

        <nav class="top-pills">
            <a href="https://mituniversity.ac.in/" target="_blank" rel="noopener noreferrer" class="pill-portal">
                <i class="bi bi-bank2"></i>
                <span>University Portal</span>
            </a>
            <a href="login.php" class="pill-internhub">
                <i class="bi bi-briefcase-fill"></i>
                <span>InternHub</span>
            </a>
        </nav>
    </header>

    <!-- Main Content Workspace (Split Layout) -->
    <main class="main-workspace">
        
        <!-- Left Hero Section (approx. 67% width) -->
        <section class="hero-section">
            <div class="hero-badge">
                <i class="bi bi-briefcase-fill"></i>
                <span>InternHub</span>
            </div>

            <h1 class="hero-heading">
                From Learning<br>
                to <span class="serif-italic">Real-World Impact</span>
            </h1>

            <p class="hero-description">
                Your internship journey at MIT-ADT University – manage opportunities, track progress and build a future-ready career.
            </p>

            <div class="features-row">
                <div class="feature-item">
                    <div class="feature-icon-wrapper">
                        <i class="bi bi-display"></i>
                    </div>
                    <div class="feature-title">
                        Explore<br>Opportunities
                    </div>
                </div>

                <div class="feature-separator"></div>

                <div class="feature-item">
                    <div class="feature-icon-wrapper">
                        <i class="bi bi-bar-chart-line"></i>
                    </div>
                    <div class="feature-title">
                        Track<br>Progress
                    </div>
                </div>

                <div class="feature-separator"></div>

                <div class="feature-item">
                    <div class="feature-icon-wrapper">
                        <i class="bi bi-people"></i>
                    </div>
                    <div class="feature-title">
                        Build<br>Your Career
                    </div>
                </div>
            </div>

            <div class="accent-dash"></div>
        </section>

        <!-- Right Section: White Login Card -->
        <section class="login-card-container">
            <div class="login-card">
                <!-- Dome Line Art Illustration -->
                <img src="assets/images/dome_illustration.png" alt="MIT-ADT Dome Illustration" class="card-dome-art">

                <!-- Card Heading -->
                <div class="card-header-area">
                    <div class="card-pretitle">Welcome to</div>
                    <h2 class="card-main-title">InternHub</h2>
                    <p class="card-subtitle">Sign in to access your internship portal</p>
                </div>

                <!-- Inline Error Banner -->
                <?php if(!empty($loginError)): ?>
                    <div class="login-alert-banner" role="alert">
                        <i class="bi bi-exclamation-circle-fill"></i>
                        <span><?php echo htmlspecialchars($loginError); ?></span>
                    </div>
                <?php endif; ?>

                <!-- Login Form -->
                <form method="POST" action="login.php" id="loginForm">
                    <!-- Hidden field to synchronize role selection -->
                    <input type="hidden" name="role" id="selectedRole" value="<?php echo htmlspecialchars($selectedRole ?? 'student'); ?>">

                    <!-- Segmented Role Selector -->
                    <div class="role-selector" role="tablist" aria-label="Select User Role">
                        <button type="button" class="role-pill <?php echo (!isset($selectedRole) || $selectedRole === 'student') ? 'active' : ''; ?>" data-role="student" onclick="setRole('student', this)">
                            <i class="bi bi-mortarboard-fill"></i>
                            <span>Student</span>
                        </button>
                        <button type="button" class="role-pill <?php echo (isset($selectedRole) && $selectedRole === 'faculty') ? 'active' : ''; ?>" data-role="faculty" onclick="setRole('faculty', this)">
                            <i class="bi bi-person"></i>
                            <span>Faculty</span>
                        </button>
                        <button type="button" class="role-pill <?php echo (isset($selectedRole) && $selectedRole === 'admin') ? 'active' : ''; ?>" data-role="admin" onclick="setRole('admin', this)">
                            <i class="bi bi-gear"></i>
                            <span>Admin</span>
                        </button>
                    </div>

                    <!-- Email Field -->
                    <div class="form-group-custom">
                        <label for="emailInput" class="field-label">Email Address</label>
                        <div class="input-box">
                            <span class="input-icon"><i class="bi bi-envelope"></i></span>
                            <input type="email" name="email" id="emailInput" class="field-control" placeholder="Enter your university email" required autocomplete="email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                        </div>
                    </div>

                    <!-- Password Field -->
                    <div class="form-group-custom">
                        <label for="passwordInput" class="field-label">Password</label>
                        <div class="input-box">
                            <span class="input-icon"><i class="bi bi-lock"></i></span>
                            <input type="password" name="password" id="passwordInput" class="field-control" placeholder="Enter your password" required autocomplete="current-password">
                            <span class="eye-toggle-btn" onclick="togglePasswordVisibility()" role="button" aria-label="Toggle password visibility">
                                <i id="eyeIcon" class="bi bi-eye"></i>
                            </span>
                        </div>
                    </div>

                    <!-- Remember Me & Forgot Password -->
                    <div class="form-options-row">
                        <label class="checkbox-container">
                            <input type="checkbox" name="remember" value="1" id="rememberMe">
                            <span>Remember me</span>
                        </label>
                        <a href="#" class="forgot-link" onclick="openForgotModal(event)">Forgot password?</a>
                    </div>

                    <!-- Submit Button -->
                    <button type="submit" name="login" class="btn-signin" id="submitBtn">
                        <span>Sign In</span>
                        <i class="bi bi-arrow-right"></i>
                    </button>
                </form>

                <!-- Card Footer -->
                <div class="card-footer-area">
                    <div class="security-badge">
                        <i class="bi bi-shield-check"></i>
                        <span>Secure University Access</span>
                    </div>
                    <p class="copyright-text">&copy; 2026 MIT-ADT University, Pune. All Rights Reserved.<br><span style="opacity: 0.8; font-size: 0.65rem;">Designed and Developed by Team-Apex</span></p>
                </div>
            </div>
        </section>

    </main>

    <!-- Empty Footer Area to anchor flex alignment -->
    <div style="height: 1px;"></div>

</div>

<!-- Forgot Password Modal -->
<div class="modal-overlay" id="forgotModal" onclick="closeForgotModal(event)">
    <div class="forgot-modal-box" onclick="event.stopPropagation()">
        <button type="button" class="modal-close-btn" onclick="closeForgotModal()" aria-label="Close modal">
            <i class="bi bi-x-lg"></i>
        </button>
        <div style="text-align: center; margin-bottom: 16px;">
            <div style="width: 52px; height: 52px; background: #f3e8ff; color: #6d28d9; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 1.5rem; margin-bottom: 12px;">
                <i class="bi bi-key-fill"></i>
            </div>
            <h3 style="font-size: 1.25rem; font-weight: 700; color: #1e293b; margin-bottom: 6px;">Password Assistance</h3>
            <p style="font-size: 0.85rem; color: #64748b; line-height: 1.5; margin: 0;">
                For institutional security, password resets are coordinated through your Academic Department.
            </p>
        </div>
        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px; margin-bottom: 20px; font-size: 0.82rem; color: #334155;">
            <div style="font-weight: 600; margin-bottom: 4px;"><i class="bi bi-info-circle text-primary me-1"></i> How to reset:</div>
            <ul style="padding-left: 20px; margin: 0; line-height: 1.5;">
                <li><strong>Students:</strong> Contact your assigned Faculty Guide or Department Coordinator.</li>
                <li><strong>Faculty & Admin:</strong> Contact the School of Computing Central Administrator.</li>
                <li>Email: <a href="mailto:internship@mitadt.edu.in" style="color: #6d28d9; font-weight: 600;">internship@mitadt.edu.in</a></li>
            </ul>
        </div>
        <button type="button" class="btn-signin" style="height: 42px;" onclick="closeForgotModal()">
            <span>Understood</span>
        </button>
    </div>
</div>

<script>
// Role Selection Controller
function setRole(roleName, element) {
    document.querySelectorAll('.role-pill').forEach(btn => btn.classList.remove('active'));
    element.classList.add('active');
    document.getElementById('selectedRole').value = roleName;

    // Update email placeholder contextually
    const emailInput = document.getElementById('emailInput');
    if(roleName === 'student') {
        emailInput.placeholder = 'Enter your university email';
    } else if(roleName === 'faculty') {
        emailInput.placeholder = 'Enter your faculty email';
    } else if(roleName === 'admin') {
        emailInput.placeholder = 'Enter your admin email';
    }
}

// Password Visibility Toggle
function togglePasswordVisibility() {
    const passwordInput = document.getElementById('passwordInput');
    const eyeIcon = document.getElementById('eyeIcon');

    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        eyeIcon.classList.replace('bi-eye', 'bi-eye-slash');
    } else {
        passwordInput.type = 'password';
        eyeIcon.classList.replace('bi-eye-slash', 'bi-eye');
    }
}

// Forgot Password Modal Handlers
function openForgotModal(e) {
    if(e) e.preventDefault();
    document.getElementById('forgotModal').classList.add('active');
}

function closeForgotModal() {
    document.getElementById('forgotModal').classList.remove('active');
}

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeForgotModal();
    }
});

// Submit Button Loading Feedback
document.getElementById('loginForm').addEventListener('submit', function() {
    const btn = document.getElementById('submitBtn');
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span><span>Signing In...</span>';
    btn.style.opacity = '0.85';
    btn.style.pointerEvents = 'none';
});
</script>

</body>
</html>
