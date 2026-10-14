<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    header("Location: ../login.php");
    exit();
}

require_once __DIR__ . '/../lib/PdfExporter.php';
require_once __DIR__ . '/../lib/TYEvaluationDataProvider.php';

$academic_year = trim($_GET['academic_year'] ?? '');
$semester = trim($_GET['semester'] ?? '');
$specialization = trim($_GET['specialization'] ?? '');
$class_division = trim($_GET['class_division'] ?? '');
$batch = trim($_GET['batch'] ?? '');
$search = trim($_GET['search'] ?? '');

$evalData = TYEvaluationDataProvider::getRows($conn, $academic_year, $semester, $specialization, $class_division, $batch, $search);
$rows = $evalData['rows'];

$headers = [
    "Enrollment No", "Roll", "Student Name", "Class", "Specialization", "Batch",
    "W1", "W2", "W3", "W4", "Wk Total", "Mentor", "Final", "Total Marks"
];

$columnWidths = [13.5, 3.8, 16.5, 7.5, 11.0, 4.0, 3.2, 3.2, 3.2, 3.2, 6.8, 6.5, 6.5, 7.2];

$pdfRows = [];
foreach ($rows as $r) {
    $pdfRows[] = [
        $r['enrollment_no'],
        $r['ty_roll_no'],
        $r['full_name'],
        $r['class_division'], // Group by Class (index 3)
        $r['specialization'],
        $r['batch'],
        (string)$r['week1'],
        (string)$r['week2'],
        (string)$r['week3'],
        (string)$r['week4'],
        (string)$r['weekly_total'],
        (string)$r['mentor'],
        (string)$r['final'],
        number_format((float)$r['total'], 1),
    ];
}

PdfExporter::streamAdminTYTable(
    "MIT ADT UNIVERSITY, PUNE",
    "SUMMER INTERNSHIP",
    $headers,
    $columnWidths,
    $pdfRows,
    "TY_Evaluation_" . date('Y-m-d'),
    3,  // Group by Class (index 3)
    32  // 32 students per page max
);

?>
