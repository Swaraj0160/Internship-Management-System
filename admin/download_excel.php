<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    header("Location: ../login.php");
    exit();
}

$division = trim($_GET['division'] ?? '');

if($division === '')
{
    die("Division is required.");
}

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=Internship_Report_" . preg_replace('/[^A-Za-z0-9_\-]/', '_', $division) . ".xls");

$query = "

SELECT
users.full_name,
users.division,
internships.company_name,
internships.internship_role,
internships.internship_type,
internships.start_date,
internships.end_date,
internships.status

FROM internships

INNER JOIN users
ON internships.student_id = users.id

INNER JOIN
(
    SELECT student_id, MAX(id) as latest_id
    FROM internships
    GROUP BY student_id
) latest
ON internships.id = latest.latest_id

WHERE users.division=?

ORDER BY CAST(users.roll_no AS UNSIGNED) ASC

";

$stmt = $conn->prepare($query);
$stmt->bind_param("s", $division);
$stmt->execute();
$result = $stmt->get_result();

?>

<table border="1">

<tr>

    <th>Student</th>
    <th>Division</th>
    <th>Company</th>
    <th>Role</th>
    <th>Internship Type</th>
    <th>Start</th>
    <th>End</th>
    <th>Status</th>

</tr>

<?php

while($row = mysqli_fetch_assoc($result))
{

?>

<tr>

    <td><?php echo htmlspecialchars($row['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
    <td><?php echo htmlspecialchars($row['division'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
    <td><?php echo htmlspecialchars($row['company_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
    <td><?php echo htmlspecialchars($row['internship_role'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
    <td><?php echo htmlspecialchars($row['internship_type'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
    <td><?php echo htmlspecialchars($row['start_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
    <td><?php echo htmlspecialchars($row['end_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
    <td><?php echo htmlspecialchars($row['status'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>

</tr>

<?php

}

?>

</table>
