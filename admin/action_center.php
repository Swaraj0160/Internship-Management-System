<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
session_start();

if (!function_exists('calculateWeeklyMarks')) {
    function calculateWeeklyMarks(?string $submittedAt, ?string $deadlineDate, ?string $rejectedAt = null): int {
        if (empty($submittedAt)) return 0;
        $submitted_ts = strtotime($submittedAt);
        if (empty($deadlineDate)) return 10;
        $deadline_ts = strtotime($deadlineDate);
        $effective_deadline = !empty($rejectedAt) ? (strtotime($rejectedAt) + 86400) : $deadline_ts;
        $seconds_late = $submitted_ts - $effective_deadline;
        $days_late = ceil($seconds_late / 86400);
        if ($days_late <= 0) return 10;
        elseif ($days_late == 1) return 9;
        elseif ($days_late == 2) return 8;
        elseif ($days_late == 3) return 7;
        else return 5;
    }
}


$configPath = __DIR__ . '/../config.php';
if (!file_exists($configPath)) {
    die('Configuration file not found.');
}
include $configPath;

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'Invalid input']);
        exit;
    }

    $action = $input['action'] ?? '';
    
    // Bulk Approve
    if ($action === 'bulk_approve' && isset($input['items']) && is_array($input['items'])) {
        $successCount = 0;
        foreach ($input['items'] as $item) {
            $source_id = intval($item['source_id']);
            $type = $item['type'];
            if ($type === 'approval') {
                $stmt = $conn->prepare("UPDATE internships SET status = 'Approved' WHERE id = ?");
                $stmt->bind_param("i", $source_id);
                if ($stmt->execute()) $successCount++;
                $stmt->close();
            } elseif ($type === 'report') {
                $stmt = $conn->prepare("UPDATE weekly_updates SET review_status = 'Approved' WHERE id = ?");
                $stmt->bind_param("i", $source_id);
                if ($stmt->execute()) $successCount++;
                $stmt->close();
            }
        }
        echo json_encode(['success' => true, 'message' => "Successfully approved $successCount items."]);
        exit;
    }

    // Single Actions
    $source_id = intval($input['source_id'] ?? 0);
    $type = $input['type'] ?? '';
    $reason = $input['reason'] ?? '';

    if (!$source_id || !$type) {
        echo json_encode(['success' => false, 'message' => 'Missing item data']);
        exit;
    }

    if ($action === 'approve') {
        if ($type === 'approval') {
            $stmt = $conn->prepare("UPDATE internships SET status = 'Approved' WHERE id = ?");
            $stmt->bind_param("i", $source_id);
            $stmt->execute();
        } elseif ($type === 'report') {
            $stmt = $conn->prepare("UPDATE weekly_updates SET review_status = 'Approved' WHERE id = ?");
            $stmt->bind_param("i", $source_id);
            $stmt->execute();
        }
        echo json_encode(['success' => true]);
        exit;
    } elseif ($action === 'reject' || $action === 'request_changes') {
        if ($type === 'approval') {
            $stmt = $conn->prepare("UPDATE internships SET status = 'Rejected', rejection_reason = ? WHERE id = ?");
            $stmt->bind_param("si", $reason, $source_id);
            $stmt->execute();
        } elseif ($type === 'report') {
            $stmt = $conn->prepare("UPDATE weekly_updates SET review_status = 'Rejected', faculty_feedback = ?, rejected_at = NOW() WHERE id = ?");
            $stmt->bind_param("si", $reason, $source_id);
            $stmt->execute();
        }
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}


$activePage = 'action_center.php';

// FETCH REAL DATA
$realActions = [];
$actionId = 1;

// 1. Approvals
$q1 = mysqli_query($conn, "
    SELECT 
        i.id as source_id,
        'approval' as type,
        'Internship approval required' as title,
        u.full_name as student,
        u.enrollment_no as enrollment,
        i.company_name as company,
        i.internship_role as role,
        u.division,
        COALESCE(dc.assigned_domain, i.internship_type) as domain,
        i.created_at as date,
        i.offer_letter,
        u.class_division, u.email, u.phone
    FROM internships i
    JOIN users u ON i.student_id = u.id
    LEFT JOIN domain_classifications dc ON u.enrollment_no = dc.enrollment_no
    WHERE i.status = 'Pending'
");
if ($q1) {
    while($r = mysqli_fetch_assoc($q1)) {
        $r['id'] = $actionId++;
        $date = $r['date'] ? strtotime($r['date']) : time();
        $days = floor((time() - $date) / 86400);
        if ($days < 0) $days = 0;
        $r['priority'] = $days > 7 ? 'urgent' : ($days > 3 ? 'high' : 'medium');
        $r['time'] = "Pending for $days days";
        $r['reason'] = '';
        $r['date'] = date('Y-m-d', $date);
        $realActions[] = $r;
    }
}

// 2. Weekly Reports
$q2 = mysqli_query($conn, "
    SELECT 
        w.id as source_id,
        'report' as type,
        CONCAT('Weekly report pending review (Week ', w.week_no, ')') as title,
        u.full_name as student,
        u.enrollment_no as enrollment,
        i.company_name as company,
        i.internship_role as role,
        u.division,
        COALESCE(dc.assigned_domain, i.internship_type) as domain,
        w.submitted_at as date,
        w.work_done,
        w.update_file,
        u.class_division, u.email, u.phone
    FROM weekly_updates w
    JOIN internships i ON w.internship_id = i.id
    JOIN users u ON i.student_id = u.id
    LEFT JOIN domain_classifications dc ON u.enrollment_no = dc.enrollment_no
    WHERE w.review_status = 'Pending'
");
if ($q2) {
    while($r = mysqli_fetch_assoc($q2)) {
        $r['id'] = $actionId++;
        $date = $r['date'] ? strtotime($r['date']) : time();
        $days = floor((time() - $date) / 86400);
        if ($days < 0) $days = 0;
        $r['priority'] = $days > 7 ? 'urgent' : ($days > 3 ? 'high' : 'low');
        $r['time'] = "Pending for $days days";
        $r['reason'] = '';
        $r['date'] = date('Y-m-d', $date);
        $realActions[] = $r;
    }
}

// 3. Evaluations
$q3 = mysqli_query($conn, "
    SELECT 
        i.id as source_id,
        'evaluation' as type,
        'Evaluation pending completion' as title,
        u.full_name as student,
        u.enrollment_no as enrollment,
        i.company_name as company,
        i.internship_role as role,
        u.division,
        COALESCE(dc.assigned_domain, i.internship_type) as domain,
        i.created_at as date,
        i.offer_letter,
        u.class_division, u.email, u.phone
    FROM internships i
    JOIN users u ON i.student_id = u.id
    LEFT JOIN domain_classifications dc ON u.enrollment_no = dc.enrollment_no
    LEFT JOIN student_evaluation se ON u.id = se.student_id
    WHERE i.status = 'Approved' AND (se.mentor_evaluation IS NULL OR se.final_evaluation IS NULL)
");
if ($q3) {
    while($r = mysqli_fetch_assoc($q3)) {
        $r['id'] = $actionId++;
        $date = $r['date'] ? strtotime($r['date']) : time();
        $days = floor((time() - $date) / 86400);
        if ($days < 0) $days = 0;
        $r['priority'] = 'medium';
        $r['time'] = "Pending for $days days";
        $r['reason'] = '';
        $r['date'] = date('Y-m-d', $date);
        $realActions[] = $r;
    }
}

// 4. Rejected
$q4 = mysqli_query($conn, "
    SELECT 
        i.id as source_id,
        'rejected' as type,
        'Internship rejected - Requires action' as title,
        u.full_name as student,
        u.enrollment_no as enrollment,
        i.company_name as company,
        i.internship_role as role,
        u.division,
        COALESCE(dc.assigned_domain, i.internship_type) as domain,
        i.created_at as date,
        'Internship proposal was rejected' as reason,
        u.class_division, u.email, u.phone
    FROM internships i
    JOIN users u ON i.student_id = u.id
    LEFT JOIN domain_classifications dc ON u.enrollment_no = dc.enrollment_no
    WHERE i.status = 'Rejected'
");
if ($q4) {
    while($r = mysqli_fetch_assoc($q4)) {
        $r['id'] = $actionId++;
        $date = $r['date'] ? strtotime($r['date']) : time();
        $days = floor((time() - $date) / 86400);
        if ($days < 0) $days = 0;
        $r['priority'] = 'high';
        $r['time'] = "Rejected $days days ago";
        $r['date'] = date('Y-m-d', $date);
        $realActions[] = $r;
    }
}

$realActionsJson = json_encode($realActions);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Action Center - InternHub</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <?php include __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
        :root {
            --primary: #4f46e5;
            --primary-glow: rgba(79,70,229,0.25);
            --bg-color: #f3f4f6;
            --text-main: #111827;
            --text-muted: #6b7280;
            --card-bg: rgba(255, 255, 255, 0.95);
        }
        * { font-family: 'Plus Jakarta Sans', system-ui, sans-serif; }
        body {
            background: #f3f4f6;
            background-image: radial-gradient(circle at 10% 20%, rgba(91, 61, 245, 0.03) 0%, transparent 20%), 
                              radial-gradient(circle at 90% 80%, rgba(16, 185, 129, 0.03) 0%, transparent 20%);
            background-attachment: fixed;
            color: var(--text-main);
        }
        .page-inner { padding: 32px; max-width: 1600px; margin: 0 auto; }
        
        /* Header */
        .page-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 2rem; }
        .page-header h2 { font-size: 1.8rem; font-weight: 800; color: #111827; margin-bottom: 4px; display: flex; align-items: center; gap: 12px; }
        .page-header p { font-size: 0.95rem; color: #6b7280; font-weight: 500; margin: 0; }
        .demo-badge { background: #fee2e2; color: #ef4444; font-size: 0.75rem; font-weight: 800; padding: 4px 10px; border-radius: 20px; letter-spacing: 0.5px; border: 1px solid #fca5a5; }

        /* KPI Strip */
        .kpi-strip { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .kpi-card {
            background: var(--card-bg); backdrop-filter: blur(10px); border-radius: 16px; padding: 18px;
            border: 1px solid rgba(255,255,255,0.8); box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            display: flex; flex-direction: column; cursor: pointer; transition: all 0.2s; position: relative; overflow: hidden;
        }
        .kpi-card:hover { transform: translateY(-4px); box-shadow: 0 12px 24px rgba(0,0,0,0.06); border-color: var(--primary); }
        .kpi-card.active { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-glow); }
        .kpi-card .icon { font-size: 1.4rem; margin-bottom: 12px; }
        .kpi-card .count { font-size: 1.8rem; font-weight: 800; line-height: 1; color: var(--text-main); margin-bottom: 4px; }
        .kpi-card .label { font-size: 0.8rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }

        /* Filters */
        .filter-panel {
            background: #fff; border-radius: 16px; padding: 20px; margin-bottom: 24px;
            border: 1px solid #e2e8f0; box-shadow: 0 4px 12px rgba(0,0,0,0.02);
            display: flex; gap: 12px; flex-wrap: wrap; align-items: center;
        }
        .filter-panel .form-control, .filter-panel .form-select {
            height: 42px; border-radius: 10px; border: 1px solid #cbd5e1; font-size: 0.9rem; font-weight: 500;
        }
        .filter-panel .form-control:focus, .filter-panel .form-select:focus {
            border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-glow);
        }

        /* Action Queue */
        .action-queue { background: #fff; border-radius: 16px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.04); }
        .action-row {
            display: flex; align-items: center; padding: 20px 24px; border-bottom: 1px solid #f1f5f9; transition: background 0.2s; cursor: pointer;
        }
        .action-row:hover { background: #f8fafc; }
        .action-row:last-child { border-bottom: none; }
        .action-priority { flex: 0 0 80px; }
        .priority-badge { font-size: 0.65rem; font-weight: 800; padding: 4px 8px; border-radius: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
        .priority-urgent { background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; }
        .priority-high { background: #ffedd5; color: #ea580c; border: 1px solid #fed7aa; }
        .priority-medium { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; }
        .priority-low { background: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; }
        
        .action-icon { flex: 0 0 48px; width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; background: #f1f5f9; color: #64748b; margin-right: 20px; }
        .action-details { flex: 1; min-width: 0; }
        .action-title { font-size: 1rem; font-weight: 700; color: #0f172a; margin-bottom: 4px; }
        .action-meta { font-size: 0.85rem; color: #64748b; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .action-meta strong { color: #334155; }
        .action-time { flex: 0 0 120px; text-align: right; font-size: 0.85rem; font-weight: 600; color: #94a3b8; }
        .action-btn { flex: 0 0 auto; margin-left: 24px; }
        
        .btn-review {
            background: #fff; color: var(--primary); border: 1px solid #cbd5e1; border-radius: 10px; padding: 8px 16px; font-weight: 700; font-size: 0.85rem; transition: all 0.2s;
        }
        .action-row:hover .btn-review { background: var(--primary); color: #fff; border-color: var(--primary); box-shadow: 0 4px 12px var(--primary-glow); }

        /* Detail Drawer */
        .offcanvas { border-left: none; box-shadow: -10px 0 40px rgba(0,0,0,0.1); width: 500px !important; }
        .offcanvas-header { background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 24px; }
        .offcanvas-title { font-weight: 800; font-size: 1.2rem; color: #0f172a; }
        .offcanvas-body { padding: 0; background: #fff; }
        .drawer-section { padding: 24px; border-bottom: 1px solid #f1f5f9; }
        .drawer-section-title { font-size: 0.8rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; margin-bottom: 16px; }
        
        .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .detail-item .label { font-size: 0.8rem; color: #64748b; font-weight: 500; margin-bottom: 4px; }
        .detail-item .value { font-size: 0.95rem; color: #0f172a; font-weight: 700; }
        
        .drawer-footer { padding: 24px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; gap: 12px; position: sticky; bottom: 0; z-index: 10; }
        .btn-approve { flex: 1; background: #10b981; color: #fff; border: none; font-weight: 700; border-radius: 10px; padding: 12px; }
        .btn-reject { flex: 1; background: #ef4444; color: #fff; border: none; font-weight: 700; border-radius: 10px; padding: 12px; }
        .btn-changes { flex: 1; background: #f59e0b; color: #fff; border: none; font-weight: 700; border-radius: 10px; padding: 12px; }
        
        /* Empty State */
        .empty-state { text-align: center; padding: 60px 20px; }
        .empty-icon { font-size: 4rem; color: #10b981; margin-bottom: 20px; animation: bounce 2s infinite; }
        .empty-title { font-size: 1.4rem; font-weight: 800; color: #0f172a; margin-bottom: 8px; }
        .empty-subtitle { font-size: 1rem; color: #64748b; }
        
        @keyframes bounce { 0%, 20%, 50%, 80%, 100% { transform: translateY(0); } 40% { transform: translateY(-20px); } 60% { transform: translateY(-10px); } }
        
        /* Toast */
        .toast-container { position: fixed; bottom: 24px; right: 24px; z-index: 1060; }
    </style>
</head>
<body>
<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="page-inner">
    <div class="page-header">
        <div>
            <h2><i class="bi bi-lightning-charge-fill text-warning"></i> Action Center </h2>
            <p>Everything requiring your attention, organized by priority.</p>
        </div>
        <div>
            <span class="text-muted" style="font-size:0.85rem; font-weight:600;"><i class="bi bi-clock-history"></i> Last updated: Just now</span>
        </div>
    </div>

    <!-- KPI STRIP -->
    <div class="kpi-strip" id="kpiStrip">
        <div class="kpi-card active" data-filter="all">
            <div class="icon" style="color: #6366f1;"><i class="bi bi-layers-fill"></i></div>
            <div class="count" id="kpi-total">27</div>
            <div class="label">Total Pending</div>
        </div>
        <div class="kpi-card" data-filter="approval">
            <div class="icon" style="color: #3b82f6;"><i class="bi bi-briefcase-fill"></i></div>
            <div class="count" id="kpi-approvals">8</div>
            <div class="label">Internship Approvals</div>
        </div>
        <div class="kpi-card" data-filter="report">
            <div class="icon" style="color: #8b5cf6;"><i class="bi bi-calendar-check-fill"></i></div>
            <div class="count" id="kpi-reports">12</div>
            <div class="label">Weekly Reports</div>
        </div>
        <div class="kpi-card" data-filter="evaluation">
            <div class="icon" style="color: #10b981;"><i class="bi bi-clipboard-data-fill"></i></div>
            <div class="count" id="kpi-evals">4</div>
            <div class="label">Evaluations</div>
        </div>
        <div class="kpi-card" data-filter="rejected">
            <div class="icon" style="color: #ef4444;"><i class="bi bi-exclamation-triangle-fill"></i></div>
            <div class="count" id="kpi-rejected">3</div>
            <div class="label">Needs Correction</div>
        </div>
    </div>

    <!-- FILTERS -->
    <div class="filter-panel">
        <div class="input-group" style="width: 300px;">
            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
            <input type="text" class="form-control border-start-0" id="searchInput" placeholder="Search student, enrollment, company...">
        </div>
        <select class="form-select" id="priorityFilter" style="width: 160px;">
            <option value="">All Priorities</option>
            <option value="urgent">Urgent</option>
            <option value="high">High</option>
            <option value="medium">Medium</option>
            <option value="low">Low</option>
        </select>
        <select class="form-select" id="divisionFilter" style="width: 160px;">
            <option value="">All Divisions</option>
            <option value="IT-DA">IT-DA</option>
            <option value="IT-SMAD">IT-SMAD</option>
            <option value="SY-1">SY-1</option>
            <option value="SY-2">SY-2</option>
        </select>
        <button class="btn btn-light border" onclick="location.reload()" style="height: 42px; font-weight:600; border-radius:10px;"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
        <button class="btn btn-light border text-primary" onclick="viewEmailLog()" style="height: 42px; font-weight:700; border-radius:10px; margin-left:auto;"><i class="bi bi-clock-history"></i> Emailed Log</button>
        <button class="btn border" onclick="bulkApproveVisible()" style="height: 42px; font-weight:700; border-radius:10px; background:#10b981; color:white; margin-left: 10px;"><i class="bi bi-check-all"></i> Mass Approve</button>
        <button class="btn border" onclick="bulkNotifyMissing()" style="height: 42px; font-weight:700; border-radius:10px; background:#4f46e5; color:white; margin-left: 10px;"><i class="bi bi-envelope-paper-fill"></i> Bulk Notify</button>
    </div>

    <!-- ACTION QUEUE -->
    <div class="action-queue" id="actionQueue">
        <!-- JS rendered rows will go here -->
    </div>
</div>

<!-- ACTION DETAIL DRAWER -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="actionDrawer" aria-labelledby="actionDrawerLabel">
    <div class="offcanvas-header">
        <div class="d-flex align-items-center gap-3">
            <div id="drawerIcon" class="action-icon" style="margin:0; background:#e0e7ff; color:#4f46e5;"></div>
            <div>
                <h5 class="offcanvas-title" id="drawerTitle">Action Details</h5>
                <div class="text-muted" style="font-size:0.85rem; font-weight:600;" id="drawerSubtitle"></div>
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <div class="drawer-section">
            <div class="drawer-section-title">Student Information</div>
            <div class="detail-grid">
                <div class="detail-item">
                    <div class="label">Name</div>
                    <div class="value" id="drawerStudentName"></div>
                </div>
                <div class="detail-item">
                    <div class="label">Enrollment No</div>
                    <div class="value" id="drawerEnrollment"></div>
                </div>
                <div class="detail-item">
                    <div class="label">Division</div>
                    <div class="value" id="drawerDivision"></div>
                </div>
            </div>
        </div>
        <div class="drawer-section">
            <div class="drawer-section-title">Context Details</div>
            <div class="detail-grid" id="drawerContextGrid">
                <!-- Populated dynamically based on action type -->
            </div>
        </div>
        <div class="drawer-section" id="drawerDocuments" style="display:none;">
            <div class="drawer-section-title">Evidence & Documents</div>
            <div class="p-3 bg-light rounded border d-flex align-items-center gap-3">
                <i class="bi bi-file-earmark-pdf-fill text-danger fs-3"></i>
                <div style="flex:1;">
                    <div style="font-weight:700; font-size:0.9rem;">Offer_Letter_2024.pdf</div>
                    <div class="text-muted" style="font-size:0.75rem;">1.2 MB</div>
                </div>
                <button class="btn btn-sm btn-outline-secondary">View</button>
            </div>
        </div>
    </div>
    <div class="drawer-footer" id="drawerFooter">
        <button class="btn-approve" onclick="performAction('approve')">Approve</button>
        <button class="btn-changes" onclick="performAction('request_changes')">Request Changes</button>
        <button class="btn-reject" onclick="performAction('reject')">Reject</button>
    </div>
</div>

<!-- TOAST CONTAINER -->
<div class="toast-container"></div>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Mock Data Engine
const mockActions = <?php echo $realActionsJson; ?>;

let currentFilter = 'all';
let currentSearch = '';
let currentPriority = '';
let currentDivision = '';
let selectedActionId = null;

const drawer = new bootstrap.Offcanvas(document.getElementById('actionDrawer'));

function renderQueue() {
    const emailedList = JSON.parse(localStorage.getItem('emailed_students') || '[]');
    const queue = document.getElementById('actionQueue');
    queue.innerHTML = '';
    
    let filtered = mockActions.filter(a => {
        if(currentFilter !== 'all' && a.type !== currentFilter) return false;
        if(currentPriority !== '' && a.priority !== currentPriority) return false;
        if(currentDivision !== '' && a.division !== currentDivision) return false;
        if(currentSearch !== '') {
            const s = currentSearch.toLowerCase();
            return a.student.toLowerCase().includes(s) || a.enrollment.toLowerCase().includes(s) || a.company.toLowerCase().includes(s);
        }
        return true;
    });
    
    if(filtered.length === 0) {
        queue.innerHTML = `
            <div class="empty-state">
                <i class="bi bi-check-circle-fill empty-icon"></i>
                <div class="empty-title">You're all caught up!</div>
                <div class="empty-subtitle">All administrative actions have been resolved for this filter.</div>
            </div>
        `;
        return;
    }
    
    filtered.forEach(a => {
        let icon = ''; let iconColor = ''; let bg = '';
        if(a.type === 'approval') { icon = 'bi-briefcase-fill'; iconColor = '#3b82f6'; bg = '#eff6ff'; }
        if(a.type === 'report') { icon = 'bi-calendar-check-fill'; iconColor = '#8b5cf6'; bg = '#f5f3ff'; }
        if(a.type === 'evaluation') { icon = 'bi-clipboard-data-fill'; iconColor = '#10b981'; bg = '#ecfdf5'; }
        if(a.type === 'rejected') { icon = 'bi-exclamation-triangle-fill'; iconColor = '#ef4444'; bg = '#fef2f2'; }
        
        queue.innerHTML += `
            <div class="action-row" onclick="openDrawer(${a.id})">
                <div class="action-priority">
                    <span class="priority-badge priority-${a.priority}">${a.priority}</span>
                </div>
                <div class="action-icon" style="color:${iconColor}; background:${bg};"><i class="bi ${icon}"></i></div>
                <div class="action-details">
                    <div class="action-title" style="font-size: 1.05rem; color: #1e293b;">${a.title}</div>
                    <div class="action-meta" style="font-size: 0.85rem; margin-top: 4px;">
                        <span style="color: #0f172a; font-weight: 700;">${a.student}</span> 
                        ${emailedList.includes(a.enrollment) ? '<span class="badge bg-success ms-2" style="font-size:0.65rem;">✓ Emailed</span>' : ''} 
                        <span style="color: #94a3b8; margin: 0 6px;">|</span> 
                        <span style="color: #475569;">${a.enrollment}</span>
                        <span style="color: #94a3b8; margin: 0 6px;">|</span> 
                        <span style="color: #475569;">${a.company || 'No Company'}</span>
                        <span style="color: #94a3b8; margin: 0 6px;">|</span> 
                        <span style="color: #0369a1; font-weight:600;">SY: ${a.division || 'N/A'}</span>
                        <span style="color: #94a3b8; margin: 0 6px;">|</span> 
                        <span style="color: #047857; font-weight:600;">TY: ${a.class_division || 'N/A'}</span>
                    </div>
                </div>
                <div class="action-time">
                    <i class="bi bi-clock"></i> ${a.time}
                </div>
                <div class="action-btn">
                    <button class="btn-review">Review <i class="bi bi-arrow-right"></i></button>
                </div>
            </div>
        `;
    });
    
    updateKPIs();
}

function updateKPIs() {
    document.getElementById('kpi-total').textContent = mockActions.length;
    document.getElementById('kpi-approvals').textContent = mockActions.filter(a => a.type==='approval').length;
    document.getElementById('kpi-reports').textContent = mockActions.filter(a => a.type==='report').length;
    document.getElementById('kpi-evals').textContent = mockActions.filter(a => a.type==='evaluation').length;
    document.getElementById('kpi-rejected').textContent = mockActions.filter(a => a.type==='rejected').length;
}

function openDrawer(id) {
    const a = mockActions.find(x => x.id === id);
    if(!a) return;
    selectedActionId = id;
    
    document.getElementById('drawerTitle').textContent = a.title;
    document.getElementById('drawerSubtitle').textContent = a.priority.toUpperCase() + ' PRIORITY';
    
    let icon = ''; let iconColor = ''; let bg = '';
    if(a.type === 'approval') { icon = 'bi-briefcase-fill'; iconColor = '#3b82f6'; bg = '#eff6ff'; }
    if(a.type === 'report') { icon = 'bi-calendar-check-fill'; iconColor = '#8b5cf6'; bg = '#f5f3ff'; }
    if(a.type === 'evaluation') { icon = 'bi-clipboard-data-fill'; iconColor = '#10b981'; bg = '#ecfdf5'; }
    if(a.type === 'rejected') { icon = 'bi-exclamation-triangle-fill'; iconColor = '#ef4444'; bg = '#fef2f2'; }
    
    const iconEl = document.getElementById('drawerIcon');
    iconEl.innerHTML = `<i class="bi ${icon}"></i>`;
    iconEl.style.color = iconColor;
    iconEl.style.background = bg;
    
    document.getElementById('drawerStudentName').textContent = a.student;
    document.getElementById('drawerEnrollment').textContent = a.enrollment;
    document.getElementById('drawerDivision').textContent = a.division;
    
    let grid = document.getElementById('drawerContextGrid');
    grid.innerHTML = `
        <div class="detail-item"><div class="label">Company</div><div class="value">${a.company}</div></div>
        <div class="detail-item"><div class="label">Role</div><div class="value">${a.role}</div></div>
        <div class="detail-item"><div class="label">Domain</div><div class="value">${a.domain}</div></div>
        <div class="detail-item"><div class="label">Submitted Date</div><div class="value">${a.date}</div></div>
    `;
    
    if(a.type === 'report') {
        document.getElementById('drawerDocuments').style.display = 'block';
        let fileHtml = a.update_file ? `<a href="../uploads/weekly_reports/${a.update_file}" target="_blank" class="btn btn-sm btn-primary mt-2">View Attachment</a>` : `<span class="badge bg-secondary mt-2">No Attachment</span>`;
        document.getElementById('drawerDocuments').innerHTML = `
            <div class="drawer-section-title">Weekly Submission</div>
            <div class="p-3 bg-light rounded border">
                <div style="font-weight:700; font-size:0.9rem; margin-bottom:8px;">Work Done:</div>
                <div style="font-size:0.85rem; color:#475569; white-space:pre-wrap;">${a.work_done || 'No description provided.'}</div>
                ${fileHtml}
            </div>
        `;
    } else if(a.type === 'approval' && a.offer_letter) {
        document.getElementById('drawerDocuments').style.display = 'block';
        let filePath = '../uploads/offer_letters/' + (a.division || 'Unknown') + '/' + a.offer_letter;
        document.getElementById('drawerDocuments').innerHTML = `
            <div class="drawer-section-title">Evidence & Documents</div>
            <div class="p-3 bg-light rounded border d-flex align-items-center gap-3">
                <i class="bi bi-file-earmark-pdf-fill text-danger fs-3"></i>
                <div style="flex:1;">
                    <div style="font-weight:700; font-size:0.9rem;">Offer Letter</div>
                    <div class="text-muted" style="font-size:0.75rem;">Document provided by student</div>
                </div>
                <a href="${filePath}" target="_blank" class="btn btn-sm btn-primary">View Letter</a>
            </div>
        `;
    } else if(a.type === 'approval') {
        document.getElementById('drawerDocuments').style.display = 'block';
        document.getElementById('drawerDocuments').innerHTML = `
            <div class="drawer-section-title">Evidence & Documents</div>
            <div class="alert alert-warning m-0">No Offer Letter Uploaded</div>
        `;
    } else {
        document.getElementById('drawerDocuments').style.display = 'none';
    }
    
    drawer.show();
}

async function performAction(action) {
    if(!selectedActionId) return;
    const aIndex = mockActions.findIndex(x => x.id === selectedActionId);
    if(aIndex === -1) return;
    
    const a = mockActions[aIndex];
    let reason = '';
    
    if(action === 'approve') {
        if(!confirm(`Are you sure you want to approve this for ${a.student}?`)) return;
    } else if(action === 'reject') {
        reason = prompt(`Provide a rejection reason for ${a.student}:`);
        if(!reason) return;
    } else if(action === 'request_changes') {
        reason = prompt(`What changes are required from ${a.student}?`);
        if(!reason) return;
    }
    
    // Send to backend
    try {
        let res = await fetch('action_center.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action: action,
                source_id: a.source_id,
                type: a.type,
                reason: reason
            })
        });
        let data = await res.json();
        
        if (data.success) {
            // Remove from queue visually
            mockActions.splice(aIndex, 1);
            drawer.hide();
            renderQueue();
            showToast(`✓ Action completed successfully for ${a.student}.`);
        } else {
            alert('Error: ' + data.message);
        }
    } catch (e) {
        alert('Request failed. Check console.');
        console.error(e);
    }
}

function showToast(msg) {
    const container = document.querySelector('.toast-container');
    const t = document.createElement('div');
    t.className = 'toast align-items-center text-white bg-dark border-0 show';
    t.setAttribute('role', 'alert');
    t.innerHTML = `<div class="d-flex"><div class="toast-body" style="font-weight:600;">${msg}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    container.appendChild(t);
    setTimeout(() => { t.classList.remove('show'); setTimeout(()=>t.remove(), 300); }, 3000);
}


function bulkNotifyMissing() {
    const missing = mockActions.filter(a => a.type === 'approval' && !a.offer_letter && a.email);
    if(missing.length === 0) {
        alert("No students with missing offer letters found in the current list.");
        return;
    }
    
    let emails = missing.map(a => a.email).join(',');
    let subject = encodeURIComponent("ACTION REQUIRED: Missing Internship Offer Letter");
    let body = encodeURIComponent("Dear Student,\n\nOur records indicate that you have not yet uploaded your internship offer letter. Please log into the InternHub portal and upload it immediately so we can approve your internship.\n\nThank you,\nMIT-ADT University Admin");
    
    let mailtoUrl = `mailto:?bcc=${emails}&subject=${subject}&body=${body}`;
    
    // Open default mail client
    window.location.href = mailtoUrl;
    
    // Save to tracking log
    let log = JSON.parse(localStorage.getItem('emailed_students') || '[]');
    missing.forEach(a => {
        if(!log.includes(a.enrollment)) log.push(a.enrollment);
    });
    localStorage.setItem('emailed_students', JSON.stringify(log));
    renderQueue();
    
    showToast(`Drafting email to ${missing.length} students... Log updated.`);
}

function viewEmailLog() {
    const log = JSON.parse(localStorage.getItem('emailed_students') || '[]');
    if(log.length === 0) {
        alert("No students have been emailed yet.");
        return;
    }
    
    // Cross reference with mockActions
    let listHtml = log.map(enrollment => {
        const a = mockActions.find(x => x.enrollment === enrollment);
        const name = a ? a.student : 'Unknown Student';
        return `<li><strong>${name}</strong> (${enrollment})</li>`;
    }).join('');
    
    const html = `
        <div class="modal fade" id="emailLogModal" tabindex="-1">
          <div class="modal-dialog">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title">Emailed Students Log</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body">
                <p>The following students were included in bulk emails:</p>
                <ul>${listHtml}</ul>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-outline-danger" onclick="clearEmailLog()">Clear Log</button>
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
              </div>
            </div>
          </div>
        </div>
    `;
    
    const existing = document.getElementById('emailLogModal');
    if(existing) existing.remove();
    
    document.body.insertAdjacentHTML('beforeend', html);
    new bootstrap.Modal(document.getElementById('emailLogModal')).show();
}

function clearEmailLog() {
    if(confirm("Are you sure you want to clear the emailed tracking log?")) {
        localStorage.removeItem('emailed_students');
        bootstrap.Modal.getInstance(document.getElementById('emailLogModal')).hide();
        renderQueue();
        showToast("Email log cleared.");
    }
}



async function bulkApproveVisible() {
    let visibleApprovals = [];
    // Re-run the current filter logic to grab what's strictly visible on screen
    mockActions.forEach(a => {
        let match = true;
        if(currentFilter !== 'all' && a.type !== currentFilter) match = false;
        if(currentPriority !== '' && a.priority !== currentPriority) match = false;
        if(currentDivision !== '' && a.division !== currentDivision) match = false;
        if(currentSearch !== '') {
            const s = currentSearch.toLowerCase();
            if(!(a.student.toLowerCase().includes(s) || a.enrollment.toLowerCase().includes(s) || (a.company||'').toLowerCase().includes(s))) match = false;
        }
        // Only mass approve 'approval' and 'report' types that are visible
        if (match && (a.type === 'approval' || a.type === 'report')) {
            visibleApprovals.push({source_id: a.source_id, type: a.type, id: a.id});
        }
    });

    if (visibleApprovals.length === 0) {
        alert("No valid approvals or reports found in the current filtered list.");
        return;
    }

    if (!confirm(`Are you sure you want to mass approve ${visibleApprovals.length} items? This cannot be undone.`)) {
        return;
    }

    try {
        let res = await fetch('action_center.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action: 'bulk_approve',
                items: visibleApprovals.map(x => ({source_id: x.source_id, type: x.type}))
            })
        });
        let data = await res.json();
        
        if (data.success) {
            showToast(data.message);
            // Remove approved items from mockActions visually
            let approvedIds = visibleApprovals.map(x => x.id);
            for (let i = mockActions.length - 1; i >= 0; i--) {
                if (approvedIds.includes(mockActions[i].id)) {
                    mockActions.splice(i, 1);
                }
            }
            renderQueue();
        } else {
            alert('Error: ' + data.message);
        }
    } catch (e) {
        alert('Request failed. Check console.');
        console.error(e);
    }
}

// Event Listeners
document.querySelectorAll('.kpi-card').forEach(c => {
    c.addEventListener('click', function() {
        document.querySelectorAll('.kpi-card').forEach(x => x.classList.remove('active'));
        this.classList.add('active');
        currentFilter = this.getAttribute('data-filter');
        renderQueue();
    });
});
document.getElementById('searchInput').addEventListener('input', e => { currentSearch = e.target.value; renderQueue(); });
document.getElementById('priorityFilter').addEventListener('change', e => { currentPriority = e.target.value; renderQueue(); });
document.getElementById('divisionFilter').addEventListener('change', e => { currentDivision = e.target.value; renderQueue(); });

renderQueue();
</script>
</body>
</html>
