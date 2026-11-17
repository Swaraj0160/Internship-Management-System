<?php
/* -----------------------------------------------------------------
   InternHub — Faculty Actions API Endpoint
   Handles:
   - Application Quick Approve / Reject / Undo
   - Deadline updates
   - Bookmark toggling
   - Marks saving
   ----------------------------------------------------------------- */

session_start();
header('Content-Type: application/json; charset=UTF-8');
include __DIR__ . '/../../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'faculty') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized faculty session.']);
    exit();
}

$faculty_id = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Ensure faculty_bookmarks table exists
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS faculty_bookmarks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    faculty_id INT NOT NULL,
    student_id INT NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fac_stu (faculty_id, student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

switch ($action) {
    case 'toggle_bookmark':
        $student_id = (int)($_POST['student_id'] ?? 0);
        if ($student_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid student ID.']);
            exit();
        }

        $check = mysqli_query($conn, "SELECT id FROM faculty_bookmarks WHERE faculty_id='$faculty_id' AND student_id='$student_id'");
        if ($check && mysqli_num_rows($check) > 0) {
            mysqli_query($conn, "DELETE FROM faculty_bookmarks WHERE faculty_id='$faculty_id' AND student_id='$student_id'");
            echo json_encode(['success' => true, 'bookmarked' => false, 'message' => 'Student removed from bookmarks.']);
        } else {
            mysqli_query($conn, "INSERT INTO faculty_bookmarks (faculty_id, student_id) VALUES ('$faculty_id', '$student_id')");
            echo json_encode(['success' => true, 'bookmarked' => true, 'message' => 'Student bookmarked for mentorship.']);
        }
        break;

    case 'quick_approve':
        $internship_id = (int)($_POST['internship_id'] ?? 0);
        $remarks = trim($_POST['remarks'] ?? 'Application approved by faculty guide.');
        if ($internship_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid internship application ID.']);
            exit();
        }

        $upd = mysqli_query($conn, "UPDATE internships SET status='Approved' WHERE id='$internship_id'");
        if ($upd) {
            // Fetch student ID for notification
            $int_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT student_id, company_name FROM internships WHERE id='$internship_id'"));
            if ($int_row) {
                $stu_id = (int)$int_row['student_id'];
                $title = "Internship Application Approved";
                $msg = "Your internship application for " . $int_row['company_name'] . " has been approved by your faculty guide.";
                mysqli_query($conn, "INSERT INTO user_notifications (user_id, title, message, is_read, created_at) VALUES ('$stu_id', '$title', '" . mysqli_real_escape_string($conn, $msg) . "', 0, NOW())");
            }
            echo json_encode(['success' => true, 'message' => 'Application approved successfully.']);
        } else {
            echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        }
        break;

    case 'quick_reject':
        $internship_id = (int)($_POST['internship_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        if ($internship_id <= 0 || empty($reason)) {
            echo json_encode(['success' => false, 'error' => 'Please provide a specific constructive reason for rejection.']);
            exit();
        }

        $upd = mysqli_query($conn, "UPDATE internships SET status='Rejected' WHERE id='$internship_id'");
        if ($upd) {
            $int_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT student_id, company_name FROM internships WHERE id='$internship_id'"));
            if ($int_row) {
                $stu_id = (int)$int_row['student_id'];
                $title = "Internship Application Requires Revision";
                $msg = "Your internship application for " . $int_row['company_name'] . " requires revision. Feedback: " . $reason;
                mysqli_query($conn, "INSERT INTO user_notifications (user_id, title, message, is_read, created_at) VALUES ('$stu_id', '$title', '" . mysqli_real_escape_string($conn, $msg) . "', 0, NOW())");
            }
            echo json_encode(['success' => true, 'message' => 'Application status updated to Rejected with constructive feedback.']);
        } else {
            echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        }
        break;

    case 'undo_status':
        $internship_id = (int)($_POST['internship_id'] ?? 0);
        if ($internship_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid internship application ID.']);
            exit();
        }

        $upd = mysqli_query($conn, "UPDATE internships SET status='Pending' WHERE id='$internship_id'");
        if ($upd) {
            echo json_encode(['success' => true, 'message' => 'Application status reverted to Pending.']);
        } else {
            echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        }
        break;

    case 'save_deadline':
        $division = trim($_POST['division'] ?? '');
        $week_no = (int)($_POST['week_no'] ?? 0);
        $deadline_date = trim($_POST['deadline_date'] ?? '');

        if (empty($division) || $week_no <= 0 || empty($deadline_date)) {
            echo json_encode(['success' => false, 'error' => 'All deadline fields are required.']);
            exit();
        }

        // Format to MySQL DATETIME
        $dt = date('Y-m-d H:i:s', strtotime($deadline_date));
        $check = mysqli_query($conn, "SELECT id FROM weekly_deadlines WHERE division='" . mysqli_real_escape_string($conn, $division) . "' AND week_no='$week_no'");
        if ($check && mysqli_num_rows($check) > 0) {
            $row = mysqli_fetch_assoc($check);
            mysqli_query($conn, "UPDATE weekly_deadlines SET deadline_date='$dt' WHERE id='" . $row['id'] . "'");
        } else {
            mysqli_query($conn, "INSERT INTO weekly_deadlines (division, week_no, deadline_date, created_at) VALUES ('" . mysqli_real_escape_string($conn, $division) . "', '$week_no', '$dt', NOW())");
        }

        echo json_encode(['success' => true, 'message' => "Deadline for Week $week_no updated successfully."]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
        break;
}
