<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty')
{
    header("Location: ../login.php");
    exit();
}

/* -----------------------------------------------------------------
   Two fixes in this file, same root causes as admin/export_student_
   evaluations.php:

   1. "Oct-50"/"May-50"/"Dec-50": the old file wrote the Total column
      as a literal string "X / 50", which Excel's HTML-to-XLS importer
      auto-detects as a date (N/N -> month/year). This file now
      produces a REAL .xlsx via lib/ExcelExporter.php, with Total as a
      genuine numeric cell — the "/50" context moves to the column
      header only, matching your requirement.

   2. DUAL-DIVISION FACULTY: the old file read only the legacy single
      value users.faculty_division, so a faculty member assigned to
      BOTH IT-DA and IT-SMAD (via the faculty_divisions mapping table)
      would only ever get ONE of their two divisions in this export —
      the other's students would be silently missing. This now uses
      the same multi-division lookup already correct in
      faculty/dashboard.php: read every division from faculty_divisions
      for this faculty_id, falling back to the legacy column only if
      that mapping table has no rows for them.

   ORDERING: IT-DA sorts before IT-SMAD, which sorts before any other
   division this faculty is assigned to; numeric roll number within
   each division. The academic marking scheme (stored total_marks) is
   unchanged — this file only changes how it's displayed/exported.

   FIX (post-implementation audit, Section J / data consistency): this
   file previously ran its OWN separate SQL query and its OWN local
   copy of the mark-formatting function (formatMarksValueFaculty),
   and read the Total column straight from the stored total_marks
   column instead of recomputing it the same way the Admin export and
   the PDF export do. That meant this file could silently drift out
   of sync with the other two exports (e.g. if total_marks was ever
   stale). It now uses the exact same lib/EvaluationDataProvider.php
   as the Admin Excel export and both PDF exports, so all four exports
   are guaranteed to use identical data, ordering, and the same
   malformed-value detection (Section J).
   ----------------------------------------------------------------- */

require_once __DIR__ . '/../lib/ExcelExporter.php';
require_once __DIR__ . '/../lib/EvaluationDataProvider.php';

$faculty_id = (int)$_SESSION['user_id'];

$faculty_query = $conn->prepare("SELECT full_name FROM users WHERE id=?");
$faculty_query->bind_param("i", $faculty_id);
$faculty_query->execute();
$faculty_row = $faculty_query->get_result()->fetch_assoc();
$faculty_name = $faculty_row['full_name'] ?? '';

$faculty_divisions = [];

$fd_res = mysqli_query(
    $conn,
    "SELECT DISTINCT division FROM faculty_divisions
     WHERE faculty_id='$faculty_id'
       AND division IS NOT NULL
       AND TRIM(division) <> ''"
);

if($fd_res)
{
    while($row = mysqli_fetch_assoc($fd_res))
    {
        $v = trim((string)$row['division']);
        if($v !== '')
        {
            $faculty_divisions[] = $v;
        }
    }
}

if(empty($faculty_divisions))
{
    $fac_res = mysqli_query($conn, "SELECT faculty_division FROM users WHERE id='$faculty_id'");
    $fac_row = mysqli_fetch_assoc($fac_res);
    $legacy = trim((string)($fac_row['faculty_division'] ?? ''));
    if($legacy !== '')
    {
        $faculty_divisions[] = $legacy;
    }
}

if(empty($faculty_divisions))
{
    die("No division is assigned to your faculty account. Contact admin.");
}

$evalData = EvaluationDataProvider::getRows($conn, $faculty_divisions);
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

$filterSummary = "Division: " . implode(", ", $faculty_divisions) .
    "   |   Internship Class Coordinator: " . $faculty_name .
    "   |   Total Students: " . count($rows);

try {
    ExcelExporter::streamStudentReport(
        "MIT Art, Design and Technology University — Student Internship Evaluation Sheet",
        $filterSummary,
        $headers,
        $rows,
        $columnMap,
        "Student_Evaluation_" . implode("_", $faculty_divisions),
        "Evaluations",
        $warnings
    );
} catch (ExcelExporterException $e) {
    ExcelExporter::renderSetupError($e->getMessage());
    exit();
}
