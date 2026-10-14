<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    header("Location: ../login.php");
    exit();
}

/* -----------------------------------------------------------------
   ROOT CAUSE OF "Oct-50" / "May-50" / "Dec-50" (fixed in an earlier
   phase, restated here): the previous version built an HTML table
   served with an .xls extension, and wrote the Total column as a
   literal string like "10 / 50" — Excel's HTML-to-XLS importer reads
   text shaped like N/N as a date. This file now produces a REAL
   .xlsx (via lib/ExcelExporter.php + PhpSpreadsheet) with Total as a
   genuine numeric cell, so the bug is structurally impossible here.

   Phase 2: the query/formatting logic was extracted into
   lib/EvaluationDataProvider.php so the Excel export (this file) and
   the new PDF export (export_student_evaluations_pdf.php) can never
   drift out of sync with each other — both now call the exact same
   function for their row data.
   ----------------------------------------------------------------- */

require_once __DIR__ . '/../lib/ExcelExporter.php';
require_once __DIR__ . '/../lib/EvaluationDataProvider.php';

$division = trim($_GET['division'] ?? '');

$divisions = ($division !== '' && $division !== 'ALL') ? [$division] : [];

$evalData = EvaluationDataProvider::getRows($conn, $divisions);
$rows = $evalData['rows'];
$warnings = $evalData['warnings']; // Section J: malformed marks values, reported not hidden

$headers = [
    "Division", "Enrollment No", "Roll No", "Student Name",
    "Week-1", "Week-2", "Week-3", "Week-4",
    "Week Total (Max 40)", "Mentor Evaluation (Max 40)", "Final Evaluation (Max 20)",
    "Total (Max 50)"
];

$columnMap = [
    "Division"                      => "division",
    "Enrollment No"                 => "enrollment_no",
    "Roll No"                       => "roll_no",
    "Student Name"                  => "full_name",
    "Week-1"                        => "week1",
    "Week-2"                        => "week2",
    "Week-3"                        => "week3",
    "Week-4"                        => "week4",
    "Week Total (Max 40)"           => "weekly_total",
    "Mentor Evaluation (Max 40)"    => "mentor",
    "Final Evaluation (Max 20)"     => "final",
    "Total (Max 50)"                => "total",
];

$filterSummary = ($division !== '' && $division !== 'ALL')
    ? "Division: " . $division
    : "All Divisions (IT-DA, IT-SMAD, then remaining divisions)";

$filterSummary .= "   |   Total Students: " . count($rows);

try {
    ExcelExporter::streamStudentReport(
        "MIT Art, Design and Technology University — Student Internship Evaluation Sheet",
        $filterSummary,
        $headers,
        $rows,
        $columnMap,
        "Student_Evaluation_" . ($division !== '' && $division !== 'ALL' ? $division : "All_Divisions"),
        "Evaluations",
        $warnings
    );
} catch (ExcelExporterException $e) {
    ExcelExporter::renderSetupError($e->getMessage());
    exit();
}