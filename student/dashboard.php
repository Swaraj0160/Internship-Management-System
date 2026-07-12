<?php
session_start();
include("../config.php");

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

$student_id = (int)$_SESSION['user_id'];

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fmtDate(?string $date, string $format = 'd M Y'): string
{
    if (empty($date)) {
        return '-';
    }
    $ts = strtotime($date);
    return $ts ? date($format, $ts) : '-';
}

function fmtDateTime(?string $date, string $format = 'd M Y h:i A'): string
{
    if (empty($date)) {
        return '-';
    }
    $ts = strtotime($date);
    return $ts ? date($format, $ts) : '-';
}

/* STUDENT PROFILE */
$student = [
    'full_name' => $_SESSION['name'] ?? '',
    'email' => '',
    'department' => '',
    'division' => '',
    'enrollment_no' => '',
    'roll_no' => '',
    'phone' => '',
    'profile_photo' => '',
];

$studentQuery = mysqli_query(
    $conn,
    "SELECT full_name, email, department, division, enrollment_no, roll_no, phone, profile_photo
     FROM users
     WHERE id='$student_id'
     LIMIT 1"
);

if ($studentQuery && mysqli_num_rows($studentQuery) > 0) {
    $student = mysqli_fetch_assoc($studentQuery);
}

$student_name = $student['full_name'] ?: ($_SESSION['name'] ?? 'Student');
$student_division = $student['division'] ?? '';
$student_department = $student['department'] ?? '';
$student_email = $student['email'] ?? '';
$student_enrollment = $student['enrollment_no'] ?? '';
$student_roll = $student['roll_no'] ?? '';
$student_phone = $student['phone'] ?? '';
$student_initial = strtoupper(substr(trim($student_name), 0, 1));
$profile_photo = trim($student['profile_photo'] ?? '');

if (!empty($profile_photo) && file_exists("../uploads/profile_photos/" . $profile_photo)) {
    $profile_image = "../uploads/profile_photos/" . rawurlencode($profile_photo);
} else {
    $profile_image = "";
}
/* INTERNSHIP DATA */
$current_internship = null;
$approved_internship = null;

$currentInternshipQuery = mysqli_query(
    $conn,
    "SELECT *
     FROM internships
     WHERE student_id='$student_id'
     ORDER BY id DESC
     LIMIT 1"
);
if ($currentInternshipQuery && mysqli_num_rows($currentInternshipQuery) > 0) {
    $current_internship = mysqli_fetch_assoc($currentInternshipQuery);
}

$approvedInternshipQuery = mysqli_query(
    $conn,
    "SELECT *
     FROM internships
     WHERE student_id='$student_id'
     AND status='Approved'
     ORDER BY id DESC
     LIMIT 1"
);
if ($approvedInternshipQuery && mysqli_num_rows($approvedInternshipQuery) > 0) {
    $approved_internship = mysqli_fetch_assoc($approvedInternshipQuery);
}

$active_internship = $approved_internship ?: $current_internship;
$active_internship_id = $active_internship['id'] ?? 0;

/* COUNTS */
$total_applications = 0;
$approved_count = 0;
$pending_count = 0;
$total_work = 0;

$totalResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM internships WHERE student_id='$student_id'");
if ($totalResult) {
    $total_applications = (int)(mysqli_fetch_assoc($totalResult)['total'] ?? 0);
}

$approvedResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM internships WHERE student_id='$student_id' AND status='Approved'");
if ($approvedResult) {
    $approved_count = (int)(mysqli_fetch_assoc($approvedResult)['total'] ?? 0);
}

$pendingResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM internships WHERE student_id='$student_id' AND status='Pending'");
if ($pendingResult) {
    $pending_count = (int)(mysqli_fetch_assoc($pendingResult)['total'] ?? 0);
}

if (!empty($approved_internship['id'])) {
    $workResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM weekly_updates WHERE internship_id='" . (int)$approved_internship['id'] . "'");
    if ($workResult) {
        $total_work = (int)(mysqli_fetch_assoc($workResult)['total'] ?? 0);
    }
}

/* NOTIFICATIONS */
$admin_notifications = mysqli_query(
    $conn,
    "SELECT *
     FROM admin_notifications
     WHERE target_role='student' OR target_role='all'
     ORDER BY id DESC"
);

$faculty_notifications = mysqli_query(
    $conn,
    "SELECT *
     FROM faculty_notifications
     WHERE division='$student_division'
     ORDER BY id DESC"
);

$total_admin_notifications = $admin_notifications ? mysqli_num_rows($admin_notifications) : 0;
$total_faculty_notifications = $faculty_notifications ? mysqli_num_rows($faculty_notifications) : 0;

$admin_unread_query = mysqli_query(
    $conn,
    "SELECT *
     FROM admin_notifications
     WHERE (target_role='student' OR target_role='all')
     AND id NOT IN (
        SELECT notification_id
        FROM student_notification_reads
        WHERE student_id='$student_id'
        AND notification_type='admin'
     )"
);
$admin_unread_count = $admin_unread_query ? mysqli_num_rows($admin_unread_query) : 0;

$faculty_unread_query = mysqli_query(
    $conn,
    "SELECT *
     FROM faculty_notifications
     WHERE division='$student_division'
     AND id NOT IN (
        SELECT notification_id
        FROM student_notification_reads
        WHERE student_id='$student_id'
        AND notification_type='faculty'
     )"
);
$faculty_unread_count = $faculty_unread_query ? mysqli_num_rows($faculty_unread_query) : 0;

$total_notifications = $admin_unread_count + $faculty_unread_count;

/* DEADLINES */
$deadlines = [];
$deadline_result = mysqli_query($conn, "SELECT * FROM weekly_deadlines WHERE division='$student_division' ORDER BY week_no ASC");
if ($deadline_result) {
    while ($d = mysqli_fetch_assoc($deadline_result)) {
        $deadlines[(int)$d['week_no']] = $d;
    }
}

/* SUBMISSIONS BY WEEK */
$submissionsByWeek = [];
if (!empty($approved_internship['id'])) {
    $submissions_result = mysqli_query(
        $conn,
        "SELECT *
         FROM weekly_updates
         WHERE internship_id='" . (int)$approved_internship['id'] . "'
         ORDER BY week_no ASC"
    );
    if ($submissions_result) {
        while ($row = mysqli_fetch_assoc($submissions_result)) {
            $submissionsByWeek[(int)$row['week_no']] = $row;
        }
    }
}

$submitted_count = count($submissionsByWeek);
$progress_percent = $submitted_count > 0 ? min(100, round(($submitted_count / 4) * 100)) : 0;

/* LATEST FEEDBACK */
$latest_feedback = null;
$latest_feedback_query = mysqli_query(
    $conn,
    "SELECT wu.*, i.company_name, i.internship_role
     FROM weekly_updates wu
     INNER JOIN internships i ON wu.internship_id = i.id
     WHERE i.student_id='$student_id'
     AND wu.faculty_feedback IS NOT NULL
     AND wu.faculty_feedback <> ''
     ORDER BY wu.submitted_at DESC, wu.id DESC
     LIMIT 1"
);
if ($latest_feedback_query && mysqli_num_rows($latest_feedback_query) > 0) {
    $latest_feedback = mysqli_fetch_assoc($latest_feedback_query);
}

/* LATEST ANNOUNCEMENTS */
$latest_admin = [];
$latest_faculty = [];

if ($admin_notifications) {
    mysqli_data_seek($admin_notifications, 0);
    while ($row = mysqli_fetch_assoc($admin_notifications)) {
        $latest_admin[] = $row;
        if (count($latest_admin) >= 2) break;
    }
}
if ($faculty_notifications) {
    mysqli_data_seek($faculty_notifications, 0);
    while ($row = mysqli_fetch_assoc($faculty_notifications)) {
        $latest_faculty[] = $row;
        if (count($latest_faculty) >= 2) break;
    }
}

$next_deadline_week = null;
$next_deadline_row = null;
$todayTs = strtotime(date('Y-m-d H:i:s'));

foreach ($deadlines as $week => $deadlineRow) {
    $deadlineTs = strtotime($deadlineRow['deadline_date'] ?? '');
    if ($deadlineTs && $deadlineTs >= $todayTs) {
        $next_deadline_week = $week;
        $next_deadline_row = $deadlineRow;
        break;
    }
}
if (!$next_deadline_row && !empty($deadlines)) {
    $next_deadline_row = end($deadlines);
    $next_deadline_week = (int)$next_deadline_row['week_no'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{
            --bg:#f6f4ff;
            --surface:#ffffff;
            --surface-2:#faf8ff;
            --text:#1f2430;
            --muted:#6f7690;
            --border:#e7e1f7;
            --purple:#5b3df5;
            --purple-2:#6f42ff;
            --purple-3:#3b2d7a;
            --success:#1f9d5a;
            --warning:#f3b61f;
            --danger:#e25563;
            --info:#2b7cff;
            --shadow:0 18px 50px rgba(73, 45, 150, .08);
            --shadow-soft:0 10px 25px rgba(73, 45, 150, .06);
            --radius:24px;
            --sidebar-width:288px;
        }

        *{box-sizing:border-box}
        html{
            scroll-behavior:smooth;
            scroll-padding-top:18px;
        }
        body{
            margin:0;
            font-family:'Inter',system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
            background:#f5f6fa;
            color:var(--text);
            -webkit-font-smoothing:antialiased;
            -moz-osx-font-smoothing:grayscale;
            text-rendering:optimizeLegibility;
            overscroll-behavior-y:none;
        }
        a{text-decoration:none}
        body::before{ content:none; display:none; }
        .top-progress{
            position:fixed;
            top:0;
            left:0;
            height:3px;
            width:0%;
            z-index:9999;
            background:var(--purple);
            transition:width .16s linear;
        }
        .fx-bg{ display:none !important; }
        .fx-orb{ display:none !important; }
        .particle{ display:none !important; }
        .app{min-height:100vh;display:flex;position:relative;z-index:1}
        .sidebar{
    width:var(--sidebar-width);
    min-height:100vh;
    position:fixed;
    inset:0 auto 0 0;

    background:linear-gradient(
        180deg,
        #5b2d90 0%,
        #4b2484 20%,
        #3d1f73 45%,
        #6d1b88 70%,
        #5a1778 100%
    );

    color:#fff;
    border-right:none;
    box-shadow:0 10px 30px rgba(0,0,0,.18);

    z-index:20;
    display:flex;
    flex-direction:column;
    overflow:hidden;
}
        .sidebar::after{ content:none; display:none; }
        .brand{
            padding:16px 16px 12px !important;
            text-align:center;
            border-bottom:1px solid rgba(255,255,255,.12);
        }
        .brand img{
            width:108px !important;
            max-width:100%;
            height:auto;
            object-fit:contain;
            display:block;
            margin:0 auto 8px !important;
            box-shadow:0 16px 30px rgba(0,0,0,.16);
            border-radius:16px;
        }
        .brand .title{
            font-size:1.08rem;
            font-weight:800;
            line-height:1.08;
        }
        .brand .subtitle{
            margin-top:7px;
            font-size:.70rem;
            letter-spacing:.34em;
            color:rgba(255,255,255,.58);
        }
        .profile-mini{
            display:flex;
            gap:12px;
            align-items:center;
            padding:8px 16px 10px !important;
            border-bottom:1px solid rgba(255,255,255,.12);
            margin-bottom:0 !important;
        }
        .profile-mini .avatar,
        .user-chip .avatar{
            animation:none;
        }
        .profile-mini .avatar{
    width:44px;
    height:44px;
    border-radius:50%;
    overflow:hidden;
    flex-shrink:0;
    border:2px solid rgba(255,255,255,.25);
    box-shadow:0 4px 12px rgba(0,0,0,.25);

    display:flex;
    align-items:center;
    justify-content:center;

    background:linear-gradient(135deg,#6f42ff,#8e5cff);
    color:#fff;
    font-size:18px;
    font-weight:700;
    text-transform:uppercase;
}

.profile-mini .avatar img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
}
        .profile-mini .meta{min-width:0}
        .profile-mini .meta .name{
            font-size:.96rem;
            font-weight:700;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
        }
        .profile-mini .meta .small{
            color:rgba(255,255,255,.65);
            font-size:.82rem;
        }
        .nav{
            padding:8px 12px 10px !important;
            gap:2px !important;
            overflow:visible !important;
            display:flex;
            flex-direction:column;
            flex:1 1 auto;
            min-height:0;
            scrollbar-width:none;
            -ms-overflow-style:none;
        }
        .nav::-webkit-scrollbar,.sidebar::-webkit-scrollbar{width:0;height:0}
        .nav-label{
    color:rgba(255,255,255,.55);
    font-size:.75rem;
    letter-spacing:.18em;
    font-weight:700;
    text-transform:uppercase;
}
        .nav a{
    position:relative;
    display:flex;
    align-items:center;
    gap:12px;

    color:#ffffff;

    padding:11px 16px;
    margin-bottom:4px;

    border-radius:16px;

    font-weight:600;
    font-size:15px;

    transition:all .30s ease;

    overflow:hidden;
}
        .nav a::before{ content:none; display:none; }
        .nav a i{
    width:20px;
    text-align:center;
    font-size:16px;
    transition:.30s;
}

.nav a:hover i{
    transform:scale(1.15);
}
        .nav a:hover{
    background:linear-gradient(90deg,#ff5b5b,#ff8c3a);
    color:#fff;
    transform:translateX(5px);
    box-shadow:0 10px 22px rgba(255,120,60,.35);
}
        .nav a.active{
    background:linear-gradient(90deg,#ff5b6b,#ff8c3a);
    color:#fff;
    box-shadow:0 8px 18px rgba(255,107,107,.30);
}
        .nav a:hover i,
        .nav a.active i{
            transform:none;
        }
        .account-bottom{
            margin-top:8px !important;
            padding-top:6px !important;
            border-top:1px solid rgba(255,255,255,.08);
        }
        .sidebar-footer{display:none !important}

        .main{
            margin-left:var(--sidebar-width);
            width:calc(100% - var(--sidebar-width));
            background:#f5f6fa;
            color:#1f2430;
            position:relative;
            z-index:1;
        }
        .main::before{ content:none; display:none; }
        .topbar{
            position:sticky;
            top:0;
            z-index:12;
            background:#ffffff;
            border-bottom:1px solid var(--border);
        }
        .topbar-inner{
            padding:22px 30px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:20px;
        }
        .hero-title{
            display:flex;
            flex-direction:column;
            gap:6px;
        }
        .hero-title .eyebrow{
            color:var(--muted);
            font-weight:600;
            font-size:1rem;
        }
        .hero-title h1{
            margin:0;
            font-size:2rem;
            line-height:1.05;
            font-weight:800;
            letter-spacing:-.04em;
            color:#253063;
        }
        .hero-title .sub{
            color:var(--muted);
            font-size:.98rem;
            display:flex;
            gap:12px;
            flex-wrap:wrap;
        }
        .topbar-actions{
            display:flex;
            align-items:center;
            gap:18px;
            flex-wrap:wrap;
            justify-content:flex-end;
        }
        .notif-btn{
            position:relative;
            border:none;
            background:transparent;
            color:#111;
            width:44px;height:44px;
            border-radius:50%;
            display:grid;
            place-items:center;
            transition:.2s ease;
        }
        .notif-btn:hover{background:rgba(91,61,245,.08)}
        .notif-badge{
            position:absolute;
            top:-4px;
            right:-3px;
            background:#e45060;
            color:#fff;
            border-radius:999px;
            font-size:.68rem;
            min-width:22px;
            height:22px;
            padding:0 6px;
            display:grid;
            place-items:center;
            font-weight:700;
            box-shadow:0 8px 18px rgba(228,80,96,.28);
        }
        .user-chip{
            display:flex;
            align-items:center;
                padding:6px 14px 6px 6px;
    gap:12px;
    border-radius:50px;
            background:rgba(255,255,255,.92);
            border:1px solid rgba(123,97,255,.12);
            box-shadow:0 10px 25px rgba(123,97,255,.08);
        }
        .user-chip .avatar{
    width:48px;
    height:48px;
    border-radius:50%;
    overflow:hidden;
    flex-shrink:0;
    border:3px solid #fff;
    box-shadow:0 8px 18px rgba(91,61,245,.18);

    display:flex;
    align-items:center;
    justify-content:center;

    background:linear-gradient(135deg,#6f42ff,#8e5cff);
    color:#fff;
    font-size:20px;
    font-weight:700;
    text-transform:uppercase;
}

.user-chip .avatar img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
}
        .user-chip .info{
            line-height:1.2;
            padding-right:10px;
        }
        .user-chip .info .name{
            font-weight:800;
            font-size:.98rem;
            color:#1f2430;
        }
        .user-chip .info .role{
            color:var(--muted);
            font-size:.84rem;
        }

        .page{
            padding:22px 28px 36px;
            position:relative;
            z-index:1;
        }
        .stats-grid{
            display:grid;
            grid-template-columns:repeat(4,minmax(0,1fr));
            gap:18px;
            margin-bottom:22px;
        }
        .stat-card{
            border-radius:16px;
            padding:22px;
            min-height:150px;
            display:flex;
            flex-direction:column;
            justify-content:space-between;
            border:1px solid var(--border);
            box-shadow:var(--shadow-soft);
            overflow:hidden;
            position:relative;
            transition:transform .2s ease, box-shadow .2s ease;
        }
        .stat-card::before{ content:none; display:none; }
        .stat-card::after{ content:none; display:none; }
        .stat-card:hover{
            transform:translateY(-2px);
            box-shadow:var(--shadow);
        }
        .stat-card .top{
            display:flex;
            align-items:center;
            gap:14px;
        }
        .stat-card .icon{
            width:50px;height:50px;border-radius:14px;
            display:grid;place-items:center;
            background:rgba(255,255,255,.35);
            color:#fff;
            font-size:1.1rem;
            flex:0 0 auto;
        }
        .stat-card:hover .icon{
            transform:none;
        }
        .stat-card .label{
            font-weight:800;
            font-size:1rem;
            line-height:1.1;
        }
        .stat-card .value{
            font-weight:900;
            font-size:2.15rem;
            line-height:1;
            margin-top:6px;
            letter-spacing:-.05em;
        }
        .stat-card .note{
            font-size:.92rem;
            line-height:1.35;
        }
        .stat-purple{background:#f3f0ff}
        .stat-blue{background:#eef5ff}
        .stat-green{background:#eefcf3}
        .stat-orange{background:#fff8ec}
        .stat-purple .icon{background:#7B61FF}
        .stat-blue .icon{background:#3B82F6}
        .stat-green .icon{background:#22C55E}
        .stat-orange .icon{background:#F59E0B}
        .stat-purple .label,.stat-purple .value,.stat-purple .note{color:#2f2358}
        .stat-blue .label,.stat-blue .value,.stat-blue .note{color:#183b7a}
        .stat-green .label,.stat-green .value,.stat-green .note{color:#1f4f30}
        .stat-orange .label,.stat-orange .value,.stat-orange .note{color:#7a4f00}

        .cardx,.panel,.student-card,.mini-panel,.info-card,.notice-card,.feedback-box,.table-wrap,.empty-state{
            background:#ffffff;
            border-color:var(--border) !important;
            box-shadow:var(--shadow-soft);
        }
        .cardx{
            border:1px solid rgba(231,225,247,.9);
            border-radius:var(--radius);
            box-shadow:var(--shadow);
        }
        .hero-summary{
            padding:24px;
            border-radius:20px;
            background:#ffffff;
        }
        .profile-summary{
            display:grid;
            grid-template-columns:1.15fr .85fr;
            gap:22px;
            align-items:stretch;
        }
        .student-card{
            padding:24px;
            border-radius:20px;
            background:#ffffff;
            border:1px solid var(--border);
            box-shadow:var(--shadow-soft);
        }
        .student-top{
            display:flex;
            justify-content:space-between;
            gap:18px;
            align-items:flex-start;
            margin-bottom:20px;
        }
        .student-top .name{
            font-size:1.7rem;
            line-height:1.15;
            font-weight:800;
            letter-spacing:-.02em;
            color:#253063;
            margin:0 0 8px;
        }
        .student-top .hello{
            font-size:1rem;
            color:var(--muted);
            font-weight:600;
        }
        .pill-row{
            display:flex;
            flex-wrap:wrap;
            gap:8px;
            margin-top:12px;
        }
        .pill{
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:8px 12px;
            border-radius:999px;
            font-size:.85rem;
            font-weight:700;
            background:#f5f1ff;
            color:#5b3df5;
            border:1px solid rgba(91,61,245,.12);
        }
        .pill.success{background:#edf9f2;color:#1f9d5a;border-color:rgba(31,157,90,.12)}
        .pill.warning{background:#fff7e9;color:#d39200;border-color:rgba(243,182,31,.16)}
        .pill.info{background:#eef5ff;color:#2b7cff;border-color:rgba(43,124,255,.12)}
        .details-grid{
            display:grid;
            grid-template-columns:repeat(2,1fr);
            gap:14px;
            margin-top:18px;
        }
        .detail-box{
            padding:14px 16px;
            border-radius:18px;
            background:#fbfaff;
            border:1px solid #eee6ff;
        }
        .detail-box .k{
            color:var(--muted);
            font-size:.8rem;
            margin-bottom:6px;
            font-weight:600;
            text-transform:uppercase;
            letter-spacing:.08em;
        }
        .detail-box .v{
            font-weight:700;
            font-size:.98rem;
            word-break:break-word;
            color:#162033;
        }

        .mini-panel{
            padding:22px;
            border-radius:20px;
            background:#ffffff;
            border:1px solid var(--border);
            box-shadow:var(--shadow-soft);
        }
        .section-head{
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:16px;
            margin-bottom:16px;
        }
        .section-head h2{
            font-size:1.25rem;
            font-weight:800;
            margin:0;
            letter-spacing:-.03em;
            color:#253063;
            position:relative;
            display:inline-block;
            padding-bottom:12px;
        }
        .section-head h2::after{
            content:"";
            position:absolute;
            left:0;
            bottom:0;
            width:48px;
            height:3px;
            border-radius:999px;
            background:var(--purple);
        }
        .section-head p{
            margin:6px 0 0;
            color:var(--muted);
            font-size:.94rem;
        }

        .progress-wrap{
            display:grid;
            gap:14px;
        }
        .ring{
            width:118px;height:118px;
            border-radius:50%;
            background:conic-gradient(var(--purple) 0 0, #ece7ff 0 100%);
            display:grid;
            place-items:center;
            margin-inline:auto;
            box-shadow:inset 0 0 0 10px rgba(255,255,255,.7);
            transition:transform .45s cubic-bezier(.22,1,.36,1), filter .45s ease, background .1s linear;
            will-change:transform, filter, background;
            transform:translateZ(0);
        }
        .ring .inner{
            width:88px;height:88px;border-radius:50%;
            background:#fff;
            display:grid;
            place-items:center;
            text-align:center;
            box-shadow:var(--shadow-soft);
            font-weight:800;
            color:var(--purple-3);
            position:relative;
            overflow:hidden;
        }
        .ring .inner::before{
            content:'';
            position:absolute;
            inset:0;
            background:radial-gradient(circle at 30% 20%, rgba(255,255,255,.95), rgba(255,255,255,0) 56%);
            opacity:.55;
            pointer-events:none;
        }
        .ring .inner > div{
            position:relative;
            z-index:1;
        }
        .progress-meta{text-align:center}
        .progress-meta .big{
            font-size:1.65rem;
            font-weight:800;
            line-height:1;
            color:#162033;
        }
        .progress-meta .small{
            color:var(--muted);
            font-size:.92rem;
        }
        .ring.ring-animated{
            animation:none;
        }
        .ring.ring-replay{
            animation:none;
        }

        .content-grid{
            display:grid;
            grid-template-columns:1.35fr .95fr;
            gap:22px;
            margin-top:22px;
            align-items:start;
        }
        .stack{display:grid;gap:22px}
        .panel{
            padding:22px;
            border-radius:18px;
            background:var(--surface);
            border:1px solid var(--border);
            box-shadow:var(--shadow-soft);
            transition:box-shadow .2s ease;
        }
        .panel:hover,
        .info-card:hover,
        .notice-card:hover,
        .feedback-box:hover{
            box-shadow:var(--shadow);
        }

        .table-wrap{
            overflow:auto;
            border-radius:20px;
            border:1px solid var(--border);
        }
        .table{margin:0}
        .table thead th{
            background:rgba(247,245,255,.96);
            border-bottom:1px solid rgba(123,97,255,.12);
            color:#2f365f;
            font-size:.85rem;
            text-transform:uppercase;
            letter-spacing:.07em;
            white-space:nowrap;
        }
        .table td,.table th{
            padding:14px 14px;
            vertical-align:middle;
            color:#1f2430;
        }
        .table tbody tr:hover{background:rgba(123,97,255,.05)}
        .week-title{font-weight:800;color:#162033}
        .status-badge{
            padding:8px 12px;
            border-radius:999px;
            font-size:.78rem;
            font-weight:800;
            display:inline-flex;
            align-items:center;
            gap:6px;
            white-space:nowrap;
            box-shadow:0 8px 18px rgba(0,0,0,.03);
        }
        .b-success{background:#edf9f2;color:#1f9d5a;border:1px solid rgba(34,197,94,.18)}
        .b-warning{background:#fff4d6;color:#a86f00;border:1px solid rgba(245,158,11,.18)}
        .b-danger{background:#feecef;color:#c63e52;border:1px solid rgba(239,68,68,.18)}
        .b-gray{background:#edf0f7;color:#586178;border:1px solid rgba(100,116,139,.18)}
        .b-info{background:#eef5ff;color:#2b7cff;border:1px solid rgba(59,130,246,.18)}
        .b-purple{background:#f0ebff;color:#5b3df5;border:1px solid rgba(123,97,255,.18)}
        .action-btn{
            border:1px solid var(--border);
            background:#ffffff;
            color:#4338ca;
            font-weight:700;
            padding:9px 14px;
            border-radius:12px;
            transition:background .2s ease, border-color .2s ease;
            display:inline-flex;
            align-items:center;
            gap:8px;
        }
        .action-btn:hover{
            background:#f5f3ff;
            border-color:rgba(123,97,255,.28);
        }
        .summary-list{display:grid;gap:14px}
        .info-card{
            padding:18px;
            border-radius:16px;
            background:#fff;
            border:1px solid var(--border);
            box-shadow:var(--shadow-soft);
            transition:box-shadow .2s ease;
        }
        .info-card .row-top{
            display:flex;
            align-items:flex-start;
            gap:14px;
            justify-content:space-between;
        }
        .info-card .title{
            font-weight:800;
            font-size:1.08rem;
            margin:0 0 8px;
            letter-spacing:-.02em;
            color:#162033;
        }
        .info-card .label{
            color:var(--muted);
            font-size:.83rem;
            text-transform:uppercase;
            letter-spacing:.08em;
            font-weight:700;
            margin-bottom:6px;
        }
        .info-card .value{
            font-weight:700;
            font-size:1rem;
            margin:0 0 10px;
            color:#162033;
        }
        .info-card .meta{
            color:var(--muted);
            font-size:.92rem;
            line-height:1.5;
        }
        .company-illustration{
            width:92px;height:92px;border-radius:22px;
            display:grid;place-items:center;
            background:linear-gradient(135deg,#f3ecff,#eef5ff);
            color:#7a54ff;
            font-size:2rem;
            flex:0 0 auto;
        }

        .notice-card{
            padding:18px;
            border-radius:16px;
            background:#fff;
            border:1px solid var(--border);
            box-shadow:var(--shadow-soft);
            transition:box-shadow .2s ease;
        }
        .notice-card .tag,
        .notif-item .tag{
            display:inline-flex;
            align-items:center;
            padding:6px 10px;
            border-radius:999px;
            font-size:.74rem;
            font-weight:800;
            margin-bottom:12px;
        }
        .tag.admin{background:#f0ebff;color:#5b3df5}
        .tag.faculty{background:#edf9f2;color:#1f9d5a}
        .notice-card h4{
            font-size:1rem;
            font-weight:800;
            margin:0 0 8px;
            color:#162033;
        }
        .notice-card p{
            color:var(--muted);
            font-size:.92rem;
            margin:0 0 12px;
            line-height:1.5;
        }
        .feedback-box{
            padding:18px;
            border-radius:16px;
            background:#fff;
            border:1px solid var(--border);
            box-shadow:var(--shadow-soft);
            transition:box-shadow .2s ease;
        }
        .dropdown-menu.notif-menu{
            width:min(520px, 92vw);
            max-height:520px;
            overflow:auto;
            border-radius:20px;
            border:1px solid var(--border);
            padding:0;
            box-shadow:0 30px 80px rgba(0,0,0,.18);
            color:#1f2430;
        }
        .notif-header{
            padding:16px 18px;
            border-bottom:1px solid rgba(123,97,255,.10);
            background:linear-gradient(135deg,#fcfbff,#f5f1ff);
        }
        .notif-body{padding:12px}
        .notif-item{
            padding:14px;
            border:1px solid rgba(123,97,255,.10);
            border-radius:18px;
            margin-bottom:10px;
            background:#fff;
        }
        .notif-item:last-child{margin-bottom:0}
        .notif-item .title{
            font-weight:800;
            margin-bottom:4px;
            color:#162033;
        }
        .notif-item .msg{
            color:var(--muted);
            font-size:.92rem;
            line-height:1.45;
        }
        .notif-item .time{
            margin-top:8px;
            color:#7c849b;
            font-size:.8rem;
        }

        .empty-state{
            padding:18px;
            background:linear-gradient(135deg,#f7f5ff,#eef5ff);
            border:1px dashed #d7c9ff;
            border-radius:18px;
            color:var(--muted);
            text-align:center;
        }

        .reveal{
            opacity:0;
            transform:translateY(8px);
            will-change:opacity, transform;
            transition:
                opacity .4s ease,
                transform .4s ease;
        }
        .reveal.visible{
            opacity:1;
            transform:translateY(0);
        }
        .stat-number{
            display:inline-block;
            min-width:1ch;
            letter-spacing:-.04em;
            font-variant-numeric:tabular-nums;
        }
        .sidebar, .nav{
            scrollbar-width:none;
            -ms-overflow-style:none;
        }

        @keyframes gradientFlow{
            0%{background-position:0% 50%}
            50%{background-position:100% 50%}
            100%{background-position:0% 50%}
        }
        @keyframes cardFloat{
            0%,100%{transform:translateY(0)}
            50%{transform:translateY(-4px)}
        }
        @keyframes floatIcon{
            0%,100%{transform:translateY(0)}
            50%{transform:translateY(-4px)}
        }
        @keyframes orbFloat{
            0%,100%{transform:translate3d(0,0,0) scale(1)}
            50%{transform:translate3d(0,-18px,0) scale(1.05)}
        }
        @keyframes particleDrift{
            0%{transform:translate3d(0,0,0) scale(.9);opacity:.08}
            15%{opacity:.28}
            50%{transform:translate3d(120px,-140px,0) scale(1.2);opacity:.25}
            85%{opacity:.18}
            100%{transform:translate3d(260px,-280px,0) scale(.85);opacity:0}
        }
        @keyframes underlinePulse{
            0%,100%{transform:scaleX(.86);opacity:.75}
            50%{transform:scaleX(1);opacity:1}
        }
        @keyframes ringFloat{
            0%,100%{transform:translateY(0) scale(1)}
            50%{transform:translateY(-3px) scale(1.015)}
        }
        @keyframes ringGlow{
            0%,100%{filter:drop-shadow(0 0 0 rgba(123,97,255,0))}
            50%{filter:drop-shadow(0 0 18px rgba(123,97,255,.16))}
        }
        @keyframes ringPulse{
            0%{transform:scale(.92);filter:drop-shadow(0 0 0 rgba(123,97,255,0))}
            55%{transform:scale(1.04);filter:drop-shadow(0 0 22px rgba(123,97,255,.22))}
            100%{transform:scale(1);filter:drop-shadow(0 0 8px rgba(123,97,255,.10))}
        }

        @media (prefers-reduced-motion: reduce){
            *, *::before, *::after{
                animation-duration:.001ms !important;
                animation-iteration-count:1 !important;
                transition-duration:.001ms !important;
                scroll-behavior:auto !important;
            }
        }
        @media (max-width: 1200px){
            .sidebar{position:relative;width:100%;min-height:auto;height:auto}
            .main{margin-left:0;width:100%}
            .topbar-inner{flex-direction:column;align-items:flex-start}
            .topbar-actions{width:100%;justify-content:space-between}
            .stats-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
            .profile-summary,.content-grid{grid-template-columns:1fr}
        }
        @media (max-width: 768px){
            .topbar-inner,.page{padding-left:16px;padding-right:16px}
            .stats-grid{grid-template-columns:1fr}
            .details-grid{grid-template-columns:1fr}
            .hero-title h1{font-size:1.45rem}
            .student-top{flex-direction:column}
            .user-chip{width:100%;justify-content:space-between}
            .section-head{flex-direction:column}
        }
        
        .user-chip{
    cursor:pointer;
}

.user-chip .avatar{
    cursor:zoom-in;
}

.profile-image-modal{
    position:fixed;
    top:0;
    left:0;
    width:100vw;
    height:100vh;
    background:rgba(0,0,0,.88);

    display:flex;
    justify-content:center;
    align-items:center;
    flex-direction:column;

    opacity:0;
    visibility:hidden;

    transition:.25s;
    z-index:999999;

    overflow:hidden;
}

.profile-image-modal.show{
    opacity:1;
    visibility:visible;
}

.large-profile-image{
    width:320px;
    height:320px;
    max-width:90vw;
    max-height:90vh;

    border-radius:50%;
    object-fit:cover;

    border:6px solid white;
    box-shadow:0 20px 60px rgba(0,0,0,.45);

    animation:zoomImage .25s ease;
}

.close-image{
    position:absolute;
    top:25px;
    right:35px;
    color:#fff;
    font-size:42px;
    cursor:pointer;
    z-index:1000000;
}

.image-actions{
    margin-top:25px;
}

@keyframes zoomImage{
    from{
        transform:scale(.5);
        opacity:0;
    }
    to{
        transform:scale(1);
        opacity:1;
    }
}

    </style>
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="brand">
            <img src="../assets/images/logo.png" alt="MIT-ADT University">
            <div class="title">MIT-ADT University</div>
            <div class="subtitle">PUNE, INDIA</div>
        </div>

        <div class="profile-mini">
           <div class="avatar">
    <?php if($profile_image != "") { ?>
        <img src="<?php echo $profile_image; ?>" alt="Profile">
    <?php } else { ?>
        <span><?php echo h($student_initial ?: 'S'); ?></span>
    <?php } ?>
</div>
            <div class="meta">
                <div class="name"><?php echo h($student_name); ?></div>
                <div class="small"><?php echo h($student_division ?: 'Student'); ?></div>
            </div>
        </div>

        <nav class="nav">
            <div class="nav-label">Workspace</div>
            <a href="dashboard.php" class="active"><i class="fa-solid fa-house"></i>Dashboard</a>
            <a href="apply_internship.php"><i class="fa-solid fa-briefcase"></i>Apply Internship Form</a>
            <a href="my_internships.php"><i class="fa-solid fa-list"></i>My Internships</a>
            <a href="add_update.php"><i class="fa-solid fa-calendar-days"></i>Weekly Updates</a>
            <a href="my_updates.php"><i class="fa-solid fa-clipboard-list"></i>My Weekly Work</a>
            <a href="upload_report.php"><i class="fa-solid fa-file-arrow-up"></i>Final Internship Certificate</a>

            <div class="nav-label account-bottom">Account</div>
            <a href="profile.php"><i class="fa-solid fa-user"></i>Profile</a>
            <a href="change_password.php"><i class="fa-solid fa-lock"></i>Change Password</a>
            <a href="../logout.php"><i class="fa-solid fa-right-from-bracket"></i>Logout</a>
        </nav>
    </aside>

    <main class="main">
        <div class="topbar">
            <div class="topbar-inner">
                <div class="hero-title reveal visible">
                    <div class="eyebrow">Welcome,</div>
                    <h1><?php echo h($student_name); ?></h1>
                    <div class="sub">
                        <span><?php echo h($student_division ?: 'Division not set'); ?></span>
                        <span><?php echo h($student_department ?: 'Department not set'); ?></span>
                        <?php if (!empty($student_enrollment)): ?><span><?php echo h($student_enrollment); ?></span><?php endif; ?>
                    </div>
                </div>

                <div class="topbar-actions">
                    <div class="dropdown">
                        <button class="notif-btn" data-bs-toggle="dropdown" aria-expanded="false" onclick="markNotificationsRead()">
                            <i class="fa-regular fa-bell fs-4"></i>
                            <?php if ($total_notifications > 0): ?>
                                <span class="notif-badge"><?php echo (int)$total_notifications; ?></span>
                            <?php endif; ?>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end notif-menu shadow-lg border-0">
                            <div class="notif-header">
                                <div class="fw-bold fs-5">Notifications</div>
                                <div class="text-muted small">Admin and faculty updates</div>
                            </div>
                            <div class="notif-body">
                                <?php if ($total_admin_notifications == 0 && $total_faculty_notifications == 0): ?>
                                    <div class="empty-state">No notifications available.</div>
                                <?php else: ?>
                                    <?php
                                    if (!empty($latest_admin)) {
                                        foreach ($latest_admin as $admin) {
                                            ?>
                                            <div class="notif-item">
                                                <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                                                    <span class="tag admin">Admin</span>
                                                    <span class="small text-muted"><?php echo fmtDateTime($admin['created_at'] ?? null); ?></span>
                                                </div>
                                                <div class="title"><?php echo h($admin['title'] ?? 'Notification'); ?></div>
                                                <div class="msg"><?php echo h($admin['message'] ?? ''); ?></div>
                                                <?php if (!empty($admin['attachment'])): ?>
                                                    <div class="mt-2">
                                                        <a class="action-btn" href="../uploads/notifications/<?php echo h($admin['attachment']); ?>" target="_blank">
                                                            <i class="fa-solid fa-download"></i> Attachment
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php
                                        }
                                    }

                                    if (!empty($latest_faculty)) {
                                        foreach ($latest_faculty as $faculty) {
                                            ?>
                                            <div class="notif-item">
                                                <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                                                    <span class="tag faculty">Faculty</span>
                                                    <span class="small text-muted"><?php echo fmtDateTime($faculty['created_at'] ?? null); ?></span>
                                                </div>
                                                <div class="title"><?php echo h($faculty['title'] ?? 'Notification'); ?></div>
                                                <div class="msg"><?php echo h($faculty['message'] ?? ''); ?></div>
                                                <?php if (!empty($faculty['attachment'])): ?>
                                                    <div class="mt-2">
                                                        <a class="action-btn" href="../uploads/notifications/<?php echo h($faculty['attachment']); ?>" target="_blank">
                                                            <i class="fa-solid fa-download"></i> Attachment
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php
                                        }
                                    }
                                    ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="user-chip">

    <div class="avatar" <?php if($profile_image != "") { ?>onclick="openProfileImage();" style="cursor:zoom-in;"<?php } ?>>
    <?php if($profile_image != "") { ?>
        <img src="<?php echo $profile_image; ?>" alt="Profile">
    <?php } else { ?>
        <span><?php echo h($student_initial ?: 'S'); ?></span>
    <?php } ?>
</div>

<div class="info" onclick="window.location='profile.php';" style="cursor:pointer;">        <div class="name"><?php echo h($student_name); ?></div>
        <div class="role">Student</div>
    </div>

</div>
                </div>
            </div>
        </div>

        <div class="page">
            <div class="stats-grid">
                <div class="stat-card stat-purple reveal">
                    <div class="top">
                        <div class="icon"><i class="fa-solid fa-file-lines"></i></div>
                        <div>
                            <div class="label">Total Applications</div>
                            <div class="value"><?php echo (int)$total_applications; ?></div>
                            <div class="note">All applications</div>
                        </div>
                    </div>
                </div>

                <div class="stat-card stat-green reveal">
                    <div class="top">
                        <div class="icon"><i class="fa-solid fa-circle-check"></i></div>
                        <div>
                            <div class="label">Approved</div>
                            <div class="value"><?php echo (int)$approved_count; ?></div>
                            <div class="note">Applications approved</div>
                        </div>
                    </div>
                </div>

                <div class="stat-card stat-orange reveal">
                    <div class="top">
                        <div class="icon"><i class="fa-solid fa-clock"></i></div>
                        <div>
                            <div class="label">Pending</div>
                            <div class="value"><?php echo (int)$pending_count; ?></div>
                            <div class="note">Awaiting review</div>
                        </div>
                    </div>
                </div>

                <div class="stat-card stat-blue reveal">
                    <div class="top">
                        <div class="icon"><i class="fa-solid fa-calendar-check"></i></div>
                        <div>
                            <div class="label">Weekly Work Submitted</div>
                            <div class="value"><?php echo (int)$submitted_count; ?></div>
                            <div class="note">This week submissions</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="hero-summary cardx reveal">
                <div class="profile-summary">
                    <div class="student-card">
                        <div class="student-top">
                            <div>
                                <div class="hello">Welcome Back,</div>
                                <div class="name"><?php echo h($student_name); ?></div>
                                <div class="pill-row">
                                    <span class="pill info"><?php echo h($student_division ?: 'Division not set'); ?></span>
                                    <?php if (!empty($student_enrollment)): ?><span class="pill"><?php echo h($student_enrollment); ?></span><?php endif; ?>
                                    <?php if (!empty($student_roll)): ?><span class="pill"><?php echo h($student_roll); ?></span><?php endif; ?>
                                    <?php if (!empty($student_department)): ?><span class="pill"><?php echo h($student_department); ?></span><?php endif; ?>
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="pill <?php echo $approved_count > 0 ? 'success' : 'warning'; ?>">
                                    <i class="fa-solid fa-badge-check"></i>
                                    <?php echo $approved_count > 0 ? 'Internship Approved' : 'Status Pending'; ?>
                                </div>
                            </div>
                        </div>

                        <div class="details-grid">
                            <div class="detail-box">
                                <div class="k">Email</div>
                                <div class="v"><?php echo h($student_email ?: '-'); ?></div>
                            </div>
                            <div class="detail-box">
                                <div class="k">Phone</div>
                                <div class="v"><?php echo h($student_phone ?: '-'); ?></div>
                            </div>
                            <div class="detail-box">
                                <div class="k">Division</div>
                                <div class="v"><?php echo h($student_division ?: '-'); ?></div>
                            </div>
                            <div class="detail-box">
                                <div class="k">Department</div>
                                <div class="v"><?php echo h($student_department ?: '-'); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="mini-panel">
                        <div class="section-head mb-3">
                            <div>
                                <h2>Weekly Progress</h2>
                                <p>Your submission status at a glance.</p>
                            </div>
                        </div>

                        <div class="progress-wrap">
                            <div class="ring" data-target="<?php echo (int)$progress_percent; ?>" style="background:conic-gradient(var(--purple) 0%, #ece7ff 0%);">
                                <div class="inner">
                                    <div><?php echo (int)$progress_percent; ?>%</div>
                                </div>
                            </div>
                            <div class="progress-meta">
                                <div class="big"><?php echo (int)$submitted_count; ?>/4</div>
                                <div class="small">Weekly reports submitted</div>
                            </div>
                        </div>

                        <div class="mt-4">
                            <div class="detail-box">
                                <div class="k">Next Due</div>
                                <div class="v">
                                    <?php
                                    if ($next_deadline_row) {
                                        echo 'Week ' . h($next_deadline_week) . ' · ' . h(fmtDateTime($next_deadline_row['deadline_date'] ?? null));
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-grid">
                <div class="stack">
                    <div class="panel reveal">
                        <div class="section-head">
                            <div>
                                <h2>Weekly Work Overview</h2>
                                <p>Track deadline, submission and review status for each week.</p>
                            </div>
                        </div>

                        <div class="table-wrap">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Week</th>
                                        <th>Deadline</th>
                                        <th>Submission</th>
                                        <th>Status</th>
                                        <th>Remarks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php for ($week = 1; $week <= 4; $week++): ?>
                                        <?php
                                            $deadlineRow = $deadlines[$week] ?? null;
                                            $submission = $submissionsByWeek[$week] ?? null;
                                            $deadlineText = $deadlineRow ? fmtDateTime($deadlineRow['deadline_date'] ?? null) : '-';
                                            $submittedAt = $submission ? fmtDateTime($submission['submitted_at'] ?? null) : '-';
                                            $reviewStatus = $submission['faculty_status'] ?? ($submission ? 'Submitted' : 'Not Submitted');
                                            $remarks = $submission['faculty_feedback'] ?? '';
                                            $statusClass = 'b-gray';
                                            $statusText = 'Not Submitted';

                                            if ($submission) {
                                                $submissionTs = strtotime($submission['submitted_at'] ?? '');
                                                $deadlineTs = $deadlineRow ? strtotime($deadlineRow['deadline_date'] ?? '') : 0;

                                                if (!empty($submission['faculty_status']) && strtolower($submission['faculty_status']) === 'approved') {
                                                    $statusClass = 'b-success';
                                                    $statusText = 'Approved';
                                                } elseif (!empty($submission['faculty_status']) && strtolower($submission['faculty_status']) === 'rejected') {
                                                    $statusClass = 'b-danger';
                                                    $statusText = 'Rejected';
                                                } elseif ($deadlineTs && $submissionTs && $submissionTs <= $deadlineTs) {
                                                    $statusClass = 'b-info';
                                                    $statusText = 'On Time';
                                                } else {
                                                    $statusClass = 'b-warning';
                                                    $statusText = 'Submitted';
                                                }
                                            }
                                        ?>
                                        <tr>
                                            <td><span class="week-title">Week <?php echo (int)$week; ?></span></td>
                                            <td><?php echo h($deadlineText); ?></td>
                                            <td><?php echo h($submittedAt); ?></td>
                                            <td><span class="status-badge <?php echo $statusClass; ?>"><?php echo h($statusText); ?></span></td>
                                            <td><?php echo h($remarks ?: '-'); ?></td>
                                        </tr>
                                    <?php endfor; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="panel reveal">
                        <div class="section-head">
                            <div>
                                <h2>Next Weekly Deadline</h2>
                                <p>Upcoming due date for your division.</p>
                            </div>
                        </div>

                        <?php if ($next_deadline_row): ?>
                            <div class="info-card" style="background:linear-gradient(135deg,#fff9ea,#fffdf5);">
                                <div class="row-top">
                                    <div>
                                        <div class="label">Next Weekly Deadline</div>
                                        <h4 class="title mb-2">Week <?php echo h($next_deadline_week); ?> Submission Deadline</h4>
                                        <div class="meta"><?php echo h(fmtDateTime($next_deadline_row['deadline_date'] ?? null)); ?></div>
                                    </div>
                                    <div class="company-illustration" style="background:linear-gradient(135deg,#ffeebe,#fff3d8);color:#d49600;">
                                        <i class="fa-solid fa-clock"></i>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">No weekly deadlines have been set yet for your division.</div>
                        <?php endif; ?>
                    </div>

                    <div class="panel reveal">
                        <div class="section-head">
                            <div>
                                <h2>Latest Faculty Feedback</h2>
                                <p>Your most recent review comment appears here.</p>
                            </div>
                        </div>

                        <?php if ($latest_feedback): ?>
                            <div class="feedback-box">
                                <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
                                    <span class="status-badge b-purple">Week <?php echo (int)$latest_feedback['week_no']; ?></span>
                                    <span class="text-muted small"><?php echo h(fmtDateTime($latest_feedback['submitted_at'] ?? null)); ?></span>
                                </div>
                                <div class="fw-bold mb-1"><?php echo h($latest_feedback['company_name'] ?? ''); ?> · <?php echo h($latest_feedback['internship_role'] ?? ''); ?></div>
                                <div class="text-muted mb-3">Faculty Feedback</div>
                                <div style="font-size:1rem; line-height:1.65;"><?php echo nl2br(h($latest_feedback['faculty_feedback'] ?? '')); ?></div>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">No faculty feedback yet.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="stack">
                    <div class="panel reveal">
                        <div class="section-head">
                            <div>
                                <h2>My Internship Summary</h2>
                                <p>Current internship details and status.</p>
                            </div>
                        </div>

                        <?php if ($active_internship): ?>
                            <div class="info-card">
                                <div class="row-top">
                                    <div>
                                        <div class="label">Application Status</div>
                                        <h4 class="title"><?php echo h($active_internship['status'] ?: 'Pending'); ?></h4>
                                        <div class="meta">
                                            <?php
                                            $status = $active_internship['status'] ?? 'Pending';
                                            if ($status === 'Approved') {
                                                echo '<span class="status-badge b-success">Approved</span>';
                                            } elseif ($status === 'Rejected') {
                                                echo '<span class="status-badge b-danger">Rejected</span>';
                                            } else {
                                                echo '<span class="status-badge b-warning">Pending</span>';
                                            }
                                            ?>
                                        </div>
                                    </div>
                                    <div class="company-illustration">
                                        <i class="fa-solid fa-building"></i>
                                    </div>
                                </div>

                                <div class="mt-3">
                                    <div class="label">Company</div>
                                    <div class="value"><?php echo h($active_internship['company_name'] ?? '-'); ?></div>

                                    <div class="label mt-3">Internship Role</div>
                                    <div class="value"><?php echo h($active_internship['internship_role'] ?? '-'); ?></div>

                                    <div class="label mt-3">Internship Type</div>
                                    <div class="value"><?php echo h($active_internship['internship_type'] ?? '-'); ?></div>

                                    <div class="label mt-3">Mode</div>
                                    <div class="value"><?php echo h($active_internship['mode'] ?? '-'); ?></div>

                                    <div class="label mt-3">Mentor</div>
                                    <div class="value"><?php echo h($active_internship['mentor_name'] ?: '-'); ?></div>

                                    <div class="label mt-3">Duration</div>
                                    <div class="value">
                                        <?php echo h(fmtDate($active_internship['start_date'] ?? null)); ?> → <?php echo h(fmtDate($active_internship['end_date'] ?? null)); ?>
                                    </div>
                                </div>

                                <div class="mt-4 d-grid gap-2">
                                    <a href="my_internships.php" class="action-btn justify-content-center">
                                        <i class="fa-solid fa-list"></i> View All Internships
                                    </a>
                                    <a href="add_update.php" class="action-btn justify-content-center">
                                        <i class="fa-solid fa-file-contract"></i> Add Weekly Update
                                    </a>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                No internship application found yet. Please apply for an internship from the application section.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="panel reveal">
                        <div class="section-head">
                            <div>
                                <h2>Important Announcements</h2>
                                <p>Recent updates from faculty and admin.</p>
                            </div>
                        </div>

                        <div class="summary-list">
                            <?php
                            $announcementCount = 0;

                            foreach ($latest_faculty as $faculty) {
                                $announcementCount++;
                                ?>
                                <div class="notice-card">
                                    <span class="tag faculty">Faculty</span>
                                    <h4><?php echo h($faculty['title'] ?? 'Announcement'); ?></h4>
                                    <p><?php echo h(function_exists('mb_strimwidth') ? mb_strimwidth($faculty['message'] ?? '', 0, 140, '...') : substr($faculty['message'] ?? '', 0, 140) . ((strlen($faculty['message'] ?? '') > 140) ? '...' : '')); ?></p>
                                    <?php if (!empty($faculty['attachment'])): ?>
                                        <a class="action-btn" href="../uploads/notifications/<?php echo h($faculty['attachment']); ?>" target="_blank">
                                            <i class="fa-solid fa-download"></i> View Attachment
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <?php
                            }

                            foreach ($latest_admin as $admin) {
                                $announcementCount++;
                                ?>
                                <div class="notice-card">
                                    <span class="tag admin">Admin</span>
                                    <h4><?php echo h($admin['title'] ?? 'Announcement'); ?></h4>
                                    <p><?php echo h(function_exists('mb_strimwidth') ? mb_strimwidth($admin['message'] ?? '', 0, 140, '...') : substr($admin['message'] ?? '', 0, 140) . ((strlen($admin['message'] ?? '') > 140) ? '...' : '')); ?></p>
                                    <?php if (!empty($admin['attachment'])): ?>
                                        <a class="action-btn" href="../uploads/notifications/<?php echo h($admin['attachment']); ?>" target="_blank">
                                            <i class="fa-solid fa-download"></i> View Attachment
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <?php
                            }

                            if ($announcementCount === 0): ?>
                                <div class="empty-state">No announcements available.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function markNotificationsRead() {
    fetch("mark_notifications_read.php")
        .catch(() => {})
        .finally(() => {
            const badge = document.querySelector(".notif-badge");
            if (badge) badge.style.display = "none";
        });
}
</script>

<script>
function markNotificationsRead() {
    fetch("mark_notifications_read.php")
        .catch(() => {})
        .finally(() => {
            const badge = document.querySelector(".notif-badge");
            if (badge) badge.style.display = "none";
        });
}

function openProfileImage() {
    document.getElementById("profileImageModal").classList.add("show");
}

function closeProfileImage() {
    document.getElementById("profileImageModal").classList.remove("show");
}

document.addEventListener("DOMContentLoaded", function () {
    const modal = document.getElementById("profileImageModal");

    if(modal){
        modal.addEventListener("click", function(e){
            if(e.target === modal){
                closeProfileImage();
            }
        });
    }
});
</script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const topProgress = document.getElementById('topProgress') || (() => {
        const el = document.createElement('div');
        el.className = 'top-progress';
        el.id = 'topProgress';
        document.body.prepend(el);
        return el;
    })();

    const fxBg = document.querySelector('.fx-bg') || (() => {
        const el = document.createElement('div');
        el.className = 'fx-bg';
        el.setAttribute('aria-hidden', 'true');
        el.innerHTML = `
            <div class="fx-orb orb1"></div>
            <div class="fx-orb orb2"></div>
            <div class="fx-orb orb3"></div>
            <div class="fx-particles" id="fxParticles"></div>
        `;
        document.body.prepend(el);
        return el;
    })();

    const particlesHost = document.getElementById('fxParticles');
    if (particlesHost && particlesHost.childElementCount === 0) {
        const count = 10;
        for (let i = 0; i < count; i++) {
            const p = document.createElement('span');
            p.className = 'particle';
            const size = 3 + Math.random() * 5;
            const left = Math.random() * 100;
            const top = Math.random() * 100;
            const dur = 18 + Math.random() * 12;
            const delay = -Math.random() * dur;
            p.style.width = `${size}px`;
            p.style.height = `${size}px`;
            p.style.left = `${left}%`;
            p.style.top = `${top}%`;
            p.style.setProperty('--dur', `${dur}s`);
            p.style.animationDelay = `${delay}s`;
            particlesHost.appendChild(p);
        }
    }

    const rafThrottle = (fn) => {
        let ticking = false;
        return (...args) => {
            if (ticking) return;
            ticking = true;
            requestAnimationFrame(() => {
                fn(...args);
                ticking = false;
            });
        };
    };

    const revealTargets = [
        '.topbar',
        '.stats-grid .stat-card',
        '.hero-summary',
        '.student-card',
        '.mini-panel',
        '.panel',
        '.info-card',
        '.notice-card',
        '.feedback-box',
        '.table-wrap',
        '.empty-state',
        '.user-chip',
        '.action-btn'
    ];

    const revealEls = [...document.querySelectorAll(revealTargets.join(','))];
    revealEls.forEach((el, index) => {
        el.classList.add('reveal');
        el.style.transitionDelay = `${Math.min(index * 18, 120)}ms`;
    });

    // Everything above the fold should show immediately.
    document.querySelectorAll('.topbar, .stats-grid, .hero-summary, .student-card, .mini-panel')
        .forEach(el => el.classList.add('visible'));

    const revealedOnce = new WeakSet();
    const observer = new IntersectionObserver((entries, obs) => {
        entries.forEach(entry => {
            const el = entry.target;

            if (entry.isIntersecting && !revealedOnce.has(el)) {
                revealedOnce.add(el);
                requestAnimationFrame(() => {
                    el.classList.add('visible');
                    if (el.classList.contains('stat-card')) animateCounter(el);
                    if (el.classList.contains('ring')) animateRing(el, true);
                });
                obs.unobserve(el);
            }
        });
    }, { threshold: 0.12, rootMargin: '0px 0px -5% 0px' });

    revealEls.forEach(el => {
        if (!el.classList.contains('visible')) {
            observer.observe(el);
        }
    });

    function animateCounter(card) {
        const valueEl = card.querySelector('.value');
        if (!valueEl || valueEl.dataset.animated === '1') return;

        const raw = (valueEl.textContent || '').trim();
        const match = raw.match(/^([\d,]+(?:\.\d+)?)\s*(.*)$/);
        const numberPart = match ? match[1] : '0';
        const suffix = match ? match[2] : '';
        const target = parseFloat(numberPart.replace(/,/g, '')) || 0;
        const decimals = (numberPart.split('.')[1] || '').length;

        valueEl.dataset.animated = '1';
        valueEl.innerHTML = '<span class="stat-number">0</span>' + (suffix ? `<span class="stat-suffix">${suffix}</span>` : '');
        const numberSpan = valueEl.querySelector('.stat-number');
        const start = performance.now();
        const duration = 1100;

        const format = (n) => decimals > 0 ? n.toFixed(decimals) : Math.round(n).toLocaleString();

        const step = (now) => {
            const p = Math.min((now - start) / duration, 1);
            const eased = p < 0.5 ? 4 * p * p * p : 1 - Math.pow(-2 * p + 2, 3) / 2;
            numberSpan.textContent = format(target * eased);
            if (p < 1) requestAnimationFrame(step);
            else numberSpan.textContent = format(target);
        };

        requestAnimationFrame(step);
    }

    const progressRing = document.querySelector('.ring[data-target]');
    let ringInView = false;
    let ringAnimating = false;
    let ringReplayLock = 0;

    function setRingProgress(ringEl, pct) {
        const clamped = Math.max(0, Math.min(100, pct));
        ringEl.style.background = `conic-gradient(var(--purple) ${clamped}%, #ece7ff ${clamped}% 100%)`;
    }

    function resetRing(ringEl) {
        if (!ringEl) return;
        setRingProgress(ringEl, 0);
        ringEl.classList.remove('ring-animated', 'ring-replay');
    }

    function animateRing(ringEl, force = false) {
        if (!ringEl) return;
        const target = parseFloat(ringEl.dataset.target || '0') || 0;
        const now = performance.now();
        if (ringAnimating && !force) return;
        if (!force && now - ringReplayLock < 320) return;

        ringAnimating = true;
        ringReplayLock = now;
        ringEl.classList.add('ring-animated', 'ring-replay');

        const start = performance.now();
        const duration = 1300;

        const step = (t) => {
            const p = Math.min((t - start) / duration, 1);
            const eased = p < 0.5 ? 4 * p * p * p : 1 - Math.pow(-2 * p + 2, 3) / 2;
            setRingProgress(ringEl, target * eased);
            if (p < 1) requestAnimationFrame(step);
            else {
                setRingProgress(ringEl, target);
                ringEl.classList.remove('ring-replay');
                ringAnimating = false;
            }
        };

        requestAnimationFrame(step);
    }

    if (progressRing) {
        resetRing(progressRing);
        const ringObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                ringInView = entry.isIntersecting;
                if (entry.isIntersecting) animateRing(entry.target, true);
                else resetRing(entry.target);
            });
        }, { threshold: 0.58, rootMargin: '0px 0px -8% 0px' });

        ringObserver.observe(progressRing);

        const replayOnScroll = rafThrottle(() => {
            if (!ringInView || progressRing.dataset.replayLock === '1') return;
            progressRing.dataset.replayLock = '1';
            animateRing(progressRing, true);
            window.setTimeout(() => {
                progressRing.dataset.replayLock = '0';
            }, 900);
        });

        window.addEventListener('scroll', replayOnScroll, { passive: true });
    }

    const updateProgress = rafThrottle(() => {
        const doc = document.documentElement;
        const scrollTop = doc.scrollTop || document.body.scrollTop || 0;
        const scrollHeight = Math.max(1, (doc.scrollHeight || document.body.scrollHeight) - window.innerHeight);
        const pct = (scrollTop / scrollHeight) * 100;
        topProgress.style.width = `${Math.max(0, Math.min(100, pct))}%`;
    });

    updateProgress();
    window.addEventListener('scroll', updateProgress, { passive: true });

    document.querySelectorAll('.stat-card, .panel, .info-card, .notice-card, .feedback-box').forEach(el => {
        el.addEventListener('mouseenter', () => el.classList.add('chart-active'));
        el.addEventListener('mouseleave', () => el.classList.remove('chart-active'));
    });
});
</script>


<!-- Profile Image Modal -->
<div class="profile-image-modal" id="profileImageModal">

    <span class="close-image" onclick="closeProfileImage()">&times;</span>

    <?php if($profile_image != "") { ?>
        <img src="<?php echo $profile_image; ?>"
             class="large-profile-image"
             alt="Profile Image">
    <?php } else { ?>
        <div class="large-profile-image d-flex justify-content-center align-items-center bg-light text-dark fw-bold">
            <?php echo h($student_initial ?: 'S'); ?>
        </div>
    <?php } ?>

    <div class="image-actions mt-4">
        <a href="profile.php" class="btn btn-primary px-4">
            View Profile
        </a>
    </div>

</div>

</body>
</html>