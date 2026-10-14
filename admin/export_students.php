<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    header("Location: ../login.php");
    exit();
}

/* -----------------------------------------------------------------
   NEW FILE — professional student export system for Admin.
   Generates a real .xlsx (via lib/ExcelExporter.php + PhpSpreadsheet)
   instead of the old HTML-table-served-as-.xls trick used by
   download_excel.php (which remains untouched/working for backward
   compatibility with anything still linking to it).

   Filters supported: All Students, or a single Division.
   Fields exported are limited to columns that actually exist in the
   provided schema (users / internships / student_evaluation). No
   "academic year"/"semester" fields are included because no such
   columns exist in users or internships in the schema supplied —
   add them here later if/when those columns are added.

   NEVER exports: password, auth_secret, auth_enabled, OTP data,
   trusted_devices tokens, or any other authentication field.
   ----------------------------------------------------------------- */

$export = isset($_GET['export']);
$division = trim($_GET['division'] ?? '');
$search = trim($_GET['search'] ?? '');

if($export)
{
    require_once __DIR__ . '/../lib/ExcelExporter.php';
    require_once __DIR__ . '/../lib/EvaluationDataProvider.php';

    $where = ["users.role='student'"];
    $params = [];
    $types = "";

    if($division !== '' && $division !== 'ALL')
    {
        $where[] = "users.division=?";
        $params[] = $division;
        $types .= "s";
    }

    if($search !== '')
    {
        $where[] = "(users.full_name LIKE ? OR users.enrollment_no LIKE ? OR users.roll_no LIKE ?)";
        $like = "%" . $search . "%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= "sss";
    }

    $whereSql = implode(" AND ", $where);

    $sql = "
        SELECT
            users.full_name,
            users.enrollment_no,
            users.roll_no,
            users.email,
            users.phone,
            users.department,
            users.division,

            internships.company_name,
            internships.internship_role,
            internships.internship_type,
            internships.mentor_name,
            internships.mode,
            internships.start_date,
            internships.end_date,
            internships.stipend,
            internships.status,

            student_evaluation.week1_marks,
            student_evaluation.week2_marks,
            student_evaluation.week3_marks,
            student_evaluation.week4_marks,
            student_evaluation.weekly_total,
            student_evaluation.mentor_evaluation,
            student_evaluation.final_evaluation,
            student_evaluation.total_marks

        FROM users

        LEFT JOIN internships
            ON internships.id = (
                SELECT MAX(i.id) FROM internships i WHERE i.student_id = users.id
            )

        LEFT JOIN student_evaluation
            ON student_evaluation.student_id = users.id

        WHERE $whereSql

        ORDER BY
            CASE users.division
                WHEN 'IT-DA'   THEN 0
                WHEN 'IT-SMAD' THEN 1
                ELSE 2
            END ASC,
            users.division ASC,
            CAST(users.roll_no AS UNSIGNED) ASC
    ";

    $stmt = $conn->prepare($sql);

    if(count($params) > 0)
    {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    /* -----------------------------------------------------------------
       FIX (live-testing follow-up): "Total Marks" was reading the raw
       stored student_evaluation.total_marks column directly. That
       column drifts out of sync with the actual marking scheme —
       Week Total (max 40) + Mentor Eval (max 40) + Final Eval (max
       20) = 100, halved to a mark out of 50 — for any row where
       total_marks wasn't re-saved through that exact formula (e.g.
       older/bulk-imported rows, where it looks like total_marks was
       simply set equal to the raw Week Total instead of being
       halved). Confirmed live: e.g. a row with Week Total 20, Mentor
       0, Final 0 showed "Total Marks" 20 instead of the correct
       (20+0+0)/2 = 10.

       Fixed by never trusting the stored total_marks column here —
       Total Marks is now ALWAYS recomputed from the three components
       using the exact same formula and the exact same malformed-value
       safety (EvaluationDataProvider::formatMarksValue — never lets a
       corrupted non-numeric DB value like "Oct-50" silently reach this
       export either) as every other evaluation export in this project,
       so this can never drift out of sync with them again. */
    $rows = [];
    while($row = mysqli_fetch_assoc($result))
    {
        $weekly_total = EvaluationDataProvider::formatMarksValue($row['weekly_total']);
        $mentor = EvaluationDataProvider::formatMarksValue($row['mentor_evaluation']);
        $final = EvaluationDataProvider::formatMarksValue($row['final_evaluation']);

        $row['week1_marks'] = EvaluationDataProvider::formatMarksValue($row['week1_marks']);
        $row['week2_marks'] = EvaluationDataProvider::formatMarksValue($row['week2_marks']);
        $row['week3_marks'] = EvaluationDataProvider::formatMarksValue($row['week3_marks']);
        $row['week4_marks'] = EvaluationDataProvider::formatMarksValue($row['week4_marks']);
        $row['weekly_total'] = $weekly_total;
        $row['mentor_evaluation'] = $mentor;
        $row['final_evaluation'] = $final;
        $row['total_marks'] = EvaluationDataProvider::formatMarksValue(($weekly_total + $mentor + $final) / 2);

        $rows[] = $row;
    }

    $headers = [
        "Roll No", "Name", "Enrollment No", "Division", "Email", "Phone", "Department",
        "Internship Status", "Company", "Role", "Internship Type", "Mentor", "Mode",
        "Start Date", "End Date", "Stipend",
        "W1", "W2", "W3", "W4", "Weekly Total", "Mentor Eval", "Final Eval", "Total Marks"
    ];

    $columnMap = [
        "Roll No"            => "roll_no",
        "Name"                => "full_name",
        "Enrollment No"       => "enrollment_no",
        "Division"            => "division",
        "Email"               => "email",
        "Phone"               => "phone",
        "Department"          => "department",
        "Internship Status"   => "status",
        "Company"             => "company_name",
        "Role"                => "internship_role",
        "Internship Type"     => "internship_type",
        "Mentor"              => "mentor_name",
        "Mode"                => "mode",
        "Start Date"          => "start_date",
        "End Date"            => "end_date",
        "Stipend"             => "stipend",
        "W1"                  => "week1_marks",
        "W2"                  => "week2_marks",
        "W3"                  => "week3_marks",
        "W4"                  => "week4_marks",
        "Weekly Total"        => "weekly_total",
        "Mentor Eval"         => "mentor_evaluation",
        "Final Eval"          => "final_evaluation",
        "Total Marks"         => "total_marks",
    ];

    $filterSummary = ($division !== '' && $division !== 'ALL')
        ? "Division: " . $division
        : "All Divisions";

    if($search !== '')
    {
        $filterSummary .= "   |   Search: \"" . $search . "\"";
    }

    try {
        ExcelExporter::streamStudentReport(
            "MIT Internship Portal — Student Report",
            $filterSummary,
            $headers,
            $rows,
            $columnMap,
            "Student_Report_" . ($division !== '' && $division !== 'ALL' ? $division : "All"),
            "Students"
        );
    } catch (ExcelExporterException $e) {
        ExcelExporter::renderSetupError($e->getMessage());
    }

    exit();
}

?>

<!DOCTYPE html>
<html>
<head>

<title>Export Students</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?php include __DIR__ . '/includes/sidebar_head.php'; ?>

<style>

body{
    background:#f1f5f9;
    margin:0;
}

.page-inner{
    padding:0 24px 40px;
}

.form-box{
    width:600px;
    max-width:100%;
    margin:auto;
    margin-top:24px;
    background:white;
    padding:30px;
    border-radius:15px;
    box-shadow:0px 2px 10px rgba(0,0,0,0.1);
}

</style>

</head>

<body>

<?php $activePage = 'export_students.php'; include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="page-inner">
<div class="form-box">

    <h2 class="mb-4">
        Export Students
    </h2>

    <form method="GET">

        <input type="hidden" name="export" value="1">

        <div class="mb-3">

            <label>Division</label>

            <select name="division" class="form-control">

                <option value="ALL">All Divisions</option>

                <option>IT-SMAD</option>
                <option>IT-DA</option>

                <option>SY-1</option>
                <option>SY-2</option>
                <option>SY-3</option>
                <option>SY-4</option>
                <option>SY-5</option>
                <option>SY-6</option>
                <option>SY-7</option>
                <option>SY-8</option>
                <option>SY-9</option>
                <option>SY-10</option>
                <option>SY-11</option>
                <option>SY-12</option>
                <option>SY-13</option>
                <option>SY-14</option>
                <option>SY-15</option>
                <option>SY-16</option>
                <option>SY-17</option>
                <option>SY-18</option>
                <option>SY-19</option>
                <option>SY-20</option>
                <option>SY-21</option>
                <option>SY-22</option>

            </select>

        </div>

        <div class="mb-3">

            <label>Search (name / enrollment / roll no) — optional</label>

            <input type="text" name="search" class="form-control" placeholder="Leave blank to include everyone in the selected division">

        </div>

        <button type="submit" class="btn btn-primary w-100">
            Generate Excel Report (.xlsx)
        </button>

    </form>

    <a href="dashboard.php" class="btn btn-link mt-3">← Back to Dashboard</a>

</div>
</div>

</main>
</div>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>

</body>
</html>
