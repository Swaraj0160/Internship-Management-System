<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    header("Location: ../login.php");
    exit();
}

require_once __DIR__ . '/../lib/TYEvaluationDataProvider.php';

$academic_year = trim($_GET['academic_year'] ?? '');
$semester = trim($_GET['semester'] ?? '');
$specialization = trim($_GET['specialization'] ?? '');
$class_division = trim($_GET['class_division'] ?? '');
$batch = trim($_GET['batch'] ?? '');
$search = trim($_GET['search'] ?? '');

$evalData = TYEvaluationDataProvider::getRows($conn, $academic_year, $semester, $specialization, $class_division, $batch, $search);
$rows = $evalData['rows'];

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="TY_Evaluation_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');

$headers = [
    "Academic Year", "Semester", "Specialization", "TY Class", "Batch",
    "Enrollment No", "TY Roll No", "Student Name",
    "Week-1", "Week-2", "Week-3", "Week-4",
    "Week Total (40)", "Mentor Evaluation (40)", "Final Evaluation (20)",
    "Total (50)"
];

fputcsv($output, $headers);

foreach($rows as $row) {
    fputcsv($output, [
        $row['academic_year'],
        $row['semester'],
        $row['specialization'],
        $row['class_division'],
        $row['batch'],
        $row['enrollment_no'],
        $row['ty_roll_no'],
        $row['full_name'],
        $row['week1'],
        $row['week2'],
        $row['week3'],
        $row['week4'],
        $row['weekly_total'],
        $row['mentor'],
        $row['final'],
        number_format($row['total'], 1)
    ]);
}

fclose($output);
exit();

?>