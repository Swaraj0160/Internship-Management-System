<?php
session_start();
$configPath = __DIR__ . '/../config.php';
if (!file_exists($configPath)) {
    die('Configuration file not found.');
}
include $configPath;

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die('Unauthorized access.');
}

$student_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($student_id <= 0) {
    die('Invalid Student ID.');
}

// Fetch all necessary details
$sql = "
    SELECT 
        u.full_name, u.enrollment_no, u.class_division, 
        i.company_name, i.internship_role, i.internship_type,
        dc.assigned_domain,
        se.presentation_marks, se.documentation_marks, se.viva_marks, se.internship_eval_total
    FROM users u
    LEFT JOIN internships i ON i.student_id = u.id AND i.status = 'Approved'
    LEFT JOIN domain_classifications dc ON dc.enrollment_no = u.enrollment_no
    LEFT JOIN student_evaluation se ON se.student_id = u.id
    WHERE u.id = ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die('Student not found.');
}
$student = $result->fetch_assoc();

$student_name = htmlspecialchars($student['full_name'] ?? 'Unknown');
$enrollment = htmlspecialchars($student['enrollment_no'] ?? 'Unknown');
$company = htmlspecialchars($student['company_name'] ?? 'N/A');
$role = htmlspecialchars($student['internship_role'] ?? 'N/A');
$domain = htmlspecialchars($student['assigned_domain'] ?? 'N/A');
$type = htmlspecialchars($student['internship_type'] ?? 'N/A');

$p_marks = $student['presentation_marks'] ?? 0;
$d_marks = $student['documentation_marks'] ?? 0;
$v_marks = $student['viva_marks'] ?? 0;
$t_marks = $student['internship_eval_total'] ?? 0;

$date = date('F j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate - <?php echo $student_name; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,700;1,500&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            margin: 0;
            padding: 0;
            background: #e2e8f0;
            font-family: 'Plus Jakarta Sans', sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .cert-container {
            width: 800px;
            background: #ffffff;
            padding: 50px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            position: relative;
            text-align: center;
            border: 15px solid #0f172a;
            outline: 2px solid #8b5cf6;
            outline-offset: -20px;
        }
        .cert-header {
            font-family: 'Playfair Display', serif;
            font-size: 46px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        .cert-subheader {
            font-size: 16px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 4px;
            margin-bottom: 40px;
        }
        .cert-body {
            font-size: 18px;
            color: #334155;
            line-height: 1.8;
            margin-bottom: 40px;
        }
        .student-name {
            font-family: 'Playfair Display', serif;
            font-size: 42px;
            font-weight: 700;
            color: #8b5cf6;
            margin: 20px 0;
            text-transform: capitalize;
            border-bottom: 2px solid #e2e8f0;
            display: inline-block;
            padding: 0 40px 10px;
        }
        .highlight {
            font-weight: 700;
            color: #0f172a;
        }
        .marks-table {
            width: 100%;
            max-width: 600px;
            margin: 0 auto 50px;
            border-collapse: collapse;
        }
        .marks-table th, .marks-table td {
            border: 1px solid #cbd5e1;
            padding: 12px 20px;
            text-align: left;
        }
        .marks-table th {
            background: #f8fafc;
            color: #475569;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 1px;
        }
        .marks-table td {
            font-weight: 600;
            color: #0f172a;
        }
        .marks-table .total-row {
            background: #eff6ff;
            color: #1d4ed8;
            font-weight: 800;
        }
        .cert-footer {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: 50px;
        }
        .signature-block {
            text-align: center;
        }
        .signature-line {
            width: 200px;
            height: 2px;
            background: #0f172a;
            margin-bottom: 10px;
        }
        .signature-text {
            font-size: 14px;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
        }
        .print-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: #8b5cf6;
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 10px 20px rgba(139,92,246,0.3);
            transition: all 0.2s;
        }
        .print-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 25px rgba(139,92,246,0.4);
        }
        @media print {
            body { background: white; }
            .cert-container { box-shadow: none; border: 10px solid #0f172a; }
            .print-btn { display: none; }
            @page { margin: 0; size: landscape; }
        }
    </style>
</head>
<body>

    <button class="print-btn" onclick="window.print()">🖨️ Print Certificate</button>

    <div class="cert-container">
        <div class="cert-header">Certificate of Completion</div>
        <div class="cert-subheader">Final Internship Evaluation Record</div>
        
        <div class="cert-body">
            This is to certify that<br>
            <div class="student-name"><?php echo $student_name; ?></div><br>
            (Enrollment No: <span class="highlight"><?php echo $enrollment; ?></span>)<br><br>
            has successfully completed a <span class="highlight"><?php echo $type; ?></span> at <span class="highlight"><?php echo $company; ?></span>.<br>
            Their designated role during this tenure was <span class="highlight"><?php echo $role; ?></span>, specializing in the Tech Domain of <span class="highlight"><?php echo $domain; ?></span>.
        </div>

        <table class="marks-table">
            <thead>
                <tr>
                    <th>Evaluation Criteria</th>
                    <th>Max Marks</th>
                    <th>Marks Obtained</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Presentation Marks</td>
                    <td>10</td>
                    <td><?php echo $p_marks; ?></td>
                </tr>
                <tr>
                    <td>Documentation Marks</td>
                    <td>5</td>
                    <td><?php echo $d_marks; ?></td>
                </tr>
                <tr>
                    <td>Viva Marks</td>
                    <td>5</td>
                    <td><?php echo $v_marks; ?></td>
                </tr>
                <tr class="total-row">
                    <td>Total Evaluation</td>
                    <td>20</td>
                    <td><?php echo $t_marks; ?></td>
                </tr>
            </tbody>
        </table>

        <div class="cert-footer">
            <div class="signature-block">
                <div style="font-weight:600; color:#475569; margin-bottom: 20px; font-style:italic; font-family:'Playfair Display', serif;"><?php echo $date; ?></div>
                <div class="signature-line"></div>
                <div class="signature-text">Date of Issue</div>
            </div>
            <div class="signature-block">
                <div style="width:200px; height: 25px; margin-bottom: 15px;"></div>
                <div class="signature-line"></div>
                <div class="signature-text">Authorized Signature</div>
            </div>
        </div>
    </div>

</body>
</html>
