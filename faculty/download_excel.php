<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty')
{
    header("Location: ../login.php");
    exit();
}

$faculty_id = (int)$_SESSION['user_id'];

/* -----------------------------------------------------------------
   DIVISION LOOKUP — multi-division aware, matching faculty/dashboard.php
   The previous version of this file only read users.faculty_division
   (a single legacy value). A faculty member whose divisions are set
   only through the faculty_divisions mapping table (multi-division
   support) would get $division = '' here and this export would
   silently return zero rows. This mirrors the same lookup already
   used correctly on the dashboard.
   ----------------------------------------------------------------- */
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

$div_values = array_map(
    fn($d) => "'" . mysqli_real_escape_string($conn, $d) . "'",
    $faculty_divisions
);
$division_list_sql = implode(',', $div_values);

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=division_report.xls");

$query = "

SELECT
users.roll_no,
users.full_name,
users.enrollment_no,
users.phone,
users.division,

internships.company_name,
internships.company_contact,
internships.mentor_name,
internships.internship_role,
internships.internship_type,
internships.start_date,
internships.end_date,
internships.status

FROM users

LEFT JOIN internships
ON internships.id =
(
    SELECT MAX(i.id)
    FROM internships i
    WHERE i.student_id = users.id
)

WHERE users.role='student'
AND users.division IN ($division_list_sql)

ORDER BY CAST(users.roll_no AS UNSIGNED) ASC

";

$result = mysqli_query($conn, $query);

?>

<table border="1">

<tr>

    <th>Roll No</th>
    <th>Student Name</th>
    <th>Enrollment No</th>
    <th>Phone</th>
    <th>Division</th>
    <th>Status</th>
    <th>Internship Type</th>
    <th>Mentor Name</th>
    <th>Company</th>
    <th>Company Contact</th>

</tr>

<?php

while($row = mysqli_fetch_assoc($result))
{

?>

<tr>

    <td><?php echo htmlspecialchars($row['roll_no'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>

    <td><?php echo htmlspecialchars($row['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>

    <td><?php echo htmlspecialchars($row['enrollment_no'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>

    <td><?php echo htmlspecialchars($row['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>

    <td><?php echo htmlspecialchars($row['division'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>

    <td>
        <?php echo htmlspecialchars($row['status'] ? $row['status'] : 'Not Applied', ENT_QUOTES, 'UTF-8'); ?>
    </td>

    <td>
        <?php echo htmlspecialchars($row['internship_type'] ? $row['internship_type'] : '-', ENT_QUOTES, 'UTF-8'); ?>
    </td>

    <td>
        <?php echo htmlspecialchars($row['mentor_name'] ? $row['mentor_name'] : '-', ENT_QUOTES, 'UTF-8'); ?>
    </td>

    <td>
        <?php echo htmlspecialchars($row['company_name'] ? $row['company_name'] : '-', ENT_QUOTES, 'UTF-8'); ?>
    </td>

    <td>
        <?php echo htmlspecialchars($row['company_contact'] ? $row['company_contact'] : '-', ENT_QUOTES, 'UTF-8'); ?>
    </td>

</tr>

<?php

}

?>

</table>
