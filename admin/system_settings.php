<?php
session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$activePage = 'system_settings.php';
$adminId = (int)($_SESSION['user_id'] ?? 0);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Ensure system_settings table exists
@mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `system_settings` (
      `setting_key` varchar(100) NOT NULL,
      `setting_value` text DEFAULT NULL,
      `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Default settings
$defaults = [
    'academic_year' => '2026-2027',
    'current_term' => 'Academic Year 2026 (Sem V / VI)',
    'allow_student_reg' => '1',
    'allow_faculty_reg' => '1',
    'allow_late_reports' => '1',
    'support_email' => 'internship.support@mituniversity.edu.in',
    'institution_name' => 'MIT Art, Design and Technology University, Pune',
    'maintenance_mode' => '0',
    'session_timeout' => '60'
];

$successMsg = '';
$errorMsg = '';

// Handle Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $postedCsrf)) {
        $errorMsg = 'Security validation failed (Invalid CSRF token).';
    } else {
        $action = trim($_POST['action'] ?? '');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        if ($action === 'save_settings') {
            $keys = ['academic_year', 'current_term', 'support_email', 'institution_name', 'session_timeout'];
            foreach ($keys as $k) {
                if (isset($_POST[$k])) {
                    $val = trim($_POST[$k]);
                    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt->bind_param("sss", $k, $val, $val);
                    $stmt->execute();
                }
            }
            // Checkboxes
            $chk_keys = ['allow_student_reg', 'allow_faculty_reg', 'allow_late_reports', 'maintenance_mode'];
            foreach ($chk_keys as $ck) {
                $cval = isset($_POST[$ck]) ? '1' : '0';
                $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->bind_param("sss", $ck, $cval, $cval);
                $stmt->execute();
            }

            $successMsg = "System settings updated successfully.";
            $aud = $conn->prepare("INSERT INTO audit_logs (actor_id, actor_role, action, target_id, target_type, ip_address, user_agent, details) VALUES (?, 'admin', 'update_system_settings', 1, 'system', ?, ?, ?)");
            $dt = json_encode(['updated_by' => $adminId]);
            $aud->bind_param("iisss", $adminId, $ip, $ua, $dt);
            $aud->execute();
        }
    }
}

// Fetch live settings
$settings = $defaults;
$res = @mysqli_query($conn, "SELECT setting_key, setting_value FROM system_settings");
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $settings[$r['setting_key']] = $r['setting_value'];
    }
}

// Calculate Live Storage Usage
$total_files = 0;
$total_file_bytes = 0;
$upload_dirs = ['../uploads', '../uploads/notifications', '../uploads/resources', '../uploads/final_reports'];
foreach ($upload_dirs as $d) {
    if (is_dir($d)) {
        $files = scandir($d);
        foreach ($files as $f) {
            if ($f !== '.' && $f !== '..') {
                $p = $d . '/' . $f;
                if (is_file($p)) {
                    $total_files++;
                    $total_file_bytes += filesize($p);
                }
            }
        }
    }
}
$storage_used_mb = round($total_file_bytes / (1024 * 1024), 2);
$allocated_gb = 10;
$storage_pct = round(($storage_used_mb / ($allocated_gb * 1024)) * 100, 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings — InternHub Admin</title>
    <?php include __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
        .set-container {
            padding: 24px 32px 48px;
            max-width: 1540px;
            margin: 0 auto;
        }
        .set-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 22px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .set-title {
            font-size: 1.6rem;
            font-weight: 800;
            color: #1e1b4b;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .set-subtitle {
            font-size: 0.86rem;
            color: #64748b;
            margin-top: 4px;
        }

        /* 2-Column Settings Layout */
        .set-grid {
            display: grid;
            grid-template-columns: 1.8fr 1.2fr;
            gap: 24px;
        }
        .set-card {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid #eef1f6;
            padding: 26px 28px;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.03);
            margin-bottom: 24px;
        }
        .set-card-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: #1e1b4b;
            margin: 0 0 4px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .set-card-sub {
            font-size: 0.8rem;
            color: #64748b;
            margin-bottom: 20px;
        }

        /* Storage Bar */
        .storage-bar-bg {
            height: 12px;
            background: #f1f5f9;
            border-radius: 6px;
            overflow: hidden;
            margin: 12px 0 8px;
        }
        .storage-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #7c3aed, #a855f7);
            border-radius: 6px;
        }

        .switch-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #f8fafc;
        }
        .switch-title {
            font-size: 0.86rem;
            font-weight: 600;
            color: #1e1b4b;
        }
        .switch-desc {
            font-size: 0.76rem;
            color: #64748b;
            margin-top: 2px;
        }

        @media (max-width: 992px) {
            .set-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

    <div class="set-container">
        
        <!-- Header -->
        <div class="set-header">
            <div>
                <h1 class="set-title"><i class="fa-solid fa-sliders" style="color: #7c3aed;"></i> System Settings</h1>
                <div class="set-subtitle">Academic cycle configuration, storage quotas, institutional identity, and platform maintenance.</div>
            </div>
        </div>

        <!-- Feedback Alerts -->
        <?php if ($successMsg): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert" style="border-radius: 12px;">
                <i class="fa-solid fa-circle-check"></i> <?php echo $successMsg; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-radius: 12px;">
                <i class="fa-solid fa-triangle-exclamation"></i> <?php echo $errorMsg; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="save_settings">

            <div class="set-grid">
                
                <!-- Left Column: Core Configuration -->
                <div>
                    <!-- Academic Session -->
                    <div class="set-card">
                        <h3 class="set-card-title"><i class="fa-solid fa-graduation-cap" style="color: #7c3aed;"></i> Academic Cycle & Registration</h3>
                        <div class="set-card-sub">Global parameters governing internship cycles and student account enrollment</div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Current Academic Year</label>
                                <input type="text" name="academic_year" class="form-control" value="<?php echo h($settings['academic_year']); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Current Term / Semester Phase</label>
                                <input type="text" name="current_term" class="form-control" value="<?php echo h($settings['current_term']); ?>" required>
                            </div>
                        </div>

                        <div class="switch-row">
                            <div>
                                <div class="switch-title">Student Self-Registration</div>
                                <div class="switch-desc">Allow students to sign up with verified university email addresses</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="allow_student_reg" <?php echo $settings['allow_student_reg'] === '1' ? 'checked' : ''; ?>>
                            </div>
                        </div>

                        <div class="switch-row">
                            <div>
                                <div class="switch-title">Faculty Self-Registration & Approval Queue</div>
                                <div class="switch-desc">Allow professors to submit registration requests awaiting admin approval</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="allow_faculty_reg" <?php echo $settings['allow_faculty_reg'] === '1' ? 'checked' : ''; ?>>
                            </div>
                        </div>

                        <div class="switch-row">
                            <div>
                                <div class="switch-title">Allow Late Weekly Report Submissions</div>
                                <div class="switch-desc">Permit students to upload retroactive weekly reports with mentor consent</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="allow_late_reports" <?php echo $settings['allow_late_reports'] === '1' ? 'checked' : ''; ?>>
                            </div>
                        </div>
                    </div>

                    <!-- Institutional Identity -->
                    <div class="set-card">
                        <h3 class="set-card-title"><i class="fa-solid fa-landmark" style="color: #7c3aed;"></i> Institutional Identity</h3>
                        <div class="set-card-sub">Branding displayed on generated certificates, letters, and emails</div>

                        <div class="mb-3">
                            <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">University / Institution Name</label>
                            <input type="text" name="institution_name" class="form-control" value="<?php echo h($settings['institution_name']); ?>" required>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Support & Admin Contact Email</label>
                                <input type="email" name="support_email" class="form-control" value="<?php echo h($settings['support_email']); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Admin Session Timeout (Minutes)</label>
                                <input type="number" name="session_timeout" class="form-control" value="<?php echo h($settings['session_timeout']); ?>" min="15" max="480">
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="background: #3a104f; border-color: #3a104f; border-radius: 12px; font-weight: 700; padding: 12px 28px;">
                        <i class="fa-solid fa-floppy-disk"></i> Save System Configuration
                    </button>
                </div>

                <!-- Right Column: Storage & Health -->
                <div>
                    <!-- Storage Monitoring (Integrated from storage_monitoring.php) -->
                    <div class="set-card">
                        <h3 class="set-card-title"><i class="fa-solid fa-hard-drive" style="color: #059669;"></i> Storage & Data Quota</h3>
                        <div class="set-card-sub">Live monitoring of uploaded offer letters, reports, and certificates</div>

                        <div style="display: flex; justify-content: space-between; align-items: baseline;">
                            <span style="font-size: 1.4rem; font-weight: 800; color: #1e1b4b;"><?php echo $storage_used_mb; ?> MB</span>
                            <span style="font-size: 0.78rem; color: #64748b;">of <?php echo $allocated_gb; ?> GB Total Quota</span>
                        </div>

                        <div class="storage-bar-bg">
                            <div class="storage-bar-fill" style="width: <?php echo max(2, min(100, $storage_pct)); ?>%;"></div>
                        </div>

                        <div style="font-size: 0.76rem; color: #64748b; margin-top: 6px;">
                            <strong><?php echo number_format($total_files); ?></strong> files verified in object repository (<?php echo $storage_pct; ?>% capacity).
                        </div>

                        <div style="margin-top: 18px; padding-top: 14px; border-top: 1px solid #f1f5f9; display: flex; justify-content: space-between; font-size: 0.8rem;">
                            <span style="color: #64748b;">Storage Health</span>
                            <span style="color: #059669; font-weight: 700;"><i class="fa-solid fa-circle-check"></i> Optimal (Normal)</span>
                        </div>
                    </div>

                    <!-- System Security & Maintenance -->
                    <div class="set-card">
                        <h3 class="set-card-title"><i class="fa-solid fa-shield-halved" style="color: #be123c;"></i> Maintenance & Security</h3>
                        <div class="set-card-sub">Platform emergency lock and administrative security tools</div>

                        <div class="switch-row" style="border-bottom: none;">
                            <div>
                                <div class="switch-title">Maintenance Mode</div>
                                <div class="switch-desc">Restrict student and faculty logins during semester migrations</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="maintenance_mode" <?php echo $settings['maintenance_mode'] === '1' ? 'checked' : ''; ?>>
                            </div>
                        </div>

                        <div style="margin-top: 16px; padding: 14px; background: #f8fafc; border-radius: 12px;">
                            <div style="font-weight: 700; font-size: 0.82rem; color: #1e1b4b;">Security Quick Links</div>
                            <div style="display: flex; flex-direction: column; gap: 8px; margin-top: 8px;">
                                <a href="audit_logs.php" style="color: #7c3aed; font-size: 0.78rem; text-decoration: none; font-weight: 600;">
                                    <i class="fa-solid fa-arrow-right"></i> Review Security Audit Trail & Logins
                                </a>
                                <a href="change_password.php" style="color: #7c3aed; font-size: 0.78rem; text-decoration: none; font-weight: 600;">
                                    <i class="fa-solid fa-arrow-right"></i> Change Administrator Password
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </form>

    </div>

    <!-- Layout Close -->
    </main>
    </div>

    <?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>
</body>
</html>
