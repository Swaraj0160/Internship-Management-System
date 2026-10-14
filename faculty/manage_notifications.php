<?php

session_start();
include("../config.php");
include("../mail_function.php");

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'faculty') {
    header("Location: ../login.php");
    exit();
}

$faculty_id = (int) $_SESSION['user_id'];

/* -----------------------------------------------------------------
   ESCAPING HELPER
   Every dynamic value printed into HTML goes through this. Prevents
   stored XSS from notification titles/messages/attachment names.
   ----------------------------------------------------------------- */
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/* -----------------------------------------------------------------
   TRUNCATE HELPER — compact preview for the table, full text stays
   in the DB / modal untouched.
   ----------------------------------------------------------------- */
function shortText(?string $text, int $limit = 150): string
{
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $limit, '...');
    }
    return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
}

/* -----------------------------------------------------------------
   ATTACHMENT ICON HELPER — maps a filename's extension to a
   Font Awesome icon, label and accent color for the table + modal.
   ----------------------------------------------------------------- */
function getAttachmentIcon(?string $filename): array
{
    $filename = trim((string) $filename);
    if ($filename === '') {
        return ['icon' => 'fa-file', 'label' => 'File', 'color' => '#64748b'];
    }

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    $map = [
        'pdf'  => ['icon' => 'fa-file-pdf',        'label' => 'PDF Document',     'color' => '#ef4444'],
        'doc'  => ['icon' => 'fa-file-word',       'label' => 'Word Document',    'color' => '#3B82F6'],
        'docx' => ['icon' => 'fa-file-word',       'label' => 'Word Document',    'color' => '#3B82F6'],
        'xls'  => ['icon' => 'fa-file-excel',      'label' => 'Excel Spreadsheet', 'color' => '#22C55E'],
        'xlsx' => ['icon' => 'fa-file-excel',      'label' => 'Excel Spreadsheet', 'color' => '#22C55E'],
        'csv'  => ['icon' => 'fa-file-csv',        'label' => 'CSV File',         'color' => '#22C55E'],
        'ppt'  => ['icon' => 'fa-file-powerpoint', 'label' => 'PowerPoint File',  'color' => '#F59E0B'],
        'pptx' => ['icon' => 'fa-file-powerpoint', 'label' => 'PowerPoint File',  'color' => '#F59E0B'],
        'jpg'  => ['icon' => 'fa-file-image',      'label' => 'Image',            'color' => '#7B61FF'],
        'jpeg' => ['icon' => 'fa-file-image',      'label' => 'Image',            'color' => '#7B61FF'],
        'png'  => ['icon' => 'fa-file-image',      'label' => 'Image',            'color' => '#7B61FF'],
        'gif'  => ['icon' => 'fa-file-image',      'label' => 'Image',            'color' => '#7B61FF'],
        'webp' => ['icon' => 'fa-file-image',      'label' => 'Image',            'color' => '#7B61FF'],
        'zip'  => ['icon' => 'fa-file-zipper',     'label' => 'ZIP Archive',      'color' => '#F59E0B'],
        'rar'  => ['icon' => 'fa-file-zipper',     'label' => 'RAR Archive',      'color' => '#F59E0B'],
        '7z'   => ['icon' => 'fa-file-zipper',     'label' => 'Archive',          'color' => '#F59E0B'],
        'txt'  => ['icon' => 'fa-file-lines',      'label' => 'Text File',        'color' => '#64748b'],
    ];

    return $map[$ext] ?? ['icon' => 'fa-file', 'label' => strtoupper($ext ?: 'FILE') . ' File', 'color' => '#64748b'];
}

/* -----------------------------------------------------------------
   GET FACULTY DIVISION
   ----------------------------------------------------------------- */
$getFaculty = mysqli_query(
    $conn,
    "SELECT faculty_division
     FROM users
     WHERE id='$faculty_id'"
);
$facultyData = mysqli_fetch_assoc($getFaculty);

/*
 * FACULTY DIVISIONS
 * A faculty member may be assigned to multiple divisions through
 * faculty_divisions. Use all assigned divisions for notifications.
 * Keep users.faculty_division as a fallback for older faculty accounts.
 */
$faculty_divisions = [];

$divisionQuery = mysqli_query(
    $conn,
    "SELECT DISTINCT division
     FROM faculty_divisions
     WHERE faculty_id='$faculty_id'
       AND division IS NOT NULL
       AND TRIM(division) <> ''
     ORDER BY division ASC"
);

if ($divisionQuery) {
    while ($divisionRow = mysqli_fetch_assoc($divisionQuery)) {
        $divisionValue = trim((string)($divisionRow['division'] ?? ''));
        if ($divisionValue !== '' && !in_array($divisionValue, $faculty_divisions, true)) {
            $faculty_divisions[] = $divisionValue;
        }
    }
    mysqli_free_result($divisionQuery);
}

if (empty($faculty_divisions) && !empty($facultyData['faculty_division'])) {
    $faculty_divisions[] = trim((string)$facultyData['faculty_division']);
}

$divisionValues = [];
foreach ($faculty_divisions as $divisionValue) {
    $divisionValues[] = "'" . mysqli_real_escape_string($conn, $divisionValue) . "'";
}

$faculty_division_sql = !empty($divisionValues)
    ? implode(',', $divisionValues)
    : "''";

$division = implode(', ', $faculty_divisions);

/* -----------------------------------------------------------------
   ADD NOTIFICATION
   ----------------------------------------------------------------- */
if (isset($_POST['add_notification'])) {

    $title = mysqli_real_escape_string($conn, $_POST['title'] ?? '');
    $message = (string) ($_POST['message'] ?? '');
    $message_db = mysqli_real_escape_string($conn, $message);

    /* FILE UPLOAD */
    $attachment = "";

    if (isset($_FILES['attachment']) && $_FILES['attachment']['name'] != '') {
        // Sanitize the original filename before prefixing with a timestamp,
        // so path separators / unexpected characters can't escape the
        // uploads/notifications directory.
        $original_name = basename($_FILES['attachment']['name']);
        $safe_name = preg_replace('/[^A-Za-z0-9._-]/', '_', $original_name);
        $attachment = time() . "_" . $safe_name;

        move_uploaded_file(
            $_FILES['attachment']['tmp_name'],
            "../uploads/notifications/" . $attachment
        );
    }

    /*
     * SAVE ONE NOTIFICATION PER ASSIGNED DIVISION.
     * The student side can continue filtering by the existing single
     * division value, while one faculty action reaches every division.
     */
    foreach ($faculty_divisions as $division_value) {
        $division_esc = mysqli_real_escape_string($conn, $division_value);

        mysqli_query(
            $conn,
            "INSERT INTO faculty_notifications
            (faculty_id, division, title, message, attachment)
            VALUES
            ('$faculty_id', '$division_esc', '$title', '$message_db', '$attachment')"
        );
    }

    /* SEND EMAIL TO STUDENTS FROM ALL ASSIGNED DIVISIONS */
    $students = mysqli_query($conn, "
        SELECT DISTINCT users.full_name, users.email
        FROM users
        JOIN internships ON users.id = internships.student_id
        WHERE users.division IN ($faculty_division_sql)
        AND internships.status IN ('Pending','Approved')
    ");

    // Escape once for use in the email body (nl2br preserves line breaks).
    $title_for_email = htmlspecialchars($_POST['title'] ?? '', ENT_QUOTES, 'UTF-8');

    while ($student = mysqli_fetch_assoc($students)) {
        $student_name = htmlspecialchars($student['full_name'], ENT_QUOTES, 'UTF-8');
        $student_email = $student['email'];

        $subject = "New Internship Notification";

        $email_message = "
            <h3>MIT Internship Portal Notification</h3>
            <p>Dear <b>$student_name</b>,</p>
            <p>A new internship notification has been posted by your faculty.</p>
            <p><b>Title:</b> $title_for_email</p>
            <p><b>Message:</b><br>" . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . "</p>
            <hr>
            <p>Please login to the Internship Portal for complete details:</p>
            <p>
                <a href='https://mitinternship.online/login.php'
                   style='background:#0d6efd;color:white;padding:10px 15px;text-decoration:none;border-radius:5px;'>
                   Open Internship Portal
                </a>
            </p>
            <br>
            <p>Regards,<br><b>MIT Internship Portal</b></p>
        ";

        sendPortalMail($student_email, $subject, $email_message);
    }

    echo "<script>alert('Notification Sent Successfully');</script>";
}

/* -----------------------------------------------------------------
   DELETE
   ----------------------------------------------------------------- */
if (isset($_GET['delete'])) {

    $id = (int) $_GET['delete']; // cast to int — closes the SQL injection gap

    $getFile = mysqli_query(
        $conn,
        "SELECT attachment
         FROM faculty_notifications
         WHERE id='$id' AND faculty_id='$faculty_id'"
    );
    $fileData = mysqli_fetch_assoc($getFile);

    if (!empty($fileData['attachment'])) {
        $filePath = "../uploads/notifications/" . $fileData['attachment'];
        if (is_file($filePath)) {
            unlink($filePath);
        }
    }

    mysqli_query(
        $conn,
        "DELETE FROM faculty_notifications
         WHERE id='$id' AND faculty_id='$faculty_id'"
    );

    echo "<script>window.location='manage_notifications.php';</script>";
}

/* -----------------------------------------------------------------
   FETCH
   ----------------------------------------------------------------- */
$notifications = mysqli_query(
    $conn,
    "SELECT * FROM faculty_notifications
     WHERE faculty_id='$faculty_id'
     ORDER BY id DESC"
);

?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Faculty Notifications</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<?php include __DIR__ . '/includes/sidebar_head.php'; ?>

<style>
body{
    background:#f1f5f9;
    margin:0;
}
.page-inner{
    padding:0 24px 40px;
}
.container-box{
    width:100%;
    margin:auto;
    margin-top:24px;
    background:white;
    padding:30px;
    border-radius:15px;
    box-shadow:0px 2px 10px rgba(0,0,0,0.1);
}
textarea{
    resize:none;
}

/* ---- Message column: compact preview + Read More trigger ---- */
.msg-preview{
    color:#334155;
    margin-bottom:6px;
    line-height:1.5;
}
.read-more-btn{
    border:1px solid rgba(13,110,253,.25);
    background:rgba(13,110,253,.06);
    color:#0d6efd;
    font-weight:600;
    font-size:.8rem;
    padding:4px 12px;
    border-radius:999px;
    transition:background .2s ease, transform .2s ease;
}
.read-more-btn:hover{
    background:#0d6efd;
    color:#fff;
    transform:translateY(-1px);
}

/* ---- Attachment chip in table ---- */
.attachment-chip{
    display:inline-flex;
    align-items:center;
    gap:6px;
    font-size:.82rem;
    color:#475569;
}

/* ---- Modal styling ---- */
.notif-modal-body{
    max-height:65vh;
    overflow-y:auto;
}
.notif-modal-message{
    white-space:pre-wrap;
    overflow-wrap:anywhere;
    line-height:1.7;
    color:#26304a;
}
.notif-modal-time{
    color:#64748b;
    font-size:.88rem;
    margin-bottom:14px;
}
.notif-attachment-card{
    display:flex;
    align-items:center;
    gap:14px;
    padding:14px;
    border-radius:14px;
    background:#f8fafc;
    border:1px solid #e2e8f0;
    flex-wrap:wrap;
}
.notif-attachment-icon{
    width:50px;
    height:50px;
    border-radius:12px;
    display:grid;
    place-items:center;
    font-size:1.3rem;
    flex:0 0 auto;
}
.notif-attachment-info{
    flex:1 1 160px;
    min-width:0;
}
.notif-attachment-name{
    font-weight:700;
    color:#1e293b;
    overflow-wrap:anywhere;
}
.notif-attachment-type{
    font-size:.82rem;
    color:#64748b;
}
.notif-attachment-actions{
    display:flex;
    gap:8px;
    flex:0 0 auto;
}

@media (max-width:768px){
    .notif-attachment-actions{ width:100%; }
    .notif-attachment-actions a{ flex:1 1 auto; text-align:center; }
}
</style>

</head>

<body>

<?php $activePage = 'manage_notifications.php'; include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="page-inner">
<div class="container-box">

    <h2 class="mb-4">Division Notifications</h2>

    <h5 class="mb-4">Division: <?php echo h($division); ?></h5>

    <!-- ADD FORM -->
    <form method="POST" enctype="multipart/form-data">

        <div class="mb-3">
            <label class="form-label">Notification Title</label>
            <input type="text" name="title" class="form-control" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Notification Message</label>
            <textarea name="message" class="form-control" rows="4" required></textarea>
        </div>

        <div class="mb-3">
            <label class="form-label">Attach File (Optional)</label>
            <input type="file" name="attachment" class="form-control">
        </div>

        <button type="submit" name="add_notification" class="btn btn-primary">
            Send Notification
        </button>

    </form>

    <hr class="my-4">

    <!-- TABLE -->
    <h4 class="mb-3">Sent Notifications</h4>

    <table class="table table-bordered table-striped align-middle">

        <thead class="table-dark">
            <tr>
                <th>Title</th>
                <th>Message</th>
                <th>Attachment</th>
                <th>Created At</th>
                <th>Action</th>
            </tr>
        </thead>

        <tbody>

        <?php if (mysqli_num_rows($notifications) > 0): ?>
            <?php while ($row = mysqli_fetch_assoc($notifications)): ?>
                <?php
                    $full_message   = (string) $row['message'];
                    $preview        = shortText($full_message, 150);
                    $is_long        = mb_strlen(trim($full_message)) > 150;
                    $created_fmt    = date("d M Y h:i A", strtotime($row['created_at']));
                    $attachment     = trim((string) ($row['attachment'] ?? ''));
                    $has_attachment = $attachment !== '';
                    $attachment_url = $has_attachment ? '../uploads/notifications/' . $attachment : '';
                    $attachment_meta = getAttachmentIcon($attachment);
                ?>
                <tr>
                    <td><?php echo h($row['title']); ?></td>

                    <td width="380">
                        <div class="msg-preview"><?php echo nl2br(h($preview)); ?></div>

                        <!-- Single trigger — the shared modal below is
                             populated via JS from these data-* attributes,
                             so there is no per-row modal markup duplicated. -->
                        <button
                            type="button"
                            class="btn btn-sm read-more-btn"
                            data-bs-toggle="modal"
                            data-bs-target="#notifDetailModal"
                            data-notif-title="<?php echo h($row['title']); ?>"
                            data-notif-message="<?php echo h($full_message); ?>"
                            data-notif-time="<?php echo h($created_fmt); ?>"
                            data-notif-has-attachment="<?php echo $has_attachment ? '1' : '0'; ?>"
                            data-notif-attachment-url="<?php echo h($attachment_url); ?>"
                            data-notif-attachment-name="<?php echo h($attachment); ?>"
                            data-notif-attachment-icon="<?php echo h($attachment_meta['icon']); ?>"
                            data-notif-attachment-label="<?php echo h($attachment_meta['label']); ?>"
                            data-notif-attachment-color="<?php echo h($attachment_meta['color']); ?>"
                        >
                            Read More <i class="fa-solid fa-arrow-right ms-1"></i>
                        </button>
                    </td>

                    <td>
                        <?php if ($has_attachment): ?>
                            <span class="attachment-chip">
                                <i class="fa-solid <?php echo h($attachment_meta['icon']); ?>"
                                   style="color:<?php echo h($attachment_meta['color']); ?>"></i>
                                <?php echo h($attachment_meta['label']); ?>
                            </span>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>

                    <td><?php echo h($created_fmt); ?></td>

                    <td>
                        <a href="manage_notifications.php?delete=<?php echo (int) $row['id']; ?>"
                           class="btn btn-danger btn-sm"
                           onclick="return confirm('Delete Notification?')">
                           Delete
                        </a>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr>
                <td colspan="5" class="text-center text-danger">
                    No notifications sent yet.
                </td>
            </tr>
        <?php endif; ?>

        </tbody>

    </table>

</div>
</div>

</main>
</div>

<!-- =====================================================================
     SHARED DETAIL MODAL — filled by JS on open, one instance for the
     whole table (no duplication per row).
     ===================================================================== -->
<div class="modal fade" id="notifDetailModal" tabindex="-1" aria-labelledby="notifDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-radius:18px; overflow:hidden;">

            <div class="modal-header">
                <h5 class="modal-title" id="notifDetailModalLabel">Notification</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body notif-modal-body">
                <div class="notif-modal-time">
                    <i class="fa-regular fa-clock me-2"></i><span id="notifDetailTime">-</span>
                </div>

                <div class="notif-modal-message" id="notifDetailMessage"></div>

                <hr class="my-4">

                <div>
                    <div class="fw-bold text-uppercase small text-secondary mb-2">
                        <i class="fa-solid fa-paperclip me-2"></i>Attachment
                    </div>

                    <div id="notifDetailAttachmentBlock" class="notif-attachment-card d-none">
                        <div class="notif-attachment-icon" id="notifDetailIconWrap">
                            <i class="fa-solid fa-file" id="notifDetailIcon"></i>
                        </div>
                        <div class="notif-attachment-info">
                            <div class="notif-attachment-name" id="notifDetailAttName">-</div>
                            <div class="notif-attachment-type" id="notifDetailAttType">-</div>
                        </div>
                        <div class="notif-attachment-actions">
                            <a href="#" id="notifDetailView" target="_blank" class="btn btn-sm btn-outline-primary">
                                <i class="fa-solid fa-eye me-1"></i>View
                            </a>
                            <a href="#" id="notifDetailDownload" class="btn btn-sm btn-primary" download>
                                <i class="fa-solid fa-download me-1"></i>Download
                            </a>
                        </div>
                    </div>

                    <div id="notifDetailNoAttachment" class="text-secondary d-none">
                        <i class="fa-solid fa-circle-info me-2"></i>No attachment was included with this notification.
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function setupNotifDetailModal(){
    const modalEl = document.getElementById('notifDetailModal');
    if (!modalEl) return;

    modalEl.addEventListener('show.bs.modal', function (event) {
        const trigger = event.relatedTarget;
        if (!trigger) return;

        const title           = trigger.getAttribute('data-notif-title') || 'Notification';
        const message          = trigger.getAttribute('data-notif-message') || '';
        const time              = trigger.getAttribute('data-notif-time') || '-';
        const hasAttachment     = trigger.getAttribute('data-notif-has-attachment') === '1';
        const attachmentUrl     = trigger.getAttribute('data-notif-attachment-url') || '';
        const attachmentName    = trigger.getAttribute('data-notif-attachment-name') || '';
        const attachmentIcon    = trigger.getAttribute('data-notif-attachment-icon') || 'fa-file';
        const attachmentLabel   = trigger.getAttribute('data-notif-attachment-label') || 'File';
        const attachmentColor   = trigger.getAttribute('data-notif-attachment-color') || '#64748b';

        // textContent avoids any HTML injection risk (defense in depth —
        // values are already htmlspecialchars()-escaped server-side).
        document.getElementById('notifDetailModalLabel').textContent = title;
        document.getElementById('notifDetailTime').textContent = time;
        document.getElementById('notifDetailMessage').textContent = message;

        const attBlock = document.getElementById('notifDetailAttachmentBlock');
        const noAttBlock = document.getElementById('notifDetailNoAttachment');

        if (hasAttachment && attachmentUrl) {
            attBlock.classList.remove('d-none');
            noAttBlock.classList.add('d-none');

            document.getElementById('notifDetailIcon').className = 'fa-solid ' + attachmentIcon;

            const iconWrap = document.getElementById('notifDetailIconWrap');
            iconWrap.style.color = attachmentColor;
            iconWrap.style.background = attachmentColor + '1A';

            document.getElementById('notifDetailAttName').textContent = attachmentName;
            document.getElementById('notifDetailAttType').textContent = attachmentLabel;

            const viewBtn = document.getElementById('notifDetailView');
            const downloadBtn = document.getElementById('notifDetailDownload');
            viewBtn.href = attachmentUrl;
            downloadBtn.href = attachmentUrl;
            downloadBtn.setAttribute('download', attachmentName);
        } else {
            attBlock.classList.add('d-none');
            noAttBlock.classList.remove('d-none');
        }
    });
})();
</script>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>

</body>
</html>