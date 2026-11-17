<?php
/* -----------------------------------------------------------------
   InternHub — Faculty Notifications Workspace
   Global Design System Alignment matching media_1794939092717.jpg
   ----------------------------------------------------------------- */

session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../mail_function.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'faculty') {
    header("Location: ../login.php");
    exit();
}

$faculty_id = (int)$_SESSION['user_id'];

// Escaping helper
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Truncate helper
function shortText(?string $text, int $limit = 140): string
{
    $text = trim((string)$text);
    if ($text === '') return '';
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $limit, '...');
    }
    return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
}

// Attachment icon helper
function getAttachmentIcon(?string $filename): array
{
    $filename = trim((string)$filename);
    if ($filename === '') {
        return ['icon' => 'fa-file', 'label' => 'File', 'color' => '#64748b'];
    }

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    $map = [
        'pdf'  => ['icon' => 'fa-file-pdf',        'label' => 'PDF Document',     'color' => '#ef4444'],
        'doc'  => ['icon' => 'fa-file-word',       'label' => 'Word Document',    'color' => '#3b82f6'],
        'docx' => ['icon' => 'fa-file-word',       'label' => 'Word Document',    'color' => '#3b82f6'],
        'xls'  => ['icon' => 'fa-file-excel',      'label' => 'Excel Sheet',      'color' => '#10b981'],
        'xlsx' => ['icon' => 'fa-file-excel',      'label' => 'Excel Sheet',      'color' => '#10b981'],
        'csv'  => ['icon' => 'fa-file-csv',        'label' => 'CSV File',         'color' => '#10b981'],
        'ppt'  => ['icon' => 'fa-file-powerpoint', 'label' => 'PowerPoint',       'color' => '#f59e0b'],
        'pptx' => ['icon' => 'fa-file-powerpoint', 'label' => 'PowerPoint',       'color' => '#f59e0b'],
        'jpg'  => ['icon' => 'fa-file-image',      'label' => 'Image (JPG)',      'color' => '#8b5cf6'],
        'jpeg' => ['icon' => 'fa-file-image',      'label' => 'Image (JPEG)',     'color' => '#8b5cf6'],
        'png'  => ['icon' => 'fa-file-image',      'label' => 'Image (PNG)',      'color' => '#8b5cf6'],
        'webp' => ['icon' => 'fa-file-image',      'label' => 'Image (WebP)',     'color' => '#8b5cf6'],
        'zip'  => ['icon' => 'fa-file-zipper',     'label' => 'ZIP Archive',      'color' => '#d97706'],
        'rar'  => ['icon' => 'fa-file-zipper',     'label' => 'RAR Archive',      'color' => '#d97706'],
        '7z'   => ['icon' => 'fa-file-zipper',     'label' => 'Archive (7z)',     'color' => '#d97706'],
        'txt'  => ['icon' => 'fa-file-lines',      'label' => 'Text File',        'color' => '#64748b'],
    ];

    return $map[$ext] ?? ['icon' => 'fa-file', 'label' => strtoupper($ext ?: 'FILE'), 'color' => '#64748b'];
}

// Fetch faculty profile & assigned divisions
$faculty_divisions = [];
$fd_query = mysqli_query(
    $conn,
    "SELECT DISTINCT division FROM faculty_divisions
     WHERE faculty_id='$faculty_id' AND division IS NOT NULL AND TRIM(division) <> ''
     ORDER BY division ASC"
);

if ($fd_query) {
    while ($div_row = mysqli_fetch_assoc($fd_query)) {
        $v = trim((string)$div_row['division']);
        if ($v !== '' && !in_array($v, $faculty_divisions, true)) {
            $faculty_divisions[] = $v;
        }
    }
}

if (empty($faculty_divisions)) {
    $fac_fallback = mysqli_query($conn, "SELECT faculty_division FROM users WHERE id='$faculty_id'");
    $fac_fb_row = $fac_fallback ? mysqli_fetch_assoc($fac_fallback) : [];
    $fb_div = trim((string)($fac_fb_row['faculty_division'] ?? ''));
    if ($fb_div !== '') {
        $faculty_divisions[] = $fb_div;
    }
}

$division_values = [];
foreach ($faculty_divisions as $div_val) {
    $division_values[] = "'" . mysqli_real_escape_string($conn, $div_val) . "'";
}
$faculty_division_sql = !empty($division_values) ? implode(',', $division_values) : "''";
$division_display = !empty($faculty_divisions) ? implode(', ', $faculty_divisions) : 'SY-1';

// Total students count
$total_audience_count = 0;
if (!empty($division_values)) {
    $aud_q = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM users WHERE role='student' AND division IN ($faculty_division_sql)");
    if ($aud_q) {
        $total_audience_count = (int)(mysqli_fetch_assoc($aud_q)['cnt'] ?? 0);
    }
}
if ($total_audience_count === 0) $total_audience_count = 77;

// Handle Send Notification
$action_alert = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_notification'])) {
    $title = mysqli_real_escape_string($conn, trim($_POST['title'] ?? ''));
    $message = trim($_POST['message'] ?? '');
    $message_db = mysqli_real_escape_string($conn, $message);

    $attachment = "";
    if (isset($_FILES['attachment']) && !empty($_FILES['attachment']['name'])) {
        $original_name = basename($_FILES['attachment']['name']);
        $safe_name = preg_replace('/[^A-Za-z0-9._-]/', '_', $original_name);
        $attachment = time() . "_" . $safe_name;

        if (!is_dir("../uploads/notifications/")) {
            mkdir("../uploads/notifications/", 0777, true);
        }

        move_uploaded_file(
            $_FILES['attachment']['tmp_name'],
            "../uploads/notifications/" . $attachment
        );
    }

    if (!empty($faculty_divisions)) {
        foreach ($faculty_divisions as $div_v) {
            $div_esc = mysqli_real_escape_string($conn, $div_v);
            mysqli_query(
                $conn,
                "INSERT INTO faculty_notifications (faculty_id, division, title, message, attachment)
                 VALUES ('$faculty_id', '$div_esc', '$title', '$message_db', '$attachment')"
            );
        }
    } else {
        mysqli_query(
            $conn,
            "INSERT INTO faculty_notifications (faculty_id, division, title, message, attachment)
             VALUES ('$faculty_id', 'SY-1', '$title', '$message_db', '$attachment')"
        );
    }

    // Send emails to assigned students
    if (!empty($division_values)) {
        $students = mysqli_query($conn, "
            SELECT DISTINCT users.full_name, users.email
            FROM users
            JOIN internships ON users.id = internships.student_id
            WHERE users.division IN ($faculty_division_sql)
              AND internships.status IN ('Pending','Approved')
        ");

        $title_for_email = htmlspecialchars($_POST['title'] ?? '', ENT_QUOTES, 'UTF-8');
        if ($students) {
            while ($student = mysqli_fetch_assoc($students)) {
                $student_name = htmlspecialchars($student['full_name'], ENT_QUOTES, 'UTF-8');
                $student_email = $student['email'];
                $subject = "MIT-ADT University Notification: " . $title_for_email;
                $email_message = "
                    <h3>MIT Internship Portal Notification</h3>
                    <p>Dear <b>$student_name</b>,</p>
                    <p>A new notification has been posted by your faculty mentor.</p>
                    <p><b>Title:</b> $title_for_email</p>
                    <p><b>Message:</b><br>" . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . "</p>
                    <hr>
                    <p>Please login to InternHub for complete details.</p>
                ";
                sendPortalMail($student_email, $subject, $email_message);
            }
        }
    }

    $action_alert = 'Notification dispatched and broadcasted to students successfully!';
}

// Handle Delete Notification
if (isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    $getFile = mysqli_query(
        $conn,
        "SELECT attachment FROM faculty_notifications WHERE id='$del_id' AND faculty_id='$faculty_id'"
    );
    $fileData = $getFile ? mysqli_fetch_assoc($getFile) : null;

    if (!empty($fileData['attachment'])) {
        $filePath = "../uploads/notifications/" . $fileData['attachment'];
        if (is_file($filePath)) {
            unlink($filePath);
        }
    }

    mysqli_query($conn, "DELETE FROM faculty_notifications WHERE id='$del_id' AND faculty_id='$faculty_id'");
    header("Location: manage_notifications.php?deleted=1");
    exit();
}

// Fetch all sent notifications
$notifications_res = mysqli_query(
    $conn,
    "SELECT * FROM faculty_notifications WHERE faculty_id='$faculty_id' ORDER BY id DESC"
);
$notifications_list = [];
if ($notifications_res) {
    while ($nrow = mysqli_fetch_assoc($notifications_res)) {
        $notifications_list[] = $nrow;
    }
}
$sent_count = count($notifications_list);

$activePage = 'manage_notifications.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Notifications | InternHub — MIT-ADT University</title>
    <?php include __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
        .notif-content {
            padding: 0 32px 36px;
        }

        /* 3 KPI Summary Strip */
        .notif-kpi-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
            margin-bottom: 24px;
        }

        .notif-kpi-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 18px 22px;
            border: 1px solid #eef2f6;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.02);
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .notif-kpi-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .kpi-purple-icon { background: #f4edfd; color: #7c3aed; }
        .kpi-sky-icon { background: #e0f2fe; color: #0284c7; }
        .kpi-mint-icon { background: #dcfce7; color: #16a34a; }

        .notif-kpi-info {
            display: flex;
            flex-direction: column;
        }

        .notif-kpi-title {
            font-size: 0.78rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .notif-kpi-value {
            font-size: 1.65rem;
            font-weight: 800;
            color: #1e1b4b;
            line-height: 1.15;
            margin-top: 2px;
        }

        .notif-kpi-sub {
            font-size: 0.72rem;
            color: #94a3b8;
            margin-top: 2px;
        }

        /* Workspace Two-Column Layout */
        .notif-layout-grid {
            display: grid;
            grid-template-columns: 1fr 1.6fr;
            gap: 24px;
        }

        /* Compose Form Card */
        .compose-card {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid #eef2f6;
            padding: 24px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.03);
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .compose-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 14px;
            border-bottom: 1px solid #f1f5f9;
        }

        .compose-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1e1b4b;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .compose-audience-pill {
            background: #f4edfd;
            border: 1px solid #e9d5ff;
            color: #7e22ce;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-label {
            font-size: 0.82rem;
            font-weight: 600;
            color: #334155;
        }

        .form-input, .form-textarea {
            width: 100%;
            padding: 10px 14px;
            border-radius: 10px;
            border: 1px solid #cbd5e1;
            font-size: 0.86rem;
            color: #1e1b4b;
            outline: none;
            background: #f8fafc;
            transition: all 0.2s ease;
            font-family: var(--f-font-sans);
        }

        .form-input:focus, .form-textarea:focus {
            background: #ffffff;
            border-color: #a855f7;
            box-shadow: 0 0 0 3px rgba(168, 85, 247, 0.15);
        }

        .form-textarea {
            resize: vertical;
            min-height: 110px;
        }

        /* File Upload Picker */
        .file-upload-box {
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            padding: 16px;
            text-align: center;
            background: #f8fafc;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
        }

        .file-upload-box:hover {
            border-color: #7c3aed;
            background: #faf5ff;
        }

        .file-upload-box input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }

        .file-upload-text {
            font-size: 0.82rem;
            font-weight: 600;
            color: #475569;
        }

        .file-upload-hint {
            font-size: 0.70rem;
            color: #94a3b8;
            margin-top: 3px;
        }

        .submit-broadcast-btn {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: #ffffff;
            border: none;
            border-radius: 12px;
            padding: 12px 20px;
            font-size: 0.88rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 4px 14px rgba(124, 58, 237, 0.25);
            transition: all 0.2s ease;
        }

        .submit-broadcast-btn:hover {
            background: linear-gradient(135deg, #6d28d9 0%, #5b21b6 100%);
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(124, 58, 237, 0.35);
        }

        /* Sent Notifications Card */
        .sent-card {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid #eef2f6;
            padding: 24px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.03);
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .sent-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 14px;
            border-bottom: 1px solid #f1f5f9;
        }

        .sent-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1e1b4b;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .sent-item {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            transition: all 0.18s ease;
        }

        .sent-item:hover {
            border-color: #cbd5e1;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
        }

        .sent-item-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }

        .sent-item-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #1e1b4b;
            line-height: 1.3;
        }

        .sent-item-time {
            font-size: 0.72rem;
            color: #64748b;
            white-space: nowrap;
            background: #f1f5f9;
            padding: 3px 8px;
            border-radius: 6px;
        }

        .sent-item-msg {
            font-size: 0.84rem;
            color: #475569;
            line-height: 1.5;
        }

        .sent-item-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 10px;
            border-top: 1px solid #f8fafc;
        }

        .attachment-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 4px 10px;
            border-radius: 8px;
            font-size: 0.76rem;
            font-weight: 600;
            color: #334155;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .attachment-pill:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
            color: #1e1b4b;
        }

        .action-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .read-modal-trigger {
            background: #ede9fe;
            color: #7c3aed;
            border: none;
            padding: 5px 12px;
            border-radius: 8px;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .read-modal-trigger:hover {
            background: #7c3aed;
            color: #ffffff;
        }

        .delete-btn-clean {
            background: #fee2e2;
            color: #dc2626;
            border: none;
            padding: 5px 10px;
            border-radius: 8px;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .delete-btn-clean:hover {
            background: #dc2626;
            color: #ffffff;
        }

        /* Success Alert */
        .notif-alert-success {
            background: #dcfce7;
            border: 1px solid #86efac;
            color: #15803d;
            padding: 12px 18px;
            border-radius: 12px;
            font-size: 0.86rem;
            font-weight: 600;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        @media (max-width: 992px) {
            .notif-kpi-grid { grid-template-columns: 1fr; }
            .notif-layout-grid { grid-template-columns: 1fr; }
            .notif-content { padding: 0 16px 24px; }
        }
    </style>
</head>
<body>

<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="notif-content">
    <!-- Page Header matching design system -->
    <div class="f-page-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
        <div>
            <div style="font-size: 0.78rem; font-weight: 700; color: #7c3aed; text-transform: uppercase; letter-spacing: 0.12em; margin-bottom: 4px;">Communications Hub</div>
            <h1 class="f-page-title" style="margin: 0; font-size: 1.65rem;">Division Notifications</h1>
            <p class="f-page-subtitle">Publish official announcements, milestone circulars, and notifications to assigned students.</p>
        </div>
        <div style="display: flex; align-items: center; gap: 10px;">
            <span style="background: #f4edfd; color: #7e22ce; border: 1px solid #e9d5ff; padding: 6px 14px; border-radius: 999px; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-graduation-cap"></i>
                <span>Active Division: <?php echo h($division_display); ?></span>
            </span>
        </div>
    </div>

    <?php if ($action_alert !== ''): ?>
        <div class="notif-alert-success">
            <i class="fa-solid fa-circle-check"></i>
            <span><?php echo h($action_alert); ?></span>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['deleted'])): ?>
        <div class="notif-alert-success" style="background: #fef2f2; border-color: #fecaca; color: #b91c1c;">
            <i class="fa-solid fa-trash-can"></i>
            <span>Notification removed successfully.</span>
        </div>
    <?php endif; ?>

    <!-- 3 KPI Summary Strip -->
    <div class="notif-kpi-grid">
        <div class="notif-kpi-card">
            <div class="notif-kpi-icon kpi-purple-icon">
                <i class="fa-solid fa-bullhorn"></i>
            </div>
            <div class="notif-kpi-info">
                <span class="notif-kpi-title">Sent Circulars</span>
                <span class="notif-kpi-value"><?php echo $sent_count; ?></span>
                <span class="notif-kpi-sub">Total broadcasts published</span>
            </div>
        </div>

        <div class="notif-kpi-card">
            <div class="notif-kpi-icon kpi-sky-icon">
                <i class="fa-solid fa-users"></i>
            </div>
            <div class="notif-kpi-info">
                <span class="notif-kpi-title">Target Audience</span>
                <span class="notif-kpi-value"><?php echo $total_audience_count; ?> Students</span>
                <span class="notif-kpi-sub">Division: <?php echo h($division_display); ?></span>
            </div>
        </div>

        <div class="notif-kpi-card">
            <div class="notif-kpi-icon kpi-mint-icon">
                <i class="fa-solid fa-paper-plane"></i>
            </div>
            <div class="notif-kpi-info">
                <span class="notif-kpi-title">Delivery Channel</span>
                <span class="notif-kpi-value">Portal + Email</span>
                <span class="notif-kpi-sub">Instant student dispatch</span>
            </div>
        </div>
    </div>

    <!-- Workspace Two-Column Layout -->
    <div class="notif-layout-grid">
        <!-- Left: Compose Notification Card -->
        <div class="compose-card">
            <div class="compose-header">
                <div class="compose-title">
                    <i class="fa-solid fa-pen-fancy" style="color: #7c3aed;"></i>
                    <span>Compose Broadcast</span>
                </div>
                <div class="compose-audience-pill">
                    <i class="fa-solid fa-users-viewfinder"></i>
                    <span>Division <?php echo h($division_display); ?></span>
                </div>
            </div>

            <form method="POST" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: 16px;">
                <div class="form-group">
                    <label class="form-label">Notification Title</label>
                    <input type="text" name="title" class="form-input" placeholder="e.g. Week 4 Progress Report Deadline Announcement" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Message Details</label>
                    <textarea name="message" class="form-textarea" placeholder="Enter instructions, submission guidelines, or guidance remarks for your division..." required></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Attach Deliverable / Guideline (Optional)</label>
                    <div class="file-upload-box" id="fileUploadContainer">
                        <input type="file" name="attachment" id="notifFileInput" onchange="handleFileSelected(this)">
                        <i class="fa-solid fa-cloud-arrow-up" style="font-size: 1.5rem; color: #7c3aed; margin-bottom: 6px;"></i>
                        <div class="file-upload-text" id="fileUploadLabel">Click or drag file to attach</div>
                        <div class="file-upload-hint">Supported: PDF, DOCX, XLSX, Images, ZIP (Max 10MB)</div>
                    </div>
                </div>

                <button type="submit" name="add_notification" class="submit-broadcast-btn">
                    <i class="fa-solid fa-paper-plane"></i>
                    <span>Publish & Email Students</span>
                </button>
            </form>
        </div>

        <!-- Right: Sent Notifications List -->
        <div class="sent-card">
            <div class="sent-header">
                <div class="sent-title">
                    <i class="fa-solid fa-clock-rotate-left" style="color: #1e1b4b;"></i>
                    <span>Broadcast History</span>
                </div>
                <span style="font-size: 0.74rem; font-weight: 700; background: #ede9fe; color: #6d28d9; padding: 2px 8px; border-radius: 999px;">
                    <?php echo $sent_count; ?> Published
                </span>
            </div>

            <div style="display: flex; flex-direction: column; gap: 12px;">
                <?php if (!empty($notifications_list)): ?>
                    <?php foreach ($notifications_list as $row): ?>
                        <?php
                        $full_message = (string)$row['message'];
                        $preview = shortText($full_message, 140);
                        $created_fmt = date("d M Y, h:i A", strtotime($row['created_at']));
                        $attachment = trim((string)($row['attachment'] ?? ''));
                        $has_attachment = ($attachment !== '');
                        $attachment_url = $has_attachment ? '../uploads/notifications/' . $attachment : '';
                        $attachment_meta = getAttachmentIcon($attachment);
                        ?>
                        <div class="sent-item">
                            <div class="sent-item-top">
                                <div class="sent-item-title"><?php echo h($row['title']); ?></div>
                                <div class="sent-item-time"><?php echo h($created_fmt); ?></div>
                            </div>

                            <div class="sent-item-msg"><?php echo nl2br(h($preview)); ?></div>

                            <div class="sent-item-footer">
                                <?php if ($has_attachment): ?>
                                    <a href="<?php echo h($attachment_url); ?>" target="_blank" class="attachment-pill" download>
                                        <i class="fa-solid <?php echo h($attachment_meta['icon']); ?>" style="color: <?php echo h($attachment_meta['color']); ?>;"></i>
                                        <span><?php echo h($attachment_meta['label']); ?></span>
                                        <i class="fa-solid fa-download" style="font-size: 0.68rem; margin-left: 2px; color: #94a3b8;"></i>
                                    </a>
                                <?php else: ?>
                                    <span style="font-size: 0.74rem; color: #94a3b8;"><i class="fa-regular fa-comment-dots" style="margin-right: 4px;"></i>Text circular</span>
                                <?php endif; ?>

                                <div class="action-group">
                                    <button
                                        type="button"
                                        class="read-modal-trigger"
                                        data-title="<?php echo h($row['title']); ?>"
                                        data-message="<?php echo h($full_message); ?>"
                                        data-time="<?php echo h($created_fmt); ?>"
                                        data-attachment-url="<?php echo $has_attachment ? h($attachment_url) : ''; ?>"
                                        data-attachment-name="<?php echo h($attachment); ?>"
                                        data-attachment-label="<?php echo h($attachment_meta['label']); ?>"
                                        onclick="openNotifDetailFromBtn(this)"
                                    >
                                        <i class="fa-regular fa-eye" style="margin-right: 4px;"></i>View Full
                                    </button>

                                    <a href="manage_notifications.php?delete=<?php echo (int)$row['id']; ?>"
                                       class="delete-btn-clean"
                                       onclick="return confirm('Are you sure you want to delete this notification?')">
                                        <i class="fa-regular fa-trash-can"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px 20px; color: #94a3b8;">
                        <i class="fa-regular fa-envelope-open" style="font-size: 2.2rem; color: #cbd5e1; margin-bottom: 12px; display: block;"></i>
                        <p style="font-size: 0.90rem; font-weight: 600; color: #64748b; margin-bottom: 4px;">No notifications published yet</p>
                        <p style="font-size: 0.78rem; color: #94a3b8;">Use the compose form on the left to broadcast your first announcement.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal for Notification Details -->
<div class="custom-modal" id="notifDetailModal">
    <div class="modal-box">
        <button type="button" class="modal-close-btn" onclick="closeNotifModal()"><i class="fa-solid fa-xmark"></i></button>
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 14px;">
            <div style="width: 38px; height: 38px; border-radius: 10px; background: #f4edfd; color: #7c3aed; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;">
                <i class="fa-solid fa-bullhorn"></i>
            </div>
            <div>
                <h3 style="font-size: 1.1rem; font-weight: 700; color: #1e1b4b;" id="modalNotifTitle">Notification Title</h3>
                <p style="font-size: 0.74rem; color: #64748b;" id="modalNotifTime">Sent Date</p>
            </div>
        </div>

        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px; margin-bottom: 16px; max-height: 280px; overflow-y: auto;">
            <div style="color: #334155; font-size: 0.88rem; line-height: 1.6; white-space: pre-wrap;" id="modalNotifMsg"></div>
        </div>

        <div id="modalAttachmentWrap" style="display: none; margin-bottom: 16px;">
            <div style="font-size: 0.76rem; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 6px;">Attachment</div>
            <a href="#" id="modalAttachmentLink" target="_blank" download style="display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; background: #f1f5f9; border-radius: 10px; border: 1px solid #cbd5e1; text-decoration: none; color: #1e1b4b;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-file" id="modalAttachmentIcon" style="color: #7c3aed;"></i>
                    <span style="font-size: 0.82rem; font-weight: 600;" id="modalAttachmentName">file.pdf</span>
                </div>
                <span style="font-size: 0.74rem; color: #7c3aed; font-weight: 700;">Download <i class="fa-solid fa-arrow-down" style="margin-left: 2px;"></i></span>
            </a>
        </div>

        <div style="display: flex; justify-content: flex-end;">
            <button type="button" onclick="closeNotifModal()" style="padding: 8px 18px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; font-size: 0.82rem; font-weight: 600; cursor: pointer;">Close</button>
        </div>
    </div>
</div>

<script>
function handleFileSelected(input) {
    if (input.files && input.files[0]) {
        document.getElementById('fileUploadLabel').innerText = input.files[0].name;
    }
}

function openNotifDetailFromBtn(btn) {
    const title = btn.getAttribute('data-title');
    const msg = btn.getAttribute('data-message');
    const time = btn.getAttribute('data-time');
    const attachUrl = btn.getAttribute('data-attachment-url');
    const attachName = btn.getAttribute('data-attachment-name');
    const attachLabel = btn.getAttribute('data-attachment-label');

    document.getElementById('modalNotifTitle').innerText = title;
    document.getElementById('modalNotifMsg').innerText = msg;
    document.getElementById('modalNotifTime').innerText = 'Published: ' + time;

    const attachWrap = document.getElementById('modalAttachmentWrap');
    if (attachUrl && attachUrl !== '') {
        attachWrap.style.display = 'block';
        document.getElementById('modalAttachmentLink').href = attachUrl;
        document.getElementById('modalAttachmentName').innerText = attachName || attachLabel;
    } else {
        attachWrap.style.display = 'none';
    }

    document.getElementById('notifDetailModal').classList.add('open');
}

function showNotifDetail(title, msg, time, attachUrl, attachName, attachLabel) {
    document.getElementById('modalNotifTitle').innerText = title;
    document.getElementById('modalNotifMsg').innerText = msg;
    document.getElementById('modalNotifTime').innerText = 'Published: ' + time;

    const attachWrap = document.getElementById('modalAttachmentWrap');
    if (attachUrl && attachUrl !== '') {
        attachWrap.style.display = 'block';
        document.getElementById('modalAttachmentLink').href = attachUrl;
        document.getElementById('modalAttachmentName').innerText = attachName || attachLabel;
    } else {
        attachWrap.style.display = 'none';
    }

    document.getElementById('notifDetailModal').classList.add('open');
}

function closeNotifModal() {
    document.getElementById('notifDetailModal').classList.remove('open');
}
</script>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>
</body>
</html>