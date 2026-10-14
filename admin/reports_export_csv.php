<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    header("Location: ../login.php");
    exit();
}

/* NEW FILE — Reports Module CSV export. Read-only, same
   AnalyticsDataProvider query as the on-screen table and the Excel/PDF
   exports, so all three (and the screen) always match for the same
   filters. */

require_once __DIR__ . '/../lib/AnalyticsDataProvider.php';

$filters = AnalyticsDataProvider::parseFilters($_GET);
$rows = AnalyticsDataProvider::getRows($conn, $filters);
$summary = AnalyticsDataProvider::summarize($rows);
$appliedFilterLines = AnalyticsDataProvider::describeFilters($filters);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="Student_Report_' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');

fputcsv($out, ["MIT Art, Design and Technology University"]);
fputcsv($out, ["PUNE, INDIA"]);
fputcsv($out, ["Student Internship / Evaluation Report"]);
fputcsv($out, ["Generated: " . date('d M Y, h:i A')]);
fputcsv($out, []);

fputcsv($out, ["APPLIED FILTERS"]);
foreach ($appliedFilterLines as [$label, $value]) {
    fputcsv($out, [$label, $value]);
}
fputcsv($out, []);

fputcsv($out, ["REPORT SUMMARY"]);
fputcsv($out, ["Total Students", $summary['total_students']]);
fputcsv($out, ["Students With Evaluation Data", $summary['with_evaluation']]);
fputcsv($out, ["Students Without Evaluation Data", $summary['without_evaluation']]);
fputcsv($out, ["Average Weekly Total", $summary['avg_weekly'] !== null ? $summary['avg_weekly'] . ' / 40' : '-']);
fputcsv($out, ["Average Mentor Evaluation", $summary['avg_mentor'] !== null ? $summary['avg_mentor'] . ' / 40' : '-']);
fputcsv($out, ["Average Final Evaluation", $summary['avg_final'] !== null ? $summary['avg_final'] . ' / 20' : '-']);
fputcsv($out, ["Average Final Total", $summary['avg_total'] !== null ? $summary['avg_total'] . ' / 50' : '-']);
fputcsv($out, ["Highest Total", $summary['highest_total'] !== null ? $summary['highest_total'] . ' / 50' : '-']);
fputcsv($out, ["Lowest Total", $summary['lowest_total'] !== null ? $summary['lowest_total'] . ' / 50' : '-']);
fputcsv($out, []);

// Section 6 required column order.
fputcsv($out, [
    "Enrollment No", "Roll No", "Student Name", "Year", "Academic Year", "Semester",
    "Class", "Specialization", "Batch",
    "W1", "W2", "W3", "W4", "Week Total", "Mentor Evaluation", "Final Evaluation", "Total"
]);

foreach ($rows as $row) {
    fputcsv($out, [
        $row['enrollment_no'],
        $row['roll_label'],
        $row['full_name'],
        $row['level'],
        $row['academic_year'],
        $row['semester'],
        $row['class_label'],
        $row['specialization'],
        $row['batch'],
        $row['week1'] ?? '',
        $row['week2'] ?? '',
        $row['week3'] ?? '',
        $row['week4'] ?? '',
        $row['weekly_total'] ?? '',
        $row['mentor_evaluation'] ?? '',
        $row['final_evaluation'] ?? '',
        number_format($row['total_50'], 1),
    ]);
}

fclose($out);
exit();