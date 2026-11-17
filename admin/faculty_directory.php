<?php
session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$activePage = 'faculty_directory.php';
$adminId = (int)($_SESSION['user_id'] ?? 0);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$successMsg = '';
$errorMsg = '';

// -------------------------------------------------------------
// POST ACTIONS (Add, Edit, Assign Divisions, Toggle Status, Delete)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $postedCsrf)) {
        $errorMsg = 'Security validation failed (Invalid CSRF token).';
    } else {
        $action = trim($_POST['action'] ?? '');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        // 1. ADD FACULTY
        if ($action === 'add_faculty') {
            $name = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $pwd = $_POST['password'] ?? '';
            $dept = trim($_POST['department'] ?? 'Information Technology');
            $desig = trim($_POST['designation'] ?? 'Assistant Professor');
            $empId = trim($_POST['employee_id'] ?? '');
            $phone = trim($_POST['phone'] ?? '');

            if ($name === '' || $email === '' || $pwd === '') {
                $errorMsg = 'Faculty name, email, and password are required.';
            } else {
                $chk = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $chk->bind_param("s", $email);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    $errorMsg = 'A user with this email address already exists.';
                } else {
                    $hash = password_hash($pwd, PASSWORD_DEFAULT);
                    $ins = $conn->prepare("
                        INSERT INTO users (full_name, email, password, role, account_status, email_verified, department, designation, employee_id, phone, created_at)
                        VALUES (?, ?, ?, 'faculty', 'active', 1, ?, ?, ?, ?, NOW())
                    ");
                    $ins->bind_param("sssssss", $name, $email, $hash, $dept, $desig, $empId, $phone);
                    if ($ins->execute()) {
                        $newId = $ins->insert_id;
                        $successMsg = "Faculty mentor <strong>" . h($name) . "</strong> created successfully.";
                    } else {
                        $errorMsg = 'Database error: ' . $conn->error;
                    }
                }
            }
        }

        // 2. EDIT FACULTY
        elseif ($action === 'edit_faculty') {
            $facId = (int)($_POST['faculty_id'] ?? 0);
            $name = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $dept = trim($_POST['department'] ?? '');
            $desig = trim($_POST['designation'] ?? '');
            $empId = trim($_POST['employee_id'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $status = in_array($_POST['account_status'] ?? '', ['active', 'suspended']) ? $_POST['account_status'] : 'active';

            if ($facId > 0 && $name !== '' && $email !== '') {
                $upd = $conn->prepare("
                    UPDATE users 
                    SET full_name = ?, email = ?, department = ?, designation = ?, employee_id = ?, phone = ?, account_status = ?
                    WHERE id = ? AND role = 'faculty'
                ");
                $upd->bind_param("sssssssi", $name, $email, $dept, $desig, $empId, $phone, $status, $facId);
                if ($upd->execute()) {
                    $successMsg = "Faculty profile updated successfully.";
                }
            }
        }

        // 3. ASSIGN DIVISIONS
        elseif ($action === 'assign_divisions') {
            $facId = (int)($_POST['faculty_id'] ?? 0);
            $divisions_posted = $_POST['assigned_divisions'] ?? [];

            if ($facId > 0) {
                // Delete existing divisions
                $del = $conn->prepare("DELETE FROM faculty_divisions WHERE faculty_id = ?");
                $del->bind_param("i", $facId);
                $del->execute();

                // Insert newly selected divisions
                if (!empty($divisions_posted) && is_array($divisions_posted)) {
                    $insDiv = $conn->prepare("INSERT INTO faculty_divisions (faculty_id, division) VALUES (?, ?)");
                    foreach ($divisions_posted as $dv) {
                        $dv = trim($dv);
                        if ($dv !== '') {
                            $insDiv->bind_param("is", $facId, $dv);
                            $insDiv->execute();
                        }
                    }
                }
                $successMsg = "Division assignments updated successfully.";
            }
        }

        // 4. TOGGLE STATUS
        elseif ($action === 'toggle_status') {
            $facId = (int)($_POST['faculty_id'] ?? 0);
            $newStatus = $_POST['target_status'] === 'suspended' ? 'suspended' : 'active';
            if ($facId > 0) {
                $upd = $conn->prepare("UPDATE users SET account_status = ? WHERE id = ? AND role = 'faculty'");
                $upd->bind_param("si", $newStatus, $facId);
                $upd->execute();
                $successMsg = "Faculty status updated to " . ucfirst($newStatus) . ".";
            }
        }

        // 5. DELETE FACULTY
        elseif ($action === 'delete_faculty') {
            $facId = (int)($_POST['faculty_id'] ?? 0);
            if ($facId > 0) {
                $conn->query("DELETE FROM faculty_divisions WHERE faculty_id = $facId");
                $conn->query("DELETE FROM users WHERE id = $facId AND role = 'faculty'");
                $successMsg = "Faculty account removed successfully.";
            }
        }
    }
}

// -------------------------------------------------------------
// DIRECTORY LISTING & METRICS
// -------------------------------------------------------------
$search = trim($_GET['search'] ?? '');
$filter_dept = trim($_GET['department'] ?? '');
$filter_status = trim($_GET['status'] ?? '');

// Metrics
$total_faculty = 0;
$active_faculty = 0;
$c_res = @mysqli_query($conn, "SELECT account_status, COUNT(*) as c FROM users WHERE role = 'faculty' GROUP BY account_status");
if ($c_res) {
    while ($cr = mysqli_fetch_assoc($c_res)) {
        $c = (int)$cr['c'];
        $total_faculty += $c;
        if ($cr['account_status'] === 'active') $active_faculty += $c;
    }
}

// All Academic Divisions for assignment modals
$all_divisions = [];
$ad_q = @mysqli_query($conn, "SELECT DISTINCT division FROM users WHERE role = 'student' AND division IS NOT NULL AND TRIM(division) != '' ORDER BY division");
if ($ad_q) {
    while ($ar = mysqli_fetch_assoc($ad_q)) {
        $all_divisions[] = $ar['division'];
    }
}

// Query Construction
$where = ["u.role = 'faculty'"];
$params = [];
$types = "";

if ($search !== '') {
    $where[] = "(u.full_name LIKE ? OR u.email LIKE ? OR u.employee_id LIKE ? OR u.designation LIKE ?)";
    $lk = "%$search%";
    $params[] = $lk; $params[] = $lk; $params[] = $lk; $params[] = $lk;
    $types .= "ssss";
}

if ($filter_dept !== '') {
    $where[] = "u.department = ?";
    $params[] = $filter_dept;
    $types .= "s";
}

if ($filter_status !== '') {
    $where[] = "u.account_status = ?";
    $params[] = $filter_status;
    $types .= "s";
}

$where_clause = implode(" AND ", $where);

$sql = "
    SELECT u.id, u.full_name, u.email, u.phone, u.employee_id, u.designation, u.department, 
           u.account_status, u.created_at,
           GROUP_CONCAT(DISTINCT fd.division ORDER BY fd.division SEPARATOR ', ') as assigned_divisions
    FROM users u
    LEFT JOIN faculty_divisions fd ON fd.faculty_id = u.id
    WHERE $where_clause
    GROUP BY u.id
    ORDER BY u.full_name ASC
";
$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$faculty_members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Directory — InternHub Admin</title>
    <?php include __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
        .fd-container {
            padding: 24px 32px 48px;
            max-width: 1540px;
            margin: 0 auto;
        }
        .fd-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 22px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .fd-title {
            font-size: 1.6rem;
            font-weight: 800;
            color: #1e1b4b;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .fd-subtitle {
            font-size: 0.86rem;
            color: #64748b;
            margin-top: 4px;
        }

        /* Stats Strip */
        .fd-stats-strip {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 22px;
        }
        .fd-stat-card {
            background: #ffffff;
            border-radius: 14px;
            padding: 18px 20px;
            border: 1px solid #eef1f6;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.02);
        }
        .fd-stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
        }
        .fd-stat-val {
            font-size: 1.5rem;
            font-weight: 800;
            color: #1e1b4b;
            line-height: 1.1;
        }
        .fd-stat-lbl {
            font-size: 0.76rem;
            font-weight: 600;
            color: #64748b;
            margin-top: 2px;
        }

        /* Filter Toolbar */
        .fd-toolbar {
            background: #ffffff;
            border-radius: 16px;
            padding: 16px 20px;
            border: 1px solid #eef1f6;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
        }
        .fd-search-wrap {
            position: relative;
            flex: 1;
            min-width: 280px;
        }
        .fd-search-input {
            width: 100%;
            padding: 10px 14px 10px 38px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            font-size: 0.86rem;
            font-family: inherit;
            outline: none;
            transition: border-color 0.2s;
        }
        .fd-search-input:focus { border-color: #7c3aed; }
        .fd-search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 0.9rem;
        }

        /* Faculty Cards Grid */
        .faculty-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 20px;
        }
        .fac-card {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid #eef1f6;
            padding: 24px;
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.03);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .fac-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 26px rgba(58, 16, 79, 0.08);
        }
        .fc-head {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }
        .fc-avatar {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: #eddff7;
            color: #3a104f;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            font-weight: 800;
            flex-shrink: 0;
        }
        .fc-name {
            font-size: 1.05rem;
            font-weight: 700;
            color: #1e1b4b;
            line-height: 1.2;
            margin-bottom: 2px;
        }
        .fc-desig {
            font-size: 0.78rem;
            color: #7c3aed;
            font-weight: 600;
        }
        .fc-dept {
            font-size: 0.74rem;
            color: #64748b;
            margin-top: 2px;
        }

        /* Division Chip */
        .fc-div-section {
            background: #f8fafc;
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 16px;
            border: 1px solid #f1f5f9;
        }
        .fc-div-label {
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #94a3b8;
            margin-bottom: 6px;
        }
        .fc-div-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .fc-div-tag {
            background: #eddff7;
            color: #3a104f;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
        }

        /* Footer Action Group */
        .fc-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: auto;
            padding-top: 14px;
            border-top: 1px solid #f8fafc;
        }
        .fc-btn-assign {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            font-weight: 700;
            color: #3a104f;
            background: #eddff7;
            padding: 6px 12px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .fc-btn-assign:hover {
            background: #dfc8f2;
        }
    </style>
</head>
<body>

    <?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

    <div class="fd-container">
        
        <!-- Header -->
        <div class="fd-header">
            <div>
                <h1 class="fd-title"><i class="fa-solid fa-chalkboard-user" style="color: #7c3aed;"></i> Faculty Directory</h1>
                <div class="fd-subtitle">Academic mentorship network, division assignments, and faculty supervisor oversight.</div>
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="button" class="btn btn-primary" style="background: #3a104f; border-color: #3a104f; border-radius: 12px; font-weight: 600; padding: 10px 18px;" onclick="openModal('addFacultyModal')">
                    <i class="fa-solid fa-user-plus"></i> Add Faculty
                </button>
            </div>
        </div>

        <!-- Feedback Alerts -->
        <?php if ($successMsg): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert" style="border-radius: 12px;">
                <i class="fa-solid fa-circle-check"></i> <?php echo $successMsg; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-radius: 12px;">
                <i class="fa-solid fa-triangle-exclamation"></i> <?php echo $errorMsg; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Stats Strip -->
        <div class="fd-stats-strip">
            <div class="fd-stat-card">
                <div class="fd-stat-icon" style="background: #eddff7; color: #6b21a8;">
                    <i class="fa-solid fa-chalkboard-user"></i>
                </div>
                <div>
                    <div class="fd-stat-val"><?php echo number_format($total_faculty); ?></div>
                    <div class="fd-stat-lbl">Total Faculty Mentors</div>
                </div>
            </div>
            <div class="fd-stat-card">
                <div class="fd-stat-icon" style="background: #d1fae5; color: #047857;">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div>
                    <div class="fd-stat-val"><?php echo number_format($active_faculty); ?></div>
                    <div class="fd-stat-lbl">Active Supervisors</div>
                </div>
            </div>
            <div class="fd-stat-card">
                <div class="fd-stat-icon" style="background: #eff6ff; color: #1d4ed8;">
                    <i class="fa-solid fa-sitemap"></i>
                </div>
                <div>
                    <div class="fd-stat-val"><?php echo count($all_divisions); ?></div>
                    <div class="fd-stat-lbl">Academic Divisions Covered</div>
                </div>
            </div>
        </div>

        <!-- Filter Toolbar -->
        <form method="GET" action="faculty_directory.php" class="fd-toolbar">
            <div class="fd-search-wrap">
                <i class="fa-solid fa-magnifying-glass fd-search-icon"></i>
                <input type="text" name="search" class="fd-search-input" placeholder="Search by faculty name, designation, email, or employee ID..." value="<?php echo h($search); ?>">
            </div>
            <div style="display: flex; gap: 10px; align-items: center;">
                <select name="status" class="sd-select" style="padding: 9px 14px; border-radius: 10px; border: 1px solid #e2e8f0; font-size: 0.84rem;">
                    <option value="">All Statuses</option>
                    <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="suspended" <?php echo $filter_status === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                </select>
                <button type="submit" class="btn btn-sm btn-dark" style="border-radius: 9px; padding: 9px 16px; font-weight: 600;">Apply</button>
                <?php if ($search || $filter_status): ?>
                    <a href="faculty_directory.php" class="btn btn-sm btn-light" style="border-radius: 9px; padding: 9px 14px; color: #64748b;">Reset</a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Faculty Cards Grid -->
        <?php if (!empty($faculty_members)): ?>
            <div class="faculty-grid">
                <?php foreach ($faculty_members as $f): ?>
                    <?php 
                        $init = strtoupper(substr($f['full_name'] ?: 'F', 0, 1));
                        $divs = $f['assigned_divisions'] ? explode(', ', $f['assigned_divisions']) : [];
                        $st = $f['account_status'] ?: 'active';
                    ?>
                    <div class="fac-card">
                        <div class="fc-head">
                            <div class="fc-avatar"><?php echo $init; ?></div>
                            <div style="flex: 1; min-width: 0;">
                                <div class="fc-name"><?php echo h($f['full_name']); ?></div>
                                <div class="fc-desig"><?php echo h($f['designation'] ?: 'Faculty Mentor'); ?></div>
                                <div class="fc-dept"><?php echo h($f['department'] ?: 'Information Technology'); ?></div>
                            </div>
                        </div>

                        <div style="font-size: 0.78rem; color: #64748b; margin-bottom: 14px;">
                            <div><i class="fa-regular fa-envelope" style="width: 16px;"></i> <?php echo h($f['email']); ?></div>
                            <div style="margin-top: 3px;"><i class="fa-solid fa-phone" style="width: 16px;"></i> <?php echo h($f['phone'] ?: 'No phone recorded'); ?></div>
                            <div style="margin-top: 3px;"><i class="fa-solid fa-id-badge" style="width: 16px;"></i> <?php echo h($f['employee_id'] ?: 'EMP #' . $f['id']); ?></div>
                        </div>

                        <div class="fc-div-section">
                            <div class="fc-div-label">Assigned Divisions (<?php echo count($divs); ?>)</div>
                            <div class="fc-div-pills">
                                <?php if (!empty($divs)): ?>
                                    <?php foreach ($divs as $d): ?>
                                        <span class="fc-div-tag"><?php echo h($d); ?></span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span style="font-size: 0.74rem; color: #94a3b8;">No divisions currently assigned</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="fc-actions">
                            <button type="button" class="fc-btn-assign" onclick='openAssignModal(<?php echo $f['id']; ?>, "<?php echo addslashes($f['full_name']); ?>", <?php echo json_encode($divs); ?>)'>
                                <i class="fa-solid fa-sitemap"></i> Assign Divisions
                            </button>

                            <div style="display: flex; gap: 6px;">
                                <button type="button" class="btn btn-sm btn-outline-secondary" style="border-radius: 8px;" onclick='openEditModal(<?php echo json_encode($f); ?>)' title="Edit Profile">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </button>
                                
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="faculty_id" value="<?php echo $f['id']; ?>">
                                    <input type="hidden" name="target_status" value="<?php echo $st === 'active' ? 'suspended' : 'active'; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-warning" style="border-radius: 8px;" title="<?php echo $st === 'active' ? 'Suspend' : 'Activate'; ?>">
                                        <i class="fa-solid <?php echo $st === 'active' ? 'fa-ban' : 'fa-check'; ?>"></i>
                                    </button>
                                </form>

                                <form method="POST" style="display: inline;" onsubmit="return confirm('Permanently remove this faculty member?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="delete_faculty">
                                    <input type="hidden" name="faculty_id" value="<?php echo $f['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" style="border-radius: 8px;" title="Delete">
                                        <i class="fa-regular fa-trash-can"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div style="background: #ffffff; border-radius: 18px; padding: 48px; text-align: center; border: 1px solid #eef1f6;">
                <i class="fa-solid fa-user-slash" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 14px;"></i>
                <h3 style="font-size: 1.1rem; color: #1e1b4b; font-weight: 700;">No Faculty Mentors Found</h3>
                <p style="font-size: 0.86rem; color: #64748b; margin-top: 4px;">No faculty accounts match the current query.</p>
            </div>
        <?php endif; ?>

    </div>

    <!-- MODAL: ADD FACULTY -->
    <div class="custom-modal" id="addFacultyModal" style="display: none;">
        <div class="modal-box" style="max-width: 540px;">
            <div class="modal-head">
                <h3 class="modal-head-title"><i class="fa-solid fa-user-plus"></i> Add Faculty Member</h3>
                <button type="button" class="modal-close" onclick="closeModal('addFacultyModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="add_faculty">
                <div class="modal-body-scroll">
                    <div class="mb-3">
                        <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Full Name</label>
                        <input type="text" name="full_name" class="form-control" placeholder="e.g. Dr. Jane Smith" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Email Address</label>
                        <input type="email" name="email" class="form-control" placeholder="faculty@mituniversity.edu.in" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Initial Password</label>
                        <input type="password" name="password" class="form-control" placeholder="Temporary password" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Designation</label>
                            <input type="text" name="designation" class="form-control" value="Assistant Professor">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Department</label>
                            <input type="text" name="department" class="form-control" value="Information Technology">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Employee ID</label>
                            <input type="text" name="employee_id" class="form-control" placeholder="MITFAC1024">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Phone</label>
                            <input type="text" name="phone" class="form-control" placeholder="+91 9876543210">
                        </div>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-light" onclick="closeModal('addFacultyModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background: #3a104f; border-color: #3a104f;">Create Faculty</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: EDIT FACULTY -->
    <div class="custom-modal" id="editFacultyModal" style="display: none;">
        <div class="modal-box" style="max-width: 540px;">
            <div class="modal-head">
                <h3 class="modal-head-title"><i class="fa-solid fa-pen-to-square"></i> Edit Faculty Profile</h3>
                <button type="button" class="modal-close" onclick="closeModal('editFacultyModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="edit_faculty">
                <input type="hidden" name="faculty_id" id="editFacId">
                <div class="modal-body-scroll">
                    <div class="mb-3">
                        <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Full Name</label>
                        <input type="text" name="full_name" id="editFacName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Email Address</label>
                        <input type="email" name="email" id="editFacEmail" class="form-control" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Designation</label>
                            <input type="text" name="designation" id="editFacDesig" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Department</label>
                            <input type="text" name="department" id="editFacDept" class="form-control">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Employee ID</label>
                            <input type="text" name="employee_id" id="editFacEmpId" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Phone</label>
                            <input type="text" name="phone" id="editFacPhone" class="form-control">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Account Status</label>
                        <select name="account_status" id="editFacStatus" class="form-select">
                            <option value="active">Active</option>
                            <option value="suspended">Suspended</option>
                        </select>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-light" onclick="closeModal('editFacultyModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background: #3a104f; border-color: #3a104f;">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: ASSIGN DIVISIONS -->
    <div class="custom-modal" id="assignDivisionsModal" style="display: none;">
        <div class="modal-box" style="max-width: 520px;">
            <div class="modal-head">
                <h3 class="modal-head-title"><i class="fa-solid fa-sitemap"></i> Assign Academic Divisions</h3>
                <button type="button" class="modal-close" onclick="closeModal('assignDivisionsModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="assign_divisions">
                <input type="hidden" name="faculty_id" id="assignFacId">
                <div class="modal-body-scroll">
                    <p style="font-size: 0.86rem; color: #64748b; margin-bottom: 14px;">
                        Select academic divisions mentored by <strong id="assignFacName" style="color: #1e1b4b;"></strong>:
                    </p>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; max-height: 260px; overflow-y: auto; padding: 4px;">
                        <?php foreach ($all_divisions as $dv): ?>
                            <label style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.82rem; cursor: pointer;">
                                <input type="checkbox" name="assigned_divisions[]" value="<?php echo h($dv); ?>" class="div-checkbox">
                                <span><?php echo h($dv); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-light" onclick="closeModal('assignDivisionsModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background: #3a104f; border-color: #3a104f;">Save Assignments</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Layout Close -->
    </main>
    </div>

    <?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>

    <script>
    function openModal(id) {
        const el = document.getElementById(id);
        if (el) el.style.display = 'flex';
    }
    function closeModal(id) {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    }

    function openEditModal(f) {
        document.getElementById('editFacId').value = f.id;
        document.getElementById('editFacName').value = f.full_name || '';
        document.getElementById('editFacEmail').value = f.email || '';
        document.getElementById('editFacDesig').value = f.designation || '';
        document.getElementById('editFacDept').value = f.department || '';
        document.getElementById('editFacEmpId').value = f.employee_id || '';
        document.getElementById('editFacPhone').value = f.phone || '';
        document.getElementById('editFacStatus').value = f.account_status || 'active';
        openModal('editFacultyModal');
    }

    function openAssignModal(facId, facName, assignedList) {
        document.getElementById('assignFacId').value = facId;
        document.getElementById('assignFacName').textContent = facName;
        document.querySelectorAll('.div-checkbox').forEach(cb => {
            cb.checked = assignedList.includes(cb.value);
        });
        openModal('assignDivisionsModal');
    }

    document.querySelectorAll('.custom-modal').forEach(m => {
        m.addEventListener('click', function(e) {
            if (e.target === this) this.style.display = 'none';
        });
    });
    </script>
</body>
</html>
