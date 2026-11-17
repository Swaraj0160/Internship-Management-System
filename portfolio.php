<?php
/**
 * InternHub — Public Student Professional Portfolio
 * MIT-ADT University, Pune
 * Viewable by faculty, peers, recruiters, and external visitors
 */

require_once __DIR__ . '/config.php';

$student_id = (int)($_GET['id'] ?? 0);
if ($student_id <= 0) {
    header("Location: index.php");
    exit;
}

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

// 1. Fetch Student User
$u_stmt = $conn->prepare("SELECT id, full_name, email, department, division, specialization, profile_photo FROM users WHERE id = ? AND role = 'student' LIMIT 1");
$u_stmt->bind_param("i", $student_id);
$u_stmt->execute();
$student = $u_stmt->get_result()->fetch_assoc();
$u_stmt->close();

if (!$student) {
    die("Student profile not found.");
}

// 2. Fetch Profile Record
$p_stmt = $conn->prepare("SELECT * FROM student_profiles WHERE user_id = ? LIMIT 1");
$p_stmt->bind_param("i", $student_id);
$p_stmt->execute();
$profile = $p_stmt->get_result()->fetch_assoc();
$p_stmt->close();

$student_name = $student['full_name'];
$profile_photo = trim($student['profile_photo'] ?? '');
$profile_image = (!empty($profile_photo) && file_exists(__DIR__ . "/uploads/profile_photos/" . $profile_photo))
    ? "uploads/profile_photos/" . rawurlencode($profile_photo)
    : "";

// 3. Fetch Projects
$projects_list = [];
$pr_stmt = $conn->prepare("
    SELECT p.*, GROUP_CONCAT(pt.technology_name SEPARATOR ', ') AS technologies
    FROM projects p
    LEFT JOIN project_technologies pt ON p.id = pt.project_id
    WHERE p.student_id = ?
    GROUP BY p.id
    ORDER BY p.is_featured DESC, p.created_at DESC
");
$pr_stmt->bind_param("i", $student_id);
$pr_stmt->execute();
$pr_res = $pr_stmt->get_result();
while ($r = $pr_res->fetch_assoc()) $projects_list[] = $r;
$pr_stmt->close();

// 4. Fetch Skills
$skills_list = [];
$sk_stmt = $conn->prepare("
    SELECT ss.proficiency_level, s.name
    FROM student_skills ss
    JOIN skills s ON ss.skill_id = s.id
    WHERE ss.student_id = ?
    ORDER BY ss.proficiency_level DESC, s.name ASC
");
$sk_stmt->bind_param("i", $student_id);
$sk_stmt->execute();
$sk_res = $sk_stmt->get_result();
while ($r = $sk_res->fetch_assoc()) $skills_list[] = $r;
$sk_stmt->close();

// 5. Fetch Certifications
$certs_list = [];
$c_stmt = $conn->prepare("SELECT * FROM certifications WHERE student_id = ? ORDER BY issue_date DESC");
$c_stmt->bind_param("i", $student_id);
$c_stmt->execute();
$c_res = $c_stmt->get_result();
while ($r = $c_res->fetch_assoc()) $certs_list[] = $r;
$c_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($student_name); ?> — Professional Portfolio | InternHub</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Playfair+Display:ital,wght@0,600;0,700;1,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: 'Plus Jakarta Sans', sans-serif;
        background-color: #f7f6fb;
        color: #1e1b4b;
        min-height: 100vh;
    }
    .public-header {
        background: #ffffff;
        border-bottom: 1px solid #e2e8f0;
        padding: 16px 36px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .brand-group {
        display: flex;
        align-items: center;
        gap: 12px;
        text-decoration: none;
    }
    .brand-title {
        font-size: 1.15rem;
        font-weight: 800;
        color: #1e1b4b;
    }
    .brand-sub {
        font-size: 0.72rem;
        color: #64748b;
        font-weight: 600;
    }
    .container {
        max-width: 1080px;
        margin: 36px auto;
        padding: 0 20px;
        display: flex;
        flex-direction: column;
        gap: 24px;
    }
    .hero-card {
        background: #ffffff;
        border-radius: 24px;
        padding: 36px 40px;
        border: 1px solid rgba(226, 232, 240, 0.8);
        box-shadow: 0 4px 20px rgba(0,0,0,0.035);
        display: flex;
        align-items: center;
        gap: 32px;
        flex-wrap: wrap;
    }
    .avatar-lg {
        width: 120px;
        height: 120px;
        border-radius: 50%;
        background: #ede9fe;
        overflow: hidden;
        border: 4px solid #ffffff;
        box-shadow: 0 6px 20px rgba(0,0,0,0.1);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2.8rem;
        font-weight: 800;
        color: #6d28d9;
        flex-shrink: 0;
    }
    .avatar-lg img { width: 100%; height: 100%; object-fit: cover; }
    .hero-details { flex: 1; }
    .name-title { font-size: 1.85rem; font-weight: 800; color: #1e1b4b; letter-spacing: -0.02em; }
    .headline-text { font-size: 1.05rem; font-weight: 700; color: #5b21b6; margin: 6px 0 10px; }
    .bio-text { font-size: 0.88rem; color: #475569; line-height: 1.55; margin-bottom: 16px; }
    .meta-badges { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
    .meta-pill { padding: 4px 12px; border-radius: 999px; background: #ede9fe; color: #5b21b6; font-size: 0.74rem; font-weight: 700; }
    .links-row { display: flex; gap: 14px; flex-wrap: wrap; }
    .link-pill {
        display: inline-flex; align-items: center; gap: 6px; padding: 7px 16px; border-radius: 999px;
        background: #f1f5f9; color: #334155; font-size: 0.82rem; font-weight: 700; text-decoration: none;
        transition: all 0.2s ease;
    }
    .link-pill:hover { background: #ede9fe; color: #5b21b6; }
    .section-card {
        background: #ffffff;
        border-radius: 20px;
        padding: 28px 34px;
        border: 1px solid rgba(226, 232, 240, 0.8);
        box-shadow: 0 4px 18px rgba(0,0,0,0.03);
    }
    .section-heading {
        font-size: 1.2rem; font-weight: 800; color: #1e1b4b; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;
    }
    .section-heading i { color: #7c3aed; }
    .projects-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; }
    .project-card {
        border-radius: 16px; border: 1px solid #e2e8f0; background: #ffffff; overflow: hidden;
        display: flex; flex-direction: column; justify-content: space-between; padding: 20px;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .project-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,0.08); }
    .skills-cloud { display: flex; flex-wrap: wrap; gap: 10px; }
    .skill-badge {
        padding: 7px 16px; border-radius: 999px; background: #f8fafc; border: 1px solid #cbd5e1;
        font-size: 0.82rem; font-weight: 700; color: #1e293b;
    }
    </style>
</head>
<body>

<header class="public-header">
    <a href="index.php" class="brand-group">
        <img src="assets/images/mit_adt_crest.png" alt="MIT-ADT Crest" style="width:38px; height:38px; border-radius:50%;" onerror="this.src='assets/images/logo.png'">
        <div>
            <div class="brand-title">InternHub <span style="font-size:0.75rem; color:#7c3aed; font-weight:700;">Portfolio</span></div>
            <div class="brand-sub">MIT-ADT University, Pune</div>
        </div>
    </a>
    <div>
        <a href="login.php" style="padding:8px 20px; border-radius:999px; background:#5b21b6; color:#ffffff; font-size:0.84rem; font-weight:700; text-decoration:none;">Student Login</a>
    </div>
</header>

<div class="container">
    <!-- Hero Profile Card -->
    <div class="hero-card">
        <div class="avatar-lg">
            <?php if (!empty($profile_image)): ?>
                <img src="<?php echo h($profile_image); ?>" alt="Avatar">
            <?php else: ?>
                <span><?php echo strtoupper(substr($student_name, 0, 1)); ?></span>
            <?php endif; ?>
        </div>

        <div class="hero-details">
            <h1 class="name-title"><?php echo h($student_name); ?></h1>
            <div class="headline-text"><?php echo h($profile['headline'] ?? 'Student @ MIT-ADT University'); ?></div>
            <p class="bio-text"><?php echo nl2br(h($profile['bio'] ?? '')); ?></p>

            <div class="meta-badges">
                <span class="meta-pill"><?php echo h($student['department']); ?></span>
                <?php if (!empty($student['specialization'])): ?>
                    <span class="meta-pill"><?php echo h($student['specialization']); ?></span>
                <?php endif; ?>
                <?php if (!empty($profile['current_semester'])): ?>
                    <span class="meta-pill"><?php echo h($profile['current_semester']); ?></span>
                <?php endif; ?>
            </div>

            <div class="links-row">
                <?php if (!empty($profile['github_url'])): ?>
                    <a href="<?php echo h($profile['github_url']); ?>" target="_blank" class="link-pill"><i class="fa-brands fa-github"></i> GitHub</a>
                <?php endif; ?>
                <?php if (!empty($profile['linkedin_url'])): ?>
                    <a href="<?php echo h($profile['linkedin_url']); ?>" target="_blank" class="link-pill"><i class="fa-brands fa-linkedin"></i> LinkedIn</a>
                <?php endif; ?>
                <?php if (!empty($profile['portfolio_url'])): ?>
                    <a href="<?php echo h($profile['portfolio_url']); ?>" target="_blank" class="link-pill"><i class="fa-solid fa-globe"></i> Website</a>
                <?php endif; ?>
                <?php if (!empty($profile['resume_file'])): ?>
                    <a href="uploads/resumes/<?php echo rawurlencode($profile['resume_file']); ?>" target="_blank" class="link-pill" style="background:#dcfce7; color:#15803d;"><i class="fa-regular fa-file-pdf"></i> Resume PDF</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Showcase Projects -->
    <?php if (!empty($projects_list)): ?>
        <div class="section-card">
            <div class="section-heading">
                <i class="fa-solid fa-laptop-code"></i>
                <span>Showcase Projects</span>
            </div>
            <div class="projects-grid">
                <?php foreach ($projects_list as $proj): ?>
                    <div class="project-card">
                        <div>
                            <div style="font-weight:800; font-size:1.1rem; color:#1e1b4b; margin-bottom:8px;"><?php echo h($proj['title']); ?></div>
                            <div style="font-size:0.84rem; color:#475569; line-height:1.45; margin-bottom:14px;"><?php echo nl2br(h($proj['description'])); ?></div>
                            <?php if (!empty($proj['technologies'])): ?>
                                <div style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:14px;">
                                    <?php foreach (explode(',', $proj['technologies']) as $t): ?>
                                        <span style="font-size:0.7rem; font-weight:700; padding:2px 8px; border-radius:999px; background:#f1f5f9; color:#475569;"><?php echo h(trim($t)); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex; gap:12px; border-top:1px solid #f1f5f9; padding-top:12px;">
                            <?php if (!empty($proj['github_url'])): ?>
                                <a href="<?php echo h($proj['github_url']); ?>" target="_blank" style="font-size:0.82rem; font-weight:700; color:#5b21b6; text-decoration:none;"><i class="fa-brands fa-github"></i> Repository</a>
                            <?php endif; ?>
                            <?php if (!empty($proj['live_url'])): ?>
                                <a href="<?php echo h($proj['live_url']); ?>" target="_blank" style="font-size:0.82rem; font-weight:700; color:#5b21b6; text-decoration:none;"><i class="fa-solid fa-arrow-up-right-from-square"></i> Demo</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Verified Skills -->
    <?php if (!empty($skills_list)): ?>
        <div class="section-card">
            <div class="section-heading">
                <i class="fa-solid fa-cubes"></i>
                <span>Technical Skills Matrix</span>
            </div>
            <div class="skills-cloud">
                <?php foreach ($skills_list as $sk): ?>
                    <span class="skill-badge"><?php echo h($sk['name']); ?> <small style="color:#6d28d9; margin-left:4px; font-weight:600;">(<?php echo h(ucfirst($sk['proficiency_level'] ?? '')); ?>)</small></span>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Certifications -->
    <?php if (!empty($certs_list)): ?>
        <div class="section-card">
            <div class="section-heading">
                <i class="fa-solid fa-certificate"></i>
                <span>Certifications & Credentials</span>
            </div>
            <div style="display:flex; flex-direction:column; gap:12px;">
                <?php foreach ($certs_list as $cert): ?>
                    <div style="padding:14px 18px; border-radius:14px; border:1px solid #e2e8f0; background:#f8fafc; display:flex; align-items:center; justify-content:space-between;">
                        <div>
                            <div style="font-weight:700; color:#1e1b4b;"><?php echo h($cert['name']); ?></div>
                            <div style="font-size:0.8rem; color:#64748b;"><?php echo h($cert['issuing_organization']); ?> • Issued <?php echo h($cert['issue_date']); ?></div>
                        </div>
                        <?php if (!empty($cert['credential_url'])): ?>
                            <a href="<?php echo h($cert['credential_url']); ?>" target="_blank" style="color:#5b21b6; font-size:0.82rem; font-weight:700; text-decoration:none;">Verify <i class="fa-solid fa-arrow-up-right-from-square"></i></a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<footer style="text-align:center; padding:32px 0; color:#64748b; font-size:0.8rem;">
    InternHub — MIT-ADT University, Pune | Designed and Developed by Team-Apex
</footer>

</body>
</html>
