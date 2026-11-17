<?php
/**
 * InternHub — Discover Students Directory (discover.php)
 * Search and connect with peers across departments, skills, and projects
 */

session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../login.php");
    exit;
}

$student_id = (int)$_SESSION['user_id'];
$activePage = 'discover.php';

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$q = trim($_GET['q'] ?? '');
$deptFilter = trim($_GET['dept'] ?? '');
$specFilter = trim($_GET['spec'] ?? '');

// Build search SQL
$whereClauses = ["u.role = 'student'", "COALESCE(sp.is_public, 1) = 1"];
$params = [];
$types = '';

if (!empty($q)) {
    $whereClauses[] = "(u.full_name LIKE ? OR u.enrollment_no LIKE ? OR sp.headline LIKE ? OR EXISTS (
        SELECT 1 FROM student_skills ss2 JOIN skills sk2 ON ss2.skill_id = sk2.id WHERE ss2.student_id = u.id AND sk2.name LIKE ?
    ))";
    $likeQ = '%' . $q . '%';
    $params[] = $likeQ;
    $params[] = $likeQ;
    $params[] = $likeQ;
    $params[] = $likeQ;
    $types .= 'ssss';
}

if (!empty($deptFilter)) {
    $whereClauses[] = "u.department = ?";
    $params[] = $deptFilter;
    $types .= 's';
}

if (!empty($specFilter)) {
    $whereClauses[] = "u.specialization LIKE ?";
    $params[] = '%' . $specFilter . '%';
    $types .= 's';
}

$whereSql = implode(' AND ', $whereClauses);

$query = "
    SELECT u.id, u.full_name, u.department, u.division, u.enrollment_no, u.specialization, u.profile_photo,
           sp.headline, sp.bio,
           (SELECT COUNT(*) FROM projects p WHERE p.student_id = u.id) AS projects_count,
           (SELECT GROUP_CONCAT(sk.name SEPARATOR ', ') FROM student_skills ss JOIN skills sk ON ss.skill_id = sk.id WHERE ss.student_id = u.id LIMIT 4) AS top_skills
    FROM users u
    LEFT JOIN student_profiles sp ON u.id = sp.user_id
    WHERE {$whereSql}
    ORDER BY projects_count DESC, u.full_name ASC
    LIMIT 40
";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();
$students = [];
while ($row = $res->fetch_assoc()) {
    $students[] = $row;
}
$stmt->close();

// Distinct departments for filter
$deptsRes = mysqli_query($conn, "SELECT DISTINCT department FROM users WHERE role = 'student' AND department != '' ORDER BY department ASC");
$depts = [];
if ($deptsRes) {
    while ($d = mysqli_fetch_row($deptsRes)) $depts[] = $d[0];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Discover Students — InternHub | MIT-ADT University</title>
    <?php require_once __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
    .discover-wrapper {
        padding: 0 32px 32px;
        display: flex;
        flex-direction: column;
        gap: 22px;
    }

    .discover-search-card {
        background: #ffffff;
        border-radius: 20px;
        padding: 22px 28px;
        border: 1px solid rgba(226, 232, 240, 0.8);
        box-shadow: 0 4px 18px rgba(0,0,0,0.035);
    }

    .search-inputs-row {
        display: flex;
        gap: 14px;
        align-items: center;
        flex-wrap: wrap;
    }

    .search-field-main {
        flex: 1;
        min-width: 260px;
        position: relative;
    }

    .search-field-main input {
        width: 100%;
        padding: 11px 40px 11px 16px;
        border-radius: 999px;
        border: 1px solid #cbd5e1;
        font-family: inherit;
        font-size: 0.88rem;
        outline: none;
    }
    .search-field-main input:focus { border-color: #7c3aed; }
    .search-field-main i { position: absolute; right: 16px; top: 50%; transform: translateY(-50%); color: #64748b; }

    .search-select {
        padding: 11px 18px;
        border-radius: 999px;
        border: 1px solid #cbd5e1;
        font-family: inherit;
        font-size: 0.84rem;
        color: #334155;
        background: #ffffff;
        outline: none;
        cursor: pointer;
    }

    .btn-search {
        padding: 11px 24px;
        border-radius: 999px;
        background: #5b21b6;
        color: #ffffff;
        font-size: 0.86rem;
        font-weight: 700;
        border: none;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .btn-search:hover { background: #43185b; }

    /* Students Directory Grid */
    .students-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(310px, 1fr));
        gap: 20px;
    }

    .student-card {
        background: #ffffff;
        border-radius: 20px;
        padding: 24px;
        border: 1px solid rgba(226, 232, 240, 0.8);
        box-shadow: 0 4px 16px rgba(0,0,0,0.03);
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .student-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 24px rgba(0,0,0,0.08);
    }

    .student-card-top {
        display: flex;
        align-items: center;
        gap: 14px;
        margin-bottom: 12px;
    }

    .student-avatar {
        width: 54px;
        height: 54px;
        border-radius: 50%;
        background: #ede9fe;
        color: #6d28d9;
        font-weight: 800;
        font-size: 1.25rem;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        flex-shrink: 0;
        border: 2px solid #ffffff;
        box-shadow: 0 4px 10px rgba(0,0,0,0.08);
    }
    .student-avatar img { width: 100%; height: 100%; object-fit: cover; }

    .student-name-bold {
        font-size: 1.02rem;
        font-weight: 800;
        color: #1e1b4b;
        line-height: 1.2;
    }

    .student-headline {
        font-size: 0.82rem;
        color: #5b21b6;
        font-weight: 700;
        margin: 4px 0 10px;
        line-height: 1.35;
    }

    .badges-strip {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
        margin-bottom: 12px;
    }
    .badge-pill {
        padding: 2px 9px;
        border-radius: 999px;
        background: #ede9fe;
        color: #5b21b6;
        font-size: 0.68rem;
        font-weight: 700;
    }

    .skills-strip {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
        margin-bottom: 16px;
    }
    .skill-mini-pill {
        padding: 3px 10px;
        border-radius: 999px;
        background: #f1f5f9;
        color: #475569;
        font-size: 0.72rem;
        font-weight: 600;
    }

    .card-footer-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding-top: 14px;
        border-top: 1px solid #f1f5f9;
    }

    .projects-count-text {
        font-size: 0.76rem;
        color: #64748b;
        font-weight: 600;
    }

    .btn-view-portfolio {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 0.82rem;
        font-weight: 700;
        color: #5b21b6;
        text-decoration: none;
        transition: all 0.15s ease;
    }
    .btn-view-portfolio:hover {
        color: #3b0764;
        transform: translateX(2px);
    }

    @media (max-width: 900px) {
        .discover-wrapper { padding: 0 16px 24px; }
        .search-inputs-row { flex-direction: column; align-items: stretch; }
    }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="discover-wrapper">

    <!-- Search & Filter Card -->
    <div class="discover-search-card">
        <form action="discover.php" method="GET" class="search-inputs-row">
            <div class="search-field-main">
                <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="Search by student name, enrollment, skill, or project keyword...">
                <i class="fa-solid fa-magnifying-glass"></i>
            </div>

            <select name="dept" class="search-select">
                <option value="">All Departments</option>
                <?php foreach ($depts as $dept): ?>
                    <option value="<?php echo h($dept); ?>" <?php echo $deptFilter === $dept ? 'selected' : ''; ?>><?php echo h($dept); ?></option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn-search">
                <i class="fa-solid fa-filter"></i>
                <span>Filter Directory</span>
            </button>

            <?php if (!empty($q) || !empty($deptFilter)): ?>
                <a href="discover.php" style="font-size:0.82rem; color:#ef4444; font-weight:700; text-decoration:none;">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Student Cards Grid -->
    <div class="students-grid">
        <?php if (!empty($students)): ?>
            <?php foreach ($students as $stu): ?>
                <?php
                $photo = trim($stu['profile_photo'] ?? '');
                $photoPath = (!empty($photo) && file_exists(__DIR__ . '/../uploads/profile_photos/' . $photo))
                    ? '../uploads/profile_photos/' . rawurlencode($photo)
                    : '';
                ?>
                <div class="student-card">
                    <div>
                        <div class="student-card-top">
                            <div class="student-avatar">
                                <?php if (!empty($photoPath)): ?>
                                    <img src="<?php echo h($photoPath); ?>" alt="Avatar">
                                <?php else: ?>
                                    <span><?php echo strtoupper(substr($stu['full_name'], 0, 1)); ?></span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <div class="student-name-bold"><?php echo h($stu['full_name']); ?></div>
                                <div style="font-size:0.75rem; color:#64748b;"><?php echo h($stu['enrollment_no']); ?></div>
                            </div>
                        </div>

                        <div class="badges-strip">
                            <?php if (!empty($stu['department'])): ?>
                                <span class="badge-pill"><?php echo h($stu['department']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($stu['division'])): ?>
                                <span class="badge-pill"><?php echo h($stu['division']); ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="student-headline">
                            <?php echo h($stu['headline'] ?: ($stu['specialization'] ?: 'Student @ MIT-ADT')); ?>
                        </div>

                        <?php if (!empty($stu['top_skills'])): ?>
                            <div class="skills-strip">
                                <?php foreach (explode(',', $stu['top_skills']) as $sk): ?>
                                    <span class="skill-mini-pill"><?php echo h(trim($sk)); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="card-footer-row">
                        <span class="projects-count-text">
                            <i class="fa-solid fa-laptop-code" style="color:#7c3aed;"></i>
                            <?php echo (int)$stu['projects_count']; ?> Projects
                        </span>
                        <a href="../portfolio.php?id=<?php echo $stu['id']; ?>" target="_blank" class="btn-view-portfolio">
                            <span>View Portfolio</span>
                            <i class="fa-solid fa-arrow-right"></i>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div style="grid-column: 1 / -1; background:#ffffff; border-radius:20px; padding:40px; text-align:center; border:1px solid #e2e8f0; color:#64748b;">
                <i class="fa-solid fa-compass" style="font-size:2.6rem; color:#cbd5e1; margin-bottom:12px;"></i>
                <h4 style="font-size:1.1rem; color:#1e1b4b; margin-bottom:4px;">No Students Found</h4>
                <p style="font-size:0.85rem;">Try refining your search keyword or clearing the department filter.</p>
            </div>
        <?php endif; ?>
    </div>

</div>

</main>
</div>

<?php require_once __DIR__ . '/includes/sidebar_scripts.php'; ?>
</body>
</html>
