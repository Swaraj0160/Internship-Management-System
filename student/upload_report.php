<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/../config.php";

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'student')
{
    header("Location: ../login.php");
    exit();
}

$student_id = (int)$_SESSION['user_id'];

/* ------------------------------------------------------------------
   Flash messages (used by POST actions so page refreshes never re-submit)
------------------------------------------------------------------- */
$flashSuccess = isset($_SESSION['cert_flash_success']) ? $_SESSION['cert_flash_success'] : null;
$flashError   = isset($_SESSION['cert_flash_error'])   ? $_SESSION['cert_flash_error']   : null;
unset($_SESSION['cert_flash_success'], $_SESSION['cert_flash_error']);

// Get student division and enrollment info
$getStudent = mysqli_query(
    $conn,
    "SELECT division, full_name, enrollment_no
     FROM users
     WHERE id='$student_id'"
);

$studentData = mysqli_fetch_assoc($getStudent);
$division = $studentData['division'] ?? 'GENERAL';

// Get student's approved internship
$query = "SELECT * FROM internships
          WHERE student_id='$student_id'
          AND status='Approved'
          LIMIT 1";

$result = mysqli_query($conn, $query);
$approvedInternship = mysqli_fetch_assoc($result);

/* ------------------------------------------------------------------
   FIRST-TIME CERTIFICATE UPLOAD HANDLER
------------------------------------------------------------------- */
if(isset($_POST['submit']))
{
    if(!$approvedInternship)
    {
        $_SESSION['cert_flash_error'] = "No approved internship application found.";
        header("Location: upload_report.php");
        exit();
    }

    $internship_id = $approvedInternship['id'];

    $check = mysqli_query(
        $conn,
        "SELECT id FROM final_reports
         WHERE internship_id='$internship_id'"
    );

    if(mysqli_num_rows($check) > 0)
    {
        $_SESSION['cert_flash_error'] = "Certificate has already been submitted.";
        header("Location: upload_report.php");
        exit();
    }

    if(!isset($_FILES['certificate_file']) || $_FILES['certificate_file']['error'] !== UPLOAD_ERR_OK)
    {
        $_SESSION['cert_flash_error'] = "Please select a valid PDF certificate file.";
        header("Location: upload_report.php");
        exit();
    }

    $file_size = $_FILES['certificate_file']['size'];
    $file_extension = strtolower(pathinfo($_FILES['certificate_file']['name'], PATHINFO_EXTENSION));

    if($file_size > 512000)
    {
        $_SESSION['cert_flash_error'] = "File size must be less than or equal to 500 KB.";
        header("Location: upload_report.php");
        exit();
    }

    if($file_extension !== 'pdf')
    {
        $_SESSION['cert_flash_error'] = "Only PDF files are accepted.";
        header("Location: upload_report.php");
        exit();
    }

    $student_name = preg_replace('/[^A-Za-z0-9]/', '_', $studentData['full_name']);
    $enrollment_no = !empty($studentData['enrollment_no']) ? $studentData['enrollment_no'] : 'STUDENT_'.$student_id;

    $certificate_file = $enrollment_no . "_" . $student_name . "_Certificate.pdf";
    $upload_folder = "../uploads/certificates/" . $division . "/";

    if(!file_exists($upload_folder))
    {
        mkdir($upload_folder, 0777, true);
    }

    if(move_uploaded_file($_FILES['certificate_file']['tmp_name'], $upload_folder . $certificate_file))
    {
        mysqli_query(
            $conn,
            "INSERT INTO final_reports (internship_id, certificate_file)
             VALUES ('$internship_id', '$certificate_file')"
        );

        $_SESSION['cert_flash_success'] = "Certificate Uploaded Successfully!";
    }
    else
    {
        $_SESSION['cert_flash_error'] = "File Upload Failed. Please try again.";
    }

    header("Location: upload_report.php");
    exit();
}

/* ------------------------------------------------------------------
   REPLACE CERTIFICATE HANDLER
------------------------------------------------------------------- */
if(isset($_POST['replace_certificate']))
{
    $posted_internship_id = isset($_POST['internship_id']) ? (int)$_POST['internship_id'] : 0;

    $stmt = mysqli_prepare(
        $conn,
        "SELECT final_reports.id AS final_report_id,
                final_reports.internship_id,
                final_reports.certificate_file
         FROM final_reports
         JOIN internships ON final_reports.internship_id = internships.id
         WHERE final_reports.internship_id = ?
           AND internships.student_id = ?
           AND internships.status = 'Approved'
         LIMIT 1"
    );

    $ownedRecord = null;
    if($stmt)
    {
        mysqli_stmt_bind_param($stmt, "ii", $posted_internship_id, $student_id);
        mysqli_stmt_execute($stmt);
        $ownedResult = mysqli_stmt_get_result($stmt);
        $ownedRecord = $ownedResult ? mysqli_fetch_assoc($ownedResult) : null;
        mysqli_stmt_close($stmt);
    }

    if(!$ownedRecord)
    {
        $_SESSION['cert_flash_error'] = "Could not verify this certificate submission. Please try again.";
    }
    elseif(!isset($_FILES['replacement_certificate_file']) || $_FILES['replacement_certificate_file']['error'] !== UPLOAD_ERR_OK)
    {
        $_SESSION['cert_flash_error'] = "Please choose a valid PDF file to upload.";
    }
    else
    {
        $newFile = $_FILES['replacement_certificate_file'];

        if($newFile['size'] <= 0)
        {
            $_SESSION['cert_flash_error'] = "The selected file appears to be empty.";
        }
        elseif($newFile['size'] > 512000)
        {
            $_SESSION['cert_flash_error'] = "File size must be less than or equal to 500 KB.";
        }
        else
        {
            $ext = strtolower(pathinfo($newFile['name'], PATHINFO_EXTENSION));

            $mimeType = '';
            if(function_exists('finfo_open'))
            {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if($finfo)
                {
                    $mimeType = finfo_file($finfo, $newFile['tmp_name']);
                    finfo_close($finfo);
                }
            }

            $isPdfExtension = ($ext === 'pdf');
            $isPdfMime = ($mimeType === '') ? true : ($mimeType === 'application/pdf');

            if(!$isPdfExtension || !$isPdfMime)
            {
                $_SESSION['cert_flash_error'] = "Only PDF files are allowed.";
            }
            else
            {
                $student_name = preg_replace('/[^A-Za-z0-9]/', '_', $studentData['full_name']);
                $enrollment_no = !empty($studentData['enrollment_no']) ? $studentData['enrollment_no'] : 'STUDENT_'.$student_id;

                $final_filename = $enrollment_no . "_" . $student_name . "_Certificate.pdf";
                $upload_folder = "../uploads/certificates/" . $division . "/";

                if(!file_exists($upload_folder))
                {
                    mkdir($upload_folder, 0777, true);
                }

                $final_path = $upload_folder . $final_filename;
                $temp_filename = $final_filename . ".replace_" . uniqid('', true) . ".tmp";
                $temp_path = $upload_folder . $temp_filename;

                if(!move_uploaded_file($newFile['tmp_name'], $temp_path))
                {
                    $_SESSION['cert_flash_error'] = "File upload failed. Please try again.";
                }
                else
                {
                    $old_filename = $ownedRecord['certificate_file'];
                    $old_path = $upload_folder . $old_filename;
                    $sameFilename = ($old_filename === $final_filename);

                    if(!@rename($temp_path, $final_path))
                    {
                        @unlink($temp_path);
                        $_SESSION['cert_flash_error'] = "Could not finalize the replacement. Please try again.";
                    }
                    else
                    {
                        if(!$sameFilename && file_exists($old_path))
                        {
                            @unlink($old_path);
                        }

                        $updateStmt = mysqli_prepare(
                            $conn,
                            "UPDATE final_reports
                             SET certificate_file = ?
                             WHERE id = ?"
                        );

                        $dbOk = false;
                        if($updateStmt)
                        {
                            mysqli_stmt_bind_param(
                                $updateStmt,
                                "si",
                                $final_filename,
                                $ownedRecord['final_report_id']
                            );
                            $dbOk = mysqli_stmt_execute($updateStmt);
                            mysqli_stmt_close($updateStmt);
                        }

                        if($dbOk)
                        {
                            $_SESSION['cert_flash_success'] = "Certificate Replaced Successfully!";
                        }
                        else
                        {
                            $_SESSION['cert_flash_error'] = "Your file was uploaded but DB confirmation failed. Please refresh the page.";
                        }
                    }
                }
            }
        }
    }

    header("Location: upload_report.php");
    exit();
}

// Query current submitted report record (if any)
$alreadySubmitted = null;
$submittedData = null;

if($approvedInternship)
{
    $internship_id = $approvedInternship['id'];
    $alreadySubmitted = mysqli_query(
        $conn,
        "SELECT final_reports.*
         FROM final_reports
         WHERE internship_id='$internship_id'
         LIMIT 1"
    );

    if(mysqli_num_rows($alreadySubmitted) > 0)
    {
        $submittedData = mysqli_fetch_assoc($alreadySubmitted);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Final Internship Certificate — MIT-ADT Internship Portal</title>

    <!-- Google Fonts & CSS Libraries -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Bootstrap 5.3.3 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">

    <?php include __DIR__ . '/includes/sidebar_head.php'; ?>

    <style>
        :root {
            --brand-purple: #4f46e5;
            --brand-purple-dark: #4338ca;
            --brand-purple-light: #eeeffe;
            --brand-purple-subtle: #f5f3ff;
            --bg-canvas: #f8fafc;
            --card-border: #e2e8f0;
            --text-heading: #0f172a;
            --text-body: #475569;
            --text-muted: #64748b;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background-color: var(--bg-canvas);
            color: var(--text-body);
        }

        .page-inner {
            padding: 30px;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Top Hero Header Box */
        .page-header-box {
            background: #ffffff;
            border-radius: 16px;
            padding: 26px 32px;
            border: 1px solid var(--card-border);
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.03);
            margin-bottom: 28px;
        }

        .page-eyebrow {
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--brand-purple);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 4px;
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--text-heading);
            margin: 0;
            letter-spacing: -0.02em;
        }

        .page-subtitle {
            font-size: 0.95rem;
            color: var(--text-muted);
            margin-top: 4px;
            margin-bottom: 0;
        }

        /* Content Card Styling */
        .content-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid var(--card-border);
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.04);
            padding: 30px;
            height: 100%;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .card-header-title {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 22px;
            padding-bottom: 16px;
            border-bottom: 1px solid #f1f5f9;
        }

        .card-header-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: var(--brand-purple-subtle);
            color: var(--brand-purple);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .card-header-title h2 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-heading);
            margin: 0;
        }

        .card-header-title p {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin: 2px 0 0 0;
        }

        /* Upload Dropzone Area */
        .upload-zone {
            border: 2px dashed #c7d2fe;
            border-radius: 16px;
            padding: 40px 24px;
            text-align: center;
            background: #f8fafc;
            position: relative;
            transition: all 0.2s ease;
            cursor: pointer;
            margin-bottom: 24px;
        }

        .upload-zone:hover,
        .upload-zone.dragover {
            border-color: var(--brand-purple);
            background: var(--brand-purple-subtle);
            transform: translateY(-2px);
        }

        .upload-icon-circle {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: var(--brand-purple-light);
            color: var(--brand-purple);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 1.75rem;
            transition: transform 0.2s ease;
        }

        .upload-zone:hover .upload-icon-circle {
            transform: scale(1.08);
        }

        .upload-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--text-heading);
            margin-bottom: 6px;
        }

        .upload-hint {
            font-size: 0.88rem;
            color: var(--text-muted);
            margin-bottom: 14px;
        }

        .upload-zone input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }

        .selected-file-box {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            padding: 14px 18px;
            margin-top: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            animation: fadeIn 0.25s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .file-info-text {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            color: var(--brand-purple-dark);
            font-size: 0.92rem;
            word-break: break-all;
        }

        /* Buttons */
        .btn-brand-primary {
            background: linear-gradient(135deg, var(--brand-purple) 0%, var(--brand-purple-dark) 100%);
            color: #ffffff;
            font-weight: 700;
            padding: 14px 28px;
            border-radius: 12px;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            font-size: 1rem;
            box-shadow: 0 8px 20px rgba(79, 70, 229, 0.25);
            transition: all 0.2s ease;
        }

        .btn-brand-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 24px rgba(79, 70, 229, 0.35);
            color: #ffffff;
        }

        .btn-brand-primary:disabled {
            opacity: 0.7;
            transform: none;
            box-shadow: none;
            cursor: not-allowed;
        }

        .btn-outline-custom {
            border: 1.5px solid var(--card-border);
            background: #ffffff;
            color: var(--text-heading);
            font-weight: 600;
            padding: 12px 20px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s ease;
        }

        .btn-outline-custom:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            transform: translateY(-1px);
            color: var(--brand-purple);
        }

        /* Success Certificate Display Card */
        .success-banner-box {
            text-align: center;
            padding: 10px 0 24px;
        }

        .success-badge-icon {
            width: 76px;
            height: 76px;
            border-radius: 50%;
            background: #f0fdf4;
            color: #16a34a;
            border: 2px solid #bbf7d0;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 2.2rem;
            box-shadow: 0 6px 16px rgba(22, 163, 74, 0.12);
        }

        .cert-details-grid {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 24px;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px 24px;
            margin-bottom: 24px;
            text-align: left;
        }

        .detail-meta-item .meta-label {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 4px;
        }

        .detail-meta-item .meta-value {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--text-heading);
            word-break: break-all;
        }

        /* Guidelines sidebar box */
        .info-card {
            background: linear-gradient(180deg, #f5f3ff 0%, #faf5ff 100%);
            border: 1px solid #e0e7ff;
            border-radius: 16px;
            padding: 26px;
        }

        .info-card h3 {
            color: var(--brand-purple-dark);
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .guideline-list {
            list-style: none;
            padding: 0;
            margin: 0 0 20px 0;
        }

        .guideline-list li {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-size: 0.92rem;
            color: #334155;
            margin-bottom: 12px;
            line-height: 1.5;
        }

        .guideline-list li i {
            color: #16a34a;
            margin-top: 3px;
            flex-shrink: 0;
        }

        .support-box {
            background: #ffffff;
            border-radius: 12px;
            padding: 16px;
            border: 1px solid #e0e7ff;
            font-size: 0.88rem;
            color: var(--text-muted);
        }

        .support-box h4 {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--brand-purple);
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Modal styling */
        .modal {
            z-index: 1060 !important;
        }

        .modal-backdrop {
            z-index: 1050 !important;
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            background-color: rgba(15, 23, 42, 0.65);
        }

        .modal-content-custom {
            border: none;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 25px 50px rgba(15, 23, 42, 0.25);
        }

        .modal-header-custom {
            background: linear-gradient(90deg, #f5f3ff 0%, #eef2ff 100%);
            border-bottom: 1px solid var(--card-border);
            padding: 20px 26px;
        }

        .modal-header-custom .modal-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--text-heading);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-body-custom {
            padding: 26px;
        }

        .modal-footer-custom {
            border-top: 1px solid var(--card-border);
            padding: 18px 26px;
            background: #f8fafc;
            display: flex;
            gap: 12px;
        }

        /* Toast Container */
        .toast-container-custom {
            position: fixed;
            top: 24px;
            right: 24px;
            z-index: 2000;
            display: flex;
            flex-direction: column;
            gap: 12px;
            pointer-events: none;
        }

        .toast-custom {
            pointer-events: auto;
            min-width: 320px;
            background: #ffffff;
            border-radius: 14px;
            box-shadow: 0 16px 36px rgba(15, 23, 42, 0.15);
            border: 1px solid #e2e8f0;
            border-left: 4px solid #16a34a;
            padding: 16px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            opacity: 0;
            transform: translateX(30px);
            transition: all 0.25s ease;
        }

        .toast-custom.toast-show {
            opacity: 1;
            transform: translateX(0);
        }

        @media (max-width: 991px) {
            .cert-details-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .page-inner {
                padding: 16px;
            }
            .page-header-box {
                padding: 20px;
            }
            .content-card {
                padding: 20px;
            }
            .action-btn-group {
                flex-direction: column;
            }
            .action-btn-group a,
            .action-btn-group button {
                width: 100%;
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
            <div class="page-eyebrow"><i class="fa-solid fa-award me-1"></i> Internship Completion</div>
            <h1 class="page-title">Final Internship Certificate</h1>
            <p class="page-subtitle">Upload and manage your official internship completion certificate issued by your host organization.</p>
        </div>
        <div>
            <a href="my_internships.php" class="btn btn-light fw-bold px-4 py-2 shadow-sm text-primary rounded-3 border">
                <i class="fa-solid fa-briefcase me-2"></i> My Internships
            </a>
        </div>
    </div>

    <!-- Alert Notifications -->
    <?php if ($flashSuccess): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3 shadow-sm mb-4 border-0 bg-success-subtle text-success-emphasis" role="alert">
            <i class="fa-solid fa-circle-check me-2 fs-5"></i> <strong>Success!</strong> <?php echo htmlspecialchars($flashSuccess); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($flashError): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3 shadow-sm mb-4 border-0 bg-danger-subtle text-danger-emphasis" role="alert">
            <i class="fa-solid fa-triangle-exclamation me-2 fs-5"></i> <strong>Notice:</strong> <?php echo htmlspecialchars($flashError); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        
        <!-- Left Main Content Area -->
        <div class="col-lg-8">
            <div class="content-card">

                <?php if (!$approvedInternship): ?>
                    
                    <!-- NO APPROVED INTERNSHIP STATE -->
                    <div class="text-center py-5">
                        <div class="mb-3 text-warning">
                            <i class="fa-solid fa-file-circle-exclamation display-3"></i>
                        </div>
                        <h3 class="fw-bold text-dark mb-2">No Approved Internship Found</h3>
                        <p class="text-muted mx-auto" style="max-width: 480px;">
                            You can only upload your internship completion certificate after your internship application has been officially approved by the faculty coordinator.
                        </p>
                        <a href="my_internships.php" class="btn btn-primary fw-bold px-4 py-2 rounded-3 mt-3 shadow-sm">
                            <i class="fa-solid fa-arrow-left me-2"></i> Check My Internship Applications
                        </a>
                    </div>

                <?php elseif ($submittedData): ?>

                    <!-- CERTIFICATE ALREADY SUBMITTED (VERIFIED STATE) -->
                    <div class="card-header-title">
                        <div class="card-header-icon">
                            <i class="fa-solid fa-award"></i>
                        </div>
                        <div>
                            <h2>Submitted Internship Certificate</h2>
                            <p>Your official completion certificate is securely recorded and attached to your student profile.</p>
                        </div>
                    </div>

                    <?php
                        $certPath = "../uploads/certificates/" . $division . "/" . $submittedData['certificate_file'];
                        $certWebPath = "../uploads/certificates/" . $division . "/" . $submittedData['certificate_file'];

                        $certSizeLabel = "—";
                        $certDateLabel = "—";
                        $certVersion = time();

                        if (file_exists($certPath)) {
                            $bytes = filesize($certPath);
                            $certSizeLabel = $bytes >= 1024 ? round($bytes / 1024, 1) . " KB" : $bytes . " B";
                            $certDateLabel = date("d M Y, h:i A", filemtime($certPath));
                            $certVersion = filemtime($certPath);
                        }
                    ?>

                    <div class="success-banner-box">
                        <div class="success-badge-icon">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>
                        <h3 class="fw-bold text-dark mb-1">Certificate Successfully Submitted</h3>
                        <p class="text-muted fs-6">Verified completion record for <?php echo htmlspecialchars($approvedInternship['company_name']); ?></p>

                        <div class="cert-details-grid my-4" id="certSummaryBlock">
                            <div class="detail-meta-item">
                                <div class="meta-label">File Name</div>
                                <div class="meta-value" id="certFileNameValue"><?php echo htmlspecialchars($submittedData['certificate_file']); ?></div>
                            </div>
                            <div class="detail-meta-item">
                                <div class="meta-label">File Size</div>
                                <div class="meta-value" id="certFileSizeValue"><?php echo $certSizeLabel; ?></div>
                            </div>
                            <div class="detail-meta-item">
                                <div class="meta-label">Uploaded On</div>
                                <div class="meta-value" id="certUploadedOnValue"><?php echo $certDateLabel; ?></div>
                            </div>
                            <div class="detail-meta-item">
                                <div class="meta-label">Organization</div>
                                <div class="meta-value"><?php echo htmlspecialchars($approvedInternship['company_name']); ?></div>
                            </div>
                            <div class="detail-meta-item">
                                <div class="meta-label">Division / Class</div>
                                <div class="meta-value"><?php echo htmlspecialchars($division); ?></div>
                            </div>
                            <div class="detail-meta-item">
                                <div class="meta-label">Verification Status</div>
                                <div class="meta-value">
                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 rounded-pill fw-bold">
                                        <i class="fa-solid fa-shield-check me-1"></i> Submitted & Verified
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-3 flex-wrap action-btn-group">
                            <a href="<?php echo htmlspecialchars($certWebPath); ?>?v=<?php echo (int)$certVersion; ?>" 
                               target="_blank" 
                               class="btn btn-primary fw-bold flex-fill py-3 rounded-3 shadow-sm d-flex align-items-center justify-content-center gap-2"
                               id="certViewLink">
                                <i class="fa-solid fa-eye fs-5"></i> View Certificate PDF
                            </a>
                            <button type="button" 
                                    class="btn btn-outline-secondary fw-bold flex-fill py-3 rounded-3 d-flex align-items-center justify-content-center gap-2" 
                                    id="replaceToggleBtn" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#replaceCertModal">
                                <i class="fa-solid fa-arrows-rotate fs-5"></i> Replace Certificate
                            </button>
                        </div>
                    </div>

                <?php else: ?>

                    <!-- FIRST-TIME UPLOAD FORM -->
                    <div class="card-header-title">
                        <div class="card-header-icon">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                        </div>
                        <div>
                            <h2>Upload Certificate</h2>
                            <p>Official completion certificate for <strong><?php echo htmlspecialchars($approvedInternship['company_name']); ?></strong></p>
                        </div>
                    </div>

                    <form method="POST" enctype="multipart/form-data" id="uploadForm">
                        <div class="mb-4">
                            <label class="form-label fw-bold text-dark mb-2">Completion Certificate File (PDF)</label>
                            
                            <div class="upload-zone" id="uploadZone">
                                <div class="upload-icon-circle">
                                    <i class="fa-solid fa-file-pdf"></i>
                                </div>
                                <div class="upload-title" id="uploadTitle">Drag & Drop your certificate here</div>
                                <div class="upload-hint">or click to browse files from your device</div>
                                
                                <div class="d-flex justify-content-center gap-2 flex-wrap mt-2">
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 rounded-pill">
                                        <i class="fa-solid fa-file-check me-1"></i> PDF Format Only
                                    </span>
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-3 py-2 rounded-pill">
                                        <i class="fa-solid fa-weight-hanging me-1"></i> Max Size: 500 KB
                                    </span>
                                </div>

                                <input type="file" 
                                       id="certificate_file" 
                                       name="certificate_file" 
                                       accept=".pdf" 
                                       required>

                                <div id="selectedFileBox" class="selected-file-box" style="display: none;">
                                    <div class="file-info-text">
                                        <i class="fa-solid fa-circle-check text-success fs-5"></i>
                                        <span id="selectedFileName">filename.pdf</span>
                                        <span class="badge bg-light text-dark border ms-1" id="selectedFileSize">0 KB</span>
                                    </div>
                                    <button type="button" class="btn-close btn-sm" id="removeFileBtn" aria-label="Remove"></button>
                                </div>
                            </div>
                        </div>

                        <button type="submit" name="submit" class="btn-brand-primary" id="submitBtn">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <span id="submitBtnText">Upload Certificate</span>
                        </button>
                    </form>

                <?php endif; ?>

            </div>
        </div>

        <!-- Right Column (Guidelines & Help) -->
        <div class="col-lg-4">
            <div class="info-card">
                <h3>
                    <i class="fa-solid fa-shield-halved text-primary"></i>
                    Certificate Requirements
                </h3>

                <ul class="guideline-list">
                    <li>
                        <i class="fa-solid fa-circle-check"></i>
                        <span>Must clearly state your <strong>Full Student Name</strong> matching university records.</span>
                    </li>
                    <li>
                        <i class="fa-solid fa-circle-check"></i>
                        <span>Must specify the <strong>Company / Organization Name</strong> where internship was completed.</span>
                    </li>
                    <li>
                        <i class="fa-solid fa-circle-check"></i>
                        <span>Must include start & end dates or total <strong>Internship Duration</strong>.</span>
                    </li>
                    <li>
                        <i class="fa-solid fa-circle-check"></i>
                        <span>File format must be <strong>PDF document only</strong> (`.pdf`).</span>
                    </li>
                    <li>
                        <i class="fa-solid fa-circle-check"></i>
                        <span>Maximum allowed file size is strictly <strong>500 KB</strong>.</span>
                    </li>
                </ul>

                <div class="support-box">
                    <h4>
                        <i class="fa-solid fa-headset"></i>
                        Need Assistance?
                    </h4>
                    <p class="mb-0">
                        If you encounter issues uploading or replacing your completion certificate, please reach out to your assigned faculty coordinator.
                    </p>
                </div>
            </div>
        </div>

    </div>

</div>

<!-- TOP-LEVEL REPLACE CERTIFICATE MODAL (Direct child of <body> to prevent stacking traps) -->
<?php if ($submittedData): ?>
<div class="modal fade" id="replaceCertModal" tabindex="-1" aria-labelledby="replaceCertModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-content-custom">

            <form method="POST" enctype="multipart/form-data" id="replaceForm">
                <input type="hidden" name="internship_id" id="replaceInternshipId" value="<?php echo htmlspecialchars($submittedData['internship_id']); ?>">

                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title" id="replaceCertModalLabel">
                        <i class="fa-solid fa-arrows-rotate text-primary"></i> Replace Certificate
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" id="replaceModalCloseBtn"></button>
                </div>

                <div class="modal-body modal-body-custom">

                    <div class="alert alert-danger alert-dismissible fade show rounded-3 mb-3" id="replaceModalError" style="display:none;">
                        <i class="fa-solid fa-circle-exclamation me-2"></i>
                        <span id="replaceModalErrorText"></span>
                    </div>

                    <!-- Current Certificate Details Panel -->
                    <div class="bg-light border rounded-3 p-3 mb-3">
                        <div class="text-uppercase fw-bold text-muted" style="font-size: 0.72rem; letter-spacing: 0.5px;">Currently Submitted Certificate</div>
                        <div class="d-flex justify-content-between align-items-center mt-2 flex-wrap gap-2">
                            <div>
                                <div class="fw-bold text-dark" id="replaceCurrentName" style="font-size: 0.9rem; word-break: break-all;"><?php echo htmlspecialchars($submittedData['certificate_file']); ?></div>
                                <div class="text-muted small">Uploaded: <?php echo $certDateLabel; ?></div>
                            </div>
                            <span class="badge bg-secondary-subtle text-secondary border px-2 py-1"><?php echo $certSizeLabel; ?></span>
                        </div>
                    </div>

                    <p class="text-muted small mb-3">Select a new PDF file to replace your existing certificate record.</p>

                    <div class="upload-zone py-4" id="replaceUploadZone">
                        <div class="upload-icon-circle" style="width: 48px; height: 48px; font-size: 1.25rem; margin-bottom: 10px;">
                            <i class="fa-solid fa-file-pdf"></i>
                        </div>
                        <div class="upload-title fs-6" id="replaceUploadTitle">Choose Replacement Certificate</div>
                        <div class="upload-hint small">PDF only &middot; Maximum 500 KB</div>

                        <input type="file"
                               id="replacement_certificate_file"
                               name="replacement_certificate_file"
                               accept=".pdf"
                               required>

                        <div id="replaceSelectedFileBox" class="selected-file-box" style="display:none;">
                            <div class="file-info-text">
                                <i class="fa-solid fa-circle-check text-success fs-5"></i>
                                <span id="replaceSelectedFileName">filename.pdf</span>
                                <span class="badge bg-light text-dark border ms-1" id="replaceSelectedFileSize">0 KB</span>
                            </div>
                            <button type="button" class="btn-close btn-sm" id="replaceRemoveFileBtn" aria-label="Remove"></button>
                        </div>
                    </div>

                </div>

                <div class="modal-footer modal-footer-custom">
                    <button type="button" class="btn btn-light fw-bold flex-fill py-2 border" id="replaceModalCancelBtn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="replace_certificate" class="btn btn-primary fw-bold flex-fill py-2" id="replaceConfirmBtn">
                        <span id="replaceConfirmBtnContent">
                            <i class="fa-solid fa-arrows-rotate me-1"></i> Replace Certificate
                        </span>
                    </button>
                </div>

            </form>

        </div>
    </div>
</div>
<?php endif; ?>

<!-- Custom Toast Notification Container -->
<div class="toast-container-custom" id="toastContainer"></div>

<!-- JavaScript Logic -->
<script>
document.addEventListener("DOMContentLoaded", function() {

    // 1. FIRST-TIME UPLOAD VALIDATION & DROPZONE LOGIC
    const fileInput = document.getElementById("certificate_file");
    const selectedFileBox = document.getElementById("selectedFileBox");
    const selectedFileName = document.getElementById("selectedFileName");
    const selectedFileSize = document.getElementById("selectedFileSize");
    const removeFileBtn = document.getElementById("removeFileBtn");
    const uploadZone = document.getElementById("uploadZone");
    const uploadForm = document.getElementById("uploadForm");
    const submitBtn = document.getElementById("submitBtn");
    const submitBtnText = document.getElementById("submitBtnText");

    function formatBytes(bytes) {
        if (bytes >= 1024) return (bytes / 1024).toFixed(1) + " KB";
        return bytes + " B";
    }

    if (fileInput) {
        fileInput.addEventListener("change", function() {
            if (this.files && this.files.length > 0) {
                const file = this.files[0];
                const ext = file.name.split('.').pop().toLowerCase();

                if (ext !== 'pdf') {
                    alert("Only PDF files are allowed.");
                    this.value = "";
                    if (selectedFileBox) selectedFileBox.style.display = "none";
                    return;
                }

                if (file.size > 512000) {
                    alert("File size must be less than or equal to 500 KB.");
                    this.value = "";
                    if (selectedFileBox) selectedFileBox.style.display = "none";
                    return;
                }

                if (selectedFileName) selectedFileName.textContent = file.name;
                if (selectedFileSize) selectedFileSize.textContent = formatBytes(file.size);
                if (selectedFileBox) selectedFileBox.style.display = "flex";
            }
        });

        if (removeFileBtn) {
            removeFileBtn.addEventListener("click", function(e) {
                e.stopPropagation();
                fileInput.value = "";
                if (selectedFileBox) selectedFileBox.style.display = "none";
            });
        }
    }

    if (uploadZone) {
        uploadZone.addEventListener("dragover", function(e) {
            e.preventDefault();
            uploadZone.classList.add("dragover");
        });

        uploadZone.addEventListener("dragleave", function() {
            uploadZone.classList.remove("dragover");
        });

        uploadZone.addEventListener("drop", function(e) {
            uploadZone.classList.remove("dragover");
        });
    }

    if (uploadForm) {
        uploadForm.addEventListener("submit", function() {
            if (submitBtn) {
                submitBtn.disabled = true;
                if (submitBtnText) {
                    submitBtnText.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Uploading Certificate...';
                }
            }
        });
    }

    // 2. REPLACE CERTIFICATE MODAL LOGIC
    const replaceModalEl = document.getElementById("replaceCertModal");
    if (replaceModalEl) {
        const replaceForm = document.getElementById("replaceForm");
        const replaceFileInput = document.getElementById("replacement_certificate_file");
        const replaceSelectedFileBox = document.getElementById("replaceSelectedFileBox");
        const replaceSelectedFileName = document.getElementById("replaceSelectedFileName");
        const replaceSelectedFileSize = document.getElementById("replaceSelectedFileSize");
        const replaceRemoveFileBtn = document.getElementById("replaceRemoveFileBtn");
        const replaceUploadZone = document.getElementById("replaceUploadZone");
        const replaceConfirmBtn = document.getElementById("replaceConfirmBtn");
        const replaceConfirmBtnContent = document.getElementById("replaceConfirmBtnContent");
        const replaceModalCancelBtn = document.getElementById("replaceModalCancelBtn");
        const replaceModalError = document.getElementById("replaceModalError");
        const replaceModalErrorText = document.getElementById("replaceModalErrorText");

        const replaceModal = bootstrap.Modal.getOrCreateInstance(replaceModalEl, {
            backdrop: true,
            keyboard: true
        });

        function resetReplaceInput() {
            if (replaceFileInput) replaceFileInput.value = "";
            if (replaceSelectedFileBox) replaceSelectedFileBox.style.display = "none";
            if (replaceModalError) replaceModalError.style.display = "none";
        }

        replaceModalEl.addEventListener("show.bs.modal", function() {
            resetReplaceInput();
            if (replaceConfirmBtn) replaceConfirmBtn.disabled = false;
        });

        replaceModalEl.addEventListener("hidden.bs.modal", function() {
            resetReplaceInput();
            if (replaceConfirmBtn) replaceConfirmBtn.disabled = false;
        });

        if (replaceFileInput) {
            replaceFileInput.addEventListener("change", function() {
                if (this.files && this.files.length > 0) {
                    const file = this.files[0];
                    const ext = file.name.split('.').pop().toLowerCase();

                    if (ext !== 'pdf') {
                        alert("Only PDF files are allowed.");
                        this.value = "";
                        if (replaceSelectedFileBox) replaceSelectedFileBox.style.display = "none";
                        return;
                    }

                    if (file.size > 512000) {
                        alert("File size must be less than or equal to 500 KB.");
                        this.value = "";
                        if (replaceSelectedFileBox) replaceSelectedFileBox.style.display = "none";
                        return;
                    }

                    if (replaceSelectedFileName) replaceSelectedFileName.textContent = file.name;
                    if (replaceSelectedFileSize) replaceSelectedFileSize.textContent = formatBytes(file.size);
                    if (replaceSelectedFileBox) replaceSelectedFileBox.style.display = "flex";
                }
            });

            if (replaceRemoveFileBtn) {
                replaceRemoveFileBtn.addEventListener("click", function(e) {
                    e.stopPropagation();
                    replaceFileInput.value = "";
                    if (replaceSelectedFileBox) replaceSelectedFileBox.style.display = "none";
                });
            }
        }

        if (replaceUploadZone) {
            replaceUploadZone.addEventListener("dragover", function(e) {
                e.preventDefault();
                replaceUploadZone.classList.add("dragover");
            });

            replaceUploadZone.addEventListener("dragleave", function() {
                replaceUploadZone.classList.remove("dragover");
            });

            replaceUploadZone.addEventListener("drop", function() {
                replaceUploadZone.classList.remove("dragover");
            });
        }

        if (replaceForm) {
            replaceForm.addEventListener("submit", function() {
                if (replaceConfirmBtn) {
                    replaceConfirmBtn.disabled = true;
                    if (replaceConfirmBtnContent) {
                        replaceConfirmBtnContent.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Replacing...';
                    }
                }
            });
        }
    }

});
</script>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>

</body>
</html>