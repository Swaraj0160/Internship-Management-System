<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    header("Location: ../login.php");
    exit();
}

/* NEW FILE — Reports Module PDF export. Read-only, same
   AnalyticsDataProvider query as the on-screen table / Excel / CSV
   exports. Uses PdfExporter::streamReportWithSummary() (additive
   method — see lib/PdfExporter.php) for a real, printable PDF: repeats
   the university header and table column headers on every page,
   shows Applied Filters + Summary once on page 1, and auto-selects A4
   vs A3 landscape so no column is cut off. */

require_once __DIR__ . '/../lib/AnalyticsDataProvider.php';
require_once __DIR__ . '/../lib/PdfExporter.php';

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

$columnWidths  = [
    "Level"               => "level",
    "Academic Year"       => "academic_year",
    "Semester"            => "semester",
    "Specialization"      => "specialization",
    "Class / Division"    => "class_label",
    "Batch"               => "batch",
    "Enrollment No"       => "enrollment_no",
    "Roll No"             => "roll_label",
    "Student Name"        => "full_name",
    "Domain"              => "domain",
    "Internship Type"     => "internship_type",
    "Company"             => "company_name",
    "Internship Role"     => "internship_role",
    "Status"              => "internship_status",
    "W1"                  => "week1",
    "W2"                  => "week2",
    "W3"                  => "week3",
    "W4"                  => "week4",
    "Week Total (40)"     => "weekly_total",
    "Mentor Evaluation (40)" => "mentor_evaluation",
    "Final Evaluation (20)"  => "final_evaluation",
    "Total (50)"          => "total_50",
];
$columnMinWidths= [
    "Level", "Academic Year", "Semester", "Specialization", "Class / Division", "Batch",
    "Enrollment No", "Roll No", "Student Name",
    "Domain", "Internship Type", "Company", "Internship Role", "Status",
    "W1", "W2", "W3", "W4",
    "Week Total (40)", "Mentor Evaluation (40)", "Final Evaluation (20)", "Total (50)"
];

$pdfRows= [
    "Level", "Academic Year", "Semester", "Specialization", "Class / Division", "Batch",
    "Enrollment No", "Roll No", "Student Name",
    "Domain", "Internship Type", "Company", "Internship Role", "Status",
    "W1", "W2", "W3", "W4",
    "Week Total (40)", "Mentor Evaluation (40)", "Final Evaluation (20)", "Total (50)"
];
foreach ($rows as $r) {
    $pdfRows[]= [
    "Level", "Academic Year", "Semester", "Specialization", "Class / Division", "Batch",
    "Enrollment No", "Roll No", "Student Name",
    "Domain", "Internship Type", "Company", "Internship Role", "Status",
    "W1", "W2", "W3", "W4",
    "Week Total (40)", "Mentor Evaluation (40)", "Final Evaluation (20)", "Total (50)"
];
}

$title = "Student Internship / Evaluation Report";
$subtitle = "MIT-ADT University Internship Portal   |   Generated: " . date('d M Y, h:i A');

PdfExporter::streamReportWithSummary(
    "MIT Art, Design and Technology University, Pune, India",
    $title,
    $subtitle,
    $filterLines,
    $summaryLines,
    $headers,
    $columnWidths,
    $pdfRows,
    "Student_Report_" . date('Y-m-d'),
    $columnMinWidths
);