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


// ---------------------------------------------------------
// AI TECH DOMAIN ANALYTICS
// ---------------------------------------------------------
$domain_labels = [];
$domain_values = [];
$domains_res = runQuery($conn, "
    SELECT TRIM(dc.assigned_domain) AS dname, COUNT(*) AS total 
    FROM internships i 
    JOIN users u ON i.student_id = u.id 
    LEFT JOIN domain_classifications dc ON dc.enrollment_no = u.enrollment_no 
    WHERE i.status='Approved' 
      AND dc.assigned_domain IS NOT NULL 
      AND dc.assigned_domain != 'Needs Verification'
    GROUP BY TRIM(dc.assigned_domain) 
    ORDER BY total DESC 
    LIMIT 6
");
while ($r = mysqli_fetch_assoc($domains_res)) {
    if (trim($r['dname']) !== '') {
        $domain_labels[] = trim($r['dname']);
        $domain_values[] = (int)$r['total'];
    }
}

// FETCH ACTION CENTER COUNT FOR BADGE
$action_center_count = 0;
$ac_res1 = @mysqli_query($conn, "SELECT COUNT(*) as c FROM internships WHERE status = 'Pending'");
if($ac_res1) $action_center_count += mysqli_fetch_assoc($ac_res1)['c'];
$ac_res2 = @mysqli_query($conn, "SELECT COUNT(*) as c FROM weekly_updates WHERE review_status = 'Pending'");
if($ac_res2) $action_center_count += mysqli_fetch_assoc($ac_res2)['c'];
$ac_res3 = @mysqli_query($conn, "SELECT COUNT(*) as c FROM internships WHERE status = 'Rejected'");
if($ac_res3) $action_center_count += mysqli_fetch_assoc($ac_res3)['c'];

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

// ---------------------------------------------------------
// DATA FIX: Include domain_classifications for unified data
// ---------------------------------------------------------
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
        LEFT JOIN domain_classifications dc
            ON dc.enrollment_no = u.roll_no
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
                AND TRIM(IFNULL(COALESCE(dc.assigned_domain, i.internship_type),'')) = ''
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

// Fixed Types query to match TY students page logic
$types_result = runQuery(
    $conn,
    "SELECT COUNT(DISTINCT COALESCE(dc.assigned_domain, i.internship_type)) AS total
     $latest_from
     $latest_where
     AND COALESCE(dc.assigned_domain, i.internship_type) IS NOT NULL
     AND TRIM(COALESCE(dc.assigned_domain, i.internship_type)) <> ''"
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

/* TYPES FIXED TO USE DOMAIN CLASSIFICATIONS */
$type_rows = fetchAllAssoc(
    $conn,
    "SELECT
        TRIM(COALESCE(dc.assigned_domain, i.internship_type)) AS internship_type,
        COUNT(*) AS total
     $latest_from
     $latest_where
     AND i.status='Approved'
     AND COALESCE(dc.assigned_domain, i.internship_type) IS NOT NULL
     AND TRIM(COALESCE(dc.assigned_domain, i.internship_type)) <> ''
     GROUP BY TRIM(COALESCE(dc.assigned_domain, i.internship_type))
     ORDER BY total DESC"
);
$type_labels = [];
$type_values = [];
foreach ($type_rows as $row)
{
    $name = trim($row['internship_type']);
    if($name == "Training + Mini Project Internship") {
        $name = "Training + Mini Project";
    }
    if($name == "Needs Verification" || $name == "Not Applicable") {
        continue;
    }
    
    $index = array_search($name, $type_labels);
    if($index !== false) {
        $type_values[$index] += (int)$row['total'];
    } else {
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
     $latest_from
     $latest_where
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
        COALESCE(NULLIF(TRIM(COALESCE(dc.assigned_domain, i.internship_type)), ''), '-') AS internship_type,
        COALESCE(NULLIF(TRIM(i.company_name), ''), '-') AS company_name,
        COALESCE(NULLIF(TRIM(i.mentor_name), ''), '-') AS mentor_name,
        COALESCE(i.created_at, u.created_at) AS created_at
     $latest_from
     $latest_where
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
    "SELECT DISTINCT COALESCE(dc.assigned_domain, i.internship_type) AS internship_type
     $latest_from
     $latest_where
     AND COALESCE(dc.assigned_domain, i.internship_type) IS NOT NULL 
     AND TRIM(COALESCE(dc.assigned_domain, i.internship_type)) <> ''
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
     $latest_from
     $latest_where
     AND company_name IS NOT NULL AND TRIM(company_name) <> ''
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
     $latest_from
     $latest_where
     AND i.id IS NOT NULL
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
    
    $conditions = ["u.role = 'student'"];
    $params = [];
    $types = '';
    if ($divisionFilter !== '') {
        $conditions[] = 'TRIM(u.division) = ?';
        $params[] = $divisionFilter;
        $types .= 's';
    }
    if ($typeFilter !== '') {
        $conditions[] = 'TRIM(COALESCE(dc.assigned_domain, i.internship_type)) = ?';
        $params[] = $typeFilter;
        $types .= 's';
    }
    if ($companyFilter !== '') {
        $conditions[] = 'TRIM(i.company_name) = ?';
        $params[] = $companyFilter;
        $types .= 's';
    }
    
    $sql = "
        SELECT
            u.roll_no,
            COALESCE(NULLIF(TRIM(u.full_name), ''), CONCAT('Student #', u.id)) AS student_name,
            COALESCE(NULLIF(TRIM(u.division), ''), '-') AS division,
            COALESCE(NULLIF(TRIM(i.company_name), ''), '-') AS company_name,
            COALESCE(NULLIF(TRIM(COALESCE(dc.assigned_domain, i.internship_type)), ''), '-') AS internship_type,
            COALESCE(NULLIF(TRIM(i.status), ''), 'Pending') AS status,
            COALESCE(i.created_at, u.created_at) AS created_at
        FROM users u
        LEFT JOIN internships i
            ON i.id = (
                SELECT id
                FROM internships
                WHERE student_id = u.id
                ORDER BY created_at DESC, id DESC
                LIMIT 1
            )
        LEFT JOIN domain_classifications dc
            ON dc.enrollment_no = u.roll_no
        WHERE " . implode(' AND ', $conditions) . "
        ORDER BY CAST(u.roll_no AS UNSIGNED), u.full_name ASC
        LIMIT 100
    ";
    $records = [];
    $statusCounts = ['Approved' => 0, 'Pending' => 0, 'Rejected' => 0, 'No Internship' => 0];
    $dbError = null;
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        if ($types !== '') {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
        }
        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
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
        } else {
            $dbError = mysqli_stmt_error($stmt);
        }
        mysqli_stmt_close($stmt);
    } else {
        $dbError = mysqli_error($conn);
    }
    $response = [
        'ok' => true,
        'total_records' => count($records),
        'approved' => $statusCounts['Approved'] ?? 0,
        'pending' => $statusCounts['Pending'] ?? 0,
        'rejected' => $statusCounts['Rejected'] ?? 0,
        'no_internship' => $statusCounts['No Internship'] ?? 0,
        'records' => $records,
    ];
    if ($dbError !== null) {
        $response['ok'] = false;
        $response['error'] = $dbError;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
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
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    <style>
        :root {
            --sidebar-width: 280px;
            --primary: #5b3df5;
            --bg-color: #f3f4f6;
            --text-main: #111827;
            --text-muted: #6b7280;
            --card-bg: rgba(255, 255, 255, 0.95);
        }
        
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #f3f4f6;
            background-image: radial-gradient(circle at 10% 20%, rgba(91, 61, 245, 0.03) 0%, transparent 20%), 
                              radial-gradient(circle at 90% 80%, rgba(16, 185, 129, 0.03) 0%, transparent 20%);
            background-attachment: fixed;
            color: var(--text-main);
            overflow-x: hidden;
        }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        a { text-decoration: none; }
        
        .app {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }
        
        /* SIDEBAR (Kept exactly as requested) */
        .sidebar {
            width: var(--sidebar-width);
            min-height: 100vh;
            position: fixed;
            left: 0; top: 0;
            background: linear-gradient(180deg, #3f0f68 0%, #6d1a88 48%, #4a1474 100%);
            color: #fff;
            display: flex;
            flex-direction: column;
            z-index: 100;
            transition: transform 0.3s ease;
        }
        .brand {
            padding: 24px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }
        .brand img {
            width: 100px;
            margin-bottom: 12px;
            border-radius: 8px;
            background: #fff;
            padding: 4px;
        }
        .brand .title { font-weight: 800; font-size: 1.1rem; letter-spacing: 0.5px; }
        .brand .subtitle { font-size: 0.7rem; letter-spacing: 2px; color: rgba(255,255,255,0.6); margin-top: 4px; }
        
        .nav {
            padding: 16px 12px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            overflow-y: auto;
        }
        .nav a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: rgba(255,255,255,0.8);
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.2s;
        }
        .nav a i { width: 20px; text-align: center; font-size: 1.1rem; }
        .nav a:hover {
            background: rgba(255,255,255,0.1);
            color: #fff;
            transform: translateX(4px);
        }
        .nav a.active {
            background: linear-gradient(90deg, #f26d21, #cf1e71);
            color: #fff;
            box-shadow: 0 4px 12px rgba(207,30,113,0.3);
        }
        
        .sidebar-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,0.6);
            z-index: 90;
            backdrop-filter: blur(4px);
        }
        
        /* MAIN AREA */
        .main {
            margin-left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width));
            display: flex;
            flex-direction: column;
        }
        
        /* TOPBAR - Super Clean Glassmorphism */
        .topbar {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.4);
            position: sticky;
            top: 0;
            z-index: 50;
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.03);
        }
        .topbar-inner {
            padding: 16px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .mobile-menu-toggle {
            display: none;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            width: 40px;
            height: 40px;
            align-items: center;
            justify-content: center;
            color: #475569;
            cursor: pointer;
        }
        .hero-title h1 {
            margin: 0;
            font-size: 1.6rem;
            font-weight: 800;
            color: #111827;
            background: linear-gradient(90deg, #111827, #4f46e5);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .hero-title .sub {
            font-size: 0.9rem;
            color: #6b7280;
            font-weight: 500;
            margin-top: 2px;
        }
        
        /* ANIMATIONS */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-up {
            animation: fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            opacity: 0;
        }
        
        /* DASHBOARD CONTENT */
        .page { padding: 32px; }
        
        /* STATS GRID - Modern Animated Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 24px;
            margin-bottom: 40px;
        }
        .stat-card {
            background: var(--card-bg);
            backdrop-filter: blur(10px);
            border-radius: 24px;
            padding: 24px;
            border: 1px solid rgba(255, 255, 255, 0.8);
            display: flex;
            flex-direction: column;
            gap: 16px;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01);
            position: relative;
            overflow: hidden;
            z-index: 1;
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.4) 0%, rgba(255,255,255,0) 100%);
            z-index: -1;
            border-radius: 24px;
        }
        .stat-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.1), 0 10px 20px -10px rgba(0, 0, 0, 0.04);
            border-color: rgba(255, 255, 255, 1);
        }
        
        .stat-card .icon-wrapper {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .stat-card .icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: #fff;
            box-shadow: 0 8px 16px -4px rgba(0,0,0,0.15);
            transition: transform 0.3s ease;
        }
        .stat-card:hover .icon {
            transform: scale(1.1) rotate(5deg);
        }
        
        .stat-card .trend {
            font-size: 0.8rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
        }
        
        .stat-card .label {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-card .value {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--text-main);
            line-height: 1;
            margin-top: 4px;
        }
        .stat-card .note {
            font-size: 0.8rem;
            color: #94a3b8;
            margin-top: 4px;
            font-weight: 500;
        }
        
        /* Vibrant Gradient Icons */
        .stat-purple .icon { background: linear-gradient(135deg, #8b5cf6, #6d28d9); box-shadow: 0 8px 16px rgba(139, 92, 246, 0.3); }
        .stat-green .icon { background: linear-gradient(135deg, #10b981, #059669); box-shadow: 0 8px 16px rgba(16, 185, 129, 0.3); }
        .stat-blue .icon { background: linear-gradient(135deg, #3b82f6, #2563eb); box-shadow: 0 8px 16px rgba(59, 130, 246, 0.3); }
        .stat-dark .icon { background: linear-gradient(135deg, #334155, #0f172a); box-shadow: 0 8px 16px rgba(51, 65, 85, 0.3); }
        .stat-violet .icon { background: linear-gradient(135deg, #d946ef, #c026d3); box-shadow: 0 8px 16px rgba(217, 70, 239, 0.3); }
        .stat-orange .icon { background: linear-gradient(135deg, #f59e0b, #d97706); box-shadow: 0 8px 16px rgba(245, 158, 11, 0.3); }
        .stat-red .icon { background: linear-gradient(135deg, #f43f5e, #e11d48); box-shadow: 0 8px 16px rgba(244, 63, 94, 0.3); }
        .stat-cyan .icon { background: linear-gradient(135deg, #06b6d4, #0891b2); box-shadow: 0 8px 16px rgba(6, 182, 212, 0.3); }
        
        /* PANELS - Modern Glass Style */
        .dashboard-sections {
            display: flex;
            flex-direction: column;
            gap: 32px;
        }
        .overview-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 32px;
        }
        .panel {
            background: var(--card-bg);
            backdrop-filter: blur(10px);
            border-radius: 24px;
            padding: 32px;
            border: 1px solid rgba(255, 255, 255, 0.8);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05);
            transition: box-shadow 0.3s ease;
        }
        .panel:hover {
            box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.1);
        }
        .section-head { margin-bottom: 28px; }
        .section-head h2 {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--text-main);
            margin: 0 0 6px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .section-head h2::before {
            content: '';
            display: block;
            width: 12px;
            height: 24px;
            background: linear-gradient(180deg, #4f46e5, #06b6d4);
            border-radius: 4px;
        }
        .section-head p {
            font-size: 0.95rem;
            color: var(--text-muted);
            margin: 0;
        }
        
        .chart-box {
            position: relative;
            height: 320px;
            width: 100%;
        }
        
        /* STATUS SUMMARY */
        .status-layout {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        .status-summary-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }
        .status-summary-card {
            padding: 20px 16px;
            border-radius: 20px;
            border: 1px solid rgba(226, 232, 240, 0.8);
            text-align: center;
            background: linear-gradient(180deg, #ffffff, #f8fafc);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .status-summary-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.06);
        }
        .status-summary-card .kicker {
            font-size: 0.75rem;
            font-weight: 800;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }
        .status-summary-card .count {
            font-size: 1.7rem;
            font-weight: 800;
            color: var(--text-main);
        }
        .status-summary-card.approved { border-bottom: 4px solid #10b981; }
        .status-summary-card.pending { border-bottom: 4px solid #f59e0b; }
        .status-summary-card.rejected { border-bottom: 4px solid #ef4444; }
        
        /* LEGEND PILLS */
        .legend-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 24px;
            justify-content: center;
        }
        .legend-pill {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 999px;
            background: #fff;
            font-size: 0.85rem;
            font-weight: 700;
            color: #334155;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            transition: all 0.2s;
        }
        .legend-pill:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
        }
        .legend-pill .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }
        
        /* INSIGHTS (4 COLUMNS) */
        .insights-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 20px;
        }
        .info-card {
            background: linear-gradient(135deg, #ffffff, #f8fafc);
            border: 1px solid #e2e8f0;
            padding: 24px;
            border-radius: 20px;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
        }
        .info-card::before {
            content: '';
            position: absolute;
            left: 0; top: 0; width: 4px; height: 100%;
            background: linear-gradient(180deg, #4f46e5, #06b6d4);
        }
        .info-card:hover { 
            transform: translateY(-6px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.06);
        }
        .info-card .label {
            font-size: 0.8rem;
            font-weight: 800;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }
        .info-card .value {
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--text-main);
            margin-bottom: 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .info-card .meta {
            font-size: 0.9rem;
            color: #64748b;
            font-weight: 500;
        }
        
        /* TABLE & FILTERS */
        .filter-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 24px;
        }
        .filter-row label {
            display: block;
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--text-muted);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 500;
            outline: none;
            color: var(--text-main);
            background: #fff;
            transition: all 0.2s;
            font-family: inherit;
        }
        .form-control:focus {
            border-color: #4f46e5;
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        }
        .filter-actions {
            display: flex;
            gap: 12px;
        }
        .btn-soft {
            padding: 12px 28px;
            border-radius: 12px;
            border: none;
            font-weight: 700;
            font-size: 0.95rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            font-family: inherit;
        }
        .btn-primary-soft { 
            background: linear-gradient(135deg, #4f46e5, #3b82f6);
            color: #fff;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.2);
        }
        .btn-primary-soft:hover { 
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(79, 70, 229, 0.3);
        }
        .btn-secondary-soft { 
            background: #fff;
            color: #475569;
            border: 2px solid #e2e8f0;
        }
        .btn-secondary-soft:hover { 
            background: #f8fafc;
            transform: translateY(-2px);
            border-color: #cbd5e1;
        }
        
        .table-wrap {
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.02);
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
        }
        .table th {
            background: #f8fafc;
            padding: 18px 20px;
            font-size: 0.8rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            border-bottom: 2px solid #e2e8f0;
            text-align: left;
        }
        .table td {
            padding: 18px 20px;
            font-size: 0.95rem;
            font-weight: 500;
            color: #334155;
            border-bottom: 1px solid #f1f5f9;
        }
        .table tbody tr { transition: background 0.2s; }
        .table tbody tr:hover { background: #f8fafc; }
        
        .status-badge {
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 700;
            display: inline-block;
        }
        .b-success { background: rgba(16, 185, 129, 0.1); color: #059669; }
        .b-warning { background: rgba(245, 158, 11, 0.1); color: #d97706; }
        .b-danger { background: rgba(239, 68, 68, 0.1); color: #dc2626; }
        .b-gray { background: #f1f5f9; color: #475569; }
        
        /* NOTIFICATIONS */
        .notifications-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .notification-card {
            padding: 24px;
            border-radius: 20px;
            border: 1px solid #e2e8f0;
            background: linear-gradient(135deg, #ffffff, #f8fafc);
            display: flex;
            flex-direction: column;
            gap: 12px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .notification-card:hover {
            transform: translateX(4px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            border-color: #cbd5e1;
        }
        .notification-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        .notification-title {
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--text-main);
        }
        .notification-meta {
            font-size: 0.85rem;
            color: var(--text-muted);
            font-weight: 600;
            margin-top: 4px;
        }
        .notification-message {
            font-size: 0.95rem;
            color: #475569;
            line-height: 1.6;
            margin: 0;
            font-weight: 500;
        }
        
        /* RESPONSIVE */
        @media (max-width: 1400px) {
            .stats-grid { grid-template-columns: repeat(3, 1fr); }
            .insights-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 1024px) {
            .overview-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .sidebar-backdrop.show { display: block; }
            .main { width: 100%; margin-left: 0; }
            .mobile-menu-toggle { display: flex; }
        }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .filter-row { grid-template-columns: 1fr; }
            .status-summary-grid { grid-template-columns: 1fr; }
            .insights-grid { grid-template-columns: 1fr; }
            .page { padding: 20px; }
            .panel { padding: 24px; }
            .topbar-inner { padding: 16px 20px; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
        }
        
        /* Apply staggered animations */
        .stat-card:nth-child(1) { animation-delay: 0.05s; }
        .stat-card:nth-child(2) { animation-delay: 0.1s; }
        .stat-card:nth-child(3) { animation-delay: 0.15s; }
        .stat-card:nth-child(4) { animation-delay: 0.2s; }
        .stat-card:nth-child(5) { animation-delay: 0.25s; }
        .stat-card:nth-child(6) { animation-delay: 0.3s; }
        .stat-card:nth-child(7) { animation-delay: 0.35s; }
        .stat-card:nth-child(8) { animation-delay: 0.4s; }
        .stat-card:nth-child(9) { animation-delay: 0.45s; }
        .stat-card:nth-child(10) { animation-delay: 0.5s; }
        
        .panel { animation: fadeInUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards; opacity: 0; }
        .panel:nth-child(1) { animation-delay: 0.2s; }
        .panel:nth-child(2) { animation-delay: 0.3s; }
        .panel:nth-child(3) { animation-delay: 0.4s; }
        .panel:nth-child(4) { animation-delay: 0.5s; }
        .panel:nth-child(5) { animation-delay: 0.6s; }
        .panel:nth-child(6) { animation-delay: 0.7s; }
    
          
/* SUPER MASTER REFINEMENT CSS */
.adv-panel {
    background: #ffffff;
    border-radius: 20px;
    padding: 28px;
    border: 1px solid rgba(226, 232, 240, 0.8);
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02), 0 10px 15px -3px rgba(0,0,0,0.02), inset 0 1px 0 rgba(255,255,255,1);
    position: relative;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.4s ease, border-color 0.4s;
}
.adv-panel::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    background: radial-gradient(circle 600px at var(--mouse-x, 50%) var(--mouse-y, 50%), rgba(248, 250, 252, 0.6), transparent 40%);
    opacity: 0;
    transition: opacity 0.5s;
    pointer-events: none;
    z-index: 1;
}
.adv-panel:hover::before { opacity: 1; }
.adv-panel:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 25px -5px rgba(0,0,0,0.05), 0 8px 10px -6px rgba(0,0,0,0.01), inset 0 1px 0 rgba(255,255,255,1);
    border-color: #cbd5e1;
}
.eco-container {
    position: relative;
    width: 100%;
    flex: 1;
    min-height: 320px;
    margin-top: 10px;
    z-index: 5;
}
.eco-svg {
    position: absolute;
    top: 0; left: 0; width: 100%; height: 100%;
    pointer-events: none;
}
.eco-line {
    stroke: #e2e8f0;
    stroke-width: 1.5;
    stroke-dasharray: 4;
    fill: none;
    transition: stroke 0.3s, stroke-width 0.3s;
}
.eco-line.active {
    stroke: #3b82f6;
    stroke-width: 2;
    stroke-dasharray: 0;
}
.eco-node {
    position: absolute;
    transform: translate(-50%, -50%) scale(0.94);
    opacity: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.4s, box-shadow 0.3s;
    pointer-events: auto;
}
.eco-node.visible {
    opacity: 1;
    transform: translate(-50%, -50%) scale(1);
}
.eco-node:hover {
    transform: translate(-50%, -50%) scale(1.08) !important;
    z-index: 20;
}
.eco-node-center {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: #ffffff;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08), inset 0 2px 4px rgba(255,255,255,0.8);
    border: 2px solid #e2e8f0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    z-index: 10;
}
.eco-node-center span {
    font-size: 0.65rem;
    font-weight: 800;
    color: #475569;
    line-height: 1.2;
    letter-spacing: 0.5px;
}
.eco-node-item {
    background: rgba(255,255,255,0.95);
    backdrop-filter: blur(4px);
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 8px 12px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.04);
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    min-width: max-content;
    max-width: 130px;
}
.eco-node-item:hover {
    border-color: #3b82f6;
    box-shadow: 0 8px 20px rgba(59, 130, 246, 0.15);
}
.eco-item-title {
    font-size: 0.75rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 2px;
    white-space: pre-wrap;
    line-height: 1.2;
}
.eco-item-stats {
    font-size: 0.7rem;
    color: #64748b;
    font-weight: 600;
}
.eco-item-pct {
    color: #3b82f6;
    font-weight: 800;
}

/* DOMAIN CONSTELLATION */
.const-node {
    position: absolute;
    transform: translate(-50%, -50%) scale(0.9);
    opacity: 0;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1), inset 0 2px 4px rgba(255,255,255,0.3);
    color: white;
    text-align: center;
    flex-direction: column;
    padding: 10px;
}
.const-node.visible { opacity: 1; transform: translate(-50%, -50%) scale(1); }
.const-node:hover {
    transform: translate(-50%, -50%) scale(1.1) !important;
    box-shadow: 0 10px 25px rgba(139, 92, 246, 0.4), inset 0 2px 4px rgba(255,255,255,0.5);
    z-index: 20;
}
.const-title {
    font-size: 0.7rem;
    font-weight: 800;
    line-height: 1.1;
    text-shadow: 0 1px 2px rgba(0,0,0,0.2);
}
.const-tooltip {
    position: absolute;
    bottom: calc(100% + 10px);
    left: 50%;
    transform: translateX(-50%) translateY(10px);
    opacity: 0;
    pointer-events: none;
    background: rgba(15, 23, 42, 0.95);
    backdrop-filter: blur(8px);
    color: white;
    padding: 10px 14px;
    border-radius: 10px;
    font-size: 0.75rem;
    white-space: nowrap;
    box-shadow: 0 10px 25px rgba(0,0,0,0.2);
    transition: all 0.2s ease;
    z-index: 30;
    border: 1px solid rgba(255,255,255,0.1);
}
.const-node:hover .const-tooltip {
    opacity: 1;
    transform: translateX(-50%) translateY(0);
}
.const-tooltip-title { font-weight: 800; font-size: 0.85rem; margin-bottom: 4px; color: #fff; }
.const-tooltip-stat { font-weight: 600; color: #cbd5e1; }
.const-tooltip-pct { color: #38bdf8; font-weight: 800; }



/* ENTERPRISE REDESIGN CSS */
:root {
    --card-shadow: 0 4px 6px -1px rgba(0,0,0,0.02), 0 10px 15px -3px rgba(0,0,0,0.02);
    --card-hover-shadow: 0 12px 25px -5px rgba(0,0,0,0.06), 0 8px 10px -6px rgba(0,0,0,0.02);
}

@keyframes advFadeInUp {
    from { opacity: 0; transform: translateY(16px); }
    to { opacity: 1; transform: translateY(0); }
}

#advOverviewGrid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 24px;
    align-items: stretch;
}

.adv-panel {
    background: #ffffff;
    border-radius: 20px;
    padding: 28px 24px;
    border: 1px solid rgba(226, 232, 240, 0.8);
    box-shadow: var(--card-shadow);
    position: relative;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    min-height: 460px;
    transition: box-shadow 0.3s ease, border-color 0.3s ease, transform 0.3s ease;
    opacity: 0;
    animation: advFadeInUp 0.7s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
.adv-panel:hover {
    box-shadow: var(--card-hover-shadow);
    border-color: #cbd5e1;
    transform: translateY(-2px);
}
.adv-panel:nth-child(1) { animation-delay: 0.1s; }
.adv-panel:nth-child(2) { animation-delay: 0.2s; }
.adv-panel:nth-child(3) { animation-delay: 0.3s; }

/* Subtle Lighting */
.adv-panel::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0;
    background: radial-gradient(circle 500px at var(--mouse-x, 50%) var(--mouse-y, -20%), rgba(248, 250, 252, 0.8), transparent 50%);
    opacity: 0; transition: opacity 0.5s; pointer-events: none; z-index: 1;
}
.adv-panel:hover::before { opacity: 1; }

.adv-header {
    display: flex; align-items: flex-start; gap: 14px; margin-bottom: 24px; z-index: 2; position: relative;
}
.adv-header-icon {
    width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; color: white; box-shadow: 0 4px 10px rgba(0,0,0,0.08); flex-shrink: 0;
}
.icon-status { background: linear-gradient(135deg, #10b981, #059669); }
.icon-types { background: linear-gradient(135deg, #3b82f6, #2563eb); }
.icon-domains { background: linear-gradient(135deg, #8b5cf6, #6d28d9); }

.adv-header-content { flex: 1; }
.adv-header-content h2 { font-size: 1.1rem; font-weight: 800; color: #0f172a; margin: 0 0 4px 0; letter-spacing: -0.01em; }
.adv-header-content p { font-size: 0.8rem; color: #64748b; margin: 0; }

.domain-badge {
    background: #f3e8ff; color: #7e22ce; font-size: 0.65rem; font-weight: 800; padding: 3px 8px; border-radius: 16px; border: 1px solid #e9d5ff; display: inline-flex; align-items: center; gap: 4px;
}

.expand-btn {
    background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; width: 32px; height: 32px; color: #64748b; cursor: pointer; transition: 0.2s; display: flex; align-items: center; justify-content: center; font-size: 0.85rem; flex-shrink: 0;
}
.expand-btn:hover { background: #e2e8f0; color: #0f172a; transform: scale(1.05); }

.adv-body { flex: 1; display: flex; flex-direction: column; z-index: 2; position: relative; }

/* Status Styles */
.status-chart-container { position: relative; height: 220px; width: 100%; display: flex; align-items: center; justify-content: center; margin-bottom: 24px; }
.status-center-text { position: absolute; text-align: center; pointer-events: none; transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); }
.status-center-val { font-size: 2.4rem; font-weight: 800; color: #0f172a; line-height: 1; font-variant-numeric: tabular-nums; }
.status-center-lbl { font-size: 0.75rem; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-top: 6px; }

.status-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: auto; }
.status-card {
    background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 14px; cursor: pointer; transition: 0.2s ease; display: flex; flex-direction: column;
}
.status-card:hover { background: #fff; border-color: #cbd5e1; box-shadow: 0 4px 6px rgba(0,0,0,0.02); transform: translateY(-2px); }
.st-card-head { display: flex; align-items: center; gap: 8px; font-size: 0.75rem; color: #475569; font-weight: 700; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
.st-dot { width: 8px; height: 8px; border-radius: 50%; }
.st-card-body { display: flex; justify-content: space-between; align-items: flex-end; }
.st-val { font-size: 1.25rem; font-weight: 800; color: #0f172a; line-height: 1; }
.st-pct { font-size: 0.8rem; font-weight: 700; }

/* Types Proportional Bar */
.prop-section { margin-bottom: 24px; }
.prop-head { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 12px; }
.prop-lbl { font-size: 0.7rem; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
.prop-val { font-size: 1.25rem; font-weight: 800; color: #0f172a; line-height: 1; }
.prop-bar { width: 100%; height: 28px; display: flex; border-radius: 14px; overflow: hidden; background: #f1f5f9; gap: 2px; }
.prop-seg { height: 100%; transition: width 1s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.3s; opacity: 0.9; cursor: pointer; }
.prop-seg:hover { opacity: 1; filter: brightness(1.1); }
.prop-seg:first-child { border-top-left-radius: 14px; border-bottom-left-radius: 14px; }
.prop-seg:last-child { border-top-right-radius: 14px; border-bottom-right-radius: 14px; }

.types-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: auto; }
.type-card {
    background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px; display: flex; flex-direction: column; justify-content: space-between; cursor: pointer; transition: 0.2s;
}
.type-card:hover { border-color: #94a3b8; box-shadow: 0 4px 10px rgba(0,0,0,0.03); }
.tc-title-row { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 12px; }
.tc-dot { width: 10px; height: 10px; border-radius: 3px; margin-top: 3px; flex-shrink: 0; }
.tc-title { font-size: 0.75rem; font-weight: 700; color: #334155; line-height: 1.3; }
.tc-data { display: flex; justify-content: space-between; align-items: flex-end; }
.tc-val { font-size: 1.1rem; font-weight: 800; color: #0f172a; line-height: 1; }
.tc-pct { font-size: 0.8rem; font-weight: 800; }

/* Domains Treemap */
.treemap-rect {
    position: absolute; border: 1px solid #ffffff; border-radius: 6px; padding: 8px; display: flex; flex-direction: column; justify-content: space-between; color: white; cursor: pointer; overflow: hidden; transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); opacity: 0;
}
.treemap-rect.visible { opacity: 1; }
.treemap-rect:hover { transform: scale(1.02); z-index: 10; box-shadow: 0 10px 20px rgba(0,0,0,0.15); filter: brightness(1.05); }
.tm-title { font-size: 0.75rem; font-weight: 700; line-height: 1.2; text-shadow: 0 1px 2px rgba(0,0,0,0.1); }
.tm-stats { display: flex; justify-content: space-between; font-size: 0.7rem; font-weight: 600; opacity: 0.9; }
.tm-large .tm-title { font-size: 0.9rem; font-weight: 800; }
.tm-large .tm-stats { font-size: 0.85rem; }

.verif-box {
    margin-top: 12px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center;
}
.verif-lbl { color: #d97706; font-size: 0.8rem; font-weight: 700; display: flex; align-items: center; gap: 8px; }
.verif-val { color: #b45309; font-size: 1rem; font-weight: 800; }

/* Modal Styles */
.analytics-modal {
    position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(8px);
    z-index: 1000; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: 0.3s;
}
.analytics-modal.open { opacity: 1; pointer-events: auto; }
.modal-content {
    background: #ffffff; width: 90%; max-width: 900px; max-height: 85vh; border-radius: 24px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
    display: flex; flex-direction: column; position: relative; transform: translateY(20px) scale(0.98); transition: 0.4s cubic-bezier(0.16, 1, 0.3, 1);
}
.analytics-modal.open .modal-content { transform: translateY(0) scale(1); }
.modal-header { padding: 24px 32px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; gap: 16px; }
.modal-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; color: white; }
.modal-title h2 { font-size: 1.5rem; font-weight: 800; margin: 0 0 4px 0; color: #0f172a; }
.modal-title p { font-size: 0.9rem; color: #64748b; margin: 0; }
.modal-close { position: absolute; top: 24px; right: 24px; background: #f1f5f9; border: none; border-radius: 50%; width: 36px; height: 36px; cursor: pointer; color: #64748b; font-size: 1rem; transition: 0.2s; }
.modal-close:hover { background: #e2e8f0; color: #0f172a; transform: scale(1.1); }
.modal-body { padding: 32px; overflow-y: auto; flex: 1; }

@media (max-width: 1200px) {
    #advOverviewGrid { grid-template-columns: repeat(2, 1fr); }
    .adv-panel:nth-child(3) { grid-column: span 2; }
}
@media (max-width: 768px) {
    #advOverviewGrid { grid-template-columns: 1fr; }
    .adv-panel:nth-child(3) { grid-column: span 1; }
}

</style>
</head>
<body>
<div class="app">
    <aside class="sidebar" id="appSidebar">
        <div class="brand">
            <img src="../assets/images/MIT_LOGO.jpg" alt="MIT-ADT University">
            <div class="title">MIT-ADT University</div>
            <div class="subtitle">PUNE, INDIA</div>
        </div>
        <nav class="nav">
            <a href="dashboard.php" class="active"><i class="fa-solid fa-house"></i>Dashboard</a>
            <a href="action_center.php" style="justify-content: space-between;"><span style="display:flex;align-items:center;gap:11px;"><i class="fa-solid fa-bolt" style="color:#fbbf24;"></i>Action Center</span> <span class="badge" style="background:#f43f5e; color:#fff; padding:3px 8px; border-radius:12px; font-size:0.7rem; font-weight:800;"><?php echo $action_center_count; ?></span></a>
            <a href="manage_students.php"><i class="fa-solid fa-users"></i>Manage Students</a>
            <a href="ty_students.php"><i class="fa-solid fa-user-graduate"></i>Third Year Students</a>
            <a href="manage_faculty.php"><i class="fa-solid fa-user-tie"></i>Manage Faculty</a>
            <a href="all_internships.php"><i class="fa-solid fa-briefcase"></i>All Internships</a>
            <a href="view_updates.php"><i class="fa-solid fa-calendar-days"></i>Weekly Reports</a>
            <a href="student_evaluations.php"><i class="fa-solid fa-clipboard-check"></i>Student Evaluations</a>
            <a href="reports.php"><i class="fa-solid fa-chart-line"></i>Reports</a>
            <a href="manage_notifications.php"><i class="fa-solid fa-bell"></i>Notifications</a>
            <a href="../logout.php"><i class="fa-solid fa-right-from-bracket"></i>Logout</a>
        </nav>
    </aside>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
    
    <main class="main">
        <div class="topbar">
            <div class="topbar-inner">
                <div class="d-flex align-items-center gap-3">
                    <button class="mobile-menu-toggle" id="mobileMenuToggle"><i class="fa-solid fa-bars"></i></button>
                    <div class="hero-title">
                        <h1>Admin Dashboard</h1>
                        <div class="sub">Live University Internship Analytics</div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <div style="text-align:right" class="d-none d-sm-block">
                        <div style="font-weight:800;color:var(--text-main)"><?php echo h($faculty_name); ?></div>
                        <div style="font-size:0.8rem;color:var(--text-muted);font-weight:600">Administrator</div>
                    </div>
                    <div style="width:48px;height:48px;border-radius:14px;background:linear-gradient(135deg, #4f46e5, #3b82f6);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.2rem;flex-shrink:0;box-shadow:0 8px 16px rgba(79,70,229,0.25)">
                        <?php echo h($faculty_initial); ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="page">
            <div class="stats-grid">
                <div class="stat-card stat-purple animate-up">
                    <div class="icon-wrapper">
                        <div class="icon"><i class="fa-solid fa-users"></i></div>
                    </div>
                    <div>
                        <div class="label">Total Students</div>
                        <div class="value"><?php echo (int)$total_students; ?></div>
                        <div class="note">Registered students</div>
                    </div>
                </div>
                <div class="stat-card stat-cyan animate-up">
                    <div class="icon-wrapper">
                        <div class="icon"><i class="fa-solid fa-user-tie"></i></div>
                    </div>
                    <div>
                        <div class="label">Total Faculty</div>
                        <div class="value"><?php echo (int)$total_faculty; ?></div>
                        <div class="note">Active faculty</div>
                    </div>
                </div>
                <div class="stat-card stat-blue animate-up">
                    <div class="icon-wrapper">
                        <div class="icon"><i class="fa-solid fa-briefcase"></i></div>
                    </div>
                    <div>
                        <div class="label">Internships</div>
                        <div class="value"><?php echo (int)$total_internships; ?></div>
                        <div class="note">Latest records</div>
                    </div>
                </div>
                <div class="stat-card stat-dark animate-up">
                    <div class="icon-wrapper">
                        <div class="icon"><i class="fa-solid fa-building"></i></div>
                    </div>
                    <div>
                        <div class="label">Companies</div>
                        <div class="value"><?php echo (int)$total_companies; ?></div>
                        <div class="note">Unique recruiters</div>
                    </div>
                </div>
                <div class="stat-card stat-violet animate-up">
                    <div class="icon-wrapper">
                        <div class="icon"><i class="fa-solid fa-layer-group"></i></div>
                    </div>
                    <div>
                        <div class="label">Categories</div>
                        <div class="value"><?php echo (int)$total_types; ?></div>
                        <div class="note">Distinct types</div>
                    </div>
                </div>
                <div class="stat-card stat-blue animate-up">
                    <div class="icon-wrapper">
                        <div class="icon"><i class="fa-solid fa-chart-line"></i></div>
                        <div class="trend"><i class="fa-solid fa-arrow-trend-up"></i> Top</div>
                    </div>
                    <div>
                        <div class="label">Approval Rate</div>
                        <div class="value"><?php echo number_format((float)$approval_rate, 2); ?>%</div>
                        <div class="note">Overall approved</div>
                    </div>
                </div>
                <div class="stat-card stat-green animate-up">
                    <div class="icon-wrapper">
                        <div class="icon"><i class="fa-solid fa-circle-check"></i></div>
                    </div>
                    <div>
                        <div class="label">Approved</div>
                        <div class="value"><?php echo (int)$approved_count; ?></div>
                        <div class="note">Final approvals</div>
                    </div>
                </div>
                <div class="stat-card stat-orange animate-up">
                    <div class="icon-wrapper">
                        <div class="icon"><i class="fa-regular fa-clock"></i></div>
                    </div>
                    <div>
                        <div class="label">Pending</div>
                        <div class="value"><?php echo (int)$pending_count; ?></div>
                        <div class="note">Awaiting review</div>
                    </div>
                </div>
                <div class="stat-card stat-red animate-up">
                    <div class="icon-wrapper">
                        <div class="icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    </div>
                    <div>
                        <div class="label">Rejected</div>
                        <div class="value"><?php echo (int)$rejected_count; ?></div>
                        <div class="note">Returned for fix</div>
                    </div>
                </div>
                <div class="stat-card stat-gray animate-up">
                    <div class="icon-wrapper">
                        <div class="icon"><i class="fa-solid fa-user-slash"></i></div>
                    </div>
                    <div>
                        <div class="label">No Details</div>
                        <div class="value"><?php echo (int)$no_details_count; ?></div>
                        <div class="note">Without internship</div>
                    </div>
                </div>
            </div>
            
            <div class="dashboard-sections">
                <div class="overview-grid" id="advOverviewGrid">
    <!-- 1. Internship Status -->
    <div class="adv-panel" id="panelStatus">
        <div class="adv-header">
            <div class="adv-header-icon icon-status"><i class="fa-solid fa-chart-pie"></i></div>
            <div class="adv-header-content">
                <h2>Internship Status</h2>
                <p>Live approval distribution</p>
            </div>
            <button class="expand-btn" onclick="openFullscreen('status')" title="Expand Status"><i class="fa-solid fa-expand"></i></button>
        </div>
        <div class="adv-body">
            <div class="status-chart-container">
                <canvas id="statusChartAdv"></canvas>
                <div class="status-center-text" id="statusCenterText">
                    <div class="status-center-val" id="statusCenterValue">0</div>
                    <div class="status-center-lbl" id="statusCenterLabel">TOTAL</div>
                </div>
            </div>
            <div class="status-grid" id="statusPillGrid"></div>
        </div>
    </div>

    <!-- 2. Internship Types -->
    <div class="adv-panel" id="panelTypes">
        <div class="adv-header">
            <div class="adv-header-icon icon-types"><i class="fa-solid fa-diagram-project"></i></div>
            <div class="adv-header-content">
                <h2>Internship Types</h2>
                <p>Proportional distribution</p>
            </div>
            <button class="expand-btn" onclick="openFullscreen('types')" title="Expand Types"><i class="fa-solid fa-expand"></i></button>
        </div>
        <div class="adv-body" id="typesEcoContainer" style="display:flex; flex-direction:column; justify-content:space-between; height:100%;">
            <!-- Rendered via JS -->
        </div>
    </div>

    <!-- 3. Tech Domains -->
    <div class="adv-panel" id="panelDomains">
        <div class="adv-header">
            <div class="adv-header-icon icon-domains"><i class="fa-solid fa-network-wired"></i></div>
            <div class="adv-header-content" style="flex:1;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <h2>Tech Domains</h2>
                    <span class="domain-badge"><i class="fa-solid fa-wand-magic-sparkles"></i> CLASSIFIED</span>
                </div>
                <p>Intelligence treemap</p>
            </div>
            <button class="expand-btn" onclick="openFullscreen('domains')" title="Expand Domains"><i class="fa-solid fa-expand"></i></button>
        </div>
        <div class="adv-body" style="display:flex; flex-direction:column; justify-content:flex-end; height:100%;">
            <div id="domainsEcoContainer" style="flex:1; width:100%; position:relative; min-height:220px; border-radius:12px; overflow:hidden; background:#f8fafc; border:1px solid #e2e8f0;">
                <!-- Treemap JS -->
            </div>
            <div id="domainsVerificationContainer"></div>
        </div>
    </div>
</div>

<!-- Fullscreen Analytics Modal -->
<div id="analyticsModal" class="analytics-modal" onclick="closeFullscreen(event)">
    <div class="modal-content" onclick="event.stopPropagation()">
        <button class="modal-close" onclick="closeFullscreen(event)"><i class="fa-solid fa-xmark"></i></button>
        <div class="modal-header">
            <div class="modal-icon" id="modalIcon"><i class="fa-solid fa-chart-simple"></i></div>
            <div class="modal-title">
                <h2 id="modalTitle">Analytics View</h2>
                <p id="modalSubtitle">Detailed breakdown</p>
            </div>
        </div>
        <div id="modalBody" class="modal-body"></div>
    </div>
</div>


                  <div class="panel">
                      <div class="section-head">
                          <h2>Division Performance</h2>
                        <p>Approved, pending and rejected split by division.</p>
                    </div>
                    <div class="chart-box" style="height:400px">
                        <canvas id="divisionChart"></canvas>
                    </div>
                </div>
                
                <div class="panel">
                    <div class="section-head">
                        <h2>Top Recruiting Companies</h2>
                        <p>Company distribution inside the university.</p>
                    </div>
                    <div class="chart-box" style="height:400px">
                        <canvas id="companyChart"></canvas>
                    </div>
                </div>
                
                <div class="panel">
                    <div class="section-head">
                        <h2>Division Analytics Search</h2>
                        <p>Filter students by division, internship type and company.</p>
                    </div>
                    <div class="filter-row">
                        <div>
                            <label>Division</label>
                            <select id="divisionFilter" class="form-control">
                                <option value="">All Divisions</option>
                                <?php foreach ($division_options as $opt): ?>
                                    <option value="<?php echo h($opt); ?>"><?php echo h($opt); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Internship Type</label>
                            <select id="typeFilter" class="form-control">
                                <option value="">All Types</option>
                                <?php foreach ($type_options as $opt): ?>
                                    <option value="<?php echo h($opt); ?>"><?php echo h($opt); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Company</label>
                            <select id="companyFilter" class="form-control">
                                <option value="">All Companies</option>
                                <?php foreach ($company_options as $opt): ?>
                                    <option value="<?php echo h($opt); ?>"><?php echo h($opt); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="filter-actions">
                        <button class="btn-soft btn-primary-soft" onclick="loadAnalytics()"><i class="fa-solid fa-magnifying-glass"></i> Search Data</button>
                        <button class="btn-soft btn-secondary-soft" onclick="resetFilters()"><i class="fa-solid fa-rotate-left"></i> Reset</button>
                    </div>
                    <div class="mt-4">
                        <div id="analyticsSummary" style="margin-bottom:16px;color:#64748b;font-weight:700;font-size:0.9rem">Select filters and click Search</div>
                        <div class="table-wrap">
                            <table class="table">
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
                                    <tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8;font-weight:600">No results to display yet.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <div class="panel">
                    <div class="section-head">
                        <h2>Key Insights</h2>
                        <p>Quick summary for the whole university.</p>
                    </div>
                    <div class="insights-grid">
                        <div class="info-card">
                            <div class="label">Top Division</div>
                            <div class="value" title="<?php echo h($top_division_name); ?>"><?php echo h($top_division_name); ?></div>
                            <div class="meta"><?php echo (int)$top_division_students; ?> students placed.</div>
                        </div>
                        <div class="info-card">
                            <div class="label">Top Company</div>
                            <div class="value" title="<?php echo h($top_company_name); ?>"><?php echo h($top_company_name); ?></div>
                            <div class="meta"><?php echo (int)$top_company_students; ?> students recruited.</div>
                        </div>
                        <div class="info-card">
                            <div class="label">Total Students</div>
                            <div class="value"><?php echo (int)$total_students; ?></div>
                            <div class="meta">Registered across university.</div>
                        </div>
                        <div class="info-card">
                            <div class="label">Approval Rate</div>
                            <div class="value"><?php echo number_format((float)$approval_rate, 2); ?>%</div>
                            <div class="meta">Overall approval metric.</div>
                        </div>
                    </div>
                </div>
                
                <div class="panel">
                    <div class="section-head">
                        <h2>Latest Notifications</h2>
                        <p>Recent updates from admin and faculty.</p>
                    </div>
                    <div class="notifications-list">
                        <?php if (empty($latest_notifications)): ?>
                            <div style="color:#94a3b8;padding:32px;text-align:center;background:#f8fafc;border-radius:20px;border:2px dashed #e2e8f0;font-weight:600">No notifications available.</div>
                        <?php else: ?>
                            <?php foreach ($latest_notifications as $note): ?>
                                <div class="notification-card">
                                    <div class="notification-top">
                                        <div>
                                            <div class="notification-title"><?php echo h($note['title'] ?? 'Notification'); ?></div>
                                            <div class="notification-meta">From: <span style="color:#4f46e5"><?php echo h($note['sender']); ?></span> • <?php echo h(notificationTimeLabel($note['created_at'])); ?></div>
                                        </div>
                                        <span class="status-badge <?php echo (($note['type'] ?? '') === 'faculty') ? 'b-gray' : 'b-success'; ?>">
                                            <?php echo (($note['type'] ?? '') === 'faculty') ? 'Faculty' : 'Admin'; ?>
                                        </span>
                                    </div>
                                    <p class="notification-message"><?php echo h(shortText($note['message'] ?? '', 240)); ?></p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
            </div>
        </div>
    </main>
</div>

<script>
// Use a vibrant color palette for modern professional look
const vibrantColors = ['#4f46e5', '#0ea5e9', '#10b981', '#f59e0b', '#ec4899', '#8b5cf6', '#f43f5e'];

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
      domains: {
          labels: <?php echo json_encode(array_values($domain_labels), JSON_UNESCAPED_UNICODE); ?>,
          values: <?php echo json_encode(array_values($domain_values), JSON_UNESCAPED_UNICODE); ?>
      }
,
    divisions: {
        labels: <?php echo json_encode(array_values($division_labels), JSON_UNESCAPED_UNICODE); ?>,
        approved: <?php echo json_encode(array_values($division_approved), JSON_UNESCAPED_UNICODE); ?>,
        pending: <?php echo json_encode(array_values($division_pending), JSON_UNESCAPED_UNICODE); ?>,
        rejected: <?php echo json_encode(array_values($division_rejected), JSON_UNESCAPED_UNICODE); ?>
    }
};

// Custom plugin for Chart.js to draw text in center of doughnut
Chart.register(ChartDataLabels);
Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
Chart.defaults.color = "#6b7280";
Chart.defaults.scale.grid.color = "rgba(241, 245, 249, 0.5)";





function handleCardHover(e, card) {
    if (window.matchMedia("(hover: hover)").matches) {
        const rect = card.getBoundingClientRect();
        card.style.setProperty('--mouse-x', `${e.clientX - rect.left}px`);
        card.style.setProperty('--mouse-y', `${e.clientY - rect.top}px`);
    }
}

// Tech Colors Dictionary
const domColors = {
    'ai': ['#6366f1', '#4f46e5'], 'machine learning': ['#8b5cf6', '#7c3aed'],
    'web': ['#0ea5e9', '#0284c7'], 'software': ['#3b82f6', '#2563eb'],
    'cyber': ['#ef4444', '#dc2626'], 'security': ['#ef4444', '#dc2626'],
    'cloud': ['#06b6d4', '#0891b2'], 'data': ['#a855f7', '#9333ea'],
    'research': ['#64748b', '#475569'], 'ui': ['#f43f5e', '#e11d48']
};
function getDomainColor(label) {
    let lower = label.toLowerCase();
    for (let key in domColors) {
        if (lower.includes(key)) return domColors[key];
    }
    return ['#14b8a6', '#0d9488']; // Default teal
}
const typeColors = ['#4f46e5', '#0ea5e9', '#10b981', '#f59e0b', '#ec4899', '#8b5cf6'];

function initInternshipStatus() {
    try {
        const statusCtx = document.getElementById('statusChartAdv');
        if (!statusCtx) return;
        const statusColors = ['#10b981', '#f59e0b', '#ef4444', '#94a3b8'];
        const statusLabels = chartData.status.labels;
        const statusValues = chartData.status.values;
        const statusTotal = statusValues.reduce((a,b)=>a+b, 0);
        
        const centerValEl = document.getElementById('statusCenterValue');
        const animateValue = (obj, start, end, duration) => {
            let startTimestamp = null;
            const step = (timestamp) => {
                if (!startTimestamp) startTimestamp = timestamp;
                const progress = Math.min((timestamp - startTimestamp) / duration, 1);
                obj.innerHTML = Math.floor((progress === 1 ? 1 : 1 - Math.pow(2, -10 * progress)) * (end - start) + start).toLocaleString();
                if (progress < 1) window.requestAnimationFrame(step);
            };
            window.requestAnimationFrame(step);
        };
        animateValue(centerValEl, 0, statusTotal, 1200);
        
        window.statusChartInstance = new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: statusLabels,
                datasets: [{ data: statusValues, backgroundColor: statusColors, borderWidth: 3, borderColor: '#ffffff', hoverOffset: 6, cutout: '82%', borderRadius: 3 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, layout: { padding: 10 },
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                onHover: (event, elements) => {
                    const centerText = document.getElementById('statusCenterText');
                    if (elements && elements.length) {
                        const idx = elements[0].index;
                        centerValEl.textContent = statusValues[idx].toLocaleString();
                        document.getElementById('statusCenterLabel').innerHTML = `${statusLabels[idx]}<br><span style="color:${statusColors[idx]};font-size:0.9rem;">${((statusValues[idx] / statusTotal) * 100).toFixed(1)}%</span>`;
                        centerValEl.style.color = statusColors[idx];
                        centerText.style.transform = 'scale(1.05)';
                    } else {
                        centerValEl.textContent = statusTotal.toLocaleString();
                        document.getElementById('statusCenterLabel').textContent = 'TOTAL';
                        centerValEl.style.color = '#0f172a';
                        centerText.style.transform = 'scale(1)';
                    }
                },
                animation: { animateScale: true, animateRotate: true, duration: 1500, easing: 'easeOutQuart' }
            }
        });

        const pillGrid = document.getElementById('statusPillGrid');
        let pillsHTML = '';
        statusLabels.forEach((label, i) => {
            let pct = statusTotal > 0 ? ((statusValues[i] / statusTotal) * 100).toFixed(1) : 0;
            pillsHTML += `<div class="status-card" onmouseenter="triggerStatusHover(${i})" onmouseleave="triggerStatusLeave()">
                <div class="st-card-head"><div class="st-dot" style="background:${statusColors[i]}"></div>${label}</div>
                <div class="st-card-body"><span class="st-val">${statusValues[i].toLocaleString()}</span><span class="st-pct" style="color:${statusColors[i]}">${pct}%</span></div>
            </div>`;
        });
        pillGrid.innerHTML = pillsHTML;

        window.triggerStatusHover = function(index) {
            if (!window.statusChartInstance) return;
            const meta = window.statusChartInstance.getDatasetMeta(0);
            if (!meta.data[index]) return;
            window.statusChartInstance.setActiveElements([{datasetIndex: 0, index: index}]);
            window.statusChartInstance.update();
            centerValEl.textContent = statusValues[index].toLocaleString();
            document.getElementById('statusCenterLabel').innerHTML = `${statusLabels[index]}<br><span style="color:${statusColors[index]};font-size:0.9rem;">${((statusValues[index] / statusTotal) * 100).toFixed(1)}%</span>`;
            centerValEl.style.color = statusColors[index];
            document.getElementById('statusCenterText').style.transform = 'scale(1.05)';
        };
        window.triggerStatusLeave = function() {
            if (!window.statusChartInstance) return;
            window.statusChartInstance.setActiveElements([]);
            window.statusChartInstance.update();
            centerValEl.textContent = statusTotal.toLocaleString();
            document.getElementById('statusCenterLabel').textContent = 'TOTAL';
            centerValEl.style.color = '#0f172a';
            document.getElementById('statusCenterText').style.transform = 'scale(1)';
        };
    } catch(e) { console.error("Status Error:", e); }
}

function initInternshipTypes() {
    try {
        const eco = document.getElementById('typesEcoContainer');
        if (!eco) return;
        let labels = chartData.types.labels; let values = chartData.types.values;
        if (!labels || labels.length === 0) return;
        let total = values.reduce((a,b)=>a+b, 0);
        let items = labels.map((l, i) => ({label: l, value: values[i], color: typeColors[i % typeColors.length]})).sort((a,b) => b.value - a.value);
        
        let html = `
        <div class="prop-section">
            <div class="prop-head">
                <span class="prop-lbl">Distribution Total</span>
                <span class="prop-val">${total.toLocaleString()}</span>
            </div>
            <div class="prop-bar">`;
        items.forEach((item, i) => {
            let pct = total > 0 ? (item.value / total) * 100 : 0;
            html += `<div class="prop-seg" id="pseg-${i}" style="width: 0%; background: ${item.color};" title="${item.label} - ${pct.toFixed(1)}%" onmouseenter="highlightType(${i})" onmouseleave="resetType()"></div>`;
        });
        html += `</div></div><div class="types-grid">`;
        items.forEach((item, i) => {
            let pct = total > 0 ? (item.value / total) * 100 : 0;
            html += `
            <div class="type-card" id="pcard-${i}" onmouseenter="highlightType(${i})" onmouseleave="resetType()">
                <div class="tc-title-row">
                    <div class="tc-dot" style="background:${item.color}"></div>
                    <div class="tc-title">${item.label}</div>
                </div>
                <div class="tc-data">
                    <span class="tc-val">${item.value.toLocaleString()}</span>
                    <span class="tc-pct" style="color:${item.color}">${pct.toFixed(1)}%</span>
                </div>
            </div>`;
        });
        html += `</div>`;
        eco.innerHTML = html;
        
        setTimeout(() => {
            items.forEach((item, i) => {
                let el = document.getElementById(`pseg-${i}`);
                if (el) el.style.width = `${(item.value / total) * 100}%`;
            });
        }, 100);

        window.highlightType = function(idx) {
            items.forEach((_, i) => {
                let s = document.getElementById(`pseg-${i}`);
                let c = document.getElementById(`pcard-${i}`);
                if (i !== idx) { if(s) s.style.opacity = '0.3'; if(c) c.style.opacity = '0.5'; }
                else { if(c) c.style.borderColor = items[i].color; }
            });
        };
        window.resetType = function() {
            items.forEach((_, i) => {
                let s = document.getElementById(`pseg-${i}`);
                let c = document.getElementById(`pcard-${i}`);
                if (s) s.style.opacity = '0.9';
                if (c) { c.style.opacity = '1'; c.style.borderColor = '#e2e8f0'; }
            });
        };
    } catch(e) { console.error("Types Error:", e); }
}

function initTechDomains() {
    try {
        const domEco = document.getElementById('domainsEcoContainer');
        if (!domEco) return;
        let labels = chartData.domains.labels; let values = chartData.domains.values;
        if (!labels || labels.length === 0) return;
        let total = values.reduce((a,b)=>a+b, 0);
        let domains = []; let needsVerif = 0;
        labels.forEach((l, i) => {
            if (l.toLowerCase().includes('needs verification')) needsVerif += values[i];
            else domains.push({label: l, value: values[i]});
        });
        domains.sort((a,b) => b.value - a.value);
        
        // Binary Split Treemap Algorithm
        function getTreemap(data, w, h) {
            let rects = [];
            function divide(start, end, x, y, currW, currH) {
                if (start >= end) return;
                if (start === end - 1) { rects.push({ item: data[start], x, y, w: currW, h: currH }); return; }
                let target = data.slice(start, end).reduce((s,d)=>s+d.value,0) / 2;
                let sum = 0, splitIdx = start;
                for (let i = start; i < end; i++) { sum += data[i].value; splitIdx = i; if (sum >= target) break; }
                if (splitIdx === start) splitIdx++; 
                let sum1 = data.slice(start, splitIdx).reduce((s,d)=>s+d.value,0);
                let sum2 = data.slice(splitIdx, end).reduce((s,d)=>s+d.value,0);
                let ratio = sum1 / (sum1 + sum2);
                if (currW > currH) {
                    let w1 = currW * ratio; divide(start, splitIdx, x, y, w1, currH); divide(splitIdx, end, x + w1, y, currW - w1, currH);
                } else {
                    let h1 = currH * ratio; divide(start, splitIdx, x, y, currW, h1); divide(splitIdx, end, x, y + h1, currW, currH - h1);
                }
            }
            divide(0, data.length, 0, 0, w, h); return rects;
        }

        const renderTreemap = () => {
            const w = domEco.clientWidth; const h = domEco.clientHeight;
            if (w === 0 || h === 0) return; // Wait for layout
            const rects = getTreemap(domains.slice(0, 10), w, h);
            domEco.innerHTML = '';
            rects.forEach((r, i) => {
                const c = getDomainColor(r.item.label);
                const isLarge = r.w > 120 && r.h > 80;
                let html = `<div class="treemap-rect ${isLarge ? 'tm-large' : ''}" style="left:${r.x}px; top:${r.y}px; width:${r.w}px; height:${r.h}px; background:linear-gradient(135deg, ${c[0]}, ${c[1]});" title="${r.item.label} - ${r.item.value} students">
                    <div class="tm-title">${r.item.label}</div>
                    ${r.h > 40 && r.w > 60 ? `<div class="tm-stats"><span>${r.item.value}</span><span>${((r.item.value/total)*100).toFixed(1)}%</span></div>` : ''}
                </div>`;
                domEco.insertAdjacentHTML('beforeend', html);
            });
            setTimeout(() => { domEco.querySelectorAll('.treemap-rect').forEach((el, i) => { setTimeout(()=>el.classList.add('visible'), i*50); }); }, 50);
        };
        
        // Wait for CSS to apply dimensions
        setTimeout(renderTreemap, 100);
        
        if (needsVerif > 0) {
            document.getElementById('domainsVerificationContainer').innerHTML = `<div class="verif-box"><span class="verif-lbl"><i class="fa-solid fa-triangle-exclamation"></i> Needs Verification</span><span class="verif-val">${needsVerif.toLocaleString()}</span></div>`;
        }
    } catch(e) { console.error("Domains Error:", e); }
}

function openFullscreen(type) {
    const modal = document.getElementById('analyticsModal');
    const title = document.getElementById('modalTitle');
    const icon = document.getElementById('modalIcon');
    const body = document.getElementById('modalBody');
    body.innerHTML = '';
    
    if (type === 'status') {
        title.innerText = 'Internship Status Breakdown';
        icon.style.background = 'linear-gradient(135deg, #10b981, #059669)';
        icon.innerHTML = '<i class="fa-solid fa-chart-pie"></i>';
        // Basic list for now, since radial chart doesn't deep clone easily
        let html = '<div class="fallback-list">';
        chartData.status.labels.forEach((l, i) => {
            html += `<div class="fallback-item"><span style="font-weight:700;">${l}</span><span>${chartData.status.values[i].toLocaleString()}</span></div>`;
        });
        body.innerHTML = html + '</div>';
    } else if (type === 'types') {
        title.innerText = 'Internship Types Full Data';
        icon.style.background = 'linear-gradient(135deg, #3b82f6, #2563eb)';
        icon.innerHTML = '<i class="fa-solid fa-diagram-project"></i>';
        let html = '<div class="fallback-list">';
        chartData.types.labels.forEach((l, i) => {
            html += `<div class="fallback-item"><span style="font-weight:700;">${l}</span><span>${chartData.types.values[i].toLocaleString()}</span></div>`;
        });
        body.innerHTML = html + '</div>';
    } else if (type === 'domains') {
        title.innerText = 'Tech Domains Full Dataset';
        icon.style.background = 'linear-gradient(135deg, #8b5cf6, #6d28d9)';
        icon.innerHTML = '<i class="fa-solid fa-network-wired"></i>';
        let html = '<div class="fallback-list">';
        chartData.domains.labels.forEach((l, i) => {
            html += `<div class="fallback-item"><span style="font-weight:700;">${l}</span><span>${chartData.domains.values[i].toLocaleString()}</span></div>`;
        });
        body.innerHTML = html + '</div>';
    }
    
    modal.classList.add('open');
}

function closeFullscreen(e) {
    document.getElementById('analyticsModal').classList.remove('open');
}

window.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeFullscreen();
});

function initAdvancedCharts() {
    initInternshipStatus();
    initInternshipTypes();
    initTechDomains();
}

function initCharts() {
    initAdvancedCharts();
    
    // Division Chart
    new Chart(document.getElementById('divisionChart'), {
        type: 'bar',
        data: {
            labels: chartData.divisions.labels,
            datasets: [
                { label: 'Approved', data: chartData.divisions.approved, backgroundColor: '#10b981', borderRadius: {topLeft: 6, topRight: 6, bottomLeft: 0, bottomRight: 0}, barThickness: 20 },
                { label: 'Pending', data: chartData.divisions.pending, backgroundColor: '#f59e0b', borderRadius: {topLeft: 6, topRight: 6, bottomLeft: 0, bottomRight: 0}, barThickness: 20 },
                { label: 'Rejected', data: chartData.divisions.rejected, backgroundColor: '#ef4444', borderRadius: {topLeft: 6, topRight: 6, bottomLeft: 0, bottomRight: 0}, barThickness: 20 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            scales: {
                x: { stacked: true, grid: { display: false } },
                y: { stacked: true, border: { display: false } }
            },
            plugins: {
                legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 10, font: { weight: '800' }, padding: 20 } },
                tooltip: {
                    backgroundColor: 'rgba(17, 24, 39, 0.95)',
                    padding: 12, cornerRadius: 10, titleFont: {size: 14}, bodyFont: {size: 14, weight: 'bold'}
                },
                datalabels: {
                    display: function(context) { return context.dataset.data[context.dataIndex] > 0; },
                    color: '#fff', font: {weight: 'bold', size: 12}, anchor: 'center', align: 'center'
                }
            },
            animation: { duration: 1500, easing: 'easeOutQuart', delay: 400 }
        }
    });
    
    // Company Chart (Horizontal Bar with gradients)
    new Chart(document.getElementById('companyChart'), {
        type: 'bar',
        data: {
            labels: chartData.companies.labels,
            datasets: [{
                data: chartData.companies.values,
                backgroundColor: function(context) {
                    const chart = context.chart;
                    const {ctx, chartArea} = chart;
                    if (!chartArea) return null;
                    const gradient = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0);
                    gradient.addColorStop(0, '#4f46e5'); // Indigo
                    gradient.addColorStop(1, '#06b6d4'); // Cyan
                    return gradient;
                },
                borderRadius: 8,
                borderSkipped: false,
                barPercentage: 0.7,
                categoryPercentage: 0.8
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, indexAxis: 'y',
            scales: {
                x: { grid: { display: false }, border: { display: false }, display: false },
                y: { grid: { display: false }, border: { display: false }, ticks: { font: { weight: '600', size: 12 } } }
            },
            plugins: { 
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(17, 24, 39, 0.95)',
                    padding: 12, cornerRadius: 10, titleFont: {size: 14}, bodyFont: {size: 14, weight: 'bold'}
                },
                datalabels: {
                    color: '#fff',
                    anchor: 'end',
                    align: 'start',
                    offset: 10,
                    font: { weight: 'bold', size: 13 },
                    formatter: Math.round,
                    color: '#111827' // For the text outside the bar
                }
            },
            animation: { duration: 1500, easing: 'easeOutQuart', delay: 600 }
        }
    });
}

async function loadAnalytics() {
    const div = document.getElementById('divisionFilter').value;
    const typ = document.getElementById('typeFilter').value;
    const cmp = document.getElementById('companyFilter').value;
    const tbody = document.getElementById('analyticsTableBody');
    const summary = document.getElementById('analyticsSummary');
    
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:40px;color:#64748b;font-weight:600"><i class="fa-solid fa-circle-notch fa-spin"></i> Fetching records...</td></tr>';
    
    const url = new URL(window.location.href);
    url.searchParams.set('ajax', 'analytics');
    if(div) url.searchParams.set('division', div);
    if(typ) url.searchParams.set('type', typ);
    if(cmp) url.searchParams.set('company', cmp);
    
    try {
        const res = await fetch(url);
        const data = await res.json();
        
        if(!data.ok) throw new Error();
        
        summary.innerHTML = `<span style="color:#4f46e5"><i class="fa-solid fa-bolt"></i> Found ${data.total_records} records</span> &nbsp;&bull;&nbsp; <span style="color:#10b981">Approved: ${data.approved}</span> &nbsp;&bull;&nbsp; <span style="color:#f59e0b">Pending: ${data.pending}</span>`;
        
        if(data.records.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8;font-weight:600">No records match your criteria.</td></tr>';
            return;
        }
        
        tbody.innerHTML = data.records.map((r, i) => {
            const cls = r.status === 'Approved' ? 'b-success' : r.status === 'Rejected' ? 'b-danger' : r.status === 'Pending' ? 'b-warning' : 'b-gray';
            const delay = (i * 0.03) + 's';
            return `
                <tr style="animation: fadeInUp 0.4s ease forwards; animation-delay: ${delay}; opacity: 0;">
                    <td style="font-weight:700; color:#475569">${r.roll_no}</td>
                    <td style="font-weight:800; color:#111827">${r.student_name}</td>
                    <td><span style="background:#f1f5f9;padding:6px 12px;border-radius:8px;font-size:0.8rem;font-weight:700">${r.division}</span></td>
                    <td style="font-weight:600">${r.company_name}</td>
                    <td style="font-weight:600; color:#4f46e5">${r.internship_type}</td>
                    <td><span class="status-badge ${cls}" style="box-shadow:0 4px 6px rgba(0,0,0,0.02)">${r.status}</span></td>
                </tr>
            `;
        }).join('');
    } catch(e) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:40px;color:#ef4444;font-weight:700">Failed to load data. Please try again.</td></tr>';
    }
}

function resetFilters() {
    document.getElementById('divisionFilter').value = '';
    document.getElementById('typeFilter').value = '';
    document.getElementById('companyFilter').value = '';
    document.getElementById('analyticsTableBody').innerHTML = '<tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8;font-weight:600">No results to display yet.</td></tr>';
    document.getElementById('analyticsSummary').textContent = 'Select filters and click Search Data';
}

document.getElementById('mobileMenuToggle').addEventListener('click', () => {
    document.getElementById('appSidebar').classList.add('open');
    document.getElementById('sidebarBackdrop').classList.add('show');
});
document.getElementById('sidebarBackdrop').addEventListener('click', () => {
    document.getElementById('appSidebar').classList.remove('open');
    document.getElementById('sidebarBackdrop').classList.remove('show');
});

document.addEventListener('DOMContentLoaded', initCharts);
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


