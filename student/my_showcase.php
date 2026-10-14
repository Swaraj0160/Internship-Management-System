<?php
session_start();
include("../config.php");

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

$student_id = (int)$_SESSION['user_id'];
$activePage = 'my_showcase.php';

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Fetch Student details for enrollment-based directory storage
$stu_stmt = $conn->prepare("SELECT enrollment_no, full_name, division FROM users WHERE id = ? LIMIT 1");
$stu_stmt->bind_param("i", $student_id);
$stu_stmt->execute();
$stu_data = $stu_stmt->get_result()->fetch_assoc() ?: [];
$stu_stmt->close();

$enrollment_no = !empty($stu_data['enrollment_no']) 
    ? preg_replace('/[^a-zA-Z0-9_\-]/', '', $stu_data['enrollment_no']) 
    : ("USER_" . $student_id);

// Base Upload Directory
$base_upload_dir = __DIR__ . '/../uploads/student_work/';
if (!is_dir($base_upload_dir)) {
    @mkdir($base_upload_dir, 0755, true);
}

// Handle Form Submissions (Create, Edit, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf_token, $posted_csrf)) {
        $_SESSION['showcase_error'] = "Security validation failed. Please refresh and try again.";
        header("Location: my_showcase.php");
        exit();
    }

    $action = $_POST['action'] ?? '';

    // ==========================================
    // ACTION 1: CREATE NEW SUBMISSION
    // ==========================================
    if ($action === 'create_work' || isset($_POST['submit_work'])) {
        $title = trim($_POST['title'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $tech_used = trim($_POST['tech_used'] ?? '');
        $internship_id = !empty($_POST['internship_id']) ? (int)$_POST['internship_id'] : null;

        if (!empty($url) && !filter_var($url, FILTER_VALIDATE_URL)) {
            $_SESSION['showcase_error'] = "Please enter a valid project URL starting with http:// or https://";
            header("Location: my_showcase.php");
            exit();
        }

        if (empty($title) || empty($category) || empty($description)) {
            $_SESSION['showcase_error'] = "Title, Category, and Description are required fields.";
            header("Location: my_showcase.php");
            exit();
        }

        // Insert database record first to obtain submission ID
        $stmt = $conn->prepare("INSERT INTO student_work_showcase (student_id, internship_id, title, category, description, url, file_path, tech_used, status, created_at) VALUES (?, ?, ?, ?, ?, ?, '', ?, 'Submitted', NOW())");
        $stmt->bind_param("iisssss", $student_id, $internship_id, $title, $category, $description, $url, $tech_used);
        
        if ($stmt->execute()) {
            $sub_id = $stmt->insert_id;
            $stmt->close();

            // Handle file upload if present
            if (isset($_FILES['work_file']) && $_FILES['work_file']['error'] === UPLOAD_ERR_OK) {
                $allowed_exts = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'zip', 'png', 'jpg', 'jpeg'];
                $file_name = $_FILES['work_file']['name'];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                if (!in_array($file_ext, $allowed_exts)) {
                    $_SESSION['showcase_warning'] = "Work submitted, but file was skipped because format .$file_ext is not allowed.";
                } elseif ($_FILES['work_file']['size'] > 10 * 1024 * 1024) { // 10MB limit
                    $_SESSION['showcase_warning'] = "Work submitted, but file was skipped because it exceeds 10MB.";
                } else {
                    // Path format: uploads/student_work/{ENROLLMENT_NUMBER}/{SUBMISSION_ID}/
                    $target_folder = $base_upload_dir . $enrollment_no . '/' . $sub_id . '/';
                    if (!is_dir($target_folder)) {
                        @mkdir($target_folder, 0755, true);
                    }

                    $safe_filename = 'work_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $file_ext;
                    $target_file = $target_folder . $safe_filename;
                    $db_relative_path = 'student_work/' . $enrollment_no . '/' . $sub_id . '/' . $safe_filename;

                    if (move_uploaded_file($_FILES['work_file']['tmp_name'], $target_file)) {
                        $up_stmt = $conn->prepare("UPDATE student_work_showcase SET file_path = ? WHERE id = ?");
                        $up_stmt->bind_param("si", $db_relative_path, $sub_id);
                        $up_stmt->execute();
                        $up_stmt->close();
                    }
                }
            }

            $_SESSION['showcase_success'] = "Your work has been submitted successfully!";
        } else {
            $_SESSION['showcase_error'] = "Database error: Unable to create submission.";
        }

        header("Location: my_showcase.php");
        exit();
    }

    // ==========================================
    // ACTION 2: EDIT EXISTING SUBMISSION
    // ==========================================
    elseif ($action === 'edit_work') {
        $sub_id = (int)($_POST['submission_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $tech_used = trim($_POST['tech_used'] ?? '');
        $internship_id = !empty($_POST['internship_id']) ? (int)$_POST['internship_id'] : null;

        // Verify ownership
        $chk_stmt = $conn->prepare("SELECT * FROM student_work_showcase WHERE id = ? AND student_id = ?");
        $chk_stmt->bind_param("ii", $sub_id, $student_id);
        $chk_stmt->execute();
        $existing = $chk_stmt->get_result()->fetch_assoc();
        $chk_stmt->close();

        if (!$existing) {
            $_SESSION['showcase_error'] = "Unauthorized operation or submission not found.";
            header("Location: my_showcase.php");
            exit();
        }

        if (!empty($url) && !filter_var($url, FILTER_VALIDATE_URL)) {
            $_SESSION['showcase_error'] = "Please enter a valid project URL.";
            header("Location: my_showcase.php");
            exit();
        }

        if (empty($title) || empty($category) || empty($description)) {
            $_SESSION['showcase_error'] = "Title, Category, and Description cannot be empty.";
            header("Location: my_showcase.php");
            exit();
        }

        // Handle file replacement if new file is uploaded
        $file_path = $existing['file_path'];
        if (isset($_FILES['work_file']) && $_FILES['work_file']['error'] === UPLOAD_ERR_OK) {
            $allowed_exts = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'zip', 'png', 'jpg', 'jpeg'];
            $file_name = $_FILES['work_file']['name'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            if (in_array($file_ext, $allowed_exts) && $_FILES['work_file']['size'] <= 10 * 1024 * 1024) {
                $target_folder = $base_upload_dir . $enrollment_no . '/' . $sub_id . '/';
                if (!is_dir($target_folder)) {
                    @mkdir($target_folder, 0755, true);
                }

                $safe_filename = 'work_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $file_ext;
                $target_file = $target_folder . $safe_filename;
                $new_relative_path = 'student_work/' . $enrollment_no . '/' . $sub_id . '/' . $safe_filename;

                if (move_uploaded_file($_FILES['work_file']['tmp_name'], $target_file)) {
                    // Remove old file if it exists
                    if (!empty($file_path)) {
                        $old_full_path = __DIR__ . '/../uploads/' . $file_path;
                        if (file_exists($old_full_path) && is_file($old_full_path)) {
                            @unlink($old_full_path);
                        }
                    }
                    $file_path = $new_relative_path;
                }
            } else {
                $_SESSION['showcase_warning'] = "Submission text updated, but file replacement was skipped (invalid format or > 10MB).";
            }
        }

        // Update submission while preserving faculty review status & feedback
        $stmt = $conn->prepare("UPDATE student_work_showcase SET title = ?, category = ?, description = ?, url = ?, file_path = ?, tech_used = ?, internship_id = ?, updated_at = NOW() WHERE id = ? AND student_id = ?");
        $stmt->bind_param("ssssssiii", $title, $category, $description, $url, $file_path, $tech_used, $internship_id, $sub_id, $student_id);
        
        if ($stmt->execute()) {
            $_SESSION['showcase_success'] = "Submission updated successfully!";
        } else {
            $_SESSION['showcase_error'] = "Failed to update submission.";
        }
        $stmt->close();

        header("Location: my_showcase.php");
        exit();
    }

    // ==========================================
    // ACTION 3: DELETE SUBMISSION
    // ==========================================
    elseif ($action === 'delete_work') {
        $sub_id = (int)($_POST['submission_id'] ?? 0);

        // Verify ownership
        $chk_stmt = $conn->prepare("SELECT * FROM student_work_showcase WHERE id = ? AND student_id = ?");
        $chk_stmt->bind_param("ii", $sub_id, $student_id);
        $chk_stmt->execute();
        $existing = $chk_stmt->get_result()->fetch_assoc();
        $chk_stmt->close();

        if (!$existing) {
            $_SESSION['showcase_error'] = "Unauthorized action or submission does not exist.";
            header("Location: my_showcase.php");
            exit();
        }

        // Delete physical file and folder if present
        $file_path = $existing['file_path'];
        if (!empty($file_path)) {
            $full_file_path = __DIR__ . '/../uploads/' . $file_path;
            if (file_exists($full_file_path) && is_file($full_file_path)) {
                @unlink($full_file_path);
            }
        }

        // Remove submission folder if empty
        $folder_path = $base_upload_dir . $enrollment_no . '/' . $sub_id . '/';
        if (is_dir($folder_path)) {
            @rmdir($folder_path);
        }

        // Delete database record
        $del_stmt = $conn->prepare("DELETE FROM student_work_showcase WHERE id = ? AND student_id = ?");
        $del_stmt->bind_param("ii", $sub_id, $student_id);
        if ($del_stmt->execute()) {
            $_SESSION['showcase_success'] = "Submission deleted successfully.";
        } else {
            $_SESSION['showcase_error'] = "Failed to delete submission.";
        }
        $del_stmt->close();

        header("Location: my_showcase.php");
        exit();
    }
}

// Fetch Approved Internships for dropdown
$internships = [];
$int_stmt = $conn->prepare("SELECT id, company_name, internship_role FROM internships WHERE student_id = ? AND status = 'Approved' ORDER BY created_at DESC");
$int_stmt->bind_param("i", $student_id);
$int_stmt->execute();
$int_res = $int_stmt->get_result();
while ($r = $int_res->fetch_assoc()) {
    $internships[] = $r;
}
$int_stmt->close();

// Fetch Student Submissions
$submissions = [];
$stats = ['total' => 0, 'submitted' => 0, 'reviewed' => 0];

$sub_stmt = $conn->prepare("
    SELECT sw.*, i.company_name, i.internship_role, r.full_name as reviewer_name 
    FROM student_work_showcase sw 
    LEFT JOIN internships i ON sw.internship_id = i.id 
    LEFT JOIN users r ON sw.reviewed_by = r.id 
    WHERE sw.student_id = ? 
    ORDER BY sw.created_at DESC
");
$sub_stmt->bind_param("i", $student_id);
$sub_stmt->execute();
$sub_res = $sub_stmt->get_result();
while ($r = $sub_res->fetch_assoc()) {
    $submissions[] = $r;
    $stats['total']++;
    if (in_array($r['status'], ['Reviewed', 'Approved'])) {
        $stats['reviewed']++;
    } else {
        $stats['submitted']++;
    }
}
$sub_stmt->close();

// Retain session messages
$success_msg = $_SESSION['showcase_success'] ?? null;
$error_msg = $_SESSION['showcase_error'] ?? null;
$warning_msg = $_SESSION['showcase_warning'] ?? null;
unset($_SESSION['showcase_success'], $_SESSION['showcase_error'], $_SESSION['showcase_warning']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Internship Work | MIT-ADT University</title>
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
            max-width: 1280px;
            margin: 0 auto;
        }

        /* Top Hero Header */
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

        /* Stat Summary Cards */
        .stat-card {
            background: var(--surface);
            border-radius: 14px;
            padding: 20px 24px;
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            display: flex;
            align-items: center;
            gap: 16px;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px -4px rgba(0,0,0,0.06);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .stat-val {
            font-size: 1.6rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 2px;
        }

        .stat-lbl {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Search & Filter Bar */
        .filter-toolbar {
            background: var(--surface);
            border-radius: 14px;
            padding: 16px 20px;
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        /* Submission Cards */
        .submission-card {
            background: var(--surface);
            border-radius: 16px;
            border: 1px solid var(--border-color);
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.03);
            transition: all 0.2s ease;
            position: relative;
        }

        .submission-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 12px 24px -6px rgba(0, 0, 0, 0.08);
            transform: translateY(-2px);
        }

        .submission-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 12px;
        }

        .work-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-dark);
            margin: 0 0 6px 0;
            line-height: 1.35;
        }

        .category-badge {
            background: #e0e7ff;
            color: #3730a3;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .status-pill {
            font-size: 0.75rem;
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

        .status-submitted { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
        .status-review { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
        .status-reviewed, .status-approved { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
        .status-rejected { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }

        .work-description {
            font-size: 0.95rem;
            color: #475569;
            line-height: 1.6;
            margin-bottom: 16px;
            white-space: pre-line;
        }

        .tech-pill {
            background: #f1f5f9;
            color: #334155;
            font-size: 0.8rem;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 20px;
            border: 1px solid #e2e8f0;
        }

        .submission-meta {
            display: flex;
            align-items: center;
            gap: 20px;
            font-size: 0.85rem;
            color: var(--text-muted);
            flex-wrap: wrap;
            margin-bottom: 16px;
            padding-bottom: 16px;
            border-bottom: 1px solid #f1f5f9;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .action-btn-group {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn-action {
            font-size: 0.85rem;
            font-weight: 600;
            padding: 8px 16px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            transition: all 0.2s;
        }

        .btn-action-primary {
            background: var(--brand-purple);
            color: white;
            border: none;
        }

        .btn-action-primary:hover {
            background: var(--brand-purple-hover);
            color: white;
        }

        .btn-action-outline {
            background: #fff;
            border: 1px solid var(--border-color);
            color: var(--text-dark);
        }

        .btn-action-outline:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: var(--brand-purple);
        }

        .btn-action-danger {
            background: #fff;
            border: 1px solid #fecaca;
            color: #dc2626;
        }

        .btn-action-danger:hover {
            background: #fef2f2;
            border-color: #fca5a5;
        }

        /* Empty State */
        .empty-state-box {
            background: var(--surface);
            border-radius: 20px;
            border: 2px dashed #cbd5e1;
            padding: 60px 24px;
            text-align: center;
            margin: 20px 0;
        }

        .empty-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: #e0e7ff;
            color: var(--brand-purple);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 2.2rem;
            margin-bottom: 20px;
        }

        /* Modals */
        .modal {
            z-index: 1060 !important;
        }
        .modal-backdrop {
            z-index: 1050 !important;
        }

        .modal-content {
            border-radius: 20px;
            border: none;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
        }

        .modal-header {
            background: #f8fafc;
            border-bottom: 1px solid var(--border-color);
            padding: 20px 24px;
            border-top-left-radius: 20px;
            border-top-right-radius: 20px;
        }

        .modal-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--text-dark);
        }

        .modal-body {
            padding: 24px;
        }

        .modal-footer {
            background: #f8fafc;
            border-top: 1px solid var(--border-color);
            padding: 16px 24px;
            border-bottom-left-radius: 20px;
            border-bottom-right-radius: 20px;
        }
    </style>
</head>
<body>

<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="page-inner">

    <!-- Top Hero Header -->
    <div class="page-header-box d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="page-title"><i class="fa-solid fa-diagram-project me-2"></i> My Internship Work</h1>
            <p class="page-subtitle">Showcase your research projects, web applications, publications, and internship deliverables to university evaluators and faculty mentors.</p>
        </div>
        <div>
            <button type="button" class="btn btn-light fw-bold px-4 py-2 shadow-sm text-primary rounded-3" data-bs-toggle="modal" data-bs-target="#createModal">
                <i class="fa-solid fa-plus me-2"></i> Submit New Work
            </button>
        </div>
    </div>

    <!-- Alert Notifications -->
    <?php if (!empty($success_msg)): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3 shadow-sm mb-4" role="alert">
            <i class="fa-solid fa-circle-check me-2"></i> <?php echo h($success_msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_msg)): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3 shadow-sm mb-4" role="alert">
            <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo h($error_msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($warning_msg)): ?>
        <div class="alert alert-warning alert-dismissible fade show rounded-3 shadow-sm mb-4" role="alert">
            <i class="fa-solid fa-circle-exclamation me-2"></i> <?php echo h($warning_msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Summary Stats Bar -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                    <i class="fa-solid fa-folder-open"></i>
                </div>
                <div>
                    <div class="stat-val"><?php echo $stats['total']; ?></div>
                    <div class="stat-lbl">Total Submissions</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
                <div>
                    <div class="stat-val"><?php echo $stats['submitted']; ?></div>
                    <div class="stat-lbl">Pending Review</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-icon bg-success bg-opacity-10 text-success">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div>
                    <div class="stat-val"><?php echo $stats['reviewed']; ?></div>
                    <div class="stat-lbl">Faculty Reviewed</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter & Search Toolbar -->
    <div class="filter-toolbar">
        <div class="row g-3 align-items-center">
            <div class="col-md-6">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0 text-muted"><i class="fa-solid fa-magnifying-glass"></i></span>
                    <input type="text" id="searchInput" class="form-control border-start-0 ps-0" placeholder="Search work title, technology, description..." onkeyup="filterSubmissions()">
                </div>
            </div>
            <div class="col-md-3">
                <select id="categoryFilter" class="form-select" onchange="filterSubmissions()">
                    <option value="">All Categories</option>
                    <option value="Research Paper">Research Paper</option>
                    <option value="Research Project">Research Project</option>
                    <option value="Web Development">Web Development</option>
                    <option value="Mobile Application">Mobile Application</option>
                    <option value="Software Development">Software Development</option>
                    <option value="AI / Machine Learning">AI / Machine Learning</option>
                    <option value="Data Science / Analytics">Data Science / Analytics</option>
                    <option value="IoT / Embedded Systems">IoT / Embedded Systems</option>
                    <option value="Cyber Security">Cyber Security</option>
                    <option value="Cloud / DevOps">Cloud / DevOps</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            <div class="col-md-3">
                <select id="statusFilter" class="form-select" onchange="filterSubmissions()">
                    <option value="">All Statuses</option>
                    <option value="Submitted">Submitted</option>
                    <option value="Under Review">Under Review</option>
                    <option value="Reviewed">Reviewed / Approved</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Submissions Container -->
    <div id="submissionsList">
        <?php if (empty($submissions)): ?>
            <!-- Empty State -->
            <div class="empty-state-box">
                <div class="empty-icon">
                    <i class="fa-solid fa-cloud-arrow-up"></i>
                </div>
                <h3 class="fw-bold mb-2">No Work Submitted Yet</h3>
                <p class="text-muted max-w-md mx-auto mb-4" style="max-width:480px;">Showcase your real-world internship projects, research publications, code repositories, and deliverables to university mentors.</p>
                <button type="button" class="btn btn-primary fw-semibold px-4 py-2" data-bs-toggle="modal" data-bs-target="#createModal">
                    <i class="fa-solid fa-plus me-2"></i> Submit Your First Work
                </button>
            </div>
        <?php else: ?>
            <?php foreach ($submissions as $sub): 
                $status = $sub['status'] ?? 'Submitted';
                $statusClass = 'status-submitted';
                $statusIcon = 'fa-paper-plane';
                
                if ($status === 'Under Review') {
                    $statusClass = 'status-review';
                    $statusIcon = 'fa-hourglass-half';
                } elseif (in_array($status, ['Reviewed', 'Approved'])) {
                    $statusClass = 'status-reviewed';
                    $statusIcon = 'fa-check-circle';
                } elseif ($status === 'Rejected') {
                    $statusClass = 'status-rejected';
                    $statusIcon = 'fa-circle-xmark';
                }

                $tech_array = !empty($sub['tech_used']) ? array_filter(array_map('trim', explode(',', $sub['tech_used']))) : [];
            ?>
                <div class="submission-card" 
                     data-title="<?php echo h(strtolower($sub['title'])); ?>" 
                     data-category="<?php echo h($sub['category']); ?>" 
                     data-status="<?php echo h($status); ?>"
                     data-search="<?php echo h(strtolower($sub['title'] . ' ' . $sub['description'] . ' ' . $sub['tech_used'])); ?>">
                    
                    <div class="submission-header">
                        <div>
                            <div class="mb-2">
                                <span class="category-badge"><i class="fa-solid fa-tag"></i> <?php echo h($sub['category']); ?></span>
                            </div>
                            <h3 class="work-title"><?php echo h($sub['title']); ?></h3>
                        </div>
                        <div>
                            <span class="status-pill <?php echo $statusClass; ?>">
                                <i class="fa-solid <?php echo $statusIcon; ?>"></i> <?php echo h($status); ?>
                            </span>
                        </div>
                    </div>

                    <div class="work-description"><?php echo h($sub['description']); ?></div>

                    <?php if (!empty($tech_array)): ?>
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <?php foreach ($tech_array as $t): ?>
                                <span class="tech-pill"><i class="fa-solid fa-code me-1 text-muted" style="font-size:0.75rem;"></i> <?php echo h($t); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="submission-meta">
                        <div class="meta-item" title="Submitted Date">
                            <i class="fa-regular fa-calendar-check text-primary"></i> 
                            <span>Submitted: <?php echo date('d M Y, h:i A', strtotime($sub['created_at'])); ?></span>
                        </div>
                        <?php if (!empty($sub['company_name'])): ?>
                            <div class="meta-item" title="Associated Internship">
                                <i class="fa-solid fa-building-user text-indigo"></i> 
                                <span><?php echo h($sub['company_name']); ?><?php echo !empty($sub['internship_role']) ? ' (' . h($sub['internship_role']) . ')' : ''; ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($sub['reviewer_name'])): ?>
                            <div class="meta-item" title="Faculty Evaluator">
                                <i class="fa-solid fa-user-check text-success"></i> 
                                <span>Evaluated by: <?php echo h($sub['reviewer_name']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="action-btn-group">
                        <div class="d-flex gap-2">
                            <?php if (!empty($sub['url'])): ?>
                                <a href="<?php echo h($sub['url']); ?>" target="_blank" rel="noopener noreferrer" class="btn-action btn-action-outline">
                                    <i class="fa-solid fa-arrow-up-right-from-square text-primary"></i> Open Link
                                </a>
                            <?php endif; ?>

                            <?php if (!empty($sub['file_path'])): ?>
                                <a href="view_showcase_file.php?id=<?php echo (int)$sub['id']; ?>" target="_blank" class="btn-action btn-action-outline">
                                    <i class="fa-solid fa-file-pdf text-danger"></i> View File
                                </a>
                            <?php endif; ?>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="button" class="btn-action btn-action-outline" 
                                    onclick='openEditModal(<?php echo json_encode($sub, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>
                                <i class="fa-solid fa-pen-to-square text-primary"></i> Edit
                            </button>
                            
                            <button type="button" class="btn-action btn-action-danger" 
                                    onclick="openDeleteModal(<?php echo (int)$sub['id']; ?>, '<?php echo h(addslashes($sub['title'])); ?>')">
                                <i class="fa-solid fa-trash-can"></i> Delete
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

</main>
</div>

<!-- ========================================== -->
<!-- MODAL 1: CREATE NEW SUBMISSION -->
<!-- ========================================== -->
<div class="modal fade" id="createModal" tabindex="-1" aria-labelledby="createModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="createModalLabel"><i class="fa-solid fa-cloud-arrow-up text-primary me-2"></i> Submit New Work</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="my_showcase.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo h($csrf_token); ?>">
                <input type="hidden" name="action" value="create_work">

                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Work Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control" placeholder="e.g. AI-Based Crop Disease Detection" required maxlength="255">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Category <span class="text-danger">*</span></label>
                            <select name="category" class="form-select" required>
                                <option value="">Select Category...</option>
                                <option value="Research Paper">Research Paper</option>
                                <option value="Research Project">Research Project</option>
                                <option value="Web Development">Web Development</option>
                                <option value="Mobile Application">Mobile Application</option>
                                <option value="Software Development">Software Development</option>
                                <option value="AI / Machine Learning">AI / Machine Learning</option>
                                <option value="Data Science / Analytics">Data Science / Analytics</option>
                                <option value="IoT / Embedded Systems">IoT / Embedded Systems</option>
                                <option value="Cyber Security">Cyber Security</option>
                                <option value="Cloud / DevOps">Cloud / DevOps</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description <span class="text-danger">*</span></label>
                        <textarea name="description" class="form-control" rows="4" placeholder="Briefly describe what you built, researched, or contributed..." required></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Project / Publication URL</label>
                            <input type="url" name="url" class="form-control" placeholder="https://github.com/... or live demo" maxlength="512">
                            <div class="form-text">GitHub repository, IEEE publication link, or live site.</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Upload Deliverable / File</label>
                            <input type="file" name="work_file" class="form-control" accept=".pdf,.doc,.docx,.ppt,.pptx,.zip,.png,.jpg,.jpeg">
                            <div class="form-text">Max 10MB. Formats: PDF, DOC, PPT, ZIP, PNG, JPG.</div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Technologies Used</label>
                            <input type="text" name="tech_used" class="form-control" placeholder="e.g. Python, React, TensorFlow" maxlength="255">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Associated Internship</label>
                            <select name="internship_id" class="form-select">
                                <option value="">Select Approved Internship (Optional)</option>
                                <?php foreach ($internships as $int): ?>
                                    <option value="<?php echo (int)$int['id']; ?>"><?php echo h($int['company_name'] . ' - ' . $int['internship_role']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4"><i class="fa-solid fa-paper-plane me-2"></i> Submit Work</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 2: EDIT SUBMISSION -->
<!-- ========================================== -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editModalLabel"><i class="fa-solid fa-pen-to-square text-primary me-2"></i> Edit Submission</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="my_showcase.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo h($csrf_token); ?>">
                <input type="hidden" name="action" value="edit_work">
                <input type="hidden" name="submission_id" id="edit_submission_id">

                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Work Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" id="edit_title" class="form-control" required maxlength="255">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Category <span class="text-danger">*</span></label>
                            <select name="category" id="edit_category" class="form-select" required>
                                <option value="">Select Category...</option>
                                <option value="Research Paper">Research Paper</option>
                                <option value="Research Project">Research Project</option>
                                <option value="Web Development">Web Development</option>
                                <option value="Mobile Application">Mobile Application</option>
                                <option value="Software Development">Software Development</option>
                                <option value="AI / Machine Learning">AI / Machine Learning</option>
                                <option value="Data Science / Analytics">Data Science / Analytics</option>
                                <option value="IoT / Embedded Systems">IoT / Embedded Systems</option>
                                <option value="Cyber Security">Cyber Security</option>
                                <option value="Cloud / DevOps">Cloud / DevOps</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description <span class="text-danger">*</span></label>
                        <textarea name="description" id="edit_description" class="form-control" rows="4" required></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Project / Publication URL</label>
                            <input type="url" name="url" id="edit_url" class="form-control" placeholder="https://..." maxlength="512">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Replace Attachment (Optional)</label>
                            <input type="file" name="work_file" class="form-control" accept=".pdf,.doc,.docx,.ppt,.pptx,.zip,.png,.jpg,.jpeg">
                            <div class="form-text" id="current_file_info">Leave empty to keep current file.</div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Technologies Used</label>
                            <input type="text" name="tech_used" id="edit_tech_used" class="form-control" maxlength="255">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Associated Internship</label>
                            <select name="internship_id" id="edit_internship_id" class="form-select">
                                <option value="">Select Approved Internship (Optional)</option>
                                <?php foreach ($internships as $int): ?>
                                    <option value="<?php echo (int)$int['id']; ?>"><?php echo h($int['company_name'] . ' - ' . $int['internship_role']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4"><i class="fa-solid fa-floppy-disk me-2"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 3: DELETE CONFIRMATION -->
<!-- ========================================== -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger bg-opacity-10 border-danger border-opacity-25">
                <h5 class="modal-title text-danger" id="deleteModalLabel"><i class="fa-solid fa-triangle-exclamation me-2"></i> Confirm Deletion</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="my_showcase.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo h($csrf_token); ?>">
                <input type="hidden" name="action" value="delete_work">
                <input type="hidden" name="submission_id" id="delete_submission_id">

                <div class="modal-body text-center py-4">
                    <i class="fa-solid fa-trash-can text-danger display-4 mb-3"></i>
                    <h5 class="fw-bold mb-2">Are you sure you want to delete this work?</h5>
                    <p class="text-muted mb-0">Submission: <strong id="delete_work_title" class="text-dark"></strong></p>
                    <p class="text-danger small mt-2"><i class="fa-solid fa-circle-exclamation me-1"></i> This action cannot be undone and attached files will be removed.</p>
                </div>

                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger px-4 fw-semibold"><i class="fa-solid fa-trash-can me-2"></i> Yes, Delete Work</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
function getOrCreateModal(modalId) {
    const el = document.getElementById(modalId);
    if (!el) return null;
    let modal = bootstrap.Modal.getInstance(el);
    if (!modal) {
        modal = new bootstrap.Modal(el, { backdrop: true, keyboard: true });
    }
    return modal;
}

function openEditModal(sub) {
    document.getElementById('edit_submission_id').value = sub.id || '';
    document.getElementById('edit_title').value = sub.title || '';
    document.getElementById('edit_category').value = sub.category || '';
    document.getElementById('edit_description').value = sub.description || '';
    document.getElementById('edit_url').value = sub.url || '';
    document.getElementById('edit_tech_used').value = sub.tech_used || '';
    document.getElementById('edit_internship_id').value = sub.internship_id || '';

    const fileInfo = document.getElementById('current_file_info');
    if (sub.file_path && sub.file_path.trim() !== '') {
        const fileName = sub.file_path.split('/').pop();
        fileInfo.innerHTML = `<span class="text-success"><i class="fa-solid fa-paperclip me-1"></i> Current file: <strong>${fileName}</strong>. Select a new file to replace it.</span>`;
    } else {
        fileInfo.innerHTML = 'Leave empty to keep current file.';
    }

    const editModal = getOrCreateModal('editModal');
    if (editModal) editModal.show();
}

function openDeleteModal(subId, subTitle) {
    document.getElementById('delete_submission_id').value = subId;
    document.getElementById('delete_work_title').innerText = subTitle;
    const deleteModal = getOrCreateModal('deleteModal');
    if (deleteModal) deleteModal.show();
}

function filterSubmissions() {
    const searchVal = document.getElementById('searchInput').value.toLowerCase().trim();
    const categoryVal = document.getElementById('categoryFilter').value;
    const statusVal = document.getElementById('statusFilter').value;

    const cards = document.querySelectorAll('#submissionsList .submission-card');
    let visibleCount = 0;

    cards.forEach(card => {
        const title = card.getAttribute('data-title') || '';
        const category = card.getAttribute('data-category') || '';
        const status = card.getAttribute('data-status') || '';
        const searchBlob = card.getAttribute('data-search') || '';

        let matchesSearch = searchVal === '' || searchBlob.includes(searchVal);
        let matchesCategory = categoryVal === '' || category === categoryVal;
        let matchesStatus = statusVal === '' || (statusVal === 'Reviewed' ? (status === 'Reviewed' || status === 'Approved') : status === statusVal);

        if (matchesSearch && matchesCategory && matchesStatus) {
            card.style.display = 'block';
            visibleCount++;
        } else {
            card.style.display = 'none';
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    // Ensure no leftover backdrop locks scrolling or clicks after hiding modals
    ['createModal', 'editModal', 'deleteModal'].forEach(id => {
        const modalEl = document.getElementById(id);
        if (modalEl) {
            modalEl.addEventListener('hidden.bs.modal', function () {
                if (document.querySelectorAll('.modal.show').length === 0) {
                    document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
                    document.body.classList.remove('modal-open');
                    document.body.style.overflow = '';
                    document.body.style.paddingRight = '';
                }
            });
        }
    });

    // Form submit loading states and duplicate click protection
    document.querySelectorAll('.modal form').forEach(form => {
        form.addEventListener('submit', function () {
            const btn = this.querySelector('button[type="submit"]');
            if (btn && !btn.disabled) {
                btn.disabled = true;
                const origText = btn.innerHTML;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i> Saving...';
                setTimeout(() => {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = origText;
                    }
                }, 8000);
            }
        });
    });
});
</script>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>
</body>
</html>
