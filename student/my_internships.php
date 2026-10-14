<?php
session_start();
include("../config.php");

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

$student_id = (int)$_SESSION['user_id'];
$activePage = 'my_internships.php';

// Generate CSRF token for secure post actions
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Fetch Student division for offer letter file path calculation
$stu_stmt = $conn->prepare("SELECT division, full_name FROM users WHERE id = ? LIMIT 1");
$stu_stmt->bind_param("i", $student_id);
$stu_stmt->execute();
$stu_data = $stu_stmt->get_result()->fetch_assoc() ?: [];
$stu_stmt->close();
$student_division = trim($stu_data['division'] ?? '');

// Handle Withdraw / Delete Application Action (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf_token, $posted_csrf)) {
        $_SESSION['internship_error'] = "Security validation failed. Please refresh and try again.";
        header("Location: my_internships.php");
        exit();
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'withdraw_application') {
        $app_id = (int)($_POST['application_id'] ?? 0);

        // Fetch application & verify ownership
        $chk_stmt = $conn->prepare("SELECT * FROM internships WHERE id = ? AND student_id = ?");
        $chk_stmt->bind_param("ii", $app_id, $student_id);
        $chk_stmt->execute();
        $existing = $chk_stmt->get_result()->fetch_assoc();
        $chk_stmt->close();

        if (!$existing) {
            $_SESSION['internship_error'] = "Unauthorized action or application does not exist.";
            header("Location: my_internships.php");
            exit();
        }

        if ($existing['status'] !== 'Pending') {
            $_SESSION['internship_error'] = "Only pending applications can be withdrawn.";
            header("Location: my_internships.php");
            exit();
        }

        // Remove offer letter file if present
        if (!empty($existing['offer_letter'])) {
            $file_basename = basename($existing['offer_letter']);
            $offer_path = __DIR__ . '/../uploads/offer_letters/' . $student_division . '/' . $file_basename;
            if (file_exists($offer_path) && is_file($offer_path)) {
                @unlink($offer_path);
            }
        }

        // Delete application record
        $del_stmt = $conn->prepare("DELETE FROM internships WHERE id = ? AND student_id = ?");
        $del_stmt->bind_param("ii", $app_id, $student_id);
        if ($del_stmt->execute()) {
            $_SESSION['internship_success'] = "Internship application for '" . h($existing['company_name']) . "' has been withdrawn.";
        } else {
            $_SESSION['internship_error'] = "Failed to withdraw application. Please try again.";
        }
        $del_stmt->close();

        header("Location: my_internships.php");
        exit();
    }
}

// Fetch All Student Internship Applications
$applications = [];
$stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];

$query = "SELECT * FROM internships WHERE student_id = ? ORDER BY created_at DESC";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $applications[] = $row;
    $stats['total']++;
    $st = strtolower($row['status']);
    if ($st === 'approved') {
        $stats['approved']++;
    } elseif ($st === 'rejected') {
        $stats['rejected']++;
    } else {
        $stats['pending']++;
    }
}
$stmt->close();

// Retain session alert messages
$success_msg = $_SESSION['internship_success'] ?? null;
$error_msg = $_SESSION['internship_error'] ?? null;
unset($_SESSION['internship_success'], $_SESSION['internship_error']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Internship Applications | MIT-ADT University</title>
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

        /* Search & Filter Toolbar */
        .filter-toolbar {
            background: var(--surface);
            border-radius: 14px;
            padding: 16px 20px;
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        /* Application Showcase Cards */
        .app-card {
            background: var(--surface);
            border-radius: 16px;
            border: 1px solid var(--border-color);
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.03);
            transition: all 0.2s ease;
            position: relative;
        }

        .app-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 12px 24px -6px rgba(0, 0, 0, 0.08);
            transform: translateY(-2px);
        }

        .app-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .company-name {
            font-size: 1.3rem;
            font-weight: 800;
            color: var(--text-dark);
            margin: 0 0 4px 0;
            line-height: 1.3;
        }

        .intern-role {
            font-size: 0.95rem;
            font-weight: 600;
            color: #475569;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .status-pill {
            font-size: 0.78rem;
            font-weight: 700;
            padding: 6px 14px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            white-space: nowrap;
        }

        .status-pending { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
        .status-approved { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
        .status-rejected { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 14px;
            margin-bottom: 18px;
            padding: 16px;
            background: #f8fafc;
            border-radius: 12px;
            border: 1px solid #f1f5f9;
        }

        .grid-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 0.88rem;
        }

        .grid-item i {
            font-size: 1.05rem;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .grid-item .item-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            font-weight: 700;
            color: var(--text-muted);
            letter-spacing: 0.4px;
            display: block;
            margin-bottom: 2px;
        }

        .grid-item .item-val {
            font-weight: 600;
            color: #334155;
            word-break: break-word;
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

        .btn-action-warning {
            background: #f59e0b;
            color: white;
            border: none;
        }
        .btn-action-warning:hover {
            background: #d97706;
            color: white;
        }

        .btn-action-danger {
            background: #ef4444;
            color: white;
            border: none;
        }
        .btn-action-danger:hover {
            background: #dc2626;
            color: white;
        }

        .btn-action-danger-outline {
            background: #fff;
            border: 1px solid #fecaca;
            color: #dc2626;
        }
        .btn-action-danger-outline:hover {
            background: #fef2f2;
            border-color: #fca5a5;
        }

        /* Empty State Box */
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

        /* Modals Z-Index Fix */
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

        @media (max-width: 768px) {
            .page-header-box {
                padding: 24px;
            }
            .page-title {
                font-size: 1.5rem;
            }
            .details-grid {
                grid-template-columns: 1fr;
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
            <h1 class="page-title"><i class="fa-solid fa-briefcase me-2"></i> My Internship Applications</h1>
            <p class="page-subtitle">Track your internship applications, review approval status, access mentor feedback, and manage your offer letters.</p>
        </div>
        <div>
            <a href="apply_internship.php" class="btn btn-light fw-bold px-4 py-2 shadow-sm text-primary rounded-3">
                <i class="fa-solid fa-plus me-2"></i> Apply for Internship
            </a>
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

    <!-- Summary Stats Bar -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                    <i class="fa-solid fa-folder-open"></i>
                </div>
                <div>
                    <div class="stat-val"><?php echo $stats['total']; ?></div>
                    <div class="stat-lbl">Total Applied</div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                    <i class="fa-solid fa-hourglass-half"></i>
                </div>
                <div>
                    <div class="stat-val"><?php echo $stats['pending']; ?></div>
                    <div class="stat-lbl">Pending Review</div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="stat-icon bg-success bg-opacity-10 text-success">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div>
                    <div class="stat-val"><?php echo $stats['approved']; ?></div>
                    <div class="stat-lbl">Approved</div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                    <i class="fa-solid fa-circle-xmark"></i>
                </div>
                <div>
                    <div class="stat-val"><?php echo $stats['rejected']; ?></div>
                    <div class="stat-lbl">Rejected</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter & Search Toolbar -->
    <div class="filter-toolbar">
        <div class="row g-3 align-items-center">
            <div class="col-md-4">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0 text-muted"><i class="fa-solid fa-magnifying-glass"></i></span>
                    <input type="text" id="searchInput" class="form-control border-start-0 ps-0" placeholder="Search company, role, project, mentor..." onkeyup="filterApplications()">
                </div>
            </div>
            <div class="col-md-3 col-6">
                <select id="statusFilter" class="form-select" onchange="filterApplications()">
                    <option value="">All Statuses</option>
                    <option value="Pending">Pending Review</option>
                    <option value="Approved">Approved</option>
                    <option value="Rejected">Rejected</option>
                </select>
            </div>
            <div class="col-md-3 col-6">
                <select id="modeFilter" class="form-select" onchange="filterApplications()">
                    <option value="">All Work Modes</option>
                    <option value="Online">Online</option>
                    <option value="Offline">Offline</option>
                    <option value="Hybrid">Hybrid</option>
                </select>
            </div>
            <div class="col-md-2 text-end">
                <button type="button" class="btn btn-outline-secondary w-100 fw-semibold" onclick="resetFilters()">
                    <i class="fa-solid fa-rotate-left me-1"></i> Reset
                </button>
            </div>
        </div>
    </div>

    <!-- Applications Container -->
    <div id="applicationsList">
        <?php if (empty($applications)): ?>
            <!-- Empty State -->
            <div class="empty-state-box">
                <div class="empty-icon">
                    <i class="fa-solid fa-briefcase"></i>
                </div>
                <h3 class="fw-bold mb-2">No Internship Applications Found</h3>
                <p class="text-muted max-w-md mx-auto mb-4" style="max-width:480px;">Submit your internship details to request university approval, upload offer letters, and manage your weekly report logs.</p>
                <a href="apply_internship.php" class="btn btn-primary fw-semibold px-4 py-2">
                    <i class="fa-solid fa-plus me-2"></i> Apply for Internship
                </a>
            </div>
        <?php else: ?>
            <?php foreach ($applications as $app): 
                $status = $app['status'] ?? 'Pending';
                $statusClass = 'status-pending';
                $statusIcon = 'fa-hourglass-half';

                if ($status === 'Approved') {
                    $statusClass = 'status-approved';
                    $statusIcon = 'fa-circle-check';
                } elseif ($status === 'Rejected') {
                    $statusClass = 'status-rejected';
                    $statusIcon = 'fa-circle-xmark';
                }

                $offerLetterPath = '';
                if (!empty($app['offer_letter'])) {
                    $offerLetterPath = '../uploads/offer_letters/' . rawurlencode($student_division) . '/' . rawurlencode(basename($app['offer_letter']));
                }
            ?>
                <div class="app-card" 
                     data-company="<?php echo h(strtolower($app['company_name'])); ?>"
                     data-role="<?php echo h(strtolower($app['internship_role'])); ?>"
                     data-status="<?php echo h($status); ?>"
                     data-mode="<?php echo h($app['mode'] ?? ''); ?>"
                     data-type="<?php echo h($app['internship_type'] ?? ''); ?>"
                     data-search="<?php echo h(strtolower($app['company_name'] . ' ' . $app['internship_role'] . ' ' . $app['mini_project_title'] . ' ' . $app['mentor_name'])); ?>">
                    
                    <div class="app-header">
                        <div>
                            <h3 class="company-name"><?php echo h($app['company_name']); ?></h3>
                            <div class="intern-role">
                                <i class="fa-solid fa-user-gear text-primary"></i> 
                                <span><?php echo h($app['internship_role']); ?></span>
                            </div>
                        </div>
                        <div>
                            <span class="status-pill <?php echo $statusClass; ?>">
                                <i class="fa-solid <?php echo $statusIcon; ?>"></i> <?php echo h($status); ?>
                            </span>
                        </div>
                    </div>

                    <div class="details-grid">
                        <?php if (!empty($app['mini_project_title'])): ?>
                            <div class="grid-item">
                                <i class="fa-solid fa-lightbulb text-warning"></i>
                                <div>
                                    <span class="item-label">Mini Project Title</span>
                                    <span class="item-val"><?php echo h($app['mini_project_title']); ?></span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="grid-item">
                            <i class="fa-solid fa-layer-group text-primary"></i>
                            <div>
                                <span class="item-label">Internship Type</span>
                                <span class="item-val"><?php echo h($app['internship_type']); ?></span>
                            </div>
                        </div>

                        <div class="grid-item">
                            <i class="fa-solid fa-laptop-house text-indigo"></i>
                            <div>
                                <span class="item-label">Work Mode</span>
                                <span class="item-val"><?php echo h($app['mode']); ?></span>
                            </div>
                        </div>

                        <div class="grid-item">
                            <i class="fa-solid fa-calendar-days text-info"></i>
                            <div>
                                <span class="item-label">Duration</span>
                                <span class="item-val"><?php echo date('d M Y', strtotime($app['start_date'])); ?> to <?php echo date('d M Y', strtotime($app['end_date'])); ?></span>
                            </div>
                        </div>

                        <div class="grid-item">
                            <i class="fa-solid fa-user-tie text-secondary"></i>
                            <div>
                                <span class="item-label">Mentor Name</span>
                                <span class="item-val"><?php echo h($app['mentor_name']); ?></span>
                            </div>
                        </div>

                        <div class="grid-item">
                            <i class="fa-solid fa-file-contract text-success"></i>
                            <div>
                                <span class="item-label">Offer Letter</span>
                                <span class="item-val">
                                    <?php if (!empty($offerLetterPath)): ?>
                                        <span class="text-success"><i class="fa-solid fa-circle-check me-1"></i> Uploaded</span>
                                    <?php else: ?>
                                        <span class="text-muted"><i class="fa-solid fa-minus me-1"></i> Not Uploaded</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Rejection Alert Callout if Rejected -->
                    <?php if ($status === 'Rejected' && !empty($app['rejection_reason'])): ?>
                        <div class="alert alert-danger mb-3 rounded-3 shadow-sm py-2 px-3">
                            <i class="fa-solid fa-circle-exclamation me-2"></i> <strong>Rejection Reason:</strong> <?php echo h($app['rejection_reason']); ?>
                        </div>
                    <?php endif; ?>

                    <div class="submission-meta">
                        <div>
                            <i class="fa-regular fa-calendar-check text-primary me-1"></i>
                            <span>Applied Date: <?php echo date('d M Y, h:i A', strtotime($app['created_at'])); ?></span>
                        </div>
                        <?php if (!empty($app['stipend'])): ?>
                            <div>
                                <i class="fa-solid fa-indian-rupee-sign text-success me-1"></i>
                                <span>Stipend: <?php echo h($app['stipend']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="action-btn-group">
                        <div class="d-flex gap-2 flex-wrap">
                            <?php if (!empty($offerLetterPath)): ?>
                                <a href="<?php echo $offerLetterPath; ?>" target="_blank" class="btn-action btn-action-primary">
                                    <i class="fa-solid fa-file-pdf"></i> View Offer Letter
                                </a>
                            <?php endif; ?>

                            <button type="button" class="btn-action btn-action-outline" 
                                    onclick='openDetailsModal(<?php echo json_encode($app, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>, "<?php echo h($offerLetterPath); ?>")'>
                                <i class="fa-solid fa-eye text-primary"></i> View Details
                            </button>
                        </div>

                        <div class="d-flex gap-2 flex-wrap">
                            <?php if ($status === 'Pending'): ?>
                                <a href="apply_internship.php?edit=<?php echo (int)$app['id']; ?>" class="btn-action btn-action-warning">
                                    <i class="fa-solid fa-pen-to-square"></i> Edit
                                </a>
                                <button type="button" class="btn-action btn-action-danger-outline" 
                                        onclick="openWithdrawModal(<?php echo (int)$app['id']; ?>, '<?php echo h(addslashes($app['company_name'])); ?>')">
                                    <i class="fa-solid fa-ban"></i> Withdraw
                                </button>
                            <?php elseif ($status === 'Rejected'): ?>
                                <a href="apply_internship.php?edit=<?php echo (int)$app['id']; ?>" class="btn-action btn-action-danger">
                                    <i class="fa-solid fa-rotate-left"></i> Edit & Resubmit
                                </a>
                            <?php else: ?>
                                <a href="apply_internship.php?edit=<?php echo (int)$app['id']; ?>" class="btn-action btn-action-outline">
                                    <i class="fa-solid fa-pen-to-square text-primary"></i> Edit Details
                                </a>
                            <?php endif; ?>
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
<!-- MODAL 1: VIEW DETAILS MODAL -->
<!-- ========================================== -->
<div class="modal fade" id="detailsModal" tabindex="-1" aria-labelledby="detailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="detailsModalLabel"><i class="fa-solid fa-circle-info text-primary me-2"></i> Application Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label text-muted small text-uppercase fw-bold">Company Name</label>
                        <div class="fw-bold fs-6" id="det_company"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small text-uppercase fw-bold">Internship Role</label>
                        <div class="fw-bold fs-6" id="det_role"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small text-uppercase fw-bold">Application Status</label>
                        <div id="det_status"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small text-uppercase fw-bold">Internship Type</label>
                        <div class="fw-semibold" id="det_type"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small text-uppercase fw-bold">Work Mode</label>
                        <div class="fw-semibold" id="det_mode"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small text-uppercase fw-bold">Company Contact</label>
                        <div class="fw-semibold" id="det_contact"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small text-uppercase fw-bold">Start Date</label>
                        <div class="fw-semibold" id="det_start"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small text-uppercase fw-bold">End Date</label>
                        <div class="fw-semibold" id="det_end"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small text-uppercase fw-bold">Mentor Name</label>
                        <div class="fw-semibold" id="det_mentor"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small text-uppercase fw-bold">Stipend</label>
                        <div class="fw-semibold" id="det_stipend"></div>
                    </div>
                    <div class="col-12" id="det_project_box">
                        <label class="form-label text-muted small text-uppercase fw-bold">Mini Project Title</label>
                        <div class="fw-semibold text-dark p-3 bg-light rounded-3" id="det_project"></div>
                    </div>
                    <div class="col-12" id="det_rejection_box" style="display:none;">
                        <label class="form-label text-danger small text-uppercase fw-bold">Rejection Feedback</label>
                        <div class="alert alert-danger mb-0" id="det_rejection"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer" id="det_footer">
                <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 2: WITHDRAW APPLICATION MODAL -->
<!-- ========================================== -->
<div class="modal fade" id="withdrawModal" tabindex="-1" aria-labelledby="withdrawModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger bg-opacity-10 border-danger border-opacity-25">
                <h5 class="modal-title text-danger" id="withdrawModalLabel"><i class="fa-solid fa-triangle-exclamation me-2"></i> Withdraw Application</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="my_internships.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo h($csrf_token); ?>">
                <input type="hidden" name="action" value="withdraw_application">
                <input type="hidden" name="application_id" id="withdraw_application_id">

                <div class="modal-body text-center py-4">
                    <i class="fa-solid fa-ban text-danger display-4 mb-3"></i>
                    <h5 class="fw-bold mb-2">Are you sure you want to withdraw this application?</h5>
                    <p class="text-muted mb-0">Company: <strong id="withdraw_company_name" class="text-dark"></strong></p>
                    <p class="text-danger small mt-2"><i class="fa-solid fa-circle-exclamation me-1"></i> This action will remove your pending application record from the portal.</p>
                </div>

                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger px-4 fw-semibold"><i class="fa-solid fa-ban me-2"></i> Yes, Withdraw</button>
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

function openDetailsModal(app, offerPath) {
    document.getElementById('det_company').innerText = app.company_name || '—';
    document.getElementById('det_role').innerText = app.internship_role || '—';
    document.getElementById('det_status').innerHTML = `<span class="badge bg-${app.status==='Approved'?'success':(app.status==='Rejected'?'danger':'warning')} px-3 py-2 fs-6">${app.status || 'Pending'}</span>`;
    document.getElementById('det_type').innerText = app.internship_type || '—';
    document.getElementById('det_mode').innerText = app.mode || '—';
    document.getElementById('det_contact').innerText = app.company_contact || '—';
    document.getElementById('det_start').innerText = app.start_date || '—';
    document.getElementById('det_end').innerText = app.end_date || '—';
    document.getElementById('det_mentor').innerText = app.mentor_name || '—';
    document.getElementById('det_stipend').innerText = app.stipend || 'Unpaid / N/A';

    const projBox = document.getElementById('det_project_box');
    if (app.mini_project_title && app.mini_project_title.trim() !== '') {
        document.getElementById('det_project').innerText = app.mini_project_title;
        projBox.style.display = 'block';
    } else {
        projBox.style.display = 'none';
    }

    const rejBox = document.getElementById('det_rejection_box');
    if (app.status === 'Rejected' && app.rejection_reason && app.rejection_reason.trim() !== '') {
        document.getElementById('det_rejection').innerText = app.rejection_reason;
        rejBox.style.display = 'block';
    } else {
        rejBox.style.display = 'none';
    }

    const footer = document.getElementById('det_footer');
    let offerBtn = '';
    if (offerPath && offerPath.trim() !== '') {
        offerBtn = `<a href="${offerPath}" target="_blank" class="btn btn-primary px-4"><i class="fa-solid fa-file-pdf me-2"></i> View Offer Letter</a>`;
    }
    footer.innerHTML = `${offerBtn}<button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Close</button>`;

    const modal = getOrCreateModal('detailsModal');
    if (modal) modal.show();
}

function openWithdrawModal(appId, companyName) {
    document.getElementById('withdraw_application_id').value = appId;
    document.getElementById('withdraw_company_name').innerText = companyName;
    const modal = getOrCreateModal('withdrawModal');
    if (modal) modal.show();
}

function filterApplications() {
    const searchVal = document.getElementById('searchInput').value.toLowerCase().trim();
    const statusVal = document.getElementById('statusFilter').value;
    const modeVal = document.getElementById('modeFilter').value;

    const cards = document.querySelectorAll('#applicationsList .app-card');

    cards.forEach(card => {
        const company = card.getAttribute('data-company') || '';
        const role = card.getAttribute('data-role') || '';
        const status = card.getAttribute('data-status') || '';
        const mode = card.getAttribute('data-mode') || '';
        const searchBlob = card.getAttribute('data-search') || '';

        let matchesSearch = searchVal === '' || searchBlob.includes(searchVal);
        let matchesStatus = statusVal === '' || status === statusVal;
        let matchesMode = modeVal === '' || mode === modeVal;

        if (matchesSearch && matchesStatus && matchesMode) {
            card.style.display = 'block';
        } else {
            card.style.display = 'none';
        }
    });
}

function resetFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('statusFilter').value = '';
    document.getElementById('modeFilter').value = '';
    filterApplications();
}

document.addEventListener('DOMContentLoaded', function () {
    // Backdrop cleanup listener
    ['detailsModal', 'withdrawModal'].forEach(id => {
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
});
</script>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>
</body>
</html>
