<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    header("Location: ../login.php");
    exit();
}

require_once __DIR__ . '/../lib/PdfExporter.php';
require_once __DIR__ . '/../lib/EvaluationDataProvider.php';

$division = trim($_GET['division'] ?? '');
$divisions = ($division !== '' && $division !== 'ALL') ? [$division] : [];

$evalData = EvaluationDataProvider::getRows($conn, $divisions);
$rows = $evalData['rows'];

$headers = [
    "Division", "Enrollment No", "Roll", "Student Name",
    "W1", "W2", "W3", "W4",
    "Wk Total", "Mentor (40)", "Final (20)", "Total Marks"
];

$columnWidths = [8, 14, 4.5, 22, 4, 4, 4, 4, 8.5, 8.5, 8.5, 9.5];

$pdfRows = [];
foreach ($rows as $r) {
    $pdfRows[] = [
        $r['division'], // Group by Division (index 0)
        $r['enrollment_no'],
        $r['roll_no'],
        $r['full_name'],
        (string)$r['week1'],
        (string)$r['week2'],
        (string)$r['week3'],
        (string)$r['week4'],
        (string)$r['weekly_total'],
        (string)$r['mentor'],
        (string)$r['final'],
        (string)$r['total'],
    ];
}

PdfExporter::streamAdminTYTable(
    "MIT ADT UNIVERSITY, PUNE",
    "SUMMER INTERNSHIP",
    $headers,
    $columnWidths,
    $pdfRows,
    "Student_Evaluation_" . ($division !== '' && $division !== 'ALL' ? $division : "All_Divisions"),
    0,  // Group by Division (index 0)
    32  // 32 students per page max
);

?>
