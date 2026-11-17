<?php
session_start();
require_once "config.php";
require_once "includes/mail_service.php";

$settings = file_exists("config/account_settings.php") ? require "config/account_settings.php" : [];
$errorMsg = "";
$successMsg = "";
$showConfirmation = false;

if (isset($_POST['register_student'])) {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $enrollmentNo = strtoupper(trim($_POST['enrollment_no'] ?? ''));
    $department = trim($_POST['department'] ?? 'SOC');
    $specialization = trim($_POST['specialization'] ?? '');
    $semester = trim($_POST['semester'] ?? '');
    $yearOfStudy = trim($_POST['year_of_study'] ?? 'TY');
    $batch = strtoupper(trim($_POST['batch'] ?? 'A'));
    $phone = trim($_POST['phone'] ?? '');

    // 1. Validation
    if (empty($fullName) || empty($email) || empty($password) || empty($enrollmentNo)) {
        $errorMsg = "Please fill in all mandatory fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMsg = "Please enter a valid email address.";
    } elseif (strlen($password) < 8) {
        $errorMsg = "Password must be at least 8 characters long.";
    } elseif ($password !== $confirmPassword) {
        $errorMsg = "Passwords do not match.";
    } else {
        // Check optional domain enforcement
        $allowedDomains = $settings['allowed_student_domains'] ?? null;
        if (is_array($allowedDomains) && !empty($allowedDomains)) {
            $domain = substr(strrchr($email, "@"), 1);
            if (!in_array($domain, $allowedDomains)) {
                $errorMsg = "Registration requires an institutional email from: " . implode(', ', $allowedDomains);
            }
        }
    }

    // 2. Database checks (Strict Email & Enrollment Uniqueness)
    if (empty($errorMsg)) {
        $stmt = $conn->prepare("SELECT id, role FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $dupCheck = $stmt->get_result();

        if ($dupCheck->num_rows > 0) {
            $existing = $dupCheck->fetch_assoc();
            $errorMsg = "An account with this email address already exists (registered as " . ucfirst($existing['role']) . "). Please sign in or use password recovery.";
        } else {
            // Check enrollment uniqueness
            $enrStmt = $conn->prepare("SELECT id FROM users WHERE enrollment_no = ? LIMIT 1");
            $enrStmt->bind_param("s", $enrollmentNo);
            $enrStmt->execute();
            if ($enrStmt->get_result()->num_rows > 0) {
                $errorMsg = "A student with enrollment number '$enrollmentNo' is already registered in the system.";
            }
        }
    }

    // 3. Create Account & Dispatch Verification
    if (empty($errorMsg)) {
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $role = 'student';
        $status = 'pending_verification';
        $emailVerified = 0;
        $academicYear = '2026-27';

        $insertStmt = $conn->prepare("
            INSERT INTO users (full_name, email, password, role, account_status, email_verified, department, enrollment_no, specialization, semester, year_of_study, batch, phone, academic_year)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insertStmt->bind_param(
            "sssssissssssss",
            $fullName, $email, $passwordHash, $role, $status, $emailVerified,
            $department, $enrollmentNo, $specialization, $semester, $yearOfStudy, $batch, $phone, $academicYear
        );

        if ($insertStmt->execute()) {
            $newUserId = $insertStmt->insert_id;

            // Generate cryptographic verification token (64 hex characters)
            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $expiry = date('Y-m-d H:i:s', strtotime('+24 hours'));

            $tokenStmt = $conn->prepare("INSERT INTO email_verification_tokens (user_id, token_hash, token_type, expires_at) VALUES (?, ?, 'email_verify', ?)");
            $tokenStmt->bind_param("iss", $newUserId, $tokenHash, $expiry);
            $tokenStmt->execute();

            // Send Verification Email
            MailService::sendStudentVerification($email, $fullName, $rawToken);
            $verifyUrl = "verify_email.php?token=" . urlencode($rawToken) . "&type=student";

            $showConfirmation = true;
            $successMsg = "Registration successful! A verification email has been dispatched to <strong>" . htmlspecialchars($email) . "</strong>.<br><br><div class='mt-2 p-2 rounded bg-light border text-center'><span class='small text-muted d-block mb-1'>Hosting / Instant Verification:</span><a href='" . htmlspecialchars($verifyUrl) . "' class='btn btn-sm btn-primary text-white px-3 py-1 text-decoration-none rounded-pill shadow-sm'>Click Here to Activate Account Instantly</a></div>";
        } else {
            $errorMsg = "Failed to create account due to a database error. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Registration — InternHub | MIT-ADT University</title>
    <link rel="icon" type="image/png" href="assets/images/mit_adt_crest.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Playfair+Display:ital,wght@1,600&display=swap" rel="stylesheet">

    <style>
        :root {
            --brand-primary: #512da8;
            --brand-deep: #3b1d77;
            --brand-gradient: linear-gradient(135deg, #6d28d9 0%, #43248b 100%);
            --accent-lavender: #f3e8ff;
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --bg-border: #e2e8f0;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #0d0722 url('assets/images/campus_bg_reference.webp') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 15px;
            color: #334155;
            position: relative;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: linear-gradient(135deg, rgba(15, 7, 34, 0.88) 0%, rgba(20, 10, 45, 0.75) 50%, rgba(10, 5, 25, 0.85) 100%);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 1;
        }

        .reg-container {
            position: relative;
            z-index: 2;
            width: 100%;
            max-width: 680px;
        }

        .reg-card {
            background: #ffffff;
            border-radius: 28px;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.35);
            padding: 40px;
            position: relative;
            overflow: hidden;
        }

        .card-dome-art {
            position: absolute;
            top: 24px;
            right: 28px;
            width: 76px;
            opacity: 0.85;
            pointer-events: none;
        }

        .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #f3e8ff;
            color: #5b21b6;
            font-size: 0.82rem;
            font-weight: 700;
            padding: 6px 14px;
            border-radius: 30px;
            margin-bottom: 12px;
        }

        .reg-title {
            font-size: 1.85rem;
            font-weight: 800;
            color: #1e1b4b;
            margin-bottom: 4px;
        }

        .reg-subtitle {
            font-size: 0.9rem;
            color: #64748b;
            margin-bottom: 24px;
        }

        .form-label-custom {
            font-size: 0.82rem;
            font-weight: 700;
            color: #334155;
            margin-bottom: 5px;
            display: block;
        }

        .input-box {
            position: relative;
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            height: 46px;
            display: flex;
            align-items: center;
            padding: 0 14px;
            transition: all 0.2s ease;
        }

        .input-box:focus-within {
            border-color: #6d28d9;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(109, 40, 217, 0.12);
        }

        .input-icon {
            color: #94a3b8;
            font-size: 1rem;
            margin-right: 10px;
        }

        .field-control {
            border: none;
            background: transparent;
            outline: none;
            width: 100%;
            font-size: 0.9rem;
            color: #0f172a;
            font-weight: 500;
        }

        .field-control::placeholder {
            color: #94a3b8;
            font-weight: 400;
        }

        .btn-register {
            background: var(--brand-gradient);
            color: #ffffff;
            border: none;
            height: 48px;
            border-radius: 12px;
            width: 100%;
            font-weight: 700;
            font-size: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 8px 20px rgba(109, 40, 217, 0.28);
            transition: all 0.2s ease;
            margin-top: 15px;
        }

        .btn-register:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 25px rgba(109, 40, 217, 0.35);
            color: #ffffff;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #6d28d9;
            font-weight: 600;
            text-decoration: none;
            font-size: 0.88rem;
            margin-top: 20px;
        }

        .back-link:hover {
            color: #43248b;
            text-decoration: underline;
        }

        .success-box {
            text-align: center;
            padding: 30px 10px;
        }

        .success-icon {
            width: 68px;
            height: 68px;
            background: #dcfce7;
            color: #16a34a;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>

<div class="reg-container">
    <div class="reg-card">
        <img src="assets/images/dome_illustration.png" alt="MIT-ADT" class="card-dome-art">

        <?php if ($showConfirmation): ?>
            <!-- Success / Email Verification Screen -->
            <div class="success-box">
                <div class="success-icon">
                    <i class="bi bi-check2-circle"></i>
                </div>
                <h2 style="font-weight: 800; color: #1e1b4b; margin-bottom: 12px;">Verify Your Email</h2>
                <div class="alert alert-success" style="border-radius: 14px; font-size: 0.95rem; line-height: 1.6; text-align: left;">
                    <?php echo $successMsg; ?>
                </div>
                <p style="color: #64748b; font-size: 0.88rem; margin: 20px 0;">
                    Once you click the link in your email, your student account will be activated immediately and you can start building your professional profile!
                </p>
                <a href="login.php" class="btn-register" style="text-decoration: none;">
                    <span>Go to Sign In</span>
                    <i class="bi bi-arrow-right"></i>
                </a>
            </div>
        <?php else: ?>
            <div class="brand-badge">
                <i class="bi bi-mortarboard-fill"></i>
                <span>Student Registration</span>
            </div>

            <h1 class="reg-title">Create Student Profile</h1>
            <p class="reg-subtitle">Join InternHub to build your portfolio, discover internships, and track academic milestones.</p>

            <?php if (!empty($errorMsg)): ?>
                <div class="alert alert-danger d-flex align-items-center gap-2" role="alert" style="border-radius: 12px; font-size: 0.88rem;">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <div><?php echo htmlspecialchars($errorMsg); ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" action="student_register.php">
                <div class="row g-3">
                    <!-- Full Name -->
                    <div class="col-md-6">
                        <label class="form-label-custom">Full Name *</label>
                        <div class="input-box">
                            <span class="input-icon"><i class="bi bi-person"></i></span>
                            <input type="text" name="full_name" class="field-control" placeholder="e.g. Aadarsh Chandurao" required value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
                        </div>
                    </div>

                    <!-- Email Address -->
                    <div class="col-md-6">
                        <label class="form-label-custom">Email Address *</label>
                        <div class="input-box">
                            <span class="input-icon"><i class="bi bi-envelope"></i></span>
                            <input type="email" name="email" class="field-control" placeholder="student@mitadt.edu.in" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>
                    </div>

                    <!-- Enrollment Number -->
                    <div class="col-md-6">
                        <label class="form-label-custom">Enrollment Number *</label>
                        <div class="input-box">
                            <span class="input-icon"><i class="bi bi-card-text"></i></span>
                            <input type="text" name="enrollment_no" class="field-control" placeholder="e.g. ADT24SOCB0002" required value="<?php echo htmlspecialchars($_POST['enrollment_no'] ?? ''); ?>">
                        </div>
                    </div>

                    <!-- Department -->
                    <div class="col-md-6">
                        <label class="form-label-custom">Department *</label>
                        <div class="input-box">
                            <span class="input-icon"><i class="bi bi-building"></i></span>
                            <select name="department" class="field-control" style="cursor: pointer;">
                                <option value="SOC" <?php echo (($_POST['department'] ?? '') === 'SOC') ? 'selected' : ''; ?>>School of Computing (SOC)</option>
                                <option value="IT" <?php echo (($_POST['department'] ?? '') === 'IT') ? 'selected' : ''; ?>>Information Technology (IT)</option>
                                <option value="SOE" <?php echo (($_POST['department'] ?? '') === 'SOE') ? 'selected' : ''; ?>>School of Engineering (SOE)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Specialization / Programme -->
                    <div class="col-md-6">
                        <label class="form-label-custom">Programme / Specialization *</label>
                        <div class="input-box">
                            <span class="input-icon"><i class="bi bi-award"></i></span>
                            <select name="specialization" class="field-control" style="cursor: pointer;">
                                <option value="BTech CSE - Core">BTech CSE - Core</option>
                                <option value="BTech CSE - Artificial Intelligence and Analytics">BTech CSE - AI & Analytics</option>
                                <option value="BTech CSE - Cyber Security">BTech CSE - Cyber Security</option>
                                <option value="BTech IT - Data Analytics">BTech IT - Data Analytics</option>
                                <option value="BTech IT - Software and Mobile Application Development">BTech IT - SMAD</option>
                            </select>
                        </div>
                    </div>

                    <!-- Semester & Batch -->
                    <div class="col-md-3">
                        <label class="form-label-custom">Semester</label>
                        <div class="input-box">
                            <input type="text" name="semester" class="field-control" placeholder="SEM I" value="<?php echo htmlspecialchars($_POST['semester'] ?? 'SEM I'); ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label-custom">Batch</label>
                        <div class="input-box">
                            <input type="text" name="batch" class="field-control" placeholder="A" value="<?php echo htmlspecialchars($_POST['batch'] ?? 'A'); ?>">
                        </div>
                    </div>

                    <!-- Password -->
                    <div class="col-md-6">
                        <label class="form-label-custom">Password (min 8 chars) *</label>
                        <div class="input-box">
                            <span class="input-icon"><i class="bi bi-lock"></i></span>
                            <input type="password" name="password" class="field-control" placeholder="••••••••" required>
                        </div>
                    </div>

                    <!-- Confirm Password -->
                    <div class="col-md-6">
                        <label class="form-label-custom">Confirm Password *</label>
                        <div class="input-box">
                            <span class="input-icon"><i class="bi bi-lock-fill"></i></span>
                            <input type="password" name="confirm_password" class="field-control" placeholder="••••••••" required>
                        </div>
                    </div>
                </div>

                <button type="submit" name="register_student" class="btn-register">
                    <span>Create Student Account</span>
                    <i class="bi bi-arrow-right"></i>
                </button>
            </form>

            <div class="text-center">
                <a href="login.php" class="back-link">
                    <i class="bi bi-chevron-left"></i>
                    <span>Already have an account? Sign In</span>
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
