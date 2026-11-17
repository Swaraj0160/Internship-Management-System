<?php
/* -----------------------------------------------------------------
   InternHub — University-Wide Student Directory
   Empowers faculty to discover and mentor students across MIT-ADT University.
   ----------------------------------------------------------------- */

session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'faculty') {
    header("Location: ../login.php");
    exit();
}

$faculty_id = (int)$_SESSION['user_id'];

// Ensure faculty_bookmarks table exists
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS faculty_bookmarks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    faculty_id INT NOT NULL,
    student_id INT NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fac_stu (faculty_id, student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Fetch bookmarks for this faculty
$bookmarked_ids = [];
$bm_q = mysqli_query($conn, "SELECT student_id FROM faculty_bookmarks WHERE faculty_id='$faculty_id'");
if ($bm_q) {
    while ($bm = mysqli_fetch_assoc($bm_q)) {
        $bookmarked_ids[] = (int)$bm['student_id'];
    }
}

// Search and filter parameters
$q = trim($_GET['q'] ?? '');
$dept_filter = trim($_GET['dept'] ?? '');
$div_filter = trim($_GET['division'] ?? '');
$only_bookmarked = isset($_GET['bookmarked']) && $_GET['bookmarked'] === '1';

// Build query
$where = ["u.role='student'"];

if ($q !== '') {
    $safe_q = mysqli_real_escape_string($conn, $q);
    $where[] = "(u.full_name LIKE '%$safe_q%' OR u.roll_no LIKE '%$safe_q%' OR u.email LIKE '%$safe_q%' OR sp.headline LIKE '%$safe_q%' OR sp.bio LIKE '%$safe_q%')";
}

if ($dept_filter !== '') {
    $safe_dept = mysqli_real_escape_string($conn, $dept_filter);
    $where[] = "u.department = '$safe_dept'";
}

if ($div_filter !== '') {
    $safe_div = mysqli_real_escape_string($conn, $div_filter);
    $where[] = "u.division = '$safe_div'";
}

if ($only_bookmarked && !empty($bookmarked_ids)) {
    $where[] = "u.id IN (" . implode(',', $bookmarked_ids) . ")";
} elseif ($only_bookmarked && empty($bookmarked_ids)) {
    $where[] = "1=0";
}

$where_sql = implode(' AND ', $where);

// Metrics
$total_univ_students = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt FROM users WHERE role='student'"))['cnt'] ?? 0);
$total_active_internships = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt FROM internships WHERE status='Approved'"))['cnt'] ?? 0);
$total_profiles = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt FROM student_profiles"))['cnt'] ?? 0);

// Paginated Query
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 24;
$offset = ($page - 1) * $limit;

$count_res = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM users u LEFT JOIN student_profiles sp ON sp.user_id = u.id WHERE $where_sql");
$total_filtered = (int)(mysqli_fetch_assoc($count_res)['cnt'] ?? 0);
$total_pages = ceil($total_filtered / $limit);

$students_q = mysqli_query(
    $conn,
    "SELECT u.id, u.full_name, u.roll_no, u.division, u.department, u.email,
            sp.headline, sp.bio, sp.cgpa,
            (SELECT COUNT(*) FROM projects p WHERE p.student_id = u.id) as project_count,
            (SELECT status FROM internships i WHERE i.student_id = u.id ORDER BY id DESC LIMIT 1) as internship_status
     FROM users u
     LEFT JOIN student_profiles sp ON sp.user_id = u.id
     WHERE $where_sql
     ORDER BY u.full_name ASC
     LIMIT $limit OFFSET $offset"
);

$students = [];
if ($students_q) {
    while ($row = mysqli_fetch_assoc($students_q)) {
        $students[] = $row;
    }
}

// Distinct departments and divisions for filter dropdowns
$depts_res = mysqli_query($conn, "SELECT DISTINCT department FROM users WHERE role='student' AND department IS NOT NULL AND department <> '' ORDER BY department ASC");
$depts = [];
if ($depts_res) {
    while ($dr = mysqli_fetch_assoc($depts_res)) $depts[] = $dr['department'];
}

$divs_res = mysqli_query($conn, "SELECT DISTINCT division FROM users WHERE role='student' AND division IS NOT NULL AND division <> '' ORDER BY division ASC");
$divs = [];
if ($divs_res) {
    while ($dr = mysqli_fetch_assoc($divs_res)) $divs[] = $dr['division'];
}

$activePage = 'student_directory.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>University Student Directory | InternHub — MIT-ADT University</title>
    <?php include __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
        .directory-container {
            padding: 0 32px 36px;
        }

        .dir-header-box {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 22px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .dir-title-group h1 {
            font-size: 1.6rem;
            font-weight: 800;
            color: #1e1b4b;
            letter-spacing: -0.02em;
        }

        .dir-title-group p {
            font-size: 0.88rem;
            color: #64748b;
            margin-top: 3px;
        }

        /* Metrics Strip */
        .metrics-strip {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        .metric-card {
            background: #ffffff;
            border-radius: 14px;
            padding: 16px 20px;
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
        }

        .metric-icon-box {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .metric-num {
            font-size: 1.45rem;
            font-weight: 800;
            color: #1e1b4b;
            line-height: 1;
        }

        .metric-label {
            font-size: 0.76rem;
            font-weight: 600;
            color: #64748b;
            margin-top: 3px;
        }

        /* Filter Controls */
        .search-filter-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 18px 22px;
            border: 1px solid #e2e8f0;
            margin-bottom: 24px;
            display: flex;
            flex-direction: column;
            gap: 14px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.02);
        }

        .filter-row-top {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }

        .search-input-wrap {
            flex: 1;
            position: relative;
            min-width: 260px;
        }

        .search-input-wrap input {
            width: 100%;
            padding: 10px 16px 10px 40px;
            border-radius: 10px;
            border: 1px solid #cbd5e1;
            font-size: 0.88rem;
            color: #1e1b4b;
            outline: none;
            font-family: inherit;
        }

        .search-input-wrap i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }

        .filter-select {
            padding: 10px 14px;
            border-radius: 10px;
            border: 1px solid #cbd5e1;
            font-size: 0.85rem;
            color: #334155;
            background: #ffffff;
            outline: none;
            font-family: inherit;
        }

        .btn-filter-apply {
            padding: 10px 20px;
            border-radius: 10px;
            background: #7c3aed;
            color: #ffffff;
            border: none;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-filter-apply:hover {
            background: #6d28d9;
        }

        .filter-pills-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .pill-toggle {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 600;
            text-decoration: none;
            color: #64748b;
            background: #f1f5f9;
            border: 1px solid transparent;
            transition: all 0.15s ease;
        }

        .pill-toggle:hover {
            background: #e2e8f0;
            color: #1e1b4b;
        }

        .pill-toggle.active {
            background: #fef3c7;
            color: #b45309;
            border-color: #fde68a;
        }

        /* Student Grid */
        .student-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
            margin-bottom: 28px;
        }

        .student-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            padding: 22px;
            display: flex;
            flex-direction: column;
            gap: 14px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.02);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            position: relative;
        }

        .student-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 28px rgba(61, 20, 82, 0.08);
            border-color: #d8b4fe;
        }

        .card-top {
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }

        .card-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, #7c3aed, #4f46e5);
            color: #ffffff;
            font-weight: 800;
            font-size: 1.15rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 4px 10px rgba(124, 58, 237, 0.25);
        }

        .card-meta {
            flex: 1;
            min-width: 0;
        }

        .card-name {
            font-size: 1.0rem;
            font-weight: 700;
            color: #1e1b4b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .card-prn {
            font-size: 0.74rem;
            color: #64748b;
            margin-top: 2px;
        }

        .card-badge-row {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 6px;
            flex-wrap: wrap;
        }

        .tag-pill {
            font-size: 0.68rem;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 6px;
            background: #f1f5f9;
            color: #475569;
        }

        .tag-dept { background: #ede9fe; color: #7c3aed; }
        .tag-div { background: #fef3c7; color: #b45309; }

        .bookmark-btn {
            background: none;
            border: none;
            cursor: pointer;
            color: #cbd5e1;
            font-size: 1.15rem;
            transition: color 0.15s ease, transform 0.15s ease;
        }

        .bookmark-btn:hover {
            transform: scale(1.15);
            color: #fbbf24;
        }

        .bookmark-btn.bookmarked {
            color: #f59e0b;
        }

        .card-headline {
            font-size: 0.82rem;
            color: #475569;
            line-height: 1.45;
            min-height: 38px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .card-footer {
            margin-top: auto;
            border-top: 1px solid #f1f5f9;
            padding-top: 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card-stat {
            font-size: 0.74rem;
            color: #64748b;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-view-profile {
            padding: 7px 14px;
            border-radius: 8px;
            background: #7c3aed;
            color: #ffffff;
            font-size: 0.78rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .btn-view-profile:hover {
            background: #6d28d9;
        }

        /* Pagination */
        .pagination-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .page-btn {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #475569;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.82rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .page-btn:hover {
            background: #f8fafc;
            border-color: #94a3b8;
        }

        .page-btn.active {
            background: #7c3aed;
            border-color: #7c3aed;
            color: #ffffff;
        }
    </style>
</head>
<body>

<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="directory-container">
    <!-- Header -->
    <div class="dir-header-box">
        <div class="dir-title-group">
            <h1>University Student Directory</h1>
            <p>Discover student talent, explore professional profiles, and guide mentorship across MIT-ADT University.</p>
        </div>
    </div>

    <!-- Metrics Strip -->
    <div class="metrics-strip">
        <div class="metric-card">
            <div class="metric-icon-box" style="background: #ede9fe; color: #7c3aed;"><i class="fa-solid fa-users"></i></div>
            <div>
                <div class="metric-num"><?php echo $total_univ_students; ?></div>
                <div class="metric-label">University Students</div>
            </div>
        </div>
        <div class="metric-card">
            <div class="metric-icon-box" style="background: #d1fae5; color: #059669;"><i class="fa-solid fa-briefcase"></i></div>
            <div>
                <div class="metric-num"><?php echo $total_active_internships; ?></div>
                <div class="metric-label">Authorized Internships</div>
            </div>
        </div>
        <div class="metric-card">
            <div class="metric-icon-box" style="background: #fef3c7; color: #b45309;"><i class="fa-solid fa-id-card"></i></div>
            <div>
                <div class="metric-num"><?php echo $total_profiles; ?></div>
                <div class="metric-label">Active Portfolios</div>
            </div>
        </div>
        <div class="metric-card">
            <div class="metric-icon-box" style="background: #ffedd5; color: #ea580c;"><i class="fa-solid fa-star"></i></div>
            <div>
                <div class="metric-num"><?php echo count($bookmarked_ids); ?></div>
                <div class="metric-label">Bookmarked by You</div>
            </div>
        </div>
    </div>

    <!-- Search & Filters -->
    <form method="GET" action="" class="search-filter-card">
        <div class="filter-row-top">
            <div class="search-input-wrap">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" name="q" placeholder="Search by student name, PRN, email, or headline..." value="<?php echo h($q); ?>">
            </div>

            <select name="dept" class="filter-select">
                <option value="">All Departments</option>
                <?php foreach ($depts as $d): ?>
                    <option value="<?php echo h($d); ?>" <?php echo $dept_filter === $d ? 'selected' : ''; ?>><?php echo h($d); ?></option>
                <?php endforeach; ?>
            </select>

            <select name="division" class="filter-select">
                <option value="">All Divisions</option>
                <?php foreach ($divs as $div): ?>
                    <option value="<?php echo h($div); ?>" <?php echo $div_filter === $div ? 'selected' : ''; ?>><?php echo h($div); ?></option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn-filter-apply">Filter</button>
        </div>

        <div class="filter-pills-row">
            <a href="?<?php echo http_build_query(array_merge($_GET, ['bookmarked' => $only_bookmarked ? '0' : '1'])); ?>" class="pill-toggle <?php echo $only_bookmarked ? 'active' : ''; ?>">
                <i class="fa-solid fa-star"></i>
                <span><?php echo $only_bookmarked ? 'Showing Bookmarked' : 'Show Only Bookmarked'; ?></span>
            </a>
            <?php if (!empty($q) || !empty($dept_filter) || !empty($div_filter) || $only_bookmarked): ?>
                <a href="student_directory.php" style="font-size: 0.78rem; color: #ef4444; text-decoration: none; font-weight: 600; margin-left: 6px;">
                    <i class="fa-solid fa-xmark"></i> Clear Filters
                </a>
            <?php endif; ?>
            <span style="margin-left: auto; font-size: 0.78rem; color: #64748b; font-weight: 500;">
                Showing <?php echo count($students); ?> of <?php echo $total_filtered; ?> students
            </span>
        </div>
    </form>

    <!-- Student Cards Grid -->
    <div class="student-grid">
        <?php if (!empty($students)): ?>
            <?php foreach ($students as $stu): ?>
                <?php
                $sid = (int)$stu['id'];
                $is_bm = in_array($sid, $bookmarked_ids, true);
                $initial = strtoupper(substr($stu['full_name'] ?: 'S', 0, 1));
                ?>
                <div class="student-card">
                    <div class="card-top">
                        <div class="card-avatar"><?php echo h($initial); ?></div>
                        <div class="card-meta">
                            <div class="card-name" title="<?php echo h($stu['full_name']); ?>"><?php echo h($stu['full_name']); ?></div>
                            <div class="card-prn"><?php echo h($stu['roll_no'] ?: $stu['email']); ?></div>
                            <div class="card-badge-row">
                                <span class="tag-pill tag-dept"><?php echo h($stu['department'] ?: 'SOC'); ?></span>
                                <span class="tag-pill tag-div"><?php echo h($stu['division'] ?: 'Gen'); ?></span>
                            </div>
                        </div>
                        <button type="button" class="bookmark-btn <?php echo $is_bm ? 'bookmarked' : ''; ?>" onclick="toggleBookmark(<?php echo $sid; ?>, this)" title="<?php echo $is_bm ? 'Remove Bookmark' : 'Bookmark Student'; ?>">
                            <i class="fa-solid fa-star"></i>
                        </button>
                    </div>

                    <div class="card-headline">
                        <?php echo h($stu['headline'] ?: $stu['bio'] ?: 'Computer Engineering student at MIT-ADT University passionate about software development and engineering innovation.'); ?>
                    </div>

                    <div class="card-footer">
                        <div class="card-stat">
                            <i class="fa-solid fa-laptop-code" style="color: #7c3aed;"></i>
                            <span><?php echo (int)$stu['project_count']; ?> Projects</span>
                        </div>
                        <a href="student_portfolios.php?id=<?php echo $sid; ?>" class="btn-view-profile">View Portfolio &rarr;</a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div style="grid-column: 1 / -1; background: #fff; border-radius: 16px; border: 1px solid #e2e8f0; padding: 48px; text-align: center; color: #94a3b8;">
                <i class="fa-solid fa-user-slash" style="font-size: 2.2rem; margin-bottom: 12px; display: block; color: #cbd5e1;"></i>
                <h3 style="font-size: 1.1rem; font-weight: 700; color: #334155;">No students found</h3>
                <p style="font-size: 0.85rem; color: #64748b; margin-top: 4px;">Try refining your search query or clear the active filters.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="pagination-row">
            <?php for ($i = 1; $i <= min($total_pages, 8); $i++): ?>
                <?php
                $url_params = array_merge($_GET, ['page' => $i]);
                ?>
                <a href="?<?php echo http_build_query($url_params); ?>" class="page-btn <?php echo $page === $i ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<script>
function toggleBookmark(studentId, btn) {
    const fd = new FormData();
    fd.append('action', 'toggle_bookmark');
    fd.append('student_id', studentId);

    fetch('api/faculty_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                if (data.bookmarked) {
                    btn.classList.add('bookmarked');
                    btn.title = 'Remove Bookmark';
                } else {
                    btn.classList.remove('bookmarked');
                    btn.title = 'Bookmark Student';
                }
            } else {
                alert(data.error || 'Failed to update bookmark.');
            }
        })
        .catch(err => {
            alert('Network error.');
        });
}
</script>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>
</body>
</html>
