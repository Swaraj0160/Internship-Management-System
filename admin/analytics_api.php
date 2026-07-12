<?php

include("../config.php");

$division = $_GET['division'] ?? '';
$type = $_GET['type'] ?? '';
$company = $_GET['company'] ?? '';

$where = [];

if($division!='')
{
    $where[] = "u.division='".mysqli_real_escape_string($conn,$division)."'";
}

if($type!='')
{
    $where[] = "i.internship_type='".mysqli_real_escape_string($conn,$type)."'";
}

if($company!='')
{
    $where[] = "i.company_name='".mysqli_real_escape_string($conn,$company)."'";
}

$sql = "
SELECT
COUNT(*) total_students,

SUM(i.status='Approved') approved,
SUM(i.status='Pending') pending,
SUM(i.status='Rejected') rejected

FROM users u

LEFT JOIN internships i
ON u.id=i.student_id

WHERE u.role='student'
";

if(count($where)>0)
{
    $sql .= " AND ".implode(" AND ",$where);
}

$result=mysqli_query($conn,$sql);

echo json_encode(mysqli_fetch_assoc($result));