<?php

session_start();
include("../config.php");
require_once __DIR__ . "/../lib/AcademicOrdering.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
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

/* AJAX HANDLER FOR ADMIN */
if (isset($_POST['ajax_action'])) {
    @set_time_limit(300);
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $response = ['success' => false, 'message' => 'Invalid action'];

    $division_filter = mysqli_real_escape_string($conn, $_POST['division_filter'] ?? '');
    $week_filter = mysqli_real_escape_string($conn, $_POST['week_filter'] ?? '');
    $search = mysqli_real_escape_string($conn, $_POST['search'] ?? '');
    $status_filter = mysqli_real_escape_string($conn, $_POST['status_filter'] ?? '');

    $where_extra = "";
    if ($division_filter != "") { $where_extra .= " AND users.division='$division_filter'"; }
    if ($week_filter != "") { $where_extra .= " AND weekly_updates.week_no='$week_filter'"; }
    if ($search != "") { $where_extra .= " AND (users.full_name LIKE '%$search%' OR users.enrollment_no LIKE '%$search%' OR internships.company_name LIKE '%$search%')"; }

    if ($action === 'approve') {
        $update_id = (int)$_POST['update_id'];
        
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
            
            $response = ['success' => true, 'message' => 'Report approved & graded successfully!'];
        }
    } 
    elseif ($action === 'approve_all') {
        // ONLY ACCEPT SUBMISSIONS UPLOADED ON OR BEFORE 2026-09-28 23:59:59
        $pending_query = "SELECT 
            weekly_updates.id AS update_id,
            weekly_updates.week_no,
            weekly_updates.submitted_at,
            weekly_updates.rejected_at,
            internships.student_id,
            weekly_deadlines.deadline_date
        FROM weekly_updates 
        JOIN internships ON weekly_updates.internship_id = internships.id 
        JOIN users ON internships.student_id = users.id 
        LEFT JOIN weekly_deadlines ON weekly_deadlines.week_no = weekly_updates.week_no AND weekly_deadlines.division = users.division
        WHERE (weekly_updates.review_status='Pending' OR weekly_updates.review_status IS NULL OR TRIM(weekly_updates.review_status)='')
          AND weekly_updates.submitted_at <= '2026-09-28 23:59:59' $where_extra";

        $pending_res = mysqli_query($conn, $pending_query);
        $approved_count = 0;
        
        while ($report = mysqli_fetch_assoc($pending_res)) {
            $update_id = $report['update_id'];
            $student_id = $report['student_id'];
            $week_no = $report['week_no'];
            
            mysqli_query($conn, "UPDATE weekly_updates SET review_status='Accepted', faculty_feedback='' WHERE id='$update_id'");
            
            $marks = calculateWeeklyMarks($report['submitted_at'], $report['deadline_date'], $report['rejected_at']);
            
            $checkEval = mysqli_query($conn, "SELECT id FROM student_evaluation WHERE student_id='$student_id'");
            if (mysqli_num_rows($checkEval) == 0) {
                mysqli_query($conn, "INSERT INTO student_evaluation (student_id) VALUES ('$student_id')");
            }
            
            $weekColumn = "week" . $week_no . "_marks";
            mysqli_query($conn, "UPDATE student_evaluation SET $weekColumn='$marks' WHERE student_id='$student_id'");
            mysqli_query($conn, "UPDATE student_evaluation SET weekly_total = week1_marks + week2_marks + week3_marks + week4_marks, total_marks = ROUND((weekly_total + mentor_evaluation + final_evaluation) / 2) WHERE student_id='$student_id'");
            
            $approved_count++;
        }
        
        $response = ['success' => true, 'count' => $approved_count, 'message' => $approved_count . ' pending reports (submitted before 28-09-2026) approved & graded!'];
    }
    elseif ($action === 'reapprove_all') {
        // OPTIMIZED HIGH-PERFORMANCE RE-CALCULATE AND SYNC ALL MARKS SYSTEM-WIDE
        $sync_query = "SELECT 
            weekly_updates.id AS update_id,
            weekly_updates.week_no,
            weekly_updates.submitted_at,
            weekly_updates.rejected_at,
            internships.student_id,
            weekly_deadlines.deadline_date
        FROM weekly_updates 
        JOIN internships ON weekly_updates.internship_id = internships.id 
        JOIN users ON internships.student_id = users.id 
        LEFT JOIN weekly_deadlines ON weekly_deadlines.week_no = weekly_updates.week_no AND weekly_deadlines.division = users.division
        WHERE (weekly_updates.review_status='Accepted' OR weekly_updates.review_status='Approved') $where_extra";

        $sync_res = mysqli_query($conn, $sync_query);
        $synced_count = 0;
        
        while ($report = mysqli_fetch_assoc($sync_res)) {
            $student_id = $report['student_id'];
            $week_no = $report['week_no'];
            
            $marks = calculateWeeklyMarks($report['submitted_at'], $report['deadline_date'], $report['rejected_at']);
            
            $checkEval = mysqli_query($conn, "SELECT id FROM student_evaluation WHERE student_id='$student_id'");
            if (mysqli_num_rows($checkEval) == 0) {
                mysqli_query($conn, "INSERT INTO student_evaluation (student_id) VALUES ('$student_id')");
            }
            
            $weekColumn = "week" . $week_no . "_marks";
            mysqli_query($conn, "UPDATE student_evaluation SET $weekColumn='$marks' WHERE student_id='$student_id'");
            mysqli_query($conn, "UPDATE student_evaluation SET weekly_total = week1_marks + week2_marks + week3_marks + week4_marks, total_marks = ROUND((weekly_total + mentor_evaluation + final_evaluation) / 2) WHERE student_id='$student_id'");
            
            $synced_count++;
        }
        
        $response = ['success' => true, 'count' => $synced_count, 'message' => $synced_count . ' reports recalculated & synced to evaluation sheet!'];
    }
    
    // Summary Counts Calculation
    $summary_query = "SELECT COUNT(*) AS total_reports, IFNULL(SUM(CASE WHEN review_status='Accepted' OR review_status='Approved' THEN 1 ELSE 0 END),0) AS accepted_count, IFNULL(SUM(CASE WHEN review_status='Rejected' THEN 1 ELSE 0 END),0) AS rejected_count, IFNULL(SUM(CASE WHEN review_status='Pending' OR review_status IS NULL OR TRIM(review_status)='' THEN 1 ELSE 0 END),0) AS pending_count FROM weekly_updates JOIN internships ON weekly_updates.internship_id = internships.id JOIN users ON internships.student_id = users.id WHERE 1=1 $where_extra";
    
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
        'total' => $summary['total_reports'],
        'accepted' => $summary['accepted_count'],
        'rejected' => $summary['rejected_count'],
        'pending' => $summary['pending_count']
    ];
    
    echo json_encode($response);
    exit();
}

$activePage = 'view_updates.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$division_filter = "";
$search = "";
$week_filter = "";
$status_filter = "";
$batch_filter = "";
$student_id_filter = "";

if (isset($_GET['division'])) { $division_filter = mysqli_real_escape_string($conn, $_GET['division']); }
if (isset($_GET['search'])) { $search = mysqli_real_escape_string($conn, $_GET['search']); }
if (isset($_GET['week'])) { $week_filter = mysqli_real_escape_string($conn, $_GET['week']); }
if (isset($_GET['status'])) { $status_filter = mysqli_real_escape_string($conn, $_GET['status']); }
if (isset($_GET['batch'])) { $batch_filter = mysqli_real_escape_string($conn, $_GET['batch']); }
if (isset($_GET['student_id'])) { $student_id_filter = mysqli_real_escape_string($conn, $_GET['student_id']); }

/* SAVE FACULTY FEEDBACK */
if (isset($_POST['save_feedback'])) {
    $update_id = (int)$_POST['update_id'];
    $faculty_feedback = mysqli_real_escape_string($conn, $_POST['faculty_feedback']);

    mysqli_query($conn, "UPDATE weekly_updates SET faculty_feedback='$faculty_feedback' WHERE id='$update_id'");

    echo "<script>alert('Feedback Saved Successfully'); window.location='view_updates.php';</script>";
    exit();
}

/* ACCEPT REPORT */
if (isset($_POST['accept_report'])) {
    $update_id = (int)$_POST['update_id'];
    $faculty_feedback = mysqli_real_escape_string($conn, $_POST['faculty_feedback']);

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

    echo "<script>alert('Weekly Report Accepted'); window.location='view_updates.php';</script>";
    exit();
}

/* REJECT REPORT */
if (isset($_POST['reject_report'])) {
    $update_id = (int)$_POST['update_id'];
    $faculty_feedback = mysqli_real_escape_string($conn, $_POST['faculty_feedback']);

    mysqli_query($conn, "UPDATE weekly_updates SET review_status='Rejected', faculty_feedback='$faculty_feedback', rejected_at=NOW() WHERE id='$update_id'");

    echo "<script>alert('Weekly Report Rejected'); window.location='view_updates.php';</script>";
    exit();
}

/* UNDO REPORT */
if (isset($_POST['undo_report'])) {
    $update_id = (int)$_POST['update_id'];
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

    echo "<script>alert('Report Status Undone'); window.location='view_updates.php';</script>";
    exit();
}

/* MAIN QUERY */
$query = "SELECT weekly_updates.id AS update_id, weekly_updates.*, users.full_name, users.enrollment_no, users.roll_no, users.division, users.class_division, users.year_of_study, users.id AS student_id, internships.company_name FROM weekly_updates JOIN internships ON weekly_updates.internship_id = internships.id JOIN users ON internships.student_id = users.id WHERE 1=1";

if ($division_filter != "") { $query .= " AND users.division='$division_filter'"; }
if ($search != "") { $query .= " AND (users.full_name LIKE '%$search%' OR users.enrollment_no LIKE '%$search%' OR internships.company_name LIKE '%$search%')"; }
if ($week_filter != "") { $query .= " AND weekly_updates.week_no='$week_filter'"; }
if ($batch_filter != "") { $query .= " AND users.batch='$batch_filter'"; }
if ($student_id_filter != "") { $query .= " AND users.id='$student_id_filter'"; }

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

$summary_query = "SELECT COUNT(*) AS total_reports, IFNULL(SUM(CASE WHEN review_status='Accepted' OR review_status='Approved' THEN 1 ELSE 0 END),0) AS accepted_count, IFNULL(SUM(CASE WHEN review_status='Rejected' THEN 1 ELSE 0 END),0) AS rejected_count, IFNULL(SUM(CASE WHEN review_status='Pending' OR review_status IS NULL OR TRIM(review_status)='' THEN 1 ELSE 0 END),0) AS pending_count FROM weekly_updates JOIN internships ON weekly_updates.internship_id = internships.id JOIN users ON internships.student_id = users.id WHERE 1=1";

if ($division_filter != "") { $summary_query .= " AND users.division='$division_filter'"; }
if ($search != "") { $summary_query .= " AND (users.full_name LIKE '%$search%' OR users.enrollment_no LIKE '%$search%' OR internships.company_name LIKE '%$search%')"; }
if ($week_filter != "") { $summary_query .= " AND weekly_updates.week_no='$week_filter'"; }
if ($batch_filter != "") { $summary_query .= " AND users.batch='$batch_filter'"; }
if ($student_id_filter != "") { $summary_query .= " AND users.id='$student_id_filter'"; }

if ($status_filter != "") {
    if ($status_filter == 'Pending') {
        $summary_query .= " AND (weekly_updates.review_status='Pending' OR weekly_updates.review_status IS NULL OR TRIM(weekly_updates.review_status)='')";
    } elseif ($status_filter == 'Accepted') {
        $summary_query .= " AND (weekly_updates.review_status='Accepted' OR weekly_updates.review_status='Approved')";
    } else {
        $summary_query .= " AND weekly_updates.review_status='$status_filter'";
    }
}

$summary_result = mysqli_query($conn, $summary_query);
$summary = mysqli_fetch_assoc($summary_result);

function getWeeklyStatusLabel(?string $submittedAt, ?string $deadlineDate, ?string $rejectedAt = null): array {
    if(empty($submittedAt)) return ['label' => 'No Submission', 'class' => 'secondary'];
    $submissionTime = strtotime($submittedAt);
    if($submissionTime === false) return ['label' => 'No Submission', 'class' => 'secondary'];
    if(empty($deadlineDate)) return ['label' => 'On Time', 'class' => 'success'];
    $deadline = strtotime($deadlineDate);
    if(!empty($rejectedAt)) { $deadline = strtotime($rejectedAt) + 86400; }
    if($submissionTime <= $deadline) return ['label' => 'On Time', 'class' => 'success'];
    return ['label' => 'Late', 'class' => 'danger'];
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Admin - Student Weekly Reports</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{ background:#eef2f7; font-family:'Segoe UI',sans-serif; }
.container-box{ width:98%; margin:auto; margin-top:20px; background:#fff; padding:25px; border-radius:20px; box-shadow:0 10px 30px rgba(0,0,0,0.08); }
.page-title{ font-size:32px; font-weight:700; color:#0f172a; }
.page-subtitle{ color:#64748b; font-size:14px; }
.filter-box{ background:white; padding:20px; border-radius:16px; box-shadow:0 4px 20px rgba(0,0,0,0.05); margin-bottom:25px; }
.form-control, .form-select{ height:46px; border-radius:12px; border:1px solid #dbe4ee; font-size:13.5px; }
.stat-card{ border:none; border-radius:18px; color:white; }
.stat-number{ font-size:32px; font-weight:700; }
.stat-title{ font-size:13px; color:rgba(255,255,255,0.9); margin-bottom:6px; font-weight:500; }
.table{ border-radius:16px; overflow:hidden; table-layout:fixed; width:100%; }
.table th, .table td{ word-wrap:break-word; overflow-wrap:break-word; font-size:12px; padding:10px 8px; }
.table thead{ background:#0f172a; color:white; }
.badge{ padding:5px 8px; border-radius:30px; font-size:11px; font-weight:600; }
.feedback-box{ width:100%; height:70px; border-radius:10px; border:1px solid #dbe4ee; resize:none; font-size:12px; padding:6px; }
.btn{ border-radius:10px; font-size:13px; }
.small-btn{ font-size:11px; padding:4px 8px; }
.work-preview{ max-height:80px; overflow:hidden; }
.work-preview.expanded{ max-height:none; }
.read-more{ color:#2563eb; font-weight:600; cursor:pointer; font-size:11px; }
</style>
<?php include __DIR__ . '/includes/sidebar_head.php'; ?>
<style> body{ margin:0; } .page-inner{ padding:0 20px 40px; } </style>
</head>
<body>
<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>
<div class="page-inner">
<div class="container-box">
<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h1 class="page-title mb-1">Weekly Reports Management</h1>
        <div class="page-subtitle">Admin Overview — Approve, Grade & Sync Weekly Submissions</div>
    </div>
</div>

<div class="filter-box">
<form method="GET">
<div class="row g-2">
<div class="col-md-3">
<input type="text" name="search" class="form-control" placeholder="Search Student / Enrollment / Company" value="<?php echo h($search); ?>">
</div>

<div class="col-md-2">
<select name="division" class="form-select">
<option value="">All Divisions</option>
<?php
$divisions = mysqli_query($conn,"SELECT DISTINCT division FROM users WHERE role='student' AND division IS NOT NULL AND TRIM(division)<>'' ORDER BY division ASC");
while($d=mysqli_fetch_assoc($divisions)) {
?>
<option value="<?php echo $d['division'];?>" <?php if($division_filter==$d['division']) echo "selected";?>><?php echo $d['division'];?></option>
<?php } ?>
</select>
</div>

<div class="col-md-2">
<select name="week" class="form-select">
<option value="">Select Week</option>
<?php for ($i = 1; $i <= 4; $i++) { ?>
<option value="<?php echo $i; ?>" <?php if ($week_filter == $i) echo "selected"; ?>>Week <?php echo $i; ?></option>
<?php } ?>
</select>
</div>

<div class="col-md-2">
<select name="status" class="form-select">
<option value="">All Status</option>
<option value="Pending" <?php if($status_filter=='Pending') echo 'selected'; ?>>Pending</option>
<option value="Accepted" <?php if($status_filter=='Accepted') echo 'selected'; ?>>Accepted</option>
<option value="Rejected" <?php if($status_filter=='Rejected') echo 'selected'; ?>>Rejected</option>
</select>
</div>

<div class="col-md-1">
<button type="submit" class="btn btn-primary w-100">Search</button>
</div>

<div class="col-md-2">
<a href="view_updates.php" class="btn btn-secondary w-100">Reset</a>
</div>
</div>

<div class="row g-2 mt-2">
<div class="col-md-6">
<button type="button" id="approveAllBtn" onclick="ajaxApproveAll()" class="btn btn-success w-100 fw-bold">Approve Pending (Uploaded On / Before 28-09-2026)</button>
</div>
<div class="col-md-6">
<button type="button" id="reapproveAllBtn" onclick="ajaxReapproveAll()" class="btn btn-warning w-100 fw-bold">Sync & Calculate All Marks</button>
</div>
</div>
</form>
</div>

<div class="row mb-4">
<div class="col-md-3">
<div class="card stat-card bg-primary">
<div class="card-body text-center p-3">
<div class="stat-title">📄 Total Reports</div>
<div class="stat-number" id="totalCount"><?php echo $summary['total_reports']; ?></div>
</div>
</div>
</div>

<div class="col-md-3">
<div class="card stat-card bg-success">
<div class="card-body text-center p-3">
<div class="stat-title">✅ Accepted</div>
<div class="stat-number" id="acceptedCount"><?php echo $summary['accepted_count']; ?></div>
</div>
</div>
</div>

<div class="col-md-3">
<div class="card stat-card bg-danger">
<div class="card-body text-center p-3">
<div class="stat-title">❌ Rejected</div>
<div class="stat-number" id="rejectedCount"><?php echo $summary['rejected_count']; ?></div>
</div>
</div>
</div>

<div class="col-md-3">
<div class="card stat-card bg-warning">
<div class="card-body text-center p-3">
<div class="stat-title">⏳ Pending</div>
<div class="stat-number" id="pendingCount"><?php echo $summary['pending_count']; ?></div>
</div>
</div>
</div>
</div>

<div class="table-responsive shadow-sm rounded">
<table class="table table-bordered table-striped">
<thead>
<tr>
<th style="width:4%;">Roll</th>
<th style="width:10%;">Student</th>
<th style="width:9%;">Enrollment</th>
<th style="width:7%;">Division</th>
<th style="width:8%;">Company</th>
<th style="width:5%;">Week</th>
<th style="width:11%;">Work Done</th>
<th style="width:8%;">Submission</th>
<th style="width:7%;">Status</th>
<th style="width:7%;">Review</th>
<th style="width:6%;">File</th>
<th style="width:11%;">Faculty Feedback</th>
<th style="width:7%;">Action</th>
</tr>
</thead>

<tbody>
<?php
if (mysqli_num_rows($result) > 0) {
    while ($row = mysqli_fetch_assoc($result)) {
        $deadline_query = "SELECT deadline_date FROM weekly_deadlines WHERE week_no='" . mysqli_real_escape_string($conn, $row['week_no']) . "' AND division='" . mysqli_real_escape_string($conn, $row['division']) . "' LIMIT 1";
        $deadline_result = mysqli_query($conn, $deadline_query);
        $deadline_data = mysqli_fetch_assoc($deadline_result);

        $statusInfo = getWeeklyStatusLabel($row['submitted_at'] ?? null, $deadline_data['deadline_date'] ?? null, $row['rejected_at'] ?? null);
?>
<tr>
<td><?php echo h($row['roll_no']); ?></td>
<td><?php echo h($row['full_name']); ?></td>
<td><?php echo h($row['enrollment_no']); ?></td>
<td><span class="badge bg-secondary"><?php echo h($row['division']); ?></span></td>
<td><?php echo h($row['company_name']); ?></td>
<td>Week <?php echo h($row['week_no']); ?></td>
<td class="work-column">
<div class="work-preview" id="work_<?php echo (int)$row['update_id']; ?>"><?php echo nl2br(h($row['work_done'])); ?></div>
<span class="read-more" onclick="toggleWork(<?php echo (int)$row['update_id']; ?>, this)">Read More</span>
</td>

<td><?php echo ($row['submitted_at'] != '') ? date("d M Y h:i A", strtotime($row['submitted_at'])) : "-"; ?></td>

<td><span class="badge bg-<?php echo h($statusInfo['class']); ?>"><?php echo h($statusInfo['label']); ?></span></td>

<td>
<?php
if ($row['review_status'] == 'Accepted' || $row['review_status'] == 'Approved') {
    echo "<span class='badge bg-success'>Accepted</span>";
} elseif ($row['review_status'] == 'Rejected') {
    echo "<span class='badge bg-danger'>Rejected</span>";
} else {
    echo "<span class='badge bg-warning text-dark'>Pending</span>";
}
?>
</td>

<td>
<?php if ($row['update_file'] != '') { ?>
<a href="../uploads/weekly_reports/<?php echo h($row['update_file']); ?>" target="_blank" class="btn btn-primary btn-sm small-btn">View File</a>
<?php } else { echo "-"; } ?>
</td>

<td>
<form method="POST">
<textarea name="faculty_feedback" class="form-control feedback-box" placeholder="Enter feedback"><?php echo h($row['faculty_feedback']); ?></textarea>
<input type="hidden" name="update_id" value="<?php echo (int)$row['update_id']; ?>">
</td>

<td>
<div class="d-flex flex-column gap-1">
<?php if ($row['review_status'] == 'Pending' || empty(trim($row['review_status']))) { ?>
<button type="button" onclick="ajaxApprove(<?php echo (int)$row['update_id']; ?>, this)" class="btn btn-success btn-sm small-btn">Accept</button>
<button type="submit" name="reject_report" class="btn btn-danger btn-sm small-btn">Reject</button>
<?php } else { ?>
<button type="submit" name="undo_report" class="btn btn-warning btn-sm small-btn">Undo</button>
<?php } ?>
</div>
</form>
</td>

</tr>
<?php
    }
} else {
?>
<tr><td colspan="13" class="text-center text-danger">No weekly reports found.</td></tr>
<?php } ?>
</tbody>
</table>
</div>

</div>
</div>
</main>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1100">
  <div id="ajaxToast" class="toast align-items-center text-white bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body" id="toastMessage">Action successful.</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  </div>
</div>

<script>
function toggleWork(id, element) {
    let workBox = document.getElementById("work_" + id);
    if(workBox.classList.contains("expanded")) {
        workBox.classList.remove("expanded");
        element.innerHTML = "Read More";
    } else {
        workBox.classList.add("expanded");
        element.innerHTML = "Show Less";
    }
}

function showToast(message, type = 'success') {
    const toastEl = document.getElementById('ajaxToast');
    const toastBody = document.getElementById('toastMessage');
    toastBody.innerText = message;
    toastEl.className = `toast align-items-center text-white border-0 bg-${type}`;
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

function ajaxApprove(updateId, btnElement) {
    const originalText = btnElement.innerHTML;
    btnElement.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    btnElement.disabled = true;

    const formData = new FormData();
    formData.append('ajax_action', 'approve');
    formData.append('update_id', updateId);
    formData.append('search', document.querySelector('input[name="search"]').value);
    formData.append('division_filter', document.querySelector('select[name="division"]').value);
    formData.append('week_filter', document.querySelector('select[name="week"]').value);
    formData.append('status_filter', document.querySelector('select[name="status"]').value);

    fetch('view_updates.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if(data.success) {
            showToast(data.message, 'success');
            if(data.summary) updateSummaryCounts(data.summary);
            const row = btnElement.closest('tr');
            const statusCell = row.querySelectorAll('td')[9];
            if (statusCell) { statusCell.innerHTML = '<span class="badge bg-success">Accepted</span>'; }
            btnElement.remove();
        } else {
            showToast(data.message || 'Approval failed', 'danger');
            btnElement.innerHTML = originalText;
            btnElement.disabled = false;
        }
    })
    .catch(err => {
        showToast('Network error occurred.', 'danger');
        btnElement.innerHTML = originalText;
        btnElement.disabled = false;
    });
}

function ajaxApproveAll() {
    const pendingCount = parseInt(document.getElementById('pendingCount').innerText) || 0;
    if(pendingCount === 0) {
        alert("No pending reports to approve.");
        return;
    }
    
    if(!confirm(`Approve & grade all pending reports uploaded ON OR BEFORE 28-09-2026? Submissions uploaded after 28-09-2026 will NOT be approved.`)) {
        return;
    }

    const btn = document.getElementById('approveAllBtn');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Processing...';
    btn.disabled = true;

    const formData = new FormData();
    formData.append('ajax_action', 'approve_all');
    formData.append('search', document.querySelector('input[name="search"]').value);
    formData.append('division_filter', document.querySelector('select[name="division"]').value);
    formData.append('week_filter', document.querySelector('select[name="week"]').value);
    formData.append('status_filter', document.querySelector('select[name="status"]').value);

    fetch('view_updates.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if(data.success) {
            showToast(data.message, 'success');
            if(data.summary) updateSummaryCounts(data.summary);
            window.location.reload();
        } else {
            showToast(data.message || 'Bulk approval failed', 'danger');
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    })
    .catch(err => {
        showToast('Network error occurred.', 'danger');
        btn.innerHTML = originalText;
        btn.disabled = false;
    });
}

function ajaxReapproveAll() {
    if(!confirm(`Sync & calculate marks for all accepted/approved reports system-wide?`)) {
        return;
    }

    const btn = document.getElementById('reapproveAllBtn');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Syncing...';
    btn.disabled = true;

    const formData = new FormData();
    formData.append('ajax_action', 'reapprove_all');
    formData.append('search', document.querySelector('input[name="search"]').value);
    formData.append('division_filter', document.querySelector('select[name="division"]').value);
    formData.append('week_filter', document.querySelector('select[name="week"]').value);
    formData.append('status_filter', document.querySelector('select[name="status"]').value);

    fetch('view_updates.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if(data.success) {
            showToast(data.message, 'success');
            if(data.summary) updateSummaryCounts(data.summary);
            btn.innerHTML = originalText;
            btn.disabled = false;
        } else {
            showToast(data.message || 'Sync failed', 'danger');
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    })
    .catch(err => {
        showToast('Network error occurred.', 'danger');
        btn.innerHTML = originalText;
        btn.disabled = false;
    });
}
</script>
<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>
</body>
</html>