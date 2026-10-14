<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    header("Location: ../login.php");
    exit();
}

/* -----------------------------------------------------------------
   REPLACE — this is the actual Reports-module report generator that
   admin/reports.php's "View Report" form submits to. The audit
   (earlier this session) found this file completely untouched, with
   several real problems fixed here:

   1. SQL injection: $_GET['division'] was concatenated directly into
      the query with no escaping. Converted to a prepared statement.
   2. XSS: every value (division, roll_no, full_name, company_name,
      etc.) was echoed with no escaping.
   3. Only a single division could ever be viewed — no "All
      Divisions" option existed in admin/reports.php's dropdown
      either (that dropdown was also missing IT-DA/IT-SMAD entirely
      before this session; both gaps are now fixed).
   4. No IT-DA-first / IT-SMAD-second / numeric-roll ordering.
   5. The only export option was a link to download_excel.php (the
      legacy HTML-served-as-.xls file, the same mechanism responsible
      for the Oct-50/Dec-50 bug elsewhere in the project). Replaced
      with real Export Excel (.xlsx via ExcelExporter) and Export PDF
      (via PdfExporter) buttons, matching the same export mechanism
      used for Student Evaluations.
   6. No sidebar — added, consistent with every other admin page.
   ----------------------------------------------------------------- */

$division = trim($_GET['division'] ?? '');
$export = trim($_GET['export'] ?? '');

$divisions = ($division !== '' && $division !== 'ALL') ? [$division] : [];

$where = ["users.role='student'"];
$params = [];
$types = "";

if(!empty($divisions))
{
    $where[] = "users.division=?";
    $params[] = $divisions[0];
    $types .= "s";
}

$whereSql = implode(" AND ", $where);

$sql = "
    SELECT
        users.full_name,
        users.division,
        users.roll_no,
        users.enrollment_no,

        internships.company_name,
        internships.internship_role,
        internships.internship_type,
        internships.start_date,
        internships.end_date,
        internships.status

    FROM users

    LEFT JOIN internships
        ON internships.id = (
            SELECT MAX(i.id) FROM internships i WHERE i.student_id = users.id
        )

    WHERE $whereSql

    ORDER BY
        CASE users.division
            WHEN 'IT-DA'   THEN 0
            WHEN 'IT-SMAD' THEN 1
            ELSE 2
        END ASC,
        users.division ASC,
        CAST(users.roll_no AS UNSIGNED) ASC
";

$stmt = $conn->prepare($sql);

if(count($params) > 0)
{
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while($row = mysqli_fetch_assoc($result))
{
    $rows[] = $row;
}

$division_label = ($division !== '' && $division !== 'ALL') ? $division : "All Divisions";

/* -----------------------------------------------------------------
   EXPORT — Excel (.xlsx) and PDF, both real files, both built from
   the exact same $rows the on-screen table renders, so they can
   never drift apart.
   ----------------------------------------------------------------- */

if($export === 'excel')
{
    require_once __DIR__ . '/../lib/ExcelExporter.php';

    $headers = [
        "Roll No", "Enrollment No", "Student Name", "Division",
        "Company", "Role", "Internship Type", "Start Date", "End Date", "Status"
    ];

    $columnMap = [
        "Roll No"          => "roll_no",
        "Enrollment No"    => "enrollment_no",
        "Student Name"     => "full_name",
        "Division"         => "division",
        "Company"          => "company_name",
        "Role"             => "internship_role",
        "Internship Type"  => "internship_type",
        "Start Date"       => "start_date",
        "End Date"         => "end_date",
        "Status"           => "status",
    ];

    try {
        ExcelExporter::streamStudentReport(
            "MIT Art, Design and Technology University — Internship Report",
            "Division: " . $division_label . "   |   Total Students: " . count($rows),
            $headers,
            $rows,
            $columnMap,
            "Internship_Report_" . ($division !== '' && $division !== 'ALL' ? $division : "All_Divisions"),
            "Internship Report"
        );
    } catch (ExcelExporterException $e) {
        ExcelExporter::renderSetupError($e->getMessage());
    }

    exit();
}

if($export === 'pdf')
{
    require_once __DIR__ . '/../lib/PdfExporter.php';

    $headers = ["Roll No", "Student Name", "Division", "Company", "Role", "Type", "Start", "End", "Status"];
    $columnWidths = [7, 15, 8, 14, 12, 12, 8, 8, 8];

    $pdfRows = [];
    foreach($rows as $r)
    {
        $pdfRows[] = [
            $r['roll_no'],
            $r['full_name'],
            $r['division'],
            $r['company_name'] ?: '-',
            $r['internship_role'] ?: '-',
            $r['internship_type'] ?: '-',
            $r['start_date'] ?: '-',
            $r['end_date'] ?: '-',
            $r['status'] ?: 'Not Applied',
        ];
    }

    $subtitle = "Division: " . $division_label . "   |   Total Students: " . count($rows) .
        "   |   Generated: " . date('d M Y, h:i A');

    PdfExporter::streamEvaluationTable(
        "MIT Art, Design and Technology University",
        "Internship Report",
        $subtitle,
        $headers,
        $columnWidths,
        $pdfRows,
        "Internship_Report_" . ($division !== '' && $division !== 'ALL' ? $division : "All_Divisions")
    );

    exit();
}

?>

<!DOCTYPE html>
<html>
<head>

<title>Division Report</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?php include __DIR__ . '/includes/sidebar_head.php'; ?>

<style>

body{
    background:#f5f7fb;
    margin:0;
}

.page-inner{
    padding:0 24px 40px;
}

.container-box{
    width:100%;
    margin:24px auto;
    background:#fff;
    padding:35px;
    border-radius:20px;
    box-shadow:0 10px 30px rgba(0,0,0,.08);
}

.report-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:25px;
    flex-wrap:wrap;
    gap:16px;
}

.report-title h2{
    font-weight:700;
    margin-bottom:5px;
}

.report-title p{
    color:#6c757d;
    margin:0;
}

.action-buttons{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
}

.table{
    margin-top:20px;
}

.table thead th{
    background:#212529;
    color:white;
    vertical-align:middle;
}

.table td{
    vertical-align:middle;
}

.badge{
    font-size:13px;
    padding:8px 12px;
    border-radius:20px;
}

.btn{
    border-radius:10px;
    font-weight:600;
}

@media print{

.no-print{
display:none;
}

.container-box{
box-shadow:none;
border:none;
padding:0;
}

body{
background:white;
}

}

</style>

</head>

<body>

<?php $activePage = 'reports.php'; include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="page-inner">
<div class="container-box">

    <div class="report-header">

<div class="report-title">

<a href="reports.php"
class="btn btn-primary mb-3 no-print">

← Back

</a>

<h2>

Internship Report

</h2>

<p>

Division :
<strong><?php echo htmlspecialchars($division_label, ENT_QUOTES, 'UTF-8'); ?></strong>
&nbsp;|&nbsp;
Total Students : <strong><?php echo count($rows); ?></strong>

</p>

</div>

<div class="action-buttons no-print">

<button
onclick="window.print()"
class="btn btn-dark">

🖨 Print

</button>

<a href="export_report.php?division=<?php echo urlencode($division); ?>&export=excel"
class="btn btn-success">

📊 Export Excel

</a>

<a href="export_report.php?division=<?php echo urlencode($division); ?>&export=pdf"
class="btn btn-danger">

📄 Export PDF

</a>

</div>

</div>

    <div class="table-responsive">

<table class="table table-bordered table-hover align-middle">

        <thead class="table-dark">

            <tr>

                <th>Roll No</th>
                <th>Enrollment No</th>
                <th>Student</th>
                <th>Division</th>
                <th>Company</th>
                <th>Role</th>
                <th>Internship Type</th>
                <th>Start Date</th>
                <th>End Date</th>
                <th>Status</th>

            </tr>

        </thead>

        <tbody>

        <?php if(count($rows) === 0): ?>

        <tr>
            <td colspan="10" class="text-center text-danger">
                No students found for this division.
            </td>
        </tr>

        <?php else: ?>
        <?php foreach($rows as $row): ?>

        <tr>

            <td>
                <?php echo htmlspecialchars($row['roll_no'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
            </td>

            <td>
                <?php echo htmlspecialchars($row['enrollment_no'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
            </td>

            <td>
                <?php echo htmlspecialchars($row['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
            </td>

            <td>
                <?php echo htmlspecialchars($row['division'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
            </td>

            <td>
                <?php echo htmlspecialchars($row['company_name'] ?: '-', ENT_QUOTES, 'UTF-8'); ?>
            </td>

            <td>
                <?php echo htmlspecialchars($row['internship_role'] ?: '-', ENT_QUOTES, 'UTF-8'); ?>
            </td>

            <td>
                <?php echo htmlspecialchars($row['internship_type'] ?: '-', ENT_QUOTES, 'UTF-8'); ?>
            </td>

            <td>
                <?php echo htmlspecialchars($row['start_date'] ?: '-', ENT_QUOTES, 'UTF-8'); ?>
            </td>

            <td>
                <?php echo htmlspecialchars($row['end_date'] ?: '-', ENT_QUOTES, 'UTF-8'); ?>
            </td>

            <td>

                <?php

                if($row['status'] == 'Approved')
                {
                    echo "<span class='badge bg-success'>Approved</span>";
                }
                elseif($row['status'] == 'Pending')
                {
                    echo "<span class='badge bg-warning text-dark'>Pending</span>";
                }
                elseif($row['status'] == 'Rejected')
                {
                    echo "<span class='badge bg-danger'>Rejected</span>";
                }
                else
                {
                    echo "<span class='badge bg-secondary'>Not Applied</span>";
                }

                ?>

            </td>

        </tr>

        <?php endforeach; ?>
        <?php endif; ?>

        </tbody>

    </table>
    </div>

</div>
</div>

</main>
</div>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>

</body>
</html>
