<?php

session_start();
include("../config.php");
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

$student_id = (int)$_SESSION['user_id'];
$activePage = 'add_update.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Verify student has an approved internship with an offer letter uploaded
$check = mysqli_query($conn, "
    SELECT status, offer_letter
    FROM internships
    WHERE student_id='$student_id'
    LIMIT 1
");

$data = mysqli_fetch_assoc($check);

if (!$data || $data['status'] !== 'Approved' || empty($data['offer_letter'])) {
    echo "
    <script>
    alert('You can submit weekly reports only after your internship is approved and the offer letter is uploaded.');
    window.location='dashboard.php';
    </script>
    ";
    exit();
}

/* GET STUDENT DIVISION */
$getStudent = mysqli_query($conn, "SELECT division FROM users WHERE id='$student_id'");
$studentData = mysqli_fetch_assoc($getStudent);
$division = $studentData['division'] ?? '';

/* GET APPROVED INTERNSHIP */
$getApprovedInternship = mysqli_query($conn, "
    SELECT *
    FROM internships
    WHERE student_id='$student_id'
    AND status='Approved'
    LIMIT 1
");

$internship = mysqli_fetch_assoc($getApprovedInternship);
$internship_id = $internship ? (int)$internship['id'] : 0;

/* FETCH DEADLINES */
$deadline_result = mysqli_query($conn, "
    SELECT *
    FROM weekly_deadlines
    WHERE division='$division'
    ORDER BY week_no ASC
");

$alert_message = null;
$alert_type = null;

/* SUBMIT UPDATE */
if (isset($_POST['submit'])) {
    if (!$internship) {
        $alert_message = "No approved internship found.";
        $alert_type = "danger";
    } else {
        $post_internship_id = (int)$_POST['internship_id'];
        $week_no = (int)$_POST['week_no'];
        $work_done = mysqli_real_escape_string($conn, trim($_POST['work_done'] ?? ''));

        if (empty($_FILES['update_file']['name'])) {
            $alert_message = "Please select a PDF file for your weekly report.";
            $alert_type = "warning";
        } else {
            $file_name = "";

            if (isset($_FILES['update_file']) && $_FILES['update_file']['error'] === 0) {
                $max_size = 500 * 1024; // 500 KB Limit

                if ($_FILES['update_file']['size'] > $max_size) {
                    $alert_message = "File exceeds the 500KB size limit. Please upload a smaller PDF file.";
                    $alert_type = "danger";
                } else {
                    $ext = strtolower(pathinfo($_FILES['update_file']['name'], PATHINFO_EXTENSION));
                    $mime = mime_content_type($_FILES['update_file']['tmp_name']);

                    if ($ext !== 'pdf' || $mime !== 'application/pdf') {
                        $alert_message = "Only PDF documents (.pdf) are allowed for weekly reports.";
                        $alert_type = "danger";
                    } else {
                        $upload_dir = "../uploads/weekly_reports/";

                        if (!is_dir($upload_dir)) {
                            @mkdir($upload_dir, 0755, true);
                        }

                        $file_name = time() . "_" . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', basename($_FILES['update_file']['name']));
                        $file_name_db = mysqli_real_escape_string($conn, $file_name);
                        $temp_name = $_FILES['update_file']['tmp_name'];
                        $destination = $upload_dir . $file_name;

                        if (!move_uploaded_file($temp_name, $destination)) {
                            $alert_message = "Failed to upload report file. Check server folder permissions.";
                            $alert_type = "danger";
                        } else {
                            /* CHECK DUPLICATE SUBMISSION */
                            $check_dup = mysqli_query($conn, "
                                SELECT *
                                FROM weekly_updates
                                WHERE internship_id='$post_internship_id'
                                AND week_no='$week_no'
                            ");

                            if (mysqli_num_rows($check_dup) > 0) {
                                $alert_message = "Week {$week_no} update has already been submitted.";
                                $alert_type = "warning";
                            } else {
                                $insert = "INSERT INTO weekly_updates
                                (
                                    internship_id,
                                    week_no,
                                    work_done,
                                    update_file,
                                    submitted_at
                                )
                                VALUES
                                (
                                    '$post_internship_id',
                                    '$week_no',
                                    '$work_done',
                                    '$file_name_db',
                                    NOW()
                                )";

                                if (mysqli_query($conn, $insert)) {
                                    $alert_message = "Weekly Update for Week {$week_no} submitted successfully!";
                                    $alert_type = "success";
                                } else {
                                    $alert_message = "Database error: " . mysqli_error($conn);
                                    $alert_type = "danger";
                                }
                            }
                        }
                    }
                }
            } else {
                $alert_message = "Upload Error Code: " . $_FILES['update_file']['error'];
                $alert_type = "danger";
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
    <title>Weekly Progress Update | MIT-ADT University</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <?php include __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
        :root {
            --bg-main: #f8fafc;
            --surface: #ffffff;
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --brand-purple: #4f46e5;
            --brand-purple-hover: #4338ca;
            --radius-card: 16px;
        }

        body {
            background-color: var(--bg-main);
            font-family: 'Inter', sans-serif;
            color: var(--text-dark);
            margin: 0;
        }

        .page-inner {
            padding: 0 24px 40px;
            max-width: 1040px;
            margin: 0 auto;
        }

        /* Top Hero Header Box */
        .page-header-box {
            background: linear-gradient(135deg, #1e1b4b 0%, #312e81 40%, #4338ca 100%);
            border-radius: 20px;
            padding: 32px;
            color: white;
            margin: 24px 0 28px 0;
            box-shadow: 0 10px 25px -5px rgba(67, 56, 202, 0.25);
            position: relative;
            overflow: hidden;
        }

        .page-header-box::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 50%;
            pointer-events: none;
        }

        .page-title {
            font-size: 1.85rem;
            font-weight: 800;
            margin-bottom: 6px;
            letter-spacing: -0.5px;
        }

        .page-subtitle {
            font-size: 0.95rem;
            color: rgba(255, 255, 255, 0.85);
            max-width: 680px;
            margin: 0;
            line-height: 1.5;
        }

        /* Main Section Cards */
        .content-card {
            background: var(--surface);
            border-radius: 18px;
            border: 1px solid var(--border-color);
            padding: 28px;
            margin-bottom: 24px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.03);
        }

        .card-header-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Table Design */
        .table-responsive {
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--border-color);
        }

        .table {
            margin-bottom: 0;
            font-size: 0.92rem;
        }

        .table thead {
            background: #f8fafc;
        }

        .table thead th {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
            color: var(--text-muted);
            padding: 14px 16px;
            border-bottom: 1px solid var(--border-color);
        }

        .table tbody td {
            padding: 14px 16px;
            vertical-align: middle;
            color: #334155;
        }

        .badge-status {
            font-size: 0.76rem;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            white-space: nowrap;
        }

        /* Form Custom Controls */
        .form-label {
            font-weight: 700;
            font-size: 0.88rem;
            color: #334155;
            margin-bottom: 8px;
        }

        .form-control, .form-select {
            border-radius: 10px;
            border: 1px solid #cbd5e1;
            padding: 10px 14px;
            font-size: 0.95rem;
            transition: all 0.2s;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--brand-purple);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        }

        .upload-dropzone {
            background: #f8fafc;
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            transition: all 0.2s;
            cursor: pointer;
        }

        .upload-dropzone:hover {
            border-color: var(--brand-purple);
            background: #e0e7ff33;
        }

        .btn-submit-update {
            background: var(--brand-purple);
            color: white;
            border: none;
            border-radius: 12px;
            padding: 12px 24px;
            font-size: 1rem;
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
            transition: all 0.2s;
        }

        .btn-submit-update:hover:not(:disabled) {
            background: var(--brand-purple-hover);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(79, 70, 229, 0.35);
        }

        @media (max-width: 768px) {
            .page-header-box {
                padding: 24px;
            }
            .page-title {
                font-size: 1.5rem;
            }
            .content-card {
                padding: 20px;
            }
        }
    </style>
</head>
<body>

<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="page-inner">

    <!-- Top Hero Header Box -->
    <div class="page-header-box d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="page-title"><i class="fa-solid fa-calendar-days me-2"></i> Weekly Progress Update</h1>
            <p class="page-subtitle">Submit your weekly internship progress reports, track submission deadlines, and review your evaluation status.</p>
        </div>
        <div>
            <a href="my_updates.php" class="btn btn-light fw-bold px-4 py-2 shadow-sm text-primary rounded-3">
                <i class="fa-solid fa-clipboard-list me-2"></i> View Submitted Work
            </a>
        </div>
    </div>

    <!-- Alert Notifications -->
    <?php if (!empty($alert_message)): ?>
        <div class="alert alert-<?php echo $alert_type; ?> alert-dismissible fade show rounded-3 shadow-sm mb-4" role="alert">
            <i class="fa-solid <?php echo $alert_type==='success'?'fa-circle-check':($alert_type==='warning'?'fa-circle-exclamation':'fa-triangle-exclamation'); ?> me-2"></i> 
            <?php echo h($alert_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- 1. DEADLINES TABLE CARD -->
    <div class="content-card">
        <div class="card-header-title">
            <i class="fa-solid fa-clock-rotate-left text-primary fs-5"></i>
            <span>Weekly Submission Deadlines & Status</span>
        </div>

        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Week Number</th>
                        <th>Deadline Date & Time</th>
                        <th>Deadline Status</th>
                        <th>Submission Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($deadline_result && mysqli_num_rows($deadline_result) > 0): ?>
                        <?php while ($deadline = mysqli_fetch_assoc($deadline_result)): 
                            $week = (int)$deadline['week_no'];

                            $check_submission = mysqli_query($conn, "
                                SELECT *
                                FROM weekly_updates
                                WHERE internship_id='$internship_id'
                                AND week_no='$week'
                            ");

                            $submitted = mysqli_num_rows($check_submission);
                            $deadline_timestamp = strtotime($deadline['deadline_date']);
                            $is_deadline_passed = (strtotime(date("Y-m-d H:i:s")) > $deadline_timestamp);
                        ?>
                            <tr>
                                <td class="fw-bold text-dark">
                                    <i class="fa-solid fa-calendar-week text-primary me-2"></i> Week <?php echo $week; ?>
                                </td>
                                <td>
                                    <i class="fa-regular fa-clock text-muted me-1"></i> <?php echo date("d M Y, h:i A", $deadline_timestamp); ?>
                                </td>
                                <td>
                                    <?php if ($is_deadline_passed): ?>
                                        <span class="badge-status bg-danger-subtle text-danger border border-danger-subtle">
                                            <i class="fa-solid fa-circle-xmark"></i> Deadline Passed
                                        </span>
                                    <?php else: ?>
                                        <span class="badge-status bg-success-subtle text-success border border-success-subtle">
                                            <i class="fa-solid fa-circle-check"></i> Active
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($submitted > 0): 
                                        $submission = mysqli_fetch_assoc($check_submission);
                                        $submitted_timestamp = strtotime($submission['submitted_at']);
                                        $is_on_time = ($submitted_timestamp <= $deadline_timestamp);
                                    ?>
                                        <?php if ($is_on_time): ?>
                                            <span class="badge-status bg-success-subtle text-success border border-success-subtle">
                                                <i class="fa-solid fa-check-double"></i> Submitted On Time
                                            </span>
                                        <?php else: ?>
                                            <span class="badge-status bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                                                <i class="fa-solid fa-triangle-exclamation"></i> Submitted Late
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge-status bg-secondary-subtle text-secondary border border-secondary-subtle">
                                            <i class="fa-solid fa-minus"></i> Not Submitted
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="text-center py-4 text-muted">
                                <i class="fa-solid fa-info-circle me-1"></i> No submission deadlines added by faculty for your division yet.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 2. SUBMISSION FORM CARD -->
    <div class="content-card">
        <div class="card-header-title">
            <i class="fa-solid fa-paper-plane text-primary fs-5"></i>
            <span>Submit Weekly Progress Report</span>
        </div>

        <form method="POST" enctype="multipart/form-data" id="updateForm">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Internship Company</label>
                    <?php if ($internship): ?>
                        <input type="text" class="form-control bg-light fw-bold text-dark" value="<?php echo h($internship['company_name']); ?>" readonly>
                        <input type="hidden" name="internship_id" value="<?php echo (int)$internship['id']; ?>">
                    <?php else: ?>
                        <input type="text" class="form-control bg-light text-muted" value="No internship approved yet" readonly>
                    <?php endif; ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Week Number <span class="text-danger">*</span></label>
                    <select name="week_no" class="form-select" required <?php if (!$internship) echo "disabled"; ?>>
                        <option value="">Select Week Number...</option>
                        <?php
                        for ($i = 1; $i <= 4; $i++) {
                            $checkWeek = mysqli_query($conn, "
                                SELECT id
                                FROM weekly_updates
                                WHERE internship_id='$internship_id'
                                AND week_no='$i'
                            ");

                            if (mysqli_num_rows($checkWeek) === 0) {
                                echo '<option value="' . $i . '">Week ' . $i . '</option>';
                            }
                        }
                        ?>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label">Work Done Summary (2-3 lines) <span class="text-danger">*</span></label>
                    <textarea name="work_done" class="form-control" rows="4" placeholder="Describe your key tasks, accomplishments, technologies worked on, and deliverables for this week..." required <?php if (!$internship) echo "disabled"; ?>></textarea>
                </div>

                <div class="col-12">
                    <label class="form-label">Upload Weekly Report PDF <span class="text-danger">*</span></label>
                    <div class="upload-dropzone" onclick="document.getElementById('fileInput').click();">
                        <i class="fa-solid fa-file-pdf text-danger display-6 mb-2"></i>
                        <h6 class="fw-bold text-dark mb-1">Click to select PDF Weekly Report</h6>
                        <p class="text-muted small mb-0"><i class="fa-solid fa-info-circle me-1"></i> PDF format only (.pdf), 1-2 pages, Max file size: <strong>500 KB</strong></p>
                        <span id="selectedFileName" class="badge bg-primary mt-2 d-inline-block p-2 px-3 fw-bold" style="display:none !important;"></span>
                    </div>
                    <input type="file" id="fileInput" name="update_file" accept=".pdf,application/pdf" style="display:none;" required <?php if (!$internship) echo "disabled"; ?> onchange="updateFileName(this)">
                </div>

                <?php if (!$internship): ?>
                    <div class="col-12">
                        <div class="alert alert-danger mb-0">
                            <i class="fa-solid fa-triangle-exclamation me-2"></i> You cannot submit weekly updates until your internship is approved and the offer letter is uploaded.
                        </div>
                    </div>
                <?php endif; ?>

                <div class="col-12 mt-4">
                    <button type="submit" name="submit" id="submitBtn" class="btn btn-submit-update w-100" <?php if (!$internship) echo "disabled"; ?>>
                        <i class="fa-solid fa-paper-plane me-2"></i> Submit Weekly Update
                    </button>
                </div>
            </div>
        </form>
    </div>

</div>

</main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
function updateFileName(input) {
    const fileNameBadge = document.getElementById('selectedFileName');
    if (input.files && input.files[0]) {
        const file = input.files[0];
        if (file.size > 500 * 1024) {
            alert('Warning: Selected file size (' + (file.size / 1024).toFixed(1) + ' KB) exceeds the 500 KB limit.');
        }
        fileNameBadge.innerText = 'Selected: ' + file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
        fileNameBadge.style.setProperty('display', 'inline-block', 'important');
    } else {
        fileNameBadge.style.setProperty('display', 'none', 'important');
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('updateForm');
    if (form) {
        form.addEventListener('submit', function () {
            const btn = document.getElementById('submitBtn');
            if (btn && !btn.disabled) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i> Submitting Weekly Update...';
                setTimeout(() => {
                    if (btn) btn.disabled = false;
                }, 8000);
            }
        });
    }
});
</script>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>
</body>
</html>