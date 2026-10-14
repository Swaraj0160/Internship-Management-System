<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    header("Location: ../login.php");
    exit();
}

/* NEW FILE — Reports Module Excel export. Read-only: reuses
   lib/AnalyticsDataProvider.php (same SELECT-only query the on-screen
   report table is built from) so the exported workbook can never
   contain a different dataset than what's on screen for the same
   filters. */

require_once __DIR__ . '/../lib/AnalyticsDataProvider.php';
require_once __DIR__ . '/../lib/ExcelExporter.php';

$filters = AnalyticsDataProvider::parseFilters($_GET);
$rows = AnalyticsDataProvider::getRows($conn, $filters);
$summary = AnalyticsDataProvider::summarize($rows);
$appliedFilterLines = AnalyticsDataProvider::describeFilters($filters);

$filterLines = array_map(fn($p) => $p[0] . ': ' . $p[1], $appliedFilterLines);

$summaryLines= [
    "Level", "Academic Year", "Semester", "Specialization", "Class / Division", "Batch",
    "Enrollment No", "Roll No", "Student Name",
    "Domain", "Internship Type", "Company", "Internship Role", "Status",
    "W1", "W2", "W3", "W4",
    "Week Total (40)", "Mentor Evaluation (40)", "Final Evaluation (20)", "Total (50)"
];

$headers= [
    "Level", "Academic Year", "Semester", "Specialization", "Class / Division", "Batch",
    "Enrollment No", "Roll No", "Student Name",
    "Domain", "Internship Type", "Company", "Internship Role", "Status",
    "W1", "W2", "W3", "W4",
    "Week Total (40)", "Mentor Evaluation (40)", "Final Evaluation (20)", "Total (50)"
];

$columnMap= [
    "Level", "Academic Year", "Semester", "Specialization", "Class / Division", "Batch",
    "Enrollment No", "Roll No", "Student Name",
    "Domain", "Internship Type", "Company", "Internship Role", "Status",
    "W1", "W2", "W3", "W4",
    "Week Total (40)", "Mentor Evaluation (40)", "Final Evaluation (20)", "Total (50)"
];

// Round the computed Total (50) to 1 decimal for a clean numeric export cell.
foreach ($rows as &$r) {
    $r['total_50'] = round($r['total_50'], 1);
}
unset($r);

try {
    ExcelExporter::streamReportWithSummary(
        "MIT Art, Design and Technology University \xe2\x80\x94 Student Internship / Evaluation Report",
        $filterLines,
        $summaryLines,
        $headers,
        $rows,
        $columnMap,
        "Student_Report_" . date('Y-m-d'),
        "Report"
    );
} catch (ExcelExporterException $e) {
    ExcelExporter::renderSetupError($e->getMessage());
    exit();
}