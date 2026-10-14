<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty')
{
    header("Location: ../login.php");
    exit();
}

/* -----------------------------------------------------------------
   NEW FILE — real PDF evaluation export for Faculty, scoped to this
   faculty's assigned divisions only (faculty_divisions, with legacy
   users.faculty_division fallback) — same authorization pattern as
   faculty/export_students.php. A faculty member assigned to BOTH
   IT-DA and IT-SMAD gets both in this PDF.

   See admin/export_student_evaluations_pdf.php and
   lib/PdfExporter.php for why this is a native dependency-free PDF
   generator rather than dompdf (dompdf was not installable in this
   sandbox), and for the pypdf-based verification that it produces a
   real, valid PDF.
   ----------------------------------------------------------------- */

require_once __DIR__ . '/../lib/PdfExporter.php';
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
/* Section J data-quality note: any malformed marks values found are in
   $evalData['warnings'] and are surfaced on faculty/student_evaluations.php
   (the page this PDF is downloaded from), not inside the PDF itself. */

/* -----------------------------------------------------------------
   REDESIGNED (post-implementation audit — PDF readability): see
   admin/export_student_evaluations_pdf.php for the full explanation.
   Same shortened headers / rebalanced widths applied here.
   ----------------------------------------------------------------- */
$headers = [
    "Division", "Enrollment No", "Roll", "Student Name",
    "W1", "W2", "W3", "W4",
    "Wk Total (40)", "Mentor (40)", "Final (20)", "Total (50)"
];

$columnWidths    = [6,  9,  4,  16,  4,  4,  4,  4,  9,  9,  9,  7];
$columnMinWidths = [55, 90, 40, 140, 32, 32, 32, 32, 60, 60, 55, 55];

$pdfRows = [];
foreach ($rows as $r) {
    $pdfRows[] = [
        $r['division'],
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

$subtitle = "Division: " . implode(", ", $faculty_divisions) .
    "   |   Coordinator: " . $faculty_name .
    "   |   Total Students: " . count($rows) .
    "   |   Generated: " . date('d M Y, h:i A');

PdfExporter::streamEvaluationTable(
    "MIT Art, Design and Technology University",
    "Student Internship Evaluation Sheet",
    $subtitle,
    $headers,
    $columnWidths,
    $pdfRows,
    "Student_Evaluation_" . implode("_", $faculty_divisions),
    $columnMinWidths
);
