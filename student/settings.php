<?php
/**
 * InternHub — Student Account Settings (settings.php)
 * Account preferences, password security, contact update, and portfolio privacy
 */

session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../login.php");
    exit;
}

$student_id = (int)$_SESSION['user_id'];
$activePage = 'settings.php';

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$message = '';
$messageType = '';

// 1. Fetch Student User Details
$stmt = $conn->prepare("SELECT id, full_name, email, phone, division, department, enrollment_no, password FROM users WHERE id = ?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$student_name = $user['full_name'];
$student_email = $user['email'];
$student_phone = $user['phone'] ?? '';
$student_division = $user['division'] ?? 'SY-4';
$student_department = $user['department'] ?? 'SOC';
$enrollment_no = $user['enrollment_no'] ?? '';

// 2. Fetch Profile Privacy
$pStmt = $conn->prepare("SELECT is_public FROM student_profiles WHERE user_id = ?");
$pStmt->bind_param("i", $student_id);
$pStmt->execute();
$pRow = $pStmt->get_result()->fetch_assoc();
$pStmt->close();
$is_public = (int)($pRow['is_public'] ?? 1);

// 3. Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Action: Update Contact & Name
    if ($action === 'update_info') {
        $full_name = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $privacy = isset($_POST['is_public']) ? 1 : 0;

        if (empty($full_name)) {
            $message = 'Full Name cannot be empty.';
            $messageType = 'danger';
        } else {
            $upU = $conn->prepare("UPDATE users SET full_name = ?, phone = ? WHERE id = ?");
            $upU->bind_param("ssi", $full_name, $phone, $student_id);
            $upU->execute();
            $upU->close();

            // Upsert profile privacy
            $upP = $conn->prepare("INSERT INTO student_profiles (user_id, is_public) VALUES (?, ?) ON DUPLICATE KEY UPDATE is_public = VALUES(is_public)");
            $upP->bind_param("ii", $student_id, $privacy);
            $upP->execute();
            $upP->close();

            $_SESSION['name'] = $full_name;
            $student_name = $full_name;
            $student_phone = $phone;
            $is_public = $privacy;

            $message = 'Account details updated successfully.';
            $messageType = 'success';
        }
    }

    // Action: Change Password
    elseif ($action === 'change_password') {
        $current_pass = $_POST['current_password'] ?? '';
        $new_pass = $_POST['new_password'] ?? '';
        $confirm_pass = $_POST['confirm_password'] ?? '';

        if (empty($current_pass) || empty($new_pass)) {
            $message = 'All password fields are required.';
            $messageType = 'danger';
        } elseif ($new_pass !== $confirm_pass) {
            $message = 'New passwords do not match.';
            $messageType = 'danger';
        } elseif (strlen($new_pass) < 6) {
            $message = 'New password must be at least 6 characters long.';
            $messageType = 'danger';
        } else {
            // Verify current password (supports bcrypt hash and legacy plain text fallback)
            $validCurrent = false;
            if (password_verify($current_pass, $user['password'])) {
                $validCurrent = true;
            } elseif ($current_pass === $user['password']) {
                $validCurrent = true;
            }

            if (!$validCurrent) {
                $message = 'Current password is incorrect.';
                $messageType = 'danger';
            } else {
                $newHash = password_hash($new_pass, PASSWORD_BCRYPT);
                $upPass = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $upPass->bind_param("si", $newHash, $student_id);
                $upPass->execute();
                $upPass->close();

                $message = 'Password changed successfully! Next login will require your new password.';
                $messageType = 'success';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings — InternHub | MIT-ADT University</title>
    <?php require_once __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
    .settings-wrapper {
        padding: 0 32px 32px;
        display: flex;
        flex-direction: column;
        gap: 24px;
        max-width: 900px;
    }

    .settings-card {
        background: #ffffff;
        border-radius: 20px;
        padding: 26px 30px;
        border: 1px solid rgba(226, 232, 240, 0.8);
        box-shadow: 0 4px 18px rgba(0,0,0,0.035);
    }

    .settings-card-header {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 1.15rem;
        font-weight: 800;
        color: #1e1b4b;
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 1px solid #f1f5f9;
    }
    .settings-card-header i { color: #7c3aed; }

    .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 18px; }
    .form-group { display: flex; flex-direction: column; gap: 6px; }
    .form-group.full-width { grid-column: span 2; }
    .form-label { font-size: 0.82rem; font-weight: 700; color: #334155; }
    .form-control {
        width: 100%; padding: 10px 14px; border-radius: 12px; border: 1px solid #cbd5e1;
        font-family: inherit; font-size: 0.88rem; color: #1e293b; background: #ffffff; outline: none;
    }
    .form-control:focus { border-color: #7c3aed; box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.12); }

    .btn-submit {
        display: inline-flex; align-items: center; gap: 8px; padding: 10px 24px; border-radius: 999px;
        background: linear-gradient(135deg, #7c3aed, #5b21b6); color: #ffffff; font-weight: 700; font-size: 0.88rem;
        border: none; cursor: pointer; transition: all 0.2s ease;
    }
    .btn-submit:hover { transform: translateY(-2px); }

    .alert-banner {
        padding: 12px 18px; border-radius: 14px; font-size: 0.86rem; font-weight: 600;
        display: flex; align-items: center; gap: 10px; margin-bottom: 20px;
    }
    .alert-success { background: #dcfce7; color: #14532d; border: 1px solid #bbf7d0; }
    .alert-danger  { background: #fee2e2; color: #7f1d1d; border: 1px solid #fecaca; }

    @media (max-width: 768px) {
        .settings-wrapper { padding: 0 16px 24px; }
        .form-grid { grid-template-columns: 1fr; }
        .form-group.full-width { grid-column: span 1; }
    }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="settings-wrapper">

    <?php if (!empty($message)): ?>
        <div class="alert-banner alert-<?php echo $messageType; ?>">
            <i class="fa-solid <?php echo $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
            <span><?php echo h($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- Card 1: Personal & Contact Information -->
    <div class="settings-card">
        <div class="settings-card-header">
            <i class="fa-solid fa-user-pen"></i>
            <span>Personal & Account Details</span>
        </div>

        <form action="settings.php" method="POST">
            <input type="hidden" name="action" value="update_info">

            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label">Full Name *</label>
                    <input type="text" name="full_name" class="form-control" required value="<?php echo h($student_name); ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Institutional Email</label>
                    <input type="email" class="form-control" readonly value="<?php echo h($student_email); ?>" style="background:#f1f5f9; color:#64748b;">
                </div>

                <div class="form-group">
                    <label class="form-label">Phone Number</label>
                    <input type="text" name="phone" class="form-control" value="<?php echo h($student_phone); ?>" placeholder="e.g. +91 9876543210">
                </div>

                <div class="form-group">
                    <label class="form-label">Enrollment & Division</label>
                    <input type="text" class="form-control" readonly value="<?php echo h($enrollment_no); ?> (<?php echo h($student_division); ?> - <?php echo h($student_department); ?>)" style="background:#f1f5f9; color:#64748b;">
                </div>

                <div class="form-group full-width" style="margin-top:6px;">
                    <label style="display:flex; align-items:center; gap:10px; cursor:pointer;">
                        <input type="checkbox" name="is_public" value="1" <?php echo $is_public ? 'checked' : ''; ?> style="width:18px; height:18px; accent-color:#7c3aed;">
                        <span style="font-size:0.86rem; font-weight:700; color:#1e1b4b;">Show my portfolio in Student Discovery Directory & shareable link</span>
                    </label>
                </div>
            </div>

            <div style="margin-top:20px;">
                <button type="submit" class="btn-submit">
                    <i class="fa-solid fa-floppy-disk"></i>
                    <span>Save Contact Changes</span>
                </button>
            </div>
        </form>
    </div>

    <!-- Card 2: Password & Security -->
    <div class="settings-card">
        <div class="settings-card-header">
            <i class="fa-solid fa-lock"></i>
            <span>Password & Security</span>
        </div>

        <form action="settings.php" method="POST">
            <input type="hidden" name="action" value="change_password">

            <div class="form-grid">
                <div class="form-group full-width">
                    <label class="form-label">Current Password *</label>
                    <input type="password" name="current_password" class="form-control" required placeholder="Enter current password">
                </div>

                <div class="form-group">
                    <label class="form-label">New Password *</label>
                    <input type="password" name="new_password" class="form-control" required placeholder="At least 6 characters">
                </div>

                <div class="form-group">
                    <label class="form-label">Confirm New Password *</label>
                    <input type="password" name="confirm_password" class="form-control" required placeholder="Repeat new password">
                </div>
            </div>

            <div style="margin-top:20px;">
                <button type="submit" class="btn-submit">
                    <i class="fa-solid fa-key"></i>
                    <span>Update Password</span>
                </button>
            </div>
        </form>
    </div>

</div>

</main>
</div>

<?php require_once __DIR__ . '/includes/sidebar_scripts.php'; ?>
</body>
</html>
