<?php
/**
 * InternHub — College Community Feed (community.php)
 * Peer learning, project showcases, internship experiences, and Q&A
 * Synchronized categories, modal composer, live search, comments, and portfolio links.
 */

session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/StorageManager.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../login.php");
    exit;
}

$student_id = (int)$_SESSION['user_id'];
$activePage = 'community.php';

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Category Definition & Mapping
$validCategories = [
    'all'                   => ['label' => 'All Posts',              'icon' => 'fa-layer-group',     'badge' => 'badge-all'],
    'showcase'              => ['label' => 'Project Showcases',      'icon' => 'fa-laptop-code',     'badge' => 'badge-showcase'],
    'internship_experience' => ['label' => 'Internship Experiences', 'icon' => 'fa-briefcase',       'badge' => 'badge-experience'],
    'question'              => ['label' => 'Technical Q&A',          'icon' => 'fa-circle-question', 'badge' => 'badge-question'],
    'general'               => ['label' => 'General Discussions',    'icon' => 'fa-comments',        'badge' => 'badge-general']
];

$filterType = $_GET['type'] ?? 'all';
if (!array_key_exists($filterType, $validCategories)) {
    $filterType = 'all';
}

$searchQuery = trim($_GET['q'] ?? '');
$sortBy = trim($_GET['sort'] ?? 'latest');
if (!in_array($sortBy, ['latest', 'popular'])) {
    $sortBy = 'latest';
}

$message = '';
$messageType = '';

if (isset($_GET['created']) && $_GET['created'] === '1') {
    $message = 'Your post has been published to the university community!';
    $messageType = 'success';
}

// Form state retention for validation errors
$composer_title = '';
$composer_content = '';
$composer_tags = '';
$composer_type = ($filterType !== 'all') ? $filterType : 'showcase';

// -------------------------------------------------------------
// Form Submissions Handling
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Action 1: Create Post
    if ($action === 'create_post') {
        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $submittedToken)) {
            $message = 'Security token validation failed. Please refresh and try again.';
            $messageType = 'danger';
        } else {
            $composer_title = trim($_POST['title'] ?? '');
            $composer_content = trim($_POST['content'] ?? '');
            $chosenType = trim($_POST['post_type'] ?? 'general');
            $composer_tags = trim($_POST['tags'] ?? '');

            if (!in_array($chosenType, ['showcase', 'internship_experience', 'question', 'general'])) {
                $chosenType = 'general';
            }
            $composer_type = $chosenType;

            if (empty($composer_title) || empty($composer_content)) {
                $message = 'Please provide both a post title and content description.';
                $messageType = 'danger';
            } else {
                $attachment_file = null;
                $uploadOk = true;

                if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                    $fileTmp = $_FILES['attachment']['tmp_name'];
                    $fileName = $_FILES['attachment']['name'];
                    $fileSize = $_FILES['attachment']['size'];
                    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                        $message = 'Invalid attachment format. Only JPG, PNG, and WEBP images are accepted.';
                        $messageType = 'danger';
                        $uploadOk = false;
                    } elseif ($fileSize > 5 * 1024 * 1024) { // 5MB limit
                        $message = 'Image attachment exceeds the 5MB size limit.';
                        $messageType = 'danger';
                        $uploadOk = false;
                    } else {
                        $cdir = __DIR__ . '/../uploads/community/';
                        if (!is_dir($cdir)) {
                            @mkdir($cdir, 0755, true);
                        }
                        $cleanName = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', pathinfo($fileName, PATHINFO_FILENAME));
                        $attachment_file = time() . '_comm_' . substr($cleanName, 0, 40) . '.' . $ext;
                        if (!move_uploaded_file($fileTmp, $cdir . $attachment_file)) {
                            $attachment_file = null;
                        }
                    }
                }

                if ($uploadOk) {
                    $inStmt = $conn->prepare("
                        INSERT INTO community_posts (author_id, author_role, title, content, attachment_file, tags, post_type, created_at, updated_at)
                        VALUES (?, 'student', ?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    $inStmt->bind_param("isssss", $student_id, $composer_title, $composer_content, $attachment_file, $composer_tags, $composer_type);

                    if ($inStmt->execute()) {
                        $inStmt->close();
                        header("Location: community.php?type=" . urlencode($composer_type) . "&created=1");
                        exit;
                    } else {
                        $message = 'Failed to publish post: ' . $conn->error;
                        $messageType = 'danger';
                        $inStmt->close();
                    }
                }
            }
        }
    }

    // Action 2: Like / Unlike AJAX & POST
    elseif ($action === 'react_post') {
        $postId = (int)($_POST['post_id'] ?? 0);
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if ($postId > 0) {
            $chkR = $conn->prepare("SELECT id FROM post_reactions WHERE post_id = ? AND user_id = ?");
            $chkR->bind_param("ii", $postId, $student_id);
            $chkR->execute();
            $existR = $chkR->get_result()->fetch_assoc();
            $chkR->close();

            $hasLiked = false;
            if ($existR) {
                // Unlike
                $delR = $conn->prepare("DELETE FROM post_reactions WHERE id = ?");
                $delR->bind_param("i", $existR['id']);
                $delR->execute();
                $delR->close();
                $conn->query("UPDATE community_posts SET likes_count = GREATEST(0, likes_count - 1) WHERE id = {$postId}");
                $hasLiked = false;
            } else {
                // Like
                $addR = $conn->prepare("INSERT INTO post_reactions (post_id, user_id, reaction_type, created_at) VALUES (?, ?, 'like', NOW())");
                $addR->bind_param("ii", $postId, $student_id);
                $addR->execute();
                $addR->close();
                $conn->query("UPDATE community_posts SET likes_count = likes_count + 1 WHERE id = {$postId}");
                $hasLiked = true;
            }

            $countRes = $conn->query("SELECT likes_count FROM community_posts WHERE id = {$postId}");
            $newCount = (int)($countRes->fetch_assoc()['likes_count'] ?? 0);

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'has_liked' => $hasLiked, 'likes_count' => $newCount]);
                exit;
            }
        }
    }

    // Action 3: Add Comment
    elseif ($action === 'add_comment') {
        $postId = (int)($_POST['post_id'] ?? 0);
        $commentText = trim($_POST['comment'] ?? '');

        if ($postId > 0 && !empty($commentText)) {
            $inC = $conn->prepare("INSERT INTO post_comments (post_id, user_id, comment, created_at) VALUES (?, ?, ?, NOW())");
            $inC->bind_param("iis", $postId, $student_id, $commentText);
            if ($inC->execute()) {
                $conn->query("UPDATE community_posts SET comments_count = comments_count + 1 WHERE id = {$postId}");
                $message = 'Your comment has been posted.';
                $messageType = 'success';
            }
            $inC->close();
        }
    }

    // Action 4: Delete Post (Author Only)
    elseif ($action === 'delete_post') {
        $postId = (int)($_POST['post_id'] ?? 0);
        if ($postId > 0) {
            $chkAuthor = $conn->prepare("SELECT id, attachment_file FROM community_posts WHERE id = ? AND author_id = ? AND author_role = 'student'");
            $chkAuthor->bind_param("ii", $postId, $student_id);
            $chkAuthor->execute();
            $pRow = $chkAuthor->get_result()->fetch_assoc();
            $chkAuthor->close();

            if ($pRow) {
                if (!empty($pRow['attachment_file'])) {
                    $attPath = __DIR__ . '/../uploads/community/' . $pRow['attachment_file'];
                    if (file_exists($attPath)) {
                        @unlink($attPath);
                    }
                }
                $conn->query("DELETE FROM post_reactions WHERE post_id = {$postId}");
                $conn->query("DELETE FROM post_comments WHERE post_id = {$postId}");
                $delStmt = $conn->prepare("DELETE FROM community_posts WHERE id = ?");
                $delStmt->bind_param("i", $postId);
                $delStmt->execute();
                $delStmt->close();

                $message = 'Your post has been successfully removed.';
                $messageType = 'success';
            } else {
                $message = 'Unauthorized to delete this post.';
                $messageType = 'danger';
            }
        }
    }
}

// -------------------------------------------------------------
// Category Count Query
// -------------------------------------------------------------
$categoryCounts = [
    'all'                   => 0,
    'showcase'              => 0,
    'internship_experience' => 0,
    'question'              => 0,
    'general'               => 0
];

$countsRes = mysqli_query($conn, "SELECT post_type, COUNT(*) as cnt FROM community_posts WHERE is_moderated = 0 GROUP BY post_type");
if ($countsRes) {
    $totalCount = 0;
    while ($cr = mysqli_fetch_assoc($countsRes)) {
        $pt = $cr['post_type'];
        $cVal = (int)$cr['cnt'];
        if (isset($categoryCounts[$pt])) {
            $categoryCounts[$pt] = $cVal;
        }
        $totalCount += $cVal;
    }
    $categoryCounts['all'] = $totalCount;
}

// -------------------------------------------------------------
// Fetch Filtered Feed Posts
// -------------------------------------------------------------
$whereClauses = ["cp.is_moderated = 0"];
$queryParams = [];
$queryTypes = '';

if ($filterType !== 'all') {
    $whereClauses[] = "cp.post_type = ?";
    $queryParams[] = $filterType;
    $queryTypes .= 's';
}

if (!empty($searchQuery)) {
    $whereClauses[] = "(cp.title LIKE ? OR cp.content LIKE ? OR cp.tags LIKE ? OR u.full_name LIKE ?)";
    $likeQ = '%' . $searchQuery . '%';
    $queryParams[] = $likeQ;
    $queryParams[] = $likeQ;
    $queryParams[] = $likeQ;
    $queryParams[] = $likeQ;
    $queryTypes .= 'ssss';
}

$whereSql = implode(' AND ', $whereClauses);
$orderSql = ($sortBy === 'popular') ? "cp.is_pinned DESC, cp.likes_count DESC, cp.created_at DESC" : "cp.is_pinned DESC, cp.created_at DESC";

$feedQuery = "
    SELECT cp.*, u.full_name AS author_name, u.division AS author_division, u.department AS author_dept, u.profile_photo AS author_photo,
           (SELECT COUNT(*) FROM post_reactions pr WHERE pr.post_id = cp.id AND pr.user_id = {$student_id}) AS user_has_liked
    FROM community_posts cp
    JOIN users u ON cp.author_id = u.id
    WHERE {$whereSql}
    ORDER BY {$orderSql}
    LIMIT 40
";

$feedStmt = $conn->prepare($feedQuery);
if (!empty($queryParams)) {
    $feedStmt->bind_param($queryTypes, ...$queryParams);
}
$feedStmt->execute();
$feedRes = $feedStmt->get_result();
$posts = [];
if ($feedRes) {
    while ($p = $feedRes->fetch_assoc()) {
        // Fetch comments for this post
        $cStmt = $conn->prepare("
            SELECT pc.*, u.full_name AS commenter_name, u.profile_photo AS commenter_photo
            FROM post_comments pc
            JOIN users u ON pc.user_id = u.id
            WHERE pc.post_id = ?
            ORDER BY pc.created_at ASC
            LIMIT 15
        ");
        $cStmt->bind_param("i", $p['id']);
        $cStmt->execute();
        $cRes = $cStmt->get_result();
        $p['comments'] = [];
        if ($cRes) {
            while ($c = $cRes->fetch_assoc()) {
                $p['comments'][] = $c;
            }
        }
        $cStmt->close();
        $posts[] = $p;
    }
}
$feedStmt->close();

// Helper for Relative Time
function format_time_ago($timestamp) {
    $diff = time() - strtotime($timestamp);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('d M Y', strtotime($timestamp));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MIT-ADT Community — InternHub</title>
    <?php require_once __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
    .community-wrapper {
        padding: 0 32px 36px;
        display: flex;
        flex-direction: column;
        gap: 22px;
    }

    /* Page Header Banner */
    .community-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        background: #ffffff;
        border-radius: 20px;
        padding: 22px 28px;
        border: 1px solid rgba(226, 232, 240, 0.85);
        box-shadow: 0 4px 18px rgba(0, 0, 0, 0.035);
        flex-wrap: wrap;
    }

    .community-header-left {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .community-title-row {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .community-title {
        font-size: 1.35rem;
        font-weight: 800;
        color: #1e1b4b;
        letter-spacing: -0.01em;
    }

    .community-badge {
        background: #ede9fe;
        color: #6d28d9;
        font-size: 0.72rem;
        font-weight: 700;
        padding: 3px 10px;
        border-radius: 999px;
    }

    .community-sub {
        font-size: 0.86rem;
        color: #64748b;
        font-weight: 500;
    }

    .btn-create-post {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%);
        color: #ffffff;
        font-size: 0.88rem;
        font-weight: 700;
        padding: 11px 22px;
        border-radius: 999px;
        border: none;
        cursor: pointer;
        box-shadow: 0 4px 14px rgba(124, 58, 237, 0.28);
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        text-decoration: none;
    }

    .btn-create-post:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(124, 58, 237, 0.38);
        color: #ffffff;
    }

    /* Category Navigation */
    .category-nav-bar {
        display: flex;
        align-items: center;
        gap: 10px;
        background: #ffffff;
        padding: 8px 10px;
        border-radius: 999px;
        border: 1px solid rgba(226, 232, 240, 0.85);
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03);
        overflow-x: auto;
        scrollbar-width: none;
    }

    .category-nav-bar::-webkit-scrollbar { display: none; }

    .category-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 9px 18px;
        border-radius: 999px;
        font-size: 0.82rem;
        font-weight: 600;
        color: #475569;
        text-decoration: none;
        white-space: nowrap;
        transition: all 0.2s ease;
    }

    .category-pill:hover {
        background: #f8fafc;
        color: #6d28d9;
    }

    .category-pill.active {
        background: #5b21b6;
        color: #ffffff !important;
        box-shadow: 0 4px 12px rgba(91, 33, 182, 0.28);
    }

    .category-pill .badge-count {
        font-size: 0.72rem;
        padding: 1px 7px;
        border-radius: 999px;
        background: rgba(0, 0, 0, 0.06);
        color: inherit;
    }

    .category-pill.active .badge-count {
        background: rgba(255, 255, 255, 0.25);
        color: #ffffff;
    }

    /* Controls Bar: Search & Sorting */
    .feed-controls-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
    }

    .search-post-box {
        flex: 1;
        max-width: 480px;
        position: relative;
    }

    .search-post-input {
        width: 100%;
        padding: 10px 40px 10px 18px;
        border-radius: 999px;
        border: 1px solid #cbd5e1;
        background: #ffffff;
        font-family: inherit;
        font-size: 0.86rem;
        color: #1e293b;
        outline: none;
        transition: border 0.2s ease;
    }

    .search-post-input:focus {
        border-color: #7c3aed;
        box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
    }

    .search-post-icon {
        position: absolute;
        right: 16px;
        top: 50%;
        transform: translateY(-50%);
        color: #64748b;
        font-size: 0.9rem;
    }

    .sort-control-group {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 0.82rem;
        color: #475569;
        font-weight: 600;
    }

    .sort-select {
        padding: 8px 16px;
        border-radius: 999px;
        border: 1px solid #cbd5e1;
        background: #ffffff;
        font-family: inherit;
        font-size: 0.82rem;
        color: #334155;
        font-weight: 600;
        outline: none;
        cursor: pointer;
    }

    /* Feed List */
    .feed-list {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    .post-card {
        background: #ffffff;
        border-radius: 20px;
        padding: 24px 28px;
        border: 1px solid rgba(226, 232, 240, 0.85);
        box-shadow: 0 4px 18px rgba(0, 0, 0, 0.03);
        transition: box-shadow 0.2s ease;
    }

    .post-card:hover {
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.06);
    }

    .post-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 14px;
        gap: 12px;
    }

    .author-meta {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .author-avatar {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        background: #ede9fe;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        color: #6d28d9;
        font-size: 1.05rem;
        flex-shrink: 0;
        border: 2px solid #ffffff;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }

    .author-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .author-info-block {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .author-name-row {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    .author-name {
        font-size: 0.95rem;
        font-weight: 800;
        color: #1e1b4b;
        text-decoration: none;
        transition: color 0.15s ease;
    }

    .author-name:hover {
        color: #6d28d9;
    }

    .author-sub {
        font-size: 0.74rem;
        color: #64748b;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .author-dept-pill {
        background: #f1f5f9;
        color: #475569;
        font-size: 0.68rem;
        font-weight: 700;
        padding: 1px 7px;
        border-radius: 999px;
    }

    .post-header-right {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .post-type-badge {
        padding: 4px 12px;
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    .badge-showcase { background: #ede9fe; color: #6d28d9; }
    .badge-experience { background: #dcfce7; color: #15803d; }
    .badge-question { background: #fef3c7; color: #b45309; }
    .badge-general { background: #e0f2fe; color: #0369a1; }

    .btn-delete-post {
        background: none;
        border: none;
        color: #94a3b8;
        cursor: pointer;
        font-size: 0.85rem;
        padding: 6px;
        border-radius: 50%;
        transition: all 0.15s ease;
    }

    .btn-delete-post:hover {
        color: #ef4444;
        background: #fee2e2;
    }

    .post-title {
        font-size: 1.15rem;
        font-weight: 800;
        color: #1e1b4b;
        margin-bottom: 8px;
        line-height: 1.35;
    }

    .post-content {
        font-size: 0.88rem;
        color: #334155;
        line-height: 1.6;
        white-space: pre-wrap;
        margin-bottom: 14px;
        word-break: break-word;
    }

    .post-attachment-container {
        margin-bottom: 14px;
    }

    .post-attachment-img {
        max-width: 100%;
        max-height: 420px;
        border-radius: 14px;
        object-fit: cover;
        border: 1px solid #e2e8f0;
        display: block;
        cursor: pointer;
        transition: transform 0.2s ease;
    }

    .post-attachment-img:hover {
        transform: scale(1.01);
    }

    .post-tags-row {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-bottom: 16px;
    }

    .post-tag {
        font-size: 0.72rem;
        padding: 3px 10px;
        border-radius: 999px;
        background: #f1f5f9;
        color: #475569;
        font-weight: 600;
        text-decoration: none;
    }

    .post-tag:hover {
        background: #ede9fe;
        color: #6d28d9;
    }

    /* Post Footer Actions */
    .post-footer-actions {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding-top: 14px;
        border-top: 1px solid #f1f5f9;
        gap: 12px;
        flex-wrap: wrap;
    }

    .actions-left {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .action-btn {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        font-family: inherit;
        font-size: 0.82rem;
        font-weight: 600;
        color: #475569;
        cursor: pointer;
        padding: 6px 14px;
        border-radius: 999px;
        transition: all 0.15s ease;
    }

    .action-btn:hover {
        background: #f1f5f9;
        color: #5b21b6;
        border-color: #cbd5e1;
    }

    .action-btn.liked {
        color: #ef4444;
        background: #fef2f2;
        border-color: #fecaca;
        font-weight: 700;
    }

    .action-btn.liked i {
        animation: heartPop 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }

    @keyframes heartPop {
        0% { transform: scale(1); }
        50% { transform: scale(1.3); }
        100% { transform: scale(1); }
    }

    .portfolio-link-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 0.8rem;
        font-weight: 700;
        color: #6d28d9;
        text-decoration: none;
        transition: color 0.15s ease;
    }

    .portfolio-link-btn:hover {
        color: #4c1d95;
        text-decoration: underline;
    }

    /* Comment Thread Drawer */
    .comments-thread {
        margin-top: 16px;
        padding-top: 16px;
        border-top: 1px solid #f1f5f9;
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .comment-item {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        font-size: 0.82rem;
    }

    .comment-avatar {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: #ede9fe;
        color: #6d28d9;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        font-size: 0.75rem;
        overflow: hidden;
    }

    .comment-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .comment-bubble {
        background: #f8fafc;
        border-radius: 14px;
        padding: 9px 15px;
        flex: 1;
        border: 1px solid #e2e8f0;
    }

    .comment-header-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 2px;
    }

    .comment-author-name {
        font-weight: 700;
        color: #1e1b4b;
        font-size: 0.8rem;
    }

    .comment-time {
        font-size: 0.68rem;
        color: #94a3b8;
    }

    .comment-text {
        color: #334155;
        line-height: 1.4;
    }

    .comment-form-row {
        display: flex;
        gap: 8px;
        margin-top: 8px;
    }

    .comment-input {
        flex: 1;
        padding: 8px 16px;
        border-radius: 999px;
        border: 1px solid #cbd5e1;
        font-family: inherit;
        font-size: 0.82rem;
        outline: none;
    }

    .comment-input:focus {
        border-color: #7c3aed;
    }

    .btn-comment-submit {
        padding: 8px 18px;
        border-radius: 999px;
        background: #5b21b6;
        color: #ffffff;
        border: none;
        font-size: 0.8rem;
        font-weight: 700;
        cursor: pointer;
        transition: background 0.15s ease;
    }

    .btn-comment-submit:hover {
        background: #43185b;
    }

    /* Category Empty State */
    .empty-category-card {
        background: #ffffff;
        border-radius: 20px;
        padding: 50px 30px;
        text-align: center;
        border: 1px solid rgba(226, 232, 240, 0.85);
        box-shadow: 0 4px 18px rgba(0, 0, 0, 0.025);
    }

    .empty-icon-circle {
        width: 72px;
        height: 72px;
        border-radius: 50%;
        background: #f5f3ff;
        color: #7c3aed;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
        margin: 0 auto 16px;
    }

    .empty-title {
        font-size: 1.15rem;
        font-weight: 800;
        color: #1e1b4b;
        margin-bottom: 6px;
    }

    .empty-desc {
        font-size: 0.88rem;
        color: #64748b;
        max-width: 460px;
        margin: 0 auto 20px;
        line-height: 1.5;
    }

    /* Modal Post Composer */
    .modal-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(4px);
        z-index: 2000;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .modal-backdrop.show {
        display: flex;
    }

    .modal-dialog {
        background: #ffffff;
        border-radius: 24px;
        width: 100%;
        max-width: 650px;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 20px 48px rgba(0, 0, 0, 0.2);
        border: 1px solid rgba(226, 232, 240, 0.8);
        animation: modalScaleUp 0.22s cubic-bezier(0.16, 1, 0.3, 1);
    }

    @keyframes modalScaleUp {
        from { transform: scale(0.94); opacity: 0; }
        to { transform: scale(1); opacity: 1; }
    }

    .modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 20px 24px;
        border-bottom: 1px solid #f1f5f9;
    }

    .modal-header-title {
        font-size: 1.15rem;
        font-weight: 800;
        color: #1e1b4b;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .modal-close-btn {
        background: none;
        border: none;
        font-size: 1.2rem;
        color: #94a3b8;
        cursor: pointer;
        padding: 6px;
        border-radius: 50%;
        transition: all 0.15s ease;
    }

    .modal-close-btn:hover {
        background: #f1f5f9;
        color: #1e293b;
    }

    .modal-body {
        padding: 24px;
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    .form-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .form-label {
        font-size: 0.82rem;
        font-weight: 700;
        color: #334155;
    }

    .form-control {
        width: 100%;
        padding: 10px 14px;
        border-radius: 12px;
        border: 1px solid #cbd5e1;
        font-family: inherit;
        font-size: 0.88rem;
        color: #1e293b;
        outline: none;
        transition: border 0.15s ease;
    }

    .form-control:focus {
        border-color: #7c3aed;
        box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.12);
    }

    textarea.form-control {
        min-height: 120px;
        resize: vertical;
        line-height: 1.5;
    }

    .img-preview-box {
        position: relative;
        margin-top: 8px;
        max-width: 220px;
        border-radius: 12px;
        overflow: hidden;
        border: 1px solid #e2e8f0;
        display: none;
    }

    .img-preview-box img {
        width: 100%;
        height: auto;
        display: block;
    }

    .btn-remove-preview {
        position: absolute;
        top: 6px;
        right: 6px;
        background: rgba(0, 0, 0, 0.65);
        color: #ffffff;
        border: none;
        border-radius: 50%;
        width: 26px;
        height: 26px;
        font-size: 0.75rem;
        cursor: pointer;
    }

    .modal-footer {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 12px;
        padding: 16px 24px;
        border-top: 1px solid #f1f5f9;
        background: #faf8fd;
        border-bottom-left-radius: 24px;
        border-bottom-right-radius: 24px;
    }

    .btn-cancel {
        padding: 9px 20px;
        border-radius: 999px;
        border: 1px solid #cbd5e1;
        background: #ffffff;
        color: #475569;
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
    }

    .btn-publish {
        padding: 9px 24px;
        border-radius: 999px;
        background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%);
        color: #ffffff;
        font-size: 0.85rem;
        font-weight: 700;
        border: none;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .alert-banner {
        padding: 12px 18px;
        border-radius: 14px;
        font-size: 0.86rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .alert-success { background: #dcfce7; color: #14532d; border: 1px solid #bbf7d0; }
    .alert-danger  { background: #fee2e2; color: #7f1d1d; border: 1px solid #fecaca; }

    @media (max-width: 900px) {
        .community-wrapper { padding: 0 16px 24px; }
        .community-header { flex-direction: column; align-items: stretch; }
        .feed-controls-bar { flex-direction: column; align-items: stretch; }
        .search-post-box { max-width: 100%; }
    }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="community-wrapper">

    <!-- Community Header Banner -->
    <div class="community-header">
        <div class="community-header-left">
            <div class="community-title-row">
                <h1 class="community-title">MIT-ADT Community</h1>
                <span class="community-badge">Campus Hub</span>
            </div>
            <p class="community-sub">Share your work, exchange ideas and learn from the university community.</p>
        </div>
        <button type="button" class="btn-create-post" id="openComposerBtn">
            <i class="fa-solid fa-plus"></i>
            <span>Create Post</span>
        </button>
    </div>

    <!-- Alert Notifications -->
    <?php if (!empty($message)): ?>
        <div class="alert-banner alert-<?php echo $messageType; ?>">
            <i class="fa-solid <?php echo $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
            <span><?php echo h($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- Category Navigation -->
    <nav class="category-nav-bar" aria-label="Community Categories">
        <?php foreach ($validCategories as $cKey => $cData): ?>
            <?php
            $isActive = ($filterType === $cKey);
            $queryUrl = "?type=" . urlencode($cKey);
            if (!empty($searchQuery)) $queryUrl .= "&q=" . urlencode($searchQuery);
            if ($sortBy !== 'latest') $queryUrl .= "&sort=" . urlencode($sortBy);
            ?>
            <a href="<?php echo h($queryUrl); ?>" class="category-pill <?php echo $isActive ? 'active' : ''; ?>">
                <i class="fa-solid <?php echo $cData['icon']; ?>"></i>
                <span><?php echo h($cData['label']); ?></span>
                <span class="badge-count"><?php echo $categoryCounts[$cKey] ?? 0; ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <!-- Feed Controls: Search & Sort -->
    <div class="feed-controls-bar">
        <form action="community.php" method="GET" class="search-post-box">
            <input type="hidden" name="type" value="<?php echo h($filterType); ?>">
            <input type="hidden" name="sort" value="<?php echo h($sortBy); ?>">
            <input type="text" name="q" class="search-post-input" value="<?php echo h($searchQuery); ?>" placeholder="Search posts, tags, or authors...">
            <i class="fa-solid fa-magnifying-glass search-post-icon"></i>
        </form>

        <div class="sort-control-group">
            <span>Sort by:</span>
            <select class="sort-select" onchange="location.href='community.php?type=<?php echo urlencode($filterType); ?>&q=<?php echo urlencode($searchQuery); ?>&sort=' + this.value;">
                <option value="latest" <?php echo $sortBy === 'latest' ? 'selected' : ''; ?>>Latest Activity</option>
                <option value="popular" <?php echo $sortBy === 'popular' ? 'selected' : ''; ?>>Most Appreciated</option>
            </select>
            <?php if (!empty($searchQuery)): ?>
                <a href="community.php?type=<?php echo urlencode($filterType); ?>" style="color:#ef4444; font-size:0.8rem; text-decoration:none; margin-left:4px;">Clear Search</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Community Feed Posts -->
    <div class="feed-list">
        <?php if (!empty($posts)): ?>
            <?php foreach ($posts as $post): ?>
                <?php
                $pType = $post['post_type'] ?? 'general';
                $badgeInfo = $validCategories[$pType] ?? $validCategories['general'];
                $isAuthor = ((int)$post['author_id'] === $student_id);
                $hasLiked = ((int)$post['user_has_liked'] > 0);
                ?>
                <article class="post-card" id="post-<?php echo $post['id']; ?>">
                    <div class="post-header">
                        <div class="author-meta">
                            <div class="author-avatar">
                                <?php if (!empty($post['author_photo']) && file_exists(__DIR__ . '/../uploads/profile_photos/' . $post['author_photo'])): ?>
                                    <img src="../uploads/profile_photos/<?php echo rawurlencode($post['author_photo']); ?>" alt="Author Avatar">
                                <?php else: ?>
                                    <span><?php echo strtoupper(substr($post['author_name'] ?? 'U', 0, 1)); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="author-info-block">
                                <div class="author-name-row">
                                    <a href="../portfolio.php?id=<?php echo $post['author_id']; ?>" target="_blank" class="author-name">
                                        <?php echo h($post['author_name']); ?>
                                    </a>
                                    <?php if (!empty($post['author_dept'])): ?>
                                        <span class="author-dept-pill"><?php echo h($post['author_dept']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($post['author_division'])): ?>
                                        <span class="author-dept-pill"><?php echo h($post['author_division']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="author-sub">
                                    <i class="fa-regular fa-clock" style="font-size:0.7rem;"></i>
                                    <span><?php echo format_time_ago($post['created_at']); ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="post-header-right">
                            <span class="post-type-badge <?php echo $badgeInfo['badge']; ?>">
                                <i class="fa-solid <?php echo $badgeInfo['icon']; ?>"></i>
                                <span><?php echo h($badgeInfo['label']); ?></span>
                            </span>
                            <?php if ($isAuthor): ?>
                                <form action="community.php?type=<?php echo urlencode($filterType); ?>" method="POST" onsubmit="return confirm('Are you sure you want to delete this post?');" style="margin:0;">
                                    <input type="hidden" name="action" value="delete_post">
                                    <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                                    <button type="submit" class="btn-delete-post" title="Delete post">
                                        <i class="fa-regular fa-trash-can"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Post Body -->
                    <h2 class="post-title"><?php echo h($post['title']); ?></h2>
                    <div class="post-content"><?php echo h($post['content']); ?></div>

                    <!-- Post Attachment Image -->
                    <?php if (!empty($post['attachment_file']) && file_exists(__DIR__ . '/../uploads/community/' . $post['attachment_file'])): ?>
                        <div class="post-attachment-container">
                            <img src="../uploads/community/<?php echo rawurlencode($post['attachment_file']); ?>" alt="Attachment" class="post-attachment-img" onclick="window.open(this.src, '_blank')">
                        </div>
                    <?php endif; ?>

                    <!-- Tags -->
                    <?php if (!empty($post['tags'])): ?>
                        <div class="post-tags-row">
                            <?php foreach (explode(',', $post['tags']) as $tag): ?>
                                <?php $cleanTag = trim($tag); if (!empty($cleanTag)): ?>
                                    <a href="community.php?type=all&q=<?php echo urlencode($cleanTag); ?>" class="post-tag">#<?php echo h($cleanTag); ?></a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Post Footer Actions -->
                    <div class="post-footer-actions">
                        <div class="actions-left">
                            <button type="button" class="action-btn react-btn <?php echo $hasLiked ? 'liked' : ''; ?>" data-post-id="<?php echo $post['id']; ?>">
                                <i class="fa-<?php echo $hasLiked ? 'solid' : 'regular'; ?> fa-heart"></i>
                                <span class="likes-count"><?php echo (int)$post['likes_count']; ?></span>
                            </button>
                            <button type="button" class="action-btn toggle-comments-btn" data-target="comments-<?php echo $post['id']; ?>">
                                <i class="fa-regular fa-comment"></i>
                                <span><?php echo (int)$post['comments_count']; ?> Comments</span>
                            </button>
                        </div>
                        <a href="../portfolio.php?id=<?php echo $post['author_id']; ?>" target="_blank" class="portfolio-link-btn">
                            <span>View Portfolio</span>
                            <i class="fa-solid fa-arrow-right"></i>
                        </a>
                    </div>

                    <!-- Comment Thread Drawer -->
                    <div class="comments-thread" id="comments-<?php echo $post['id']; ?>" style="display:none;">
                        <?php if (!empty($post['comments'])): ?>
                            <?php foreach ($post['comments'] as $c): ?>
                                <div class="comment-item">
                                    <div class="comment-avatar">
                                        <?php if (!empty($c['commenter_photo']) && file_exists(__DIR__ . '/../uploads/profile_photos/' . $c['commenter_photo'])): ?>
                                            <img src="../uploads/profile_photos/<?php echo rawurlencode($c['commenter_photo']); ?>" alt="User Avatar">
                                        <?php else: ?>
                                            <span><?php echo strtoupper(substr($c['commenter_name'] ?? 'U', 0, 1)); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="comment-bubble">
                                        <div class="comment-header-row">
                                            <span class="comment-author-name"><?php echo h($c['commenter_name']); ?></span>
                                            <span class="comment-time"><?php echo format_time_ago($c['created_at']); ?></span>
                                        </div>
                                        <div class="comment-text"><?php echo nl2br(h($c['comment'])); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- Add Comment Box -->
                        <form action="community.php?type=<?php echo urlencode($filterType); ?>" method="POST" class="comment-form-row">
                            <input type="hidden" name="action" value="add_comment">
                            <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                            <input type="text" name="comment" class="comment-input" required placeholder="Write a constructive comment or question...">
                            <button type="submit" class="btn-comment-submit">Post</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>

        <?php else: ?>
            <!-- Category-Specific Empty States -->
            <div class="empty-category-card">
                <?php if ($filterType === 'showcase'): ?>
                    <div class="empty-icon-circle"><i class="fa-solid fa-laptop-code"></i></div>
                    <h3 class="empty-title">No Project Showcases Yet</h3>
                    <p class="empty-desc">No projects shared yet. Showcase your work and let others explore what you have built.</p>
                <?php elseif ($filterType === 'internship_experience'): ?>
                    <div class="empty-icon-circle"><i class="fa-solid fa-briefcase"></i></div>
                    <h3 class="empty-title">No Internship Experiences Yet</h3>
                    <p class="empty-desc">No internship experiences shared yet. Share your learning and practical experience.</p>
                <?php elseif ($filterType === 'question'): ?>
                    <div class="empty-icon-circle"><i class="fa-solid fa-circle-question"></i></div>
                    <h3 class="empty-title">No Technical Questions Yet</h3>
                    <p class="empty-desc">No questions yet. Ask a technical question and learn from your peers.</p>
                <?php elseif ($filterType === 'general'): ?>
                    <div class="empty-icon-circle"><i class="fa-solid fa-comments"></i></div>
                    <h3 class="empty-title">No Discussions Yet</h3>
                    <p class="empty-desc">No discussions yet. Start a conversation with the university community.</p>
                <?php else: ?>
                    <div class="empty-icon-circle"><i class="fa-solid fa-layer-group"></i></div>
                    <h3 class="empty-title">No Posts Yet</h3>
                    <p class="empty-desc">No posts yet. Be the first to share something with the MIT-ADT community.</p>
                <?php endif; ?>

                <button type="button" class="btn-create-post" onclick="document.getElementById('openComposerBtn').click();">
                    <i class="fa-solid fa-feather-pointed"></i>
                    <span>Share the First Post</span>
                </button>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- Modal Post Composer -->
<div class="modal-backdrop" id="composerModal">
    <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="composerModalTitle">
        <div class="modal-header">
            <span class="modal-header-title" id="composerModalTitle">
                <i class="fa-solid fa-feather-pointed" style="color:#7c3aed;"></i>
                <span>Share with MIT-ADT Community</span>
            </span>
            <button type="button" class="modal-close-btn" id="closeComposerBtn" aria-label="Close modal">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form action="community.php?type=<?php echo urlencode($filterType); ?>" method="POST" enctype="multipart/form-data" id="communityComposerForm">
            <input type="hidden" name="action" value="create_post">
            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">

            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="composerPostType">Category *</label>
                    <select name="post_type" id="composerPostType" class="form-control" required>
                        <option value="showcase" <?php echo $composer_type === 'showcase' ? 'selected' : ''; ?>>Project Showcase</option>
                        <option value="internship_experience" <?php echo $composer_type === 'internship_experience' ? 'selected' : ''; ?>>Internship Experience</option>
                        <option value="question" <?php echo $composer_type === 'question' ? 'selected' : ''; ?>>Technical Q&A</option>
                        <option value="general" <?php echo $composer_type === 'general' ? 'selected' : ''; ?>>General Discussion</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="composerTitle">Post Title *</label>
                    <input type="text" name="title" id="composerTitle" class="form-control" required value="<?php echo h($composer_title); ?>" placeholder="e.g. Built an automated attendance system using OpenCV!">
                </div>

                <div class="form-group">
                    <label class="form-label" for="composerContent">Content *</label>
                    <textarea name="content" id="composerContent" class="form-control" required placeholder="Share your experience, technical challenges, project architecture, or ask peers questions..."><?php echo h($composer_content); ?></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Attachment Image (Optional)</label>
                    <input type="file" name="attachment" id="composerAttachment" class="form-control" accept=".jpg,.jpeg,.png,.webp">
                    <span style="font-size:0.72rem; color:#64748b; margin-top:2px;">Supported: JPG, PNG, WEBP (Max 5MB)</span>
                    <div class="img-preview-box" id="composerPreviewBox">
                        <img src="" id="composerPreviewImg" alt="Preview">
                        <button type="button" class="btn-remove-preview" id="btnRemoveAttachment" title="Remove">&times;</button>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="composerTags">Tags (comma separated)</label>
                    <input type="text" name="tags" id="composerTags" class="form-control" value="<?php echo h($composer_tags); ?>" placeholder="e.g. python, react, aws, machine-learning">
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-cancel" id="btnCancelComposer">Cancel</button>
                <button type="submit" class="btn-publish" id="btnPublishPost">
                    <i class="fa-solid fa-paper-plane"></i>
                    <span>Publish Post</span>
                </button>
            </div>
        </form>
    </div>
</div>

</main>
</div>

<?php require_once __DIR__ . '/includes/sidebar_scripts.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // 1. Modal Composer Controls
    var openBtn = document.getElementById('openComposerBtn');
    var closeBtn = document.getElementById('closeComposerBtn');
    var cancelBtn = document.getElementById('btnCancelComposer');
    var modal = document.getElementById('composerModal');
    var composerForm = document.getElementById('communityComposerForm');
    var fileInput = document.getElementById('composerAttachment');
    var previewBox = document.getElementById('composerPreviewBox');
    var previewImg = document.getElementById('composerPreviewImg');
    var removePreviewBtn = document.getElementById('btnRemoveAttachment');
    var categorySelect = document.getElementById('composerPostType');

    function openModal() {
        if (modal) modal.classList.add('show');
    }

    function closeModal() {
        if (modal) modal.classList.remove('show');
    }

    if (openBtn) openBtn.addEventListener('click', openModal);
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal();
        });
    }

    // Image file preview
    if (fileInput && previewBox && previewImg) {
        fileInput.addEventListener('change', function () {
            if (this.files && this.files[0]) {
                var file = this.files[0];
                if (file.size > 5 * 1024 * 1024) {
                    alert('File exceeds 5MB size limit.');
                    this.value = '';
                    previewBox.style.display = 'none';
                    return;
                }
                var reader = new FileReader();
                reader.onload = function (e) {
                    previewImg.src = e.target.result;
                    previewBox.style.display = 'block';
                };
                reader.readAsDataURL(file);
            }
        });

        if (removePreviewBtn) {
            removePreviewBtn.addEventListener('click', function () {
                fileInput.value = '';
                previewBox.style.display = 'none';
                previewImg.src = '';
            });
        }
    }

    // Prevent duplicate form submit
    if (composerForm) {
        composerForm.addEventListener('submit', function () {
            var submitBtn = document.getElementById('btnPublishPost');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Publishing...';
            }
        });
    }

    // 2. Toggle Comment Drawer
    document.querySelectorAll('.toggle-comments-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var targetId = this.getAttribute('data-target');
            var thread = document.getElementById(targetId);
            if (thread) {
                if (thread.style.display === 'none' || thread.style.display === '') {
                    thread.style.display = 'flex';
                } else {
                    thread.style.display = 'none';
                }
            }
        });
    });

    // 3. AJAX Like / Reaction
    document.querySelectorAll('.react-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var postId = this.getAttribute('data-post-id');
            var heartIcon = this.querySelector('i');
            var countSpan = this.querySelector('.likes-count');
            var self = this;

            var formData = new FormData();
            formData.append('action', 'react_post');
            formData.append('post_id', postId);

            fetch('community.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success) {
                    if (data.has_liked) {
                        self.classList.add('liked');
                        heartIcon.className = 'fa-solid fa-heart';
                    } else {
                        self.classList.remove('liked');
                        heartIcon.className = 'fa-regular fa-heart';
                    }
                    if (countSpan) countSpan.textContent = data.likes_count;
                }
            })
            .catch(function (err) {
                console.error('Reaction error:', err);
            });
        });
    });
});
</script>
</body>
</html>
