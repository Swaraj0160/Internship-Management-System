<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty')
{
    header("Location: ../login.php");
    exit();
}

/* -----------------------------------------------------------------
   NEW FILE — professional student export system for Faculty.
   Same ExcelExporter as admin/export_students.php, but the division
   filter is CONSTRAINED to the divisions this faculty member is
   actually assigned to (faculty_divisions, with users.faculty_division
   as legacy fallback) — a faculty account can never export a division
   they aren't authorized for, even by editing the URL, because the
   allowed division list is looked up server-side from the session's
   faculty_id, not trusted from any GET parameter.
   ----------------------------------------------------------------- */

$faculty_id = (int)$_SESSION['user_id'];

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

$export = isset($_GET['export']);
$requested_division = trim($_GET['division'] ?? '');
$search = trim($_GET['search'] ?? '');

if($export)
{
    require_once __DIR__ . '/../lib/ExcelExporter.php';
    require_once __DIR__ . '/../lib/EvaluationDataProvider.php';

    /* Determine which of the faculty's OWN divisions to include.
       "ALL" (or nothing selected) means all divisions this faculty is
       authorized for — never any division outside that list. */
    if($requested_division !== '' && $requested_division !== 'ALL')
    {
        if(!in_array($requested_division, $faculty_divisions, true))
        {
            http_response_code(403);
            die("You are not authorized to export this division.");
        }
        $divisions_to_export = [$requested_division];
    }
    else
    {
        $divisions_to_export = $faculty_divisions;
    }

    $div_values = array_map(
        fn($d) => "'" . mysqli_real_escape_string($conn, $d) . "'",
        $divisions_to_export
    );
    $division_list_sql = implode(',', $div_values);

    $where = ["users.role='student'", "users.division IN ($division_list_sql)"];
    $params = [];
    $types = "";

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

    /* FIX (live-testing follow-up): same "Total Marks" drift bug as
       admin/export_students.php — see that file's comment for the
       full explanation. Total Marks is now always recomputed from
       Week Total + Mentor Eval + Final Eval (halved), never read
       from the raw total_marks column, using the same malformed-value
       safe helper as every other evaluation export. */
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

    $filterSummary = "Division: " . implode(", ", $divisions_to_export);

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
            "Student_Report_" . implode("_", $divisions_to_export),
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

                <option value="ALL">All my divisions (<?php echo htmlspecialchars(implode(", ", $faculty_divisions), ENT_QUOTES, 'UTF-8'); ?>)</option>

                <?php foreach($faculty_divisions as $d): ?>
                    <option value="<?php echo htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>

            </select>

            <small class="text-muted">
                Only divisions assigned to your faculty account are shown here.
            </small>

        </div>

        <div class="mb-3">

            <label>Search (name / enrollment / roll no) — optional</label>

            <input type="text" name="search" class="form-control" placeholder="Leave blank to include everyone">

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
