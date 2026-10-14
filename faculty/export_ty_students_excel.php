<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty')
{
    header("Location: ../login.php");
    exit();
}

require_once __DIR__ . '/../lib/ExcelExporter.php';
require_once __DIR__ . '/../lib/TYEvaluationDataProvider.php';

$class_division = trim($_GET['class_division'] ?? '');
$specialization = trim($_GET['specialization'] ?? '');

$evalData = TYEvaluationDataProvider::getRows($conn, '', '', $specialization, $class_division, '', '');
$rows = $evalData['rows'];

$headers = ["Enrollment No", "TY Roll No", "Student Name", "Class", "Batch", "Week-1", "Week-2", "Week-3", "Week-4", "Week Total (40)", "Mentor (40)", "Final (20)", "Total (50)"];

$columnMap = ["Enrollment No" => "enrollment_no", "TY Roll No" => "ty_roll_no", "Student Name" => "full_name", "Class" => "class_division", "Batch" => "batch", "Week-1" => "week1", "Week-2" => "week2", "Week-3" => "week3", "Week-4" => "week4", "Week Total (40)" => "weekly_total", "Mentor (40)" => "mentor", "Final (20)" => "final", "Total (50)" => "total"];

$filterSummary = "Third Year Students | Total: " . count($rows);

try {
    ExcelExporter::streamStudentReport("MIT Art, Design and Technology University — TY Evaluation Sheet", $filterSummary, $headers, $rows, $columnMap, "TY_Evaluation_Faculty_" . date('Y-m-d'), "TY Evaluations", []);
} catch (ExcelExporterException $e) {
    ExcelExporter::renderSetupError($e->getMessage());
    exit();
}

?>