<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
session_start();

$configPath = __DIR__ . '/../config.php';
if (!file_exists($configPath)) {
    die('Configuration file not found.');
}
include $configPath;

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Optimized query removing the bloated division columns
$sql = "
    SELECT 
        TRIM(i.company_name) as company_name, 
        COUNT(DISTINCT i.student_id) as total_students,
        GROUP_CONCAT(DISTINCT TRIM(i.internship_role) SEPARATOR ', ') as roles_offered,
        GROUP_CONCAT(DISTINCT TRIM(dc.assigned_domain) SEPARATOR ', ') as domains
    FROM internships i
    JOIN users u ON i.student_id = u.id
    LEFT JOIN domain_classifications dc ON u.enrollment_no = dc.enrollment_no
    WHERE i.status = 'Approved' 
      AND i.company_name IS NOT NULL 
      AND TRIM(i.company_name) != ''
    GROUP BY TRIM(i.company_name)
    ORDER BY total_students DESC, company_name ASC
";

$result = mysqli_query($conn, $sql);

if (!$result) {
    die("Error fetching company data.");
}

$filename = "InternHub_Company_Analytics_" . date('Y-m-d') . ".csv";

// Output headers for a clean CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// Super professional, clean header
fputcsv($output, ['Company Name', 'Total Students Hired', 'Roles Offered', 'Tech Domains (AI Scan)']);

while ($row = mysqli_fetch_assoc($result)) {
    // Clean up empty or NA roles/domains for a more polished look
    $roles = $row['roles_offered'] ? trim($row['roles_offered'], ", ") : 'Not Specified';
    $domains = $row['domains'] ? trim($row['domains'], ", ") : 'Needs Verification';
    
    // Capitalize first letters for professionalism
    $roles = ucwords(strtolower($roles));
    
    fputcsv($output, [
        $row['company_name'],
        (int)$row['total_students'],
        $roles,
        $domains
    ]);
}

fclose($output);
exit();
?>
