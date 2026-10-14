<?php
session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: ../login.php");
    exit();
}

$sql = "
    SELECT 
        i.company_name, 
        COUNT(DISTINCT i.student_id) as total_students,
        GROUP_CONCAT(DISTINCT i.internship_role SEPARATOR ', ') as roles_offered,
        GROUP_CONCAT(DISTINCT dc.assigned_domain SEPARATOR ', ') as domains,
        GROUP_CONCAT(DISTINCT u.class_division SEPARATOR ', ') as classes_hired_from
    FROM internships i
    JOIN users u ON i.student_id = u.id
    LEFT JOIN domain_classifications dc ON u.enrollment_no = dc.enrollment_no
    WHERE i.status = 'Approved' 
      AND i.company_name IS NOT NULL 
      AND TRIM(i.company_name) != ''
    GROUP BY i.company_name
    ORDER BY total_students DESC
";
$result = mysqli_query($conn, $sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Company Analytics</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<?php include __DIR__ . '/includes/sidebar_head.php'; ?>
<style>
body { background:#F8FAFC; font-family:'Inter', sans-serif; }
.page-inner { padding: 40px; }
.card { border:none; border-radius:16px; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); }
.table thead th { background:#F1F5F9; color:#475569; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; padding:16px; white-space:nowrap; }
.table tbody td { padding:16px; vertical-align:middle; color:#334155; font-size:14px; }
.company-name { font-weight:700; color:#0F172A; }
.badge-custom { background:#E2E8F0; color:#475569; font-weight:600; padding:6px 12px; border-radius:8px; margin:2px; display:inline-block; font-size:12px; }
</style>
</head>
<body>
<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>
<div class="page-inner">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-1">Company Analytics</h3>
            <p class="text-muted mb-0">Detailed breakdown of hiring metrics across all internship partners.</p>
        </div>
        <a href="export_companies.php" class="btn btn-primary" style="background:#8b5cf6; border:none; border-radius:10px; font-weight:600; padding:10px 20px;">
            <i class="bi bi-download me-2"></i> Download Full CSV
        </a>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Company Name</th>
                        <th class="text-center">Students Hired</th>
                        <th>Roles Offered</th>
                        <th>Domains</th>
                        <th>Classes Hired From</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = mysqli_fetch_assoc($result)): ?>
                    <tr>
                        <td class="company-name"><?php echo htmlspecialchars($row['company_name']); ?></td>
                        <td class="text-center"><span class="badge bg-success" style="font-size:14px; padding:8px 12px; border-radius:8px;"><?php echo (int)$row['total_students']; ?></span></td>
                        <td>
                            <?php 
                            $roles = explode(', ', $row['roles_offered']);
                            foreach($roles as $r) {
                                if(trim($r)) echo '<span class="badge-custom"><i class="bi bi-person-badge"></i> '.htmlspecialchars(trim($r)).'</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <?php 
                            $domains = explode(', ', $row['domains']);
                            foreach($domains as $d) {
                                if(trim($d)) echo '<span class="badge-custom" style="background:#DBEAFE; color:#1E40AF;"><i class="bi bi-braces"></i> '.htmlspecialchars(trim($d)).'</span>';
                            }
                            ?>
                        </td>
                        <td style="max-width:200px; white-space:normal; font-size:13px;">
                            <?php echo htmlspecialchars($row['classes_hired_from']); ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>
</body>
</html>
