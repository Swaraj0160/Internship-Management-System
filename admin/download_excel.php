<?php

session_start();
include("../config.php");

$division = $_GET['division'];

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=Internship_Report_$division.xls");

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

WHERE users.division='$division'

ORDER BY users.roll_no ASC

";

$result = mysqli_query($conn, $query);

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

    <td><?php echo $row['full_name']; ?></td>
    <td><?php echo $row['division']; ?></td>
    <td><?php echo $row['company_name']; ?></td>
    <td><?php echo $row['internship_role']; ?></td>
    <td><?php echo $row['internship_type']; ?></td>
    <td><?php echo $row['start_date']; ?></td>
    <td><?php echo $row['end_date']; ?></td>
    <td><?php echo $row['status']; ?></td>

</tr>

<?php

}

?>

</table>