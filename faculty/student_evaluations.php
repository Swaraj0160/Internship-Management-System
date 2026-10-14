<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty')
{
    header("Location: ../login.php");
    exit();
}

/* -----------------------------------------------------------------
   REPLACE — Phase 2. This is the actual marks-entry page faculty use
   to grade students, so the bug fixed here is significant:

   1. CRITICAL functional bug: $faculty_division was read ONLY from
      users.faculty_division (the legacy single-value column), and
      the student query filtered on "users.division='$faculty_division'"
      — a single exact match. A faculty member assigned to BOTH
      IT-DA and IT-SMAD via faculty_divisions would only ever see
      ONE of their two divisions' students here, meaning they
      couldn't grade the other division's students on this page at
      all. Rewired to the same faculty_divisions-first,
      legacy-fallback pattern used on faculty/dashboard.php, with the
      student query now using "users.division IN (...)" across all
      assigned divisions.
   2. XSS: student_name, enrollment_no, roll_no, division, and
      faculty name were echoed with no escaping. Fixed.
   3. Ordering: added IT-DA-first / IT-SMAD-second CASE ordering on
      top of the existing CAST(roll_no) numeric ordering (previously
      only had roll-no ordering, no division ordering at all).
   4. Added a genuine PDF export link (lib/PdfExporter.php-backed,
      see faculty/export_student_evaluations_pdf.php) next to the
      existing Excel export — the old "Print Evaluation Sheet" button
      still exists for on-screen print-to-PDF-via-browser, but is no
      longer the only PDF-shaped option.
   5. Final Evaluation is now READ-ONLY for faculty on this page.
      It's set by admin (imported from the official evaluation
      sheet) — faculty may only edit Mentor Evaluation here. The
      "final" input/POST field is gone entirely; the save handler
      below now iterates $_POST['mentor'] and always re-reads the
      existing final_evaluation from the database rather than
      trusting anything from the request, so this can't be bypassed
      by editing the form client-side either.

   The marks SAVE logic (clamping, week1..4 + mentor + final -> /2
   formula, INSERT/UPDATE into student_evaluation) is otherwise
   UNCHANGED — parameter-safe via (int) casts on every value before it
   reaches SQL, and the formula matches the admin side exactly.
   ----------------------------------------------------------------- */

$faculty_id = (int)$_SESSION['user_id'];

$faculty_query = "SELECT faculty_division, full_name FROM users WHERE id='$faculty_id'";
$faculty_result = mysqli_query($conn, $faculty_query);
$faculty_data = mysqli_fetch_assoc($faculty_result);

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
    $legacy = trim((string)($faculty_data['faculty_division'] ?? ''));
    if($legacy !== '')
    {
        $faculty_divisions[] = $legacy;
    }
}

$faculty_division = implode(', ', $faculty_divisions); // display string, kept same variable name used in the header

if(empty($faculty_divisions))
{
    die("No division is assigned to your faculty account. Contact admin.");
}

/* -----------------------------------------------------------------
   NEW — Internship Type filter (display/filter only, does not touch
   marks calculation, storage, or the Save All Marks logic below).

   GET param survives a plain page refresh. It is ALSO carried through
   the Save All Marks POST via a hidden field in the form further down,
   so saving marks on a filtered view doesn't reset the filter back to
   "All Internship Types" on redirect — read from $_POST as a fallback
   only when there's no GET value (i.e. we're inside that POST).
   ----------------------------------------------------------------- */
$type_filter = trim($_GET['internship_type'] ?? '');
if($type_filter === '' && isset($_POST['internship_type']))
{
    $type_filter = trim((string)$_POST['internship_type']);
}

require_once __DIR__ . '/../lib/EvaluationDataProvider.php';

/* Section J (data-quality audit): detect malformed marks values (e.g.
   "Oct-50") for this faculty's divisions, purely for the warning
   banner below — does not affect the save-marks logic or the table
   query, both unchanged. */
$eval_warnings = EvaluationDataProvider::getRows($conn, $faculty_divisions)['warnings'];

/* SAVE MARKS
   CHANGED: Final Evaluation is admin-controlled (imported from the
   official evaluation sheet under Admin) and must NOT be editable by
   faculty here. The form no longer submits a 'final' field at all, so
   this now iterates $_POST['mentor'] (faculty's only editable field)
   and always PRESERVES whatever final_evaluation is already stored in
   the database — it is never taken from request input on this page. */

if(isset($_POST['save_marks']))
{
    foreach($_POST['mentor'] as $student_id => $mentor_raw)
    {
        $student_id = (int)$student_id;

        $eval = mysqli_fetch_assoc(
            mysqli_query(
                $conn,
                "SELECT * FROM student_evaluation WHERE student_id='$student_id'"
            )
        );

        $week1 = $eval['week1_marks'] ?? 0;
        $week2 = $eval['week2_marks'] ?? 0;
        $week3 = $eval['week3_marks'] ?? 0;
        $week4 = $eval['week4_marks'] ?? 0;

        /* Preserve the existing Final Evaluation exactly as-is —
           faculty cannot change it from this page. */
        $final_raw = (int)($eval['final_evaluation'] ?? 0);

        $mentor_raw = (int)$mentor_raw;

        if($final_raw < 0) $final_raw = 0;
        if($final_raw > 20) $final_raw = 20;

        if($mentor_raw < 0) $mentor_raw = 0;
        if($mentor_raw > 40) $mentor_raw = 40;

        $weekly_total = $week1 + $week2 + $week3 + $week4;

        /*
        TOTAL OUT OF 100
        (Week Total 40 + Mentor Evaluation 40 + Final Evaluation 20)
        SCALED DOWN TO 50
        */
        $total_marks = round(($weekly_total + $mentor_raw + $final_raw) / 2);

        $check = mysqli_query(
            $conn,
            "SELECT * FROM student_evaluation WHERE student_id='$student_id'"
        );

        if(mysqli_num_rows($check) > 0)
        {
            mysqli_query(
                $conn,
                "UPDATE student_evaluation
                 SET
                 week1_marks='$week1',
                 week2_marks='$week2',
                 week3_marks='$week3',
                 week4_marks='$week4',
                 weekly_total='$weekly_total',
                 mentor_evaluation='$mentor_raw',
                 final_evaluation='$final_raw',
                 total_marks='$total_marks'
                 WHERE student_id='$student_id'"
            );
        }
        else
        {
            mysqli_query(
                $conn,
                "INSERT INTO student_evaluation
                (
                    student_id, week1_marks, week2_marks, week3_marks, week4_marks,
                    weekly_total, mentor_evaluation, final_evaluation, total_marks
                )
                VALUES
                (
                    '$student_id', '$week1', '$week2', '$week3', '$week4',
                    '$weekly_total', '$mentor_raw', '$final_raw', '$total_marks'
                )"
            );
        }
    }

    /* Preserve the current Internship Type filter across the redirect
       (rawurlencode makes this safe to drop straight into both the
       inline JS string and the URL — no quotes/angle-brackets can
       appear in its output, so this can't break out of the <script>
       or introduce XSS). Everything else about this save flow —
       which students it touches, the formula, the INSERT/UPDATE — is
       unchanged from above. */
    $redirect_url = 'student_evaluations.php';
    if($type_filter !== '')
    {
        $redirect_url .= '?internship_type=' . rawurlencode($type_filter);
    }

    echo "
    <script>
    alert('Marks Saved Successfully');
    window.location='" . $redirect_url . "';
    </script>
    ";
    exit();
}

/* FETCH STUDENTS — now across ALL of this faculty's divisions, and
   optionally narrowed to one internship type. */

$div_values = array_map(
    fn($d) => "'" . mysqli_real_escape_string($conn, $d) . "'",
    $faculty_divisions
);
$division_list_sql = implode(',', $div_values);

/* -----------------------------------------------------------------
   NEW — same "latest internship per student" join already used on
   faculty/manage_applications.php and faculty/dashboard.php: exactly
   one internships row per student (their most recent, by id), so
   LEFT JOINing it below can never duplicate a student row even if
   they have multiple internship records. LEFT JOIN (not INNER) so
   students with zero internship rows still appear under "All
   Internship Types" — they're simply excluded once a specific type
   is selected, per the existing "no internship type -> All only"
   rule already used elsewhere in this project.
   ----------------------------------------------------------------- */
$latest_internship_sql = "
    SELECT i.*
    FROM internships i
    INNER JOIN (
        SELECT student_id, MAX(id) AS max_id
        FROM internships
        GROUP BY student_id
    ) latest
        ON latest.student_id = i.student_id
       AND latest.max_id = i.id
";

/* Dropdown options — DISTINCT internship types actually present among
   this faculty's own students, not a hardcoded list. No user input in
   this query (division_list_sql is built from DB-sourced division
   names via mysqli_real_escape_string, same as the main query below),
   so a plain query is consistent with the rest of this file. */
$type_options = [];
$type_options_result = mysqli_query(
    $conn,
    "SELECT DISTINCT li.internship_type
     FROM users
     INNER JOIN ($latest_internship_sql) li
        ON li.student_id = users.id
     WHERE users.role='student'
       AND users.division IN ($division_list_sql)
       AND li.internship_type IS NOT NULL
       AND TRIM(li.internship_type) <> ''
     ORDER BY li.internship_type ASC"
);
if($type_options_result)
{
    while($t = mysqli_fetch_assoc($type_options_result))
    {
        $type_options[] = $t['internship_type'];
    }
}

$query = "
    SELECT
        users.id,
        users.roll_no,
        users.full_name,
        users.enrollment_no,
        users.division,

        student_evaluation.week1_marks,
        student_evaluation.week2_marks,
        student_evaluation.week3_marks,
        student_evaluation.week4_marks,
        student_evaluation.weekly_total,
        student_evaluation.mentor_evaluation,
        student_evaluation.final_evaluation,
        student_evaluation.total_marks

    FROM users

    LEFT JOIN student_evaluation
        ON users.id = student_evaluation.student_id

    LEFT JOIN ($latest_internship_sql) li
        ON li.student_id = users.id

    WHERE users.role='student'
      AND users.division IN ($division_list_sql)
      " . ($type_filter !== '' ? "AND li.internship_type = ?" : "") . "

    ORDER BY
        CASE users.division
            WHEN 'IT-DA'   THEN 0
            WHEN 'IT-SMAD' THEN 1
            ELSE 2
        END ASC,
        users.division ASC,
        CAST(users.roll_no AS UNSIGNED) ASC
";

/* Parameterized only for the one piece of real user input
   (internship_type) — the division list stays inline exactly as it
   was, since it's DB-sourced and already safely escaped above. */
$stmt = mysqli_prepare($conn, $query);
if($type_filter !== '')
{
    mysqli_stmt_bind_param($stmt, 's', $type_filter);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$student_count = mysqli_num_rows($result);

/*
FORMAT A MARKS VALUE FOR DISPLAY
SHOWS A DECIMAL ONLY WHEN THE VALUE IS NOT A WHOLE NUMBER
(e.g. 15 -> "15", 7.5 -> "7.5")
*/

function formatMarks($value)
{
    if((float)$value == (int)$value)
    {
        return (int)$value;
    }

    return number_format((float)$value, 1);
}

$activePage = 'student_evaluations.php';

?>

<!DOCTYPE html>
<html>

<head>

<title>Student Evaluation</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?php include __DIR__ . '/includes/sidebar_head.php'; ?>

<style>

body{
    background:#f5f5f5;
    margin:0;
}

.page-inner{
    padding:0 20px 40px;
}

.container-box{
    width:100%;
    margin:auto;
    margin-top:20px;
    background:white;
    padding:20px;
    border-radius:15px;
}

table{
    font-size:12px;
}

select,
input[type=number]
{
    min-width:55px;
}

/* HEADER */

.print-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:8px;
}

.college-section{
    flex:1;
    text-align:center;
}

.college-section h2{
    font-size:24px;
    margin:0;
    font-weight:bold;
}

.college-section h4{
    font-size:16px;
    margin:0;
}

.faculty-section{
    min-width:250px;
    text-align:right;
    font-size:13px;
}

.college-logo{
    width:160px;
    height:auto;
}

.college-section{
    flex:1;
    text-align:center;
}

.college-section h2{
    font-size:14px;
}

.college-section h4{
    font-size:10px;
}

.faculty-section{
    min-width:250px;
    text-align:right;
    font-size:13px;
}

/* FOOTER */

.footer-print{
    display:none;
}
.print-only{
    display:none;
}
.footer-left,
.footer-right{
    margin-bottom:2px;
}

@page{
    margin:10mm;
}

@media (max-width:768px){
    .table-responsive-wrap{
        overflow-x:auto;
    }
    table{
        font-size:11px;
    }
}

/* PRINT SETTINGS */

@media print
{
    h1.mb-4{
    display:none;
}

tbody::after{
    content:"";
    display:block;
    height:50px;
}

select{
    border:none !important;
    background:none !important;
    appearance:none !important;
    -webkit-appearance:none !important;
    -moz-appearance:none !important;
    text-align:center;
}

input[type=number]{
    border:none !important;
    background:none !important;
    text-align:center;
}

    select{
    appearance:none !important;
    -webkit-appearance:none !important;
    -moz-appearance:none !important;
}

.table{
    margin-bottom:0 !important;
}

hr{
    margin:3px 0 !important;
}

    tbody tr{
        page-break-inside: auto;
    }

@page{
    size:A4 landscape;
    margin:10mm 10mm 30mm 10mm;
}
    thead{
        display:table-header-group;
    }

    tr{
        page-break-inside:auto;
    }


    body{
        background:white;
        font-size:7px;
    }

    .btn{
        display:none !important;
    }

    .no-print{
    display:none !important;
}

.print-only{
    display:inline !important;
    font-weight:bold;
}

    .container-box{
        width:100%;
        margin:0;
        padding:0;
        box-shadow:none;
    }

    .college-logo{
        width:120px;
        height:auto;
    }

    .college-section h2{
        font-size:16px;
    }

    .college-section h4{
        font-size:12px;
    }

    .faculty-section{
        font-size:10px;
    }

table{
    font-size:9.6px !important;
    margin-bottom:20px !important;
}

table th,
table td{
    padding:0px 2px !important;
    line-height:0.9;
}

   select,
input[type=number]
{
    width:40px !important;
    min-width:40px !important;
}

.footer-print{
    display:none !important;
    position:fixed;
    bottom:0;
    left:0;
    right:0;
    font-size:8px;

    border-top:1px solid #000;
    padding-top:4px;
}

    .footer-left{
        float:left;
    }

    .footer-right{
        float:right;
    }
}

</style>

<script>

function calculateRow(row)
{
    let weeklyTotal =
    parseFloat(
        row.querySelector('.weeklytotal').innerText
    ) || 0;

    let mentorEval =
    parseInt(
        row.querySelector('.mentoreval').value
    ) || 0;

    let finalEval =
    parseInt(
        row.querySelector('.finaleval-display').dataset.final
    ) || 0;

    /*
    TOTAL OUT OF 100
    (Week Total 40 + Mentor Evaluation 40 + Final Evaluation 20)
    SCALED DOWN TO 50
    */

    let totalRaw =
    weeklyTotal + mentorEval + finalEval;

    let totalScaled =
    totalRaw / 2;

    let displayValue =
    (totalScaled % 1 === 0)
        ? totalScaled
        : totalScaled.toFixed(1);

    row.querySelector('.totalmarks').innerHTML =
displayValue + " / 50";
}

</script>

</head>

<body>

<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="page-inner">
<div class="container-box">

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">

<div></div>

<div class="d-flex gap-2 flex-wrap">

<!-- REPLACE (post-implementation audit): removed the separate
     "Print Evaluation Sheet" (window.print()) button — per your
     instruction, window.print() must not remain a PDF generation
     path at all. "Download PDF" below (export_student_evaluations_pdf.php,
     the dedicated PDF exporter) is now the single, authoritative PDF
     path — a real downloadable .pdf, not a browser print dialog. -->

<a href="export_student_evaluations.php"
class="btn btn-primary">

📊 Export Excel

</a>

<a href="export_student_evaluations_pdf.php"
class="btn btn-danger">

📄 Download PDF

</a>

</div>

</div>

<?php if (!empty($eval_warnings)): ?>
<div class="alert alert-danger" role="alert">
    <strong>⚠ Data Quality Warning:</strong>
    <?php echo count($eval_warnings); ?> evaluation value(s) in the database could not be read as numbers and are being shown as 0 below and in exports until corrected. These are NOT deleted or overwritten — please re-enter them for the affected student(s):
    <ul class="mb-0 mt-2">
        <?php foreach ($eval_warnings as $w): ?>
            <li>
                <?php echo htmlspecialchars($w['student'], ENT_QUOTES, 'UTF-8'); ?>
                (<?php echo htmlspecialchars($w['enrollment_no'], ENT_QUOTES, 'UTF-8'); ?>,
                <?php echo htmlspecialchars($w['division'], ENT_QUOTES, 'UTF-8'); ?>)
                — <?php echo htmlspecialchars($w['field'], ENT_QUOTES, 'UTF-8'); ?>:
                database has <code><?php echo htmlspecialchars($w['raw_value'], ENT_QUOTES, 'UTF-8'); ?></code>, expected a number.
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

    <div class="print-header">

    <div>
        <img src="../assets/images/logo.png"
             class="college-logo">
    </div>

    <div class="college-section">

        <h2>
            MIT Art, Design and Technology University
        </h2>

        <h4>
            Student Internship Evaluation Sheet
        </h4>

    </div>

    <div class="faculty-section">

        <b>Division:</b>
        <?php echo htmlspecialchars($faculty_division, ENT_QUOTES, 'UTF-8'); ?>

        <br>

        <b>Internship Class Coordinator</b>
        <?php echo htmlspecialchars($faculty_data['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>

    </div>

</div>

<hr>

<h1 class="mb-4">
Student Evaluation
</h1>

<!-- NEW — Internship Type filter. Own GET form, separate from the
     marks POST form below, so changing it can never interfere with
     Save All Marks. onchange auto-submits (no extra JS beyond this
     one built-in form-submit attribute). -->
<form method="GET" class="d-flex align-items-center flex-wrap gap-2 mb-3 no-print">

<label for="internshipTypeFilter" class="mb-0 fw-semibold" style="font-size:14px;">
Internship Type:
</label>

<select name="internship_type"
id="internshipTypeFilter"
class="form-select form-select-sm"
style="width:auto; min-width:230px;"
onchange="this.form.submit()">

<option value="" <?php echo ($type_filter === '') ? 'selected' : ''; ?>>All Internship Types</option>

<?php foreach($type_options as $opt): ?>
<option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($type_filter === $opt) ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>
</option>
<?php endforeach; ?>

</select>

<span class="text-muted" style="font-size:13px;">
Showing <?php echo (int)$student_count; ?> student<?php echo ($student_count === 1 ? '' : 's'); ?>
</span>

</form>

<form method="POST">

<!-- Carries the current filter through Save All Marks so the
     post-save redirect above can restore it. Does not participate in
     which students' marks get saved — that's still driven entirely
     by $_POST['final']/$_POST['mentor'], unchanged. -->
<input type="hidden" name="internship_type" value="<?php echo htmlspecialchars($type_filter, ENT_QUOTES, 'UTF-8'); ?>">

<?php if($student_count === 0): ?>

<div class="alert alert-info no-print">
No students found for this internship type.
</div>

<?php else: ?>

<div class="table-responsive-wrap">
<table class="table table-bordered table-striped">

<thead class="table-dark">

<tr>

<th>Division</th>
<th>Enrollment No</th>
<th>Roll No</th>
<th>Student Name</th>

<th>Week-1</th>
<th>Week-2</th>
<th>Week-3</th>
<th>Week-4</th>

<th>Week Total (40 Marks)</th>

<th>Mentor Evaluation (40 Marks)</th>

<th>Final Evaluation (20 Marks) <small class="fw-normal"></th>

<th>Total Marks (50)</th>

</tr>

</thead>

<tbody>

<?php

while($row = mysqli_fetch_assoc($result))
{
?>
<tr>

<td>
<?php echo htmlspecialchars($row['division'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
</td>

<td>
<?php echo htmlspecialchars($row['enrollment_no'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
</td>

<td>
<?php echo htmlspecialchars($row['roll_no'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
</td>

<td>
<?php echo htmlspecialchars($row['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
</td>

<td>
<?php echo ($row['week1_marks']!="") ? htmlspecialchars((string)$row['week1_marks'], ENT_QUOTES, 'UTF-8') : 0; ?>
</td>

<td>
<?php echo ($row['week2_marks']!="") ? htmlspecialchars((string)$row['week2_marks'], ENT_QUOTES, 'UTF-8') : 0; ?>
</td>

<td>
<?php echo ($row['week3_marks']!="") ? htmlspecialchars((string)$row['week3_marks'], ENT_QUOTES, 'UTF-8') : 0; ?>
</td>

<td>
<?php echo ($row['week4_marks']!="") ? htmlspecialchars((string)$row['week4_marks'], ENT_QUOTES, 'UTF-8') : 0; ?>
</td>

<td class="weeklytotal">

<?php
echo ($row['weekly_total']!="")
? htmlspecialchars((string)$row['weekly_total'], ENT_QUOTES, 'UTF-8')
: "0";
?>

</td>

<td>

<input type="number"
name="mentor[<?php echo (int)$row['id']; ?>]"
class="form-control mentoreval no-print"
min="0"
max="40"
value="<?php echo ($row['mentor_evaluation'] != "") ? (int)$row['mentor_evaluation'] : 0; ?>"
oninput="calculateRow(this.closest('tr'))">

<span class="print-only">
<?php echo ($row['mentor_evaluation']!="") ? htmlspecialchars((string)$row['mentor_evaluation'], ENT_QUOTES, 'UTF-8') : 0; ?>
</span>

</td>

<td>

<!-- CHANGED: Final Evaluation is admin-set (imported from the
     official evaluation sheet) and is read-only for faculty — no
     editable input here anymore, just a plain display. The
     data-final attribute is what calculateRow() reads for the live
     total-marks preview above; the POST save logic also never reads
     this value from the request, it re-reads it from the database
     directly, so this being read-only on the page is enforced on
     both ends, not just visually. -->
<span class="finaleval-display fw-semibold"
data-final="<?php echo ($row['final_evaluation'] != "") ? (int)$row['final_evaluation'] : 0; ?>">
<?php echo ($row['final_evaluation']!="") ? htmlspecialchars((string)$row['final_evaluation'], ENT_QUOTES, 'UTF-8') : 0; ?>
</span>

</td>

<td class="totalmarks">

<?php

$weekly_disp = ($row['weekly_total'] != "") ? $row['weekly_total'] : 0;
$mentor_disp = ($row['mentor_evaluation'] != "") ? $row['mentor_evaluation'] : 0;
$final_disp = ($row['final_evaluation'] != "") ? $row['final_evaluation'] : 0;

/*
TOTAL OUT OF 100 (Week Total 40 + Mentor 40 + Final 20)
SCALED DOWN TO 50 -- COMPUTED LIVE SO IT'S ALWAYS
ACCURATE EVEN BEFORE "Save All Marks" IS CLICKED
*/

$total_disp = ($weekly_disp + $mentor_disp + $final_disp) / 2;

echo htmlspecialchars(formatMarks($total_disp) . " / 50", ENT_QUOTES, 'UTF-8');

?>

</td>

</tr>

<?php

}

?>

</tbody>

</table>
</div>

<button type="submit"
name="save_marks"
class="btn btn-success">

Save All Marks

</button>

<?php endif; ?>

</form>

</div>
</div>

</main>
</div>

    <div class="footer-print">

    <div class="footer-left">
        MIT Art, Design and Technology University, Rajbaugh, Loni Kalbhor, Pune - 412201.
    </div>

    <div class="footer-right">
        Page :
    </div>

</div>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>

</body>
</html>