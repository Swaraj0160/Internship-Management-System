<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/../config.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty') {
    header("Location: ../login.php");
    exit();
}

/* HELPER: Calculate weekly marks based on deadline and late submission rules */
function calculateWeeklyMarks(?string $submittedAt, ?string $deadlineDate, ?string $rejectedAt = null): int
{
    if (empty($submittedAt)) {
        return 0;
    }
    $submitted_ts = strtotime($submittedAt);
    if (empty($deadlineDate)) {
        return 10;
    }
    $deadline_ts = strtotime($deadlineDate);
    $effective_deadline = !empty($rejectedAt) ? (strtotime($rejectedAt) + 86400) : $deadline_ts;
    
    $seconds_late = $submitted_ts - $effective_deadline;
    $days_late = ceil($seconds_late / 86400);

    if ($days_late <= 0) {
        return 10;
    } elseif ($days_late == 1) {
        return 9;
    } elseif ($days_late == 2) {
        return 8;
    } elseif ($days_late == 3) {
        return 7;
    } else {
        return 5;
    }
}

/* HELPER: Get student initials for avatar badge */
function getStudentInitials(?string $name): string {
    if (empty($name)) return 'ST';
    $parts = preg_split('/\s+/', trim($name));
    if (count($parts) >= 2) {
        return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[count($parts) - 1], 0, 1));
    }
    return strtoupper(mb_substr($parts[0], 0, 2));
}

/* HELPER: Get subtle avatar background color based on student name */
function getAvatarBgStyle(?string $str): array {
    $colors = [
        ['bg' => '#e0e7ff', 'text' => '#3730a3'], // indigo
        ['bg' => '#f3e8ff', 'text' => '#6b21a8'], // purple
        ['bg' => '#dcfce7', 'text' => '#15803d'], // green
        ['bg' => '#fef3c7', 'text' => '#b45309'], // amber
        ['bg' => '#e0f2fe', 'text' => '#0369a1'], // sky
        ['bg' => '#ffe4e6', 'text' => '#be123c'], // rose
    ];
    $hash = abs(crc32((string)$str));
    return $colors[$hash % count($colors)];
}

/* HELPER: Strict authorization check to ensure report belongs to faculty division */
function isReportAuthorized($conn, int $updateId, string $facDivSql): bool {
    if ($updateId <= 0 || empty($facDivSql)) {
        return false;
    }
    $checkQuery = "SELECT weekly_updates.id 
                   FROM weekly_updates 
                   JOIN internships ON weekly_updates.internship_id = internships.id 
                   JOIN users ON internships.student_id = users.id 
                   WHERE weekly_updates.id = '$updateId' 
                     AND users.division IN ($facDivSql) 
                   LIMIT 1";
    $checkRes = mysqli_query($conn, $checkQuery);
    return ($checkRes && mysqli_num_rows($checkRes) > 0);
}

$faculty_id = $_SESSION['user_id'];

// Resolve faculty assigned divisions
$faculty_query = "SELECT faculty_division, full_name FROM users WHERE id='$faculty_id'";
$faculty_result = mysqli_query($conn, $faculty_query);
$faculty_data = mysqli_fetch_assoc($faculty_result);

$faculty_divisions = [];
$division_query = mysqli_query($conn, "SELECT DISTINCT division FROM faculty_divisions WHERE faculty_id='$faculty_id' AND division IS NOT NULL AND TRIM(division) <> '' ORDER BY division ASC");

if ($division_query) {
    while ($division_row = mysqli_fetch_assoc($division_query)) {
        $division_value = trim((string)($division_row['division'] ?? ''));
        if ($division_value !== '' && !in_array($division_value, $faculty_divisions, true)) {
            $faculty_divisions[] = $division_value;
        }
    }
    mysqli_free_result($division_query);
}

if (empty($faculty_divisions) && !empty($faculty_data['faculty_division'])) {
    $faculty_divisions[] = trim((string)$faculty_data['faculty_division']);
}

$division_values = [];
foreach ($faculty_divisions as $division_value) {
    $division_values[] = "'" . mysqli_real_escape_string($conn, $division_value) . "'";
}

$faculty_division_sql = !empty($division_values) ? implode(',', $division_values) : "''";
$faculty_division = implode(', ', $faculty_divisions);

/* UNIFIED ASYNCHRONOUS AJAX ACTION HANDLER (APPROVE, REJECT, UNDO, SAVE FEEDBACK) */
if (isset($_POST['ajax_action'])) {
    @set_time_limit(300);
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $response = ['success' => false, 'message' => 'Invalid action'];

    if ($action === 'approve') {
        $update_id = (int)$_POST['update_id'];
        
        if (!isReportAuthorized($conn, $update_id, $faculty_division_sql)) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized operation']);
            exit();
        }

        mysqli_query($conn, "UPDATE weekly_updates SET review_status='Accepted', faculty_feedback='' WHERE id='$update_id'");
        
        $reportQuery = mysqli_query($conn, "SELECT weekly_updates.*, users.division, weekly_deadlines.deadline_date FROM weekly_updates JOIN internships ON weekly_updates.internship_id = internships.id JOIN users ON internships.student_id = users.id LEFT JOIN weekly_deadlines ON weekly_deadlines.week_no = weekly_updates.week_no AND weekly_deadlines.division = users.division WHERE weekly_updates.id='$update_id'");
        if ($report = mysqli_fetch_assoc($reportQuery)) {
            $internship_id = $report['internship_id'];
            $week_no = $report['week_no'];
            
            $internshipQuery = mysqli_query($conn, "SELECT student_id FROM internships WHERE id='$internship_id'");
            $student_id = mysqli_fetch_assoc($internshipQuery)['student_id'];
            
            $marks = calculateWeeklyMarks($report['submitted_at'], $report['deadline_date'], $report['rejected_at']);
            
            $checkEval = mysqli_query($conn, "SELECT * FROM student_evaluation WHERE student_id='$student_id'");
            if (mysqli_num_rows($checkEval) == 0) {
                mysqli_query($conn, "INSERT INTO student_evaluation (student_id) VALUES ('$student_id')");
            }
            
            $weekColumn = "week" . $week_no . "_marks";
            mysqli_query($conn, "UPDATE student_evaluation SET $weekColumn='$marks' WHERE student_id='$student_id'");
            mysqli_query($conn, "UPDATE student_evaluation SET weekly_total = week1_marks + week2_marks + week3_marks + week4_marks, total_marks = ROUND((weekly_total + mentor_evaluation + final_evaluation) / 2) WHERE student_id='$student_id'");
            
            $response = ['success' => true, 'message' => 'Report accepted successfully!'];
        }
    } 
    elseif ($action === 'reject') {
        $update_id = (int)$_POST['update_id'];
        
        if (!isReportAuthorized($conn, $update_id, $faculty_division_sql)) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized operation']);
            exit();
        }

        $faculty_feedback = mysqli_real_escape_string($conn, $_POST['faculty_feedback'] ?? '');
        mysqli_query($conn, "UPDATE weekly_updates SET review_status='Rejected', faculty_feedback='$faculty_feedback', rejected_at=NOW() WHERE id='$update_id'");
        
        $response = ['success' => true, 'message' => 'Report rejected successfully!'];
    }
    elseif ($action === 'undo') {
        $update_id = (int)$_POST['update_id'];
        
        if (!isReportAuthorized($conn, $update_id, $faculty_division_sql)) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized operation']);
            exit();
        }

        $reportQuery = mysqli_query($conn, "SELECT * FROM weekly_updates WHERE id='$update_id'");
        if ($report = mysqli_fetch_assoc($reportQuery)) {
            $internship_id = $report['internship_id'];
            $week_no = $report['week_no'];

            $internshipQuery = mysqli_query($conn, "SELECT student_id FROM internships WHERE id='$internship_id'");
            $student_id = mysqli_fetch_assoc($internshipQuery)['student_id'];

            $weekColumn = "week" . $week_no . "_marks";
            mysqli_query($conn, "UPDATE student_evaluation SET $weekColumn='0' WHERE student_id='$student_id'");
            mysqli_query($conn, "UPDATE student_evaluation SET weekly_total = week1_marks + week2_marks + week3_marks + week4_marks, total_marks = ROUND((weekly_total + mentor_evaluation + final_evaluation) / 2) WHERE student_id='$student_id'");

            mysqli_query($conn, "UPDATE weekly_updates SET review_status='Pending', rejected_at=NULL WHERE id='$update_id'");
            
            $response = ['success' => true, 'message' => 'Report review undone successfully!'];
        }
    }
    elseif ($action === 'save_feedback') {
        $update_id = (int)$_POST['update_id'];
        
        if (!isReportAuthorized($conn, $update_id, $faculty_division_sql)) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized operation']);
            exit();
        }

        $faculty_feedback = mysqli_real_escape_string($conn, $_POST['faculty_feedback'] ?? '');
        mysqli_query($conn, "UPDATE weekly_updates SET faculty_feedback='$faculty_feedback' WHERE id='$update_id'");
        
        $response = ['success' => true, 'message' => 'Feedback saved successfully!'];
    }

    if (!empty($response['success'])) {
        $update_id = (int)$_POST['update_id'];
        $updatedRowQuery = mysqli_query($conn, "SELECT weekly_updates.id, weekly_updates.review_status, weekly_updates.faculty_feedback, users.full_name, users.enrollment_no FROM weekly_updates JOIN internships ON weekly_updates.internship_id = internships.id JOIN users ON internships.student_id = users.id WHERE weekly_updates.id = '$update_id' LIMIT 1");
        if ($updatedRow = mysqli_fetch_assoc($updatedRowQuery)) {
            $response['report_id'] = (int)$updatedRow['id'];
            $response['new_status'] = $updatedRow['review_status'];
            $response['faculty_feedback'] = (string)($updatedRow['faculty_feedback'] ?? '');
            $response['full_name'] = (string)($updatedRow['full_name'] ?? '');
            $response['enrollment_no'] = (string)($updatedRow['enrollment_no'] ?? '');
        }
    }
    
    // Compute Updated Summary Counts for Active Filter Context
    $summary_query = "SELECT COUNT(*) AS total_reports, IFNULL(SUM(CASE WHEN review_status='Accepted' OR review_status='Approved' THEN 1 ELSE 0 END),0) AS accepted_count, IFNULL(SUM(CASE WHEN review_status='Rejected' THEN 1 ELSE 0 END),0) AS rejected_count, IFNULL(SUM(CASE WHEN review_status='Pending' OR review_status IS NULL OR TRIM(review_status)='' THEN 1 ELSE 0 END),0) AS pending_count FROM weekly_updates JOIN internships ON weekly_updates.internship_id = internships.id JOIN users ON internships.student_id = users.id WHERE users.division IN ($faculty_division_sql)";
    
    $week_filter = mysqli_real_escape_string($conn, $_POST['week_filter'] ?? '');
    $search = mysqli_real_escape_string($conn, $_POST['search'] ?? '');
    $status_filter = mysqli_real_escape_string($conn, $_POST['status_filter'] ?? '');
    
    if($week_filter != "") { $summary_query .= " AND weekly_updates.week_no='$week_filter' "; }
    if($search != "") { $summary_query .= " AND (users.full_name LIKE '%$search%' OR users.enrollment_no LIKE '%$search%' OR internships.company_name LIKE '%$search%') "; }
    if($status_filter != "") { 
        if ($status_filter == 'Pending') {
            $summary_query .= " AND (weekly_updates.review_status='Pending' OR weekly_updates.review_status IS NULL OR TRIM(weekly_updates.review_status)='') ";
        } elseif ($status_filter == 'Accepted') {
            $summary_query .= " AND (weekly_updates.review_status='Accepted' OR weekly_updates.review_status='Approved') ";
        } else {
            $summary_query .= " AND weekly_updates.review_status='$status_filter' ";
        }
    }
    
    $summary_result = mysqli_query($conn, $summary_query);
    $summary = mysqli_fetch_assoc($summary_result);
    
    $response['summary'] = [
        'total' => (int)$summary['total_reports'],
        'accepted' => (int)$summary['accepted_count'],
        'rejected' => (int)$summary['rejected_count'],
        'pending' => (int)$summary['pending_count']
    ];
    
    echo json_encode($response);
    exit();
}

$activePage = 'view_updates.php';

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$search = "";
$week_filter = "";
$status_filter = "";
$return_query = "";

if (isset($_GET['search'])) { $return_query .= "&search=" . urlencode($_GET['search']); $search = mysqli_real_escape_string($conn, $_GET['search']); }
if (isset($_GET['week'])) { $return_query .= "&week=" . urlencode($_GET['week']); $week_filter = mysqli_real_escape_string($conn, $_GET['week']); }
if (isset($_GET['status'])) { $return_query .= "&status=" . urlencode($_GET['status']); $status_filter = mysqli_real_escape_string($conn, $_GET['status']); }

/* FALLBACK TRADITIONAL POST HANDLERS (WITHOUT NATIVE ALERT POPUPS) */
if (isset($_POST['save_feedback'])) {
    $update_id = (int)$_POST['update_id'];
    if (!isReportAuthorized($conn, $update_id, $faculty_division_sql)) {
        die("Unauthorized access to report.");
    }
    $faculty_feedback = mysqli_real_escape_string($conn, $_POST['faculty_feedback']);
    mysqli_query($conn, "UPDATE weekly_updates SET faculty_feedback='$faculty_feedback' WHERE id='$update_id'");
    header("Location: view_updates.php?saved=1{$return_query}");
    exit();
}

if (isset($_POST['accept_report'])) {
    $update_id = (int)$_POST['update_id'];
    if (!isReportAuthorized($conn, $update_id, $faculty_division_sql)) {
        die("Unauthorized access to report.");
    }
    $faculty_feedback = mysqli_real_escape_string($conn, $_POST['faculty_feedback'] ?? '');
    mysqli_query($conn, "UPDATE weekly_updates SET review_status='Accepted', faculty_feedback='$faculty_feedback' WHERE id='$update_id'");

    $reportQuery = mysqli_query($conn, "SELECT weekly_updates.*, users.division, weekly_deadlines.deadline_date FROM weekly_updates JOIN internships ON weekly_updates.internship_id = internships.id JOIN users ON internships.student_id = users.id LEFT JOIN weekly_deadlines ON weekly_deadlines.week_no = weekly_updates.week_no AND weekly_deadlines.division = users.division WHERE weekly_updates.id='$update_id'");
    $report = mysqli_fetch_assoc($reportQuery);

    $internship_id = $report['internship_id'];
    $week_no = $report['week_no'];

    $internshipQuery = mysqli_query($conn, "SELECT student_id FROM internships WHERE id='$internship_id'");
    $student_id = mysqli_fetch_assoc($internshipQuery)['student_id'];

    $marks = calculateWeeklyMarks($report['submitted_at'], $report['deadline_date'], $report['rejected_at']);

    $checkEval = mysqli_query($conn, "SELECT * FROM student_evaluation WHERE student_id='$student_id'");
    if (mysqli_num_rows($checkEval) == 0) {
        mysqli_query($conn, "INSERT INTO student_evaluation (student_id) VALUES ('$student_id')");
    }

    $weekColumn = "week" . $week_no . "_marks";
    mysqli_query($conn, "UPDATE student_evaluation SET $weekColumn='$marks' WHERE student_id='$student_id'");
    mysqli_query($conn, "UPDATE student_evaluation SET weekly_total = week1_marks + week2_marks + week3_marks + week4_marks, total_marks = ROUND((weekly_total + mentor_evaluation + final_evaluation) / 2) WHERE student_id='$student_id'");

    header("Location: view_updates.php?success=accepted{$return_query}");
    exit();
}

if (isset($_POST['reject_report'])) {
    $update_id = (int)$_POST['update_id'];
    if (!isReportAuthorized($conn, $update_id, $faculty_division_sql)) {
        die("Unauthorized access to report.");
    }
    $faculty_feedback = mysqli_real_escape_string($conn, $_POST['faculty_feedback'] ?? '');

    mysqli_query($conn, "UPDATE weekly_updates SET review_status='Rejected', faculty_feedback='$faculty_feedback', rejected_at=NOW() WHERE id='$update_id'");

    header("Location: view_updates.php?success=rejected{$return_query}");
    exit();
}

if (isset($_POST['undo_report'])) {
    $update_id = (int)$_POST['update_id'];
    if (!isReportAuthorized($conn, $update_id, $faculty_division_sql)) {
        die("Unauthorized access to report.");
    }
    $reportQuery = mysqli_query($conn, "SELECT * FROM weekly_updates WHERE id='$update_id'");
    $report = mysqli_fetch_assoc($reportQuery);

    $internship_id = $report['internship_id'];
    $week_no = $report['week_no'];

    $internshipQuery = mysqli_query($conn, "SELECT student_id FROM internships WHERE id='$internship_id'");
    $student_id = mysqli_fetch_assoc($internshipQuery)['student_id'];

    $weekColumn = "week" . $week_no . "_marks";
    mysqli_query($conn, "UPDATE student_evaluation SET $weekColumn='0' WHERE student_id='$student_id'");
    mysqli_query($conn, "UPDATE student_evaluation SET weekly_total = week1_marks + week2_marks + week3_marks + week4_marks, total_marks = ROUND((weekly_total + mentor_evaluation + final_evaluation) / 2) WHERE student_id='$student_id'");

    mysqli_query($conn, "UPDATE weekly_updates SET review_status='Pending', rejected_at=NULL WHERE id='$update_id'");

    header("Location: view_updates.php?success=undone{$return_query}");
    exit();
}

/* MAIN QUERY (SINGLE ULTRA-EFFICIENT QUERY FETCHING ALL MATCHING REPORTS & DEADLINES WITH ZERO N+1 LOOPS) */
$query = "SELECT 
    weekly_updates.*, 
    internships.company_name, 
    users.full_name, 
    users.enrollment_no, 
    users.division, 
    users.roll_no,
    weekly_deadlines.deadline_date
FROM weekly_updates 
JOIN internships ON weekly_updates.internship_id = internships.id 
JOIN users ON internships.student_id = users.id 
LEFT JOIN weekly_deadlines ON weekly_deadlines.week_no = weekly_updates.week_no AND weekly_deadlines.division = users.division
WHERE users.division IN ($faculty_division_sql)";

if ($search != "") {
    $query .= " AND (users.full_name LIKE '%$search%' OR users.enrollment_no LIKE '%$search%' OR internships.company_name LIKE '%$search%')";
}
if ($week_filter != "") {
    $query .= " AND weekly_updates.week_no='$week_filter'";
}
if ($status_filter != "") {
    if ($status_filter == 'Pending') {
        $query .= " AND (weekly_updates.review_status='Pending' OR weekly_updates.review_status IS NULL OR TRIM(weekly_updates.review_status)='')";
    } elseif ($status_filter == 'Accepted') {
        $query .= " AND (weekly_updates.review_status='Accepted' OR weekly_updates.review_status='Approved')";
    } else {
        $query .= " AND weekly_updates.review_status='$status_filter'";
    }
}

$query .= " ORDER BY CAST(users.roll_no AS UNSIGNED) ASC, weekly_updates.week_no ASC";
$result = mysqli_query($conn, $query);

// OVERALL DIVISION SUMMARY COUNTS
$summary_query = "SELECT COUNT(*) AS total_reports, IFNULL(SUM(CASE WHEN review_status='Accepted' OR review_status='Approved' THEN 1 ELSE 0 END),0) AS accepted_count, IFNULL(SUM(CASE WHEN review_status='Rejected' THEN 1 ELSE 0 END),0) AS rejected_count, IFNULL(SUM(CASE WHEN review_status='Pending' OR review_status IS NULL OR TRIM(review_status)='' THEN 1 ELSE 0 END),0) AS pending_count FROM weekly_updates JOIN internships ON weekly_updates.internship_id = internships.id JOIN users ON internships.student_id = users.id WHERE users.division IN ($faculty_division_sql)";

if($week_filter != "") { $summary_query .= " AND weekly_updates.week_no='$week_filter' "; }
if($status_filter != "") {
    if ($status_filter == 'Pending') {
        $summary_query .= " AND (weekly_updates.review_status='Pending' OR weekly_updates.review_status IS NULL OR TRIM(weekly_updates.review_status)='') ";
    } elseif ($status_filter == 'Accepted') {
        $summary_query .= " AND (weekly_updates.review_status='Accepted' OR weekly_updates.review_status='Approved') ";
    } else {
        $summary_query .= " AND weekly_updates.review_status='$status_filter' ";
    }
}
if($search != "") { $summary_query .= " AND (users.full_name LIKE '%$search%' OR users.enrollment_no LIKE '%$search%' OR internships.company_name LIKE '%$search%') "; }

$summary_result = mysqli_query($conn, $summary_query);
$summary = mysqli_fetch_assoc($summary_result);

function getWeeklyStatusLabel(?string $submittedAt, ?string $deadlineDate, ?string $rejectedAt = null): array {
    if(empty($submittedAt)) return ['label' => 'NO SUBMISSION', 'class' => 'secondary'];
    $submissionTime = strtotime($submittedAt);
    if($submissionTime === false) return ['label' => 'NO SUBMISSION', 'class' => 'secondary'];
    if(empty($deadlineDate)) return ['label' => 'ON TIME', 'class' => 'success'];
    $deadline = strtotime($deadlineDate);
    if(!empty($rejectedAt)) { $deadline = strtotime($rejectedAt) + 86400; }
    if($submissionTime <= $deadline) return ['label' => 'ON TIME', 'class' => 'success'];
    return ['label' => 'LATE', 'class' => 'danger'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Weekly Reports — MIT ADT Faculty Review</title>

<!-- Google Fonts & CSS Libraries -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">

<?php include __DIR__ . '/includes/sidebar_head.php'; ?>

<style>
:root {
    --brand-purple: #4f46e5;
    --brand-purple-dark: #4338ca;
    --brand-purple-light: #eeeffe;
    --brand-purple-subtle: #f5f3ff;
    --bg-canvas: #f8fafc;
    --card-border: #e2e8f0;
    --text-heading: #0f172a;
    --text-body: #334155;
    --text-muted: #64748b;
}

body {
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    background-color: var(--bg-canvas);
    color: var(--text-body);
    margin: 0;
    overflow-x: hidden;
}

.page-inner {
    padding: 24px 28px 60px;
    max-width: 1600px;
    margin: 0 auto;
    width: 100%;
    box-sizing: border-box;
}

/* Header Box */
.page-header-box {
    background: #ffffff;
    border-radius: 14px;
    padding: 20px 24px;
    border: 1px solid var(--card-border);
    box-shadow: 0 4px 18px rgba(15, 23, 42, 0.03);
}

.breadcrumb-item + .breadcrumb-item::before {
    content: "›";
    color: #94a3b8;
}

.page-title {
    font-size: 1.6rem;
    font-weight: 800;
    color: var(--text-heading);
    letter-spacing: -0.02em;
}

/* Filter Card */
.filter-card {
    background: #ffffff;
    border-radius: 14px;
    border: 1px solid var(--card-border);
    box-shadow: 0 4px 18px rgba(15, 23, 42, 0.03);
}

.filter-title {
    font-size: 0.88rem;
    font-weight: 700;
    color: var(--text-heading);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.form-control, .form-select {
    height: 42px;
    border-radius: 8px;
    border: 1px solid var(--card-border);
    padding: 0 12px;
    font-size: 0.88rem;
    color: var(--text-heading);
    background-color: #ffffff;
    transition: all 0.2s ease;
}

.form-control:focus, .form-select:focus {
    border-color: var(--brand-purple);
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.12);
}

/* Statistics Cards */
.stat-card {
    background: #ffffff;
    border-radius: 14px;
    border: 1px solid var(--card-border);
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.02);
    padding: 16px 20px;
    height: 100%;
}

.stat-icon-circle {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
}

.stat-label {
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.6px;
}

.stat-value {
    line-height: 1.1;
    margin-top: 2px;
}

/* Student Initials Avatar */
.avatar-circle {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.78rem;
    flex-shrink: 0;
}

/* HIGH-DENSITY FACULTY DATA TABLE (10 COLUMNS, PERFECT NO-OVERLAP SPACING) */
.table-card {
    background: #ffffff;
    border-radius: 14px;
    border: 1px solid var(--card-border);
    box-shadow: 0 4px 18px rgba(15, 23, 42, 0.03);
    overflow: hidden;
    width: 100%;
    max-width: 100%;
}

.table-card-header {
    background: #ffffff;
    padding: 16px 20px;
    border-bottom: 1px solid var(--card-border);
}

.table-container {
    width: 100%;
    max-width: 100%;
    overflow: hidden;
}

.review-table {
    width: 100%;
    max-width: 100%;
    table-layout: fixed;
    border-collapse: separate;
    border-spacing: 0;
    margin-bottom: 0;
}

.review-table th {
    background: #0f172a;
    color: #ffffff;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 12px 10px;
    vertical-align: middle !important;
    border: none;
    white-space: nowrap;
    box-sizing: border-box;
}

.review-table td {
    padding: 12px 10px;
    vertical-align: middle !important; /* Perfect middle alignment for all rows */
    font-size: 0.85rem;
    color: var(--text-heading);
    border-bottom: 1px solid #f1f5f9;
    box-sizing: border-box;
}

.review-table tbody tr {
    transition: background 0.15s ease;
}

.review-table tbody tr:hover {
    background-color: #f8fafc;
}

/* Student & Company Cell Styling (Strict Natural Word-Boundary Wrapping) */
.student-name-text {
    font-weight: 700;
    color: var(--text-heading);
    line-height: 1.25;
    font-size: 0.86rem;
    overflow-wrap: normal;
    word-break: normal;
    white-space: normal;
}

.student-enrollment-text {
    font-size: 0.76rem;
    color: var(--text-muted);
    font-family: monospace;
    margin-top: 2px;
}

.company-text {
    font-weight: 600;
    color: #475569;
    font-size: 0.83rem;
    line-height: 1.35;
    overflow-wrap: normal;
    word-break: normal;
    word-wrap: normal;
    white-space: normal;
}

/* Work Summary Preview (Unclipped 2-3 lines + Proper Spaced Read More) */
.work-preview {
    max-height: 52px;
    overflow: hidden;
    line-height: 1.45;
    font-size: 0.82rem;
    color: var(--text-body);
    margin-bottom: 3px;
}

.work-preview.expanded {
    max-height: 400px;
    overflow-y: auto;
}

.read-more {
    color: var(--brand-purple);
    font-weight: 700;
    font-size: 0.76rem;
    cursor: pointer;
    display: inline-block;
    margin-top: 2px;
    line-height: 1;
}

.read-more:hover {
    text-decoration: underline;
}

/* Explicit Right-Side Component Spacing (Zero Overlap Guaranteed) */
.review-cell {
    white-space: nowrap !important;
    vertical-align: middle !important;
}

.file-cell {
    white-space: nowrap !important;
    vertical-align: middle !important;
}

.actions-cell {
    white-space: nowrap !important;
    vertical-align: middle !important;
}

.badge-review {
    white-space: nowrap !important;
    flex-shrink: 0 !important;
}

.week-badge {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    height: 26px;
    padding: 0 10px;
    font-size: 0.74rem;
    font-weight: 700;
    border-radius: 6px;
    white-space: nowrap;
    line-height: 1;
}

.btn-file-view {
    white-space: nowrap !important;
    flex-shrink: 0 !important;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

/* Combined Actions Column Layout */
.actions-cell-container {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    white-space: nowrap;
    width: 100%;
}

.actions-cell-container .btn {
    white-space: nowrap !important;
    flex-shrink: 0 !important;
}

.btn-compact {
    font-size: 0.76rem;
    font-weight: 700;
    padding: 5px 9px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    white-space: nowrap;
    transition: all 0.15s ease;
}

/* Empty State Box */
.empty-state-box {
    padding: 50px 20px;
    text-align: center;
}

.empty-state-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: var(--brand-purple-subtle);
    color: var(--brand-purple);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    margin: 0 auto 14px;
}

@media (max-width: 991px) {
    .page-inner {
        padding: 16px 16px 40px;
    }
}
</style>
</head>
<body>

<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="page-inner">

    <!-- Top Hero Header Box -->
    <div class="page-header-box d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 small text-uppercase fw-semibold" style="letter-spacing: 0.5px;">
                    <li class="breadcrumb-item"><a href="dashboard.php" class="text-decoration-none text-muted">Faculty Portal</a></li>
                    <li class="breadcrumb-item active text-primary" aria-current="page">Weekly Reports</li>
                </ol>
            </nav>
            <h1 class="page-title m-0">Student Weekly Reports</h1>
            <p class="page-subtitle text-muted m-0 small mt-1">Review internship progress, weekly submissions, and faculty feedback.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 rounded-pill fw-bold" style="font-size: 0.88rem;">
                <i class="fa-solid fa-graduation-cap me-1.5"></i> Division: <?php echo h($faculty_division); ?>
            </span>
        </div>
    </div>

    <!-- Filter Reports Panel -->
    <div class="filter-card p-3 mb-3">
        <div class="filter-title mb-2"><i class="fa-solid fa-filter me-1.5 text-primary"></i> Filter Reports</div>
        <form method="GET" action="view_updates.php" class="row g-2 align-items-center">
            <div class="col-lg-5 col-md-6">
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                    <input type="text" name="search" class="form-control border-start-0 ps-0" placeholder="Search student name, enrollment number or company..." value="<?php echo h($search); ?>">
                </div>
            </div>
            <div class="col-lg-3 col-md-3 col-6">
                <select name="week" class="form-select">
                    <option value="">All Weeks</option>
                    <?php for ($i = 1; $i <= 4; $i++) { ?>
                        <option value="<?php echo $i; ?>" <?php if ($week_filter == $i) echo "selected"; ?>>Week <?php echo $i; ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-lg-3 col-md-3 col-6">
                <select name="status" class="form-select">
                    <option value="">All Status</option>
                    <option value="Pending" <?php if($status_filter=='Pending') echo 'selected'; ?>>Pending</option>
                    <option value="Accepted" <?php if($status_filter=='Accepted') echo 'selected'; ?>>Accepted</option>
                    <option value="Rejected" <?php if($status_filter=='Rejected') echo 'selected'; ?>>Rejected</option>
                </select>
            </div>
            <div class="col-lg-1 col-md-12">
                <button type="submit" class="btn btn-brand-primary w-100 fw-bold d-flex align-items-center justify-content-center" style="height: 42px; border-radius: 8px; gap: 8px;">
                    <i class="fa-solid fa-magnifying-glass" style="font-size: 0.9rem;"></i> Search
                </button>
            </div>
        </form>
    </div>

    <!-- Summary Statistics Cards -->
    <div class="row g-3 mb-3">
        <div class="col-xl-3 col-md-6">
            <div class="stat-card d-flex align-items-center gap-3" style="border-left: 4px solid var(--brand-purple) !important;">
                <div class="stat-icon-circle bg-primary-subtle text-primary">
                    <i class="fa-solid fa-file-invoice"></i>
                </div>
                <div>
                    <div class="stat-label text-muted text-uppercase">TOTAL REPORTS</div>
                    <div class="stat-value fw-extrabold text-dark fs-4" id="totalCount"><?php echo $summary['total_reports']; ?></div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card d-flex align-items-center gap-3" style="border-left: 4px solid #16a34a !important;">
                <div class="stat-icon-circle bg-success-subtle text-success">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div>
                    <div class="stat-label text-muted text-uppercase">ACCEPTED</div>
                    <div class="stat-value fw-extrabold text-dark fs-4" id="acceptedCount"><?php echo $summary['accepted_count']; ?></div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card d-flex align-items-center gap-3" style="border-left: 4px solid #dc2626 !important;">
                <div class="stat-icon-circle bg-danger-subtle text-danger">
                    <i class="fa-solid fa-circle-xmark"></i>
                </div>
                <div>
                    <div class="stat-label text-muted text-uppercase">REJECTED</div>
                    <div class="stat-value fw-extrabold text-dark fs-4" id="rejectedCount"><?php echo $summary['rejected_count']; ?></div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card d-flex align-items-center gap-3" style="border-left: 4px solid #d97706 !important;">
                <div class="stat-icon-circle bg-warning-subtle text-warning-emphasis">
                    <i class="fa-solid fa-clock"></i>
                </div>
                <div>
                    <div class="stat-label text-muted text-uppercase">PENDING</div>
                    <div class="stat-value fw-extrabold text-dark fs-4" id="pendingCount"><?php echo $summary['pending_count']; ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- MAIN REPORTS CONTINUOUS DATA TABLE (EXACT 10 COLUMNS, PERFECT NO-OVERLAP SPACING) -->
    <?php if (mysqli_num_rows($result) > 0): ?>

        <div class="table-card mb-4">
            <div class="table-card-header d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2">
                    <h5 class="m-0 fw-bold text-dark fs-6">Weekly Reports</h5>
                    <span class="badge bg-secondary-subtle text-secondary rounded-pill px-2.5 py-1 fw-bold"><?php echo mysqli_num_rows($result); ?></span>
                </div>
            </div>

            <div class="table-container">
                <table class="review-table align-middle">
                    <thead>
                        <tr>
                            <th style="width: 3%;">#</th>
                            <th style="width: 15%;">STUDENT</th>
                            <th style="width: 14%;">COMPANY</th>
                            <th style="width: 5%; text-align: center;">WEEK</th>
                            <th style="width: 18%;">WORK SUMMARY</th>
                            <th style="width: 9%;">SUBMITTED</th>
                            <th style="width: 7%; text-align: center;">TIMING</th>
                            <th style="width: 8%; text-align: center;">REVIEW</th>
                            <th style="width: 6%; text-align: center;">FILE</th>
                            <th style="width: 15%; text-align: right;">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $rowIndex = 1;
                        while ($row = mysqli_fetch_assoc($result)) {
                            $statusInfo = getWeeklyStatusLabel($row['submitted_at'] ?? null, $row['deadline_date'] ?? null, $row['rejected_at'] ?? null);
                            $initials = getStudentInitials($row['full_name']);
                            $avatarStyle = getAvatarBgStyle($row['full_name']);
                            $isPending = ($row['review_status'] == 'Pending' || empty(trim((string)$row['review_status'])));
                            $isAccepted = ($row['review_status'] == 'Accepted' || $row['review_status'] == 'Approved');
                            $isRejected = ($row['review_status'] == 'Rejected');
                        ?>
                        <tr id="reportRow_<?php echo (int)$row['id']; ?>" 
                            data-report-id="<?php echo (int)$row['id']; ?>" 
                            data-student-name="<?php echo h($row['full_name']); ?>" 
                            data-enrollment="<?php echo h($row['enrollment_no']); ?>"
                            data-week="<?php echo h($row['week_no']); ?>"
                            data-feedback="<?php echo h($row['faculty_feedback']); ?>">
                            <!-- 1. # -->
                            <td class="fw-bold text-muted" style="font-size: 0.8rem;"><?php echo $rowIndex; ?></td>

                            <!-- 2. STUDENT (AVATAR + FULL UNTRUNCATED NAME + ENROLLMENT) -->
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="avatar-circle" style="background-color: <?php echo $avatarStyle['bg']; ?>; color: <?php echo $avatarStyle['text']; ?>;">
                                        <?php echo h($initials); ?>
                                    </div>
                                    <div>
                                        <div class="student-name-text"><?php echo h($row['full_name']); ?></div>
                                        <div class="student-enrollment-text"><?php echo h($row['enrollment_no']); ?></div>
                                    </div>
                                </div>
                            </td>

                            <!-- 3. COMPANY (PROPER NATURAL WORD-BOUNDARY WRAPPING) -->
                            <td>
                                <div class="company-text" title="<?php echo h($row['company_name']); ?>">
                                    <?php echo h($row['company_name']); ?>
                                </div>
                            </td>

                            <!-- 4. WEEK (PERFECTLY CENTERED BADGE) -->
                            <td class="text-center" style="vertical-align: middle !important;">
                                <div class="d-flex align-items-center justify-content-center w-100 h-100">
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle week-badge">
                                        Week <?php echo h($row['week_no']); ?>
                                    </span>
                                </div>
                            </td>

                            <!-- 5. WORK SUMMARY (FLEXIBLE SPACING WITH UNCLIPPED READ MORE) -->
                            <td>
                                <div class="work-preview" id="work_<?php echo (int)$row['id']; ?>"><?php echo nl2br(h($row['work_done'])); ?></div>
                                <span class="read-more" onclick="toggleWork('work_<?php echo (int)$row['id']; ?>', this)">Read More</span>
                            </td>

                            <!-- 6. SUBMITTED (2-LINE TIMESTAMP) -->
                            <td class="small text-muted lh-sm" style="font-size: 0.78rem; white-space: nowrap;">
                                <?php echo ($row['submitted_at'] != '') ? date("d M Y<b\\r>h:i A", strtotime($row['submitted_at'])) : "-"; ?>
                            </td>

                            <!-- 7. TIMING (ON TIME / LATE) -->
                            <td class="text-center" style="white-space: nowrap;">
                                <div class="d-flex align-items-center justify-content-center w-100 h-100">
                                    <span class="badge bg-<?php echo h($statusInfo['class']); ?> px-2 py-1 fw-bold" style="font-size: 0.72rem;">
                                        <?php echo h($statusInfo['label']); ?>
                                    </span>
                                </div>
                            </td>

                            <!-- 8. REVIEW CELL (ACCEPTED / REJECTED / PENDING) -->
                            <td class="text-center review-cell" id="statusCell_<?php echo (int)$row['id']; ?>">
                                <div class="d-flex align-items-center justify-content-center w-100 h-100">
                                    <?php
                                    if ($isAccepted) {
                                        echo '<span class="badge bg-success px-2 py-1 fw-bold badge-review" style="font-size: 0.72rem;">ACCEPTED</span>';
                                    } elseif ($isRejected) {
                                        echo '<span class="badge bg-danger px-2 py-1 fw-bold badge-review" style="font-size: 0.72rem;">REJECTED</span>';
                                    } else {
                                        echo '<span class="badge bg-warning text-dark px-2 py-1 fw-bold badge-review" style="font-size: 0.72rem;">PENDING</span>';
                                    }
                                    ?>
                                </div>
                            </td>

                            <!-- 9. FILE CELL -->
                            <td class="text-center file-cell">
                                <div class="d-flex align-items-center justify-content-center w-100 h-100">
                                    <?php if ($row['update_file'] != '') { ?>
                                        <a href="../uploads/weekly_reports/<?php echo h($row['update_file']); ?>" target="_blank" class="btn btn-outline-primary btn-sm btn-compact btn-file-view" title="View PDF Report">
                                            <i class="fa-solid fa-file-pdf"></i> View
                                        </a>
                                    <?php } else { echo '<span class="text-muted small">—</span>'; } ?>
                                </div>
                            </td>

                            <!-- 10. ACTIONS CELL (EXACT STATE-BASED ASYNCHRONOUS BUTTON RENDERING) -->
                            <td class="text-end actions-cell">
                                <div class="actions-cell-container" id="actionBtnContainer_<?php echo (int)$row['id']; ?>">
                                    <?php if ($isPending) { ?>
                                        <!-- PENDING REPORT ACTIONS: ACCEPT + REJECT ONLY (NO FEEDBACK BUTTON) -->
                                        <button type="button" onclick="performRowAction(<?php echo (int)$row['id']; ?>, 'approve', this, event)" class="btn btn-success btn-compact" title="Accept Report">
                                            <i class="fa-solid fa-check"></i> Accept
                                        </button>
                                        <button type="button" onclick="performRowAction(<?php echo (int)$row['id']; ?>, 'reject', this, event)" class="btn btn-outline-danger btn-compact" title="Reject Report">
                                            <i class="fa-solid fa-xmark"></i> Reject
                                        </button>
                                    <?php } elseif ($isAccepted) { ?>
                                        <!-- ACCEPTED REPORT ACTIONS: FEEDBACK + UNDO -->
                                        <button type="button" 
                                                class="btn btn-compact btn-feedback-action <?php echo !empty($row['faculty_feedback']) ? 'btn-secondary text-white' : 'btn-outline-secondary'; ?>" 
                                                data-report-id="<?php echo (int)$row['id']; ?>"
                                                data-feedback="<?php echo h($row['faculty_feedback']); ?>"
                                                onclick="openFeedbackModal(<?php echo (int)$row['id']; ?>, this)"
                                                title="Faculty Feedback">
                                            <i class="fa-solid fa-comment-dots"></i> Feedback
                                        </button>
                                        <button type="button" onclick="performRowAction(<?php echo (int)$row['id']; ?>, 'undo', this, event)" class="btn btn-outline-warning text-dark btn-compact" title="Undo Review">
                                            <i class="fa-solid fa-rotate-left"></i> Undo
                                        </button>
                                    <?php } else { ?>
                                        <!-- REJECTED REPORT ACTIONS: UNDO (WITH OPTIONAL FEEDBACK IF PRESENT) -->
                                        <?php if (!empty($row['faculty_feedback'])) { ?>
                                            <button type="button" 
                                                    class="btn btn-secondary text-white btn-compact btn-feedback-action" 
                                                    data-report-id="<?php echo (int)$row['id']; ?>"
                                                    data-feedback="<?php echo h($row['faculty_feedback']); ?>"
                                                    onclick="openFeedbackModal(<?php echo (int)$row['id']; ?>, this)"
                                                    title="Faculty Feedback">
                                                <i class="fa-solid fa-comment-dots"></i> Feedback
                                            </button>
                                        <?php } ?>
                                        <button type="button" onclick="performRowAction(<?php echo (int)$row['id']; ?>, 'undo', this, event)" class="btn btn-outline-warning text-dark btn-compact" title="Undo Review">
                                            <i class="fa-solid fa-rotate-left"></i> Undo
                                        </button>
                                    <?php } ?>
                                </div>
                            </td>
                        </tr>
                        <?php 
                        $rowIndex++;
                        } 
                        ?>
                    </tbody>
                </table>
            </div>

        </div>

    <?php else: ?>

        <!-- EMPTY STATE CARD -->
        <div class="card border-0 shadow-sm rounded-4 text-center mb-4" style="border: 1px solid var(--card-border) !important;">
            <div class="empty-state-box">
                <div class="empty-state-icon">
                    <i class="fa-solid fa-folder-open"></i>
                </div>
                <h4 class="fw-bold text-dark mb-2">No weekly reports found</h4>
                <p class="text-muted mx-auto mb-4" style="max-width: 460px;">
                    No student weekly report submissions match your current search, week, or review status filters.
                </p>
                <a href="view_updates.php" class="btn btn-brand-primary fw-bold px-4 py-2 rounded-3 shadow-sm">
                    <i class="fa-solid fa-arrows-rotate me-1.5"></i> Clear All Filters
                </a>
            </div>
        </div>

    <?php endif; ?>

</div>

<!-- TOP-LEVEL FACULTY FEEDBACK MODAL -->
<div class="modal fade" id="feedbackModal" tabindex="-1" aria-labelledby="feedbackModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <form id="feedbackModalForm">
                <input type="hidden" name="update_id" id="modal_update_id" value="">
                
                <div class="modal-header border-bottom bg-light px-4 py-3">
                    <div>
                        <h5 class="modal-title fw-bold text-dark" id="feedbackModalLabel">Faculty Feedback</h5>
                        <div class="text-muted small mt-0.5" id="modalStudentMeta">Student Feedback Details</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="mb-2">
                        <label class="form-label fw-semibold text-dark small">Feedback Comments</label>
                        <textarea name="faculty_feedback" id="modal_faculty_feedback" class="form-control" rows="5" placeholder="Write feedback for the student..."></textarea>
                    </div>
                </div>

                <div class="modal-footer border-top bg-light px-4 py-3">
                    <button type="button" class="btn btn-light border fw-bold px-3 py-2 rounded-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary fw-bold px-4 py-2 rounded-3">
                        <i class="fa-solid fa-floppy-disk me-1.5"></i> Save Feedback
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Professional In-Page Toast Notification (No Native Browser Alerts) -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1100">
  <div id="ajaxToast" class="toast align-items-center text-white bg-success border-0 rounded-3 shadow-lg" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body fw-bold" id="toastMessage">Action successful.</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  </div>
</div>

<script>
function toggleWork(workBoxId, element) {
    let workBox = document.getElementById(workBoxId);
    if(workBox) {
        if(workBox.classList.contains("expanded")) {
            workBox.classList.remove("expanded");
            element.innerHTML = "Read More";
        } else {
            workBox.classList.add("expanded");
            element.innerHTML = "Show Less";
        }
    }
}

function openFeedbackModal(updateId, btnElement) {
    const rowElement = document.querySelector('[data-report-id="' + updateId + '"]') || document.getElementById('reportRow_' + updateId);
    
    let studentName = '';
    let enrollmentNo = '';
    let weekNo = '';
    let feedbackText = '';

    if (btnElement && btnElement.getAttribute('data-feedback') !== null) {
        feedbackText = btnElement.getAttribute('data-feedback');
    } else if (rowElement && rowElement.getAttribute('data-feedback') !== null) {
        feedbackText = rowElement.getAttribute('data-feedback');
    }

    if (rowElement) {
        studentName = rowElement.getAttribute('data-student-name') || '';
        enrollmentNo = rowElement.getAttribute('data-enrollment') || '';
        weekNo = rowElement.getAttribute('data-week') || '';
    }

    document.getElementById("modal_update_id").value = updateId;
    
    let metaText = studentName ? ("Student: " + studentName) : "Student Feedback Details";
    if (enrollmentNo) metaText += " (" + enrollmentNo + ")";
    if (weekNo) metaText += " | Week " + weekNo;
    
    document.getElementById("modalStudentMeta").textContent = metaText;
    document.getElementById("modal_faculty_feedback").value = feedbackText || "";
    
    const modalEl = document.getElementById("feedbackModal");
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
}

function showToast(message, type = 'success') {
    const toastEl = document.getElementById('ajaxToast');
    const toastBody = document.getElementById('toastMessage');
    toastBody.innerText = message;
    toastEl.className = `toast align-items-center text-white border-0 bg-${type} rounded-3 shadow-lg`;
    const toast = new bootstrap.Toast(toastEl);
    toast.show();
}

function updateSummaryCounts(summary) {
    if(!summary) return;
    if(document.getElementById('totalCount')) document.getElementById('totalCount').innerText = summary.total;
    if(document.getElementById('acceptedCount')) document.getElementById('acceptedCount').innerText = summary.accepted;
    if(document.getElementById('rejectedCount')) document.getElementById('rejectedCount').innerText = summary.rejected;
    if(document.getElementById('pendingCount')) document.getElementById('pendingCount').innerText = summary.pending;
}

// Clean URL from leftover success query parameters without full page reloads
window.addEventListener('DOMContentLoaded', function() {
    const url = new URL(window.location.href);
    if (url.searchParams.has('success') || url.searchParams.has('saved')) {
        const successVal = url.searchParams.get('success') || url.searchParams.get('saved');
        let msg = 'Action completed successfully.';
        if (successVal === 'accepted') msg = 'Report accepted successfully.';
        if (successVal === 'rejected') msg = 'Report rejected successfully.';
        if (successVal === 'undone') msg = 'Report review undone successfully.';
        if (successVal === '1') msg = 'Feedback saved successfully.';
        showToast(msg, 'success');

        url.searchParams.delete('success');
        url.searchParams.delete('saved');
        history.replaceState(null, '', url.pathname + (url.search ? url.search : ''));
    }
});

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

/**
 * CENTRALIZED ROW STATE UPDATER
 * Updates Review badge, Action buttons, data attributes, and summary counters strictly from confirmed server response.
 */
function updateReportRowState(rowElement, serverState) {
    if (!rowElement || !serverState) return;

    const reportId = serverState.report_id || rowElement.getAttribute('data-report-id');
    const newStatus = serverState.new_status || 'Pending';
    const feedback = serverState.faculty_feedback !== undefined ? serverState.faculty_feedback : '';
    const fullName = serverState.full_name || rowElement.getAttribute('data-student-name') || '';
    const enrollmentNo = serverState.enrollment_no || rowElement.getAttribute('data-enrollment') || '';

    // Update row data attributes
    if (fullName) rowElement.setAttribute('data-student-name', fullName);
    if (enrollmentNo) rowElement.setAttribute('data-enrollment', enrollmentNo);
    rowElement.setAttribute('data-feedback', feedback);

    // 1. Update Review Status Badge Cell
    const statusCell = rowElement.querySelector('.review-cell') || document.getElementById('statusCell_' + reportId);
    if (statusCell) {
        let badgeHtml = '';
        if (newStatus === 'Accepted' || newStatus === 'Approved') {
            badgeHtml = '<span class="badge bg-success px-2 py-1 fw-bold badge-review" style="font-size: 0.72rem;">ACCEPTED</span>';
        } else if (newStatus === 'Rejected') {
            badgeHtml = '<span class="badge bg-danger px-2 py-1 fw-bold badge-review" style="font-size: 0.72rem;">REJECTED</span>';
        } else {
            badgeHtml = '<span class="badge bg-warning text-dark px-2 py-1 fw-bold badge-review" style="font-size: 0.72rem;">PENDING</span>';
        }
        statusCell.innerHTML = '<div class="d-flex align-items-center justify-content-center w-100 h-100">' + badgeHtml + '</div>';
    }

    // 2. Update Action Buttons Container
    const actionContainer = rowElement.querySelector('.actions-cell-container') || document.getElementById('actionBtnContainer_' + reportId);
    if (actionContainer) {
        let html = '';
        if (newStatus === 'Accepted' || newStatus === 'Approved') {
            const activeFeedbackClass = feedback.trim() !== '' ? 'btn-secondary text-white' : 'btn-outline-secondary';
            html = '<button type="button" class="btn ' + activeFeedbackClass + ' btn-compact btn-feedback-action" data-report-id="' + reportId + '" data-feedback="' + escapeHtml(feedback) + '" onclick="openFeedbackModal(' + reportId + ', this)" title="Faculty Feedback"><i class="fa-solid fa-comment-dots"></i> Feedback</button><button type="button" onclick="performRowAction(' + reportId + ', \'undo\', this, event)" class="btn btn-outline-warning text-dark btn-compact" title="Undo Review"><i class="fa-solid fa-rotate-left"></i> Undo</button>';
        } else if (newStatus === 'Rejected') {
            const feedbackBtnHtml = feedback.trim() !== '' ? '<button type="button" class="btn btn-secondary text-white btn-compact btn-feedback-action" data-report-id="' + reportId + '" data-feedback="' + escapeHtml(feedback) + '" onclick="openFeedbackModal(' + reportId + ', this)" title="Faculty Feedback"><i class="fa-solid fa-comment-dots"></i> Feedback</button>' : '';
            html = feedbackBtnHtml + '<button type="button" onclick="performRowAction(' + reportId + ', \'undo\', this, event)" class="btn btn-outline-warning text-dark btn-compact" title="Undo Review"><i class="fa-solid fa-rotate-left"></i> Undo</button>';
        } else { // Pending
            html = '<button type="button" onclick="performRowAction(' + reportId + ', \'approve\', this, event)" class="btn btn-success btn-compact" title="Accept Report"><i class="fa-solid fa-check"></i> Accept</button><button type="button" onclick="performRowAction(' + reportId + ', \'reject\', this, event)" class="btn btn-outline-danger btn-compact" title="Reject Report"><i class="fa-solid fa-xmark"></i> Reject</button>';
        }
        actionContainer.innerHTML = html;
    }

    // 3. Update Summary Statistics Counters
    if (serverState.counts || serverState.summary) {
        updateSummaryCounts(serverState.counts || serverState.summary);
    }
}

// Single Asynchronous Action Handler (No Page Reload, Double-Click Protected)
function performRowAction(updateId, actionType, btnElement, evt) {
    if (evt && typeof evt.preventDefault === 'function') {
        evt.preventDefault();
        evt.stopPropagation();
    }
    if (!btnElement || btnElement.disabled) return;
    
    // Locate target row strictly by data-report-id or id attribute
    const rowElement = document.querySelector('[data-report-id="' + updateId + '"]') || document.getElementById('reportRow_' + updateId);
    if (!rowElement) {
        console.error('Target report row not found for report ID:', updateId);
        return;
    }

    const originalContent = btnElement.innerHTML;
    btnElement.disabled = true;
    btnElement.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Processing...';

    const formData = new FormData();
    formData.append('ajax_action', actionType);
    formData.append('update_id', updateId);
    
    const searchEl = document.querySelector('input[name="search"]');
    const weekEl = document.querySelector('select[name="week"]');
    const statusEl = document.querySelector('select[name="status"]');

    formData.append('search', searchEl ? searchEl.value : '');
    formData.append('week_filter', weekEl ? weekEl.value : '');
    formData.append('status_filter', statusEl ? statusEl.value : '');

    fetch('view_updates.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(async (res) => {
        if (!res.ok) throw new Error('HTTP status ' + res.status);
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error('Non-JSON server response:', text);
            throw new Error('Invalid JSON response from server');
        }
    })
    .then(data => {
        if (data && data.success) {
            updateReportRowState(rowElement, data);
            showToast(data.message || 'Action completed successfully.', 'success');
        } else {
            btnElement.disabled = false;
            btnElement.innerHTML = originalContent;
            showToast((data && data.message) ? data.message : 'Unable to update the report. Please try again.', 'danger');
        }
    })
    .catch(err => {
        console.error("Row action error:", err);
        btnElement.disabled = false;
        btnElement.innerHTML = originalContent;
        showToast('Unable to update the report. Please try again.', 'danger');
    });
}

// Asynchronous Feedback Form Submission
document.getElementById('feedbackModalForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const saveBtn = this.querySelector('button[type="submit"]');
    const origContent = saveBtn.innerHTML;
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

    const formData = new FormData(this);
    formData.append('ajax_action', 'save_feedback');
    
    const updateId = document.getElementById('modal_update_id').value;
    const searchEl = document.querySelector('input[name="search"]');
    const weekEl = document.querySelector('select[name="week"]');
    const statusEl = document.querySelector('select[name="status"]');

    formData.append('search', searchEl ? searchEl.value : '');
    formData.append('week_filter', weekEl ? weekEl.value : '');
    formData.append('status_filter', statusEl ? statusEl.value : '');

    fetch('view_updates.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(async (res) => {
        if (!res.ok) throw new Error('HTTP status ' + res.status);
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error('Non-JSON server response:', text);
            throw new Error('Invalid JSON response from server');
        }
    })
    .then(data => {
        saveBtn.disabled = false;
        saveBtn.innerHTML = origContent;
        
        if (data && data.success) {
            const modalEl = document.getElementById("feedbackModal");
            const modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) modal.hide();
            
            const rowElement = document.querySelector('[data-report-id="' + updateId + '"]') || document.getElementById('reportRow_' + updateId);
            if (rowElement) {
                updateReportRowState(rowElement, data);
            }
            
            showToast(data.message || 'Feedback saved successfully.', 'success');
        } else {
            showToast((data && data.message) ? data.message : 'Unable to save feedback.', 'danger');
        }
    })
    .catch(err => {
        console.error('Feedback form error:', err);
        saveBtn.disabled = false;
        saveBtn.innerHTML = origContent;
        showToast('Unable to save feedback. Please try again.', 'danger');
    });
});
</script>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>
</body>
</html>
