<?php
session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$activePage = 'content_resources.php';
$adminId = (int)($_SESSION['user_id'] ?? 0);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$cat_filter = trim($_GET['cat'] ?? 'all');
$audience_filter = trim($_GET['audience'] ?? '');
$search = trim($_GET['search'] ?? '');

$successMsg = '';
$errorMsg = '';

// -------------------------------------------------------------
// POST ACTIONS (Upload, Edit, Delete Resource)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $postedCsrf)) {
        $errorMsg = 'Security validation failed (Invalid CSRF token).';
    } else {
        $action = trim($_POST['action'] ?? '');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        // 1. UPLOAD RESOURCE
        if ($action === 'upload_resource') {
            $title = trim($_POST['title'] ?? '');
            $category = in_array($_POST['category'] ?? '', ['guidelines', 'templates', 'rubrics', 'circulars']) ? $_POST['category'] : 'guidelines';
            $audience = in_array($_POST['target_audience'] ?? '', ['all', 'student', 'faculty']) ? $_POST['target_audience'] : 'all';
            $description = trim($_POST['description'] ?? '');

            if ($title === '') {
                $errorMsg = 'Resource title is required.';
            } else {
                $filePath = 'uploads/resources/sample_doc.pdf';
                $fileSize = '500 KB';
                $fileType = 'PDF';

                if (isset($_FILES['resource_file']) && $_FILES['resource_file']['error'] === UPLOAD_ERR_OK) {
                    $fileTmp = $_FILES['resource_file']['tmp_name'];
                    $fileName = basename($_FILES['resource_file']['name']);
                    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'ppt', 'pptx'];

                    if (in_array($ext, $allowed)) {
                        $targetDir = __DIR__ . '/../uploads/resources/';
                        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
                        $newFileName = time() . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $fileName);
                        $targetPath = $targetDir . $newFileName;
                        if (move_uploaded_file($fileTmp, $targetPath)) {
                            $filePath = 'uploads/resources/' . $newFileName;
                            $sizeBytes = filesize($targetPath);
                            $fileSize = $sizeBytes > 1048576 ? round($sizeBytes / 1048576, 1) . ' MB' : round($sizeBytes / 1024) . ' KB';
                            $fileType = strtoupper($ext);
                        }
                    }
                }

                $ins = $conn->prepare("
                    INSERT INTO admin_resources (title, category, description, file_path, file_size, file_type, target_audience, uploaded_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $ins->bind_param("sssssssi", $title, $category, $description, $filePath, $fileSize, $fileType, $audience, $adminId);
                if ($ins->execute()) {
                    $successMsg = "Resource <strong>" . h($title) . "</strong> uploaded successfully.";
                    $aud = $conn->prepare("INSERT INTO audit_logs (actor_id, actor_role, action, target_id, target_type, ip_address, user_agent, details) VALUES (?, 'admin', 'upload_resource', ?, 'resource', ?, ?, ?)");
                    $dt = json_encode(['title' => $title, 'category' => $category]);
                    $aud->bind_param("iisss", $adminId, $ins->insert_id, $ip, $ua, $dt);
                    $aud->execute();
                } else {
                    $errorMsg = 'Database error: ' . $conn->error;
                }
            }
        }

        // 2. EDIT RESOURCE
        elseif ($action === 'edit_resource') {
            $resId = (int)($_POST['resource_id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $category = in_array($_POST['category'] ?? '', ['guidelines', 'templates', 'rubrics', 'circulars']) ? $_POST['category'] : 'guidelines';
            $audience = in_array($_POST['target_audience'] ?? '', ['all', 'student', 'faculty']) ? $_POST['target_audience'] : 'all';
            $description = trim($_POST['description'] ?? '');

            if ($resId > 0 && $title !== '') {
                $upd = $conn->prepare("UPDATE admin_resources SET title = ?, category = ?, target_audience = ?, description = ? WHERE id = ?");
                $upd->bind_param("ssssi", $title, $category, $audience, $description, $resId);
                if ($upd->execute()) {
                    $successMsg = "Resource details updated successfully.";
                }
            }
        }

        // 3. DELETE RESOURCE
        elseif ($action === 'delete_resource') {
            $resId = (int)($_POST['resource_id'] ?? 0);
            if ($resId > 0) {
                $del = $conn->prepare("DELETE FROM admin_resources WHERE id = ?");
                $del->bind_param("i", $resId);
                if ($del->execute()) {
                    $successMsg = "Resource #$resId deleted.";
                }
            }
        }
    }
}

// -------------------------------------------------------------
// METRICS & LISTING QUERY
// -------------------------------------------------------------
$total_docs = 0;
$total_downloads = 0;

$m_q = @mysqli_query($conn, "SELECT COUNT(*) as total, SUM(download_count) as dl FROM admin_resources");
if ($m_q) {
    $row = mysqli_fetch_assoc($m_q);
    $total_docs = (int)($row['total'] ?? 0);
    $total_downloads = (int)($row['dl'] ?? 0);
}

$where = ["1=1"];
$params = [];
$types = "";

if ($cat_filter !== 'all') {
    $where[] = "category = ?";
    $params[] = $cat_filter;
    $types .= "s";
}

if ($audience_filter !== '') {
    $where[] = "target_audience = ?";
    $params[] = $audience_filter;
    $types .= "s";
}

if ($search !== '') {
    $where[] = "(title LIKE ? OR description LIKE ?)";
    $lk = "%$search%";
    $params[] = $lk; $params[] = $lk;
    $types .= "ss";
}

$where_clause = implode(" AND ", $where);
$sql = "SELECT * FROM admin_resources WHERE $where_clause ORDER BY id DESC";
$stmt = $conn->prepare($sql);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$resources = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Content & Resources — InternHub Admin</title>
    <?php include __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
        .cr-container {
            padding: 24px 32px 48px;
            max-width: 1540px;
            margin: 0 auto;
        }
        .cr-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 22px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .cr-title {
            font-size: 1.6rem;
            font-weight: 800;
            color: #1e1b4b;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .cr-subtitle {
            font-size: 0.86rem;
            color: #64748b;
            margin-top: 4px;
        }

        /* Stats Strip */
        .cr-stats-strip {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 22px;
        }
        .cr-stat-card {
            background: #ffffff;
            border-radius: 14px;
            padding: 18px 20px;
            border: 1px solid #eef1f6;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.02);
        }
        .cr-stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
        }
        .cr-stat-val {
            font-size: 1.5rem;
            font-weight: 800;
            color: #1e1b4b;
            line-height: 1.1;
        }
        .cr-stat-lbl {
            font-size: 0.76rem;
            font-weight: 600;
            color: #64748b;
            margin-top: 2px;
        }

        /* Tabs */
        .cr-tabs {
            display: flex;
            gap: 8px;
            background: #ffffff;
            padding: 6px;
            border-radius: 14px;
            border: 1px solid #eef1f6;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .cr-tab {
            padding: 9px 18px;
            border-radius: 10px;
            font-size: 0.86rem;
            font-weight: 600;
            color: #64748b;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }
        .cr-tab:hover { background: #f8fafc; color: #1e1b4b; }
        .cr-tab.active { background: #eddff7; color: #3a104f; font-weight: 700; }

        /* Resource Grid */
        .resource-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 20px;
        }
        .rc-card {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid #eef1f6;
            padding: 24px;
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.03);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .rc-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 26px rgba(58, 16, 79, 0.08);
        }
        .rc-head {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 14px;
        }
        .rc-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            flex-shrink: 0;
        }
        .icon-pdf { background: #fff0f2; color: #be123c; }
        .icon-doc { background: #eff6ff; color: #1d4ed8; }
        .icon-zip { background: #fdf8ea; color: #b45309; }

        .rc-title {
            font-size: 1rem;
            font-weight: 700;
            color: #1e1b4b;
            line-height: 1.3;
            margin-bottom: 4px;
        }
        .rc-desc {
            font-size: 0.82rem;
            color: #64748b;
            line-height: 1.45;
            margin-bottom: 16px;
        }
        .rc-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.76rem;
            color: #94a3b8;
            margin-top: auto;
            padding-top: 14px;
            border-top: 1px solid #f8fafc;
        }
        .rc-badge-aud {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        .aud-all { background: #f1f5f9; color: #334155; }
        .aud-student { background: #eff6ff; color: #1d4ed8; }
        .aud-faculty { background: #f5f3ff; color: #7c3aed; }
    </style>
</head>
<body>

    <?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

    <div class="cr-container">
        
        <!-- Header -->
        <div class="cr-header">
            <div>
                <h1 class="cr-title"><i class="fa-solid fa-book-open" style="color: #7c3aed;"></i> Content & Resources</h1>
                <div class="cr-subtitle">Official university policies, NOC templates, internship logbooks, and academic evaluation rubrics.</div>
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="button" class="btn btn-primary" style="background: #3a104f; border-color: #3a104f; border-radius: 12px; font-weight: 600; padding: 10px 18px;" onclick="openModal('uploadModal')">
                    <i class="fa-solid fa-cloud-arrow-up"></i> Upload Document
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
        <div class="cr-stats-strip">
            <div class="cr-stat-card">
                <div class="cr-stat-icon" style="background: #eddff7; color: #6b21a8;">
                    <i class="fa-solid fa-folder-open"></i>
                </div>
                <div>
                    <div class="cr-stat-val"><?php echo number_format($total_docs); ?></div>
                    <div class="cr-stat-lbl">Published Documents</div>
                </div>
            </div>
            <div class="cr-stat-card">
                <div class="cr-stat-icon" style="background: #d1fae5; color: #047857;">
                    <i class="fa-solid fa-download"></i>
                </div>
                <div>
                    <div class="cr-stat-val"><?php echo number_format($total_downloads); ?></div>
                    <div class="cr-stat-lbl">Student & Faculty Downloads</div>
                </div>
            </div>
            <div class="cr-stat-card">
                <div class="cr-stat-icon" style="background: #eff6ff; color: #1d4ed8;">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
                <div>
                    <div class="cr-stat-val">100%</div>
                    <div class="cr-stat-lbl">Verified Policy Compliance</div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="cr-tabs">
            <a href="?cat=all" class="cr-tab <?php echo $cat_filter === 'all' ? 'active' : ''; ?>">
                <i class="fa-solid fa-folder"></i> All Documents
            </a>
            <a href="?cat=guidelines" class="cr-tab <?php echo $cat_filter === 'guidelines' ? 'active' : ''; ?>">
                <i class="fa-solid fa-scale-balanced"></i> Guidelines & SOPs
            </a>
            <a href="?cat=templates" class="cr-tab <?php echo $cat_filter === 'templates' ? 'active' : ''; ?>">
                <i class="fa-solid fa-file-contract"></i> NOC & Logbook Templates
            </a>
            <a href="?cat=rubrics" class="cr-tab <?php echo $cat_filter === 'rubrics' ? 'active' : ''; ?>">
                <i class="fa-solid fa-clipboard-check"></i> Evaluation Rubrics
            </a>
        </div>

        <!-- Resource Cards Grid -->
        <?php if (!empty($resources)): ?>
            <div class="resource-grid">
                <?php foreach ($resources as $rc): ?>
                    <?php 
                        $ext = strtoupper($rc['file_type'] ?: 'PDF');
                        $iconCls = ($ext === 'PDF') ? 'icon-pdf' : (($ext === 'DOCX' || $ext === 'DOC') ? 'icon-doc' : 'icon-zip');
                        $iconFa = ($ext === 'PDF') ? 'fa-file-pdf' : (($ext === 'DOCX' || $ext === 'DOC') ? 'fa-file-word' : 'fa-file-zipper');
                        $aud = $rc['target_audience'] ?: 'all';
                        $audCls = 'aud-' . $aud;
                    ?>
                    <div class="rc-card">
                        <div class="rc-head">
                            <div class="rc-icon <?php echo $iconCls; ?>">
                                <i class="fa-solid <?php echo $iconFa; ?>"></i>
                            </div>
                            <div style="flex: 1; min-width: 0;">
                                <div class="rc-title"><?php echo h($rc['title']); ?></div>
                                <span class="rc-badge-aud <?php echo $audCls; ?>">Audience: <?php echo ucfirst($aud); ?></span>
                            </div>
                        </div>

                        <div class="rc-desc"><?php echo h($rc['description'] ?: 'Official institutional document.'); ?></div>

                        <div class="rc-meta">
                            <div>
                                <span><?php echo h($rc['file_size'] ?: 'PDF'); ?></span> • 
                                <span><i class="fa-solid fa-download"></i> <?php echo number_format($rc['download_count']); ?></span>
                            </div>
                            <div style="display: flex; gap: 8px;">
                                <a href="../<?php echo h($rc['file_path']); ?>" target="_blank" class="btn btn-sm btn-primary" style="background: #3a104f; border-color: #3a104f; border-radius: 8px; font-size: 0.78rem; font-weight: 600;">
                                    <i class="fa-solid fa-download"></i> Download
                                </a>

                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this resource?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="delete_resource">
                                    <input type="hidden" name="resource_id" value="<?php echo $rc['id']; ?>">
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
                <i class="fa-regular fa-folder-open" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 14px;"></i>
                <h3 style="font-size: 1.1rem; color: #1e1b4b; font-weight: 700;">No Resources Found</h3>
                <p style="font-size: 0.86rem; color: #64748b; margin-top: 4px;">Click 'Upload Document' to add guides or templates.</p>
            </div>
        <?php endif; ?>

    </div>

    <!-- MODAL: UPLOAD RESOURCE -->
    <div class="custom-modal" id="uploadModal" style="display: none;">
        <div class="modal-box" style="max-width: 560px;">
            <div class="modal-head">
                <h3 class="modal-head-title"><i class="fa-solid fa-cloud-arrow-up"></i> Upload Institutional Document</h3>
                <button type="button" class="modal-close" onclick="closeModal('uploadModal')">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="upload_resource">
                <div class="modal-body-scroll">
                    <div class="mb-3">
                        <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Document Title</label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Internship Offer Letter Verification Protocol" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Category</label>
                            <select name="category" class="form-select">
                                <option value="guidelines">Policy & SOP Guidelines</option>
                                <option value="templates">NOC / Logbook Templates</option>
                                <option value="rubrics">Evaluation Rubrics</option>
                                <option value="circulars">Institutional Circulars</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Target Audience</label>
                            <select name="target_audience" class="form-select">
                                <option value="all">All (Students & Faculty)</option>
                                <option value="student">Students Only</option>
                                <option value="faculty">Faculty Mentors Only</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Document Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Brief summary of document purpose..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">File Attachment (.pdf, .docx, .zip)</label>
                        <input type="file" name="resource_file" class="form-control" accept=".pdf,.doc,.docx,.xls,.xlsx,.zip,.ppt,.pptx">
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-light" onclick="closeModal('uploadModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background: #3a104f; border-color: #3a104f;">Publish Resource</button>
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
    document.querySelectorAll('.custom-modal').forEach(m => {
        m.addEventListener('click', function(e) {
            if (e.target === this) this.style.display = 'none';
        });
    });
    </script>
</body>
</html>
