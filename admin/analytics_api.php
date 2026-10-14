<?php

session_start();
include("../config.php");

header("Content-Type: application/json");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    http_response_code(403);
    echo json_encode(["error" => "Unauthorized"]);
    exit();
}

$division = $_GET['division'] ?? '';
$type = $_GET['type'] ?? '';
$company = $_GET['company'] ?? '';

$where = [];
$params = [];
$types = "";

if($division!='')
{
    $where[] = "u.division=?";
    $params[] = $division;
    $types .= "s";
}

if($type!='')
{
    $where[] = "i.internship_type=?";
    $params[] = $type;
    $types .= "s";
}

if($company!='')
{
    $where[] = "i.company_name=?";
    $params[] = $company;
    $types .= "s";
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

$stmt = $conn->prepare($sql);

if(count($params) > 0)
{
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

echo json_encode(mysqli_fetch_assoc($result));
