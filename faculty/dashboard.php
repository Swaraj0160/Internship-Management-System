<?php

session_start();

include("../config.php");



if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'faculty') {

    header("Location: ../login.php");

    exit();

}



function h($value): string

{

    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

}



function shortText(?string $text, int $limit = 150): string

{

    $text = trim((string)$text);

    if ($text === '') {

        return '';

    }



    if (function_exists('mb_strimwidth')) {

        return mb_strimwidth($text, 0, $limit, '...');

    }



    return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;

}



function runQuery(mysqli $conn, string $sql): ?mysqli_result

{

    $result = mysqli_query($conn, $sql);

    return ($result instanceof mysqli_result) ? $result : null;

}



function fetchOneAssoc(mysqli $conn, string $sql): array

{

    $result = runQuery($conn, $sql);

    if (!$result) {

        return [];

    }

    $row = mysqli_fetch_assoc($result);

    mysqli_free_result($result);

    return $row ?: [];

}



$faculty_id = (int)$_SESSION['user_id'];



$faculty = fetchOneAssoc(

    $conn,

    "SELECT *

     FROM users

     WHERE id='$faculty_id'

     LIMIT 1"

);



if (!$faculty) {

    header("Location: ../login.php");

    exit();

}



$faculty_division = trim((string)($faculty['faculty_division'] ?? ''));

$division_esc = mysqli_real_escape_string($conn, $faculty_division);



$faculty_name = trim((string)($faculty['full_name'] ?? ''));

$faculty_name_clean = preg_replace('/^(Prof\.?|Dr\.?|Professor)\s*/i', '', $faculty_name);

$faculty_initial = strtoupper(substr($faculty_name_clean ?: $faculty_name ?: 'F', 0, 1));



$latest_from = "

    FROM users u

    LEFT JOIN internships i

        ON i.id = (

            SELECT id

            FROM internships

            WHERE student_id = u.id

            ORDER BY created_at DESC, id DESC

            LIMIT 1

        )

";



$division_where = "

    WHERE u.role='student'

      AND u.division='$division_esc'

";



/* TOTAL STUDENTS */

$total_students = 0;

$student_query = runQuery(

    $conn,

    "SELECT COUNT(*) AS total

     FROM users

     WHERE role='student'

       AND division='$division_esc'"

);

if ($student_query) {

    $total_students = (int)(mysqli_fetch_assoc($student_query)['total'] ?? 0);

    mysqli_free_result($student_query);

}



/* LATEST INTERNSHIP FOR EACH STUDENT IN THIS DIVISION */

$current_query = runQuery(

    $conn,

    "SELECT

        u.id,

        u.roll_no,

        u.full_name,

        u.division,

        i.status,

        i.internship_type,

        i.company_name,

        i.mentor_name,

        i.company_contact

     $latest_from

     $division_where

     ORDER BY CAST(u.roll_no AS UNSIGNED), u.full_name ASC"

);



$total_applications = 0;

$approved_count = 0;

$pending_count = 0;

$rejected_count = 0;

$not_submitted_count = 0;



if ($current_query) {

    while ($row = mysqli_fetch_assoc($current_query)) {

        if (!empty($row['status'])) {

            $total_applications++;

        } else {

            $not_submitted_count++;

        }



        if (($row['status'] ?? '') === 'Approved') {

            $approved_count++;

        } elseif (($row['status'] ?? '') === 'Pending') {

            $pending_count++;

        } elseif (($row['status'] ?? '') === 'Rejected') {

            $rejected_count++;

        }

    }

    mysqli_free_result($current_query);

}



/* TOTAL COMPANIES */

$total_companies = 0;

$company_count_query = runQuery(

    $conn,

    "SELECT COUNT(DISTINCT i.company_name) AS total

     $latest_from

     $division_where

       AND i.company_name IS NOT NULL

       AND i.company_name <> ''"

);

if ($company_count_query) {

    $total_companies = (int)(mysqli_fetch_assoc($company_count_query)['total'] ?? 0);

    mysqli_free_result($company_count_query);

}



/* APPROVAL RATE */

$approval_rate = 0.0;

if ($total_students > 0) {

    $approval_rate = round(($approved_count / $total_students) * 100, 2);

}



/* ADMIN NOTIFICATIONS FOR FACULTY */

$admin_notifications = [];

$notif_query = runQuery(

    $conn,

    "SELECT *

     FROM admin_notifications

     WHERE target_role='faculty'

        OR target_role='all'

     ORDER BY id DESC"

);

if ($notif_query) {

    while ($row = mysqli_fetch_assoc($notif_query)) {

        $admin_notifications[] = $row;

    }

    mysqli_free_result($notif_query);

}



/* UNREAD NOTIFICATIONS COUNT */

$notification_count = 0;

$unread_notifications = runQuery(

    $conn,

    "SELECT id

     FROM admin_notifications

     WHERE (

        target_role='faculty'

        OR target_role='all'

     )

     AND id NOT IN (

        SELECT notification_id

        FROM faculty_notification_reads

        WHERE faculty_id='$faculty_id'

     )"

);

if ($unread_notifications) {

    $notification_count = mysqli_num_rows($unread_notifications);

    mysqli_free_result($unread_notifications);

}



/* ANALYTICS SEARCH */

$search_results = [];

$search_total = 0;



if (isset($_GET['analytics_search'])) {

    $status_filter = trim($_GET['status'] ?? '');

    $type_filter = trim($_GET['internship_type'] ?? '');

    $company_filter = trim($_GET['company_name'] ?? '');



    $where = "u.role='student' AND u.division='$division_esc'";



    if ($status_filter !== '') {

        $where .= " AND i.status='" . mysqli_real_escape_string($conn, $status_filter) . "'";

    }



    if ($type_filter !== '') {

        $where .= " AND i.internship_type='" . mysqli_real_escape_string($conn, $type_filter) . "'";

    }



    if ($company_filter !== '') {

        $where .= " AND i.company_name='" . mysqli_real_escape_string($conn, $company_filter) . "'";

    }



    $analytics_query = runQuery(

        $conn,

        "SELECT

            u.roll_no,

            u.full_name,

            u.division,

            i.status,

            i.internship_type,

            i.company_name,

            i.mentor_name

         FROM users u

         LEFT JOIN internships i

            ON i.id = (

                SELECT MAX(id)

                FROM internships

                WHERE student_id = u.id

            )

         WHERE $where

         ORDER BY CAST(u.roll_no AS UNSIGNED), u.full_name ASC"

    );



    if ($analytics_query) {

        $search_total = mysqli_num_rows($analytics_query);

        while ($row = mysqli_fetch_assoc($analytics_query)) {

            $search_results[] = $row;

        }

        mysqli_free_result($analytics_query);

    }

}



/* TOP COMPANIES */

$company_query = runQuery(

    $conn,

    "SELECT

        i.company_name,

        COUNT(*) AS total

     FROM internships i

     INNER JOIN users u ON i.student_id = u.id

     WHERE u.division='" . mysqli_real_escape_string($conn, $faculty_division) . "'

     AND i.company_name IS NOT NULL

     AND i.company_name <> ''

     GROUP BY i.company_name

     ORDER BY total DESC

     LIMIT 10"

);



$company_labels = [];

$company_values = [];



if ($company_query) {

    while ($row = mysqli_fetch_assoc($company_query)) {

        $company_labels[] = (strlen($row['company_name']) > 28)

            ? substr($row['company_name'], 0, 28) . '...'

            : $row['company_name'];

        $company_values[] = (int)$row['total'];

    }

    mysqli_free_result($company_query);

}



$top_company = fetchOneAssoc(

    $conn,

    "SELECT

        i.company_name,

        COUNT(*) AS total

     FROM internships i

     INNER JOIN users u ON i.student_id = u.id

     WHERE u.division='" . mysqli_real_escape_string($conn, $faculty_division) . "'

     AND i.company_name IS NOT NULL

     AND i.company_name <> ''

     GROUP BY i.company_name

     ORDER BY total DESC

     LIMIT 1"

);



$top_company_name = $top_company['company_name'] ?? '-';

$top_company_students = (int)($top_company['total'] ?? 0);



/* INTERNSHIP TYPES */

$type_query = runQuery(

    $conn,

    "SELECT

        i.internship_type,

        COUNT(*) AS total

     FROM internships i

     INNER JOIN users u ON i.student_id = u.id

     WHERE u.division='" . mysqli_real_escape_string($conn, $faculty_division) . "'

     AND i.internship_type IS NOT NULL

     AND i.internship_type <> ''

     GROUP BY i.internship_type

     ORDER BY total DESC"

);



$type_labels = [];

$type_values = [];



if ($type_query) {

    while ($row = mysqli_fetch_assoc($type_query)) {

        $type_labels[] = $row['internship_type'] ?: 'Not Set';

        $type_values[] = (int)$row['total'];

    }

    mysqli_free_result($type_query);

}



if (empty($type_labels)) {

    $type_labels = ['Not Set'];

    $type_values = [0];

}



/* STATUS ARRAY FOR CLEAN PROFESSIONAL UI */

$status_labels = ['Approved', 'Pending', 'Rejected', 'No Internship'];

$status_values = [$approved_count, $pending_count, $rejected_count, $not_submitted_count];



/* DROPDOWNS */

$company_dropdown = [];

$company_dropdown_q = runQuery(

    $conn,

    "SELECT DISTINCT i.company_name

     FROM internships i

     INNER JOIN users u ON i.student_id = u.id

     WHERE u.division='" . mysqli_real_escape_string($conn, $faculty_division) . "'

     AND i.company_name IS NOT NULL

     AND i.company_name <> ''

     ORDER BY i.company_name"

);

if ($company_dropdown_q) {

    while ($row = mysqli_fetch_assoc($company_dropdown_q)) {

        $company_dropdown[] = $row['company_name'];

    }

    mysqli_free_result($company_dropdown_q);

}



$type_dropdown = [];

$type_dropdown_q = runQuery(

    $conn,

    "SELECT DISTINCT i.internship_type

     FROM internships i

     INNER JOIN users u ON i.student_id = u.id

     WHERE u.division='" . mysqli_real_escape_string($conn, $faculty_division) . "'

     AND i.internship_type IS NOT NULL

     AND i.internship_type <> ''

     ORDER BY i.internship_type"

);

if ($type_dropdown_q) {

    while ($row = mysqli_fetch_assoc($type_dropdown_q)) {

        $type_dropdown[] = $row['internship_type'];

    }

    mysqli_free_result($type_dropdown_q);

}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Faculty Dashboard</title>



    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <link rel="preconnect" href="https://fonts.googleapis.com">

    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>



    <style>
:root{
    --bg0:#0d0221;
    --bg1:#17053d;
    --bg2:#1e084f;
    --bg3:#2a0b73;
    --surface:rgba(255,255,255,.08);
    --surface-2:rgba(255,255,255,.12);
    --text:#f4f7ff;
    --muted:rgba(232,236,255,.72);
    --border:rgba(255,255,255,.13);
    --purple:#7B61FF;
    --blue:#3B82F6;
    --cyan:#00E5FF;
    --pink:#FF4FD8;
    --green:#22C55E;
    --orange:#F59E0B;
    --shadow:0 24px 70px rgba(4, 2, 18, .42);
    --shadow-soft:0 14px 38px rgba(4, 2, 18, .28);
  --sidebar-width:clamp(225px,18vw,288px);
    --card-radius:28px;
}
*{box-sizing:border-box;}
html, body{width:100%;max-width:100%;overflow-x:hidden;scroll-behavior:smooth;}
body{
    margin:0;
    font-family:'Inter',system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
    color:var(--text);
    background:
        radial-gradient(circle at 20% 20%, rgba(123,97,255,.22), transparent 28%),
        radial-gradient(circle at 80% 15%, rgba(0,229,255,.14), transparent 24%),
        radial-gradient(circle at 70% 85%, rgba(255,79,216,.12), transparent 22%),
        linear-gradient(135deg, var(--bg0) 0%, var(--bg1) 35%, var(--bg2) 70%, var(--bg3) 100%);
    position:relative;
}
body::before{
    content:"";
    position:fixed;
    inset:0;
    pointer-events:none;
    background-image:
        linear-gradient(rgba(255,255,255,.03) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,.03) 1px, transparent 1px);
    background-size:48px 48px;
    opacity:.10;
    mask-image:linear-gradient(to bottom, transparent, black 10%, black 90%, transparent);
    z-index:0;
}
img, canvas{
    max-width:100%;
    
}
.top-progress{
    position:fixed;
    top:0;left:0;
    height:4px;
    width:0%;
    z-index:9999;
    background:linear-gradient(90deg, var(--purple), var(--cyan), var(--pink));box-shadow:0 0 18px rgba(0,229,255,.45);
    
}
.fx-bg{
    position:fixed;
    inset:0;pointer-events:none;
    z-index:0;
    overflow:hidden;
    
}
.fx-orb{
    position:absolute;
    border-radius:50%;
    filter:blur(26px);
    opacity:.32;
    mix-blend-mode:screen;
    animation:orbFloat 16s ease-in-out infinite;
    
}
.fx-orb.orb1{
    width:360px;
    height:360px;
    left:-120px;
    top:80px;
    background:radial-gradient(circle at 30% 30%, rgba(123,97,255,.70), rgba(123,97,255,0) 68%);
    
}
.fx-orb.orb2{
    width:280px;
    height:280px;
    right:100px;
    top:140px;
    background:radial-gradient(circle at 30% 30%, rgba(0,229,255,.34), rgba(0,229,255,0) 68%);
    animation-delay:-6s;
    
}
.fx-orb.orb3{
    width:240px;
    height:240px;
    right:40px;
    bottom:90px;
    background:radial-gradient(circle at 30% 30%, rgba(255,79,216,.24), rgba(255,79,216,0) 68%);
    animation-delay:-11s;
    
}
.fx-particles{
    position:absolute;
    inset:0;
    
}
.particle{
    position:absolute;
    border-radius:50%;
    background:radial-gradient(circle, rgba(255,255,255,.95), rgba(255,255,255,.06) 55%, rgba(255,255,255,0) 72%);
    box-shadow:0 0 28px rgba(255,255,255,.25);
    opacity:.24;animation:particleDrift var(--dur, 18s) linear infinite;
    transform:translate3d(0,0,0);
    
}
.app{
    min-height:100vh;
    display:flex;width:100%;overflow-x:hidden;
    position:relative;
    z-index:1;
    
}
.sidebar{
    width:var(--sidebar-width);
    height:100vh;
    position:fixed;
    inset:0 auto 0 0;
    background:linear-gradient(180deg, rgba(123,97,255,.24), rgba(123,97,255,0)),linear-gradient(180deg, #3f0f68 0%, #6d1a88 48%, #4a1474 100%);
    color:#fff;
    box-shadow:10px 0 30px rgba(4, 2, 18, .36);
    z-index:20;
    display:flex;flex-direction:column;
    overflow:hidden;
    border-right:1px solid rgba(255,255,255,.06);
    
}
.sidebar::after{content:"";
position:absolute;
inset:0;background:linear-gradient(180deg, rgba(255,255,255,.05), transparent 35%, rgba(255,255,255,.03));
pointer-events:none;
    
}
.brand{
    padding:16px 16px 12px;
    text-align:center;
    border-bottom:1px solid rgba(255,255,255,.08);
    
}
.brand img{ 
    width:clamp(85px,8vw,120px);
    height:auto;object-fit:contain;
    display:block;margin:0 auto 10px;
    
}
.brand .title{
    font-size:clamp(.88rem,1vw,1.08rem);
    font-weight:800;line-height:1.08;
    letter-spacing:-.03em;
    
}
.brand .subtitle{
    margin-top:7px;
    font-size:.70rem;
    letter-spacing:.34em;
    color:rgba(255,255,255,.58);
    
}
.profile-mini{
    display:flex;
gap:clamp(8px,.8vw,12px);
align-items:center;   
padding:clamp(10px,.8vw,12px)
clamp(12px,1vw,16px);
border-bottom:1px solid rgba(255,255,255,.08);
min-width:0;
    
}
.profile-mini .avatar{
    width:44px;height:44px;
    border-radius:50%;
    display:grid;place-items:center;
    background:linear-gradient(135deg, #7c5cff, #4f7cff);
    font-weight:800;
    color:#fff;
    box-shadow:0 12px 24px rgba(0,0,0,.24), 0 0 0 6px rgba(255,255,255,.05);
    flex:0 0 auto;animation:floatIcon 3.8s ease-in-out infinite;
    
}
.profile-mini .meta{
    min-width:0;
    
}
.profile-mini .meta .name{
    font-size:clamp(.82rem,.9vw,.93rem);
    font-weight:700;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    
}
.profile-mini .meta .small{
    color:rgba(255,255,255,.65);
    font-size:.80rem;
    
}
.nav{
    padding:10px 12px 12px;
    gap:3px;
    min-height:0;
    flex:1 1 auto;
    display:flex;
    flex-direction:column;

    overflow-y:auto;
    overflow-x:hidden;

    scrollbar-width:thin;
}
.nav-label{
    padding:7px 10px 4px;
    color:rgba(255,255,255,.42);
    font-size:.70rem;
    letter-spacing:.16em;
    text-transform:uppercase;
    font-weight:700;
    
}
.nav a{
    display:flex;
    align-items:center;
    gap:clamp(8px,.8vw,11px);

    color:rgba(255,255,255,.94);

    padding:clamp(8px,.8vw,11px)
            clamp(10px,.8vw,14px);

    border-radius:15px;

    font-weight:600;

    margin-bottom:2px;

    text-decoration:none;

    min-width:0;

    font-size:clamp(.82rem,.9vw,.92rem);

    position:relative;

    overflow:hidden;
}
.nav a::before{
    content:"";
    position:absolute;
    inset:0;background:linear-gradient(90deg, rgba(255,255,255,.18), transparent 40%, rgba(255,255,255,.10));
    opacity:0;transform:translateX(-35%);transition:opacity .25s ease, transform .35s ease;
    
}
.nav a:hover::before,.nav a.active::before{
    opacity:1;transform:translateX(0);
    
}
.nav a i{width:clamp(16px,1vw,20px);
    min-width:clamp(16px,1vw,20px);
text-align:center;
font-size:clamp(.85rem,.9vw,.98rem);
flex:0 0 auto;transition:transform .25s ease;
    
}
.nav a:hover i,.nav a.active i{transform:rotate(8deg) scale(1.05);}
.nav a:hover{background:linear-gradient(90deg, #cf1e71, #f26d21);color:#fff;transform:translateX(4px) scale(1.02);box-shadow:0 12px 24px rgba(0,0,0,.20);}
.nav a.active{background:linear-gradient(90deg, #cf1e71, #f26d21);box-shadow:0 18px 30px rgba(91,61,245,.24);}
.nav .account-bottom{margin-top:auto;padding-top:8px;border-top:1px solid rgba(255,255,255,.08);}
.main{margin-left:var(--sidebar-width);width:calc(100% - var(--sidebar-width));min-width:0;overflow-x:hidden;position:relative;z-index:1;}
.topbar{position:sticky;top:0;z-index:12;background:rgba(10,6,28,.55);backdrop-filter:blur(20px);border-bottom:1px solid rgba(255,255,255,.08);box-shadow:0 8px 30px rgba(4,2,18,.18);}
.topbar-inner{padding:22px 30px;display:flex;align-items:center;justify-content:space-between;gap:20px;max-width:100%;min-width:0;}
.hero-title{display:flex;flex-direction:column;gap:6px;min-width:0;}
.hero-title .eyebrow{color:rgba(232,236,255,.72);font-weight:600;font-size:1rem;}
.hero-title h1{margin:0;font-size:2rem;line-height:1.05;font-weight:800;letter-spacing:-.04em;color:#f7f5ff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-shadow:0 0 24px rgba(123,97,255,.14);}
.hero-title .sub{color:rgba(232,236,255,.70);font-size:.98rem;display:flex;gap:12px;flex-wrap:wrap;}
.topbar-actions{display:flex;align-items:center;gap:18px;flex-wrap:wrap;justify-content:flex-end;min-width:0;}
.action-btn{border:1px solid rgba(255,255,255,.16);background:rgba(255,255,255,.06);color:#fff;font-weight:700;padding:10px 16px;border-radius:16px;transition:transform .25s ease, box-shadow .25s ease, background .25s ease, border-color .25s ease;display:inline-flex;align-items:center;gap:10px;white-space:nowrap;backdrop-filter:blur(12px);}
.action-btn:hover{background:rgba(255,255,255,.12);transform:translateY(-2px);box-shadow:0 16px 28px rgba(0,0,0,.20);border-color:rgba(255,255,255,.26);color:#fff;}
.user-chip{display:flex;align-items:center;gap:12px;padding:8px 10px 8px 8px;border-radius:999px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.13);box-shadow:var(--shadow-soft);max-width:100%;backdrop-filter:blur(12px);}
.user-chip .avatar{width:54px;height:54px;border-radius:50%;background:linear-gradient(135deg,#d8e8ff,#b6c8ff);color:#3254c7;display:grid;place-items:center;font-weight:800;font-size:1.1rem;flex:0 0 auto;animation:floatIcon 3.8s ease-in-out infinite;}
.user-chip .info{line-height:1.2;padding-right:10px;min-width:0;}
.user-chip .info .name{font-weight:800;font-size:.98rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#f7f8ff;}
.user-chip .info .role{color:rgba(232,236,255,.72);font-size:.84rem;}
.page{padding:22px 28px 36px;max-width:100%;overflow-x:hidden;position:relative;}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:18px;margin-bottom:22px;width:100%;}
.stat-card{border-radius:28px;padding:22px;min-height:132px;display:flex;flex-direction:column;justify-content:space-between;border:1px solid rgba(255,255,255,.14);box-shadow:var(--shadow);overflow:hidden;position:relative;min-width:0;transform:translateZ(0);transition:transform .45s cubic-bezier(.2,.9,.2,1), box-shadow .45s ease, border-color .45s ease, filter .45s ease;backdrop-filter:blur(14px);background-size:200% 200%;animation:gradientFlow 10s ease infinite, cardFloat 7s ease-in-out infinite;will-change:transform;}
.stat-card::before{content:'';position:absolute;inset:-2px;background:linear-gradient(135deg, rgba(255,255,255,.55), rgba(255,255,255,0) 32%, rgba(255,255,255,.18));opacity:.58;pointer-events:none;mix-blend-mode:screen;}
.stat-card::after{content:'';position:absolute;inset:auto -26px -28px auto;width:140px;height:140px;border-radius:50%;background:rgba(255,255,255,.18);opacity:.55;pointer-events:none;filter:blur(2px);}
.stat-card:hover{transform:translateY(-10px) scale(1.03);box-shadow:0 26px 60px rgba(4,2,18,.28);border-color:rgba(255,255,255,.34);filter:saturate(1.08);}
.stat-card .top{display:flex;align-items:center;gap:14px;min-width:0;position:relative;z-index:1;}
.stat-card .icon{width:58px;height:58px;border-radius:18px;display:grid;place-items:center;background:rgba(255,255,255,.20);color:#fff;font-size:1.2rem;flex:0 0 auto;box-shadow:0 12px 28px rgba(0,0,0,.14);animation:floatIcon 3.4s ease-in-out infinite;transition:transform .45s ease, box-shadow .45s ease, filter .45s ease;}
.stat-card:hover .icon{transform:rotate(-6deg) scale(1.10);box-shadow:0 18px 36px rgba(0,0,0,.22);filter:drop-shadow(0 0 10px rgba(255,255,255,.22));}
.stat-card .label{color:#fff;font-weight:700;font-size:1.02rem;line-height:1.1;}
.stat-card .value{color:#fff;font-weight:800;font-size:2.05rem;line-height:1;margin-top:6px;letter-spacing:-.04em;}
.stat-card .note{color:rgba(255,255,255,.86);font-size:.92rem;margin-top:4px;line-height:1.35;}
.stat-purple{background:linear-gradient(135deg, rgba(123,97,255,.35), rgba(123,97,255,.16) 45%, rgba(255,255,255,.08));}
.stat-green{background:linear-gradient(135deg, rgba(34,197,94,.30), rgba(34,197,94,.12) 45%, rgba(255,255,255,.08));}
.stat-orange{background:linear-gradient(135deg, rgba(245,158,11,.28), rgba(245,158,11,.12) 45%, rgba(255,255,255,.08));}
.stat-blue{background:linear-gradient(135deg, rgba(59,130,246,.32), rgba(59,130,246,.12) 45%, rgba(255,255,255,.08));}
.stat-red{background:linear-gradient(135deg, rgba(239,68,68,.28), rgba(239,68,68,.12) 45%, rgba(255,255,255,.08));}
.stat-gray{background:linear-gradient(135deg, rgba(148,163,184,.26), rgba(148,163,184,.10) 45%, rgba(255,255,255,.08));}
.stat-purple .icon{background:#7B61FF;}
.stat-green .icon{background:#22C55E;}
.stat-orange .icon{background:#F59E0B;}
.stat-blue .icon{background:#3B82F6;}
.stat-red .icon{background:#ef4444;}
.stat-gray .icon{background:#64748b;}
.stat-purple .label,.stat-purple .value,.stat-purple .note{color:#f6f3ff;}
.stat-green .label,.stat-green .value,.stat-green .note{color:#f1fff5;}
.stat-orange .label,.stat-orange .value,.stat-orange .note{color:#fff9ee;}
.stat-blue .label,.stat-blue .value,.stat-blue .note{color:#f4f9ff;}
.stat-red .label,.stat-red .value,.stat-red .note{color:#fff5f5;}
.stat-gray .label,.stat-gray .value,.stat-gray .note{color:#f3f6fb;}
.stack{display:grid;gap:22px;min-width:0;}
.panel{padding:22px;border-radius:28px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.10);box-shadow:var(--shadow);min-width:0;overflow:hidden;position:relative;backdrop-filter:blur(18px);transition:transform .45s ease, box-shadow .45s ease;}
.panel::before{content:"";position:absolute;inset:0;background:linear-gradient(180deg, rgba(255,255,255,.06), transparent 25%, transparent 85%, rgba(255,255,255,.04));pointer-events:none;}
.panel:hover{box-shadow:0 28px 60px rgba(0,0,0,.22);transform:translateY(-4px);}
.panel-grid-2{display:grid;grid-template-columns:minmax(0, 1fr) minmax(0, 1fr);gap:22px;align-items:stretch;}
.section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:16px;min-width:0;}
.section-head h2{font-size:1.25rem;font-weight:800;margin:0;letter-spacing:-.03em;color:#f7f8ff;}
.section-head p{margin:6px 0 0;color:var(--muted);font-size:.94rem;}
.filter-row{display:grid;grid-template-columns:repeat(3, minmax(0, 1fr));gap:14px;width:100%;}
.filter-row > div{min-width:0;}
.filter-row label{font-size:.85rem;font-weight:700;color:#eef3ff;margin-bottom:6px;}
.filter-row .form-control{height:48px;border-radius:14px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.08);color:#fff;box-shadow:none;width:100%;}
.filter-row .form-control::placeholder{color:rgba(255,255,255,.6);}
.filter-row .form-control:focus{border-color:rgba(123,97,255,.8);box-shadow:0 0 0 .2rem rgba(123,97,255,.18);background:rgba(255,255,255,.12);color:#fff;}
.filter-row option{color:#111;}
.status-badge{padding:8px 12px;border-radius:999px;font-size:.78rem;font-weight:800;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;}
.b-success{background:rgba(34,197,94,.16);color:#71f0a2;border:1px solid rgba(34,197,94,.25);}
.b-warning{background:rgba(245,158,11,.16);color:#ffd48a;border:1px solid rgba(245,158,11,.25);}
.b-danger{background:rgba(239,68,68,.16);color:#ff9aa5;border:1px solid rgba(239,68,68,.25);}
.b-gray{background:rgba(148,163,184,.16);color:#d8e2f2;border:1px solid rgba(148,163,184,.25);}
.b-info{background:rgba(59,130,246,.16);color:#a8d0ff;border:1px solid rgba(59,130,246,.25);}
.b-purple{background:rgba(123,97,255,.16);color:#d9d2ff;border:1px solid rgba(123,97,255,.25);}
.notifs-dropdown{width:min(520px, 92vw);max-height:520px;overflow:auto;border-radius:20px;border:1px solid rgba(255,255,255,.13);padding:0;background:rgba(15,8,34,.92);color:#fff;box-shadow:0 30px 80px rgba(0,0,0,.35);}
.notif-header{padding:16px 18px;border-bottom:1px solid rgba(255,255,255,.10);background:linear-gradient(135deg, rgba(255,255,255,.06), rgba(255,255,255,.02));}
.notif-body{padding:12px;}
.notif-item{padding:14px;border:1px solid rgba(255,255,255,.09);border-radius:18px;margin-bottom:10px;background:rgba(255,255,255,.05);}
.notif-item:last-child{margin-bottom:0;}
.notif-item .title{font-weight:800;margin-bottom:4px;}
.notif-item .msg{color:rgba(232,236,255,.74);font-size:.92rem;line-height:1.45;}
.notif-item .time{margin-top:8px;color:rgba(232,236,255,.55);font-size:.8rem;}
.empty-state{padding:18px;background:rgba(255,255,255,.06);border:1px dashed rgba(255,255,255,.16);border-radius:18px;color:var(--muted);text-align:center;}
.table-shell{width:100%;max-width:100%;overflow:hidden;border-radius:20px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);backdrop-filter:blur(14px);}
table{width:100%;max-width:100%;border-collapse:separate;border-spacing:0;table-layout:fixed;margin:0;color:var(--text);}
.table thead th{background:rgba(255,255,255,.07);border-bottom:1px solid rgba(255,255,255,.10);color:#ecf2ff;font-size:.82rem;text-transform:uppercase;letter-spacing:.07em;white-space:nowrap;position:sticky;top:0;z-index:1;}
.table td,.table th{padding:14px 12px;vertical-align:top;overflow-wrap:anywhere;word-break:break-word;white-space:normal;line-height:1.45;}
.table tbody tr{color:#edf2ff;transition:transform .25s ease, background .25s ease, box-shadow .25s ease;}
.table tbody tr:hover{background:rgba(123,97,255,.10);transform:translateY(-1px);}
.results-title{font-size:1.05rem;font-weight:700;margin-bottom:14px;color:#edf3ff;}
.insights-grid{display:grid;grid-template-columns:repeat(4, minmax(0, 1fr));gap:14px;}
.info-card{padding:18px;border-radius:22px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.10);box-shadow:var(--shadow-soft);min-width:0;transition:transform .35s ease, box-shadow .35s ease, border-color .35s ease;backdrop-filter:blur(16px);}
.info-card:hover{transform:translateY(-4px);box-shadow:0 18px 34px rgba(0,0,0,.22);border-color:rgba(123,97,255,.35);}
.info-card .label{color:rgba(232,236,255,.62);font-size:.83rem;text-transform:uppercase;letter-spacing:.08em;font-weight:700;margin-bottom:6px;}
.info-card .value{font-weight:700;font-size:1rem;margin:0 0 10px;overflow-wrap:anywhere;color:#f6f8ff;}
.info-card .meta{color:rgba(232,236,255,.72);font-size:.92rem;line-height:1.5;}
.top-companies-box{height:380px;}
.status-layout{display:grid;grid-template-columns:minmax(0, 1fr) 240px;gap:22px;align-items:center;}
.status-visual{display:flex;align-items:center;gap:30px;min-width:0;}
.status-chart-wrap{width:260px;height:220px;flex:0 0 auto;}
.status-chart-wrap canvas{width:100% !important;height:100% !important;}
.status-legend-list{display:flex;flex-direction:column;gap:12px;min-width:0;justify-content:center;}
.status-legend-item{display:flex;align-items:center;gap:10px;font-size:0.92rem;font-weight:600;color:#edf3ff;white-space:nowrap;}
.status-dot{width:14px;height:14px;border-radius:3px;flex:0 0 auto;box-shadow:0 0 10px rgba(255,255,255,.15);}
.status-dot.approved{background:#22c55e;}
.status-dot.pending{background:#f59e0b;}
.status-dot.rejected{background:#ef4444;}
.status-dot.nointernship{background:#9ca3af;}
.chart-box{width:100%;height:340px;min-width:0;}
.chart-box.small{height:300px;}
.chart-box canvas{width:100% !important;height:100% !important;}
.reveal{opacity:0;transform:translateY(24px) scale(.975);filter:blur(5px);transition:opacity 1.05s cubic-bezier(.16,1,.3,1), transform 1.05s cubic-bezier(.16,1,.3,1), filter 1.05s ease;}
.reveal.visible{opacity:1;transform:translateY(0) scale(1);filter:blur(0);}
.chart-ready{opacity:0;transform:translateY(16px) scale(.975);transition:opacity .95s cubic-bezier(.16,1,.3,1), transform .95s cubic-bezier(.16,1,.3,1), filter .95s ease;filter:blur(3px);}
.chart-ready.visible{opacity:1;transform:translateY(0) scale(1);filter:blur(0);}
.chart-focus{animation:chartPulse 2.2s ease-in-out infinite;}
.stat-number{display:inline-block;min-width:1ch;letter-spacing:-.04em;}
.loading-overlay{position:fixed;inset:0;z-index:10000;display:flex;align-items:center;justify-content:center;background:radial-gradient(circle at 20% 20%, rgba(123,97,255,.22), transparent 30%), linear-gradient(135deg, #0a021a, #11042b 45%, #17053d);transition:opacity .45s ease, visibility .45s ease;}
.loading-overlay.hidden{opacity:0;visibility:hidden;}
.loading-card{width:min(760px, 92vw);border-radius:30px;padding:24px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);backdrop-filter:blur(18px);box-shadow:0 28px 70px rgba(0,0,0,.35);}
.loading-grid{display:grid;grid-template-columns:repeat(4, 1fr);gap:14px;}
.loading-skel{height:110px;border-radius:22px;background:linear-gradient(90deg, rgba(255,255,255,.06) 0%, rgba(255,255,255,.12) 50%, rgba(255,255,255,.06) 100%);background-size:200% 100%;animation:shimmer 1.35s linear infinite;border:1px solid rgba(255,255,255,.08);}
@keyframes chartPulse{0%,100%{filter:drop-shadow(0 0 0 rgba(123,97,255,0));}50%{filter:drop-shadow(0 0 18px rgba(123,97,255,.18));}}
@keyframes gradientFlow{0%{background-position:0% 50%;}50%{background-position:100% 50%;}100%{background-position:0% 50%;}}
@keyframes cardFloat{0%,100%{transform:translateY(0);}50%{transform:translateY(-4px);}}
@keyframes floatIcon{0%,100%{transform:translateY(0);}50%{transform:translateY(-4px);}}
@keyframes orbFloat{0%,100%{transform:translate3d(0,0,0) scale(1);}50%{transform:translate3d(0,-18px,0) scale(1.05);}}
@keyframes particleDrift{0%{transform:translate3d(0, 0, 0) scale(.9); opacity:.08;}15%{opacity:.28;}50%{transform:translate3d(120px, -140px, 0) scale(1.2); opacity:.25;}85%{opacity:.18;}100%{transform:translate3d(260px, -280px, 0) scale(.85); opacity:0;}}
@keyframes shimmer{0%{background-position:0% 50%;}100%{background-position:200% 50%;}}
@media (prefers-reduced-motion: reduce){*, *::before, *::after{animation-duration:.001ms !important;animation-iteration-count:1 !important;transition-duration:.001ms !important;scroll-behavior:auto !important;}}
@media(max-width:1200px){.sidebar{position:relative;width:100%;min-height:auto;height:auto;}.main{margin-left:0;width:100%;}.topbar-inner{flex-direction:column;align-items:flex-start;}.topbar-actions{width:100%;justify-content:space-between;}.panel-grid-2{grid-template-columns:1fr;}.insights-grid{grid-template-columns:repeat(2, minmax(0, 1fr));}.loading-grid{grid-template-columns:repeat(2, 1fr);}}
@media(max-width:768px){.topbar-inner,.page{padding-left:16px;padding-right:16px;}.stats-grid{grid-template-columns:1fr;}.filter-row{grid-template-columns:1fr;}.hero-title h1{font-size:1.45rem;}.user-chip{width:100%;justify-content:space-between;}.section-head{flex-direction:column;}.chart-box{height:280px;}.chart-box.small{height:250px;}.insights-grid{grid-template-columns:1fr;}.table td,.table th{padding:12px 10px;font-size:.92rem;}.status-layout{grid-template-columns:1fr;}.status-visual{flex-direction:column;align-items:flex-start;gap:18px;}.status-chart-wrap{width:100%;max-width:280px;}.status-legend-list{width:100%;}.loading-grid{grid-template-columns:1fr;}}

/* --- BRIGHTER CENTER / PROFESSIONAL MOTION OVERRIDES --- */
:root{
    --main-surface: rgba(255,255,255,.88);
    --main-surface-strong: rgba(255,255,255,.96);
    --main-text:#152033;
    --main-muted:#5c6782;
    --main-border:rgba(123,97,255,.12);
}

.main{
    color:#1f2430;
    background:
        radial-gradient(circle at top left, rgba(123,97,255,.14), transparent 26%),
        radial-gradient(circle at top right, rgba(0,229,255,.10), transparent 22%),
        radial-gradient(circle at bottom right, rgba(255,79,216,.06), transparent 18%),
        linear-gradient(180deg, #ffffff 0%, #faf9ff 38%, #f2f6ff 100%);
    position:relative;
}

.main::before{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    background:
        radial-gradient(circle at 20% 12%, rgba(123,97,255,.08), transparent 22%),
        radial-gradient(circle at 85% 15%, rgba(0,229,255,.06), transparent 18%),
        radial-gradient(circle at 70% 80%, rgba(255,79,216,.05), transparent 18%);
}

.page{
    background:transparent;
    position:relative;
    z-index:1;
}

.topbar{
    background:rgba(255,255,255,.88);
    backdrop-filter:blur(20px);
    border-bottom:1px solid rgba(123,97,255,.10);
    box-shadow:0 8px 30px rgba(73,45,150,.06);
}

.hero-title .eyebrow,
.hero-title .sub,
.section-head p,
.info-card .meta,
.empty-state,
.results-title{
    color:var(--main-muted);
}

.hero-title h1,
.section-head h2{
    color:#253063;
}

.action-btn{
    background:rgba(255,255,255,.86);
    color:#4338ca;
    border-color:rgba(123,97,255,.14);
    box-shadow:0 10px 25px rgba(123,97,255,.08);
}

.action-btn:hover{
    background:#fff;
    color:#3127d1;
    border-color:rgba(123,97,255,.28);
}

.user-chip{
    background:rgba(255,255,255,.92);
    border-color:rgba(123,97,255,.12);
}

.user-chip .info .name,
.user-chip .info .role{
    color:#1f2430;
}

.panel{
    color:#1f2430;
    background:var(--main-surface);
    border:1px solid var(--main-border);
    box-shadow:0 18px 50px rgba(80,52,160,.08);
    backdrop-filter:blur(18px);
}

.panel::before{
    background:linear-gradient(180deg, rgba(255,255,255,.48), transparent 25%, transparent 85%, rgba(255,255,255,.24));
}

.panel:hover{
    box-shadow:0 26px 60px rgba(80,52,160,.14);
    transform:translateY(-4px);
}

.section-head h2{
    color:#1b2440;
}

.stat-card{
    border:1px solid rgba(123,97,255,.13);
    background-size:200% 200%;
    box-shadow:0 20px 52px rgba(80,52,160,.10);
    backdrop-filter:blur(16px);
    transition:transform .42s cubic-bezier(.2,.9,.2,1), box-shadow .42s ease, border-color .42s ease, filter .42s ease;
    animation:gradientFlow 12s ease infinite;
    min-height:160px;
}

.stat-card:hover{
    transform:translateY(-10px) scale(1.03);
    box-shadow:0 28px 68px rgba(80,52,160,.18);
    border-color:rgba(123,97,255,.28);
    filter:saturate(1.05);
}

.stat-card::before{
    content:'';
    position:absolute;
    inset:-2px;
    background:linear-gradient(135deg, rgba(255,255,255,.62), rgba(255,255,255,0) 32%, rgba(255,255,255,.20));
    opacity:.55;
    pointer-events:none;
    mix-blend-mode:screen;
}

.stat-card::after{
    content:'';
    position:absolute;
    inset:auto -26px -28px auto;
    width:140px;
    height:140px;
    border-radius:50%;
    background:rgba(255,255,255,.22);
    opacity:.45;
    pointer-events:none;
    filter:blur(2px);
}

.stat-card .top{
    position:relative;
    z-index:1;
}

.stat-card .icon{
    box-shadow:0 12px 28px rgba(0,0,0,.12);
}

.stat-card .label,
.stat-card .value,
.stat-card .note{
    color:#172033;
}

.stat-card .label{
    font-weight:800;
    font-size:1rem;
}

.stat-card .value{
    font-weight:900;
    font-size:2.15rem;
    letter-spacing:-.05em;
}

.stat-card .note{
    font-size:.92rem;
    line-height:1.35;
    color:#45506b;
}

.stat-purple{
    background:linear-gradient(135deg, rgba(123,97,255,.20), rgba(255,255,255,.88) 52%, rgba(241,238,255,.96));
}

.stat-blue{
    background:linear-gradient(135deg, rgba(59,130,246,.18), rgba(255,255,255,.88) 52%, rgba(238,246,255,.98));
}

.stat-green{
    background:linear-gradient(135deg, rgba(34,197,94,.18), rgba(255,255,255,.88) 52%, rgba(238,255,244,.98));
}

.stat-orange{
    background:linear-gradient(135deg, rgba(245,158,11,.18), rgba(255,255,255,.88) 52%, rgba(255,249,236,.98));
}

.stat-red{
    background:linear-gradient(135deg, rgba(239,68,68,.18), rgba(255,255,255,.88) 52%, rgba(255,239,241,.98));
}

.stat-gray{
    background:linear-gradient(135deg, rgba(100,116,139,.16), rgba(255,255,255,.88) 52%, rgba(244,247,251,.98));
}

.stat-purple .label,.stat-purple .value,.stat-purple .note{color:#2f2358;}
.stat-blue .label,.stat-blue .value,.stat-blue .note{color:#183b7a;}
.stat-green .label,.stat-green .value,.stat-green .note{color:#1f4f30;}
.stat-orange .label,.stat-orange .value,.stat-orange .note{color:#7a4f00;}
.stat-red .label,.stat-red .value,.stat-red .note{color:#7f1d2d;}
.stat-gray .label,.stat-gray .value,.stat-gray .note{color:#334155;}

.stats-grid{
    display:grid;
    grid-template-columns:repeat(4, minmax(0, 1fr));
    gap:18px;
    margin-bottom:22px;
}

.stats-grid .stat-card:nth-child(1){transition-delay:0ms;}
.stats-grid .stat-card:nth-child(2){transition-delay:40ms;}
.stats-grid .stat-card:nth-child(3){transition-delay:80ms;}
.stats-grid .stat-card:nth-child(4){transition-delay:120ms;}
.stats-grid .stat-card:nth-child(5){transition-delay:160ms;}
.stats-grid .stat-card:nth-child(6){transition-delay:200ms;}
.stats-grid .stat-card:nth-child(7){transition-delay:240ms;}
.stats-grid .stat-card:nth-child(8){transition-delay:280ms;}

.panel-grid-2{
    display:grid;
    grid-template-columns:minmax(0, 1fr) minmax(0, 1fr);
    gap:22px;
    align-items:stretch;
}

.info-card,
.table-shell,
.notifs-dropdown{
    background:rgba(255,255,255,.86);
    border-color:rgba(123,97,255,.12);
}

.table-shell{
    color:#1f2430;
}

.info-card{
    transition:transform .32s ease, box-shadow .32s ease, border-color .32s ease;
}

.info-card:hover{
    transform:translateY(-4px);
    box-shadow:0 18px 34px rgba(80,52,160,.14);
    border-color:rgba(123,97,255,.28);
}

.info-card .label{
    color:#6b7280;
}

.info-card .value{
    color:#162033;
}

.info-card .meta{
    color:#5c6782;
}

.empty-state{
    background:linear-gradient(135deg, #f7f5ff, #eef5ff);
    color:#5c6782;
    border-color:rgba(123,97,255,.22);
}

.table thead th{
    background:rgba(247,245,255,.96);
    color:#2f365f;
    border-bottom:1px solid rgba(123,97,255,.12);
}

.table tbody tr{
    color:#1f2430;
}

.table tbody tr:hover{
    background:rgba(123,97,255,.05);
}

.results-title{
    color:#1f2430;
}

.status-legend-item{
    color:#1f2430;
}

.status-dot.approved{ box-shadow:0 0 10px rgba(34,197,94,.25); }
.status-dot.pending{ box-shadow:0 0 10px rgba(245,158,11,.25); }
.status-dot.rejected{ box-shadow:0 0 10px rgba(239,68,68,.25); }
.status-dot.nointernship{ box-shadow:0 0 10px rgba(156,163,175,.25); }

.chart-box{
    transition:transform .42s ease, filter .42s ease;
}

.chart-box:hover{
    transform:translateY(-3px) scale(1.01);
    filter:drop-shadow(0 16px 34px rgba(123,97,255,.12));
}

.chart-ready{
    opacity:0;
    transform:translateY(10px) scale(.985);
    transition:opacity .7s ease, transform .7s cubic-bezier(.2,.8,.2,1);
}

.chart-ready.visible{
    opacity:1;
    transform:translateY(0) scale(1);
}

.reveal{
    opacity:0;
    transform:translateY(18px) scale(.985);
    filter:blur(4px);
    transition:opacity .8s cubic-bezier(.2,.8,.2,1), transform .8s cubic-bezier(.2,.8,.2,1), filter .8s ease;
}

.reveal.visible{
    opacity:1;
    transform:translateY(0) scale(1);
    filter:blur(0);
}

.top-companies-box{ height:380px; }

.status-layout{
    display:grid;
    grid-template-columns:minmax(0, 1fr) 240px;
    gap:22px;
    align-items:center;
}

.status-visual{
    display:flex;
    align-items:center;
    gap:30px;
    min-width:0;
}

.status-chart-wrap{
    width:260px;
    height:220px;
    flex:0 0 auto;
}

.status-chart-wrap canvas{
    width:100% !important;
    height:100% !important;
}

.status-legend-list{
    display:flex;
    flex-direction:column;
    gap:12px;
    min-width:0;
    justify-content:center;
}

.status-legend-item{
    display:flex;
    align-items:center;
    gap:10px;
    font-size:0.92rem;
    font-weight:700;
    white-space:nowrap;
}

.stat-number{
    display:inline-block;
    min-width:1ch;
    letter-spacing:-.04em;
}

.notifs-dropdown{
    color:#1f2430;
    width:min(520px, 92vw);
    max-height:520px;
    overflow:auto;
    border-radius:20px;
    padding:0;
    box-shadow:0 30px 80px rgba(0,0,0,.18);
}

.notif-header{
    padding:16px 18px;
    border-bottom:1px solid rgba(123,97,255,.10);
    background:linear-gradient(135deg,#fcfbff,#f5f1ff);
}

.notif-body{
    padding:12px;
}

.notif-item{
    padding:14px;
    border:1px solid rgba(123,97,255,.10);
    border-radius:18px;
    margin-bottom:10px;
    background:#fff;
}

.notif-item:last-child{margin-bottom:0;}

.notif-item .title{
    font-weight:800;
    margin-bottom:4px;
    color:#162033;
}

.notif-item .msg{
    color:#5c6782;
    font-size:.92rem;
    line-height:1.45;
}

.notif-item .time{
    margin-top:8px;
    color:#7c849b;
    font-size:.8rem;
}

.status-badge{
    padding:8px 12px;
    border-radius:999px;
    font-size:.78rem;
    font-weight:800;
    display:inline-flex;
    align-items:center;
    gap:6px;
    white-space:nowrap;
}

.b-success{background:#edf9f2;color:#1f9d5a;border:1px solid rgba(34,197,94,.18);}
.b-warning{background:#fff4d6;color:#a86f00;border:1px solid rgba(245,158,11,.18);}
.b-danger{background:#feecef;color:#c63e52;border:1px solid rgba(239,68,68,.18);}
.b-gray{background:#edf0f7;color:#586178;border:1px solid rgba(100,116,139,.18);}
.b-info{background:#eef5ff;color:#2b7cff;border:1px solid rgba(59,130,246,.18);}
.b-purple{background:#f0ebff;color:#5b3df5;border:1px solid rgba(123,97,255,.18);}

.filter-row label{
    color:#253063;
}
.filter-row .form-control{
    background:#fff;
    color:#162033;
    border:1px solid rgba(123,97,255,.14);
}

.filter-row .form-control:focus{
    border-color:#7B61FF;
    box-shadow:0 0 0 .2rem rgba(123,97,255,.12);
}

@media (max-width: 1200px){
    .sidebar{
        position:relative;
        width:100%;
        min-height:auto;
        height:auto;
    }
    .main{
        margin-left:0;
        width:100%;
    }
    .topbar-inner{
        flex-direction:column;
        align-items:flex-start;
    }
    .topbar-actions{
        width:100%;
        justify-content:space-between;
    }
    .panel-grid-2{
        grid-template-columns:1fr;
    }
    .stats-grid{
        grid-template-columns:repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 768px){
    .topbar-inner,
    .page{
        padding-left:16px;
        padding-right:16px;
    }
    .stats-grid{
        grid-template-columns:1fr;
    }
    .filter-row{
        grid-template-columns:1fr;
    }
    .hero-title h1{
        font-size:1.45rem;
    }
    .user-chip{
        width:100%;
        justify-content:space-between;
    }
    .section-head{
        flex-direction:column;
    }
    .chart-box{
        height:280px;
    }
    .chart-box.small{
        height:250px;
    }
    .insights-grid{
        grid-template-columns:1fr;
    }
    .table td,
    .table th{
        padding:12px 10px;
        font-size:.92rem;
    }
    .status-layout{
        grid-template-columns:1fr;
    }
    .status-visual{
        flex-direction:column;
        align-items:flex-start;
        gap:18px;
    }
    .status-chart-wrap{
        width:100%;
        max-width:280px;
    }
    .status-legend-list{
        width:100%;
    }
}


/* ===== chart motion upgrades ===== */
.section-head h2{
    position:relative;
    display:inline-block;
    padding-bottom:12px;
}
.section-head h2::after{
    content:"";
    position:absolute;
    left:0;
    bottom:0;
    width:78px;
    height:3px;
    border-radius:999px;
    background:linear-gradient(90deg, var(--purple), var(--blue), var(--cyan), var(--pink));
    box-shadow:0 0 18px rgba(123,97,255,.24);
    transform-origin:left center;
    animation:underlinePulse 4.5s ease-in-out infinite;
}
.chart-ready{
    position:relative;
    isolation:isolate;
    overflow:hidden;
}
.chart-ready::before{
    content:"";
    position:absolute;
    inset:10px;
    border-radius:24px;
    pointer-events:none;
    background:
        radial-gradient(circle at 18% 18%, rgba(123,97,255,.20), transparent 34%),
        radial-gradient(circle at 82% 22%, rgba(0,229,255,.12), transparent 32%),
        radial-gradient(circle at 50% 85%, rgba(255,79,216,.08), transparent 28%);
    filter:blur(16px);
    opacity:.7;
    transform:translateZ(0);
    animation:chartGlowDrift 12s ease-in-out infinite;
}
.chart-ready::after{
    content:"";
    position:absolute;
    inset:-28%;
    border-radius:50%;
    pointer-events:none;
    background:conic-gradient(from 0deg, rgba(123,97,255,.0), rgba(123,97,255,.40), rgba(0,229,255,.24), rgba(255,79,216,.28), rgba(123,97,255,.0));
    opacity:0;
    filter:blur(24px);
    animation:ringSpin 14s linear infinite;
    mix-blend-mode:screen;
}
.chart-ready.chart-active::after{
    opacity:.55;
}
.chart-ready canvas{
    position:relative;
    z-index:1;
}
.chart-legend-pills{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    margin-top:14px;
    position:relative;
    z-index:2;
}
.chart-legend-pills.compact{
    gap:8px;
}
.legend-pill{
    display:inline-flex;
    align-items:center;
    gap:10px;
    padding:10px 12px;
    border-radius:999px;
    background:rgba(255,255,255,.86);
    border:1px solid rgba(123,97,255,.12);
    box-shadow:0 10px 24px rgba(80,52,160,.08);
    backdrop-filter:blur(12px);
    color:#1f2430;
    font-size:.86rem;
    font-weight:700;
    transform:translateY(10px);
    opacity:0;
    animation:legendPop .68s cubic-bezier(.2,.8,.2,1) forwards;
    animation-delay:var(--delay, 0s);
    transition:transform .28s ease, box-shadow .28s ease, border-color .28s ease;
}
.legend-pill:hover{
    transform:translateY(-2px) scale(1.02);
    box-shadow:0 16px 28px rgba(80,52,160,.14);
    border-color:rgba(123,97,255,.24);
}
.legend-pill .swatch{
    width:10px;
    height:10px;
    border-radius:999px;
    flex:0 0 auto;
    box-shadow:0 0 14px currentColor;
}
.legend-pill .text{
    display:flex;
    flex-direction:column;
    gap:1px;
    min-width:0;
}
.legend-pill .text .name{
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}
.legend-pill .text .count{
    font-size:.74rem;
    font-weight:800;
    color:#5e6881;
}
.legend-pill .count-chip{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:28px;
    height:24px;
    padding:0 8px;
    border-radius:999px;
    font-size:.76rem;
    font-weight:900;
    color:#1f2430;
    background:rgba(123,97,255,.10);
}
.chart-note{
    margin-top:12px;
    color:#5c6782;
    font-size:.92rem;
}
.chart-ready.chart-active{
    animation:chartPulse 2.4s ease-in-out infinite;
}
.status-legend-list{
    flex-wrap:wrap;
}
.status-legend-list .legend-pill{
    animation-delay:var(--delay, 0s);
}
.chart-focus:hover{
    transform:translateY(-3px) scale(1.01);
}
.chart-shell-caption{
    margin-top:10px;
    color:#5c6782;
    font-size:.9rem;
    line-height:1.45;
}
@keyframes chartGlowDrift{
    0%,100%{transform:translate3d(0,0,0) scale(1);}
    50%{transform:translate3d(0,-8px,0) scale(1.03);}
}
@keyframes ringSpin{
    0%{transform:rotate(0deg);}
    100%{transform:rotate(360deg);}
}
@keyframes legendPop{
    0%{opacity:0; transform:translateY(10px) scale(.96);}
    70%{opacity:1; transform:translateY(-2px) scale(1.01);}
    100%{opacity:1; transform:translateY(0) scale(1);}
}
@keyframes underlinePulse{
    0%,100%{transform:scaleX(.86); opacity:.75;}
    50%{transform:scaleX(1); opacity:1;}
}
@media (max-width: 768px){
    .legend-pill{
        width:100%;
        justify-content:flex-start;
    }
}

</style>

</head>

<body>

<div class="top-progress" id="topProgress"></div>

<div class="fx-bg" aria-hidden="true">

    <div class="fx-orb orb1"></div>

    <div class="fx-orb orb2"></div>

    <div class="fx-orb orb3"></div>

    <div class="fx-particles" id="fxParticles"></div>

</div>



<div class="app">

    <aside class="sidebar">

        <div class="brand">

            <img src="../assets/images/MIT_LOGO.jpg" alt="MIT-ADT University">

            <div class="title">MIT-ADT University</div>

            <div class="subtitle">PUNE, INDIA</div>

        </div>



        <div class="profile-mini">

            <div class="avatar"><?php echo h($faculty_initial); ?></div>

            <div class="meta">

                <div class="name"><?php echo h($faculty['full_name']); ?></div>

                <div class="small"><?php echo h($faculty_division); ?></div>

            </div>

        </div>



        <nav class="nav">

            <div class="nav-label">Workspace</div>

            <a href="dashboard.php" class="active"><i class="fa-solid fa-house"></i>Dashboard</a>

            <a href="manage_applications.php"><i class="fa-solid fa-circle-check"></i>Manage Applications</a>

            <a href="view_updates.php"><i class="fa-solid fa-calendar-days"></i>Weekly Reports</a>

            <a href="student_evaluations.php"><i class="fa-solid fa-star"></i>Student Evaluation</a>

            <a href="manage_deadlines.php"><i class="fa-solid fa-clock"></i>Manage Deadlines</a>

            <a href="manage_notifications.php"><i class="fa-solid fa-bell"></i>Notifications</a>

            <a href="view_reports.php"><i class="fa-solid fa-file-lines"></i>Final Reports</a>

            <a href="export_report.php"><i class="fa-solid fa-file-export"></i>Export Division Report</a>



            <div class="account-bottom">

                <div class="nav-label">Account</div>

                <a href="change_password.php"><i class="fa-solid fa-key"></i>Change Password</a>

                <a href="../logout.php"><i class="fa-solid fa-right-from-bracket"></i>Logout</a>

            </div>

        </nav>

    </aside>



    <main class="main">

        <div class="topbar reveal">

            <div class="topbar-inner">

                <div class="hero-title">

                    <div class="eyebrow">Welcome Back</div>

                    <h1><?php echo h($faculty['full_name']); ?></h1>

                    <div class="sub">

                        <span>Division: <?php echo h($faculty_division); ?></span>

                        <span>Faculty Dashboard</span>

                    </div>

                </div>



                <div class="topbar-actions">

                    <div class="dropdown">

                        <button class="action-btn position-relative" data-bs-toggle="dropdown" onclick="markNotificationsRead()">

                            <i class="fa-regular fa-bell"></i>

                            Notifications

                            <?php if ($notification_count > 0): ?>

                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">

                                    <?php echo (int)$notification_count; ?>

                                </span>

                            <?php endif; ?>

                        </button>



                        <div class="dropdown-menu dropdown-menu-end notifs-dropdown">

                            <div class="notif-header">

                                <strong>Notifications</strong>

                            </div>

                            <div class="notif-body">

                                <?php if (empty($admin_notifications)): ?>

                                    <div class="empty-state">No notifications available.</div>

                                <?php else: ?>

                                    <?php foreach ($admin_notifications as $notification): ?>

                                        <div class="notif-item">

                                            <div class="d-flex justify-content-between align-items-start gap-2">

                                                <div class="title"><?php echo h($notification['title'] ?? 'Notification'); ?></div>

                                                <span class="status-badge b-purple">Admin</span>

                                            </div>

                                            <div class="msg"><?php echo h(shortText($notification['message'] ?? '', 180)); ?></div>

                                            <?php if (!empty($notification['attachment'])): ?>

                                                <div class="mt-2">

                                                    <a href="../uploads/notifications/<?php echo h($notification['attachment']); ?>" target="_blank" class="btn btn-sm btn-primary">

                                                        <i class="fa fa-download me-1"></i>Download Attachment

                                                    </a>

                                                </div>

                                            <?php endif; ?>

                                            <div class="time"><?php echo h(date("d M Y h:i A", strtotime($notification['created_at']))); ?></div>

                                        </div>

                                    <?php endforeach; ?>

                                <?php endif; ?>

                            </div>

                        </div>

                    </div>



                    <div class="user-chip">

                        <div class="avatar"><?php echo h($faculty_initial); ?></div>

                        <div class="info">

                            <div class="name"><?php echo h($faculty['full_name']); ?></div>

                            <div class="role">Faculty</div>

                        </div>

                    </div>

                </div>

            </div>

        </div>



        <div class="page">

            <div class="stats-grid">

                <div class="stat-card stat-purple reveal">

                    <div class="top">

                        <div class="icon"><i class="fa-solid fa-users"></i></div>

                        <div>

                            <div class="label">Total Students</div>

                            <div class="value"><span class="stat-number" data-target="<?php echo (int)$total_students; ?>" data-decimals="0">0</span></div>

                            <div class="note">In your division</div>

                        </div>

                    </div>

                </div>



                <div class="stat-card stat-blue reveal">

                    <div class="top">

                        <div class="icon"><i class="fa-solid fa-briefcase"></i></div>

                        <div>

                            <div class="label">Applications</div>

                            <div class="value"><span class="stat-number" data-target="<?php echo (int)$total_applications; ?>" data-decimals="0">0</span></div>

                            <div class="note">Latest internship records</div>

                        </div>

                    </div>

                </div>



                <div class="stat-card stat-green reveal">

                    <div class="top">

                        <div class="icon"><i class="fa-solid fa-circle-check"></i></div>

                        <div>

                            <div class="label">Approved</div>

                            <div class="value"><span class="stat-number" data-target="<?php echo (int)$approved_count; ?>" data-decimals="0">0</span></div>

                            <div class="note">Approved internships</div>

                        </div>

                    </div>

                </div>



                <div class="stat-card stat-orange reveal">

                    <div class="top">

                        <div class="icon"><i class="fa-solid fa-clock"></i></div>

                        <div>

                            <div class="label">Pending</div>

                            <div class="value"><span class="stat-number" data-target="<?php echo (int)$pending_count; ?>" data-decimals="0">0</span></div>

                            <div class="note">Waiting for review</div>

                        </div>

                    </div>

                </div>



                <div class="stat-card stat-red reveal">

                    <div class="top">

                        <div class="icon"><i class="fa-solid fa-triangle-exclamation"></i></div>

                        <div>

                            <div class="label">Rejected</div>

                            <div class="value"><span class="stat-number" data-target="<?php echo (int)$rejected_count; ?>" data-decimals="0">0</span></div>

                            <div class="note">Rejected applications</div>

                        </div>

                    </div>

                </div>



                <div class="stat-card stat-red reveal">

                    <div class="top">

                        <div class="icon"><i class="fa-solid fa-user-slash"></i></div>

                        <div>

                            <div class="label">No Internship Details</div>

                            <div class="value"><span class="stat-number" data-target="<?php echo (int)$not_submitted_count; ?>" data-decimals="0">0</span></div>

                            <div class="note">Students without internship details</div>

                        </div>

                    </div>

                </div>



                <div class="stat-card stat-gray reveal">

                    <div class="top">

                        <div class="icon"><i class="fa-solid fa-building"></i></div>

                        <div>

                            <div class="label">Companies</div>

                            <div class="value"><span class="stat-number" data-target="<?php echo (int)$total_companies; ?>" data-decimals="0">0</span></div>

                            <div class="note">Distinct recruiters</div>

                        </div>

                    </div>

                </div>



                <div class="stat-card stat-purple reveal">

                    <div class="top">

                        <div class="icon"><i class="fa-solid fa-chart-line"></i></div>

                        <div>

                            <div class="label">Approval Rate</div>

                            <div class="value"><span class="stat-number" data-target="<?php echo h($approval_rate); ?>" data-decimals="2">0.00</span>%</div>

                            <div class="note">

                                <?php echo (int)$approved_count; ?> approved out of <?php echo (int)$total_students; ?> students

                            </div>

                        </div>

                    </div>

                </div>

            </div>



            <div class="stack">

                <div class="panel-grid-2">

                    <div class="panel reveal">

                        <div class="section-head">

                            <div>

                                <h2>Internship Status</h2>

                                <p>Clean overview of submitted internship records.</p>

                            </div>

                        </div>



                        <div class="status-layout">

                            <div class="status-visual">

                                <div class="status-chart-wrap chart-ready chart-focus" data-chart-wrap>

                                    <canvas id="statusChart"></canvas>

                                </div>



                                <div class="status-legend-list">

                                    <div class="status-legend-item"><span class="status-dot approved"></span>Approved (<?php echo (int)$approved_count; ?>)</div>

                                    <div class="status-legend-item"><span class="status-dot pending"></span>Pending (<?php echo (int)$pending_count; ?>)</div>

                                    <div class="status-legend-item"><span class="status-dot rejected"></span>Rejected (<?php echo (int)$rejected_count; ?>)</div>

                                    <div class="status-legend-item"><span class="status-dot nointernship"></span>No Internship (<?php echo (int)$not_submitted_count; ?>)</div>

                                </div>

                            </div>

                        </div>

                    </div>



                    <div class="panel reveal">

                        <div class="section-head">

                            <div>

                                <h2>Internship Type Analysis</h2>

                                <p>Distribution of internship types in this division.</p>

                            </div>

                        </div>



                        <div class="chart-box small chart-ready chart-focus" data-chart-wrap>

                            <canvas id="typeChart"></canvas>

                        </div>

                    </div>

                </div>



                <div class="panel reveal">

                    <div class="section-head">

                        <div>

                            <h2>Division Analytics Search</h2>

                            <p>Filter students by status, type and company inside your division.</p>

                        </div>

                    </div>



                    <form method="GET">

                        <div class="filter-row">

                            <div>

                                <label>Status</label>

                                <select name="status" class="form-control">

                                    <option value="">All Status</option>

                                    <option value="Approved" <?php echo (($_GET['status'] ?? '') === 'Approved') ? 'selected' : ''; ?>>Approved</option>

                                    <option value="Pending" <?php echo (($_GET['status'] ?? '') === 'Pending') ? 'selected' : ''; ?>>Pending</option>

                                    <option value="Rejected" <?php echo (($_GET['status'] ?? '') === 'Rejected') ? 'selected' : ''; ?>>Rejected</option>

                                </select>

                            </div>



                            <div>

                                <label>Internship Type</label>

                                <select name="internship_type" class="form-control">

                                    <option value="">All Types</option>

                                    <?php foreach ($type_dropdown as $type): ?>

                                        <option value="<?php echo h($type); ?>" <?php echo (($_GET['internship_type'] ?? '') === $type) ? 'selected' : ''; ?>>

                                            <?php echo h($type); ?>

                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>



                            <div>

                                <label>Company</label>

                                <select name="company_name" class="form-control">

                                    <option value="">All Companies</option>

                                    <?php foreach ($company_dropdown as $company): ?>

                                        <option value="<?php echo h($company); ?>" <?php echo (($_GET['company_name'] ?? '') === $company) ? 'selected' : ''; ?>>

                                            <?php echo h($company); ?>

                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                        </div>



                        <div class="mt-3 d-flex gap-2 flex-wrap">

                            <button type="submit" name="analytics_search" class="btn btn-primary px-4" style="border-radius:14px;">Search</button>

                            <a href="dashboard.php" class="btn btn-outline-secondary px-4" style="border-radius:14px;">Reset</a>

                        </div>

                    </form>



                    <?php if (isset($_GET['analytics_search'])): ?>

                        <hr class="my-4">

                        <div id="analyticsResult">

                            <h5 class="results-title">Results Found: <?php echo (int)$search_total; ?></h5>



                            <div class="table-shell student-table-wrapper">

                                <table class="table table-hover align-middle mb-0 search-table">

                                    <thead>

                                        <tr>

                                            <th>Roll No</th>

                                            <th>Name</th>

                                            <th>Division</th>

                                            <th>Status</th>

                                            <th>Type</th>

                                            <th>Company</th>

                                            <th>Mentor</th>

                                        </tr>

                                    </thead>

                                    <tbody>

                                        <?php if (count($search_results) === 0): ?>

                                            <tr>

                                                <td colspan="7" class="text-center text-danger">No records found</td>

                                            </tr>

                                        <?php else: ?>

                                            <?php foreach ($search_results as $row): ?>

                                                <tr>

                                                    <td><?php echo h($row['roll_no']); ?></td>

                                                    <td><?php echo h($row['full_name']); ?></td>

                                                    <td><?php echo h($row['division']); ?></td>

                                                    <td><?php echo h($row['status'] ?: '-'); ?></td>

                                                    <td><?php echo h($row['internship_type'] ?: '-'); ?></td>

                                                    <td><?php echo h($row['company_name'] ?: '-'); ?></td>

                                                    <td><?php echo h($row['mentor_name'] ?: '-'); ?></td>

                                                </tr>

                                            <?php endforeach; ?>

                                        <?php endif; ?>

                                    </tbody>

                                </table>

                            </div>

                        </div>

                    <?php else: ?>

                        <hr class="my-4">

                        <div class="empty-state">Select filters and click Search</div>

                    <?php endif; ?>

                </div>



                <div class="panel reveal">

                    <div class="section-head">

                        <div>

                            <h2>Top Recruiting Companies</h2>

                            <p>Company distribution inside your division.</p>

                        </div>

                    </div>



                    <div class="chart-box top-companies-box chart-ready chart-focus" data-chart-wrap>

                        <canvas id="companyChart"></canvas>

                    </div>

                </div>



                <div class="panel reveal">

                    <div class="section-head">

                        <div>

                            <h2>Key Insights</h2>

                            <p>Quick summary for your division.</p>

                        </div>

                    </div>



                    <div class="insights-grid">

                        <div class="info-card">

                            <div class="label">Faculty</div>

                            <div class="value"><?php echo h($faculty['full_name']); ?></div>

                            <div class="meta"><?php echo h($faculty_division); ?> division coordinator.</div>

                        </div>



                        <div class="info-card">

                            <div class="label">Top Company</div>

                            <div class="value"><?php echo h($top_company_name); ?></div>

                            <div class="meta"><?php echo (int)$top_company_students; ?> students associated with this company.</div>

                        </div>



                        <div class="info-card">

                            <div class="label">Total Students</div>

                            <div class="value"><?php echo (int)$total_students; ?></div>

                            <div class="meta">Students assigned to this division.</div>

                        </div>



                        <div class="info-card">

                            <div class="label">Approval Rate</div>

                            <div class="value"><?php echo h($approval_rate); ?>%</div>

                            <div class="meta">Approved internships among current students.</div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </main>

</div>




<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function markNotificationsRead()
{
    fetch("mark_notifications_read.php")
        .then(response => response.text())
        .then(() => {
            const badge = document.querySelector(".badge.rounded-pill.bg-danger");
            if (badge) badge.style.display = "none";
        });
}

(function createParticles(){
    const root = document.getElementById('fxParticles');
    if (!root) return;

    const count = 28;
    for (let i = 0; i < count; i++) {
        const p = document.createElement('span');
        p.className = 'particle';
        const size = 4 + Math.random() * 10;
        const left = Math.random() * 100;
        const top = 10 + Math.random() * 90;
        const dur = 12 + Math.random() * 18;
        const delay = -Math.random() * dur;
        p.style.width = size + 'px';
        p.style.height = size + 'px';
        p.style.left = left + '%';
        p.style.top = top + '%';
        p.style.setProperty('--dur', dur + 's');
        p.style.animationDelay = delay + 's';
        p.style.opacity = (0.08 + Math.random() * 0.22).toFixed(2);
        root.appendChild(p);
    }
})();

(function progressBar(){
    const bar = document.getElementById('topProgress');
    const update = () => {
        const doc = document.documentElement;
        const max = doc.scrollHeight - window.innerHeight;
        const pct = max > 0 ? (window.scrollY / max) * 100 : 0;
        bar.style.width = `${Math.min(100, Math.max(0, pct))}%`;
    };
    update();
    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
})();

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function formatNumber(target, decimals, value) {
    if (decimals > 0) return value.toFixed(decimals);
    return Math.round(value).toLocaleString();
}

function resetCounter(el) {
    const decimals = parseInt(el.dataset.decimals || '0', 10);
    el.dataset.counted = 'false';
    el.textContent = decimals > 0 ? (0).toFixed(decimals) : '0';
}

function animateCounters(scope) {
    const targets = scope.querySelectorAll('.stat-number');
    targets.forEach(el => {
        if (el.dataset.counted === 'true') return;
        el.dataset.counted = 'true';
        const target = parseFloat(el.dataset.target || '0');
        const decimals = parseInt(el.dataset.decimals || '0', 10);
        const duration = reduceMotion ? 1 : 1750;
        const start = performance.now();
        const tick = (now) => {
            const progress = Math.min((now - start) / duration, 1);
            const ease = 1 - Math.pow(1 - progress, 3);
            const current = target * ease;
            el.textContent = formatNumber(target, decimals, current);
            if (progress < 1) requestAnimationFrame(tick);
            else el.textContent = formatNumber(target, decimals, target);
        };
        requestAnimationFrame(tick);
    });
}

const chartInstances = {};

function destroyChart(canvasId) {
    if (chartInstances[canvasId]) {
        chartInstances[canvasId].destroy();
        delete chartInstances[canvasId];
    }
}

function buildLegendPills(items, compact = false) {
    const row = document.createElement('div');
    row.className = `chart-legend-pills${compact ? ' compact' : ''}`;
    items.forEach((item, index) => {
        const pill = document.createElement('div');
        pill.className = 'legend-pill';
        pill.style.setProperty('--delay', `${index * 75}ms`);
        pill.innerHTML = `
            <span class="swatch" style="background:${item.color}"></span>
            <span class="text">
                <span class="name">${item.label}</span>
                <span class="count">${item.valueLabel}</span>
            </span>
            ${item.badge ? `<span class="count-chip">${item.badge}</span>` : ''}
        `;
        row.appendChild(pill);
    });
    return row;
}

function decorateChartWrapper(wrapper, canvasId) {
    if (wrapper.dataset.decorated === 'true') return;
    wrapper.dataset.decorated = 'true';
    wrapper.dataset.chartId = canvasId;

    const meta = chartMeta[canvasId];
    if (!meta) return;


    if (canvasId === 'statusChart') {
        const legend = wrapper.parentElement?.querySelector('.status-legend-list');
        if (legend) {
            legend.classList.add('chart-legend-pills');
            legend.innerHTML = '';
            meta.pills.forEach((item, index) => {
                const pill = document.createElement('div');
                pill.className = 'legend-pill';
                pill.style.setProperty('--delay', `${index * 90}ms`);
                pill.innerHTML = `
                    <span class="swatch" style="background:${item.color}"></span>
                    <span class="text">
                        <span class="name">${item.label}</span>
                        <span class="count">${item.valueLabel}</span>
                    </span>
                `;
                legend.appendChild(pill);
            });
            legend.classList.add('visible');
        }
        return;
    }

    if (meta.pills && meta.pills.length) {
        const row = buildLegendPills(meta.pills, meta.compactLegend === true);
        wrapper.insertAdjacentElement('afterend', row);
    }
}

function initChartFromWrapper(wrapper)
{
    const canvas = wrapper.querySelector('canvas');
    if (!canvas) return;

    if (chartInstances[canvas.id]) return;

    const cfg = chartData[canvas.id];
    if (!cfg) return;

    decorateChartWrapper(wrapper, canvas.id);
    wrapper.classList.add('chart-active');

    chartInstances[canvas.id] = new Chart(canvas, cfg);

    animateCounters(wrapper);

    if (!reduceMotion) {
        requestAnimationFrame(() => {
            setTimeout(() => wrapper.classList.add('visible'), 110);
        });
    }
}

const companyLabels = <?php echo json_encode($company_labels); ?>;
const companyValues = <?php echo json_encode($company_values); ?>;
const statusLabels = <?php echo json_encode($status_labels); ?>;
const statusValues = <?php echo json_encode($status_values); ?>;
const typeLabels = <?php echo json_encode($type_labels); ?>;
const typeValues = <?php echo json_encode($type_values); ?>;

const totalApplications = <?php echo (int)$total_applications; ?>;
const totalStudents = <?php echo (int)$total_students; ?>;
const totalCompanies = <?php echo (int)$total_companies; ?>;
const approvalRate = <?php echo json_encode($approval_rate); ?>;
const totalTypeRecords = typeValues.reduce((sum, value) => sum + Number(value || 0), 0);

const companyPalette = ['#00E5FF', '#7B61FF', '#FF4FD8', '#3B82F6', '#22C55E', '#F59E0B', '#EF4444', '#8B5CF6', '#14B8A6', '#94A3B8'];
const typePalette = ['#3B82F6', '#22C55E', '#F59E0B', '#EF4444', '#8B5CF6', '#00E5FF', '#64748b'];

const chartMeta = {
    companyChart: {
        kind: 'bar',
        compactLegend: true,
        pills: companyLabels.slice(0, 6).map((label, idx) => ({
            label,
            valueLabel: `${companyValues[idx] ?? 0} students`,
            badge: `#${idx + 1}`,
            color: companyPalette[idx % companyPalette.length]
        }))
    },
    statusChart: {
        kind: 'donut',
        pills: [
            { label: 'Approved', valueLabel: `${<?php echo (int)$approved_count; ?>}`, color: '#22C55E' },
            { label: 'Pending', valueLabel: `${<?php echo (int)$pending_count; ?>}`, color: '#F59E0B' },
            { label: 'Rejected', valueLabel: `${<?php echo (int)$rejected_count; ?>}`, color: '#EF4444' },
            { label: 'No Internship', valueLabel: `${<?php echo (int)$not_submitted_count; ?>}`, color: '#9CA3AF' }
        ]
    },
    typeChart: {
        kind: 'donut',
        pills: typeLabels.map((label, idx) => ({
            label,
            valueLabel: `${typeValues[idx] ?? 0}`,
            color: typePalette[idx % typePalette.length]
        }))
    }
};

Chart.defaults.color = '#24304a';
Chart.defaults.font.family = "'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif";


const chartData = {
    companyChart: {
        type: 'bar',
        data: {
            labels: companyLabels,
            datasets: [{
                label: 'Students',
                data: companyValues,
                backgroundColor: (ctx) => {
                    const chart = ctx.chart;
                    const {ctx: c, chartArea} = chart;
                    if (!chartArea) return '#3B82F6';
                    const g = c.createLinearGradient(chartArea.left, chartArea.top, chartArea.right, chartArea.top);
                    g.addColorStop(0, '#00E5FF');
                    g.addColorStop(.55, '#7B61FF');
                    g.addColorStop(1, '#FF4FD8');
                    return g;
                },
                borderRadius: 12,
                barThickness: 18,
                hoverBackgroundColor: '#ffffff',
                borderWidth: 0,
                animation: {
                    duration: 1800,
                    easing: 'easeOutBack',
                    delay: (ctx) => ctx.type === 'data' ? ctx.dataIndex * 110 : 0
                }
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 2200, easing: 'easeOutQuart' },
            layout: { padding: { left: 8, right: 12, top: 8, bottom: 8 } },
            plugins: {
                legend: { display: false },
                title: { display: true, text: 'Top Recruiting Companies', color: '#26304a', font: { size: 14, weight: '700' } },
                tooltip: { backgroundColor: 'rgba(255,255,255,.96)', titleColor: '#162033', bodyColor: '#162033', borderColor: 'rgba(123,97,255,.18)', borderWidth: 1, padding: 12, displayColors: false }
            },
            scales: {
                x: { beginAtZero: true, ticks: { precision: 0, color: 'rgba(38,48,74,.76)' }, grid: { color: 'rgba(123,97,255,.10)' } },
                y: { ticks: { font: { size: 12 }, color: 'rgba(38,48,74,.82)' }, grid: { display: false } }
            }
        }
    },
    statusChart: {
        type: 'doughnut',
        data: {
            labels: statusLabels,
            datasets: [{
                data: statusValues,
                backgroundColor: ['#22c55e','#f59e0b','#ef4444','#9ca3af'],
                borderWidth: 0,
                hoverOffset: 10,
                spacing: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '68%',
            animation: { duration: 2200, easing: 'easeOutQuart', animateRotate: true, animateScale: true },
            plugins: {
                legend: { display: false },
                tooltip: { backgroundColor: 'rgba(255,255,255,.96)', titleColor: '#162033', bodyColor: '#162033', borderColor: 'rgba(123,97,255,.18)', borderWidth: 1, padding: 12, displayColors: false }
            }
        }
    },
    typeChart: {
        type: 'doughnut',
        data: {
            labels: typeLabels,
            datasets: [{
                data: typeValues,
                backgroundColor: ['#3B82F6','#22C55E','#F59E0B','#EF4444','#8B5CF6','#00E5FF','#64748b'],
                borderWidth: 0,
                hoverOffset: 8,
                spacing: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '62%',
            animation: { duration: 2200, easing: 'easeOutQuart', animateRotate: true, animateScale: true },
            plugins: {
                legend: {
                    display: false
                },
                tooltip: { backgroundColor: 'rgba(255,255,255,.96)', titleColor: '#162033', bodyColor: '#162033', borderColor: 'rgba(123,97,255,.18)', borderWidth: 1, padding: 12, displayColors: false }
            }
        }
    }
};

const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        const el = entry.target;
        if (entry.isIntersecting) {
            el.classList.add('visible');
            if (el.classList.contains('stat-card')) {
                animateCounters(el);
            }
            if (el.classList.contains('reveal') && el.querySelector('.stat-number')) {
                animateCounters(el);
            }
            if (el.classList.contains('chart-ready')) {
                initChartFromWrapper(el);
            }
        } else {
            el.classList.remove('visible');
            if (el.classList.contains('chart-ready')) {
                const canvas = el.querySelector('canvas');
                if (canvas) destroyChart(canvas.id);
                el.classList.remove('chart-active');
                el.querySelectorAll('.stat-number').forEach(resetCounter);
            }
            if (el.classList.contains('stat-card')) {
                el.querySelectorAll('.stat-number').forEach(resetCounter);
            }
        }
    });
}, { threshold: 0.12, rootMargin: '0px 0px -2% 0px' });

document.querySelectorAll('.reveal, .chart-ready, .stat-card').forEach(el => observer.observe(el));

window.addEventListener('load', () => {
    document.querySelectorAll('.reveal, .chart-ready').forEach(el => {
        if (el.getBoundingClientRect().top < window.innerHeight * 0.9) {
            el.classList.add('visible');
            if (el.classList.contains('chart-ready')) {
                initChartFromWrapper(el);
            }
        }
    });
});
</script>

</body>

</html>



