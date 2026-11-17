<?php
// --- Legacy Router Bridge ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $target = 'system_settings.php';
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    if (!empty($qs)) {
        $sep = (strpos($target, '?') !== false) ? '&' : '?';
        $target .= $sep . $qs;
    }
    header("Location: " . $target);
    exit();
}
// --- End Legacy Router Bridge ---

session_start();
require_once "../config.php";
require_once "../includes/StorageManager.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$adminId = (int)($_SESSION['user_id'] ?? 0);
$activePage = 'storage_monitoring.php';

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$successMsg = "";
$errorMsg = "";

// Handle Quota Adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_quota'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errorMsg = "Security validation failed. Please try again.";
    } else {
        $targetUserId = (int)$_POST['target_user_id'];
        $newQuotaMb = (int)$_POST['new_quota_mb'];
        if ($newQuotaMb >= 5 && $newQuotaMb <= 500) {
            $newQuotaBytes = $newQuotaMb * 1024 * 1024;
            $upd = $conn->prepare("INSERT INTO storage_quotas (user_id, max_quota_bytes) VALUES (?, ?) ON DUPLICATE KEY UPDATE max_quota_bytes = ?");
            $upd->bind_param("iii", $targetUserId, $newQuotaBytes, $newQuotaBytes);
            if ($upd->execute()) {
                $audit = $conn->prepare("INSERT INTO audit_logs (actor_id, actor_role, action, target_id, target_type, details) VALUES (?, 'admin', 'update_storage_quota', ?, 'user', ?)");
                $details = json_encode(['new_quota_mb' => $newQuotaMb]);
                $audit->bind_param("iis", $adminId, $targetUserId, $details);
                $audit->execute();
                $successMsg = "Storage allowance updated to {$newQuotaMb}MB successfully.";
            } else {
                $errorMsg = "Database error while updating quota.";
            }
        } else {
            $errorMsg = "Quota must be between 5MB and 500MB.";
        }
    }
}

// Global Storage Usage
$globalStats = StorageManager::getGlobalStorageUsage($conn);

// Category Breakdown
$catQuery = "
    SELECT category, COUNT(*) as file_count, SUM(size_bytes) as total_bytes
    FROM storage_objects
    WHERE deleted_at IS NULL
    GROUP BY category
    ORDER BY total_bytes DESC
";
$catRes = mysqli_query($conn, $catQuery);

// Student Quotas
$studentsQuery = "
    SELECT q.user_id, q.used_bytes, q.max_quota_bytes, q.file_count,
           u.full_name, u.email, u.enrollment_no, u.department
    FROM storage_quotas q
    JOIN users u ON q.user_id = u.id
    ORDER BY q.used_bytes DESC
    LIMIT 50
";
$studentsRes = mysqli_query($conn, $studentsQuery);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Storage & Quota Monitoring — InternHub Admin</title>
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
        .content-container {
            padding: 0 32px 32px 32px;
        }
        .metric-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            padding: 24px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
            margin-bottom: 24px;
        }
        .metric-label {
            font-size: 0.82rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 6px;
        }
        .metric-value {
            font-size: 2rem;
            font-weight: 800;
            color: #0f172a;
        }
        .progress-custom {
            height: 12px;
            border-radius: 8px;
            background: #f1f5f9;
            overflow: hidden;
            margin: 12px 0 6px 0;
        }
        .threshold-pill {
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
        }
        .table th {
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-weight: 700;
            color: #64748b;
            background: #f8fafc;
            padding: 12px 16px;
            border-bottom: 1px solid #e2e8f0;
        }
        .table td {
            padding: 14px 16px;
            vertical-align: middle;
            font-size: 0.88rem;
            border-bottom: 1px solid #f1f5f9;
        }
    </style>
</head>
<body>

<?php include "includes/sidebar_nav.php"; ?>

<div class="page-header">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h1 class="page-title"><i class="fa-solid fa-cloud-arrow-up text-primary me-2"></i>Storage & Quota Management</h1>
            <p class="text-muted mb-0" style="font-size: 0.9rem;">Cloudflare R2 object storage monitoring, threshold alarms, and per-student quota allocation.</p>
        </div>
        <div class="d-flex gap-2">
            <span class="badge bg-purple px-3 py-2 text-white" style="background: #6d28d9; font-size: 0.85rem; border-radius: 20px;">
                <i class="fa-solid fa-server me-1"></i> R2 Free Tier: 10 GB Budget
            </span>
        </div>
    </div>
</div>

<div class="content-container">

    <!-- Capacity Warning Banner if >= 70% -->
    <?php if ($globalStats['is_critical_95']): ?>
        <div class="alert alert-danger d-flex align-items-center gap-3 p-3 mb-4" role="alert" style="border-radius: 14px;">
            <i class="fa-solid fa-triangle-exclamation fa-2x"></i>
            <div>
                <strong>CRITICAL CAPACITY ALERT (95% Budget Reached):</strong>
                Storage usage is at <?php echo $globalStats['percent_used']; ?>%. Non-essential uploads (covers, project screenshots) have been restricted to preserve quota for mandatory weekly reports and offer letters.
            </div>
        </div>
    <?php elseif ($globalStats['is_warning_85']): ?>
        <div class="alert alert-warning d-flex align-items-center gap-3 p-3 mb-4" role="alert" style="border-radius: 14px;">
            <i class="fa-solid fa-circle-exclamation fa-2x"></i>
            <div>
                <strong>STORAGE WARNING (85% Budget Reached):</strong>
                Total storage has consumed <?php echo $globalStats['percent_used']; ?>% of the 10GB free allowance. Consider auditing legacy archives or scaling bucket tiers.
            </div>
        </div>
    <?php endif; ?>

    <!-- Feedback Alerts -->
    <?php if (!empty($successMsg)): ?>
        <div class="alert alert-success" role="alert" style="border-radius: 12px;">
            <i class="fa-solid fa-circle-check me-2"></i><?php echo $successMsg; ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($errorMsg)): ?>
        <div class="alert alert-danger" role="alert" style="border-radius: 12px;">
            <i class="fa-solid fa-triangle-exclamation me-2"></i><?php echo htmlspecialchars($errorMsg); ?>
        </div>
    <?php endif; ?>

    <!-- Global Metric Cards -->
    <div class="row g-4 mb-4">
        <!-- Budget Progress -->
        <div class="col-lg-6">
            <div class="metric-card h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="metric-label">Global R2 Storage Allowance</div>
                    <span class="threshold-pill <?php echo ($globalStats['percent_used'] >= 95) ? 'bg-danger text-white' : (($globalStats['percent_used'] >= 70) ? 'bg-warning text-dark' : 'bg-success text-white'); ?>">
                        <?php echo $globalStats['percent_used']; ?>% Used
                    </span>
                </div>
                <div class="d-flex align-items-baseline gap-2 mt-2">
                    <div class="metric-value"><?php echo $globalStats['total_mb']; ?> <span style="font-size: 1rem; color: #64748b;">MB</span></div>
                    <div class="text-muted">/ 10,240 MB (10 GB)</div>
                </div>
                <div class="progress-custom">
                    <div class="progress-bar" role="progressbar" style="width: <?php echo min(100, $globalStats['percent_used']); ?>%; background: <?php echo ($globalStats['percent_used'] >= 95) ? '#ef4444' : (($globalStats['percent_used'] >= 70) ? '#f59e0b' : '#6d28d9'); ?>;" aria-valuenow="<?php echo $globalStats['percent_used']; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
                <div class="d-flex justify-content-between text-muted" style="font-size: 0.78rem;">
                    <span>0 GB</span>
                    <span>70% Warn</span>
                    <span>85% Warn</span>
                    <span>95% Restrict</span>
                    <span>10 GB Cap</span>
                </div>
            </div>
        </div>

        <!-- Student Quota Matrix -->
        <div class="col-lg-3">
            <div class="metric-card h-100">
                <div class="metric-label">Per-Student Allowance</div>
                <div class="metric-value">25 <span style="font-size: 1rem; color: #64748b;">MB</span></div>
                <p class="text-muted mt-2 mb-0" style="font-size: 0.84rem;">Enforced at database layer for student profile, resume & submissions.</p>
            </div>
        </div>

        <!-- Total Managed Files -->
        <div class="col-lg-3">
            <div class="metric-card h-100">
                <div class="metric-label">Registered Files</div>
                <div class="metric-value"><?php echo number_format($globalStats['total_files']); ?></div>
                <p class="text-muted mt-2 mb-0" style="font-size: 0.84rem;">Across Cloudflare R2 and legacy local directories.</p>
            </div>
        </div>
    </div>

    <!-- Category Breakdown Table -->
    <div class="card border-0 rounded-4 shadow-sm mb-4 overflow-hidden">
        <div class="card-header bg-white py-3 border-bottom">
            <h5 class="mb-0 fw-bold" style="font-size: 1.05rem;"><i class="fa-solid fa-chart-pie text-primary me-2"></i>Storage Consumption by Category</h5>
        </div>
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>File Count</th>
                        <th>Total Size (MB)</th>
                        <th>Category Limit</th>
                        <th>Visibility</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($catRes && mysqli_num_rows($catRes) > 0): ?>
                        <?php while ($c = mysqli_fetch_assoc($catRes)): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-light text-dark border px-3 py-2 fw-bold" style="font-size: 0.85rem;">
                                        <?php echo htmlspecialchars($c['category']); ?>
                                    </span>
                                </td>
                                <td><strong><?php echo number_format($c['file_count']); ?></strong> files</td>
                                <td><strong><?php echo round($c['total_bytes'] / (1024 * 1024), 2); ?></strong> MB</td>
                                <td>
                                    <span style="font-size: 0.82rem; color: #64748b;">
                                        Max <?php echo StorageManager::LIMITS[$c['category']]['max_size'] / (1024 * 1024); ?>MB / file
                                    </span>
                                </td>
                                <td>
                                    <?php if ((StorageManager::LIMITS[$c['category']]['visibility'] ?? 'private') === 'private'): ?>
                                        <span class="badge bg-secondary-subtle text-secondary px-2 py-1"><i class="fa-solid fa-lock me-1"></i>Private</span>
                                    <?php else: ?>
                                        <span class="badge bg-success-subtle text-success px-2 py-1"><i class="fa-solid fa-globe me-1"></i>Public</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center py-4 text-muted">No files registered in object storage registry yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Student Storage Quotas Table -->
    <div class="card border-0 rounded-4 shadow-sm overflow-hidden">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold" style="font-size: 1.05rem;"><i class="fa-solid fa-users text-primary me-2"></i>Student Storage Quota Inspector</h5>
            <span class="text-muted" style="font-size: 0.84rem;">Default 25MB Quota</span>
        </div>
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Department</th>
                        <th>Enrollment No</th>
                        <th>Usage</th>
                        <th>Quota</th>
                        <th>Files</th>
                        <th style="text-align: right;">Adjust Quota</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($studentsRes && mysqli_num_rows($studentsRes) > 0): ?>
                        <?php while ($s = mysqli_fetch_assoc($studentsRes)): ?>
                            <?php 
                                $usedMb = round($s['used_bytes'] / (1024 * 1024), 2);
                                $maxMb = round($s['max_quota_bytes'] / (1024 * 1024), 0);
                                $pct = ($s['max_quota_bytes'] > 0) ? round(($s['used_bytes'] / $s['max_quota_bytes']) * 100, 1) : 0;
                            ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 700; color: #0f172a;"><?php echo htmlspecialchars($s['full_name']); ?></div>
                                    <div style="font-size: 0.8rem; color: #64748b;"><?php echo htmlspecialchars($s['email']); ?></div>
                                </td>
                                <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($s['department']); ?></span></td>
                                <td><code style="font-size: 0.82rem;"><?php echo htmlspecialchars($s['enrollment_no'] ?: 'N/A'); ?></code></td>
                                <td>
                                    <strong><?php echo $usedMb; ?> MB</strong>
                                    <span style="font-size: 0.76rem; color: #64748b;">(<?php echo $pct; ?>%)</span>
                                </td>
                                <td><strong><?php echo $maxMb; ?> MB</strong></td>
                                <td><?php echo $s['file_count']; ?> files</td>
                                <td style="text-align: right;">
                                    <form method="POST" action="storage_monitoring.php" class="d-inline-flex gap-2 align-items-center">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="target_user_id" value="<?php echo $s['user_id']; ?>">
                                        <input type="number" name="new_quota_mb" value="<?php echo $maxMb; ?>" min="5" max="500" class="form-control form-control-sm text-center" style="width: 75px; height: 32px; border-radius: 8px;">
                                        <button type="submit" name="update_quota" class="btn btn-sm btn-outline-primary" style="height: 32px; border-radius: 8px; font-weight: 600;">Save</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted">No student storage records active. Once students upload resumes or portfolio items, their quota will display here.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

</main>
</div>

<?php include "includes/sidebar_scripts.php"; ?>
</body>
</html>
