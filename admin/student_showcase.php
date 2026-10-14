<?php
session_start();
include("../config.php");

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$admin_id = (int)$_SESSION['user_id'];
$activePage = 'student_showcase.php';

if (!function_exists('h')) {
    function h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

// Handle Status Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $sub_id = (int)$_POST['submission_id'];
    $new_status = $_POST['new_status'];
    $allowed_statuses = ['Submitted', 'Under Review', 'Reviewed'];
    
    if (in_array($new_status, $allowed_statuses)) {
        $chk = $conn->query("SELECT id FROM student_work_showcase WHERE id = $sub_id");
        if ($chk && $chk->num_rows > 0) {
            $stmt = $conn->prepare("UPDATE student_work_showcase SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
            $stmt->bind_param("sii", $new_status, $admin_id, $sub_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['fs_msg'] = "Status updated successfully.";
        } else {
            $_SESSION['fs_err'] = "Unauthorized access.";
        }
    }
    header("Location: student_showcase.php");
    exit();
}

// Fetch submissions (Admin sees all)
$query = "
    SELECT 
        sw.*, 
        u.full_name, u.enrollment_no, u.division, 
        i.company_name,
        r.full_name as reviewer_name
    FROM student_work_showcase sw
    JOIN users u ON sw.student_id = u.id
    LEFT JOIN internships i ON sw.internship_id = i.id
    LEFT JOIN users r ON sw.reviewed_by = r.id
    WHERE u.role = 'student'
    ORDER BY sw.created_at DESC
";
$result = $conn->query($query);
$submissions = [];
$stats = ['total' => 0, 'pending' => 0, 'review' => 0, 'reviewed' => 0];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $submissions[] = $row;
        $stats['total']++;
        if ($row['status'] === 'Submitted') $stats['pending']++;
        if ($row['status'] === 'Under Review') $stats['review']++;
        if ($row['status'] === 'Reviewed') $stats['reviewed']++;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Internship Work Submissions | Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
    <?php include __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
        :root{
            --bg:#f5f7fb; --surface:#ffffff; --text:#0f172a; --text-muted:#64748b; --border:#e2e8f0;
            --primary:#4f46e5; --success:#10b981; --warning:#f59e0b; --info:#3b82f6; --radius:16px;
        }
        body { background-color: var(--bg); font-family: 'Inter', sans-serif; color: var(--text); }
        .topbar { background: var(--surface); border-bottom: 1px solid var(--border); padding: 22px 30px; }
        .hero-title { display: flex; flex-direction: column; gap: 4px; }
        .hero-title h1 { font-size: 1.75rem; font-weight: 800; margin: 0; color: var(--text); }
        .page-content { padding: 32px 30px; max-width: 1400px; margin: 0 auto; }
        
        .stat-card { background: var(--surface); padding: 24px; border-radius: var(--radius); border: 1px solid var(--border); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); flex: 1; min-width: 200px; }
        .stat-val { font-size: 2.25rem; font-weight: 800; color: var(--text); line-height: 1; margin-bottom: 8px; }
        .stat-label { font-size: 0.9rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
        
        .table-card { background: var(--surface); border-radius: var(--radius); border: 1px solid var(--border); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); overflow: hidden; margin-top: 24px; }
        .table-toolbar { padding: 20px 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; background: #f8fafc; }
        .search-box { position: relative; width: 300px; }
        .search-box i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--text-muted); }
        .search-box input { padding-left: 40px; border-radius: 8px; border: 1px solid var(--border); width: 100%; height: 42px; }
        
        .custom-table { width: 100%; border-collapse: collapse; }
        .custom-table th { padding: 14px 20px; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); font-weight: 700; border-bottom: 2px solid var(--border); text-align: left; white-space: nowrap; }
        .custom-table td { padding: 16px 20px; border-bottom: 1px solid var(--border); vertical-align: middle; font-size: 0.95rem; }
        .custom-table tr:hover { background: #f8fafc; }
        
        .status-badge { padding: 6px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .status-submitted { background: #eff6ff; color: #3b82f6; border: 1px solid #bfdbfe; }
        .status-review { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
        .status-reviewed { background: #ecfdf5; color: #10b981; border: 1px solid #a7f3d0; }
    </style>
</head>
<body>
<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="topbar">
    <div class="topbar-inner">
        <div class="hero-title">
            <div class="eyebrow" style="color:var(--primary); font-weight:700; font-size:0.85rem; text-transform:uppercase;">Administration</div>
            <h1>Internship Work Submissions</h1>
            <div class="sub" style="color:var(--text-muted); font-size:0.95rem;">Review showcase submissions from students across the entire portal.</div>
        </div>
    </div>
</div>

<div class="page-content">
    <?php if (isset($_SESSION['fs_msg'])): ?>
        <div class="alert alert-success alert-dismissible fade show"><i class="fa-solid fa-check-circle me-2"></i><?php echo h($_SESSION['fs_msg']); unset($_SESSION['fs_msg']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['fs_err'])): ?>
        <div class="alert alert-danger alert-dismissible fade show"><i class="fa-solid fa-triangle-exclamation me-2"></i><?php echo h($_SESSION['fs_err']); unset($_SESSION['fs_err']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="d-flex gap-4 flex-wrap">
        <div class="stat-card">
            <div class="stat-val"><?php echo $stats['total']; ?></div>
            <div class="stat-label">Total Submissions</div>
        </div>
        <div class="stat-card" style="border-left: 4px solid var(--info);">
            <div class="stat-val text-info"><?php echo $stats['pending']; ?></div>
            <div class="stat-label">Awaiting Review</div>
        </div>
        <div class="stat-card" style="border-left: 4px solid var(--warning);">
            <div class="stat-val text-warning"><?php echo $stats['review']; ?></div>
            <div class="stat-label">Under Review</div>
        </div>
        <div class="stat-card" style="border-left: 4px solid var(--success);">
            <div class="stat-val text-success"><?php echo $stats['reviewed']; ?></div>
            <div class="stat-label">Reviewed</div>
        </div>
    </div>

    <div class="table-card">
        <div class="table-toolbar">
            <div class="search-box">
                <i class="fa-solid fa-search"></i>
                <input type="text" id="searchInput" placeholder="Search by name, roll no..." onkeyup="filterTable()">
            </div>
            <div class="d-flex gap-2">
                <select id="statusFilter" class="form-select" style="width:160px; height:42px; border-radius:8px;" onchange="filterTable()">
                    <option value="">All Statuses</option>
                    <option value="Submitted">Submitted</option>
                    <option value="Under Review">Under Review</option>
                    <option value="Reviewed">Reviewed</option>
                </select>
            </div>
        </div>
        
        <div class="table-responsive">
            <table class="custom-table" id="submissionsTable">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Division</th>
                        <th>Work Title</th>
                        <th>Category</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($submissions)): ?>
                        <tr><td colspan="7" class="text-center py-5 text-muted">No submissions found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($submissions as $sub): 
                            $statusClass = 'status-submitted';
                            if ($sub['status'] === 'Under Review') $statusClass = 'status-review';
                            if ($sub['status'] === 'Reviewed') $statusClass = 'status-reviewed';
                        ?>
                        <tr class="searchable-row" data-status="<?php echo h($sub['status']); ?>">
                            <td>
                                <div class="fw-bold" style="color:var(--text);"><?php echo h($sub['full_name']); ?></div>
                                <div style="font-size:0.8rem; color:var(--text-muted);"><?php echo h($sub['enrollment_no']); ?></div>
                            </td>
                            <td><?php echo h($sub['division']); ?></td>
                            <td style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo h($sub['title']); ?>">
                                <strong><?php echo h($sub['title']); ?></strong>
                            </td>
                            <td><span class="badge bg-light text-dark border"><i class="fa-solid fa-tag text-muted me-1"></i><?php echo h($sub['category']); ?></span></td>
                            <td style="font-size:0.85rem;"><?php echo date('d M Y', strtotime($sub['created_at'])); ?></td>
                            <td><span class="status-badge <?php echo $statusClass; ?>"><?php echo h($sub['status']); ?></span></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-light border fw-bold text-primary" onclick="openModal(<?php echo htmlspecialchars(json_encode($sub), ENT_QUOTES, 'UTF-8'); ?>)">View</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</main>
</div>

<!-- Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px; border:none; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
            <div class="modal-header border-bottom-0 pb-0" style="padding: 24px 32px;">
                <h5 class="modal-title fw-bold fs-4" id="mTitle">Work Title</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding: 24px 32px;">
                <div class="d-flex gap-3 mb-4 flex-wrap">
                    <span class="badge bg-light text-dark border py-2 px-3" id="mCategory"><i class="fa-solid fa-tag me-2"></i></span>
                    <span class="badge bg-light text-dark border py-2 px-3" id="mStudent"><i class="fa-solid fa-user me-2"></i></span>
                    <span class="badge bg-light text-dark border py-2 px-3" id="mDate"><i class="fa-regular fa-calendar me-2"></i></span>
                    <span class="badge bg-light text-dark border py-2 px-3 d-none" id="mCompany"><i class="fa-regular fa-building me-2"></i></span>
                </div>
                
                <h6 class="fw-bold text-muted text-uppercase" style="font-size:0.8rem; letter-spacing:0.5px;">Description</h6>
                <div class="p-3 mb-4" style="background:#f8fafc; border-radius:12px; border:1px solid var(--border); font-size:0.95rem; line-height:1.6;" id="mDesc"></div>
                
                <div class="row mb-4">
                    <div class="col-6 d-none" id="mTechWrap">
                        <h6 class="fw-bold text-muted text-uppercase" style="font-size:0.8rem; letter-spacing:0.5px;">Tech Used</h6>
                        <div class="fw-medium" id="mTech"></div>
                    </div>
                </div>

                <div class="d-flex gap-3 mb-4 p-4" style="background: linear-gradient(to right, #f8fafc, #ffffff); border-radius:12px; border:1px solid var(--border);">
                    <div class="flex-grow-1 d-none" id="mUrlWrap">
                        <h6 class="fw-bold text-muted text-uppercase mb-2" style="font-size:0.8rem; letter-spacing:0.5px;">External Link</h6>
                        <a href="#" target="_blank" id="mUrl" class="btn btn-outline-primary btn-sm fw-bold"><i class="fa-solid fa-arrow-up-right-from-square me-2"></i>Open Project Link</a>
                    </div>
                    <div class="flex-grow-1 d-none" id="mFileWrap">
                        <h6 class="fw-bold text-muted text-uppercase mb-2" style="font-size:0.8rem; letter-spacing:0.5px;">Attached File</h6>
                        <a href="#" target="_blank" id="mFile" class="btn btn-primary btn-sm fw-bold shadow-sm"><i class="fa-solid fa-download me-2"></i>Download File</a>
                    </div>
                </div>

                <form method="POST" action="student_showcase.php" class="p-4" style="background:#f1f5f9; border-radius:12px;">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="submission_id" id="mId">
                    <h6 class="fw-bold mb-3">Update Review Status</h6>
                    <div class="d-flex gap-3">
                        <select name="new_status" id="mStatus" class="form-select fw-medium" style="width:200px;">
                            <option value="Submitted">Submitted</option>
                            <option value="Under Review">Under Review</option>
                            <option value="Reviewed">Reviewed</option>
                        </select>
                        <button type="submit" class="btn btn-dark fw-bold px-4">Save Status</button>
                    </div>
                    <div id="mReviewerWrap" class="mt-2 text-muted" style="font-size:0.8rem; display:none;">
                        Last reviewed by: <strong id="mReviewer"></strong>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function filterTable() {
    const input = document.getElementById('searchInput').value.toLowerCase();
    const status = document.getElementById('statusFilter').value;
    const rows = document.querySelectorAll('.searchable-row');
    
    rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        const rowStatus = row.getAttribute('data-status');
        
        const matchesText = text.includes(input);
        const matchesStatus = status === '' || rowStatus === status;
        
        if (matchesText && matchesStatus) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function openModal(data) {
    document.getElementById('mId').value = data.id;
    document.getElementById('mTitle').textContent = data.title;
    document.getElementById('mCategory').innerHTML = '<i class="fa-solid fa-tag me-2"></i>' + data.category;
    document.getElementById('mStudent').innerHTML = '<i class="fa-solid fa-user me-2"></i>' + data.full_name + ' (' + data.enrollment_no + ')';
    
    // Format Date string
    const d = new Date(data.created_at);
    document.getElementById('mDate').innerHTML = '<i class="fa-regular fa-calendar me-2"></i>' + d.toLocaleDateString('en-GB', {day: 'numeric', month: 'short', year: 'numeric'});
    
    if (data.company_name) {
        document.getElementById('mCompany').innerHTML = '<i class="fa-regular fa-building me-2"></i>' + data.company_name;
        document.getElementById('mCompany').classList.remove('d-none');
    } else {
        document.getElementById('mCompany').classList.add('d-none');
    }
    
    const div = document.createElement('div');
    div.innerText = data.description;
    document.getElementById('mDesc').innerHTML = div.innerHTML.replace(/\n/g, '<br>');
    
    if (data.tech_used) {
        document.getElementById('mTech').textContent = data.tech_used;
        document.getElementById('mTechWrap').classList.remove('d-none');
    } else {
        document.getElementById('mTechWrap').classList.add('d-none');
    }
    
    if (data.url) {
        document.getElementById('mUrl').href = data.url;
        document.getElementById('mUrlWrap').classList.remove('d-none');
    } else {
        document.getElementById('mUrlWrap').classList.add('d-none');
    }
    
    if (data.file_path) {
        document.getElementById('mFile').href = '../uploads/showcase/' + data.file_path;
        document.getElementById('mFileWrap').classList.remove('d-none');
    } else {
        document.getElementById('mFileWrap').classList.add('d-none');
    }
    
    document.getElementById('mStatus').value = data.status;
    
    if (data.reviewer_name) {
        document.getElementById('mReviewer').textContent = data.reviewer_name;
        document.getElementById('mReviewerWrap').style.display = 'block';
    } else {
        document.getElementById('mReviewerWrap').style.display = 'none';
    }
    
    new bootstrap.Modal(document.getElementById('detailsModal')).show();
}
</script>
<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>
</body>
</html>
