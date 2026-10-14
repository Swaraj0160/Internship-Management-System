<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once __DIR__ . "/../config.php";

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'student')
{
    header("Location: ../login.php");
    exit();
}

$student_id = (int)$_SESSION['user_id'];

/* EDIT MODE */
$edit_mode = false;
$edit_data = null;

if(isset($_GET['edit']))
{
    $edit_id = (int)$_GET['edit'];

    $edit_query = mysqli_query(
        $conn,
        "SELECT *
         FROM internships
         WHERE id='$edit_id'
         AND student_id='$student_id'"
    );

    if(mysqli_num_rows($edit_query) > 0)
    {
        $edit_mode = true;
        $edit_data = mysqli_fetch_assoc($edit_query);
    }
}

/*
LOCK ONLY AFTER APPROVED INTERNSHIP
AND OFFER LETTER HAS BEEN UPLOADED
*/
$is_locked_edit = (
    $edit_mode &&
    !empty($edit_data['status']) && $edit_data['status'] == 'Approved' &&
    !empty($edit_data['offer_letter'])
);

/*
CHECK IF STUDENT ALREADY HAS EXISTING INTERNSHIP
*/
$check_query = "SELECT * FROM internships
                WHERE student_id='$student_id'
                ORDER BY id DESC
                LIMIT 1";

$check_result = mysqli_query($conn, $check_query);
$existing_application = mysqli_fetch_assoc($check_result);
$application_exists = ($existing_application) ? true : false;

/*
DISPLAY-ONLY QUERY:
Fetches student's division for "View Approval Letter" links
*/
$displayDivisionQuery = mysqli_query(
    $conn,
    "SELECT division FROM users WHERE id='$student_id'"
);
$displayDivisionRow = mysqli_fetch_assoc($displayDivisionQuery);
$display_division = $displayDivisionRow['division'] ?? '';

/*
FORM SUBMISSION HANDLER
*/
if(isset($_POST['submit']))
{
    /*
    LOCKED EDIT MODE (APPROVED INTERNSHIP)
    ONLY mini_project_title CAN BE UPDATED.
    */
    if($is_locked_edit)
    {
        $locked_edit_id = (int)$edit_data['id'];
        $mini_project_title = mysqli_real_escape_string($conn, $_POST['mini_project_title']);

        $query = "UPDATE internships
                  SET mini_project_title='$mini_project_title'
                  WHERE id='$locked_edit_id'
                  AND student_id='$student_id'
                  AND status='Approved'";

        mysqli_query($conn, $query);

        echo "<script>
                alert('Mini Project Title Updated Successfully');
                window.location.href='my_internships.php';
              </script>";
        exit();
    }

    if($application_exists && !$edit_mode)
    {
        $status = $existing_application['status'];

        if($status == 'Pending')
        {
            echo "<script>
                    alert('Your internship application is under review.');
                    window.location='my_internships.php';
                  </script>";
            exit();
        }

        if($status == 'Rejected')
        {
            echo "<script>
                    alert('Your application was rejected. Please edit and resubmit it from My Applications.');
                    window.location='my_internships.php';
                  </script>";
            exit();
        }

        if($status == 'Approved')
        {
            echo "<script>
                    alert('You already have an approved internship.');
                    window.location='dashboard.php';
                  </script>";
            exit();
        }
    }

    $company_name       = mysqli_real_escape_string($conn, $_POST['company_name']);
    $company_contact    = mysqli_real_escape_string($conn, $_POST['company_contact']);
    $internship_role    = mysqli_real_escape_string($conn, $_POST['internship_role']);
    $mini_project_title = mysqli_real_escape_string($conn, $_POST['mini_project_title']);
    $start_date         = mysqli_real_escape_string($conn, $_POST['start_date']);
    $end_date           = mysqli_real_escape_string($conn, $_POST['end_date']);
    $stipend            = mysqli_real_escape_string($conn, $_POST['stipend']);
    $mode               = mysqli_real_escape_string($conn, $_POST['mode']);
    $mentor_name        = mysqli_real_escape_string($conn, $_POST['mentor_name']);
    $internship_type    = mysqli_real_escape_string($conn, $_POST['internship_type']);

    /* FILE UPLOAD */
    $getStudent = mysqli_query($conn, "SELECT full_name, enrollment_no, division FROM users WHERE id='$student_id'");
    $student = mysqli_fetch_assoc($getStudent);

    $full_name = strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $student['full_name']));
    $enrollment = $student['enrollment_no'];
    $division = $student['division'];

    $file_name = "";

    if(!empty($_FILES['offer_letter']['name']))
    {
        $extension = strtolower(pathinfo($_FILES['offer_letter']['name'], PATHINFO_EXTENSION));
        $allowed_types = ['pdf'];

        if(!in_array($extension, $allowed_types))
        {
            die("Only PDF files are allowed.");
        }

        $max_size = 500 * 1024;
        if($_FILES['offer_letter']['size'] > $max_size)
        {
            echo "<script>
                    alert('Offer Letter size must be less than 500 KB');
                    history.back();
                  </script>";
            exit();
        }

        $upload_dir = "../uploads/offer_letters/" . $division . "/";
        if(!file_exists($upload_dir))
        {
            mkdir($upload_dir, 0777, true);
        }

        $file_name = $enrollment . "_" . $full_name . "." . $extension;

        if(!move_uploaded_file($_FILES['offer_letter']['tmp_name'], $upload_dir . $file_name))
        {
            die("File Upload Failed");
        }
    }
    else
    {
        if($edit_mode)
        {
            $file_name = $edit_data['offer_letter'];
        }
        else
        {
            echo "<script>
                    alert('Please upload Offer Letter');
                    history.back();
                  </script>";
            exit();
        }
    }

    if(isset($_POST['edit_id']))
    {
        $edit_id = (int)$_POST['edit_id'];

        $statusQuery = mysqli_query($conn, "SELECT status, rejection_reason FROM internships WHERE id='$edit_id' AND student_id='$student_id'");
        $statusRow = mysqli_fetch_assoc($statusQuery);

        if($statusRow['status'] == 'Rejected')
        {
            $newStatus = 'Pending';
            $newReason = '';
        }
        else
        {
            $newStatus = $statusRow['status'];
            $newReason = $statusRow['rejection_reason'];
        }

        $query = "UPDATE internships
                  SET company_name='$company_name',
                      company_contact='$company_contact',
                      internship_role='$internship_role',
                      mini_project_title='$mini_project_title',
                      start_date='$start_date',
                      end_date='$end_date',
                      stipend='$stipend',
                      mode='$mode',
                      mentor_name='$mentor_name',
                      internship_type='$internship_type',
                      offer_letter='$file_name',
                      status='$newStatus',
                      rejection_reason='$newReason'
                  WHERE id='$edit_id'
                  AND student_id='$student_id'";

        mysqli_query($conn, $query);

        echo "<script>
                alert('Application Updated Successfully');
                window.location.href='my_internships.php';
              </script>";
        exit();
    }
    else
    {
        $query = "INSERT INTO internships
                  (
                      student_id,
                      company_name,
                      company_contact,
                      internship_role,
                      mini_project_title,
                      start_date,
                      end_date,
                      stipend,
                      mode,
                      mentor_name,
                      internship_type,
                      offer_letter,
                      status
                  )
                  VALUES
                  (
                      '$student_id',
                      '$company_name',
                      '$company_contact',
                      '$internship_role',
                      '$mini_project_title',
                      '$start_date',
                      '$end_date',
                      '$stipend',
                      '$mode',
                      '$mentor_name',
                      '$internship_type',
                      '$file_name',
                      'Pending'
                  )";

        mysqli_query($conn, $query);

        echo "<script>
                alert('Internship Applied Successfully');
                window.location.href='dashboard.php';
              </script>";
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Internship Application Form — MIT-ADT Internship Portal</title>

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
            margin-bottom: 28px;
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

        /* Form Controls */
        .form-section-heading {
            font-size: 0.82rem;
            font-weight: 800;
            color: var(--brand-purple);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin: 24px 0 16px 0;
            padding-bottom: 8px;
            border-bottom: 1.5px solid var(--brand-purple-light);
        }

        .form-label {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-heading);
            margin-bottom: 6px;
        }

        .form-control, .form-select {
            height: 48px;
            border-radius: 10px;
            border: 1px solid var(--card-border);
            padding: 0 16px;
            font-size: 0.95rem;
            color: var(--text-heading);
            background-color: #ffffff;
            transition: all 0.2s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--brand-purple);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
            background-color: #ffffff;
        }

        .locked-field {
            background-color: #f1f5f9 !important;
            color: #64748b !important;
            border-color: #e2e8f0 !important;
            cursor: not-allowed;
        }

        /* Upload Dropzone Area */
        .upload-zone {
            border: 2px dashed #c7d2fe;
            border-radius: 16px;
            padding: 32px 24px;
            text-align: center;
            background: #f8fafc;
            position: relative;
            transition: all 0.2s ease;
            cursor: pointer;
            margin-bottom: 16px;
        }

        .upload-zone:hover,
        .upload-zone.dragover {
            border-color: var(--brand-purple);
            background: var(--brand-purple-subtle);
        }

        .upload-icon-circle {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            background: var(--brand-purple-light);
            color: var(--brand-purple);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px;
            font-size: 1.5rem;
        }

        .upload-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-heading);
            margin-bottom: 4px;
        }

        .upload-hint {
            font-size: 0.85rem;
            color: var(--text-muted);
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
            padding: 12px 16px;
            margin-top: 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        /* Details Grid */
        .details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px 24px;
        }

        .detail-item .meta-label {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 4px;
        }

        .detail-item .meta-value {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--text-heading);
            word-break: break-all;
        }

        /* Status Banner Box */
        .status-alert-box {
            border-radius: 14px;
            padding: 20px 24px;
            display: flex;
            align-items: flex-start;
            gap: 16px;
        }

        .status-alert-box.alert-success-custom {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #166534;
        }

        .status-alert-box.alert-warning-custom {
            background: #fffbeb;
            border: 1px solid #fde68a;
            color: #92400e;
        }

        .status-alert-box.alert-danger-custom {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        .status-alert-icon {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .alert-success-custom .status-alert-icon { background: #dcfce7; color: #16a34a; }
        .alert-warning-custom .status-alert-icon { background: #fef3c7; color: #d97706; }
        .alert-danger-custom .status-alert-icon { background: #fee2e2; color: #dc2626; }

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
            font-size: 1rem;
            box-shadow: 0 8px 20px rgba(79, 70, 229, 0.25);
            transition: all 0.2s ease;
        }

        .btn-brand-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 24px rgba(79, 70, 229, 0.35);
            color: #ffffff;
        }

        /* Guidelines Card */
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
            font-size: 0.9rem;
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

        @media (max-width: 991px) {
            .details-grid {
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
        }
    </style>
</head>
<body>

<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="page-inner">

    <!-- Top Hero Header Box -->
    <div class="page-header-box d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <div class="page-eyebrow"><i class="fa-solid fa-briefcase me-1"></i> Internship Portal</div>
            <h1 class="page-title">
                <?php
                if($is_locked_edit) {
                    echo '<i class="fa-solid fa-lock me-2 text-warning"></i> Update Mini Project Title';
                } elseif($edit_mode) {
                    echo '<i class="fa-solid fa-pen-to-square me-2 text-primary"></i> Edit & Resubmit Application';
                } else {
                    echo '<i class="fa-solid fa-file-pen me-2 text-primary"></i> Internship Application Form';
                }
                ?>
            </h1>
            <p class="page-subtitle">Submit your internship details, track approval status, and manage your offer letter.</p>
        </div>
        <div>
            <a href="my_internships.php" class="btn btn-light fw-bold px-4 py-2 shadow-sm text-primary rounded-3 border">
                <i class="fa-solid fa-clipboard-list me-2"></i> My Applications
            </a>
        </div>
    </div>

    <?php
    $display_record = $edit_mode ? $edit_data : $existing_application;
    ?>

    <div class="row g-4">
        
        <!-- Left Main Content Column -->
        <div class="col-lg-8">

            <?php if($display_record): ?>
                
                <!-- 1. EXISTING INTERNSHIP DETAILS CARD -->
                <div class="content-card">
                    <div class="card-header-title">
                        <div class="card-header-icon">
                            <i class="fa-solid fa-briefcase"></i>
                        </div>
                        <div>
                            <h2>Current Internship Details</h2>
                            <p>Your current internship application on file</p>
                        </div>
                    </div>

                    <div class="details-grid">
                        <div class="detail-item">
                            <div class="meta-label">Company Name</div>
                            <div class="meta-value"><?php echo htmlspecialchars($display_record['company_name'] ?? '—'); ?></div>
                        </div>

                        <div class="detail-item">
                            <div class="meta-label">Internship Role</div>
                            <div class="meta-value"><?php echo htmlspecialchars($display_record['internship_role'] ?? '—'); ?></div>
                        </div>

                        <div class="detail-item">
                            <div class="meta-label">Mini Project Title</div>
                            <div class="meta-value"><?php echo htmlspecialchars($display_record['mini_project_title'] ?? '—'); ?></div>
                        </div>

                        <div class="detail-item">
                            <div class="meta-label">Mentor Name</div>
                            <div class="meta-value"><?php echo htmlspecialchars($display_record['mentor_name'] ?? '—'); ?></div>
                        </div>

                        <div class="detail-item">
                            <div class="meta-label">Internship Type</div>
                            <div class="meta-value"><?php echo htmlspecialchars($display_record['internship_type'] ?? '—'); ?></div>
                        </div>

                        <div class="detail-item">
                            <div class="meta-label">Mode</div>
                            <div class="meta-value"><?php echo htmlspecialchars($display_record['mode'] ?? '—'); ?></div>
                        </div>

                        <div class="detail-item">
                            <div class="meta-label">Start Date</div>
                            <div class="meta-value"><?php echo htmlspecialchars($display_record['start_date'] ?? '—'); ?></div>
                        </div>

                        <div class="detail-item">
                            <div class="meta-label">End Date</div>
                            <div class="meta-value"><?php echo htmlspecialchars($display_record['end_date'] ?? '—'); ?></div>
                        </div>

                        <div class="detail-item">
                            <div class="meta-label">Stipend</div>
                            <div class="meta-value"><?php echo ($display_record['stipend'] !== '' && $display_record['stipend'] !== null) ? htmlspecialchars($display_record['stipend']) : '—'; ?></div>
                        </div>

                        <div class="detail-item">
                            <div class="meta-label">Application Status</div>
                            <div class="meta-value">
                                <?php
                                $statusVal = $display_record['status'] ?? 'Pending';
                                if($statusVal == 'Approved') {
                                    echo '<span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 rounded-pill fw-bold"><i class="fa-solid fa-circle-check me-1"></i> Approved</span>';
                                } elseif($statusVal == 'Rejected') {
                                    echo '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-2 rounded-pill fw-bold"><i class="fa-solid fa-circle-xmark me-1"></i> Rejected</span>';
                                } else {
                                    echo '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-3 py-2 rounded-pill fw-bold"><i class="fa-solid fa-clock me-1"></i> Pending Review</span>';
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2. APPROVAL LETTER & APPLICATION STATUS CARD -->
                <div class="content-card">
                    <div class="card-header-title">
                        <div class="card-header-icon">
                            <i class="fa-solid fa-shield-halved"></i>
                        </div>
                        <div>
                            <h2>Approval Letter Status</h2>
                            <p>Current status of your internship approval letter and documents</p>
                        </div>
                    </div>

                    <?php if($is_locked_edit || ($display_record['status']=='Approved' && !empty($display_record['offer_letter']))): ?>
                        
                        <div class="status-alert-box alert-success-custom">
                            <div class="status-alert-icon">
                                <i class="fa-solid fa-circle-check"></i>
                            </div>
                            <div class="flex-grow-1">
                                <h5 class="fw-bold mb-1">Approval Letter Uploaded & Verified</h5>
                                <p class="mb-2 text-success-emphasis">Your internship application has been approved and your official offer letter is safely attached.</p>
                                <?php if(!empty($display_record['offer_letter'])): ?>
                                    <a href="<?php echo htmlspecialchars('../uploads/offer_letters/'.$display_division.'/'.$display_record['offer_letter']); ?>" 
                                       target="_blank" 
                                       class="btn btn-success fw-bold px-3 py-2 rounded-3 shadow-sm d-inline-flex align-items-center gap-2 mt-1">
                                        <i class="fa-solid fa-file-pdf fs-5"></i> View Approval Letter
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                    <?php elseif($application_exists && !$edit_mode && $display_record['status']=='Pending'): ?>

                        <div class="status-alert-box alert-warning-custom">
                            <div class="status-alert-icon">
                                <i class="fa-solid fa-clock"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold mb-1">Application Under Review</h5>
                                <p class="mb-0">Your internship application has been submitted and is currently being reviewed by the faculty coordinator. Please check <strong>My Applications</strong> for status updates.</p>
                            </div>
                        </div>

                    <?php elseif($application_exists && !$edit_mode && $display_record['status']=='Rejected'): ?>

                        <div class="status-alert-box alert-danger-custom">
                            <div class="status-alert-icon">
                                <i class="fa-solid fa-circle-exclamation"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold mb-1">Application Rejected</h5>
                                <p class="mb-0">Your internship application was rejected. Please review faculty feedback and edit/resubmit your application from <strong>My Applications</strong>.</p>
                            </div>
                        </div>

                    <?php elseif($application_exists && !$edit_mode && $display_record['status']=='Approved' && empty($display_record['offer_letter'])): ?>

                        <div class="status-alert-box alert-warning-custom">
                            <div class="status-alert-icon">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold mb-1">Approval Letter Pending Upload</h5>
                                <p class="mb-0">Your internship application has been approved by the faculty, but you have not uploaded your official offer letter yet. Please upload your offer letter from <strong>My Applications</strong> to complete the process.</p>
                            </div>
                        </div>

                    <?php elseif($edit_mode && !$is_locked_edit): ?>

                        <?php if(!empty($display_record['offer_letter'])): ?>
                            <div class="status-alert-box alert-warning-custom">
                                <div class="status-alert-icon">
                                    <i class="fa-solid fa-file-invoice"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <h5 class="fw-bold mb-1">Offer Letter Currently on File</h5>
                                    <p class="mb-2">Attached file: <strong><?php echo htmlspecialchars($display_record['offer_letter']); ?></strong></p>
                                    <a href="<?php echo htmlspecialchars('../uploads/offer_letters/'.$display_division.'/'.$display_record['offer_letter']); ?>" 
                                       target="_blank" 
                                       class="btn btn-outline-warning fw-bold text-dark px-3 py-2 rounded-3 d-inline-flex align-items-center gap-2">
                                        <i class="fa-solid fa-eye"></i> View Current Letter
                                    </a>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="status-alert-box alert-warning-custom">
                                <div class="status-alert-icon">
                                    <i class="fa-solid fa-file-circle-plus"></i>
                                </div>
                                <div>
                                    <h5 class="fw-bold mb-1">No Offer Letter Attached</h5>
                                    <p class="mb-0">Please upload your official offer letter in PDF format below to complete your application resubmission.</p>
                                </div>
                            </div>
                        <?php endif; ?>

                    <?php endif; ?>
                </div>

            <?php endif; ?>

            <!-- 3. APPLICATION FORM CARD -->
            <?php if($is_locked_edit): ?>

                <!-- LOCKED EDIT MODE (ONLY MINI PROJECT TITLE EDITABLE) -->
                <div class="content-card">
                    <div class="card-header-title">
                        <div class="card-header-icon">
                            <i class="fa-solid fa-lock"></i>
                        </div>
                        <div>
                            <h2>Update Mini Project Title</h2>
                            <p>All other fields are locked because your internship and offer letter are already approved.</p>
                        </div>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="edit_id" value="<?php echo (int)$edit_data['id']; ?>">

                        <div class="mb-3">
                            <label class="form-label">Company Name</label>
                            <input type="text" class="form-control locked-field" value="<?php echo htmlspecialchars($edit_data['company_name'] ?? ''); ?>" readonly disabled>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Internship Role</label>
                            <input type="text" class="form-control locked-field" value="<?php echo htmlspecialchars($edit_data['internship_role'] ?? ''); ?>" readonly disabled>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Start Date</label>
                                <input type="text" class="form-control locked-field" value="<?php echo htmlspecialchars($edit_data['start_date'] ?? ''); ?>" readonly disabled>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">End Date</label>
                                <input type="text" class="form-control locked-field" value="<?php echo htmlspecialchars($edit_data['end_date'] ?? ''); ?>" readonly disabled>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Mentor Name</label>
                            <input type="text" class="form-control locked-field" value="<?php echo htmlspecialchars($edit_data['mentor_name'] ?? ''); ?>" readonly disabled>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Internship Type</label>
                            <input type="text" class="form-control locked-field" value="<?php echo htmlspecialchars($edit_data['internship_type'] ?? ''); ?>" readonly disabled>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Mode</label>
                            <input type="text" class="form-control locked-field" value="<?php echo htmlspecialchars($edit_data['mode'] ?? ''); ?>" readonly disabled>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold text-dark">Mini Project Title <span class="text-danger">*</span></label>
                            <input type="text" 
                                   name="mini_project_title" 
                                   class="form-control" 
                                   placeholder="Enter your Mini Project Title" 
                                   value="<?php echo htmlspecialchars($edit_data['mini_project_title'] ?? ''); ?>" 
                                   required>
                        </div>

                        <button type="submit" name="submit" class="btn-brand-primary w-100 py-3" id="submitBtn">
                            <i class="fa-solid fa-circle-check"></i>
                            <span>Update Mini Project Title</span>
                        </button>
                    </form>
                </div>

            <?php elseif($application_exists && !$edit_mode): ?>

                <!-- Form suppressed because user already submitted application without choosing edit mode -->

            <?php else: ?>

                <!-- NEW APPLICATION / EDIT APPLICATION FORM -->
                <div class="content-card">
                    <div class="card-header-title">
                        <div class="card-header-icon">
                            <i class="fa-solid fa-file-signature"></i>
                        </div>
                        <div>
                            <h2><?php echo $edit_mode ? "Edit & Resubmit Application" : "Internship Application Form"; ?></h2>
                            <p>Fill in all details accurately as per your official internship offer letter</p>
                        </div>
                    </div>

                    <form method="POST" enctype="multipart/form-data" id="applyForm">
                        <?php if($edit_mode): ?>
                            <input type="hidden" name="edit_id" value="<?php echo htmlspecialchars($edit_data['id'] ?? ''); ?>">
                        <?php endif; ?>

                        <!-- Section 1: Company & Mentor Details -->
                        <div class="form-section-heading"><i class="fa-solid fa-building me-2"></i> Company & Contact Details</div>

                        <div class="mb-3">
                            <label class="form-label">Company / Organization Name <span class="text-danger">*</span></label>
                            <input type="text" 
                                   name="company_name" 
                                   class="form-control" 
                                   placeholder="e.g. Tata Consultancy Services" 
                                   value="<?php echo $edit_mode ? htmlspecialchars($edit_data['company_name'] ?? '') : ''; ?>" 
                                   required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Company Contact Number <span class="text-danger">*</span></label>
                            <input type="text" 
                                   name="company_contact" 
                                   class="form-control" 
                                   placeholder="e.g. 9876543210" 
                                   value="<?php echo $edit_mode ? htmlspecialchars($edit_data['company_contact'] ?? '') : ''; ?>" 
                                   required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Internship Role / Designation <span class="text-danger">*</span></label>
                            <input type="text" 
                                   name="internship_role" 
                                   class="form-control" 
                                   placeholder="e.g. Software Development Intern" 
                                   value="<?php echo $edit_mode ? htmlspecialchars($edit_data['internship_role'] ?? '') : ''; ?>" 
                                   required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Industry / Company Mentor Name <span class="text-danger">*</span></label>
                            <input type="text" 
                                   name="mentor_name" 
                                   class="form-control" 
                                   placeholder="Enter Mentor Name & Designation" 
                                   value="<?php echo $edit_mode ? htmlspecialchars($edit_data['mentor_name'] ?? '') : ''; ?>" 
                                   required>
                        </div>

                        <!-- Section 2: Internship Schedule & Category -->
                        <div class="form-section-heading"><i class="fa-solid fa-calendar-days me-2"></i> Schedule & Internship Terms</div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Start Date <span class="text-danger">*</span></label>
                                <input type="date" 
                                       name="start_date" 
                                       class="form-control" 
                                       value="<?php echo $edit_mode ? htmlspecialchars($edit_data['start_date'] ?? '') : ''; ?>" 
                                       required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">End Date <span class="text-danger">*</span></label>
                                <input type="date" 
                                       name="end_date" 
                                       class="form-control" 
                                       value="<?php echo $edit_mode ? htmlspecialchars($edit_data['end_date'] ?? '') : ''; ?>" 
                                       required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Stipend Amount (Monthly / Total)</label>
                            <input type="text" 
                                   name="stipend" 
                                   class="form-control" 
                                   placeholder="e.g. Rs. 10,000 / Unpaid" 
                                   value="<?php echo $edit_mode ? htmlspecialchars($edit_data['stipend'] ?? '') : ''; ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Internship Category / Type <span class="text-danger">*</span></label>
                            <select name="internship_type" class="form-select" required>
                                <option value="">Select Internship Type</option>
                                <option value="Problem-based Internship" <?php if($edit_mode && ($edit_data['internship_type']??"")=="Problem-based Internship") echo "selected"; ?>>Problem-based Internship</option>
                                <option value="Training + Mini Project" <?php if($edit_mode && ($edit_data['internship_type']??"")=="Training + Mini Project") echo "selected"; ?>>Training + Mini Project</option>
                                <option value="Real-world Exposure Internship (Field Work)" <?php if($edit_mode && ($edit_data['internship_type']??"")=="Real-world Exposure Internship (Field Work)") echo "selected"; ?>>Real-world Exposure Internship (Field Work)</option>
                                <option value="Research Internship" <?php if($edit_mode && ($edit_data['internship_type']??"")=="Research Internship") echo "selected"; ?>>Research Internship</option>
                                <option value="International Internship / Visit (Short-term programs)" <?php if($edit_mode && ($edit_data['internship_type']??"")=="International Internship / Visit (Short-term programs)") echo "selected"; ?>>International Internship / Visit (Short-term programs)</option>
                            </select>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Work Mode <span class="text-danger">*</span></label>
                            <select name="mode" class="form-select" required>
                                <option value="">Select Mode</option>
                                <option value="Online" <?php if($edit_mode && ($edit_data['mode']??"")=="Online") echo "selected"; ?>>Online</option>
                                <option value="Offline" <?php if($edit_mode && ($edit_data['mode']??"")=="Offline") echo "selected"; ?>>Offline</option>
                                <option value="Hybrid" <?php if($edit_mode && ($edit_data['mode']??"")=="Hybrid") echo "selected"; ?>>Hybrid</option>
                            </select>
                        </div>

                        <!-- Section 3: Project Title & Document Upload -->
                        <div class="form-section-heading"><i class="fa-solid fa-file-circle-check me-2"></i> Project & Offer Letter Document</div>

                        <div class="mb-4">
                            <label class="form-label">Mini Project Title <span class="text-danger">*</span></label>
                            <input type="text" 
                                   name="mini_project_title" 
                                   class="form-control" 
                                   placeholder="Enter proposed Mini Project Title" 
                                   value="<?php echo $edit_mode ? htmlspecialchars($edit_data['mini_project_title'] ?? '') : ''; ?>" 
                                   required>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">
                                Upload Official Offer Letter 
                                <?php if(!$edit_mode): ?><span class="text-danger">*</span><?php endif; ?>
                            </label>

                            <div class="upload-zone" id="offerLetterUploadZone">
                                <div class="upload-icon-circle">
                                    <i class="fa-solid fa-file-pdf"></i>
                                </div>
                                <div class="upload-title" id="offerLetterUploadTitle">Drag & Drop your offer letter here</div>
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
                                       id="offer_letter" 
                                       name="offer_letter" 
                                       accept=".pdf" 
                                       <?php if(!$edit_mode) echo "required"; ?>>

                                <div id="selectedFileBox" class="selected-file-box" style="display: none;">
                                    <div class="d-flex align-items-center gap-2 font-semibold text-primary" style="font-size: 0.9rem;">
                                        <i class="fa-solid fa-circle-check text-success fs-5"></i>
                                        <span id="selectedFileName">filename.pdf</span>
                                        <span class="badge bg-light text-dark border ms-1" id="selectedFileSize">0 KB</span>
                                    </div>
                                    <button type="button" class="btn-close btn-sm" id="removeFileBtn" aria-label="Remove"></button>
                                </div>
                            </div>
                        </div>

                        <button type="submit" name="submit" class="btn-brand-primary w-100 py-3" id="submitBtn">
                            <i class="fa-solid fa-paper-plane"></i>
                            <span id="submitBtnText"><?php echo $edit_mode ? "Update & Resubmit Application" : "Submit Internship Application"; ?></span>
                        </button>
                    </form>
                </div>

            <?php endif; ?>

        </div>

        <!-- Right Guidance & Help Column -->
        <div class="col-lg-4">
            <div class="info-card">
                <h3>
                    <i class="fa-solid fa-shield-halved text-primary"></i>
                    Application Guidelines
                </h3>

                <ul class="guideline-list">
                    <li>
                        <i class="fa-solid fa-circle-check"></i>
                        <span>Fill in company contact and mentor details accurately.</span>
                    </li>
                    <li>
                        <i class="fa-solid fa-circle-check"></i>
                        <span>Select the appropriate <strong>Internship Type</strong> and <strong>Work Mode</strong>.</span>
                    </li>
                    <li>
                        <i class="fa-solid fa-circle-check"></i>
                        <span>Offer letter document must be in <strong>PDF format</strong> only.</span>
                    </li>
                    <li>
                        <i class="fa-solid fa-circle-check"></i>
                        <span>File size limit is strictly <strong>500 KB</strong>.</span>
                    </li>
                    <li>
                        <i class="fa-solid fa-circle-check"></i>
                        <span>After approval, the <strong>Mini Project Title</strong> can still be updated.</span>
                    </li>
                </ul>

                <div class="support-box">
                    <h4>
                        <i class="fa-solid fa-headset"></i>
                        Need Assistance?
                    </h4>
                    <p class="mb-0">
                        For any queries regarding application approvals or offer letter verification, please contact your department faculty coordinator.
                    </p>
                </div>
            </div>
        </div>

    </div>

</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const fileInput = document.getElementById("offer_letter");
    const selectedFileBox = document.getElementById("selectedFileBox");
    const selectedFileName = document.getElementById("selectedFileName");
    const selectedFileSize = document.getElementById("selectedFileSize");
    const removeFileBtn = document.getElementById("removeFileBtn");
    const uploadZone = document.getElementById("offerLetterUploadZone");
    const applyForm = document.getElementById("applyForm");
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
                    alert("Offer Letter size must be less than 500 KB.");
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

    if (applyForm) {
        applyForm.addEventListener("submit", function() {
            if (submitBtn) {
                submitBtn.disabled = true;
                if (submitBtnText) {
                    submitBtnText.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Submitting...';
                }
            }
        });
    }
});
</script>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>

</body>
</html>