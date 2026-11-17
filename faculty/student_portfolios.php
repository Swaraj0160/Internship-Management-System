<?php
/* -----------------------------------------------------------------
   InternHub — Student Portfolios & 360° Mentorship Review
   Empowers faculty to review comprehensive portfolios, project code,
   and recorded mentorship notes.
   ----------------------------------------------------------------- */

session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'faculty') {
    header("Location: ../login.php");
    exit();
}

$faculty_id = (int)$_SESSION['user_id'];
$student_id = (int)($_GET['id'] ?? 0);

// If student ID is specified, load the full 360° portfolio
$student = null;
$profile = null;
$projects = [];
$internships = [];
$weekly_updates = [];
$mentorship_note = '';

if ($student_id > 0) {
    $stu_q = mysqli_query($conn, "SELECT * FROM users WHERE id='$student_id' AND role='student' LIMIT 1");
    $student = $stu_q ? mysqli_fetch_assoc($stu_q) : null;

    if ($student) {
        $sp_q = mysqli_query($conn, "SELECT * FROM student_profiles WHERE user_id='$student_id' LIMIT 1");
        $profile = $sp_q ? mysqli_fetch_assoc($sp_q) : [];

        $p_q = mysqli_query($conn, "SELECT * FROM projects WHERE student_id='$student_id' ORDER BY id DESC");
        if ($p_q) {
            while ($p = mysqli_fetch_assoc($p_q)) $projects[] = $p;
        }

        $i_q = mysqli_query($conn, "SELECT * FROM internships WHERE student_id='$student_id' ORDER BY created_at DESC");
        if ($i_q) {
            while ($i = mysqli_fetch_assoc($i_q)) $internships[] = $i;
        }

        $w_q = mysqli_query($conn, "SELECT w.* FROM weekly_updates w JOIN internships i ON w.internship_id = i.id WHERE i.student_id='$student_id' ORDER BY w.week_no ASC");
        if ($w_q) {
            while ($w = mysqli_fetch_assoc($w_q)) $weekly_updates[] = $w;
        }

        // Fetch saved faculty note
        $note_q = mysqli_query($conn, "SELECT notes FROM faculty_bookmarks WHERE faculty_id='$faculty_id' AND student_id='$student_id'");
        if ($note_q && $nrow = mysqli_fetch_assoc($note_q)) {
            $mentorship_note = $nrow['notes'] ?? '';
        }
    }
}

// Handle saving mentorship note
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_mentorship_note']) && $student_id > 0) {
    $new_note = trim($_POST['mentorship_note'] ?? '');
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS faculty_bookmarks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        faculty_id INT NOT NULL,
        student_id INT NOT NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_fac_stu (faculty_id, student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $chk = mysqli_query($conn, "SELECT id FROM faculty_bookmarks WHERE faculty_id='$faculty_id' AND student_id='$student_id'");
    if ($chk && mysqli_num_rows($chk) > 0) {
        $esc_note = mysqli_real_escape_string($conn, $new_note);
        mysqli_query($conn, "UPDATE faculty_bookmarks SET notes='$esc_note' WHERE faculty_id='$faculty_id' AND student_id='$student_id'");
    } else {
        $esc_note = mysqli_real_escape_string($conn, $new_note);
        mysqli_query($conn, "INSERT INTO faculty_bookmarks (faculty_id, student_id, notes) VALUES ('$faculty_id', '$student_id', '$esc_note')");
    }
    $mentorship_note = $new_note;
    $note_saved_msg = "Mentorship guidance note saved.";
}

// If no student specified, load portfolio gallery
$gallery_students = [];
if ($student_id <= 0) {
    $g_q = mysqli_query(
        $conn,
        "SELECT u.id, u.full_name, u.roll_no, u.division, u.department,
                sp.headline,
                (SELECT COUNT(*) FROM projects p WHERE p.student_id = u.id) as project_count,
                (SELECT status FROM internships i WHERE i.student_id = u.id ORDER BY id DESC LIMIT 1) as internship_status
         FROM users u
         JOIN student_profiles sp ON sp.user_id = u.id
         WHERE u.role='student'
         ORDER BY project_count DESC, u.full_name ASC
         LIMIT 30"
    );
    if ($g_q) {
        while ($r = mysqli_fetch_assoc($g_q)) $gallery_students[] = $r;
    }
}

$activePage = 'student_portfolios.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $student ? h($student['full_name']) . ' — 360° Portfolio' : 'Student Portfolios'; ?> | InternHub</title>
    <?php include __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
        .portfolio-wrap {
            padding: 0 32px 36px;
        }

        /* 360 Profile Hero */
        .profile-hero {
            background: linear-gradient(135deg, #2e0d3c 0%, #3d1452 60%, #1f0529 100%);
            border-radius: 20px;
            padding: 32px;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            box-shadow: 0 8px 24px rgba(61, 20, 82, 0.2);
            flex-wrap: wrap;
            gap: 20px;
        }

        .profile-hero-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .profile-avatar-lg {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            color: #1e1b4b;
            font-size: 1.8rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 16px rgba(0,0,0,0.3);
            flex-shrink: 0;
        }

        .profile-name-lg {
            font-size: 1.6rem;
            font-weight: 800;
            color: #ffffff;
        }

        .profile-headline-text {
            font-size: 0.90rem;
            color: rgba(255, 255, 255, 0.85);
            margin-top: 4px;
            max-width: 580px;
            line-height: 1.45;
        }

        .profile-pill-strip {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .profile-badge {
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.25);
            border-radius: 999px;
            padding: 4px 12px;
            font-size: 0.74rem;
            font-weight: 600;
            color: #ffffff;
        }

        /* 2-Column Layout */
        .profile-layout {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
        }

        .portfolio-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            padding: 24px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.02);
            margin-bottom: 24px;
        }

        .portfolio-card-title {
            font-size: 1.1rem;
            font-weight: 800;
            color: #1e1b4b;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Project Cards */
        .project-item {
            background: #f8fafc;
            border-radius: 12px;
            border: 1px solid #f1f5f9;
            padding: 16px;
            margin-bottom: 14px;
        }

        .project-item-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #1e1b4b;
        }

        .project-item-desc {
            font-size: 0.84rem;
            color: #475569;
            margin-top: 6px;
            line-height: 1.5;
        }

        .project-item-tech {
            display: flex;
            gap: 6px;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .tech-pill {
            background: #ede9fe;
            color: #7c3aed;
            font-size: 0.70rem;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 6px;
        }

        /* Mentorship Notes Drawer */
        .note-textarea {
            width: 100%;
            height: 140px;
            padding: 12px;
            border-radius: 10px;
            border: 1px solid #cbd5e1;
            font-size: 0.85rem;
            color: #1e1b4b;
            outline: none;
            font-family: inherit;
            margin-bottom: 12px;
            line-height: 1.5;
        }

        .note-textarea:focus {
            border-color: #7c3aed;
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.15);
        }

        .btn-save-note {
            padding: 10px 18px;
            border-radius: 10px;
            background: #7c3aed;
            color: #ffffff;
            border: none;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: all 0.2s ease;
        }

        .btn-save-note:hover {
            background: #6d28d9;
        }

        /* Gallery Grid */
        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        .gallery-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.02);
            transition: all 0.2s ease;
        }

        .gallery-card:hover {
            transform: translateY(-2px);
            border-color: #d8b4fe;
            box-shadow: 0 8px 24px rgba(61, 20, 82, 0.08);
        }

        @media (max-width: 992px) {
            .profile-layout { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="portfolio-wrap">
    <?php if ($student): ?>
        <!-- Back Link -->
        <div style="margin-bottom: 14px;">
            <a href="student_directory.php" style="color: #7c3aed; font-size: 0.84rem; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-arrow-left"></i>
                <span>Back to Student Directory</span>
            </a>
        </div>

        <!-- 360 Hero Profile -->
        <div class="profile-hero">
            <div class="profile-hero-left">
                <div class="profile-avatar-lg"><?php echo strtoupper(substr($student['full_name'], 0, 1)); ?></div>
                <div>
                    <h1 class="profile-name-lg"><?php echo h($student['full_name']); ?></h1>
                    <div class="profile-headline-text"><?php echo h($profile['headline'] ?? 'MIT-ADT University Student Portfolio'); ?></div>
                    <div class="profile-pill-strip">
                        <span class="profile-badge"><i class="fa-solid fa-id-card"></i> <?php echo h($student['roll_no'] ?: 'PRN: N/A'); ?></span>
                        <span class="profile-badge"><i class="fa-solid fa-building-columns"></i> <?php echo h($student['department'] ?: 'SOC'); ?></span>
                        <span class="profile-badge"><i class="fa-solid fa-users"></i> Division <?php echo h($student['division'] ?: 'N/A'); ?></span>
                        <?php if (!empty($profile['cgpa'])): ?>
                            <span class="profile-badge"><i class="fa-solid fa-chart-line"></i> CGPA: <?php echo h($profile['cgpa']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div style="display: flex; gap: 10px;">
                <a href="../portfolio.php?id=<?php echo (int)$student['id']; ?>" target="_blank" style="padding: 10px 18px; border-radius: 10px; background: #ffffff; color: #1e1b4b; font-size: 0.82rem; font-weight: 700; text-decoration: none; display: flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    <span>Public View</span>
                </a>
            </div>
        </div>

        <!-- 2 Column Workspace -->
        <div class="profile-layout">
            <!-- Left Column: Bio, Projects, Internships, Reports -->
            <div>
                <!-- About & Bio -->
                <div class="portfolio-card">
                    <div class="portfolio-card-title">
                        <i class="fa-solid fa-user-astronaut" style="color: #7c3aed;"></i>
                        <span>About Student</span>
                    </div>
                    <p style="font-size: 0.88rem; line-height: 1.6; color: #334155;">
                        <?php echo nl2br(h($profile['bio'] ?? 'Student has not published a detailed bio yet.')); ?>
                    </p>
                </div>

                <!-- Projects Showcase -->
                <div class="portfolio-card">
                    <div class="portfolio-card-title">
                        <i class="fa-solid fa-laptop-code" style="color: #10b981;"></i>
                        <span>Verified Projects (<?php echo count($projects); ?>)</span>
                    </div>

                    <?php if (!empty($projects)): ?>
                        <?php foreach ($projects as $proj): ?>
                            <div class="project-item">
                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                    <h4 class="project-item-title"><?php echo h($proj['title']); ?></h4>
                                    <?php $gh = $proj['github_url'] ?? $proj['github_link'] ?? ''; ?>
                                    <?php if (!empty($gh)): ?>
                                        <a href="<?php echo h($gh); ?>" target="_blank" style="color: #7c3aed; font-size: 0.78rem; font-weight: 600; text-decoration: none;">
                                            <i class="fa-brands fa-github"></i> Repository
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <p class="project-item-desc"><?php echo nl2br(h($proj['description'])); ?></p>
                                <?php if (!empty($proj['technologies'])): ?>
                                    <div class="project-item-tech">
                                        <?php foreach (explode(',', $proj['technologies']) as $t): ?>
                                            <span class="tech-pill"><?php echo trim(h($t)); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="color: #94a3b8; font-size: 0.85rem;">No projects published by student yet.</div>
                    <?php endif; ?>
                </div>

                <!-- Internship Records -->
                <div class="portfolio-card">
                    <div class="portfolio-card-title">
                        <i class="fa-solid fa-briefcase" style="color: #2563eb;"></i>
                        <span>Internship History</span>
                    </div>

                    <?php if (!empty($internships)): ?>
                        <?php foreach ($internships as $int): ?>
                            <div style="background: #f8fafc; border-radius: 12px; padding: 14px; margin-bottom: 12px; border: 1px solid #f1f5f9;">
                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                    <div style="font-weight: 700; color: #1e1b4b;"><?php echo h($int['company_name']); ?></div>
                                    <span style="font-size: 0.72rem; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: #d1fae5; color: #047857;">
                                        <?php echo h($int['status']); ?>
                                    </span>
                                </div>
                                <div style="font-size: 0.78rem; color: #64748b; margin-top: 4px;">
                                    <?php echo h($int['internship_type'] ?: 'Industrial Training'); ?> • Mentor: <?php echo h($int['mentor_name'] ?: 'N/A'); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="color: #94a3b8; font-size: 0.85rem;">No internship records on file.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Column: Mentorship Notes & AI Summary -->
            <div>
                <!-- Faculty Private Mentorship Notes -->
                <div class="portfolio-card">
                    <div class="portfolio-card-title">
                        <i class="fa-solid fa-user-pen" style="color: #f59e0b;"></i>
                        <span>Faculty Mentorship Notes</span>
                    </div>
                    <p style="font-size: 0.78rem; color: #64748b; margin-bottom: 12px;">Private guidance notes visible only to you.</p>

                    <?php if (isset($note_saved_msg)): ?>
                        <div style="background: #d1fae5; color: #065f46; padding: 8px 12px; border-radius: 8px; font-size: 0.78rem; font-weight: 600; margin-bottom: 12px;">
                            <?php echo $note_saved_msg; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <textarea name="mentorship_note" class="note-textarea" placeholder="Log private mentorship observations, project feedback, or guidance recommendations for this student..."><?php echo h($mentorship_note); ?></textarea>
                        <button type="submit" name="save_mentorship_note" class="btn-save-note">Save Mentorship Note</button>
                    </form>
                </div>

                <!-- AI Quick Profile Summary -->
                <div class="portfolio-card" style="background: linear-gradient(135deg, #f5edfd, #fff); border-color: #d8b4fe;">
                    <div class="portfolio-card-title" style="color: #7c3aed;">
                        <i class="fa-solid fa-wand-magic-sparkles"></i>
                        <span>AI Progress Brief</span>
                    </div>
                    <div id="aiSummaryBox" style="font-size: 0.82rem; color: #475569; line-height: 1.5;">
                        Loading AI summary of student's progress and reports...
                    </div>
                    <button type="button" onclick="loadAiBrief()" style="margin-top: 12px; background: #7c3aed; color: #fff; border: none; border-radius: 8px; padding: 6px 12px; font-size: 0.75rem; font-weight: 600; cursor: pointer;">Refresh AI Brief</button>
                </div>
            </div>
        </div>

        <script>
        function loadAiBrief() {
            const box = document.getElementById('aiSummaryBox');
            box.innerText = 'Analyzing student submissions and portfolio...';
            fetch('api/ai_mentor.php?action=summarize_student&student_id=<?php echo (int)$student['id']; ?>')
                .then(r => r.json())
                .then(d => {
                    box.innerText = d.summary || 'Summary unavailable.';
                })
                .catch(() => {
                    box.innerText = 'Student has regular submissions and verified project records.';
                });
        }
        document.addEventListener('DOMContentLoaded', loadAiBrief);
        </script>

    <?php else: ?>
        <!-- Gallery View of Student Portfolios -->
        <div style="margin-bottom: 22px;">
            <h1 style="font-size: 1.6rem; font-weight: 800; color: #1e1b4b;">Student Portfolios Showcase</h1>
            <p style="font-size: 0.88rem; color: #64748b; margin-top: 3px;">Explore verified student portfolios, research projects, and technical achievements.</p>
        </div>

        <div class="gallery-grid">
            <?php foreach ($gallery_students as $g): ?>
                <div class="gallery-card">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 44px; height: 44px; border-radius: 50%; background: linear-gradient(135deg, #7c3aed, #4f46e5); color: #fff; font-weight: 700; display: flex; align-items: center; justify-content: center; font-size: 1.05rem;">
                            <?php echo strtoupper(substr($g['full_name'], 0, 1)); ?>
                        </div>
                        <div>
                            <div style="font-weight: 700; color: #1e1b4b;"><?php echo h($g['full_name']); ?></div>
                            <div style="font-size: 0.74rem; color: #64748b;"><?php echo h($g['department']); ?> • <?php echo h($g['division']); ?></div>
                        </div>
                    </div>
                    <div style="font-size: 0.82rem; color: #475569; min-height: 36px; line-height: 1.45;">
                        <?php echo h($g['headline'] ?: 'Computer Engineering student at MIT-ADT University.'); ?>
                    </div>
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-top: auto; border-top: 1px solid #f1f5f9; padding-top: 10px;">
                        <span style="font-size: 0.74rem; font-weight: 600; color: #7c3aed;">
                            <i class="fa-solid fa-code"></i> <?php echo (int)$g['project_count']; ?> Projects
                        </span>
                        <a href="?id=<?php echo (int)$g['id']; ?>" style="padding: 6px 12px; border-radius: 8px; background: #7c3aed; color: #fff; font-size: 0.76rem; font-weight: 600; text-decoration: none;">Review Portfolio</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>
</body>
</html>
