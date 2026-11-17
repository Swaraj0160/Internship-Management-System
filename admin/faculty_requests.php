<?php
session_start();
require_once "../config.php";
require_once "../includes/mail_service.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$adminId = (int)($_SESSION['user_id'] ?? 0);
$activePage = 'faculty_requests.php';

// Generate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$successMsg = "";
$errorMsg = "";

// Handle Admin Action (Approve, Reject, Suspend, Reactivate)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $postedCsrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $postedCsrf)) {
        $errorMsg = "Security validation failed (Invalid CSRF token). Please try again.";
    } else {
        $action = $_POST['action'];
        $requestId = (int)($_POST['request_id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        $remarks = trim($_POST['remarks'] ?? '');

        // Fetch User and Request Details
        $stmt = $conn->prepare("
            SELECT r.id as request_id, u.id as user_id, u.full_name, u.email, r.status
            FROM faculty_approval_requests r
            JOIN users u ON r.user_id = u.id
            WHERE r.id = ? AND u.id = ?
            LIMIT 1
        ");
        $stmt->bind_param("ii", $requestId, $userId);
        $stmt->execute();
        $targetUser = $stmt->get_result()->fetch_assoc();

        if (!$targetUser) {
            $errorMsg = "Request or faculty user not found.";
        } else {
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
            $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

            if ($action === 'approve') {
                $now = date('Y-m-d H:i:s');
                $upd1 = $conn->prepare("UPDATE faculty_approval_requests SET status = 'approved', reviewed_by = ?, admin_remarks = ?, reviewed_at = ? WHERE id = ?");
                $upd1->bind_param("issi", $adminId, $remarks, $now, $requestId);
                $upd1->execute();

                $upd2 = $conn->prepare("UPDATE users SET account_status = 'active', email_verified = 1 WHERE id = ?");
                $upd2->bind_param("i", $userId);
                $upd2->execute();

                // Audit Log
                $audit = $conn->prepare("INSERT INTO audit_logs (actor_id, actor_role, action, target_id, target_type, ip_address, user_agent, details) VALUES (?, 'admin', 'approve_faculty', ?, 'user', ?, ?, ?)");
                $details = json_encode(['remarks' => $remarks, 'approved_email' => $targetUser['email']]);
                $audit->bind_param("iisss", $adminId, $userId, $ipAddress, $userAgent, $details);
                $audit->execute();

                // Dispatch Notification Email
                MailService::sendFacultyDecision($targetUser['email'], $targetUser['full_name'], 'approved', $remarks);

                $successMsg = "Faculty member <strong>" . htmlspecialchars($targetUser['full_name']) . "</strong> has been approved successfully.";
            } elseif ($action === 'reject') {
                $now = date('Y-m-d H:i:s');
                $upd1 = $conn->prepare("UPDATE faculty_approval_requests SET status = 'rejected', reviewed_by = ?, admin_remarks = ?, reviewed_at = ? WHERE id = ?");
                $upd1->bind_param("issi", $adminId, $remarks, $now, $requestId);
                $upd1->execute();

                $upd2 = $conn->prepare("UPDATE users SET account_status = 'rejected' WHERE id = ?");
                $upd2->bind_param("i", $userId);
                $upd2->execute();

                // Audit Log
                $audit = $conn->prepare("INSERT INTO audit_logs (actor_id, actor_role, action, target_id, target_type, ip_address, user_agent, details) VALUES (?, 'admin', 'reject_faculty', ?, 'user', ?, ?, ?)");
                $details = json_encode(['remarks' => $remarks, 'rejected_email' => $targetUser['email']]);
                $audit->bind_param("iisss", $adminId, $userId, $ipAddress, $userAgent, $details);
                $audit->execute();

                // Dispatch Rejection Email
                MailService::sendFacultyDecision($targetUser['email'], $targetUser['full_name'], 'rejected', $remarks);

                $successMsg = "Faculty request for <strong>" . htmlspecialchars($targetUser['full_name']) . "</strong> has been rejected.";
            } elseif ($action === 'suspend') {
                $upd = $conn->prepare("UPDATE users SET account_status = 'suspended' WHERE id = ?");
                $upd->bind_param("i", $userId);
                $upd->execute();

                $audit = $conn->prepare("INSERT INTO audit_logs (actor_id, actor_role, action, target_id, target_type, ip_address, user_agent, details) VALUES (?, 'admin', 'suspend_faculty', ?, 'user', ?, ?, ?)");
                $details = json_encode(['remarks' => $remarks]);
                $audit->bind_param("iisss", $adminId, $userId, $ipAddress, $userAgent, $details);
                $audit->execute();

                $successMsg = "Account for <strong>" . htmlspecialchars($targetUser['full_name']) . "</strong> has been suspended.";
            } elseif ($action === 'reactivate') {
                $upd = $conn->prepare("UPDATE users SET account_status = 'active' WHERE id = ?");
                $upd->bind_param("i", $userId);
                $upd->execute();

                $audit = $conn->prepare("INSERT INTO audit_logs (actor_id, actor_role, action, target_id, target_type, ip_address, user_agent, details) VALUES (?, 'admin', 'reactivate_faculty', ?, 'user', ?, ?, ?)");
                $details = json_encode(['remarks' => $remarks]);
                $audit->bind_param("iisss", $adminId, $userId, $ipAddress, $userAgent, $details);
                $audit->execute();

                $successMsg = "Account for <strong>" . htmlspecialchars($targetUser['full_name']) . "</strong> has been reactivated.";
            }
        }
    }
}

// Fetch Filter
$filter = trim($_GET['status'] ?? 'pending');
$whereClause = "";
if (in_array($filter, ['pending', 'approved', 'rejected', 'suspended'])) {
    if ($filter === 'suspended') {
        $whereClause = "WHERE u.account_status = 'suspended'";
    } else {
        $whereClause = "WHERE r.status = '$filter'";
    }
}

// Query Requests
$query = "
    SELECT r.id as request_id, r.department, r.designation, r.employee_id, r.phone,
           r.status as request_status, r.created_at as request_date, r.admin_remarks,
           u.id as user_id, u.full_name, u.email, u.email_verified, u.account_status
    FROM faculty_approval_requests r
    JOIN users u ON r.user_id = u.id
    $whereClause
    ORDER BY r.created_at DESC
";
$requestsRes = mysqli_query($conn, $query);

// Counts
$countPending = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM faculty_approval_requests WHERE status='pending'"))['c'] ?? 0;
$countApproved = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM faculty_approval_requests WHERE status='approved'"))['c'] ?? 0;
$countRejected = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM faculty_approval_requests WHERE status='rejected'"))['c'] ?? 0;
$countAll = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM faculty_approval_requests"))['c'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Registration Requests — InternHub Admin</title>
    <link rel="icon" type="image/png" href="../assets/images/mit_adt_crest.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <?php include "includes/sidebar_head.php"; ?>
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #f8fafc;
            color: #1e293b;
        }
        .page-header {
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            padding: 24px 32px;
            margin-bottom: 24px;
        }
        .page-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .content-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
            overflow: hidden;
            margin: 0 32px 32px 32px;
        }
        .table th {
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-weight: 700;
            color: #64748b;
            background: #f8fafc;
            padding: 14px 18px;
            border-bottom: 1px solid #e2e8f0;
        }
        .table td {
            padding: 16px 18px;
            vertical-align: middle;
            font-size: 0.88rem;
            border-bottom: 1px solid #f1f5f9;
        }
        .badge-status {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.76rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .badge-pending { background: #fef3c7; color: #b45309; }
        .badge-approved { background: #dcfce7; color: #15803d; }
        .badge-rejected { background: #fee2e2; color: #b91c1c; }
        .badge-suspended { background: #f1f5f9; color: #475569; }
        .filter-nav {
            display: flex;
            gap: 10px;
            padding: 16px 24px;
            border-bottom: 1px solid #e2e8f0;
            background: #ffffff;
        }
        .filter-btn {
            border: 1px solid #e2e8f0;
            background: #ffffff;
            color: #64748b;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 0.84rem;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .filter-btn.active {
            background: #6d28d9;
            color: #ffffff;
            border-color: #6d28d9;
        }
        .btn-action-sm {
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
    </style>
</head>
<body>

<?php include "includes/sidebar_nav.php"; ?>

<div class="page-header">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h1 class="page-title"><i class="fa-solid fa-user-clock text-primary me-2"></i>Faculty Registration Requests</h1>
            <p class="text-muted mb-0" style="font-size: 0.9rem;">Review and manage faculty self-registrations across MIT-ADT University departments.</p>
        </div>
        <div class="d-flex gap-2">
            <span class="badge bg-purple px-3 py-2 text-white" style="background: #6d28d9; font-size: 0.85rem; border-radius: 20px;">
                <i class="fa-solid fa-clock me-1"></i> <?php echo $countPending; ?> Pending Review
            </span>
        </div>
    </div>
</div>

<div class="content-card">
    <!-- Filter Nav -->
    <div class="filter-nav">
        <a href="faculty_requests.php?status=pending" class="filter-btn <?php echo ($filter === 'pending') ? 'active' : ''; ?>">
            <span>Pending</span>
            <span class="badge rounded-pill bg-light text-dark" style="font-size: 0.7rem;"><?php echo $countPending; ?></span>
        </a>
        <a href="faculty_requests.php?status=approved" class="filter-btn <?php echo ($filter === 'approved') ? 'active' : ''; ?>">
            <span>Approved</span>
            <span class="badge rounded-pill bg-light text-dark" style="font-size: 0.7rem;"><?php echo $countApproved; ?></span>
        </a>
        <a href="faculty_requests.php?status=rejected" class="filter-btn <?php echo ($filter === 'rejected') ? 'active' : ''; ?>">
            <span>Rejected</span>
            <span class="badge rounded-pill bg-light text-dark" style="font-size: 0.7rem;"><?php echo $countRejected; ?></span>
        </a>
        <a href="faculty_requests.php?status=all" class="filter-btn <?php echo ($filter === 'all') ? 'active' : ''; ?>">
            <span>All Requests</span>
            <span class="badge rounded-pill bg-light text-dark" style="font-size: 0.7rem;"><?php echo $countAll; ?></span>
        </a>
    </div>

    <!-- Alerts -->
    <?php if (!empty($successMsg)): ?>
        <div class="alert alert-success m-3" role="alert" style="border-radius: 12px;">
            <i class="fa-solid fa-circle-check me-2"></i><?php echo $successMsg; ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($errorMsg)): ?>
        <div class="alert alert-danger m-3" role="alert" style="border-radius: 12px;">
            <i class="fa-solid fa-triangle-exclamation me-2"></i><?php echo htmlspecialchars($errorMsg); ?>
        </div>
    <?php endif; ?>

    <!-- Table -->
    <div class="table-responsive">
        <table class="table mb-0">
            <thead>
                <tr>
                    <th>Faculty Member</th>
                    <th>Official Email</th>
                    <th>Department & Designation</th>
                    <th>Employee ID</th>
                    <th>Email Status</th>
                    <th>Registration Date</th>
                    <th>Status</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($requestsRes && mysqli_num_rows($requestsRes) > 0): ?>
                    <?php while ($req = mysqli_fetch_assoc($requestsRes)): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div style="width: 38px; height: 38px; border-radius: 50%; background: #ede9fe; color: #6d28d9; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.95rem;">
                                        <?php echo strtoupper(substr($req['full_name'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <div style="font-weight: 700; color: #0f172a;"><?php echo htmlspecialchars($req['full_name']); ?></div>
                                        <?php if (!empty($req['phone'])): ?>
                                            <div style="font-size: 0.78rem; color: #64748b;"><i class="fa-solid fa-phone me-1"></i><?php echo htmlspecialchars($req['phone']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <a href="mailto:<?php echo htmlspecialchars($req['email']); ?>" style="color: #6d28d9; font-weight: 500; text-decoration: none;">
                                    <?php echo htmlspecialchars($req['email']); ?>
                                </a>
                            </td>
                            <td>
                                <div style="font-weight: 600; color: #1e293b;"><?php echo htmlspecialchars($req['department']); ?></div>
                                <div style="font-size: 0.8rem; color: #64748b;"><?php echo htmlspecialchars($req['designation']); ?></div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border" style="font-family: monospace; font-size: 0.82rem;">
                                    <?php echo htmlspecialchars($req['employee_id'] ?: 'N/A'); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($req['email_verified'] == 1): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1" style="font-size: 0.76rem; border-radius: 12px;">
                                        <i class="fa-solid fa-circle-check me-1"></i>Verified
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1" style="font-size: 0.76rem; border-radius: 12px;">
                                        <i class="fa-solid fa-clock me-1"></i>Unverified
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="font-size: 0.82rem; color: #64748b;">
                                    <?php echo date('d M Y, h:i A', strtotime($req['request_date'])); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($req['request_status'] === 'pending'): ?>
                                    <span class="badge-status badge-pending"><i class="fa-solid fa-hourglass-half"></i>Pending</span>
                                <?php elseif ($req['request_status'] === 'approved'): ?>
                                    <span class="badge-status badge-approved"><i class="fa-solid fa-circle-check"></i>Approved</span>
                                <?php elseif ($req['request_status'] === 'rejected'): ?>
                                    <span class="badge-status badge-rejected"><i class="fa-solid fa-circle-xmark"></i>Rejected</span>
                                <?php else: ?>
                                    <span class="badge-status badge-suspended"><?php echo ucfirst($req['request_status']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;">
                                <div class="d-inline-flex gap-2">
                                    <?php if ($req['request_status'] === 'pending'): ?>
                                        <!-- Approve Form -->
                                        <form method="POST" action="faculty_requests.php?status=<?php echo htmlspecialchars($filter); ?>" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="request_id" value="<?php echo $req['request_id']; ?>">
                                            <input type="hidden" name="user_id" value="<?php echo $req['user_id']; ?>">
                                            <button type="submit" class="btn-action-sm" style="background: #16a34a; color: #fff;" onclick="return confirm('Approve this faculty account and grant full portal access?');">
                                                <i class="fa-solid fa-check"></i> Approve
                                            </button>
                                        </form>

                                        <!-- Reject Form -->
                                        <form method="POST" action="faculty_requests.php?status=<?php echo htmlspecialchars($filter); ?>" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <input type="hidden" name="request_id" value="<?php echo $req['request_id']; ?>">
                                            <input type="hidden" name="user_id" value="<?php echo $req['user_id']; ?>">
                                            <button type="submit" class="btn-action-sm" style="background: #dc2626; color: #fff;" onclick="return confirm('Reject this faculty registration request?');">
                                                <i class="fa-solid fa-xmark"></i> Reject
                                            </button>
                                        </form>
                                    <?php elseif ($req['request_status'] === 'approved'): ?>
                                        <form method="POST" action="faculty_requests.php?status=<?php echo htmlspecialchars($filter); ?>" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                            <input type="hidden" name="action" value="suspend">
                                            <input type="hidden" name="request_id" value="<?php echo $req['request_id']; ?>">
                                            <input type="hidden" name="user_id" value="<?php echo $req['user_id']; ?>">
                                            <button type="submit" class="btn-action-sm" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;" onclick="return confirm('Temporarily suspend this faculty account?');">
                                                <i class="fa-solid fa-pause"></i> Suspend
                                            </button>
                                        </form>
                                    <?php elseif ($req['request_status'] === 'rejected' || $req['account_status'] === 'suspended'): ?>
                                        <form method="POST" action="faculty_requests.php?status=<?php echo htmlspecialchars($filter); ?>" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                            <input type="hidden" name="action" value="reactivate">
                                            <input type="hidden" name="request_id" value="<?php echo $req['request_id']; ?>">
                                            <input type="hidden" name="user_id" value="<?php echo $req['user_id']; ?>">
                                            <button type="submit" class="btn-action-sm" style="background: #6d28d9; color: #fff;" onclick="return confirm('Reactivate this faculty member account?');">
                                                <i class="fa-solid fa-arrow-rotate-right"></i> Reactivate
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="fa-solid fa-inbox fa-3x mb-3 text-secondary" style="opacity: 0.5;"></i>
                            <div style="font-size: 1rem; font-weight: 600;">No faculty registration requests found</div>
                            <div style="font-size: 0.84rem;">New faculty requests requiring administrative approval will appear here.</div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</main>
</div>

<?php include "includes/sidebar_scripts.php"; ?>
</body>
</html>
