<?php
session_start();
include("../config.php");

if (!isset($_SESSION['role'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_role = $_SESSION['role'] ?? '';
$sub_id = (int)($_GET['id'] ?? 0);

if ($sub_id <= 0) {
    http_response_code(400);
    die("Invalid request parameter.");
}

// Fetch submission and student enrollment
$stmt = $conn->prepare("SELECT sw.*, u.enrollment_no FROM student_work_showcase sw JOIN users u ON sw.student_id = u.id WHERE sw.id = ?");
$stmt->bind_param("i", $sub_id);
$stmt->execute();
$sub = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$sub) {
    http_response_code(404);
    die("Showcase submission not found.");
}

// Authorization check: Students can only view their own submissions. Faculty and Admin can view any.
if ($user_role === 'student' && (int)$sub['student_id'] !== $user_id) {
    http_response_code(403);
    die("Unauthorized access to this file.");
}

$file_path = trim((string)($sub['file_path'] ?? ''));
if (empty($file_path)) {
    http_response_code(404);
    die("No file attached to this submission.");
}

$enrollment_no = !empty($sub['enrollment_no']) ? preg_replace('/[^a-zA-Z0-9_\-]/', '', $sub['enrollment_no']) : ("USER_" . $sub['student_id']);
$filename_only = basename($file_path);

// Check candidate paths for storage backward-compatibility
$possible_paths = [
    __DIR__ . '/../uploads/' . $file_path,
    __DIR__ . '/../uploads/student_work/' . $enrollment_no . '/' . $sub_id . '/' . $filename_only,
    __DIR__ . '/../uploads/showcase/' . $filename_only,
    __DIR__ . '/../uploads/showcase/' . $file_path,
];

$real_file = null;
foreach ($possible_paths as $path) {
    if (!empty($path) && file_exists($path) && is_file($path)) {
        $real_file = $path;
        break;
    }
}

if (!$real_file) {
    http_response_code(404);
    echo "<!DOCTYPE html><html><head><title>File Not Found</title><link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css' rel='stylesheet'></head><body class='bg-light d-flex align-items-center justify-content-center vh-100'><div class='text-center p-5 bg-white rounded shadow-sm border'><i class='fa-solid fa-file-circle-xmark text-danger display-1 mb-3'></i><h3 class='fw-bold'>File Unavailable</h3><p class='text-muted'>The file referenced by this submission is not available on the server.</p><button onclick='window.close()' class='btn btn-secondary mt-2'>Close Window</button></div></body></html>";
    exit();
}

$ext = strtolower(pathinfo($real_file, PATHINFO_EXTENSION));
$mime_types = [
    'pdf'  => 'application/pdf',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'ppt'  => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'zip'  => 'application/zip',
];

$content_type = $mime_types[$ext] ?? 'application/octet-stream';
$disposition = in_array($ext, ['pdf', 'png', 'jpg', 'jpeg']) ? 'inline' : 'attachment';

if (!headers_sent()) {
    header('Content-Type: ' . $content_type);
    header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($filename_only) . '"');
    header('Content-Length: ' . filesize($real_file));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
}

readfile($real_file);
exit();
