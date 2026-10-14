<?php
session_start();
include("../config.php");
require_once __DIR__ . "/../lib/AcademicOrdering.php";

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: ../login.php");
    exit();
}

$id = (int)($_GET['id'] ?? 0);
if(!$id) {
    header("Location: manage_faculty.php");
    exit();
}

$query = "SELECT * FROM users WHERE id=$id AND role='faculty'";
$result = mysqli_query($conn, $query);
$faculty = mysqli_fetch_assoc($result);
if(!$faculty) {
    echo "Faculty not found.";
    exit();
}

// Get divisions
$divQuery = "SELECT division FROM faculty_divisions WHERE faculty_id=$id";
$divResult = mysqli_query($conn, $divQuery);
$divisions = [];
while($dr = mysqli_fetch_assoc($divResult)) {
    $divisions[] = $dr['division'];
}
if(empty($divisions) && !empty($faculty['faculty_division'])) {
    $divisions[] = $faculty['faculty_division'];
}

// Get associated students
$divList = "'" . implode("','", array_map(function($d) use ($conn) { return mysqli_real_escape_string($conn, $d); }, $divisions)) . "'";
$studentsQuery = "
    SELECT u.id, u.full_name, u.enrollment_no, u.division, i.company_name, i.status, se.weekly_total, se.mentor_evaluation, se.final_evaluation
    FROM users u
    LEFT JOIN internships i ON i.student_id = u.id AND i.id IN (SELECT MAX(id) FROM internships GROUP BY student_id)
    LEFT JOIN student_evaluation se ON se.student_id = u.id
    WHERE u.role='student' AND u.division IN ($divList)
    ORDER BY " . 
    AcademicOrdering::getSqlCase("u.specialization", AcademicOrdering::$ty_specializations) . " ASC, " .
    AcademicOrdering::getSqlCase("u.class_division", AcademicOrdering::$ty_classes) . " ASC, " .
    AcademicOrdering::getSqlCase("u.division", AcademicOrdering::$sy_divisions) . " ASC, 
    CAST(COALESCE(NULLIF(u.ty_roll_no,''), NULLIF(u.roll_no,''), '0') AS UNSIGNED) ASC,
    u.full_name ASC
";
$studentsResult = mysqli_query($conn, $studentsQuery);
$students = [];
if ($studentsResult) {
    while($sr = mysqli_fetch_assoc($studentsResult)) {
        $students[] = $sr;
    }
}

// Get Weekly Reports for these students
$reportsQuery = "
    SELECT wu.*, u.full_name, u.enrollment_no
    FROM weekly_updates wu
    JOIN internships i ON wu.internship_id = i.id
    JOIN users u ON i.student_id = u.id
    WHERE u.role='student' AND u.division IN ($divList)
    ORDER BY wu.submitted_at DESC
    LIMIT 10
";
$reportsResult = mysqli_query($conn, $reportsQuery);
$reports = [];
if ($reportsResult) {
    while($rr = mysqli_fetch_assoc($reportsResult)) {
        $reports[] = $rr;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Faculty Detail - <?php echo htmlspecialchars($faculty['full_name']); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?php include __DIR__ . '/includes/sidebar_head.php'; ?>
<style>
body { background: #f1f5f9; font-family: 'Inter', sans-serif; }
.container-box { width: 95%; margin: 30px auto; background: #fff; padding: 30px; border-radius: 16px; }
.card-header { background: #2563eb; color: #fff; border-radius: 10px 10px 0 0 !important; }
</style>
</head>
<body>
<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>
<div class="page-inner">
<div class="container-box">
    
    <h2><?php echo htmlspecialchars($faculty['full_name']); ?>'s Profile</h2>
    <p class="text-muted">Email: <?php echo htmlspecialchars($faculty['email']); ?></p>
    
    <div class="mb-4">
        <h4>Assigned Divisions</h4>
        <?php foreach($divisions as $div): ?>
            <span class="badge bg-primary" style="font-size: 14px;"><?php echo htmlspecialchars($div); ?></span>
        <?php endforeach; ?>
    </div>
    
    <div class="row">
        <div class="col-md-8">
            <div class="card mb-4">
                <div class="card-header">Associated Students (<?php echo count($students); ?>)</div>
                <div class="card-body p-0" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Name</th><th>Enrollment</th><th>Division</th><th>Company</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach($students as $s): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($s['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($s['enrollment_no']); ?></td>
                                <td><?php echo htmlspecialchars($s['division']); ?></td>
                                <td><?php echo htmlspecialchars($s['company_name'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($s['status'] ?? '-'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card mb-4">
                <div class="card-header">Recent Weekly Reports</div>
                <div class="card-body p-0" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-hover mb-0">
                        <tbody>
                            <?php foreach($reports as $r): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($r['full_name']); ?></strong><br>
                                    Week <?php echo $r['week_no']; ?> - <span class="badge bg-<?php echo $r['review_status'] == 'Accepted' ? 'success' : ($r['review_status'] == 'Rejected' ? 'danger' : 'warning'); ?>"><?php echo $r['review_status']; ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($reports)) echo "<tr><td class='p-3 text-muted'>No recent reports.</td></tr>"; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <a href="manage_faculty.php" class="btn btn-secondary">Back to Faculty</a>
</div>
</div>
<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>
</body>
</html>
