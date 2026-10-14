<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty')
{
    header("Location: ../login.php");
    exit();
}

$faculty_id = (int)$_SESSION['user_id'];

$faculty_query = "SELECT full_name FROM users WHERE id='$faculty_id'";
$faculty_result = mysqli_query($conn, $faculty_query);
$faculty_data = mysqli_fetch_assoc($faculty_result);

/* TY evaluations: read-only view (no save logic in this basic version) */
require_once __DIR__ . '/../lib/TYEvaluationDataProvider.php';

$class_division = trim($_GET['class_division'] ?? '');
$specialization = trim($_GET['specialization'] ?? '');
$batch = trim($_GET['batch'] ?? '');

/* Get filter options */
$class_options = [];
$class_result = mysqli_query($conn, "SELECT DISTINCT class_division FROM users WHERE role='student' AND year_of_study='TY' ORDER BY class_division ASC");
while($row = mysqli_fetch_assoc($class_result)) { $class_options[] = $row['class_division']; }

$spec_options = [];
$spec_result = mysqli_query($conn, "SELECT DISTINCT specialization FROM users WHERE role='student' AND year_of_study='TY' ORDER BY specialization ASC");
while($row = mysqli_fetch_assoc($spec_result)) { $spec_options[] = $row['specialization']; }

/* Get data */
$evalData = TYEvaluationDataProvider::getRows($conn, '', '', $specialization, $class_division, $batch, '');
$rows = $evalData['rows'];

$activePage = 'ty_student_evaluations.php';

?>
<!DOCTYPE html>
<html>
<head>
<title>Third Year Student Evaluations — Faculty</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?php include __DIR__ . '/includes/sidebar_head.php'; ?>
<style>
body{ background:#f5f5f5; margin:0; }
.container-box{ width:100%; margin:auto; background:white; padding:20px; border-radius:15px; }
.page-inner{ padding:0 24px 40px; }
.filter-section { background: #f9f9f9; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
.filter-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px; }
</style>
</head>
<body>

<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="page-inner">
<div class="container-box">

<h3 class="fw-bold mb-4">Third Year Student Evaluations</h3>

<div class="filter-section">
<form method="GET">
<div class="filter-row">
<div>
<label class="form-label">TY Class</label>
<select name="class_division" class="form-control">
<option value="">All Classes</option>
<?php foreach($class_options as $opt): ?>
<option value="<?php echo htmlspecialchars($opt); ?>" <?php if($class_division===$opt) echo "selected"; ?>>
<?php echo htmlspecialchars($opt); ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div>
<label class="form-label">Specialization</label>
<select name="specialization" class="form-control">
<option value="">All Specializations</option>
<?php foreach($spec_options as $opt): ?>
<option value="<?php echo htmlspecialchars($opt); ?>" <?php if($specialization===$opt) echo "selected"; ?>>
<?php echo htmlspecialchars($opt); ?>
</option>
<?php endforeach; ?>
</select>
</div>
</div>
<div>
<button type="submit" class="btn btn-success">🔍 Filter</button>
<a href="ty_student_evaluations.php" class="btn btn-secondary">↺ Reset</a>
</div>
</form>
</div>

<div class="table-responsive">
<table class="table table-bordered table-striped table-sm">
<thead class="table-dark">
<tr>
<th>Enrollment No</th>
<th>TY Roll</th>
<th>Name</th>
<th>Class</th>
<th>Batch</th>
<th>W1</th>
<th>W2</th>
<th>W3</th>
<th>W4</th>
<th>Week Total</th>
<th>Mentor</th>
<th>Final</th>
<th>Total /50</th>
</tr>
</thead>
<tbody>
<?php
foreach($rows as $r) {
$total = ($r['weekly_total'] + $r['mentor'] + $r['final']) / 2;
?>
<tr>
<td><?php echo htmlspecialchars($r['enrollment_no']); ?></td>
<td><?php echo htmlspecialchars($r['ty_roll_no']); ?></td>
<td><?php echo htmlspecialchars($r['full_name']); ?></td>
<td><?php echo htmlspecialchars($r['class_division']); ?></td>
<td><?php echo htmlspecialchars($r['batch']); ?></td>
<td><?php echo $r['week1'] > 0 ? $r['week1'] : "—"; ?></td>
<td><?php echo $r['week2'] > 0 ? $r['week2'] : "—"; ?></td>
<td><?php echo $r['week3'] > 0 ? $r['week3'] : "—"; ?></td>
<td><?php echo $r['week4'] > 0 ? $r['week4'] : "—"; ?></td>
<td><?php echo $r['weekly_total'] > 0 ? $r['weekly_total'] : "—"; ?></td>
<td><?php echo $r['mentor'] > 0 ? $r['mentor'] : "—"; ?></td>
<td><?php echo $r['final'] > 0 ? $r['final'] : "—"; ?></td>
<td><strong><?php echo number_format($total, 1) . " / 50"; ?></strong></td>
</tr>
<?php } ?>
</tbody>
</table>
</div>

</div>
</div>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>

</body>
</html>