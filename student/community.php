<?php
/**
 * InternHub — College Community Feed (community.php)
 * Peer learning, project showcases, internship experiences, and Q&A
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
$filterType = $_GET['type'] ?? 'all';

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$message = '';
$messageType = '';

// 1. Handle New Post Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_post') {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $post_type = in_array($_POST['post_type'] ?? '', ['showcase', 'internship_experience', 'question', 'general']) ? $_POST['post_type'] : 'general';
        $tags = trim($_POST['tags'] ?? '');

        if (empty($title) || empty($content)) {
            $message = 'Post title and description are required.';
            $messageType = 'danger';
        } else {
            $attachment_file = null;
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    $cdir = __DIR__ . '/../uploads/community/';
                    if (!is_dir($cdir)) mkdir($cdir, 0755, true);
                    $attachment_file = time() . '_comm_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $_FILES['attachment']['name']);
                    move_uploaded_file($_FILES['attachment']['tmp_name'], $cdir . $attachment_file);
                }
            }

            $inStmt = $conn->prepare("
                INSERT INTO community_posts (author_id, author_role, title, content, attachment_file, tags, post_type, created_at, updated_at)
                VALUES (?, 'student', ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $inStmt->bind_param("isssss", $student_id, $title, $content, $attachment_file, $tags, $post_type);
            if ($inStmt->execute()) {
                $message = 'Your post has been published to the university community!';
                $messageType = 'success';
            } else {
                $message = 'Failed to publish post: ' . $conn->error;
                $messageType = 'danger';
            }
            $inStmt->close();
        }
    }

    // Action: Like / React to Post
    elseif ($action === 'react_post') {
        $postId = (int)($_POST['post_id'] ?? 0);
        if ($postId > 0) {
            $chkR = $conn->prepare("SELECT id FROM post_reactions WHERE post_id = ? AND user_id = ?");
            $chkR->bind_param("ii", $postId, $student_id);
            $chkR->execute();
            $existR = $chkR->get_result()->fetch_assoc();
            $chkR->close();

            if ($existR) {
                // Unlike
                $delR = $conn->prepare("DELETE FROM post_reactions WHERE id = ?");
                $delR->bind_param("i", $existR['id']);
                $delR->execute();
                $delR->close();

                $conn->query("UPDATE community_posts SET likes_count = GREATEST(0, likes_count - 1) WHERE id = {$postId}");
            } else {
                // Like
                $addR = $conn->prepare("INSERT INTO post_reactions (post_id, user_id, reaction_type, created_at) VALUES (?, ?, 'like', NOW())");
                $addR->bind_param("ii", $postId, $student_id);
                $addR->execute();
                $addR->close();

                $conn->query("UPDATE community_posts SET likes_count = likes_count + 1 WHERE id = {$postId}");
            }
        }
    }

    // Action: Comment on Post
    elseif ($action === 'add_comment') {
        $postId = (int)($_POST['post_id'] ?? 0);
        $commentText = trim($_POST['comment'] ?? '');
        if ($postId > 0 && !empty($commentText)) {
            $inC = $conn->prepare("INSERT INTO post_comments (post_id, user_id, comment, created_at) VALUES (?, ?, ?, NOW())");
            $inC->bind_param("iis", $postId, $student_id, $commentText);
            $inC->execute();
            $inC->close();

            $conn->query("UPDATE community_posts SET comments_count = comments_count + 1 WHERE id = {$postId}");
            $message = 'Comment posted.';
            $messageType = 'success';
        }
    }
}

// 2. Fetch Posts Feed
$whereSql = "WHERE cp.is_moderated = 0";
if ($filterType !== 'all') {
    $safeFilter = mysqli_real_escape_string($conn, $filterType);
    $whereSql .= " AND cp.post_type = '{$safeFilter}'";
}

$feedQuery = "
    SELECT cp.*, u.full_name AS author_name, u.division AS author_division, u.department AS author_dept, u.profile_photo AS author_photo,
           (SELECT COUNT(*) FROM post_reactions pr WHERE pr.post_id = cp.id AND pr.user_id = {$student_id}) AS user_has_liked
    FROM community_posts cp
    JOIN users u ON cp.author_id = u.id
    {$whereSql}
    ORDER BY cp.is_pinned DESC, cp.created_at DESC
    LIMIT 30
";
$feedRes = mysqli_query($conn, $feedQuery);
$posts = [];
if ($feedRes) {
    while ($p = mysqli_fetch_assoc($feedRes)) {
        // Fetch comments for this post
        $cRes = mysqli_query($conn, "
            SELECT pc.*, u.full_name AS commenter_name, u.profile_photo AS commenter_photo
            FROM post_comments pc
            JOIN users u ON pc.user_id = u.id
            WHERE pc.post_id = {$p['id']}
            ORDER BY pc.created_at ASC
            LIMIT 10
        ");
        $p['comments'] = [];
        if ($cRes) {
            while ($c = mysqli_fetch_assoc($cRes)) {
                $p['comments'][] = $c;
            }
        }
        $posts[] = $p;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Community Feed — InternHub | MIT-ADT University</title>
    <?php require_once __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
    .community-wrapper {
        padding: 0 32px 32px;
        display: flex;
        flex-direction: column;
        gap: 22px;
    }

    /* Filter Bar */
    .filter-bar {
        display: flex;
        align-items: center;
        gap: 8px;
        background: #ffffff;
        padding: 6px;
        border-radius: 999px;
        border: 1px solid rgba(226, 232, 240, 0.8);
        box-shadow: 0 2px 10px rgba(0,0,0,0.03);
        overflow-x: auto;
    }

    .filter-pill {
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
    .filter-pill:hover { background: #f8fafc; color: #6d28d9; }
    .filter-pill.active { background: #5b21b6; color: #ffffff !important; box-shadow: 0 4px 12px rgba(91, 33, 182, 0.3); }

    /* Post Creation Box */
    .create-post-card {
        background: #ffffff;
        border-radius: 20px;
        padding: 24px 28px;
        border: 1px solid rgba(226, 232, 240, 0.8);
        box-shadow: 0 4px 18px rgba(0,0,0,0.035);
    }

    .create-post-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 16px;
    }

    .create-post-title {
        font-size: 1.1rem;
        font-weight: 800;
        color: #1e1b4b;
        display: flex;
        align-items: center;
        gap: 8px;
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
        border: 1px solid rgba(226, 232, 240, 0.8);
        box-shadow: 0 4px 18px rgba(0,0,0,0.03);
    }

    .post-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 14px;
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
    }
    .author-avatar img { width: 100%; height: 100%; object-fit: cover; }

    .author-name {
        font-size: 0.95rem;
        font-weight: 800;
        color: #1e1b4b;
        text-decoration: none;
    }
    .author-name:hover { color: #5b21b6; }

    .author-sub {
        font-size: 0.74rem;
        color: #64748b;
    }

    .post-type-badge {
        padding: 4px 12px;
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 700;
    }
    .badge-showcase { background: #ede9fe; color: #6d28d9; }
    .badge-experience { background: #dcfce7; color: #15803d; }
    .badge-question { background: #fef3c7; color: #b45309; }
    .badge-general { background: #e0f2fe; color: #0369a1; }

    .post-title {
        font-size: 1.15rem;
        font-weight: 800;
        color: #1e1b4b;
        margin-bottom: 8px;
    }

    .post-content {
        font-size: 0.88rem;
        color: #334155;
        line-height: 1.55;
        white-space: pre-wrap;
        margin-bottom: 14px;
    }

    .post-attachment {
        max-width: 100%;
        max-height: 380px;
        border-radius: 14px;
        object-fit: cover;
        margin-bottom: 14px;
        border: 1px solid #e2e8f0;
    }

    .post-tags-row {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-bottom: 16px;
    }
    .post-tag {
        font-size: 0.72rem;
        padding: 2px 8px;
        border-radius: 999px;
        background: #f1f5f9;
        color: #64748b;
        font-weight: 600;
    }

    .post-footer-actions {
        display: flex;
        align-items: center;
        gap: 16px;
        padding-top: 14px;
        border-top: 1px solid #f1f5f9;
    }

    .action-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: none;
        border: none;
        font-family: inherit;
        font-size: 0.84rem;
        font-weight: 600;
        color: #64748b;
        cursor: pointer;
        padding: 6px 12px;
        border-radius: 999px;
        transition: all 0.15s ease;
    }
    .action-btn:hover { background: #f8fafc; color: #5b21b6; }
    .action-btn.liked { color: #ef4444; font-weight: 700; }

    /* Comment Thread */
    .comments-thread {
        margin-top: 16px;
        padding-top: 14px;
        border-top: 1px solid #f1f5f9;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .comment-item {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        font-size: 0.82rem;
    }

    .comment-avatar {
        width: 30px;
        height: 30px;
        border-radius: 50%;
        background: #ede9fe;
        color: #6d28d9;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        font-size: 0.75rem;
    }

    .comment-bubble {
        background: #f8fafc;
        border-radius: 12px;
        padding: 8px 14px;
        flex: 1;
        border: 1px solid #e2e8f0;
    }

    .comment-author-name {
        font-weight: 700;
        color: #1e1b4b;
        margin-bottom: 2px;
    }

    .comment-text {
        color: #334155;
    }

    .comment-input-row {
        display: flex;
        gap: 8px;
        margin-top: 8px;
    }

    .comment-input {
        flex: 1;
        padding: 8px 14px;
        border-radius: 999px;
        border: 1px solid #cbd5e1;
        font-family: inherit;
        font-size: 0.82rem;
        outline: none;
    }
    .comment-input:focus { border-color: #7c3aed; }

    .btn-comment-submit {
        padding: 8px 16px;
        border-radius: 999px;
        background: #5b21b6;
        color: #ffffff;
        border: none;
        font-size: 0.8rem;
        font-weight: 700;
        cursor: pointer;
    }

    /* Forms */
    .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
    .form-group { display: flex; flex-direction: column; gap: 6px; }
    .form-group.full-width { grid-column: span 2; }
    .form-label { font-size: 0.82rem; font-weight: 700; color: #334155; }
    .form-control {
        width: 100%; padding: 10px 14px; border-radius: 12px; border: 1px solid #cbd5e1;
        font-family: inherit; font-size: 0.88rem; color: #1e293b; background: #ffffff; outline: none;
    }
    .form-control:focus { border-color: #7c3aed; box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.12); }
    textarea.form-control { min-height: 90px; resize: vertical; }

    .btn-submit {
        display: inline-flex; align-items: center; gap: 8px; padding: 10px 24px; border-radius: 999px;
        background: linear-gradient(135deg, #7c3aed, #5b21b6); color: #ffffff; font-weight: 700; font-size: 0.88rem;
        border: none; cursor: pointer; transition: all 0.2s ease;
    }
    .btn-submit:hover { transform: translateY(-2px); }

    .alert-banner {
        padding: 12px 18px; border-radius: 14px; font-size: 0.86rem; font-weight: 600;
        display: flex; align-items: center; gap: 10px; margin-bottom: 20px;
    }
    .alert-success { background: #dcfce7; color: #14532d; border: 1px solid #bbf7d0; }
    .alert-danger  { background: #fee2e2; color: #7f1d1d; border: 1px solid #fecaca; }

    @media (max-width: 900px) {
        .community-wrapper { padding: 0 16px 24px; }
        .form-grid { grid-template-columns: 1fr; }
        .form-group.full-width { grid-column: span 1; }
    }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="community-wrapper">

    <!-- Community Type Filters -->
    <nav class="filter-bar">
        <a href="?type=all" class="filter-pill <?php echo $filterType === 'all' ? 'active' : ''; ?>">
            <i class="fa-solid fa-layer-group"></i>
            <span>All Posts</span>
        </a>
        <a href="?type=showcase" class="filter-pill <?php echo $filterType === 'showcase' ? 'active' : ''; ?>">
            <i class="fa-solid fa-laptop-code"></i>
            <span>Project Showcases</span>
        </a>
        <a href="?type=internship_experience" class="filter-pill <?php echo $filterType === 'internship_experience' ? 'active' : ''; ?>">
            <i class="fa-solid fa-briefcase"></i>
            <span>Internship Experiences</span>
        </a>
        <a href="?type=question" class="filter-pill <?php echo $filterType === 'question' ? 'active' : ''; ?>">
            <i class="fa-solid fa-circle-question"></i>
            <span>Technical Q&A</span>
        </a>
        <a href="?type=general" class="filter-pill <?php echo $filterType === 'general' ? 'active' : ''; ?>">
            <i class="fa-solid fa-comments"></i>
            <span>General Discussions</span>
        </a>
    </nav>

    <?php if (!empty($message)): ?>
        <div class="alert-banner alert-<?php echo $messageType; ?>">
            <i class="fa-solid <?php echo $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
            <span><?php echo h($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- Create Post Card -->
    <div class="create-post-card">
        <div class="create-post-header">
            <span class="create-post-title">
                <i class="fa-solid fa-feather-pointed" style="color:#7c3aed;"></i>
                <span>Share with MIT-ADT Community</span>
            </span>
        </div>

        <form action="community.php?type=<?php echo urlencode($filterType); ?>" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="create_post">

            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label">Post Title *</label>
                    <input type="text" name="title" class="form-control" required placeholder="e.g. Built an automated attendance system using OpenCV!">
                </div>

                <div class="form-group">
                    <label class="form-label">Category *</label>
                    <select name="post_type" class="form-control">
                        <option value="showcase">Project Showcase</option>
                        <option value="internship_experience">Internship Experience</option>
                        <option value="question">Technical Question</option>
                        <option value="general" selected>General Discussion</option>
                    </select>
                </div>

                <div class="form-group full-width">
                    <label class="form-label">Content *</label>
                    <textarea name="content" class="form-control" required placeholder="Share your experience, technical challenges, project architecture, or ask peers questions..."></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Attachment Image (Optional)</label>
                    <input type="file" name="attachment" class="form-control" accept=".jpg,.jpeg,.png,.webp">
                </div>

                <div class="form-group">
                    <label class="form-label">Tags (comma separated)</label>
                    <input type="text" name="tags" class="form-control" placeholder="e.g. python, react, aws, machine-learning">
                </div>
            </div>

            <div style="margin-top:16px;">
                <button type="submit" class="btn-submit">
                    <i class="fa-solid fa-paper-plane"></i>
                    <span>Publish Post</span>
                </button>
            </div>
        </form>
    </div>

    <!-- Community Feed -->
    <div class="feed-list">
        <?php if (!empty($posts)): ?>
            <?php foreach ($posts as $post): ?>
                <?php
                $badgeClass = 'badge-' . ($post['post_type'] ?? 'general');
                $typeLabels = [
                    'showcase' => 'Project Showcase',
                    'internship_experience' => 'Internship Experience',
                    'question' => 'Technical Q&A',
                    'general' => 'Discussion'
                ];
                $typeLabel = $typeLabels[$post['post_type']] ?? 'Discussion';
                ?>
                <article class="post-card">
                    <div class="post-header">
                        <div class="author-meta">
                            <div class="author-avatar">
                                <?php if (!empty($post['author_photo']) && file_exists(__DIR__ . '/../uploads/profile_photos/' . $post['author_photo'])): ?>
                                    <img src="../uploads/profile_photos/<?php echo rawurlencode($post['author_photo']); ?>" alt="Author">
                                <?php else: ?>
                                    <span><?php echo strtoupper(substr($post['author_name'], 0, 1)); ?></span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <a href="../portfolio.php?id=<?php echo $post['author_id']; ?>" target="_blank" class="author-name">
                                    <?php echo h($post['author_name']); ?>
                                </a>
                                <div class="author-sub">
                                    <?php echo h($post['author_dept']); ?> • <?php echo h($post['author_division']); ?> • <?php echo date('d M Y, h:i A', strtotime($post['created_at'])); ?>
                                </div>
                            </div>
                        </div>

                        <span class="post-type-badge <?php echo $badgeClass; ?>">
                            <?php echo $typeLabel; ?>
                        </span>
                    </div>

                    <h3 class="post-title"><?php echo h($post['title']); ?></h3>
                    <div class="post-content"><?php echo nl2br(h($post['content'])); ?></div>

                    <?php if (!empty($post['attachment_file']) && file_exists(__DIR__ . '/../uploads/community/' . $post['attachment_file'])): ?>
                        <img src="../uploads/community/<?php echo rawurlencode($post['attachment_file']); ?>" alt="Attachment" class="post-attachment">
                    <?php endif; ?>

                    <?php if (!empty($post['tags'])): ?>
                        <div class="post-tags-row">
                            <?php foreach (explode(',', $post['tags']) as $tag): ?>
                                <span class="post-tag">#<?php echo h(trim($tag)); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="post-footer-actions">
                        <!-- Like Form -->
                        <form action="community.php?type=<?php echo urlencode($filterType); ?>" method="POST" style="margin:0;">
                            <input type="hidden" name="action" value="react_post">
                            <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                            <button type="submit" class="action-btn <?php echo $post['user_has_liked'] ? 'liked' : ''; ?>">
                                <i class="fa-<?php echo $post['user_has_liked'] ? 'solid' : 'regular'; ?> fa-heart"></i>
                                <span><?php echo (int)$post['likes_count']; ?> Likes</span>
                            </button>
                        </form>

                        <button type="button" class="action-btn" onclick="document.getElementById('comments_<?php echo $post['id']; ?>').style.display = (document.getElementById('comments_<?php echo $post['id']; ?>').style.display === 'none' ? 'flex' : 'none');">
                            <i class="fa-regular fa-comment"></i>
                            <span><?php echo count($post['comments']); ?> Comments</span>
                        </button>

                        <a href="../portfolio.php?id=<?php echo $post['author_id']; ?>" target="_blank" class="action-btn" style="margin-left:auto;">
                            <i class="fa-solid fa-address-card"></i>
                            <span>View Portfolio</span>
                        </a>
                    </div>

                    <!-- Threaded Comments -->
                    <div class="comments-thread" id="comments_<?php echo $post['id']; ?>" style="display: <?php echo !empty($post['comments']) ? 'flex' : 'none'; ?>;">
                        <?php foreach ($post['comments'] as $comm): ?>
                            <div class="comment-item">
                                <div class="comment-avatar">
                                    <?php echo strtoupper(substr($comm['commenter_name'], 0, 1)); ?>
                                </div>
                                <div class="comment-bubble">
                                    <div class="comment-author-name"><?php echo h($comm['commenter_name']); ?></div>
                                    <div class="comment-text"><?php echo nl2br(h($comm['comment'])); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <!-- Add Comment Input -->
                        <form action="community.php?type=<?php echo urlencode($filterType); ?>" method="POST" class="comment-input-row">
                            <input type="hidden" name="action" value="add_comment">
                            <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                            <input type="text" name="comment" class="comment-input" placeholder="Write a comment or answer..." required>
                            <button type="submit" class="btn-comment-submit">Reply</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php else: ?>
            <div style="background:#ffffff; border-radius:20px; padding:40px; text-align:center; border:1px solid #e2e8f0; color:#64748b;">
                <i class="fa-solid fa-comments" style="font-size:2.6rem; color:#cbd5e1; margin-bottom:12px;"></i>
                <h4 style="font-size:1.1rem; color:#1e1b4b; margin-bottom:4px;">No Posts in this Category</h4>
                <p style="font-size:0.85rem;">Be the first student to publish a project showcase or discussion topic!</p>
            </div>
        <?php endif; ?>
    </div>

</div>

</main>
</div>

<?php require_once __DIR__ . '/includes/sidebar_scripts.php'; ?>
</body>
</html>
