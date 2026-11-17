<?php
session_start();
require_once "config.php";
require_once "includes/mail_service.php";

$settings = file_exists("config/account_settings.php") ? require "config/account_settings.php" : [];
$errorMsg = "";
$successMsg = "";
$showConfirmation = false;

if (isset($_POST['register_faculty'])) {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $department = trim($_POST['department'] ?? 'SOC');
    $designation = trim($_POST['designation'] ?? 'Assistant Professor');
    $employeeId = strtoupper(trim($_POST['employee_id'] ?? ''));
    $phone = trim($_POST['phone'] ?? '');

    // 1. Validation
    if (empty($fullName) || empty($email) || empty($password) || empty($department) || empty($designation)) {
        $errorMsg = "Please fill in all mandatory fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMsg = "Please enter a valid official email address.";
    } elseif (strlen($password) < 8) {
        $errorMsg = "Password must be at least 8 characters long.";
    } elseif ($password !== $confirmPassword) {
        $errorMsg = "Passwords do not match.";
    } else {
        // Check optional domain enforcement
        $allowedDomains = $settings['allowed_faculty_domains'] ?? null;
        if (is_array($allowedDomains) && !empty($allowedDomains)) {
            $domain = substr(strrchr($email, "@"), 1);
            if (!in_array($domain, $allowedDomains)) {
                $errorMsg = "Faculty registration requires an institutional email from: " . implode(', ', $allowedDomains);
            }
        }
    }

    // 2. Database check (Email Uniqueness across all roles)
    if (empty($errorMsg)) {
        $stmt = $conn->prepare("SELECT id, role FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $dupCheck = $stmt->get_result();

        if ($dupCheck->num_rows > 0) {
            $existing = $dupCheck->fetch_assoc();
            $errorMsg = "An account with this email address already exists (registered as " . ucfirst($existing['role']) . "). Please sign in or use password recovery.";
        }
    }

    // 3. Create Account & Dispatch Verification
    if (empty($errorMsg)) {
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $role = 'faculty';
        $status = 'pending_verification';
        $emailVerified = 0;

        $insertStmt = $conn->prepare("
            INSERT INTO users (full_name, email, password, role, account_status, email_verified, department, designation, employee_id, phone)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insertStmt->bind_param(
            "sssssissss",
            $fullName, $email, $passwordHash, $role, $status, $emailVerified,
            $department, $designation, $employeeId, $phone
        );

        if ($insertStmt->execute()) {
            $newUserId = $insertStmt->insert_id;

            // Generate verification token
            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $expiry = date('Y-m-d H:i:s', strtotime('+24 hours'));

            $tokenStmt = $conn->prepare("INSERT INTO email_verification_tokens (user_id, token_hash, token_type, expires_at) VALUES (?, ?, 'email_verify', ?)");
            $tokenStmt->bind_param("iss", $newUserId, $tokenHash, $expiry);
            $tokenStmt->execute();

            // Send Verification Email
            MailService::sendFacultyVerification($email, $fullName, $rawToken);
            $verifyUrl = "verify_email.php?token=" . urlencode($rawToken) . "&type=faculty";

            $showConfirmation = true;
            $successMsg = "Registration request received! A verification email has been dispatched to <strong>" . htmlspecialchars($email) . "</strong>.<br><br><div class='mt-2 p-2 rounded bg-light border text-center'><span class='small text-muted d-block mb-1'>Hosting / Instant Verification:</span><a href='" . htmlspecialchars($verifyUrl) . "' class='btn btn-sm btn-primary text-white px-3 py-1 text-decoration-none rounded-pill shadow-sm'>Click Here to Verify Email Instantly</a></div><p class='small text-muted mt-2 mb-0'>Once your email is verified, your request will be reviewed by the Central Administrator.</p>";
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
    <title>Faculty Registration — InternHub | MIT-ADT University</title>
    <link rel="icon" type="image/png" href="assets/images/mit_adt_crest.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --brand-primary: #512da8;
            --brand-deep: #3b1d77;
            --brand-gradient: linear-gradient(135deg, #6d28d9 0%, #43248b 100%);
            --accent-lavender: #f3e8ff;
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
            max-width: 650px;
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
            background: #ede9fe;
            color: #4338ca;
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
            background: #ede9fe;
            color: #6d28d9;
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
                    <i class="bi bi-shield-lock-fill"></i>
                </div>
                <h2 style="font-weight: 800; color: #1e1b4b; margin-bottom: 12px;">Verify Official Email</h2>
                <div class="alert alert-info" style="border-radius: 14px; font-size: 0.95rem; line-height: 1.6; text-align: left; background: #f0fdf4; border-color: #bbf7d0; color: #166534;">
                    <?php echo $successMsg; ?>
                </div>
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 14px; padding: 18px; text-align: left; margin: 20px 0; font-size: 0.85rem; color: #475569;">
                    <div style="font-weight: 700; color: #1e293b; margin-bottom: 6px;"><i class="bi bi-info-circle-fill text-primary me-1"></i> Two-Step Approval Process:</div>
                    <ol style="margin: 0; padding-left: 20px; line-height: 1.6;">
                        <li><strong>Email Ownership:</strong> Click the verification link sent to your inbox.</li>
                        <li><strong>Administrator Review:</strong> The Central Administrator verifies your departmental credentials and activates your faculty account.</li>
                    </ol>
                </div>
                <a href="login.php" class="btn-register" style="text-decoration: none;">
                    <span>Return to Login</span>
                    <i class="bi bi-arrow-right"></i>
                </a>
            </div>
        <?php else: ?>
            <div class="brand-badge">
                <i class="bi bi-person-badge-fill"></i>
                <span>Faculty Registration</span>
            </div>

            <h1 class="reg-title">Faculty Portal Access</h1>
            <p class="reg-subtitle">Register to guide student internships, evaluate weekly submissions, and manage academic divisions.</p>

            <?php if (!empty($errorMsg)): ?>
                <div class="alert alert-danger d-flex align-items-center gap-2" role="alert" style="border-radius: 12px; font-size: 0.88rem;">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <div><?php echo htmlspecialchars($errorMsg); ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" action="faculty_register.php">
                <div class="row g-3">
                    <!-- Full Name -->
                    <div class="col-md-6">
                        <label class="form-label-custom">Full Name (with title) *</label>
                        <div class="input-box">
                            <span class="input-icon"><i class="bi bi-person"></i></span>
                            <input type="text" name="full_name" class="field-control" placeholder="e.g. Dr. Sachin Tiwari" required value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
                        </div>
                    </div>

                    <!-- Official Email -->
                    <div class="col-md-6">
                        <label class="form-label-custom">Official Faculty Email *</label>
                        <div class="input-box">
                            <span class="input-icon"><i class="bi bi-envelope"></i></span>
                            <input type="email" name="email" class="field-control" placeholder="sachin.tiwari@mituniversity.edu.in" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>
                    </div>

                    <!-- Department -->
                    <div class="col-md-6">
                        <label class="form-label-custom">Department *</label>
                        <div class="input-box">
                            <span class="input-icon"><i class="bi bi-building"></i></span>
                            <select name="department" class="field-control" style="cursor: pointer;">
                                <option value="School of Computing">School of Computing (SOC)</option>
                                <option value="Information Technology">Information Technology (IT)</option>
                                <option value="School of Engineering">School of Engineering (SOE)</option>
                                <option value="School of Design">School of Design (SOD)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Designation -->
                    <div class="col-md-6">
                        <label class="form-label-custom">Designation *</label>
                        <div class="input-box">
                            <span class="input-icon"><i class="bi bi-award"></i></span>
                            <select name="designation" class="field-control" style="cursor: pointer;">
                                <option value="Assistant Professor">Assistant Professor</option>
                                <option value="Associate Professor">Associate Professor</option>
                                <option value="Professor">Professor</option>
                                <option value="Head of Department">Head of Department (HOD)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Employee ID -->
                    <div class="col-md-6">
                        <label class="form-label-custom">Employee ID</label>
                        <div class="input-box">
                            <span class="input-icon"><i class="bi bi-card-text"></i></span>
                            <input type="text" name="employee_id" class="field-control" placeholder="e.g. MITFAC2026" value="<?php echo htmlspecialchars($_POST['employee_id'] ?? ''); ?>">
                        </div>
                    </div>

                    <!-- Phone Number -->
                    <div class="col-md-6">
                        <label class="form-label-custom">Contact Phone</label>
                        <div class="input-box">
                            <span class="input-icon"><i class="bi bi-telephone"></i></span>
                            <input type="text" name="phone" class="field-control" placeholder="e.g. 9876543210" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
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

                <button type="submit" name="register_faculty" class="btn-register">
                    <span>Submit Registration for Approval</span>
                    <i class="bi bi-arrow-right"></i>
                </button>
            </form>

            <div class="text-center">
                <a href="login.php" class="back-link">
                    <i class="bi bi-chevron-left"></i>
                    <span>Already approved? Sign In</span>
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
