<?php
/**
 * InternHub — Private Storage Authorization & Delivery Endpoint
 * Streams private documents only after verifying cryptographic token & expiration
 */

session_start();
require_once "../config.php";

$objectId = (int)($_GET['id'] ?? 0);
$expires = (int)($_GET['expires'] ?? 0);
$token = $_GET['token'] ?? '';

if ($objectId <= 0 || $expires <= 0 || empty($token)) {
    http_response_code(400);
    die("Bad Request: Missing storage authorization parameters.");
}

// 1. Verify expiration
if (time() > $expires) {
    http_response_code(403);
    die("Access Denied: Signed storage access link has expired.");
}

// 2. Fetch object metadata
$stmt = $conn->prepare("SELECT * FROM storage_objects WHERE id = ? AND deleted_at IS NULL LIMIT 1");
$stmt->bind_param("i", $objectId);
$stmt->execute();
$obj = $stmt->get_result()->fetch_assoc();

if (!$obj) {
    http_response_code(404);
    die("Not Found: Requested storage object does not exist.");
}

// 3. Verify cryptographic HMAC signature
$expectedToken = hash_hmac('sha256', $obj['object_key'] . '|' . $expires, 'InternHubStorageSecretKey2026');
if (!hash_equals($expectedToken, $token)) {
    http_response_code(403);
    die("Access Denied: Invalid storage security signature.");
}

// 4. Deliver file safely
$localPath = dirname(__DIR__) . '/uploads/' . $obj['object_key'];

if (!file_exists($localPath)) {
    http_response_code(404);
    die("File not found on storage server.");
}

// Send appropriate headers
header('Content-Type: ' . $obj['mime_type']);
header('Content-Length: ' . filesize($localPath));
header('Content-Disposition: inline; filename="' . basename($obj['original_filename']) . '"');
header('Cache-Control: private, max-age=3600');

readfile($localPath);
exit();
