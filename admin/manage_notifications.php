<?php
session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$activePage = 'manage_notifications.php';
$adminId = (int)($_SESSION['user_id'] ?? 0);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$successMsg = '';
$errorMsg = '';

// Create upload directory if missing
$notif_dir = __DIR__ . '/../uploads/notifications/';
if (!is_dir($notif_dir)) {
    mkdir($notif_dir, 0777, true);
}

// -------------------------------------------------------------
// POST ACTIONS (Add & Delete Notification)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $postedCsrf)) {
        $errorMsg = 'Security validation failed (Invalid CSRF token).';
    } else {
        $action = trim($_POST['action'] ?? '');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        // 1. ADD NOTIFICATION
        if ($action === 'add_notification') {
            $title = trim($_POST['title'] ?? '');
            $message = trim($_POST['message'] ?? '');
            $target_role = in_array($_POST['target_role'] ?? '', ['all', 'student', 'faculty']) ? $_POST['target_role'] : 'all';
            $attachment = '';

            if ($title === '' || $message === '') {
                $errorMsg = 'Notification title and message content are required.';
            } else {
                if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                    $safe_name = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', basename($_FILES['attachment']['name']));
                    $attachment = time() . '_' . $safe_name;
                    move_uploaded_file($_FILES['attachment']['tmp_name'], $notif_dir . $attachment);
                }

                $stmt = $conn->prepare("INSERT INTO admin_notifications (title, message, target_role, attachment, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->bind_param("ssss", $title, $message, $target_role, $attachment);
                if ($stmt->execute()) {
                    $newId = $stmt->insert_id;
                    $successMsg = "Broadcast notification <strong>" . h($title) . "</strong> dispatched successfully.";
                    $aud = $conn->prepare("INSERT INTO audit_logs (actor_id, actor_role, action, target_id, target_type, ip_address, user_agent, details) VALUES (?, 'admin', 'create_notification', ?, 'notification', ?, ?, ?)");
                    $dt = json_encode(['title' => $title, 'target' => $target_role]);
                    $aud->bind_param("iisss", $adminId, $newId, $ip, $ua, $dt);
                    $aud->execute();
                } else {
                    $errorMsg = 'Database error: ' . $conn->error;
                }
            }
        }

        // 2. DELETE NOTIFICATION
        elseif ($action === 'delete_notification') {
            $id = (int)($_POST['notification_id'] ?? 0);
            if ($id > 0) {
                $fstmt = $conn->prepare("SELECT attachment FROM admin_notifications WHERE id = ?");
                $fstmt->bind_param("i", $id);
                $fstmt->execute();
                $fdata = $fstmt->get_result()->fetch_assoc();
                if ($fdata && !empty($fdata['attachment'])) {
                    $fpath = $notif_dir . $fdata['attachment'];
                    if (file_exists($fpath)) @unlink($fpath);
                }

                $del = $conn->prepare("DELETE FROM admin_notifications WHERE id = ?");
                $del->bind_param("i", $id);
                if ($del->execute()) {
                    $successMsg = "Notification #$id removed.";
                }
            }
        }
    }
}

// -------------------------------------------------------------
// METRICS & LISTING
// -------------------------------------------------------------
$total_notifs = 0;
$student_notifs = 0;
$faculty_notifs = 0;

$c_q = @mysqli_query($conn, "SELECT target_role, COUNT(*) as c FROM admin_notifications GROUP BY target_role");
if ($c_q) {
    while ($cr = mysqli_fetch_assoc($c_q)) {
        $c = (int)$cr['c'];
        $total_notifs += $c;
        if ($cr['target_role'] === 'student') $student_notifs += $c;
        elseif ($cr['target_role'] === 'faculty') $faculty_notifs += $c;
    }
}

$tab = trim($_GET['tab'] ?? 'all');
$where = "1=1";
if ($tab === 'student') $where = "target_role = 'student'";
elseif ($tab === 'faculty') $where = "target_role = 'faculty'";

$notifs_list = [];
$nq = @mysqli_query($conn, "SELECT * FROM admin_notifications WHERE $where ORDER BY id DESC");
if ($nq) {
    $notifs_list = mysqli_fetch_all($nq, MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Notifications — InternHub Admin</title>
    <?php include __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
        .notif-container {
            padding: 24px 32px 48px;
            max-width: 1540px;
            margin: 0 auto;
        }
        .notif-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 22px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .notif-title {
            font-size: 1.6rem;
            font-weight: 800;
            color: #1e1b4b;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .notif-subtitle {
            font-size: 0.86rem;
            color: #64748b;
            margin-top: 4px;
        }

        /* Stats Strip */
        .notif-stats-strip {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 22px;
        }
        .notif-stat-card {
            background: #ffffff;
            border-radius: 14px;
            padding: 18px 20px;
            border: 1px solid #eef1f6;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.02);
        }
        .notif-stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
        }
        .notif-stat-val {
            font-size: 1.5rem;
            font-weight: 800;
            color: #1e1b4b;
            line-height: 1.1;
        }
        .notif-stat-lbl {
            font-size: 0.76rem;
            font-weight: 600;
            color: #64748b;
            margin-top: 2px;
        }

        /* Tabs */
        .notif-tabs {
            display: flex;
            gap: 8px;
            background: #ffffff;
            padding: 6px;
            border-radius: 14px;
            border: 1px solid #eef1f6;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .notif-tab {
            padding: 9px 18px;
            border-radius: 10px;
            font-size: 0.86rem;
            font-weight: 600;
            color: #64748b;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }
        .notif-tab:hover { background: #f8fafc; color: #1e1b4b; }
        .notif-tab.active { background: #eddff7; color: #3a104f; font-weight: 700; }

        /* Notification Card Feed */
        .notif-feed {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .nc-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #eef1f6;
            padding: 22px 24px;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.02);
            transition: transform 0.2s ease;
        }
        .nc-card:hover { transform: translateY(-2px); }
        .nc-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .nc-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1e1b4b;
            margin: 0;
        }
        .nc-meta {
            font-size: 0.74rem;
            color: #64748b;
            margin-top: 3px;
        }
        .nc-body {
            font-size: 0.86rem;
            color: #475569;
            line-height: 1.5;
            margin-bottom: 14px;
        }
        .nc-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.78rem;
            color: #64748b;
            padding-top: 12px;
            border-top: 1px solid #f8fafc;
        }

        .badge-aud {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        .aud-all { background: #f1f5f9; color: #334155; }
        .aud-student { background: #eff6ff; color: #1d4ed8; }
        .aud-faculty { background: #f5f3ff; color: #7c3aed; }
    </style>
</head>
<body>

    <?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

    <div class="notif-container">
        
        <!-- Header -->
        <div class="notif-header">
            <div>
                <h1 class="notif-title"><i class="fa-solid fa-bullhorn" style="color: #7c3aed;"></i> System Notifications</h1>
                <div class="notif-subtitle">University-wide broadcast notifications dispatched to students, faculty, and academic heads.</div>
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="button" class="btn btn-primary" style="background: #3a104f; border-color: #3a104f; border-radius: 12px; font-weight: 600; padding: 10px 18px;" onclick="openModal('broadcastModal')">
                    <i class="fa-solid fa-paper-plane"></i> Dispatch Broadcast
                </button>
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

        <!-- Stats Strip -->
        <div class="notif-stats-strip">
            <div class="notif-stat-card">
                <div class="notif-stat-icon" style="background: #eddff7; color: #6b21a8;">
                    <i class="fa-solid fa-bell"></i>
                </div>
                <div>
                    <div class="notif-stat-val"><?php echo number_format($total_notifs); ?></div>
                    <div class="notif-stat-lbl">Total Broadcasts</div>
                </div>
            </div>
            <div class="notif-stat-card">
                <div class="notif-stat-icon" style="background: #eff6ff; color: #1d4ed8;">
                    <i class="fa-solid fa-user-graduate"></i>
                </div>
                <div>
                    <div class="notif-stat-val"><?php echo number_format($student_notifs); ?></div>
                    <div class="notif-stat-lbl">Student Announcements</div>
                </div>
            </div>
            <div class="notif-stat-card">
                <div class="notif-stat-icon" style="background: #d1fae5; color: #047857;">
                    <i class="fa-solid fa-chalkboard-user"></i>
                </div>
                <div>
                    <div class="notif-stat-val"><?php echo number_format($faculty_notifs); ?></div>
                    <div class="notif-stat-lbl">Faculty Circulars</div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="notif-tabs">
            <a href="?tab=all" class="notif-tab <?php echo $tab === 'all' ? 'active' : ''; ?>">
                <i class="fa-solid fa-list-check"></i> All Notifications (<?php echo $total_notifs; ?>)
            </a>
            <a href="?tab=student" class="notif-tab <?php echo $tab === 'student' ? 'active' : ''; ?>">
                <i class="fa-solid fa-user-graduate"></i> Student Target
            </a>
            <a href="?tab=faculty" class="notif-tab <?php echo $tab === 'faculty' ? 'active' : ''; ?>">
                <i class="fa-solid fa-chalkboard-user"></i> Faculty Target
            </a>
        </div>

        <!-- Notification Feed -->
        <?php if (!empty($notifs_list)): ?>
            <div class="notif-feed">
                <?php foreach ($notifs_list as $nt): ?>
                    <?php 
                        $aud = $nt['target_role'] ?: 'all';
                        $audCls = 'aud-' . $aud;
                    ?>
                    <div class="nc-card">
                        <div class="nc-head">
                            <div>
                                <h3 class="nc-title"><?php echo h($nt['title']); ?></h3>
                                <div class="nc-meta">
                                    Dispatched: <?php echo $nt['created_at'] ? date('d M Y, h:i A', strtotime($nt['created_at'])) : '-'; ?> • 
                                    <span class="badge-aud <?php echo $audCls; ?>">Target: <?php echo ucfirst($aud); ?></span>
                                </div>
                            </div>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Permanently delete this notification?');">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="action" value="delete_notification">
                                <input type="hidden" name="notification_id" value="<?php echo $nt['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" style="border-radius: 8px;">
                                    <i class="fa-regular fa-trash-can"></i>
                                </button>
                            </form>
                        </div>

                        <div class="nc-body"><?php echo nl2br(h($nt['message'])); ?></div>

                        <div class="nc-footer">
                            <div>
                                <?php if (!empty($nt['attachment'])): ?>
                                    <a href="../uploads/notifications/<?php echo h($nt['attachment']); ?>" target="_blank" class="btn btn-sm btn-outline-primary" style="border-radius: 8px; font-size: 0.76rem; font-weight: 600;">
                                        <i class="fa-solid fa-paperclip"></i> View Attached File
                                    </a>
                                <?php else: ?>
                                    <span style="font-size: 0.74rem; color: #94a3b8;">No file attached</span>
                                <?php endif; ?>
                            </div>
                            <span style="font-size: 0.74rem; color: #94a3b8;">Notice ID #<?php echo $nt['id']; ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div style="background: #ffffff; border-radius: 18px; padding: 48px; text-align: center; border: 1px solid #eef1f6;">
                <i class="fa-regular fa-bell-slash" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 14px;"></i>
                <h3 style="font-size: 1.1rem; color: #1e1b4b; font-weight: 700;">No Notifications Found</h3>
                <p style="font-size: 0.86rem; color: #64748b; margin-top: 4px;">Click 'Dispatch Broadcast' above to send an institutional announcement.</p>
            </div>
        <?php endif; ?>

    </div>

    <!-- MODAL: BROADCAST NOTIFICATION -->
    <div class="custom-modal" id="broadcastModal" style="display: none;">
        <div class="modal-box" style="max-width: 580px;">
            <div class="modal-head">
                <h3 class="modal-head-title"><i class="fa-solid fa-paper-plane"></i> Dispatch Institutional Broadcast</h3>
                <button type="button" class="modal-close" onclick="closeModal('broadcastModal')">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="add_notification">
                <div class="modal-body-scroll">
                    <div class="mb-3">
                        <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Broadcast Title</label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Urgent: Submission Deadline for Week 8 Reports" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Target Audience</label>
                        <select name="target_role" class="form-select">
                            <option value="all">Entire University (Students & Faculty)</option>
                            <option value="student">Students Only</option>
                            <option value="faculty">Faculty Mentors Only</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Notification Message</label>
                        <textarea name="message" class="form-control" rows="4" placeholder="Enter full announcement details..." required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Attachment (Optional PDF, Document, Image)</label>
                        <input type="file" name="attachment" class="form-control">
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-light" onclick="closeModal('broadcastModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background: #3a104f; border-color: #3a104f;">Dispatch Notification</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Layout Close -->
    </main>
    </div>

    <?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>

    <script>
    function openModal(id) {
        const el = document.getElementById(id);
        if (el) el.style.display = 'flex';
    }
    function closeModal(id) {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    }
    document.querySelectorAll('.custom-modal').forEach(m => {
        m.addEventListener('click', function(e) {
            if (e.target === this) this.style.display = 'none';
        });
    });
    </script>
</body>
</html>
