<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
mysqli_report(MYSQLI_REPORT_OFF);

session_start();

$configPath = __DIR__ . '/../config.php';
if (!file_exists($configPath)) {
    die('Configuration file not found.');
}
include $configPath;

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection failed.');
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function shortText($text, $limit = 140)
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

function runQuery($conn, $sql)
{
    $result = @mysqli_query($conn, $sql);
    return ($result instanceof mysqli_result) ? $result : null;
}

function fetchOneAssoc($conn, $sql)
{
    $result = runQuery($conn, $sql);
    if (!$result) {
        return [];
    }
    $row = mysqli_fetch_assoc($result);
    mysqli_free_result($result);
    return $row ?: [];
}

function fetchAllAssoc($conn, $sql)
{
    $rows = [];
    $result = runQuery($conn, $sql);
    if (!$result) {
        return $rows;
    }
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_free_result($result);
    return $rows;
}

function latestInternshipFromClause()
{
    return "
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
}

function latestInternshipWhereClause($division = '')
{
    $where = "WHERE u.role='student'";
    if ($division !== '') {
        $where .= " AND u.division='" . mysqli_real_escape_string($GLOBALS['conn'], $division) . "'";
    }
    return $where;
}

function buildStatusBadge($status)
{
    $status = trim($status);
    $class = 'b-gray';
    if ($status === 'Approved') {
        $class = 'b-success';
    } elseif ($status === 'Pending') {
        $class = 'b-warning';
    } elseif ($status === 'Rejected') {
        $class = 'b-danger';
    } elseif ($status === 'No Internship') {
        $class = 'b-gray';
    }
    return '<span class="status-badge ' . $class . '">' . h($status ?: 'Pending') . '</span>';
}


function notificationSenderLabel(array $row, mysqli $conn): string
{
    foreach (['sender_name', 'faculty_name', 'sent_by', 'created_by_name', 'posted_by', 'author_name'] as $key) {
        if (!empty($row[$key])) {
            return trim((string)$row[$key]);
        }
    }

    foreach (['faculty_id', 'sender_id', 'user_id', 'created_by'] as $key) {
        if (!empty($row[$key]) && ctype_digit((string)$row[$key])) {
            $id = (int)$row[$key];
            $q = @mysqli_query($conn, "SELECT full_name, name FROM users WHERE id='$id' LIMIT 1");
            if ($q && mysqli_num_rows($q) > 0) {
                $u = mysqli_fetch_assoc($q);
                mysqli_free_result($q);
                $sender = trim((string)($u['full_name'] ?? ''));
                if ($sender === '') {
                    $sender = trim((string)($u['name'] ?? ''));
                }
                if ($sender !== '') {
                    return $sender;
                }
            }
        }
    }

    return 'Faculty Member';
}

function notificationTimeLabel(?string $date): string
{
    if (empty($date)) {
        return '-';
    }
    $ts = strtotime($date);
    return $ts ? date("d M Y h:i A", $ts) : '-';
}
/* -------------------------------
   CORE COUNTS
--------------------------------*/
$total_students = 0;
$total_faculty = 0;
$total_internships = 0;
$approved_count = 0;
$pending_count = 0;
$rejected_count = 0;
$no_details_count = 0;
$total_companies = 0;
$total_types = 0;
$approval_rate = 0.0;

$student_result = runQuery($conn, "SELECT COUNT(*) AS total FROM users WHERE role='student'");
if ($student_result) {
    $total_students = (int)(mysqli_fetch_assoc($student_result)['total'] ?? 0);
    mysqli_free_result($student_result);
}

$faculty_result = runQuery($conn, "SELECT COUNT(*) AS total FROM users WHERE role='faculty'");
if ($faculty_result) {
    $total_faculty = (int)(mysqli_fetch_assoc($faculty_result)['total'] ?? 0);
    mysqli_free_result($faculty_result);
}

/* latest internship per student */
$latest_from = latestInternshipFromClause();
$latest_where = latestInternshipWhereClause();

$latest_total_result = runQuery(
    $conn,
    "SELECT COUNT(*) AS total
     $latest_from
     $latest_where"
);
if ($latest_total_result) {
    $total_internships = (int)(mysqli_fetch_assoc($latest_total_result)['total'] ?? 0);
    mysqli_free_result($latest_total_result);
}

$approved_result = runQuery(
    $conn,
    "SELECT COUNT(*) AS total
     $latest_from
     $latest_where
     AND i.status='Approved'"
);
if ($approved_result) {
    $approved_count = (int)(mysqli_fetch_assoc($approved_result)['total'] ?? 0);
    mysqli_free_result($approved_result);
}

$pending_result = runQuery(
    $conn,
    "SELECT COUNT(*) AS total
     $latest_from
     $latest_where
     AND i.status='Pending'"
);
if ($pending_result) {
    $pending_count = (int)(mysqli_fetch_assoc($pending_result)['total'] ?? 0);
    mysqli_free_result($pending_result);
}

$rejected_result = runQuery(
    $conn,
    "SELECT COUNT(*) AS total
     $latest_from
     $latest_where
     AND i.status='Rejected'"
);
if ($rejected_result) {
    $rejected_count = (int)(mysqli_fetch_assoc($rejected_result)['total'] ?? 0);
    mysqli_free_result($rejected_result);
}

$no_details_result = runQuery(
    $conn,
    "SELECT COUNT(*) AS total
     $latest_from
     $latest_where
     AND (
            i.id IS NULL
            OR (
                TRIM(IFNULL(i.company_name,'')) = ''
                AND TRIM(IFNULL(i.internship_type,'')) = ''
                AND TRIM(IFNULL(i.status,'')) = ''
            )
        )"
);
if ($no_details_result) {
    $no_details_count = (int)(mysqli_fetch_assoc($no_details_result)['total'] ?? 0);
    mysqli_free_result($no_details_result);
}

$companies_result = runQuery(
    $conn,
    "SELECT COUNT(DISTINCT i.company_name) AS total
     $latest_from
     $latest_where
     AND i.company_name IS NOT NULL
     AND TRIM(i.company_name) <> ''"
);
if ($companies_result) {
    $total_companies = (int)(mysqli_fetch_assoc($companies_result)['total'] ?? 0);
    mysqli_free_result($companies_result);
}

$types_result = runQuery(
    $conn,
    "SELECT COUNT(DISTINCT i.internship_type) AS total
     $latest_from
     $latest_where
     AND i.internship_type IS NOT NULL
     AND TRIM(i.internship_type) <> ''"
);
if ($types_result) {
    $total_types = (int)(mysqli_fetch_assoc($types_result)['total'] ?? 0);
    mysqli_free_result($types_result);
}

if ($total_internships > 0) {
    $approval_rate = round(($approved_count / $total_internships) * 100, 2);
}

/* TOP COMPANY */
$top_company = fetchOneAssoc(
    $conn,
    "SELECT i.company_name, COUNT(*) AS total
     $latest_from
     $latest_where
     AND i.company_name IS NOT NULL
     AND TRIM(i.company_name) <> ''
     GROUP BY i.company_name
     ORDER BY total DESC
     LIMIT 1"
);
$top_company_name = $top_company['company_name'] ?? '-';
$top_company_students = (int)($top_company['total'] ?? 0);

/* TOP COMPANIES CHART */
$company_rows = fetchAllAssoc(
    $conn,
    "SELECT i.company_name, COUNT(*) AS total
     $latest_from
     $latest_where
     AND i.company_name IS NOT NULL
     AND TRIM(i.company_name) <> ''
     GROUP BY i.company_name
     ORDER BY total DESC
     LIMIT 8"
);
$company_labels = [];
$company_values = [];
foreach ($company_rows as $row) {
    $company_labels[] = (strlen((string)$row['company_name']) > 28)
        ? substr((string)$row['company_name'], 0, 28) . '...'
        : (string)$row['company_name'];
    $company_values[] = (int)($row['total'] ?? 0);
}

/* TYPES */
$type_rows = fetchAllAssoc(
    $conn,
    "SELECT
        TRIM(internship_type) AS internship_type,
        COUNT(*) AS total
     FROM internships
     WHERE status='Approved'
     AND internship_type IS NOT NULL
     AND TRIM(internship_type) <> ''
     GROUP BY TRIM(internship_type)
     ORDER BY total DESC"
);

$type_labels = [];
$type_values = [];

foreach ($type_rows as $row)
{
    $name = trim($row['internship_type']);

    // Merge both Training names
    if($name == "Training + Mini Project Internship")
    {
        $name = "Training + Mini Project";
    }

    $index = array_search($name, $type_labels);

    if($index !== false)
    {
        $type_values[$index] += (int)$row['total'];
    }
    else
    {
        $type_labels[] = $name;
        $type_values[] = (int)$row['total'];
    }
}

if(empty($type_labels))
{
    $type_labels = ['Not Set'];
    $type_values = [0];
}

/* DIVISIONS */
$division_rows = fetchAllAssoc(
    $conn,
    "SELECT
        u.division,
        SUM(CASE WHEN i.status='Approved' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN i.status='Pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN i.status='Rejected' THEN 1 ELSE 0 END) AS rejected,
        SUM(CASE WHEN i.id IS NULL OR i.status IS NULL OR TRIM(i.status)='' THEN 1 ELSE 0 END) AS no_details
     FROM users u
     LEFT JOIN internships i
        ON i.id = (
            SELECT MAX(id)
            FROM internships
            WHERE student_id = u.id
        )
     WHERE u.role='student'
     GROUP BY u.division
     ORDER BY CAST(REPLACE(REPLACE(UPPER(u.division), 'SY-', ''), 'FY-', '') AS UNSIGNED), u.division"
);

$division_labels = [];
$division_approved = [];
$division_pending = [];
$division_rejected = [];
$division_no_details = [];

foreach ($division_rows as $row) {
    $division_labels[] = (string)($row['division'] ?: 'Not Set');
    $division_approved[] = (int)($row['approved'] ?? 0);
    $division_pending[] = (int)($row['pending'] ?? 0);
    $division_rejected[] = (int)($row['rejected'] ?? 0);
    $division_no_details[] = (int)($row['no_details'] ?? 0);
}

/* RECENT RECORDS */
$recent_records = fetchAllAssoc(
    $conn,
    "SELECT
        u.id,
        u.roll_no,
        COALESCE(NULLIF(TRIM(u.full_name), ''), NULLIF(TRIM(u.name), ''), CONCAT('Student #', u.id)) AS student_name,
        COALESCE(NULLIF(TRIM(u.division), ''), '-') AS division,
        COALESCE(NULLIF(TRIM(i.status), ''), 'Pending') AS status,
        COALESCE(NULLIF(TRIM(i.internship_type), ''), '-') AS internship_type,
        COALESCE(NULLIF(TRIM(i.company_name), ''), '-') AS company_name,
        COALESCE(NULLIF(TRIM(i.mentor_name), ''), '-') AS mentor_name,
        COALESCE(i.created_at, u.created_at) AS created_at
     $latest_from
     WHERE u.role='student'
     ORDER BY i.id DESC
     LIMIT 10"
);

/* NOTIFICATIONS */
$notifications = fetchAllAssoc(
    $conn,
    "SELECT id, title, message, attachment, created_at
     FROM admin_notifications
     WHERE target_role IN ('all', 'admin')
     ORDER BY id DESC
     LIMIT 5"
);

$faculty_notifications = fetchAllAssoc(
    $conn,
    "SELECT id, title, message, attachment, created_at
     FROM faculty_notifications
     ORDER BY id DESC
     LIMIT 3"
);

$latest_notifications = [];

foreach ($notifications as $note) {
    $latest_notifications[] = [
        'type' => 'admin',
        'title' => (string)($note['title'] ?? 'Notification'),
        'message' => (string)($note['message'] ?? ''),
        'attachment' => (string)($note['attachment'] ?? ''),
        'created_at' => (string)($note['created_at'] ?? ''),
        'sender' => 'Admin',
    ];
}

foreach ($faculty_notifications as $note) {
    $latest_notifications[] = [
        'type' => 'faculty',
        'title' => (string)($note['title'] ?? 'Notification'),
        'message' => (string)($note['message'] ?? ''),
        'attachment' => (string)($note['attachment'] ?? ''),
        'created_at' => (string)($note['created_at'] ?? ''),
        'sender' => notificationSenderLabel($note, $conn),
    ];
}

usort($latest_notifications, static function (array $a, array $b): int {
    $ta = !empty($a['created_at']) ? strtotime($a['created_at']) : 0;
    $tb = !empty($b['created_at']) ? strtotime($b['created_at']) : 0;
    return $tb <=> $ta;
});

$latest_notifications = array_slice($latest_notifications, 0, 6);

/* FILTER OPTIONS */
$division_options = [];
$division_options_q = runQuery(
    $conn,
    "SELECT DISTINCT division
     FROM users
     WHERE role='student' AND division IS NOT NULL AND TRIM(division) <> ''
     ORDER BY division"
);
if ($division_options_q) {
    while ($r = mysqli_fetch_assoc($division_options_q)) {
        $division_options[] = $r['division'];
    }
    mysqli_free_result($division_options_q);
}

$type_options = [];
$type_options_q = runQuery(
    $conn,
    "SELECT DISTINCT internship_type
     FROM internships
     WHERE internship_type IS NOT NULL AND TRIM(internship_type) <> ''
     ORDER BY internship_type"
);
if ($type_options_q) {
    while ($r = mysqli_fetch_assoc($type_options_q)) {
        $type_options[] = $r['internship_type'];
    }
    mysqli_free_result($type_options_q);
}

$company_options = [];
$company_options_q = runQuery(
    $conn,
    "SELECT DISTINCT company_name
     FROM internships
     WHERE company_name IS NOT NULL AND TRIM(company_name) <> ''
     ORDER BY company_name"
);
if ($company_options_q) {
    while ($r = mysqli_fetch_assoc($company_options_q)) {
        $company_options[] = $r['company_name'];
    }
    mysqli_free_result($company_options_q);
}

$top_division = fetchOneAssoc(
    $conn,
    "SELECT u.division, COUNT(*) AS total
     FROM users u
     INNER JOIN (
        SELECT student_id, MAX(id) AS max_id
        FROM internships
        GROUP BY student_id
     ) latest ON latest.student_id = u.id
     INNER JOIN internships i ON i.id = latest.max_id
     WHERE u.role='student'
     GROUP BY u.division
     ORDER BY total DESC
     LIMIT 1"
);
$top_division_name = $top_division['division'] ?? '-';
$top_division_students = (int)($top_division['total'] ?? 0);

/* AJAX analytics */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'analytics') {
    header('Content-Type: application/json; charset=UTF-8');

    $divisionFilter = trim((string)($_GET['division'] ?? ''));
    $typeFilter = trim((string)($_GET['type'] ?? ''));
    $companyFilter = trim((string)($_GET['company'] ?? ''));

    $where = ["u.role='student'"];
    if ($divisionFilter !== '') {
        $where[] = "u.division='" . mysqli_real_escape_string($conn, $divisionFilter) . "'";
    }
    if ($typeFilter !== '') {
        $where[] = "i.internship_type='" . mysqli_real_escape_string($conn, $typeFilter) . "'";
    }
    if ($companyFilter !== '') {
        $where[] = "i.company_name='" . mysqli_real_escape_string($conn, $companyFilter) . "'";
    }

    $sql = "
        SELECT
            u.roll_no,
            COALESCE(NULLIF(TRIM(u.full_name), ''), NULLIF(TRIM(u.name), ''), CONCAT('Student #', u.id)) AS student_name,
            COALESCE(NULLIF(TRIM(u.division), ''), '-') AS division,
            COALESCE(NULLIF(TRIM(i.company_name), ''), '-') AS company_name,
            COALESCE(NULLIF(TRIM(i.internship_type), ''), '-') AS internship_type,
            COALESCE(NULLIF(TRIM(i.status), ''), 'Pending') AS status,
            COALESCE(i.created_at, u.created_at) AS created_at
        FROM users u
        LEFT JOIN internships i
            ON i.id = (
                SELECT MAX(id)
                FROM internships
                WHERE student_id = u.id
            )
        WHERE " . implode(' AND ', $where) . "
        ORDER BY CAST(u.roll_no AS UNSIGNED), u.full_name ASC
        LIMIT 100
    ";

    $result = runQuery($conn, $sql);

    $records = [];
    $statusCounts = ['Approved' => 0, 'Pending' => 0, 'Rejected' => 0, 'No Internship' => 0];

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $status = trim((string)($row['status'] ?? 'Pending'));
            if ($status === '') {
                $status = 'No Internship';
            }
            if (!isset($statusCounts[$status])) {
                $statusCounts[$status] = 0;
            }
            $statusCounts[$status]++;

            $records[] = [
                'roll_no' => (string)($row['roll_no'] ?? '-'),
                'student_name' => (string)($row['student_name'] ?? '-'),
                'division' => (string)($row['division'] ?? '-'),
                'company_name' => (string)($row['company_name'] ?? '-'),
                'internship_type' => (string)($row['internship_type'] ?? '-'),
                'status' => $status,
                'created_at' => (string)($row['created_at'] ?? ''),
            ];
        }
        mysqli_free_result($result);
    }

    echo json_encode([
        'ok' => true,
        'total_records' => count($records),
        'approved' => $statusCounts['Approved'] ?? 0,
        'pending' => $statusCounts['Pending'] ?? 0,
        'rejected' => $statusCounts['Rejected'] ?? 0,
        'no_internship' => $statusCounts['No Internship'] ?? 0,
        'records' => $records,
    ], JSON_UNESCAPED_UNICODE);

    exit();
}

$faculty_name = trim((string)($_SESSION['full_name'] ?? $_SESSION['name'] ?? 'Admin'));
$faculty_initial = strtoupper(substr(preg_replace('/^(Prof\.?|Dr\.?|Mr\.?|Mrs\.?|Ms\.?)\s*/i', '', $faculty_name) ?: 'A', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root{
            --bg0:#f5f3ff;
            --bg1:#faf9ff;
            --surface:#ffffff;
            --surface-2:#fcfbff;
            --text:#1f2430;
            --muted:#6a7289;
            --border:#e7e1f7;
            --purple:#5b3df5;
            --blue:#2b7cff;
            --cyan:#00c8ff;
            --pink:#ff4fd8;
            --green:#1f9d5a;
            --orange:#f3b61f;
            --danger:#e25563;
            --gray:#64748b;
            --shadow:0 20px 50px rgba(73,45,150,.08);
            --shadow-soft:0 10px 22px rgba(73,45,150,.06);
            --radius:28px;
            --sidebar-width:288px;
        }

        *{box-sizing:border-box}
        html, body{
            width:100%;
            max-width:100%;
            overflow-x:hidden;
            scroll-behavior:smooth;
        }
        body{
            margin:0;
            font-family:'Inter',system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
            color:var(--text);
            background:
                radial-gradient(circle at 12% 10%, rgba(91,61,245,.10), transparent 24%),
                radial-gradient(circle at 80% 8%, rgba(0,200,255,.08), transparent 18%),
                radial-gradient(circle at 72% 82%, rgba(255,79,216,.06), transparent 18%),
                linear-gradient(180deg, #fbfaff 0%, #f5f3ff 100%);
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
            opacity:.12;
            mask-image:linear-gradient(to bottom, transparent, black 12%, black 88%, transparent);
            z-index:0;
        }

        ::-webkit-scrollbar{width:10px;height:10px}
        ::-webkit-scrollbar-track{background:#ece8fb}
        ::-webkit-scrollbar-thumb{
            background:linear-gradient(180deg, #7b61ff, #5b3df5);
            border-radius:999px;
            border:2px solid #ece8fb;
        }

        a{text-decoration:none}
        img, canvas{max-width:100%}

        .top-progress{
            position:fixed;
            top:0; left:0;
            height:4px;
            width:0%;
            z-index:9999;
            background:linear-gradient(90deg, var(--purple), var(--blue), var(--pink));
            box-shadow:0 0 18px rgba(91,61,245,.35);
        }

        .fx-bg{
            position:fixed;
            inset:0;
            pointer-events:none;
            z-index:0;
            overflow:hidden;
        }
        .fx-orb{
            position:absolute;
            border-radius:50%;
            filter:blur(28px);
            opacity:.28;
            mix-blend-mode:screen;
            animation:orbFloat 16s ease-in-out infinite;
        }
        .fx-orb.orb1{
            width:340px;height:340px;left:-120px;top:80px;
            background:radial-gradient(circle at 30% 30%, rgba(91,61,245,.70), rgba(91,61,245,0) 68%);
        }
        .fx-orb.orb2{
            width:260px;height:260px;right:90px;top:120px;
            background:radial-gradient(circle at 30% 30%, rgba(0,200,255,.34), rgba(0,200,255,0) 68%);
            animation-delay:-6s;
        }
        .fx-orb.orb3{
            width:220px;height:220px;right:30px;bottom:80px;
            background:radial-gradient(circle at 30% 30%, rgba(255,79,216,.24), rgba(255,79,216,0) 68%);
            animation-delay:-10s;
        }
        .fx-particles{
            position:absolute;
            inset:0;
        }
        .particle{
            position:absolute;
            border-radius:50%;
            background:radial-gradient(circle, rgba(255,255,255,.95), rgba(255,255,255,.05) 55%, rgba(255,255,255,0) 72%);
            box-shadow:0 0 28px rgba(255,255,255,.26);
            opacity:.20;
            animation:particleDrift var(--dur, 18s) linear infinite;
            transform:translate3d(0,0,0);
        }

        .app{
            min-height:100vh;
            display:flex;
            width:100%;
            overflow-x:hidden;
            position:relative;
            z-index:1;
        }

        .sidebar{
            width:var(--sidebar-width);
            min-height:100vh;
            position:fixed;
            inset:0 auto 0 0;
            background:linear-gradient(180deg, #3f0f68 0%, #6d1a88 48%, #4a1474 100%);
            color:#fff;
            box-shadow:10px 0 30px rgba(4,2,18,.18);
            z-index:20;
            display:flex;
            flex-direction:column;
            overflow:hidden;
            border-right:1px solid rgba(255,255,255,.06);
        }
        .sidebar::after{
            content:"";
            position:absolute;
            inset:0;
            background:linear-gradient(180deg, rgba(255,255,255,.06), transparent 40%, rgba(255,255,255,.03));
            pointer-events:none;
        }

        .brand{
            padding:16px 16px 12px;
            text-align:center;
            border-bottom:1px solid rgba(255,255,255,.08);
        }
        .brand img{
            width:120px;
            height:auto;
            object-fit:contain;
            display:block;
            margin:0 auto 10px;
            background:none;
            box-shadow:none;
        }
        .brand .title{
            font-size:1.08rem;
            font-weight:800;
            line-height:1.08;
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
            gap:12px;
            align-items:center;
            padding:12px 16px;
            border-bottom:1px solid rgba(255,255,255,.08);
            min-width:0;
        }
        .profile-mini .avatar{
            width:44px;
            height:44px;
            border-radius:50%;
            display:grid;
            place-items:center;
            background:linear-gradient(135deg, #7c5cff, #4f7cff);
            font-weight:800;
            color:#fff;
            box-shadow:0 12px 24px rgba(0,0,0,.24), 0 0 0 6px rgba(255,255,255,.05);
            flex:0 0 auto;
            animation:floatIcon 4s ease-in-out infinite;
        }
        .profile-mini .meta{min-width:0}
        .profile-mini .meta .name{
            font-size:.93rem;
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
            overflow:auto;
            position:relative;
            z-index:1;
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
            gap:11px;
            color:rgba(255,255,255,.94);
            padding:10px 12px;
            border-radius:15px;
            transition:transform .22s ease, background .22s ease, box-shadow .22s ease, color .22s ease;
            font-weight:600;
            margin-bottom:2px;
            text-decoration:none;
            min-width:0;
            will-change:transform;
            font-size:.92rem;
            position:relative;
            overflow:hidden;
        }
        .nav a::before{
            content:"";
            position:absolute;
            inset:0;
            background:linear-gradient(90deg, rgba(255,255,255,.18), transparent 35%, rgba(255,255,255,.10));
            opacity:0;
            transform:translateX(-30%);
            transition:opacity .25s ease, transform .35s ease;
        }
        .nav a:hover::before, .nav a.active::before{
            opacity:1;
            transform:translateX(0);
        }
        .nav a i{
            width:18px;
            text-align:center;
            font-size:.98rem;
            flex:0 0 auto;
            transition:transform .25s ease;
        }
        .nav a:hover i, .nav a.active i{
            transform:rotate(8deg) scale(1.06);
        }
        .nav a:hover{
            background:linear-gradient(90deg, #cf1e71, #f26d21);
            color:#fff;
            transform:translateX(4px) scale(1.02);
            box-shadow:0 12px 24px rgba(0,0,0,.14);
        }
        .nav a.active{
            background:linear-gradient(90deg, #cf1e71, #f26d21);
            box-shadow:0 18px 30px rgba(91,61,245,.24);
        }
        .nav .account-bottom{
            margin-top:auto;
            padding-top:8px;
            border-top:1px solid rgba(255,255,255,.08);
        }

        .main{
            margin-left:var(--sidebar-width);
            width:calc(100% - var(--sidebar-width));
            min-width:0;
            overflow-x:hidden;
            position:relative;
            z-index:1;
            background:
                radial-gradient(circle at top left, rgba(91,61,245,.10), transparent 26%),
                radial-gradient(circle at top right, rgba(0,200,255,.06), transparent 18%),
                linear-gradient(180deg, #ffffff 0%, #faf9ff 38%, #f2f6ff 100%);
        }

        .topbar{
            position:sticky;
            top:0;
            z-index:12;
            background:rgba(255,255,255,.88);
            backdrop-filter:blur(20px);
            border-bottom:1px solid rgba(231,225,247,.92);
            box-shadow:0 8px 30px rgba(73,45,150,.05);
        }
        .topbar-inner{
            padding:22px 30px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:20px;
            max-width:100%;
            min-width:0;
        }
        .hero-title{
            display:flex;
            flex-direction:column;
            gap:6px;
            min-width:0;
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
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
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
            min-width:0;
        }
        .action-btn{
            border:1px solid var(--border);
            background:#fff;
            color:var(--purple);
            font-weight:700;
            padding:10px 16px;
            border-radius:16px;
            transition:transform .25s ease, box-shadow .25s ease, background .25s ease, border-color .25s ease;
            display:inline-flex;
            align-items:center;
            gap:10px;
            white-space:nowrap;
            box-shadow:var(--shadow-soft);
        }
        .action-btn:hover{
            background:#fff;
            color:#3127d1;
            border-color:rgba(123,97,255,.28);
            box-shadow:0 16px 28px rgba(91,61,245,.14);
            transform:translateY(-2px);
        }

        .user-chip{
            display:flex;
            align-items:center;
            gap:12px;
            padding:8px 10px 8px 8px;
            border-radius:999px;
            background:#fff;
            border:1px solid var(--border);
            box-shadow:var(--shadow-soft);
            max-width:100%;
        }
        .user-chip .avatar{
            width:54px;
            height:54px;
            border-radius:50%;
            background:linear-gradient(135deg,#d8e8ff,#b6c8ff);
            color:#3254c7;
            display:grid;
            place-items:center;
            font-weight:800;
            font-size:1.1rem;
            flex:0 0 auto;
            animation:floatIcon 4s ease-in-out infinite;
        }
        .user-chip .info{
            line-height:1.2;
            padding-right:10px;
            min-width:0;
        }
        .user-chip .info .name{
            font-weight:800;
            font-size:.98rem;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
            color:#24304d;
        }
        .user-chip .info .role{
            color:var(--muted);
            font-size:.84rem;
        }

        .page{
            padding:22px 28px 36px;
            max-width:100%;
            overflow-x:hidden;
            position:relative;
        }
        .page > *{position:relative;z-index:1}

        .page::before{
            content:"";
            position:absolute;
            inset:0;
            pointer-events:none;
            background:
                radial-gradient(circle at 20% 14%, rgba(91,61,245,.08), transparent 18%),
                radial-gradient(circle at 84% 18%, rgba(0,200,255,.06), transparent 16%),
                radial-gradient(circle at 70% 80%, rgba(255,79,216,.05), transparent 18%);
        }

        .stats-grid{
            display:grid;
            grid-template-columns:repeat(4, minmax(0, 1fr));
            gap:18px;
            margin-bottom:22px;
            width:100%;
        }
        .stat-card{
            border-radius:28px;
            padding:22px;
            min-height:160px;
            display:flex;
            flex-direction:column;
            justify-content:space-between;
            border:1px solid rgba(123,97,255,.13);
            box-shadow:var(--shadow);
            overflow:hidden;
            position:relative;
            min-width:0;
            opacity:0;
            transform:translateY(18px);
            transition:transform .45s cubic-bezier(.16,1,.3,1), opacity .45s ease, box-shadow .35s ease, border-color .35s ease;
        }
        .stat-card.visible{opacity:1; transform:translateY(0)}
        .stat-card::before{
            content:"";
            position:absolute;
            inset:-2px;
            background:linear-gradient(135deg, rgba(255,255,255,.65), rgba(255,255,255,0) 32%, rgba(255,255,255,.20));
            opacity:.55;
            pointer-events:none;
            mix-blend-mode:screen;
        }
        .stat-card::after{
            content:"";
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
            display:flex;
            align-items:center;
            gap:14px;
            min-width:0;
            position:relative;
            z-index:1;
        }
        .stat-card .icon{
            width:58px;
            height:58px;
            border-radius:18px;
            display:grid;
            place-items:center;
            background:rgba(255,255,255,.22);
            color:#fff;
            font-size:1.2rem;
            flex:0 0 auto;
            box-shadow:0 12px 28px rgba(0,0,0,.12);
            animation:floatIcon 3.8s ease-in-out infinite;
            transition:transform .35s ease, box-shadow .35s ease, filter .35s ease;
        }
        .stat-card:hover{
            transform:translateY(-8px) scale(1.02);
            box-shadow:0 28px 56px rgba(80,52,160,.14);
            border-color:rgba(123,97,255,.28);
        }
        .stat-card:hover .icon{
            transform:rotate(7deg) scale(1.08);
        }
        .stat-card .label{
            color:#24304d;
            font-weight:800;
            font-size:1.02rem;
            line-height:1.1;
        }
        .stat-card .value{
            color:#24304d;
            font-weight:900;
            font-size:2.1rem;
            line-height:1;
            margin-top:6px;
            letter-spacing:-.05em;
        }
        .stat-card .note{
            color:var(--muted);
            font-size:.92rem;
            margin-top:4px;
            line-height:1.35;
        }

        .stat-purple{background:linear-gradient(135deg,#f0ebff,#e8e0ff)}
        .stat-green{background:linear-gradient(135deg,#ecfbf2,#ddf8e7)}
        .stat-orange{background:linear-gradient(135deg,#fff7e9,#fff0d6)}
        .stat-blue{background:linear-gradient(135deg,#edf5ff,#deecff)}
        .stat-red{background:linear-gradient(135deg,#fff0f2,#ffe1e6)}
        .stat-gray{background:linear-gradient(135deg,#f0f2f7,#e7ebf5)}
        .stat-violet{background:linear-gradient(135deg,#f0ebff,#e9e0ff)}

        .stat-purple .icon{background:#5b3df5}
        .stat-green .icon{background:#1f9d5a}
        .stat-orange .icon{background:#f3b61f}
        .stat-blue .icon{background:#2b7cff}
        .stat-red .icon{background:#e25563}
        .stat-gray .icon{background:#64748b}
        .stat-violet .icon{background:#6f42ff}

        .stat-purple .label,.stat-purple .value{color:#2f2358}
        .stat-green .label,.stat-green .value{color:#1f4f30}
        .stat-orange .label,.stat-orange .value{color:#5a4212}
        .stat-blue .label,.stat-blue .value{color:#183b7a}
        .stat-red .label,.stat-red .value{color:#7f1d2d}
        .stat-gray .label,.stat-gray .value{color:#334155}
        .stat-violet .label,.stat-violet .value{color:#39256d}

        .content-grid{
            display:grid;
            grid-template-columns:minmax(0, 1.35fr) minmax(0, .95fr);
            gap:22px;
            align-items:start;
        }
        .stack{display:grid;gap:22px}

        .panel{
            padding:22px;
            border-radius:28px;
            background:rgba(255,255,255,.88);
            border:1px solid rgba(231,225,247,.92);
            box-shadow:var(--shadow);
            backdrop-filter:blur(14px);
            opacity:0;
            transform:translateY(18px);
            transition:opacity .55s ease, transform .55s cubic-bezier(.16,1,.3,1), box-shadow .35s ease;
        }
        .panel.visible{opacity:1; transform:translateY(0)}
        .panel:hover{box-shadow:0 24px 52px rgba(73,45,150,.12)}
        .section-head{
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:16px;
            margin-bottom:16px;
            min-width:0;
            position:relative;
        }
        .section-head::after{
            content:"";
            position:absolute;
            left:0;
            bottom:-10px;
            width:96px;
            height:3px;
            border-radius:999px;
            background:linear-gradient(90deg, var(--purple), #7aa2ff, #ff4fd8);
            opacity:.9;
        }
        .section-head h2{
            font-size:1.25rem;
            font-weight:800;
            margin:0;
            letter-spacing:-.03em;
            color:#24304d;
        }
        .section-head p{
            margin:6px 0 0;
            color:var(--muted);
            font-size:.94rem;
        }

        .chart-shell{
            position:relative;
            border-radius:22px;
            background:linear-gradient(135deg, rgba(123,97,255,.06), rgba(59,130,246,.04), rgba(255,255,255,.92));
            box-shadow:inset 0 1px 0 rgba(255,255,255,.85);
            overflow:hidden;
        }
        .chart-shell::before{
            content:"";
            position:absolute;
            inset:-30% -20%;
            background:
                radial-gradient(circle at 25% 35%, rgba(91,61,245,.14), transparent 20%),
                radial-gradient(circle at 72% 28%, rgba(43,124,255,.12), transparent 18%),
                radial-gradient(circle at 60% 72%, rgba(31,157,90,.10), transparent 20%);
            filter:blur(14px);
            opacity:.8;
            animation:blobDrift 16s ease-in-out infinite;
        }
        .chart-shell.active{
            box-shadow:0 0 0 1px rgba(123,97,255,.18), 0 0 34px rgba(91,61,245,.16), inset 0 1px 0 rgba(255,255,255,.85);
        }
        .chart-shell-inner{
            position:relative;
            z-index:1;
            padding:18px;
            height:100%;
            display:flex;
            flex-direction:column;
            gap:16px;
        }
        .chart-box{
            position:relative;
            width:100%;
            height:100%;
        }
        .chart-box canvas{
            width:100% !important;
            height:100% !important;
        }
        .chart-fallback-note{
            font-size:.88rem;
            color:#6f7690;
            line-height:1.45;
            padding:14px 16px;
            border-radius:18px;
            border:1px dashed rgba(167,139,250,.55);
            background:rgba(255,255,255,.88);
        }

        .legend-pills{display:flex;flex-wrap:wrap;gap:10px}
        .legend-pill{
            display:flex;
            align-items:center;
            gap:10px;
            padding:10px 14px;
            border-radius:999px;
            background:#fff;
            border:1px solid var(--border);
            box-shadow:0 10px 18px rgba(73,45,150,.05);
            min-width:0;
        }
        .legend-pill .dot{
            width:10px;
            height:10px;
            border-radius:50%;
            box-shadow:0 0 0 4px rgba(255,255,255,.88);
            flex:0 0 auto;
        }
        .legend-pill .txt{
            min-width:0;
            color:#2f3a57;
            font-size:.88rem;
            line-height:1.2;
            font-weight:700;
        }
        .legend-pill .txt span{
            display:block;
            font-weight:800;
            color:#596583;
            margin-top:3px;
        }

        .status-layout{
            display:grid;
            grid-template-columns:minmax(0, 1.1fr) minmax(240px, .9fr);
            gap:18px;
            align-items:stretch;
            min-height:360px;
        }
        .status-visual{
            display:flex;
            flex-direction:column;
            gap:14px;
            min-width:0;
        }
        .status-chart-wrap{
            position:relative;
            flex:1 1 auto;
            min-height:250px;
        }
        .chart-overlay{
            position:absolute;
            inset:0;
            display:grid;
            place-items:center;
            pointer-events:none;
        }
        .chart-center-card{
    width:125px;
    height:125px;
    padding:10px;
    border-radius:18px;
    background:rgba(255,255,255,.88);
    border:1px solid rgba(231,225,247,.96);
    box-shadow:0 10px 20px rgba(73,45,150,.08);
    text-align:center;

    display:flex;
    flex-direction:column;
    justify-content:center;
    align-items:center;

    backdrop-filter:blur(12px);
}
        .chart-center-card .kicker{
    text-transform:uppercase;
    letter-spacing:.08em;
    font-size:.62rem;
    font-weight:800;
    color:#7580a3;
}
        .chart-center-card .count{
    font-size:1.7rem;
    line-height:1;
    font-weight:800;
    margin:3px 0;
    color:#24304d;
}
        .chart-center-card .sub{
    margin-top:2px;
    color:var(--muted);
    font-size:.68rem;
}
        .status-summary-grid{
            display:grid;
            gap:12px;
            align-content:start;
        }
        .status-summary-card{
            position:relative;
            border-radius:18px;
            background:#fff;
            border:1px solid #ece7f8;
            padding:14px 14px 14px 16px;
            box-shadow:0 10px 20px rgba(73,45,150,.05);
            min-width:0;
            overflow:hidden;
        }
        .status-summary-card::before{
            content:"";
            position:absolute;
            left:0; top:0; bottom:0;
            width:4px;
            background:#64748b;
        }
        .status-summary-card .kicker{
            font-size:.75rem;
            font-weight:800;
            letter-spacing:.08em;
            text-transform:uppercase;
            color:#77819b;
            margin-bottom:4px;
        }
        .status-summary-card .count{
            font-size:2rem;
            line-height:1;
            font-weight:800;
            color:#2b2f3a;
            letter-spacing:-.04em;
            margin-bottom:6px;
        }
        .status-summary-card .desc{
            font-size:.88rem;
            color:var(--muted);
            line-height:1.45;
        }
        .status-summary-card.approved::before{background:var(--green)}
        .status-summary-card.approved .count{color:#1f4f30}
        .status-summary-card.pending::before{background:var(--orange)}
        .status-summary-card.pending .count{color:#8c5c00}
        .status-summary-card.rejected::before{background:var(--danger)}
        .status-summary-card.rejected .count{color:#7f1d2d}

        .status-chip-row{display:flex;flex-wrap:wrap;gap:10px}
        .status-chip{
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:9px 12px;
            border-radius:999px;
            font-size:.86rem;
            font-weight:700;
            border:1px solid transparent;
            white-space:nowrap;
        }
        .status-chip strong{font-size:.95rem}
        .status-chip.approved{background:#edf9f2;color:var(--green);border-color:#d9f1e2}
        .status-chip.pending{background:#fff4d6;color:#a86f00;border-color:#ffe7ae}
        .status-chip.rejected{background:#feecef;color:#c63e52;border-color:#ffd3db}
        .status-note-strip{
            border-radius:18px;
            border:1px solid #e5eaf5;
            background:linear-gradient(90deg, #f8fbff, #ffffff);
            padding:14px 16px;
            color:#4d5870;
            font-size:.92rem;
            line-height:1.45;
            box-shadow:0 10px 20px rgba(73,45,150,.04);
        }
        .status-note-strip strong{color:#2f2358}

        .type-layout{
            display:flex;
            flex-direction:column;
            gap:16px;
            min-height:360px;
        }
        .type-chart-wrap{
            position:relative;
            flex:1 1 auto;
            min-height:260px;
        }

        .filter-row{
            display:grid;
            grid-template-columns:repeat(3, minmax(0, 1fr));
            gap:14px;
            width:100%;
        }
        .filter-row > div{min-width:0}
        .filter-row label{
            font-size:.85rem;
            font-weight:700;
            color:#384157;
            margin-bottom:6px;
        }
        .filter-row .form-control{
            height:48px;
            border-radius:14px;
            border:1px solid #e6e0f4;
            box-shadow:none;
            background:#fff;
            color:#24304d;
        }
        .filter-row .form-control:focus{
            border-color:#a78bfa;
            box-shadow:0 0 0 .2rem rgba(167,139,250,.15);
        }
        .filter-actions{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            margin-top:14px;
        }
        .btn-soft{
            border:none;
            border-radius:14px;
            padding:11px 18px;
            font-weight:800;
            display:inline-flex;
            align-items:center;
            gap:8px;
        }
        .btn-primary-soft{
            background:linear-gradient(90deg, #5b3df5, #2b7cff);
            color:#fff;
            box-shadow:0 16px 30px rgba(91,61,245,.18);
        }
        .btn-secondary-soft{
            background:#fff;
            color:#4b5563;
            border:1px solid #d8dff0;
        }

        .table-wrap{
            overflow:auto;
            border-radius:20px;
            border:1px solid var(--border);
            background:#fff;
        }
        table{margin:0}
        .table thead th{
            background:#faf8ff;
            border-bottom:1px solid var(--border);
            color:#372b67;
            font-size:.82rem;
            text-transform:uppercase;
            letter-spacing:.07em;
            white-space:nowrap;
        }
        .table td,.table th{
            padding:14px 14px;
            vertical-align:middle;
            color:#24304d;
        }
        .table tbody tr{
            transition:transform .25s ease, background .25s ease, box-shadow .25s ease;
        }
        .table tbody tr:hover{
            background:rgba(91,61,245,.03);
            transform:translateY(-1px);
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
        .b-success{background:#edf9f2;color:#1f9d5a}
        .b-warning{background:#fff4d6;color:#a86f00}
        .b-danger{background:#feecef;color:#c63e52}
        .b-gray{background:#edf0f7;color:#586178}
        .b-info{background:#eef5ff;color:#2b7cff}
        .b-purple{background:#f0ebff;color:#5b3df5}

        .summary-list{display:grid;gap:14px}
        .info-card{
            padding:18px;
            border-radius:22px;
            background:#fff;
            border:1px solid var(--border);
            box-shadow:var(--shadow-soft);
            opacity:0;
            transform:translateY(16px);
            transition:opacity .55s ease, transform .55s cubic-bezier(.16,1,.3,1), box-shadow .35s ease, border-color .35s ease;
        }
        .info-card.visible{opacity:1;transform:translateY(0)}
        .info-card:hover{
            transform:translateY(-4px);
            box-shadow:0 18px 34px rgba(73,45,150,.12);
            border-color:rgba(91,61,245,.24);
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
            font-weight:800;
            font-size:1.03rem;
            margin:0 0 10px;
            color:#24304d;
        }
        .info-card .meta{
            color:var(--muted);
            font-size:.92rem;
            line-height:1.5;
        }

        .notifs-dropdown{
            width:min(520px, 92vw);
            max-height:520px;
            overflow:auto;
            border-radius:20px;
            border:1px solid var(--border);
            padding:0;
            background:#fff;
            box-shadow:0 28px 70px rgba(73,45,150,.16);
        }
        .notif-header{
            padding:16px 18px;
            border-bottom:1px solid var(--border);
            background:linear-gradient(135deg,#fcfbff,#ffffff);
        }
        .notif-body{padding:12px}
        .notif-item{
            padding:14px;
            border:1px solid var(--border);
            border-radius:18px;
            margin-bottom:10px;
            background:#fff;
        }
        .notif-item:last-child{margin-bottom:0}
        .notif-item .title{
            font-weight:800;
            margin-bottom:4px;
            color:#24304d;
        }
        .notif-item .msg{
            color:var(--muted);
            font-size:.92rem;
            line-height:1.45;
        }
        .notif-item .time{
            margin-top:8px;
            color:#8891a7;
            font-size:.8rem;
        }

        .empty-state{
            padding:18px;
            background:#fbfaff;
            border:1px dashed #d7c9ff;
            border-radius:18px;
            color:var(--muted);
            text-align:center;
        }

        .chart-shell.type-shell .chart-shell-inner,
        .chart-shell.company-shell .chart-shell-inner,
        .chart-shell.division-shell .chart-shell-inner{
            padding:16px;
        }
        .chart-shell.company-shell .chart-box,
        .chart-shell.division-shell .chart-box{
            min-height:320px;
        }

        .fallback-chip-row{
            display:flex;
            flex-wrap:wrap;
            gap:8px;
        }
        .fallback-chip{
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:7px 11px;
            border-radius:999px;
            border:1px solid rgba(231,225,247,.95);
            background:rgba(255,255,255,.94);
            box-shadow:0 10px 18px rgba(73,45,150,.05);
            font-size:.82rem;
            font-weight:800;
            color:#2f3a57;
        }
        .fallback-dot{
            width:10px;height:10px;border-radius:50%;
            flex:0 0 auto;
            box-shadow:0 0 0 4px rgba(255,255,255,.88);
        }

        .chart-note{
            margin-top:4px;
            color:var(--muted);
            font-size:.92rem;
            line-height:1.45;
        }

        .reveal{
            opacity:0;
            transform:translateY(18px);
            transition:opacity .75s cubic-bezier(.16,1,.3,1), transform .75s cubic-bezier(.16,1,.3,1), filter .75s ease;
        }
        .reveal.visible{
            opacity:1;
            transform:translateY(0);
        }

        @keyframes orbFloat{
            0%,100%{transform:translate3d(0,0,0) scale(1)}
            50%{transform:translate3d(0,-18px,0) scale(1.05)}
        }
        @keyframes particleDrift{
            0%{transform:translate3d(0,0,0) scale(.9); opacity:.08}
            15%{opacity:.24}
            50%{transform:translate3d(120px,-140px,0) scale(1.2); opacity:.22}
            85%{opacity:.16}
            100%{transform:translate3d(260px,-280px,0) scale(.85); opacity:0}
        }
        @keyframes floatIcon{
            0%,100%{transform:translateY(0)}
            50%{transform:translateY(-4px)}
        }
        @keyframes blobDrift{
            0%,100%{transform:translate3d(0,0,0) scale(1)}
            50%{transform:translate3d(10px,-8px,0) scale(1.08)}
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
            .content-grid{
                grid-template-columns:1fr;
            }
            .stats-grid{
                grid-template-columns:repeat(2, minmax(0, 1fr));
            }
        }
        @media (max-width: 768px){
            .topbar-inner, .page{
                padding-left:16px;
                padding-right:16px;
            }
            .stats-grid{
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
            .filter-row{
                grid-template-columns:1fr;
            }
            .table td, .table th{
                padding:12px 10px;
                font-size:.92rem;
            }
            .status-layout{
                grid-template-columns:1fr;
            }
            .status-visual{
                gap:18px;
            }
            .status-chip-row, .legend-pills{
                width:100%;
            }
        }

        @media (prefers-reduced-motion: reduce){
            *, *::before, *::after{
                animation-duration:.001ms !important;
                animation-iteration-count:1 !important;
                transition-duration:.001ms !important;
                scroll-behavior:auto !important;
            }
        }
    
        /* ===== Admin layout overrides ===== */
        .dashboard-sections{
            display:grid;
            gap:22px;
        }
        .overview-grid{
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:22px;
        }
        .full-width-panel{
            width:100%;
        }
        .full-width-panel .chart-shell.company-shell .chart-box,
        .full-width-panel .chart-shell.division-shell .chart-box{
            min-height:430px;
        }
        .full-width-panel .chart-shell.type-shell .type-chart-wrap,
        .full-width-panel .chart-shell .chart-box{
            min-height:380px;
        }
        /* ===== Division Chart Scroll Animation ===== */

.division-shell{
    opacity:0;
    transform:translateY(60px);
    transition:all .9s cubic-bezier(.16,1,.3,1);
}

.division-shell.animate{
    opacity:1;
    transform:translateY(0);
}

.division-shell canvas{
    transition:transform .8s ease;
}

.division-shell.animate canvas{
    transform:scale(1);
}

.division-shell:not(.animate) canvas{
    transform:scale(.97);
}
        .insights-grid{
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:14px;
        }
        .notifications-list{
            display:grid;
            gap:14px;
        }
        .notification-card{
            padding:18px;
            border-radius:22px;
            background:#fff;
            border:1px solid var(--border);
            box-shadow:var(--shadow-soft);
            opacity:0;
            transform:translateY(14px);
            transition:opacity .55s ease, transform .55s cubic-bezier(.16,1,.3,1), box-shadow .35s ease, border-color .35s ease;
        }
        .notification-card.visible{
            opacity:1;
            transform:translateY(0);
        }
        .notification-card:hover{
            transform:translateY(-4px);
            box-shadow:0 18px 34px rgba(73,45,150,.12);
            border-color:rgba(91,61,245,.22);
        }
        .notification-top{
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:14px;
            margin-bottom:10px;
        }
        .notification-title{
            font-weight:800;
            font-size:1.02rem;
            color:#24304d;
            margin-bottom:6px;
        }
        .notification-meta{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            align-items:center;
            color:var(--muted);
            font-size:.84rem;
        }
        .notification-sender{
            font-weight:700;
            color:#4d5870;
        }
        .notification-time{
            padding-left:10px;
            border-left:1px solid rgba(148,163,184,.35);
        }
        .notification-message{
            color:var(--muted);
            font-size:.94rem;
            line-height:1.55;
        }
        .chart-shell .chart-box{
            min-height:360px;
        }
        .chart-shell.active{
            animation:chartGlow 3.6s ease-in-out infinite;
        }
        @keyframes chartGlow{
            0%,100%{box-shadow:0 0 0 1px rgba(123,97,255,.14), 0 0 22px rgba(91,61,245,.10), inset 0 1px 0 rgba(255,255,255,.85);}
            50%{box-shadow:0 0 0 1px rgba(123,97,255,.24), 0 0 34px rgba(91,61,245,.18), inset 0 1px 0 rgba(255,255,255,.85);}
        }
        @media (max-width: 1200px){
            .overview-grid,
            .insights-grid{
                grid-template-columns:1fr;
            }
            .full-width-panel .chart-shell.company-shell .chart-box,
            .full-width-panel .chart-shell.division-shell .chart-box,
            .full-width-panel .chart-shell.type-shell .type-chart-wrap,
            .full-width-panel .chart-shell .chart-box{
                min-height:320px;
            }
        }
        @media (max-width: 768px){
            .notification-top{
                flex-direction:column;
            }
            .notification-time{
                padding-left:0;
                border-left:0;
            }
        }
        
        .stat-teal{
    background:linear-gradient(135deg,#e8fffb,#d4fff6);
}

.stat-teal .icon{
    background:#00a88f;
}

.stat-teal .label,
.stat-teal .value{
    color:#04594d;
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
                <div class="name"><?php echo h($faculty_name); ?></div>
                <div class="small">Administrator</div>
            </div>
        </div>

        <nav class="nav">
            <div class="nav-label">Workspace</div>
            <a href="dashboard.php" class="active"><i class="fa-solid fa-house"></i>Dashboard</a>
            <a href="manage_students.php"><i class="fa-solid fa-users"></i>Manage Students</a>
            <a href="manage_faculty.php"><i class="fa-solid fa-user-tie"></i>Manage Faculty</a>
            <a href="all_internships.php"><i class="fa-solid fa-briefcase"></i>All Internships</a>
            <a href="student_evaluations.php"><i class="fa-solid fa-clipboard-check"></i>Student Evaluations</a>
            <a href="reports.php"><i class="fa-solid fa-chart-line"></i>Reports</a>
            <a href="manage_notifications.php"><i class="fa-solid fa-bell"></i>Notifications</a>

            <div class="account-bottom">
                <div class="nav-label">Account</div>
                <a href="change_password.php"><i class="fa-solid fa-key"></i>Change Password</a>
                <a href="settings.php"><i class="fa-solid fa-gear"></i>Settings</a>
                <a href="../logout.php"><i class="fa-solid fa-right-from-bracket"></i>Logout</a>
            </div>
        </nav>
    </aside>

    <main class="main">
        <div class="topbar reveal">
            <div class="topbar-inner">
                <div class="hero-title">
                    <div class="eyebrow">Welcome Back</div>
                    <h1>Admin Dashboard</h1>
                    <div class="sub">
                        <span>University Internship Analytics</span>
                        <span>Live Overview</span>
                    </div>
                </div>

                <div class="topbar-actions">
                    <div class="dropdown">
                        <button class="action-btn position-relative" data-bs-toggle="dropdown">
                            <i class="fa-regular fa-bell"></i>
                            Notifications
                        </button>

                        <div class="dropdown-menu dropdown-menu-end notifs-dropdown">
                            <div class="notif-header">
                                <strong>Notifications</strong>
                            </div>
                            <div class="notif-body">
                                <?php if (empty($notifications) && empty($faculty_notifications)): ?>
                                    <div class="empty-state">No notifications available.</div>
                                <?php else: ?>
                                    <?php foreach ($notifications as $notification): ?>
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

                                    <?php foreach ($faculty_notifications as $notification): ?>
                                        <div class="notif-item">
                                            <div class="d-flex justify-content-between align-items-start gap-2">
                                                <div class="title"><?php echo h($notification['title'] ?? 'Faculty Notice'); ?></div>
                                                <span class="status-badge b-info">Faculty</span>
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
                            <div class="name"><?php echo h($faculty_name); ?></div>
                            <div class="role">Administrator</div>
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
                            <div class="value"><span class="counter" data-target="<?php echo (int)$total_students; ?>">0</span></div>
                            <div class="note">Registered students</div>
                        </div>
                    </div>
                </div>

                <div class="stat-card stat-green reveal">
                    <div class="top">
                        <div class="icon"><i class="fa-solid fa-user-tie"></i></div>
                        <div>
                            <div class="label">Total Faculty</div>
                            <div class="value"><span class="counter" data-target="<?php echo (int)$total_faculty; ?>">0</span></div>
                            <div class="note">Active faculty members</div>
                        </div>
                    </div>
                </div>

                <div class="stat-card stat-blue reveal">
                    <div class="top">
                        <div class="icon"><i class="fa-solid fa-briefcase"></i></div>
                        <div>
                            <div class="label">Total Internships</div>
                            <div class="value"><span class="counter" data-target="<?php echo (int)$total_internships; ?>">0</span></div>
                            <div class="note">Latest internship records</div>
                        </div>
                    </div>
                </div>

                <div class="stat-card stat-gray reveal">
                    <div class="top">
                        <div class="icon"><i class="fa-solid fa-building"></i></div>
                        <div>
                            <div class="label">Companies</div>
                            <div class="value"><span class="counter" data-target="<?php echo (int)$total_companies; ?>">0</span></div>
                            <div class="note">Unique recruiters</div>
                        </div>
                    </div>
                </div>

                <div class="stat-card stat-violet reveal">
                    <div class="top">
                        <div class="icon"><i class="fa-solid fa-layer-group"></i></div>
                        <div>
                            <div class="label">Internship Types</div>
                            <div class="value"><span class="counter" data-target="<?php echo (int)$total_types; ?>">0</span></div>
                            <div class="note">Distinct categories</div>
                        </div>
                    </div>
                </div>

                <div class="stat-card stat-violet reveal">
                    <div class="top">
                        <div class="icon"><i class="fa-solid fa-chart-line"></i></div>
                        <div>
                            <div class="label">Approval Rate</div>
                            <div class="value"><span class="counter" data-target="<?php echo (float)$approval_rate; ?>" data-decimals="2">0</span><span class="unit">%</span></div>
                            <div class="note"><?php echo number_format((float)$approval_rate, 2); ?>% approved</div>
                        </div>
                    </div>
                </div>

                <div class="stat-card stat-green reveal">
                    <div class="top">
                        <div class="icon"><i class="fa-solid fa-circle-check"></i></div>
                        <div>
                            <div class="label">Approved</div>
                            <div class="value"><span class="counter" data-target="<?php echo (int)$approved_count; ?>">0</span></div>
                            <div class="note">Final approvals</div>
                        </div>
                    </div>
                </div>

                <div class="stat-card stat-orange reveal">
                    <div class="top">
                        <div class="icon"><i class="fa-regular fa-clock"></i></div>
                        <div>
                            <div class="label">Pending</div>
                            <div class="value"><span class="counter" data-target="<?php echo (int)$pending_count; ?>">0</span></div>
                            <div class="note">Awaiting review</div>
                        </div>
                    </div>
                </div>

                <div class="stat-card stat-gray reveal">
                    <div class="top">
                        <div class="icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                        <div>
                            <div class="label">Rejected</div>
                            <div class="value"><span class="counter" data-target="<?php echo (int)$rejected_count; ?>">0</span></div>
                            <div class="note">Rejected internships</div>
                        </div>
                    </div>
                </div>
                
                <!-- No Internship Details -->
<div class="stat-card stat-red reveal">
    <div class="top">
        <div class="icon">
            <i class="fa-solid fa-user-slash"></i>
        </div>
        <div>
            <div class="label">No Internship Details</div>
            <div class="value">
                <span class="counter"
      data-target="<?php echo (int)$no_details_count; ?>">0</span>
            </div>
            <div class="note">Students without internship details</div>
        </div>
    </div>
</div>

</div> <!-- stats-grid -->
            </div>
            
            

            
            <div class="dashboard-sections">
                <div class="overview-grid">
                    <div class="panel reveal">
                        <div class="section-head">
                            <div>
                                <h2>Internship Status</h2>
                                <p>Overall approval split across internships.</p>
                            </div>
                        </div>

                        <div class="status-layout">
                            <div class="status-visual">
                                <div class="chart-shell active">
                                    <div class="chart-shell-inner">
                                        <div class="status-chart-wrap">
                                            <canvas id="statusChart"></canvas>
                                            <div class="chart-overlay">
                                                <div class="chart-center-card">
                                                    <div class="kicker">Total Records</div>
                                                    <div class="count" id="statusTotalCenter"><?php echo (int)$total_internships; ?></div>
                                                    <div class="sub">latest submissions</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="status-chip-row">
                                    <span class="status-chip approved">Approved <strong><?php echo (int)$approved_count; ?></strong></span>
                                    <span class="status-chip pending">Pending <strong><?php echo (int)$pending_count; ?></strong></span>
                                    <span class="status-chip rejected">Rejected <strong><?php echo (int)$rejected_count; ?></strong></span>
                                </div>

                                <div class="status-note-strip">
                                    <strong>Overview:</strong> current internship records are evaluated using the latest submission per student, which keeps the analytics clean and accurate for admin review.
                                </div>
                            </div>

                            <div class="status-summary-grid">
                                <div class="status-summary-card approved">
                                    <div class="kicker">Approved</div>
                                    <div class="count"><?php echo (int)$approved_count; ?></div>
                                    <div class="desc">Applications successfully approved.</div>
                                </div>
                                <div class="status-summary-card pending">
                                    <div class="kicker">Pending</div>
                                    <div class="count"><?php echo (int)$pending_count; ?></div>
                                    <div class="desc">Applications waiting for faculty review.</div>
                                </div>
                                <div class="status-summary-card rejected">
                                    <div class="kicker">Rejected</div>
                                    <div class="count"><?php echo (int)$rejected_count; ?></div>
                                    <div class="desc">Applications returned for correction.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="panel reveal">
                        <div class="section-head">
                            <div>
                                <h2>Internship Type Distribution</h2>
                                <p>Distribution of current internship types.</p>
                            </div>
                        </div>

                        <div class="type-layout">
                            <div class="chart-shell type-shell">
                                <div class="chart-shell-inner">
                                    <div class="type-chart-wrap">
                                        <canvas id="typeChart"></canvas>
                                    </div>
                                    <div class="legend-pills" id="typeLegend"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="panel reveal full-width-panel">
                    <div class="section-head">
                        <div>
                            <h2>Division Performance</h2>
                            <p>Approved, pending and rejected split by division.</p>
                        </div>
                    </div>

                    <div class="chart-shell division-shell active">
                        <div class="chart-shell-inner">
                            <div class="chart-box">
                                <canvas id="divisionChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="panel reveal full-width-panel">
                    <div class="section-head">
                        <div>
                            <h2>Top Recruiting Companies</h2>
                            <p>Company distribution inside your university.</p>
                        </div>
                    </div>

                    <div class="chart-shell company-shell active">
                        <div class="chart-shell-inner">
                            <div class="chart-box">
                                <canvas id="companyChart"></canvas>
                            </div>
                            <div class="fallback-chip-row" id="companyLegend"></div>
                        </div>
                    </div>
                </div>

                <div class="panel reveal full-width-panel">
                    <div class="section-head">
                        <div>
                            <h2>Division Analytics Search</h2>
                            <p>Filter students by division, internship type and company.</p>
                        </div>
                    </div>

                    <div class="filter-row">
                        <div>
                            <label for="divisionFilter">Division</label>
                            <select id="divisionFilter" class="form-control">
                                <option value="">All Divisions</option>
                                <?php foreach ($division_options as $opt): ?>
                                    <option value="<?php echo h($opt); ?>"><?php echo h($opt); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="typeFilter">Internship Type</label>
                            <select id="typeFilter" class="form-control">
                                <option value="">All Types</option>
                                <?php foreach ($type_options as $opt): ?>
                                    <option value="<?php echo h($opt); ?>"><?php echo h($opt); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="companyFilter">Company</label>
                            <select id="companyFilter" class="form-control">
                                <option value="">All Companies</option>
                                <?php foreach ($company_options as $opt): ?>
                                    <option value="<?php echo h($opt); ?>"><?php echo h($opt); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="filter-actions">
                        <button type="button" class="btn-soft btn-primary-soft" onclick="loadAnalytics()"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                        <button type="button" class="btn-soft btn-secondary-soft" onclick="resetFilters()"><i class="fa-solid fa-rotate-left"></i> Reset</button>
                    </div>

                    <div class="mt-4">
                        <div id="analyticsSummary" class="empty-state">Select filters and click Search</div>
                        <div class="table-wrap mt-3">
                            <table class="table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Roll No</th>
                                        <th>Student</th>
                                        <th>Division</th>
                                        <th>Company</th>
                                        <th>Type</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody id="analyticsTableBody">
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">No results yet. Use filters above to search.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="panel reveal full-width-panel">
                    <div class="section-head">
                        <div>
                            <h2>Key Insights</h2>
                            <p>Quick summary for the whole university.</p>
                        </div>
                    </div>

                    <div class="summary-list insights-grid">
                        <div class="info-card">
                            <div class="label">Top Division</div>
                            <div class="value"><?php echo h($top_division_name); ?></div>
                            <div class="meta"><?php echo (int)$top_division_students; ?> students with latest internship entries.</div>
                        </div>

                        <div class="info-card">
                            <div class="label">Top Company</div>
                            <div class="value"><?php echo h($top_company_name); ?></div>
                            <div class="meta"><?php echo (int)$top_company_students; ?> students associated with this company.</div>
                        </div>

                        <div class="info-card">
                            <div class="label">Total Students</div>
                            <div class="value"><?php echo (int)$total_students; ?></div>
                            <div class="meta">Registered students across the university.</div>
                        </div>

                        <div class="info-card">
                            <div class="label">Approval Rate</div>
                            <div class="value"><?php echo number_format((float)$approval_rate, 2); ?>%</div>
                            <div class="meta">Approved internships among current records.</div>
                        </div>
                    </div>
                </div>

                <div class="panel reveal full-width-panel">
                    <div class="section-head">
                        <div>
                            <h2>Latest Notifications</h2>
                            <p>Recent updates from admin and faculty.</p>
                        </div>
                    </div>

                    <?php if (empty($latest_notifications)): ?>
                        <div class="empty-state">No notifications available.</div>
                    <?php else: ?>
                        <div class="notifications-list">
                            <?php foreach ($latest_notifications as $note): ?>
                                <div class="notification-card">
                                    <div class="notification-top">
                                        <div>
                                            <div class="notification-title"><?php echo h($note['title'] ?? 'Notification'); ?></div>
                                            <div class="notification-meta">
                                                <span class="notification-sender">From: <?php echo h($note['sender'] ?? 'Faculty Member'); ?></span>
                                                <span class="notification-time"><?php echo h(notificationTimeLabel($note['created_at'] ?? null)); ?></span>
                                            </div>
                                        </div>
                                        <span class="status-badge <?php echo (($note['type'] ?? '') === 'faculty') ? 'b-info' : 'b-purple'; ?>">
                                            <?php echo (($note['type'] ?? '') === 'faculty') ? 'Faculty' : 'Admin'; ?>
                                        </span>
                                    </div>

                                    <div class="notification-message"><?php echo h(shortText($note['message'] ?? '', 240)); ?></div>

                                    <?php if (!empty($note['attachment'])): ?>
                                        <div class="mt-3">
                                            <a class="action-btn" href="../uploads/notifications/<?php echo h($note['attachment']); ?>" target="_blank">
                                                <i class="fa-solid fa-download"></i> Attachment
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>
</main>
</div>

<script>
const chartData = {
    status: {
        labels: ['Approved', 'Pending', 'Rejected', 'No Internship'],
        values: [
            <?php echo (int)$approved_count; ?>,
            <?php echo (int)$pending_count; ?>,
            <?php echo (int)$rejected_count; ?>,
            <?php echo (int)$no_details_count; ?>
        ]
    },
    types: {
        labels: <?php echo json_encode(array_values($type_labels), JSON_UNESCAPED_UNICODE); ?>,
        values: <?php echo json_encode(array_values($type_values), JSON_UNESCAPED_UNICODE); ?>
    },
    companies: {
        labels: <?php echo json_encode(array_values($company_labels), JSON_UNESCAPED_UNICODE); ?>,
        values: <?php echo json_encode(array_values($company_values), JSON_UNESCAPED_UNICODE); ?>
    },
    divisions: {
        labels: <?php echo json_encode(array_values($division_labels), JSON_UNESCAPED_UNICODE); ?>,
        approved: <?php echo json_encode(array_values($division_approved), JSON_UNESCAPED_UNICODE); ?>,
        pending: <?php echo json_encode(array_values($division_pending), JSON_UNESCAPED_UNICODE); ?>,
        rejected: <?php echo json_encode(array_values($division_rejected), JSON_UNESCAPED_UNICODE); ?>
    }
};

const colors = {
    purple: '#5b3df5',
    blue: '#2b7cff',
    cyan: '#00c8ff',
    pink: '#ff4fd8',
    green: '#1f9d5a',
    orange: '#f3b61f',
    red: '#e25563',
    gray: '#64748b'
};

function formatNumber(value, decimals = 0) {
    const num = Number(value || 0);
    return num.toLocaleString(undefined, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
}

function animateCounters() {
    document.querySelectorAll('.counter').forEach(el => {
        const target = parseFloat(el.dataset.target || '0');
        const decimals = parseInt(el.dataset.decimals || '0', 10);
        const duration = 1200;
        const start = performance.now();

        function tick(now) {
            const progress = Math.min((now - start) / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            const value = target * eased;
            el.textContent = formatNumber(value, decimals);
            if (progress < 1) requestAnimationFrame(tick);
        }
        requestAnimationFrame(tick);
    });
}

function makeGradient(ctx, colorStops) {
    const g = ctx.createLinearGradient(0, 0, 0, 260);
    colorStops.forEach(([pos, color]) => g.addColorStop(pos, color));
    return g;
}

function createCharts() {
    const statusCtx = document.getElementById('statusChart');
    const typeCtx = document.getElementById('typeChart');
    const companyCtx = document.getElementById('companyChart');
    const divisionCtx = document.getElementById('divisionChart');

    if (statusCtx) {
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: chartData.status.labels,
                datasets: [{
                    data: chartData.status.values,
                    backgroundColor: [
                        colors.green,
                        colors.orange,
                        colors.red,
                        '#9ca3af'
                    ],
                    borderWidth: 0,
                    hoverOffset: 6
                }]
            },
            options: {
        
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(20,25,40,.95)',
                        padding: 12,
                        titleFont: { size: 13, weight: '700' },
                        bodyFont: { size: 12 }
                    }
                }
            }
        });
    }

    if (typeCtx) {
        const typeColors = ['#5b3df5', '#2b7cff', '#1f9d5a', '#f3b61f', '#ff4fd8', '#64748b', '#00c8ff', '#e25563'];
        new Chart(typeCtx, {
            type: 'doughnut',
            data: {
                labels: chartData.types.labels,
                datasets: [{
                    data: chartData.types.values,
                    backgroundColor: chartData.types.labels.map((_, i) => typeColors[i % typeColors.length]),
                    borderWidth: 0,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '66%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(20,25,40,.95)',
                        padding: 12
                    }
                }
            }
        });

        const typeLegend = document.getElementById('typeLegend');
        if (typeLegend) {
            typeLegend.innerHTML = chartData.types.labels.map((label, i) => `
                <span class="legend-pill">
                    <span class="dot" style="background:${typeColors[i % typeColors.length]}"></span>
                    <span class="txt">${label}<span>${formatNumber(chartData.types.values[i] || 0)} records</span></span>
                </span>
            `).join('');
        }
    }

    if (companyCtx) {
        const topLabels = chartData.companies.labels;
        const topValues = chartData.companies.values;
        new Chart(companyCtx, {
            type: 'bar',
            data: {
                labels: topLabels,
                datasets: [{
                    label: 'Students',
                    data: topValues,
                    borderRadius: 999,
                    borderSkipped: false,
                    backgroundColor: (context) => {
                        const chart = context.chart;
                        const {ctx, chartArea} = chart;
                        if (!chartArea) {
                            return colors.blue;
                        }
                        const gradient = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0);
                        gradient.addColorStop(0, '#00c8ff');
                        gradient.addColorStop(0.55, '#5b3df5');
                        gradient.addColorStop(1, '#ff4fd8');
                        return gradient;
                    }
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(20,25,40,.95)',
                        padding: 12
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: { color: 'rgba(123,97,255,.08)' },
                        ticks: { color: '#667085' }
                    },
                    y: {
                        grid: { display: false },
                        ticks: { color: '#344054', font: { weight: '600' } }
                    }
                }
            }
        });

        const companyLegend = document.getElementById('companyLegend');
        if (companyLegend) {
            companyLegend.innerHTML = topLabels.map((label, i) => `
                <span class="fallback-chip">
                    <span class="fallback-dot" style="background:${i % 2 === 0 ? '#2b7cff' : '#5b3df5'}"></span>
                    ${label}
                    <strong>${formatNumber(topValues[i] || 0)}</strong>
                </span>
            `).join('');
        }
    }

    if (divisionCtx) {
        window.divisionChart = new Chart(divisionCtx,{
            type: 'bar',
            data: {
                labels: chartData.divisions.labels,
                datasets: [
                    {
                        label: 'Approved',
                        data: chartData.divisions.approved,
                        backgroundColor: '#1f9d5a',
                        borderRadius: 999,
                        borderSkipped: false,
                        stack: 'status'
                    },
                    {
                        label: 'Pending',
                        data: chartData.divisions.pending,
                        backgroundColor: '#f3b61f',
                        borderRadius: 999,
                        borderSkipped: false,
                        stack: 'status'
                    },
                    {
                        label: 'Rejected',
                        data: chartData.divisions.rejected,
                        backgroundColor: '#e25563',
                        borderRadius: 999,
                        borderSkipped: false,
                        stack: 'status'
                    }
                ]
            },
            options: {
                animation:{
    duration:1800,
    easing:'easeOutQuart'
},
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            color: '#344054',
                            boxWidth: 10,
                            font: { weight: '700' }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(20,25,40,.95)',
                        padding: 12
                    }
                },
                scales: {
                    x: {
                        stacked: true,
                        beginAtZero: true,
                        grid: { color: 'rgba(123,97,255,.08)' },
                        ticks: { color: '#667085' }
                    },
                    y: {
                        stacked: true,
                        grid: { display: false },
                        ticks: { color: '#344054', font: { weight: '600' } }
                    }
                }
            }
        });
    }
}

function observeReveal() {
    const els = document.querySelectorAll('.reveal, .stat-card, .panel, .info-card');
    const io = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
                io.unobserve(entry.target);
            }
        });
    }, { threshold: 0.12 });

    els.forEach(el => io.observe(el));
}

/* ===== Division Chart Scroll Animation ===== */

const divisionSection = document.querySelector('.division-shell');

if(divisionSection){

    const divisionObserver = new IntersectionObserver((entries)=>{

        entries.forEach(entry=>{

            if(entry.isIntersecting){

                divisionSection.classList.add('animate');

                if(window.divisionChart){

                    window.divisionChart.update();
                }

                divisionObserver.unobserve(divisionSection);

            }

        });

    },{

        threshold:0.35

    });

    divisionObserver.observe(divisionSection);

}

function updateScrollProgress() {
    const scrollTop = window.scrollY || document.documentElement.scrollTop;
    const docHeight = document.documentElement.scrollHeight - window.innerHeight;
    const p = docHeight > 0 ? (scrollTop / docHeight) * 100 : 0;
    const bar = document.getElementById('topProgress');
    if (bar) bar.style.width = p + '%';
}

async function loadAnalytics() {
    const division = document.getElementById('divisionFilter')?.value || '';
    const type = document.getElementById('typeFilter')?.value || '';
    const company = document.getElementById('companyFilter')?.value || '';
    const summary = document.getElementById('analyticsSummary');
    const tbody = document.getElementById('analyticsTableBody');

    if (summary) summary.textContent = 'Loading...';
    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">Loading results...</td></tr>';
    }

    const url = new URL(window.location.href);
    url.searchParams.set('ajax', 'analytics');
    if (division) url.searchParams.set('division', division); else url.searchParams.delete('division');
    if (type) url.searchParams.set('type', type); else url.searchParams.delete('type');
    if (company) url.searchParams.set('company', company); else url.searchParams.delete('company');

    try {
        const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' }});
        const data = await res.json();

        if (summary) {
            summary.textContent = `Showing ${formatNumber(data.total_records)} records | Approved: ${formatNumber(data.approved)} | Pending: ${formatNumber(data.pending)} | Rejected: ${formatNumber(data.rejected)}`;
        }

        if (tbody) {
            if (!data.records || data.records.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">No results found for selected filters.</td></tr>';
            } else {
                tbody.innerHTML = data.records.map(r => `
                    <tr>
                        <td>${escapeHtml(r.roll_no || '-')}</td>
                        <td>${escapeHtml(r.student_name || '-')}</td>
                        <td>${escapeHtml(r.division || '-')}</td>
                        <td>${escapeHtml(r.company_name || '-')}</td>
                        <td>${escapeHtml(r.internship_type || '-')}</td>
                        <td>${badgeHtml(r.status || 'Pending')}</td>
                    </tr>
                `).join('');
            }
        }
    } catch (e) {
        if (summary) summary.textContent = 'Unable to load analytics right now.';
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger py-4">Failed to load data.</td></tr>';
        }
        console.error(e);
    }
}

function resetFilters() {
    const ids = ['divisionFilter', 'typeFilter', 'companyFilter'];
    ids.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    const summary = document.getElementById('analyticsSummary');
    const tbody = document.getElementById('analyticsTableBody');
    if (summary) summary.textContent = 'Select filters and click Search';
    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">No results yet. Use filters above to search.</td></tr>';
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text ?? '';
    return div.innerHTML;
}

function badgeHtml(status) {
    const s = String(status || 'Pending');
    const cls = s === 'Approved' ? 'b-success' : s === 'Pending' ? 'b-warning' : s === 'Rejected' ? 'b-danger' : 'b-gray';
    return `<span class="status-badge ${cls}">${escapeHtml(s)}</span>`;
}

document.addEventListener('DOMContentLoaded', () => {
    animateCounters();
    observeReveal();
    createCharts();
    updateScrollProgress();
    window.addEventListener('scroll', updateScrollProgress, { passive: true });
});

window.addEventListener('load', () => {
    document.querySelectorAll('.stat-card, .panel, .info-card, .notification-card').forEach(el => {
        el.classList.add('visible');
    });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
