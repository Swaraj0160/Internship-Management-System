<?php
/**
 * InternHub — Professional Student Portfolio (my_portfolio.php)
 * Complete portfolio management: Profile Editor, Projects, Skills Matrix,
 * Achievements, Certifications, PDF Resume, and AI Assistance.
 */

session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/StorageManager.php';
require_once __DIR__ . '/../includes/AIService.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../login.php");
    exit;
}

$student_id = (int)$_SESSION['user_id'];
$activePage = 'my_portfolio.php';
$tab = $_GET['tab'] ?? 'overview';
$validTabs = ['overview', 'editor', 'projects', 'skills', 'achievements', 'resume'];
if (!in_array($tab, $validTabs)) $tab = 'overview';

$message = '';
$messageType = '';

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$ai = new AIService($conn);

// 1. Fetch Student User Details
$u_stmt = $conn->prepare("SELECT id, full_name, email, phone, division, department, enrollment_no, roll_no, specialization, profile_photo FROM users WHERE id = ?");
$u_stmt->bind_param("i", $student_id);
$u_stmt->execute();
$student = $u_stmt->get_result()->fetch_assoc();
$u_stmt->close();

$student_name = $student['full_name'] ?? 'Student';
$student_division = $student['division'] ?? 'SY-4';
$student_department = $student['department'] ?? 'SOC';
$enrollment_no = $student['enrollment_no'] ?? '';
$student_email = $student['email'] ?? '';
$student_phone = $student['phone'] ?? '';
$specialization = $student['specialization'] ?? 'Computer Science & Engineering';
$profile_photo = trim($student['profile_photo'] ?? '');
if (!empty($profile_photo) && file_exists(__DIR__ . "/../uploads/profile_photos/" . $profile_photo)) {
    $profile_image = "../uploads/profile_photos/" . rawurlencode($profile_photo);
} else {
    $profile_image = "";
}

// 2. Fetch or Create student_profiles record
$p_stmt = $conn->prepare("SELECT * FROM student_profiles WHERE user_id = ?");
$p_stmt->bind_param("i", $student_id);
$p_stmt->execute();
$profile = $p_stmt->get_result()->fetch_assoc();
$p_stmt->close();

if (!$profile) {
    $insP = $conn->prepare("INSERT INTO student_profiles (user_id, headline, bio, is_public, created_at, updated_at) VALUES (?, 'Aspiring Software Engineer | MIT-ADT University', '', 1, NOW(), NOW())");
    $insP->bind_param("i", $student_id);
    $insP->execute();
    $insP->close();

    $p_stmt = $conn->prepare("SELECT * FROM student_profiles WHERE user_id = ?");
    $p_stmt->bind_param("i", $student_id);
    $p_stmt->execute();
    $profile = $p_stmt->get_result()->fetch_assoc();
    $p_stmt->close();
}

// 3. Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Action: Update Basic Profile Info
    if ($action === 'update_profile') {
        $headline = trim($_POST['headline'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $github_url = trim($_POST['github_url'] ?? '');
        $linkedin_url = trim($_POST['linkedin_url'] ?? '');
        $portfolio_url = trim($_POST['portfolio_url'] ?? '');
        $current_semester = trim($_POST['current_semester'] ?? '');
        $cgpa = !empty($_POST['cgpa']) ? (float)$_POST['cgpa'] : null;
        $is_public = isset($_POST['is_public']) ? 1 : 0;

        // Avatar upload
        if (isset($_FILES['avatar_photo']) && $_FILES['avatar_photo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['avatar_photo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $dir = __DIR__ . '/../uploads/profile_photos/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $avatarName = time() . '_stu_' . $student_id . '.' . $ext;
                if (move_uploaded_file($_FILES['avatar_photo']['tmp_name'], $dir . $avatarName)) {
                    $upPhoto = $conn->prepare("UPDATE users SET profile_photo = ? WHERE id = ?");
                    $upPhoto->bind_param("si", $avatarName, $student_id);
                    $upPhoto->execute();
                    $upPhoto->close();
                    $profile_image = "../uploads/profile_photos/" . rawurlencode($avatarName);
                }
            }
        }

        $upStmt = $conn->prepare("
            UPDATE student_profiles
            SET headline = ?, bio = ?, github_url = ?, linkedin_url = ?, portfolio_url = ?, current_semester = ?, cgpa = ?, is_public = ?, updated_at = NOW()
            WHERE user_id = ?
        ");
        $upStmt->bind_param("ssssssidi", $headline, $bio, $github_url, $linkedin_url, $portfolio_url, $current_semester, $cgpa, $is_public, $student_id);
        if ($upStmt->execute()) {
            $message = 'Profile identity updated successfully!';
            $messageType = 'success';
        } else {
            $message = 'Failed to update profile: ' . $conn->error;
            $messageType = 'danger';
        }
        $upStmt->close();

        // Refresh profile
        $p_stmt = $conn->prepare("SELECT * FROM student_profiles WHERE user_id = ?");
        $p_stmt->bind_param("i", $student_id);
        $p_stmt->execute();
        $profile = $p_stmt->get_result()->fetch_assoc();
        $p_stmt->close();
    }

    // Action: Add / Update Project
    elseif ($action === 'save_project') {
        $proj_id = (int)($_POST['project_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $github_url = trim($_POST['github_url'] ?? '');
        $live_url = trim($_POST['live_url'] ?? '');
        $is_featured = isset($_POST['is_featured']) ? 1 : 0;
        $technologies = trim($_POST['technologies'] ?? '');

        if (empty($title)) {
            $message = 'Project title is required.';
            $messageType = 'danger';
        } else {
            $thumbName = null;
            if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['thumbnail']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    $pdir = __DIR__ . '/../uploads/project_thumbnails/';
                    if (!is_dir($pdir)) mkdir($pdir, 0755, true);
                    $thumbName = time() . '_proj_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $_FILES['thumbnail']['name']);
                    move_uploaded_file($_FILES['thumbnail']['tmp_name'], $pdir . $thumbName);
                }
            }

            if ($proj_id > 0) {
                // Update
                $upP = $conn->prepare("UPDATE projects SET title = ?, description = ?, github_url = ?, live_url = ?, thumbnail_file = COALESCE(?, thumbnail_file), is_featured = ?, updated_at = NOW() WHERE id = ? AND student_id = ?");
                $upP->bind_param("sssssiii", $title, $description, $github_url, $live_url, $thumbName, $is_featured, $proj_id, $student_id);
                $upP->execute();
                $upP->close();
                $message = 'Project updated successfully!';
                $messageType = 'success';
            } else {
                // Insert
                $inP = $conn->prepare("INSERT INTO projects (student_id, title, description, github_url, live_url, thumbnail_file, is_featured, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                $inP->bind_param("isssssi", $student_id, $title, $description, $github_url, $live_url, $thumbName, $is_featured);
                $inP->execute();
                $proj_id = mysqli_insert_id($conn);
                $inP->close();
                $message = 'Project created successfully!';
                $messageType = 'success';
            }

            // Sync Technologies
            if (!empty($technologies) && $proj_id > 0) {
                $techArray = array_filter(array_map('trim', explode(',', $technologies)));
                $delTech = $conn->prepare("DELETE FROM project_technologies WHERE project_id = ?");
                $delTech->bind_param("i", $proj_id);
                $delTech->execute();
                $delTech->close();

                $inTech = $conn->prepare("INSERT INTO project_technologies (project_id, technology_name) VALUES (?, ?)");
                foreach ($techArray as $tech) {
                    $inTech->bind_param("is", $proj_id, $tech);
                    $inTech->execute();
                }
                $inTech->close();
            }
            $tab = 'projects';
        }
    }

    // Action: Delete Project
    elseif ($action === 'delete_project') {
        $del_id = (int)($_POST['project_id'] ?? 0);
        if ($del_id > 0) {
            $delP = $conn->prepare("DELETE FROM projects WHERE id = ? AND student_id = ?");
            $delP->bind_param("ii", $del_id, $student_id);
            $delP->execute();
            $delP->close();
            $message = 'Project deleted.';
            $messageType = 'success';
            $tab = 'projects';
        }
    }

    // Action: Add / Update Skill
    elseif ($action === 'add_skill') {
        $skill_name = trim($_POST['skill_name'] ?? '');
        $profInput = strtolower(trim($_POST['proficiency'] ?? 'intermediate'));
        $proficiency_level = in_array($profInput, ['beginner', 'intermediate', 'advanced', 'expert']) ? $profInput : 'intermediate';

        if (!empty($skill_name)) {
            // Find or create skill in skills table
            $skStmt = $conn->prepare("SELECT id FROM skills WHERE name = ? LIMIT 1");
            $skStmt->bind_param("s", $skill_name);
            $skStmt->execute();
            $skRow = $skStmt->get_result()->fetch_assoc();
            $skStmt->close();

            if ($skRow) {
                $skillId = (int)$skRow['id'];
            } else {
                $inSk = $conn->prepare("INSERT INTO skills (name, category) VALUES (?, 'Technical')");
                $inSk->bind_param("s", $skill_name);
                $inSk->execute();
                $skillId = mysqli_insert_id($conn);
                $inSk->close();
            }

            // Upsert student_skills
            $upsertSk = $conn->prepare("INSERT INTO student_skills (student_id, skill_id, proficiency_level) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE proficiency_level = VALUES(proficiency_level)");
            $upsertSk->bind_param("iis", $student_id, $skillId, $proficiency_level);
            $upsertSk->execute();
            $upsertSk->close();

            $message = "Skill '{$skill_name}' added to your matrix!";
            $messageType = 'success';
            $tab = 'skills';
        }
    }

    // Action: Delete Skill
    elseif ($action === 'delete_skill') {
        $delSkillId = (int)($_POST['skill_id'] ?? 0);
        if ($delSkillId > 0) {
            $delSk = $conn->prepare("DELETE FROM student_skills WHERE student_id = ? AND skill_id = ?");
            $delSk->bind_param("ii", $student_id, $delSkillId);
            $delSk->execute();
            $delSk->close();
            $message = 'Skill removed.';
            $messageType = 'success';
            $tab = 'skills';
        }
    }

    // Action: Add Achievement
    elseif ($action === 'add_achievement') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $issuer = trim($_POST['issuer'] ?? '');
        $issue_date = !empty($_POST['issue_date']) ? $_POST['issue_date'] : null;

        if (!empty($title)) {
            $evName = null;
            if (isset($_FILES['evidence_file']) && $_FILES['evidence_file']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['evidence_file']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'])) {
                    $adir = __DIR__ . '/../uploads/achievements/';
                    if (!is_dir($adir)) mkdir($adir, 0755, true);
                    $evName = time() . '_ach_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $_FILES['evidence_file']['name']);
                    move_uploaded_file($_FILES['evidence_file']['tmp_name'], $adir . $evName);
                }
            }

            $inAch = $conn->prepare("INSERT INTO achievements (student_id, title, description, issuer, issue_date, evidence_file, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $inAch->bind_param("isssss", $student_id, $title, $description, $issuer, $issue_date, $evName);
            $inAch->execute();
            $inAch->close();
            $message = 'Achievement added successfully!';
            $messageType = 'success';
            $tab = 'achievements';
        }
    }

    // Action: Add Certification
    elseif ($action === 'add_certification') {
        $name = trim($_POST['name'] ?? '');
        $issuing_org = trim($_POST['issuing_organization'] ?? '');
        $issue_date = !empty($_POST['issue_date']) ? $_POST['issue_date'] : null;
        $credential_id = trim($_POST['credential_id'] ?? '');
        $credential_url = trim($_POST['credential_url'] ?? '');

        if (!empty($name) && !empty($issuing_org)) {
            $certFile = null;
            if (isset($_FILES['certificate_file']) && $_FILES['certificate_file']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['certificate_file']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'])) {
                    $cdir = __DIR__ . '/../uploads/certifications/';
                    if (!is_dir($cdir)) mkdir($cdir, 0755, true);
                    $certFile = time() . '_cert_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $_FILES['certificate_file']['name']);
                    move_uploaded_file($_FILES['certificate_file']['tmp_name'], $cdir . $certFile);
                }
            }

            $inCert = $conn->prepare("INSERT INTO certifications (student_id, name, issuing_organization, issue_date, credential_id, credential_url, certificate_file, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $inCert->bind_param("issssss", $student_id, $name, $issuing_org, $issue_date, $credential_id, $credential_url, $certFile);
            $inCert->execute();
            $inCert->close();
            $message = 'Certification added successfully!';
            $messageType = 'success';
            $tab = 'achievements';
        }
    }

    // Action: Upload PDF Resume
    elseif ($action === 'upload_resume') {
        if (isset($_FILES['resume_file']) && $_FILES['resume_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['resume_file']['name'], PATHINFO_EXTENSION));
            if ($ext === 'pdf') {
                $rdir = __DIR__ . '/../uploads/resumes/';
                if (!is_dir($rdir)) mkdir($rdir, 0755, true);
                $resName = time() . '_resume_' . $student_id . '.pdf';
                if (move_uploaded_file($_FILES['resume_file']['tmp_name'], $rdir . $resName)) {
                    $upRes = $conn->prepare("UPDATE student_profiles SET resume_file = ?, updated_at = NOW() WHERE user_id = ?");
                    $upRes->bind_param("si", $resName, $student_id);
                    $upRes->execute();
                    $upRes->close();
                    $message = 'Resume PDF uploaded and linked to your portfolio!';
                    $messageType = 'success';
                    $profile['resume_file'] = $resName;
                }
            } else {
                $message = 'Only PDF resume files are accepted.';
                $messageType = 'danger';
            }
        }
        $tab = 'resume';
    }
}

// 4. Fetch Projects
$projects_list = [];
$pQ = $conn->prepare("
    SELECT p.*, GROUP_CONCAT(pt.technology_name SEPARATOR ', ') AS technologies
    FROM projects p
    LEFT JOIN project_technologies pt ON p.id = pt.project_id
    WHERE p.student_id = ?
    GROUP BY p.id
    ORDER BY p.is_featured DESC, p.created_at DESC
");
$pQ->bind_param("i", $student_id);
$pQ->execute();
$pRes = $pQ->get_result();
while ($pr = $pRes->fetch_assoc()) $projects_list[] = $pr;
$pQ->close();

// 5. Fetch Skills
$skills_list = [];
$sQ = $conn->prepare("
    SELECT ss.skill_id, ss.proficiency_level, s.name, s.category
    FROM student_skills ss
    JOIN skills s ON ss.skill_id = s.id
    WHERE ss.student_id = ?
    ORDER BY ss.proficiency_level DESC, s.name ASC
");
$sQ->bind_param("i", $student_id);
$sQ->execute();
$sRes = $sQ->get_result();
while ($sk = $sRes->fetch_assoc()) $skills_list[] = $sk;
$sQ->close();

// 6. Fetch Achievements & Certifications
$achievements_list = [];
$aQ = $conn->prepare("SELECT * FROM achievements WHERE student_id = ? ORDER BY issue_date DESC, id DESC");
$aQ->bind_param("i", $student_id);
$aQ->execute();
$aRes = $aQ->get_result();
while ($ar = $aRes->fetch_assoc()) $achievements_list[] = $ar;
$aQ->close();

$certifications_list = [];
$cQ = $conn->prepare("SELECT * FROM certifications WHERE student_id = ? ORDER BY issue_date DESC, id DESC");
$cQ->bind_param("i", $student_id);
$cQ->execute();
$cRes = $cQ->get_result();
while ($cr = $cRes->fetch_assoc()) $certifications_list[] = $cr;
$cQ->close();

// 7. Calculate Completeness Analysis
$completeness = $ai->analyzeProfileCompleteness(
    $student_id,
    $profile,
    count($projects_list),
    count($skills_list),
    count($certifications_list),
    !empty($profile['resume_file'])
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Portfolio — InternHub | MIT-ADT University</title>
    <?php require_once __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
    .portfolio-wrapper {
        padding: 0 32px 32px;
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    /* Subnavigation Tabs */
    .portfolio-nav-tabs {
        display: flex;
        align-items: center;
        gap: 8px;
        background: #ffffff;
        padding: 6px;
        border-radius: 999px;
        border: 1px solid rgba(226, 232, 240, 0.8);
        box-shadow: 0 2px 10px rgba(0,0,0,0.03);
        overflow-x: auto;
    }

    .portfolio-nav-tab {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 20px;
        border-radius: 999px;
        font-size: 0.84rem;
        font-weight: 600;
        color: #475569;
        text-decoration: none;
        white-space: nowrap;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .portfolio-nav-tab:hover { background: #f8fafc; color: #6d28d9; }
    .portfolio-nav-tab.active { background: #5b21b6; color: #ffffff !important; box-shadow: 0 4px 12px rgba(91, 33, 182, 0.3); }

    /* Content Cards */
    .portfolio-card {
        background: #ffffff;
        border-radius: 20px;
        padding: 26px 30px;
        border: 1px solid rgba(226, 232, 240, 0.8);
        box-shadow: 0 4px 20px rgba(0,0,0,0.035);
    }

    .portfolio-card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 22px;
        padding-bottom: 14px;
        border-bottom: 1px solid #f1f5f9;
    }

    .portfolio-card-title {
        font-size: 1.25rem;
        font-weight: 800;
        color: #1e1b4b;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .portfolio-card-title i { color: #7c3aed; }

    /* Completeness Progress Bar */
    .completeness-banner {
        background: linear-gradient(135deg, #f5f3ff, #ede9fe);
        border: 1px solid #ddd6fe;
        border-radius: 18px;
        padding: 18px 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        margin-bottom: 22px;
    }

    .completeness-info { flex: 1; }
    .completeness-title { font-size: 0.92rem; font-weight: 800; color: #4c1d95; margin-bottom: 4px; }
    .completeness-bar-bg { width: 100%; height: 10px; background: rgba(255, 255, 255, 0.7); border-radius: 999px; overflow: hidden; margin-top: 6px; }
    .completeness-bar-fill { height: 100%; background: linear-gradient(90deg, #8b5cf6, #6d28d9); border-radius: 999px; transition: width 0.6s ease; }

    /* Forms */
    .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; }
    .form-group { display: flex; flex-direction: column; gap: 6px; }
    .form-group.full-width { grid-column: span 2; }
    .form-label { font-size: 0.82rem; font-weight: 700; color: #334155; }
    .form-control {
        width: 100%; padding: 10px 14px; border-radius: 12px; border: 1px solid #cbd5e1;
        font-family: inherit; font-size: 0.88rem; color: #1e293b; background: #ffffff; outline: none; transition: all 0.2s ease;
    }
    .form-control:focus { border-color: #7c3aed; box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.12); }
    textarea.form-control { min-height: 100px; resize: vertical; }

    .btn-submit {
        display: inline-flex; align-items: center; gap: 8px; padding: 11px 26px; border-radius: 999px;
        background: linear-gradient(135deg, #7c3aed, #5b21b6); color: #ffffff; font-weight: 700; font-size: 0.88rem;
        border: none; cursor: pointer; box-shadow: 0 4px 14px rgba(91, 33, 182, 0.28); transition: all 0.2s ease;
    }
    .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(91, 33, 182, 0.38); }

    .btn-ai-sparkle {
        display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 999px;
        background: #ede9fe; color: #6d28d9; font-size: 0.78rem; font-weight: 700; border: 1px solid #ddd6fe; cursor: pointer; transition: all 0.2s ease;
    }
    .btn-ai-sparkle:hover { background: #ddd6fe; color: #4c1d95; }

    /* Project Cards Grid */
    .projects-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; margin-top: 16px; }
    .project-card {
        border-radius: 16px; border: 1px solid #e2e8f0; background: #ffffff; overflow: hidden;
        display: flex; flex-direction: column; transition: all 0.2s ease; box-shadow: 0 2px 10px rgba(0,0,0,0.03);
    }
    .project-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,0.08); }
    .project-thumb { width: 100%; height: 160px; object-fit: cover; background: #ede9fe; }
    .project-content { padding: 18px; flex: 1; display: flex; flex-direction: column; justify-content: space-between; }
    .project-title { font-size: 1.05rem; font-weight: 800; color: #1e1b4b; margin-bottom: 6px; }
    .project-desc { font-size: 0.82rem; color: #475569; line-height: 1.45; margin-bottom: 12px; }
    .project-tech-badges { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }
    .tech-pill { padding: 2px 10px; border-radius: 999px; background: #f1f5f9; color: #475569; font-size: 0.7rem; font-weight: 600; }
    .project-links { display: flex; align-items: center; gap: 12px; border-top: 1px solid #f1f5f9; padding-top: 12px; }
    .project-link-btn { font-size: 0.82rem; font-weight: 700; color: #5b21b6; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }

    /* Skills Cloud */
    .skills-cloud { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px; }
    .skill-badge {
        display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 999px;
        background: #f8fafc; border: 1px solid #cbd5e1; font-size: 0.82rem; font-weight: 700; color: #1e293b;
    }
    .skill-badge-level { font-size: 0.68rem; padding: 2px 8px; border-radius: 999px; background: #ede9fe; color: #6d28d9; }

    /* Public Preview Banner */
    .public-link-box {
        display: flex; align-items: center; justify-content: space-between; padding: 12px 18px;
        background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 14px; margin-bottom: 20px;
    }

    .alert-banner {
        padding: 12px 18px; border-radius: 14px; font-size: 0.86rem; font-weight: 600;
        display: flex; align-items: center; gap: 10px; margin-bottom: 20px;
    }
    .alert-success { background: #dcfce7; color: #14532d; border: 1px solid #bbf7d0; }
    .alert-danger  { background: #fee2e2; color: #7f1d1d; border: 1px solid #fecaca; }

    @media (max-width: 900px) {
        .portfolio-wrapper { padding: 0 16px 24px; }
        .form-grid { grid-template-columns: 1fr; }
        .form-group.full-width { grid-column: span 1; }
        .completeness-banner { flex-direction: column; align-items: flex-start; }
    }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="portfolio-wrapper">

    <!-- Subnavigation Tabs -->
    <nav class="portfolio-nav-tabs">
        <a href="?tab=overview" class="portfolio-nav-tab <?php echo $tab === 'overview' ? 'active' : ''; ?>">
            <i class="fa-solid fa-address-card"></i>
            <span>Overview</span>
        </a>
        <a href="?tab=editor" class="portfolio-nav-tab <?php echo $tab === 'editor' ? 'active' : ''; ?>">
            <i class="fa-solid fa-pen-nib"></i>
            <span>Profile Editor</span>
        </a>
        <a href="?tab=projects" class="portfolio-nav-tab <?php echo $tab === 'projects' ? 'active' : ''; ?>">
            <i class="fa-solid fa-laptop-code"></i>
            <span>Projects (<?php echo count($projects_list); ?>)</span>
        </a>
        <a href="?tab=skills" class="portfolio-nav-tab <?php echo $tab === 'skills' ? 'active' : ''; ?>">
            <i class="fa-solid fa-cubes"></i>
            <span>Skills Matrix (<?php echo count($skills_list); ?>)</span>
        </a>
        <a href="?tab=achievements" class="portfolio-nav-tab <?php echo $tab === 'achievements' ? 'active' : ''; ?>">
            <i class="fa-solid fa-trophy"></i>
            <span>Achievements & Certs</span>
        </a>
        <a href="?tab=resume" class="portfolio-nav-tab <?php echo $tab === 'resume' ? 'active' : ''; ?>">
            <i class="fa-regular fa-file-pdf"></i>
            <span>PDF Resume</span>
        </a>
    </nav>

    <?php if (!empty($message)): ?>
        <div class="alert-banner alert-<?php echo $messageType; ?>">
            <i class="fa-solid <?php echo $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
            <span><?php echo h($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- Completeness Indicator -->
    <div class="completeness-banner">
        <div class="completeness-info">
            <div class="completeness-title">
                <i class="fa-solid fa-shield-halved"></i>
                Portfolio Completeness: <?php echo $completeness['score']; ?>% (<?php echo $completeness['level']; ?>)
            </div>
            <div style="font-size:0.78rem; color:#5b21b6;">
                <?php echo !empty($completeness['suggestions']) ? $completeness['suggestions'][0] : 'Your portfolio is in outstanding shape!'; ?>
            </div>
            <div class="completeness-bar-bg">
                <div class="completeness-bar-fill" style="width: <?php echo $completeness['score']; ?>%;"></div>
            </div>
        </div>
        <div>
            <a href="../portfolio.php?id=<?php echo $student_id; ?>" target="_blank" class="btn-ai-sparkle" style="padding:10px 18px; font-size:0.84rem;">
                <i class="fa-solid fa-arrow-up-right-from-square"></i>
                <span>Public Preview</span>
            </a>
        </div>
    </div>

    <!-- TAB 1: OVERVIEW -->
    <?php if ($tab === 'overview'): ?>
        <div class="portfolio-card">
            <div class="portfolio-card-header">
                <div class="portfolio-card-title">
                    <i class="fa-solid fa-id-badge"></i>
                    <span>Portfolio Identity</span>
                </div>
                <a href="?tab=editor" class="btn-ai-sparkle">
                    <i class="fa-solid fa-pen-to-square"></i>
                    <span>Edit Profile Details</span>
                </a>
            </div>

            <div style="display:flex; gap:26px; align-items:flex-start; flex-wrap:wrap; margin-bottom:24px;">
                <div style="width:100px; height:100px; border-radius:50%; background:#ede9fe; overflow:hidden; border:3px solid #ffffff; box-shadow:0 6px 18px rgba(0,0,0,0.1); flex-shrink:0;">
                    <?php if (!empty($profile_image)): ?>
                        <img src="<?php echo h($profile_image); ?>" alt="Avatar" style="width:100%; height:100%; object-fit:cover;">
                    <?php else: ?>
                        <div style="width:100%; height:100%; display:flex; align-items:center; justify-content:center; font-size:2rem; font-weight:800; color:#6d28d9;">
                            <?php echo strtoupper(substr($student_name, 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div style="flex:1;">
                    <h2 style="font-size:1.45rem; font-weight:800; color:#1e1b4b;"><?php echo h($student_name); ?></h2>
                    <div style="font-size:0.95rem; font-weight:700; color:#5b21b6; margin:4px 0 8px;">
                        <?php echo h($profile['headline'] ?: 'Student @ MIT-ADT University'); ?>
                    </div>
                    <div style="font-size:0.84rem; color:#475569; line-height:1.5; margin-bottom:12px;">
                        <?php echo nl2br(h($profile['bio'] ?: 'No bio written yet. Click Edit Profile to add a summary of your academic background and interests.')); ?>
                    </div>

                    <div style="display:flex; gap:14px; flex-wrap:wrap;">
                        <?php if (!empty($profile['github_url'])): ?>
                            <a href="<?php echo h($profile['github_url']); ?>" target="_blank" class="project-link-btn"><i class="fa-brands fa-github"></i> GitHub</a>
                        <?php endif; ?>
                        <?php if (!empty($profile['linkedin_url'])): ?>
                            <a href="<?php echo h($profile['linkedin_url']); ?>" target="_blank" class="project-link-btn"><i class="fa-brands fa-linkedin"></i> LinkedIn</a>
                        <?php endif; ?>
                        <?php if (!empty($profile['portfolio_url'])): ?>
                            <a href="<?php echo h($profile['portfolio_url']); ?>" target="_blank" class="project-link-btn"><i class="fa-solid fa-globe"></i> Website</a>
                        <?php endif; ?>
                        <?php if (!empty($profile['resume_file'])): ?>
                            <a href="../uploads/resumes/<?php echo rawurlencode($profile['resume_file']); ?>" target="_blank" class="project-link-btn" style="color:#16a34a;"><i class="fa-regular fa-file-pdf"></i> Resume PDF</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Showcase Projects Sample -->
            <div style="margin-top:20px; border-top:1px solid #f1f5f9; padding-top:20px;">
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
                    <h3 style="font-size:1.05rem; font-weight:800; color:#1e1b4b;">Featured Projects</h3>
                    <a href="?tab=projects" style="font-size:0.82rem; font-weight:700; color:#7c3aed; text-decoration:none;">Manage All (<?php echo count($projects_list); ?>) →</a>
                </div>

                <?php if (!empty($projects_list)): ?>
                    <div class="projects-grid">
                        <?php foreach (array_slice($projects_list, 0, 2) as $proj): ?>
                            <div class="project-card">
                                <div class="project-content">
                                    <div class="project-title"><?php echo h($proj['title']); ?></div>
                                    <div class="project-desc"><?php echo nl2br(h(substr($proj['description'], 0, 140))) . (strlen($proj['description']) > 140 ? '...' : ''); ?></div>
                                    <div class="project-tech-badges">
                                        <?php if (!empty($proj['technologies'])): ?>
                                            <?php foreach (explode(',', $proj['technologies']) as $t): ?>
                                                <span class="tech-pill"><?php echo h(trim($t)); ?></span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="project-links">
                                        <?php if (!empty($proj['github_url'])): ?>
                                            <a href="<?php echo h($proj['github_url']); ?>" target="_blank" class="project-link-btn"><i class="fa-brands fa-github"></i> Code</a>
                                        <?php endif; ?>
                                        <?php if (!empty($proj['live_url'])): ?>
                                            <a href="<?php echo h($proj['live_url']); ?>" target="_blank" class="project-link-btn"><i class="fa-solid fa-arrow-up-right-from-square"></i> Demo</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p style="font-size:0.85rem; color:#64748b;">No projects added yet. <a href="?tab=projects" style="color:#7c3aed; font-weight:700;">Add your first project showcase</a>.</p>
                <?php endif; ?>
            </div>
        </div>

    <!-- TAB 2: PROFILE EDITOR -->
    <?php elseif ($tab === 'editor'): ?>
        <div class="portfolio-card">
            <div class="portfolio-card-header">
                <div class="portfolio-card-title">
                    <i class="fa-solid fa-pen-nib"></i>
                    <span>Profile Editor</span>
                </div>
                <button type="button" class="btn-ai-sparkle" id="btnAiHeadlines">
                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                    <span>✨ AI Generate Headlines</span>
                </button>
            </div>

            <form action="?tab=editor" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_profile">

                <div class="form-grid">
                    <div class="form-group full-width">
                        <label class="form-label">Professional Headline *</label>
                        <input type="text" name="headline" id="editorHeadline" class="form-control" required value="<?php echo h($profile['headline'] ?? ''); ?>" placeholder="e.g. Full-Stack Web Developer | Python, PHP & React | BTech CSE @ MIT-ADT">
                        <div id="aiHeadlineSuggestions" style="display:none; margin-top:8px; display:flex; flex-direction:column; gap:6px;"></div>
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label">Bio & Professional Summary</label>
                        <textarea name="bio" class="form-control" placeholder="Summarize your engineering journey, technologies you enjoy building with, and key career aspirations..."><?php echo h($profile['bio'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Update Profile Avatar (JPG, PNG, max 2MB)</label>
                        <input type="file" name="avatar_photo" class="form-control" accept=".jpg,.jpeg,.png,.webp">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Specialization / Department</label>
                        <input type="text" class="form-control" readonly value="<?php echo h($specialization); ?> (<?php echo h($student_department); ?>)" style="background:#f1f5f9;">
                    </div>

                    <div class="form-group">
                        <label class="form-label">GitHub Profile URL</label>
                        <input type="url" name="github_url" class="form-control" value="<?php echo h($profile['github_url'] ?? ''); ?>" placeholder="https://github.com/username">
                    </div>

                    <div class="form-group">
                        <label class="form-label">LinkedIn Profile URL</label>
                        <input type="url" name="linkedin_url" class="form-control" value="<?php echo h($profile['linkedin_url'] ?? ''); ?>" placeholder="https://linkedin.com/in/username">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Personal Portfolio / Website</label>
                        <input type="url" name="portfolio_url" class="form-control" value="<?php echo h($profile['portfolio_url'] ?? ''); ?>" placeholder="https://yourdomain.com">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Current CGPA (Optional)</label>
                        <input type="number" step="0.01" min="0" max="10" name="cgpa" class="form-control" value="<?php echo h($profile['cgpa'] ?? ''); ?>" placeholder="e.g. 8.75">
                    </div>

                    <div class="form-group full-width" style="margin-top:6px;">
                        <label style="display:flex; align-items:center; gap:10px; cursor:pointer;">
                            <input type="checkbox" name="is_public" value="1" <?php echo ($profile['is_public'] ?? 1) ? 'checked' : ''; ?> style="width:18px; height:18px; accent-color:#7c3aed;">
                            <span style="font-size:0.88rem; font-weight:700; color:#1e1b4b;">Allow portfolio to be discoverable in Student Directory & Public Link</span>
                        </label>
                    </div>
                </div>

                <div style="margin-top:24px;">
                    <button type="submit" class="btn-submit">
                        <i class="fa-solid fa-floppy-disk"></i>
                        <span>Save Profile Changes</span>
                    </button>
                </div>
            </form>
        </div>

    <!-- TAB 3: PROJECTS -->
    <?php elseif ($tab === 'projects'): ?>
        <div class="portfolio-card">
            <div class="portfolio-card-header">
                <div class="portfolio-card-title">
                    <i class="fa-solid fa-laptop-code"></i>
                    <span>Projects Showcase</span>
                </div>
                <button type="button" class="btn-ai-sparkle" onclick="document.getElementById('addProjectBox').scrollIntoView({behavior:'smooth'});">
                    <i class="fa-solid fa-plus"></i>
                    <span>Add New Project</span>
                </button>
            </div>

            <!-- Existing Projects List -->
            <?php if (!empty($projects_list)): ?>
                <div class="projects-grid" style="margin-bottom:30px;">
                    <?php foreach ($projects_list as $proj): ?>
                        <div class="project-card">
                            <?php if (!empty($proj['thumbnail_file']) && file_exists(__DIR__ . '/../uploads/project_thumbnails/' . $proj['thumbnail_file'])): ?>
                                <img src="../uploads/project_thumbnails/<?php echo rawurlencode($proj['thumbnail_file']); ?>" alt="Thumbnail" class="project-thumb">
                            <?php endif; ?>
                            <div class="project-content">
                                <div>
                                    <div style="display:flex; align-items:center; justify-content:space-between;">
                                        <div class="project-title"><?php echo h($proj['title']); ?></div>
                                        <?php if ($proj['is_featured']): ?>
                                            <span style="font-size:0.68rem; padding:2px 8px; border-radius:999px; background:#fef3c7; color:#b45309; font-weight:700;">★ Featured</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="project-desc"><?php echo nl2br(h($proj['description'])); ?></div>
                                    <div class="project-tech-badges">
                                        <?php if (!empty($proj['technologies'])): ?>
                                            <?php foreach (explode(',', $proj['technologies']) as $t): ?>
                                                <span class="tech-pill"><?php echo h(trim($t)); ?></span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="project-links">
                                    <?php if (!empty($proj['github_url'])): ?>
                                        <a href="<?php echo h($proj['github_url']); ?>" target="_blank" class="project-link-btn"><i class="fa-brands fa-github"></i> Code</a>
                                    <?php endif; ?>
                                    <?php if (!empty($proj['live_url'])): ?>
                                        <a href="<?php echo h($proj['live_url']); ?>" target="_blank" class="project-link-btn"><i class="fa-solid fa-arrow-up-right-from-square"></i> Demo</a>
                                    <?php endif; ?>

                                    <form action="?tab=projects" method="POST" style="margin-left:auto;" onsubmit="return confirm('Delete this project?');">
                                        <input type="hidden" name="action" value="delete_project">
                                        <input type="hidden" name="project_id" value="<?php echo $proj['id']; ?>">
                                        <button type="submit" style="background:none; border:none; color:#ef4444; cursor:pointer; font-size:0.8rem;">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p style="color:#64748b; font-size:0.88rem; margin-bottom:24px;">No projects added yet.</p>
            <?php endif; ?>

            <!-- Add Project Form with AI Assistant -->
            <div id="addProjectBox" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:18px; padding:24px;">
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
                    <div style="font-size:1.05rem; font-weight:800; color:#1e1b4b;">Add Project Showcase</div>
                    <button type="button" class="btn-ai-sparkle" id="btnAiImproveProject">
                        <i class="fa-solid fa-wand-magic-sparkles"></i>
                        <span>✨ AI Polish Description</span>
                    </button>
                </div>

                <form action="?tab=projects" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_project">

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Project Title *</label>
                            <input type="text" name="title" id="projTitleInput" class="form-control" required placeholder="e.g. Distributed Task Queue Engine">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Technologies Used (comma separated)</label>
                            <input type="text" name="technologies" id="projTechInput" class="form-control" placeholder="e.g. Node.js, Redis, Docker, PostgreSQL">
                        </div>

                        <div class="form-group full-width">
                            <label class="form-label">Description & Key Contributions *</label>
                            <textarea name="description" id="projDescInput" class="form-control" required placeholder="Outline the problem solved, technical architecture, and results. You can paste rough bullets and click '✨ AI Polish Description'."></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label">GitHub Repository URL</label>
                            <input type="url" name="github_url" class="form-control" placeholder="https://github.com/...">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Live Demo / Deployment URL</label>
                            <input type="url" name="live_url" class="form-control" placeholder="https://...">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Project Thumbnail (JPG / PNG, max 3MB)</label>
                            <input type="file" name="thumbnail" class="form-control" accept=".jpg,.jpeg,.png,.webp">
                        </div>

                        <div class="form-group" style="justify-content:center;">
                            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; margin-top:20px;">
                                <input type="checkbox" name="is_featured" value="1" style="width:18px; height:18px; accent-color:#7c3aed;">
                                <span style="font-size:0.86rem; font-weight:700; color:#1e1b4b;">Pin as Featured Project</span>
                            </label>
                        </div>
                    </div>

                    <div style="margin-top:20px;">
                        <button type="submit" class="btn-submit">
                            <i class="fa-solid fa-plus"></i>
                            <span>Add Project to Portfolio</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

    <!-- TAB 4: SKILLS MATRIX & AI GAP ANALYSIS -->
    <?php elseif ($tab === 'skills'): ?>
        <div class="portfolio-card">
            <div class="portfolio-card-header">
                <div class="portfolio-card-title">
                    <i class="fa-solid fa-cubes"></i>
                    <span>Skills Matrix</span>
                </div>
            </div>

            <!-- Current Skills -->
            <div class="skills-cloud">
                <?php if (!empty($skills_list)): ?>
                    <?php foreach ($skills_list as $sk): ?>
                        <div class="skill-badge">
                            <span><?php echo h($sk['name']); ?></span>
                            <span class="skill-badge-level"><?php echo h(ucfirst($sk['proficiency_level'] ?? '')); ?></span>
                            <form action="?tab=skills" method="POST" style="margin:0; display:inline;">
                                <input type="hidden" name="action" value="delete_skill">
                                <input type="hidden" name="skill_id" value="<?php echo $sk['skill_id']; ?>">
                                <button type="submit" style="background:none; border:none; color:#ef4444; cursor:pointer; font-size:0.75rem; padding:0 2px;">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="color:#64748b; font-size:0.88rem;">No skills added yet.</p>
                <?php endif; ?>
            </div>

            <!-- Add Skill Form -->
            <form action="?tab=skills" method="POST" style="margin-top:26px; padding-top:20px; border-top:1px solid #f1f5f9; display:flex; gap:14px; align-items:flex-end; flex-wrap:wrap;">
                <input type="hidden" name="action" value="add_skill">
                <div class="form-group" style="flex:1; min-width:220px;">
                    <label class="form-label">Add Skill</label>
                    <input type="text" name="skill_name" class="form-control" required placeholder="e.g. Python, Docker, Next.js, SQL">
                </div>
                <div class="form-group" style="width:180px;">
                    <label class="form-label">Proficiency</label>
                    <select name="proficiency" class="form-control">
                        <option value="Beginner">Beginner</option>
                        <option value="Intermediate" selected>Intermediate</option>
                        <option value="Advanced">Advanced</option>
                        <option value="Expert">Expert</option>
                    </select>
                </div>
                <button type="submit" class="btn-submit" style="height:42px;">
                    <i class="fa-solid fa-plus"></i>
                    <span>Add</span>
                </button>
            </form>

            <!-- AI Skill Gap Recommendation Engine -->
            <div style="margin-top:32px; background:linear-gradient(135deg, #f5f3ff, #ede9fe); border-radius:18px; padding:22px; border:1px solid #ddd6fe;">
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;">
                    <div style="font-weight:800; color:#4c1d95; font-size:1.02rem;">
                        <i class="fa-solid fa-wand-magic-sparkles"></i>
                        <span>AI Career Skill Gap Recommender</span>
                    </div>
                </div>

                <div style="display:flex; gap:12px; margin-bottom:14px; flex-wrap:wrap;">
                    <button type="button" class="btn-ai-sparkle btn-gap-role" data-role="Full Stack Developer">Full Stack Developer</button>
                    <button type="button" class="btn-ai-sparkle btn-gap-role" data-role="AI / Machine Learning Engineer">AI / ML Engineer</button>
                    <button type="button" class="btn-ai-sparkle btn-gap-role" data-role="Cloud & DevOps Engineer">Cloud & DevOps</button>
                    <button type="button" class="btn-ai-sparkle btn-gap-role" data-role="Data Analyst">Data Analyst</button>
                </div>

                <div id="aiGapResults" style="background:#ffffff; border-radius:14px; padding:16px; font-size:0.86rem; color:#1e1b4b; border:1px solid rgba(0,0,0,0.05);">
                    <p style="color:#64748b;">Click any career target above to view recommended core skills and gap analysis for your portfolio.</p>
                </div>
            </div>
        </div>

    <!-- TAB 5: ACHIEVEMENTS & CERTIFICATIONS -->
    <?php elseif ($tab === 'achievements'): ?>
        <div class="portfolio-card">
            <div class="portfolio-card-header">
                <div class="portfolio-card-title">
                    <i class="fa-solid fa-trophy"></i>
                    <span>Achievements & Certifications</span>
                </div>
            </div>

            <!-- Certifications List -->
            <h3 style="font-size:1.05rem; font-weight:800; color:#1e1b4b; margin-bottom:14px;">Course Certifications (<?php echo count($certifications_list); ?>)</h3>
            <?php if (!empty($certifications_list)): ?>
                <div style="display:flex; flex-direction:column; gap:12px; margin-bottom:28px;">
                    <?php foreach ($certifications_list as $cert): ?>
                        <div style="padding:14px 18px; border-radius:14px; border:1px solid #e2e8f0; background:#f8fafc; display:flex; align-items:center; justify-content:space-between;">
                            <div>
                                <div style="font-weight:700; color:#1e1b4b; font-size:0.95rem;"><?php echo h($cert['name']); ?></div>
                                <div style="font-size:0.8rem; color:#64748b;"><?php echo h($cert['issuing_organization']); ?> • Issued <?php echo h($cert['issue_date']); ?></div>
                            </div>
                            <div style="display:flex; gap:10px; align-items:center;">
                                <?php if (!empty($cert['credential_url'])): ?>
                                    <a href="<?php echo h($cert['credential_url']); ?>" target="_blank" class="btn-ai-sparkle"><i class="fa-solid fa-arrow-up-right-from-square"></i> Verify</a>
                                <?php endif; ?>
                                <?php if (!empty($cert['certificate_file'])): ?>
                                    <a href="../uploads/certifications/<?php echo rawurlencode($cert['certificate_file']); ?>" target="_blank" class="btn-ai-sparkle"><i class="fa-regular fa-file-pdf"></i> Certificate</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p style="font-size:0.85rem; color:#64748b; margin-bottom:24px;">No certifications added yet.</p>
            <?php endif; ?>

            <!-- Add Certification Form -->
            <div style="background:#f8fafc; border-radius:16px; padding:20px; border:1px solid #e2e8f0; margin-bottom:30px;">
                <h4 style="font-size:0.95rem; font-weight:800; color:#1e1b4b; margin-bottom:14px;">Add New Certification</h4>
                <form action="?tab=achievements" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add_certification">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Certification Name *</label>
                            <input type="text" name="name" class="form-control" required placeholder="e.g. AWS Certified Cloud Practitioner">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Issuing Organization *</label>
                            <input type="text" name="issuing_organization" class="form-control" required placeholder="e.g. Amazon Web Services, Google, Coursera">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Issue Date</label>
                            <input type="date" name="issue_date" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Credential Verification URL</label>
                            <input type="url" name="credential_url" class="form-control" placeholder="https://coursera.org/verify/...">
                        </div>
                        <div class="form-group full-width">
                            <label class="form-label">Certificate PDF / Image</label>
                            <input type="file" name="certificate_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                        </div>
                    </div>
                    <div style="margin-top:16px;">
                        <button type="submit" class="btn-submit">
                            <i class="fa-solid fa-plus"></i>
                            <span>Save Certification</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Achievements List -->
            <h3 style="font-size:1.05rem; font-weight:800; color:#1e1b4b; margin-bottom:14px;">Honors & Achievements (<?php echo count($achievements_list); ?>)</h3>
            <?php if (!empty($achievements_list)): ?>
                <div style="display:flex; flex-direction:column; gap:12px; margin-bottom:28px;">
                    <?php foreach ($achievements_list as $ach): ?>
                        <div style="padding:14px 18px; border-radius:14px; border:1px solid #e2e8f0; background:#f8fafc; display:flex; align-items:center; justify-content:space-between;">
                            <div>
                                <div style="font-weight:700; color:#1e1b4b; font-size:0.95rem;"><?php echo h($ach['title']); ?></div>
                                <div style="font-size:0.8rem; color:#64748b;"><?php echo h($ach['issuer']); ?> • <?php echo h($ach['issue_date']); ?></div>
                                <?php if (!empty($ach['description'])): ?>
                                    <div style="font-size:0.82rem; color:#334155; margin-top:4px;"><?php echo h($ach['description']); ?></div>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($ach['evidence_file'])): ?>
                                <a href="../uploads/achievements/<?php echo rawurlencode($ach['evidence_file']); ?>" target="_blank" class="btn-ai-sparkle"><i class="fa-regular fa-file"></i> Proof</a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Add Achievement Form -->
            <div style="background:#f8fafc; border-radius:16px; padding:20px; border:1px solid #e2e8f0;">
                <h4 style="font-size:0.95rem; font-weight:800; color:#1e1b4b; margin-bottom:14px;">Add New Achievement / Honor</h4>
                <form action="?tab=achievements" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add_achievement">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Achievement Title *</label>
                            <input type="text" name="title" class="form-control" required placeholder="e.g. 1st Place — MIT-ADT Hackathon 2026">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Awarding Body / Issuer</label>
                            <input type="text" name="issuer" class="form-control" placeholder="e.g. MIT-ADT School of Computing">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Date Achieved</label>
                            <input type="date" name="issue_date" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Certificate / Proof of Award</label>
                            <input type="file" name="evidence_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                        </div>
                        <div class="form-group full-width">
                            <label class="form-label">Brief Description</label>
                            <textarea name="description" class="form-control" placeholder="Details of the competition or recognition..."></textarea>
                        </div>
                    </div>
                    <div style="margin-top:16px;">
                        <button type="submit" class="btn-submit">
                            <i class="fa-solid fa-plus"></i>
                            <span>Save Achievement</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

    <!-- TAB 6: PDF RESUME -->
    <?php elseif ($tab === 'resume'): ?>
        <div class="portfolio-card">
            <div class="portfolio-card-header">
                <div class="portfolio-card-title">
                    <i class="fa-regular fa-file-pdf"></i>
                    <span>PDF Resume Management</span>
                </div>
            </div>

            <?php if (!empty($profile['resume_file'])): ?>
                <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:16px; padding:20px; display:flex; align-items:center; justify-content:space-between; margin-bottom:24px;">
                    <div>
                        <div style="font-weight:700; color:#15803d; font-size:1rem;">
                            <i class="fa-solid fa-circle-check"></i> Current Resume Active
                        </div>
                        <div style="font-size:0.8rem; color:#166534; margin-top:2px;">
                            File: <strong><?php echo h($profile['resume_file']); ?></strong>
                        </div>
                    </div>
                    <a href="../uploads/resumes/<?php echo rawurlencode($profile['resume_file']); ?>" target="_blank" class="btn-submit" style="padding:9px 20px;">
                        <i class="fa-regular fa-file-pdf"></i>
                        <span>Preview / Download PDF</span>
                    </a>
                </div>
            <?php else: ?>
                <div style="padding:20px; background:#fffbeb; border:1px solid #fef3c7; border-radius:16px; margin-bottom:24px; color:#b45309; font-size:0.86rem;">
                    <i class="fa-solid fa-circle-exclamation"></i> No PDF resume uploaded yet. Upload your latest 1-page engineering resume below.
                </div>
            <?php endif; ?>

            <form action="?tab=resume" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_resume">
                <div class="form-group full-width">
                    <label class="form-label">Upload New PDF Resume (Max 5MB)</label>
                    <input type="file" name="resume_file" class="form-control" accept=".pdf" required>
                </div>
                <div style="margin-top:20px;">
                    <button type="submit" class="btn-submit">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        <span>Upload Resume</span>
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>

</div>

</main>
</div>

<?php require_once __DIR__ . '/includes/sidebar_scripts.php'; ?>

<!-- Script for AI Headliners, Project Polish, and Skill Gap Analysis -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    // 1. AI Headline Generator
    var btnHeadlines = document.getElementById('btnAiHeadlines');
    var headlineInput = document.getElementById('editorHeadline');
    var suggestionsBox = document.getElementById('aiHeadlineSuggestions');

    if (btnHeadlines && headlineInput) {
        btnHeadlines.addEventListener('click', function () {
            var origText = btnHeadlines.innerHTML;
            btnHeadlines.disabled = true;
            btnHeadlines.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generating...';

            fetch('api/ai_assistant.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'generate_headlines', skills: '<?php echo addslashes(implode(', ', array_column($skills_list, 'name'))); ?>' })
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                btnHeadlines.disabled = false;
                btnHeadlines.innerHTML = origText;
                if (res.success && res.headlines && res.headlines.length > 0) {
                    suggestionsBox.innerHTML = '';
                    suggestionsBox.style.display = 'flex';
                    res.headlines.forEach(function(h) {
                        var pill = document.createElement('div');
                        pill.style.cssText = 'padding:6px 12px; background:#ede9fe; color:#5b21b6; border-radius:999px; font-size:0.78rem; font-weight:600; cursor:pointer; border:1px solid #ddd6fe;';
                        pill.textContent = '✨ ' + h;
                        pill.addEventListener('click', function() {
                            headlineInput.value = h;
                            suggestionsBox.style.display = 'none';
                        });
                        suggestionsBox.appendChild(pill);
                    });
                }
            })
            .catch(function() {
                btnHeadlines.disabled = false;
                btnHeadlines.innerHTML = origText;
            });
        });
    }

    // 2. AI Project Polish
    var btnProjPolish = document.getElementById('btnAiImproveProject');
    var projTitle = document.getElementById('projTitleInput');
    var projTech = document.getElementById('projTechInput');
    var projDesc = document.getElementById('projDescInput');

    if (btnProjPolish && projDesc) {
        btnProjPolish.addEventListener('click', function () {
            var title = projTitle ? projTitle.value.trim() : '';
            var desc = projDesc.value.trim();
            if (!title) {
                alert('Please enter a project title first.');
                if (projTitle) projTitle.focus();
                return;
            }

            var orig = btnProjPolish.innerHTML;
            btnProjPolish.disabled = true;
            btnProjPolish.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Polishing...';

            fetch('api/ai_assistant.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'improve_project',
                    title: title,
                    notes: desc,
                    technologies: projTech ? projTech.value : ''
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                btnProjPolish.disabled = false;
                btnProjPolish.innerHTML = orig;
                if (res.success && res.result && res.result.text) {
                    projDesc.value = res.result.text;
                }
            })
            .catch(function() {
                btnProjPolish.disabled = false;
                btnProjPolish.innerHTML = orig;
            });
        });
    }

    // 3. AI Skill Gap Analysis
    var roleButtons = document.querySelectorAll('.btn-gap-role');
    var gapResults = document.getElementById('aiGapResults');

    roleButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var role = this.getAttribute('data-role');
            gapResults.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Analyzing skill matrix for ' + role + '...';

            fetch('api/ai_assistant.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'suggest_skill_gaps',
                    target_role: role,
                    skills: '<?php echo addslashes(implode(', ', array_column($skills_list, 'name'))); ?>'
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success && res.data) {
                    var d = res.data;
                    var coreHtml = d.recommended_core.map(function(s){ return '<span class="tech-pill" style="background:#fee2e2; color:#b91c1c; font-weight:700;">+ ' + s + '</span>'; }).join(' ');
                    var advHtml = d.recommended_advanced.map(function(s){ return '<span class="tech-pill" style="background:#ede9fe; color:#5b21b6; font-weight:700;">+ ' + s + '</span>'; }).join(' ');

                    gapResults.innerHTML = '<strong>Target Role: ' + d.target_role + '</strong><br>' +
                        '<div style="margin:8px 0;"><strong>Recommended Core Skills to Learn:</strong><br>' + (coreHtml || 'All core skills satisfied!') + '</div>' +
                        '<div style="margin:8px 0;"><strong>High-Impact Advanced Skills:</strong><br>' + (advHtml || 'Great advanced coverage!') + '</div>' +
                        '<div style="font-size:0.8rem; color:#475569; margin-top:8px;">💡 <em>' + d.career_advice + '</em></div>';
                }
            });
        });
    });
});
</script>
</body>
</html>
