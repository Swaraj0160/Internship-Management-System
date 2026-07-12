<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty')
{
    header("Location: ../login.php");
    exit();
}

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=division_report.xls");

$faculty_id = $_SESSION['user_id'];

$getFaculty = mysqli_query($conn,
"SELECT faculty_division
 FROM users
 WHERE id='$faculty_id'");

$faculty = mysqli_fetch_assoc($getFaculty);

$division = $faculty['faculty_division'];

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
AND users.division='$division'

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

    <td><?php echo $row['roll_no']; ?></td>

    <td><?php echo $row['full_name']; ?></td>

    <td><?php echo $row['enrollment_no']; ?></td>

    <td><?php echo $row['phone']; ?></td>

    <td><?php echo $row['division']; ?></td>

    <td>
        <?php echo $row['status'] ? $row['status'] : 'Not Applied'; ?>
    </td>

    <td>
        <?php echo $row['internship_type'] ? $row['internship_type'] : '-'; ?>
    </td>

    <td>
        <?php echo $row['mentor_name'] ? $row['mentor_name'] : '-'; ?>
    </td>

    <td>
        <?php echo $row['company_name'] ? $row['company_name'] : '-'; ?>
    </td>

    <td>
        <?php echo $row['company_contact'] ? $row['company_contact'] : '-'; ?>
    </td>

</tr>

<?php

}

?>

</table>