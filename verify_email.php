<?php
session_start();
require_once "config.php";

$token = trim($_GET['token'] ?? '');
$status = "error";
$message = "Invalid or expired verification token.";
$userRole = "";
$userName = "";

if (!empty($token)) {
    $tokenHash = hash('sha256', $token);
    $stmt = $conn->prepare("
        SELECT t.id as token_id, t.user_id, t.token_type, t.expires_at,
               u.full_name, u.email, u.role, u.department, u.designation, u.employee_id, u.phone
        FROM email_verification_tokens t
        JOIN users u ON t.user_id = u.id
        WHERE t.token_hash = ? AND t.expires_at > NOW()
        LIMIT 1
    ");
    $stmt->bind_param("s", $tokenHash);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $userId = (int)$row['user_id'];
        $userRole = $row['role'];
        $userName = $row['full_name'];
        $tokenId = (int)$row['token_id'];

        if ($userRole === 'student') {
            // Student: Immediate Activation
            $upd = $conn->prepare("UPDATE users SET email_verified = 1, account_status = 'active' WHERE id = ?");
            $upd->bind_param("i", $userId);
            $upd->execute();

            // Initialize student_profiles if missing
            $prof = $conn->prepare("INSERT IGNORE INTO student_profiles (user_id) VALUES (?)");
            $prof->bind_param("i", $userId);
            $prof->execute();

            // Initialize storage_quotas (25 MB)
            $defaultQuota = 25 * 1024 * 1024;
            $quota = $conn->prepare("INSERT IGNORE INTO storage_quotas (user_id, used_bytes, max_quota_bytes, file_count) VALUES (?, 0, ?, 0)");
            $quota->bind_param("ii", $userId, $defaultQuota);
            $quota->execute();

            // Automatically set authenticated session
            session_regenerate_id(true);
            $_SESSION['user_id'] = $userId;
            $_SESSION['name'] = $userName;
            $_SESSION['role'] = 'student';

            $status = "success_student";
            $message = "Your email address has been verified successfully. Your student account is now active!";
        } elseif ($userRole === 'faculty') {
            // Faculty: Mark email verified, transition to pending_approval
            $upd = $conn->prepare("UPDATE users SET email_verified = 1, account_status = 'pending_approval' WHERE id = ?");
            $upd->bind_param("i", $userId);
            $upd->execute();

            // Insert into faculty_approval_requests
            $req = $conn->prepare("
                INSERT INTO faculty_approval_requests (user_id, department, designation, employee_id, phone, status)
                VALUES (?, ?, ?, ?, ?, 'pending')
                ON DUPLICATE KEY UPDATE status = 'pending'
            ");
            $dept = $row['department'] ?: 'School of Computing';
            $desig = $row['designation'] ?: 'Assistant Professor';
            $empId = $row['employee_id'] ?: '';
            $phone = $row['phone'] ?: '';
            $req->bind_param("issss", $userId, $dept, $desig, $empId, $phone);
            $req->execute();

            // Initialize faculty_profiles
            $fprof = $conn->prepare("INSERT IGNORE INTO faculty_profiles (user_id, department, designation, employee_id) VALUES (?, ?, ?, ?)");
            $fprof->bind_param("isss", $userId, $dept, $desig, $empId);
            $fprof->execute();

            $status = "success_faculty";
            $message = "Your official email has been confirmed! Your faculty registration is now awaiting Administrator review.";
        }

        // Delete used token to prevent replay
        $delToken = $conn->prepare("DELETE FROM email_verification_tokens WHERE id = ?");
        $delToken->bind_param("i", $tokenId);
        $delToken->execute();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification — InternHub | MIT-ADT University</title>
    <link rel="icon" type="image/png" href="assets/images/mit_adt_crest.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
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

        .verify-card {
            position: relative;
            z-index: 2;
            background: #ffffff;
            border-radius: 28px;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.35);
            padding: 40px;
            max-width: 520px;
            width: 100%;
            text-align: center;
        }

        .status-icon {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 2.2rem;
            margin-bottom: 20px;
        }

        .icon-success {
            background: #dcfce7;
            color: #16a34a;
        }

        .icon-pending {
            background: #ede9fe;
            color: #6d28d9;
        }

        .icon-error {
            background: #fee2e2;
            color: #dc2626;
        }

        .btn-action {
            background: linear-gradient(135deg, #6d28d9 0%, #43248b 100%);
            color: #ffffff;
            border: none;
            height: 48px;
            border-radius: 12px;
            width: 100%;
            font-weight: 700;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
            box-shadow: 0 8px 20px rgba(109, 40, 217, 0.28);
            margin-top: 20px;
        }

        .btn-action:hover {
            color: #ffffff;
            transform: translateY(-1px);
        }
    </style>
</head>
<body>

<div class="verify-card">
    <?php if ($status === 'success_student'): ?>
        <div class="status-icon icon-success">
            <i class="bi bi-patch-check-fill"></i>
        </div>
        <h2 style="font-weight: 800; color: #1e1b4b; margin-bottom: 8px;">Account Verified!</h2>
        <p style="color: #64748b; font-size: 0.95rem; margin-bottom: 20px;">
            Welcome to InternHub, <strong><?php echo htmlspecialchars($userName); ?></strong>. Your student account is now fully active.
        </p>
        <div class="alert alert-success" style="border-radius: 12px; font-size: 0.88rem; text-align: left;">
            <i class="bi bi-check-circle-fill me-1"></i> You can immediately browse internships, build your portfolio, and submit weekly progress reports.
        </div>
        <a href="student/dashboard.php" class="btn-action">
            <span>Enter Student Dashboard</span>
            <i class="bi bi-arrow-right"></i>
        </a>

    <?php elseif ($status === 'success_faculty'): ?>
        <div class="status-icon icon-pending">
            <i class="bi bi-hourglass-split"></i>
        </div>
        <h2 style="font-weight: 800; color: #1e1b4b; margin-bottom: 8px;">Email Verified</h2>
        <p style="color: #64748b; font-size: 0.95rem; margin-bottom: 20px;">
            Thank you, <strong><?php echo htmlspecialchars($userName); ?></strong>. Your official email has been verified.
        </p>
        <div class="alert alert-warning" style="border-radius: 12px; font-size: 0.88rem; text-align: left; background: #fefce8; border-color: #fef08a; color: #854d0e;">
            <i class="bi bi-shield-lock-fill me-1"></i> <strong>Pending Administrator Approval:</strong> In accordance with university policy, faculty accounts require administrator authorization before granting portal permissions. You will receive an email once approved.
        </div>
        <a href="login.php" class="btn-action">
            <span>Return to Login</span>
            <i class="bi bi-arrow-right"></i>
        </a>

    <?php else: ?>
        <div class="status-icon icon-error">
            <i class="bi bi-x-circle-fill"></i>
        </div>
        <h2 style="font-weight: 800; color: #1e1b4b; margin-bottom: 8px;">Verification Failed</h2>
        <p style="color: #64748b; font-size: 0.95rem; margin-bottom: 20px;">
            <?php echo htmlspecialchars($message); ?>
        </p>
        <div class="alert alert-danger" style="border-radius: 12px; font-size: 0.88rem;">
            This token may have already been used, or expired after 24 hours.
        </div>
        <a href="login.php" class="btn-action">
            <span>Back to Sign In</span>
            <i class="bi bi-arrow-right"></i>
        </a>
    <?php endif; ?>
</div>

</body>
</html>
