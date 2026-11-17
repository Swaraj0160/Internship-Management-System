<?php
session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$activePage = 'community_moderation.php';
$adminId = (int)($_SESSION['user_id'] ?? 0);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$tab = trim($_GET['tab'] ?? 'posts');
$search = trim($_GET['search'] ?? '');

$successMsg = '';
$errorMsg = '';

// -------------------------------------------------------------
// POST ACTIONS (Create Official Post, Pin Toggle, Delete Post/Comment)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $postedCsrf)) {
        $errorMsg = 'Security validation failed (Invalid CSRF token).';
    } else {
        $action = trim($_POST['action'] ?? '');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        // 1. CREATE OFFICIAL ANNOUNCEMENT POST
        if ($action === 'create_post') {
            $title = trim($_POST['title'] ?? '');
            $content = trim($_POST['content'] ?? '');
            $post_type = in_array($_POST['post_type'] ?? '', ['general', 'showcase', 'internship_experience', 'question']) ? $_POST['post_type'] : 'general';
            $is_pinned = isset($_POST['is_pinned']) ? 1 : 0;
            $tags = trim($_POST['tags'] ?? 'Official, University');

            if ($title === '' || $content === '') {
                $errorMsg = 'Post title and message content are required.';
            } else {
                $ins = $conn->prepare("
                    INSERT INTO community_posts (author_id, author_role, title, content, post_type, tags, is_pinned, is_moderated, created_at)
                    VALUES (?, 'admin', ?, ?, ?, ?, ?, 1, NOW())
                ");
                $ins->bind_param("issssi", $adminId, $title, $content, $post_type, $tags, $is_pinned);
                if ($ins->execute()) {
                    $newPostId = $ins->insert_id;
                    $successMsg = "Official community post <strong>" . h($title) . "</strong> published.";
                    $aud = $conn->prepare("INSERT INTO audit_logs (actor_id, actor_role, action, target_id, target_type, ip_address, user_agent, details) VALUES (?, 'admin', 'create_community_post', ?, 'post', ?, ?, ?)");
                    $dt = json_encode(['title' => $title]);
                    $aud->bind_param("iisss", $adminId, $newPostId, $ip, $ua, $dt);
                    $aud->execute();
                } else {
                    $errorMsg = 'Database error: ' . $conn->error;
                }
            }
        }

        // 2. TOGGLE PIN STATUS
        elseif ($action === 'toggle_pin') {
            $postId = (int)($_POST['post_id'] ?? 0);
            $newPin = (int)($_POST['target_pin'] ?? 0);
            if ($postId > 0) {
                $upd = $conn->prepare("UPDATE community_posts SET is_pinned = ? WHERE id = ?");
                $upd->bind_param("ii", $newPin, $postId);
                if ($upd->execute()) {
                    $successMsg = $newPin ? "Post pinned to top." : "Post unpinned from top.";
                }
            }
        }

        // 3. DELETE POST
        elseif ($action === 'delete_post') {
            $postId = (int)($_POST['post_id'] ?? 0);
            if ($postId > 0) {
                $conn->query("DELETE FROM post_reactions WHERE post_id = $postId");
                $conn->query("DELETE FROM post_comments WHERE post_id = $postId");
                $del = $conn->prepare("DELETE FROM community_posts WHERE id = ?");
                $del->bind_param("i", $postId);
                if ($del->execute()) {
                    $successMsg = "Community post #$postId and associated comments removed.";
                }
            }
        }

        // 4. DELETE COMMENT
        elseif ($action === 'delete_comment') {
            $commId = (int)($_POST['comment_id'] ?? 0);
            $postId = (int)($_POST['post_id'] ?? 0);
            if ($commId > 0) {
                $del = $conn->prepare("DELETE FROM post_comments WHERE id = ?");
                $del->bind_param("i", $commId);
                if ($del->execute()) {
                    if ($postId > 0) {
                        $conn->query("UPDATE community_posts SET comments_count = GREATEST(0, comments_count - 1) WHERE id = $postId");
                    }
                    $successMsg = "Comment #$commId deleted.";
                }
            }
        }
    }
}

// -------------------------------------------------------------
// METRICS
// -------------------------------------------------------------
$total_posts = 0;
$pinned_posts = 0;
$total_comments = 0;

$p_cnt = @mysqli_query($conn, "SELECT COUNT(*) as total, SUM(CASE WHEN is_pinned=1 THEN 1 ELSE 0 END) as pinned FROM community_posts");
if ($p_cnt) {
    $row = mysqli_fetch_assoc($p_cnt);
    $total_posts = (int)($row['total'] ?? 0);
    $pinned_posts = (int)($row['pinned'] ?? 0);
}

$c_cnt = @mysqli_query($conn, "SELECT COUNT(*) as c FROM post_comments");
if ($c_cnt) {
    $total_comments = (int)(mysqli_fetch_assoc($c_cnt)['c'] ?? 0);
}

// -------------------------------------------------------------
// DATA QUERIES
// -------------------------------------------------------------
$posts_list = [];
$comments_list = [];

if ($tab === 'comments') {
    $c_sql = "
        SELECT pc.id, pc.post_id, pc.comment, pc.created_at,
               u.full_name as user_name, u.role as user_role,
               cp.title as post_title
        FROM post_comments pc
        JOIN users u ON pc.user_id = u.id
        LEFT JOIN community_posts cp ON pc.post_id = cp.id
        ORDER BY pc.id DESC
        LIMIT 50
    ";
    $c_res = @mysqli_query($conn, $c_sql);
    if ($c_res) $comments_list = mysqli_fetch_all($c_res, MYSQLI_ASSOC);
} else {
    $where = ["1=1"];
    $params = [];
    $types = "";

    if ($tab === 'pinned') {
        $where[] = "cp.is_pinned = 1";
    } elseif ($tab === 'showcase') {
        $where[] = "cp.post_type = 'showcase'";
    }

    if ($search !== '') {
        $where[] = "(cp.title LIKE ? OR cp.content LIKE ? OR u.full_name LIKE ?)";
        $lk = "%$search%";
        $params[] = $lk; $params[] = $lk; $params[] = $lk;
        $types .= "sss";
    }

    $where_clause = implode(" AND ", $where);
    $sql = "
        SELECT cp.id, cp.author_id, cp.author_role, cp.title, cp.content, cp.tags, 
               cp.post_type, cp.likes_count, cp.comments_count, cp.is_pinned, cp.created_at,
               u.full_name as author_name, u.email as author_email, u.division
        FROM community_posts cp
        LEFT JOIN users u ON cp.author_id = u.id
        WHERE $where_clause
        ORDER BY cp.is_pinned DESC, cp.id DESC
        LIMIT 50
    ";
    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $posts_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Community Moderation — InternHub Admin</title>
    <?php include __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
        .cm-container {
            padding: 24px 32px 48px;
            max-width: 1540px;
            margin: 0 auto;
        }
        .cm-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 22px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .cm-title {
            font-size: 1.6rem;
            font-weight: 800;
            color: #1e1b4b;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .cm-subtitle {
            font-size: 0.86rem;
            color: #64748b;
            margin-top: 4px;
        }

        /* Stats Strip */
        .cm-stats-strip {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 22px;
        }
        .cm-stat-card {
            background: #ffffff;
            border-radius: 14px;
            padding: 18px 20px;
            border: 1px solid #eef1f6;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.02);
        }
        .cm-stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
        }
        .cm-stat-val {
            font-size: 1.5rem;
            font-weight: 800;
            color: #1e1b4b;
            line-height: 1.1;
        }
        .cm-stat-lbl {
            font-size: 0.76rem;
            font-weight: 600;
            color: #64748b;
            margin-top: 2px;
        }

        /* Tabs */
        .cm-tabs {
            display: flex;
            gap: 8px;
            background: #ffffff;
            padding: 6px;
            border-radius: 14px;
            border: 1px solid #eef1f6;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .cm-tab {
            padding: 9px 18px;
            border-radius: 10px;
            font-size: 0.86rem;
            font-weight: 600;
            color: #64748b;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }
        .cm-tab:hover { background: #f8fafc; color: #1e1b4b; }
        .cm-tab.active { background: #eddff7; color: #3a104f; font-weight: 700; }

        /* Post Card Item */
        .post-feed {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .post-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #eef1f6;
            padding: 22px 24px;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.02);
            transition: transform 0.2s ease;
        }
        .post-card:hover { transform: translateY(-2px); }
        .post-card.is-pinned {
            border: 1.5px solid #d8b4fe;
            background: #fdfaff;
        }
        .post-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }
        .post-author {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .author-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #eddff7;
            color: #3a104f;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 0.95rem;
        }
        .author-name {
            font-weight: 700;
            color: #1e1b4b;
            font-size: 0.92rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .author-meta {
            font-size: 0.74rem;
            color: #64748b;
            margin-top: 2px;
        }
        .post-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: #1e1b4b;
            margin: 0 0 8px 0;
            line-height: 1.3;
        }
        .post-body {
            font-size: 0.88rem;
            color: #475569;
            line-height: 1.5;
            margin-bottom: 14px;
        }
        .post-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.78rem;
            color: #64748b;
            padding-top: 12px;
            border-top: 1px solid #f8fafc;
        }
    </style>
</head>
<body>

    <?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

    <div class="cm-container">
        
        <!-- Header -->
        <div class="cm-header">
            <div>
                <h1 class="cm-title"><i class="fa-solid fa-users-viewfinder" style="color: #7c3aed;"></i> Community & Moderation</h1>
                <div class="cm-subtitle">Moderation of university technical discussions, project showcases, and institutional announcements.</div>
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="button" class="btn btn-primary" style="background: #3a104f; border-color: #3a104f; border-radius: 12px; font-weight: 600; padding: 10px 18px;" onclick="openModal('createPostModal')">
                    <i class="fa-solid fa-bullhorn"></i> New Official Post
                </button>
            </div>
        </div>

        <!-- Feedback Alerts -->
        <?php if ($successMsg): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert" style="border-radius: 12px;">
                <i class="fa-solid fa-circle-check"></i> <?php echo $successMsg; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-radius: 12px;">
                <i class="fa-solid fa-triangle-exclamation"></i> <?php echo $errorMsg; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Stats Strip -->
        <div class="cm-stats-strip">
            <div class="cm-stat-card">
                <div class="cm-stat-icon" style="background: #eddff7; color: #6b21a8;">
                    <i class="fa-solid fa-comments"></i>
                </div>
                <div>
                    <div class="cm-stat-val"><?php echo number_format($total_posts); ?></div>
                    <div class="cm-stat-lbl">Community Posts</div>
                </div>
            </div>
            <div class="cm-stat-card">
                <div class="cm-stat-icon" style="background: #d1fae5; color: #047857;">
                    <i class="fa-solid fa-thumbtack"></i>
                </div>
                <div>
                    <div class="cm-stat-val"><?php echo number_format($pinned_posts); ?></div>
                    <div class="cm-stat-lbl">Pinned Announcements</div>
                </div>
            </div>
            <div class="cm-stat-card">
                <div class="cm-stat-icon" style="background: #eff6ff; color: #1d4ed8;">
                    <i class="fa-solid fa-reply-all"></i>
                </div>
                <div>
                    <div class="cm-stat-val"><?php echo number_format($total_comments); ?></div>
                    <div class="cm-stat-lbl">Comments & Replies</div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="cm-tabs">
            <a href="?tab=posts" class="cm-tab <?php echo $tab === 'posts' ? 'active' : ''; ?>">
                <i class="fa-solid fa-list-ul"></i> All Posts
            </a>
            <a href="?tab=pinned" class="cm-tab <?php echo $tab === 'pinned' ? 'active' : ''; ?>">
                <i class="fa-solid fa-thumbtack"></i> Pinned Announcements (<?php echo $pinned_posts; ?>)
            </a>
            <a href="?tab=showcase" class="cm-tab <?php echo $tab === 'showcase' ? 'active' : ''; ?>">
                <i class="fa-solid fa-rocket"></i> Student Showcases
            </a>
            <a href="?tab=comments" class="cm-tab <?php echo $tab === 'comments' ? 'active' : ''; ?>">
                <i class="fa-solid fa-comment-dots"></i> Comments Moderation (<?php echo $total_comments; ?>)
            </a>
        </div>

        <!-- POST FEED VIEW -->
        <?php if ($tab !== 'comments'): ?>
            <?php if (!empty($posts_list)): ?>
                <div class="post-feed">
                    <?php foreach ($posts_list as $p): ?>
                        <?php 
                            $authName = $p['author_name'] ?: 'University Administrator';
                            $authInit = strtoupper(substr($authName, 0, 1));
                            $isPinned = (int)$p['is_pinned'];
                        ?>
                        <div class="post-card <?php echo $isPinned ? 'is-pinned' : ''; ?>">
                            <div class="post-head">
                                <div class="post-author">
                                    <div class="author-avatar"><?php echo $authInit; ?></div>
                                    <div>
                                        <div class="author-name">
                                            <span><?php echo h($authName); ?></span>
                                            <span style="font-size: 0.68rem; font-weight: 700; background: #f1f5f9; padding: 2px 7px; border-radius: 6px; text-transform: uppercase;">
                                                <?php echo h($p['author_role']); ?>
                                            </span>
                                            <?php if ($isPinned): ?>
                                                <span style="font-size: 0.72rem; color: #7c3aed; font-weight: 700;">
                                                    <i class="fa-solid fa-thumbtack"></i> Pinned Notice
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="author-meta">
                                            <?php echo $p['created_at'] ? date('d M Y, h:i A', strtotime($p['created_at'])) : '-'; ?> • Category: <strong><?php echo ucfirst($p['post_type']); ?></strong>
                                        </div>
                                    </div>
                                </div>
                                <div style="display: flex; gap: 8px;">
                                    <!-- Toggle Pin -->
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="toggle_pin">
                                        <input type="hidden" name="post_id" value="<?php echo $p['id']; ?>">
                                        <input type="hidden" name="target_pin" value="<?php echo $isPinned ? 0 : 1; ?>">
                                        <button type="submit" class="btn btn-sm <?php echo $isPinned ? 'btn-outline-primary' : 'btn-light'; ?>" style="border-radius: 8px;" title="<?php echo $isPinned ? 'Unpin Post' : 'Pin to Top'; ?>">
                                            <i class="fa-solid fa-thumbtack"></i>
                                        </button>
                                    </form>

                                    <!-- Delete Post -->
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Permanently remove this community post and its replies?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete_post">
                                        <input type="hidden" name="post_id" value="<?php echo $p['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" style="border-radius: 8px;" title="Delete Post">
                                            <i class="fa-regular fa-trash-can"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <h3 class="post-title"><?php echo h($p['title']); ?></h3>
                            <div class="post-body"><?php echo nl2br(h($p['content'])); ?></div>

                            <div class="post-footer">
                                <div style="display: flex; gap: 14px;">
                                    <span><i class="fa-regular fa-heart" style="color: #ec4899;"></i> <?php echo (int)$p['likes_count']; ?> Likes</span>
                                    <span><i class="fa-regular fa-comment" style="color: #7c3aed;"></i> <?php echo (int)$p['comments_count']; ?> Replies</span>
                                </div>
                                <span style="font-size: 0.74rem; color: #94a3b8;">Post ID #<?php echo $p['id']; ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="background: #ffffff; border-radius: 18px; padding: 48px; text-align: center; border: 1px solid #eef1f6;">
                    <i class="fa-regular fa-comments" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 14px;"></i>
                    <h3 style="font-size: 1.1rem; color: #1e1b4b; font-weight: 700;">No Community Posts in this Category</h3>
                    <p style="font-size: 0.86rem; color: #64748b; margin-top: 4px;">Click 'New Official Post' above to broadcast to students and faculty.</p>
                </div>
            <?php endif; ?>

        <!-- COMMENTS MODERATION VIEW -->
        <?php else: ?>
            <div style="background: #ffffff; border-radius: 18px; border: 1px solid #eef1f6; overflow: hidden;">
                <table class="table" style="margin: 0; vertical-align: middle;">
                    <thead style="background: #f8fafc; font-size: 0.78rem; text-transform: uppercase; color: #64748b;">
                        <tr>
                            <th style="padding: 14px 18px;">Author</th>
                            <th>On Post</th>
                            <th>Comment Content</th>
                            <th>Date</th>
                            <th style="text-align: right; padding: 14px 18px;">Action</th>
                        </tr>
                    </thead>
                    <tbody style="font-size: 0.84rem;">
                        <?php if (!empty($comments_list)): ?>
                            <?php foreach ($comments_list as $c): ?>
                                <tr>
                                    <td style="padding: 14px 18px;">
                                        <div style="font-weight: 700; color: #1e1b4b;"><?php echo h($c['user_name']); ?></div>
                                        <span style="font-size: 0.7rem; background: #f1f5f9; padding: 2px 6px; border-radius: 4px;"><?php echo ucfirst($c['user_role']); ?></span>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; color: #7c3aed; max-width: 220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                            <?php echo h($c['post_title'] ?: 'Post #' . $c['post_id']); ?>
                                        </div>
                                    </td>
                                    <td style="max-width: 360px;">
                                        <div style="color: #334155; line-height: 1.4;"><?php echo h($c['comment']); ?></div>
                                    </td>
                                    <td style="color: #64748b; font-size: 0.76rem;">
                                        <?php echo date('d M, h:i A', strtotime($c['created_at'])); ?>
                                    </td>
                                    <td style="text-align: right; padding: 14px 18px;">
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this comment?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="action" value="delete_comment">
                                            <input type="hidden" name="comment_id" value="<?php echo $c['id']; ?>">
                                            <input type="hidden" name="post_id" value="<?php echo $c['post_id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" style="border-radius: 8px;">
                                                <i class="fa-regular fa-trash-can"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 36px; color: #94a3b8;">No comments currently logged.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    </div>

    <!-- MODAL: CREATE OFFICIAL POST -->
    <div class="custom-modal" id="createPostModal" style="display: none;">
        <div class="modal-box" style="max-width: 600px;">
            <div class="modal-head">
                <h3 class="modal-head-title"><i class="fa-solid fa-bullhorn"></i> New Official Community Post</h3>
                <button type="button" class="modal-close" onclick="closeModal('createPostModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="create_post">
                <div class="modal-body-scroll">
                    <div class="mb-3">
                        <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Post Headline</label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Official Circular: Winter Internship Registration Guidelines" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Category</label>
                            <select name="post_type" class="form-select">
                                <option value="general">Institutional Announcement</option>
                                <option value="showcase">Student Work Showcase</option>
                                <option value="internship_experience">Career Guidance</option>
                                <option value="question">Discussion Query</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Tags (comma separated)</label>
                            <input type="text" name="tags" class="form-control" value="Official, Announcement">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="font-weight: 600; font-size: 0.84rem;">Announcement Message</label>
                        <textarea name="content" class="form-control" rows="5" placeholder="Write message to students and faculty..." required></textarea>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="is_pinned" id="pinCheck" checked>
                        <label class="form-check-label" for="pinCheck" style="font-size: 0.84rem; font-weight: 600;">
                            Pin this post to the top of all student and faculty community feeds
                        </label>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-light" onclick="closeModal('createPostModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background: #3a104f; border-color: #3a104f;">Publish Notice</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Layout Close -->
    </main>
    </div>

    <?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>

    <script>
    function openModal(id) {
        const el = document.getElementById(id);
        if (el) el.style.display = 'flex';
    }
    function closeModal(id) {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    }
    document.querySelectorAll('.custom-modal').forEach(m => {
        m.addEventListener('click', function(e) {
            if (e.target === this) this.style.display = 'none';
        });
    });
    </script>
</body>
</html>
