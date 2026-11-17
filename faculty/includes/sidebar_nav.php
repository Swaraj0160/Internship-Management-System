<?php
/* -----------------------------------------------------------------
   InternHub — Faculty Portal Unified Navigation Shell
   Structure & visual fidelity aligned with approved reference:
   media_1794935844127.jpg
   ----------------------------------------------------------------- */

if (!function_exists('h')) {
    function h($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

// Compute faculty divisions if not already provided
if (!isset($faculty_divisions) || !is_array($faculty_divisions)) {
    $faculty_id_for_nav = (int)($_SESSION['user_id'] ?? 0);
    $faculty_divisions = [];

    $fd_res = mysqli_query(
        $conn,
        "SELECT DISTINCT division FROM faculty_divisions
         WHERE faculty_id='$faculty_id_for_nav'
           AND division IS NOT NULL
           AND TRIM(division) <> ''"
    );

    if ($fd_res) {
        while ($row = mysqli_fetch_assoc($fd_res)) {
            $v = trim((string)$row['division']);
            if ($v !== '') {
                $faculty_divisions[] = $v;
            }
        }
    }

    if (empty($faculty_divisions)) {
        $fac_res = mysqli_query($conn, "SELECT full_name, faculty_division FROM users WHERE id='$faculty_id_for_nav'");
        $fac_row = $fac_res ? mysqli_fetch_assoc($fac_res) : [];
        $legacy = trim((string)($fac_row['faculty_division'] ?? ''));
        if ($legacy !== '') {
            $faculty_divisions[] = $legacy;
        }
    } else {
        $fac_res = mysqli_query($conn, "SELECT full_name FROM users WHERE id='$faculty_id_for_nav'");
        $fac_row = $fac_res ? mysqli_fetch_assoc($fac_res) : [];
    }

    $faculty_divisions = array_values(array_unique($faculty_divisions));
}

if (!isset($faculty_name)) {
    $faculty_name = trim((string)($fac_row['full_name'] ?? $_SESSION['full_name'] ?? $_SESSION['name'] ?? 'Faculty Mentor'));
}

if (!isset($faculty_division)) {
    $faculty_division = !empty($faculty_divisions) ? implode(', ', $faculty_divisions) : 'Faculty Guide';
}

if (!isset($faculty_initial)) {
    $faculty_name_clean = preg_replace('/^(Prof\.?|Dr\.?|Professor)\s*/i', '', $faculty_name);
    $faculty_initial = strtoupper(substr($faculty_name_clean ?: $faculty_name ?: 'F', 0, 1));
}

// Form division SQL for pending badge calculation
$nav_div_sql = '';
if (!empty($faculty_divisions)) {
    $escaped_divs = array_map(fn($d) => "'" . mysqli_real_escape_string($conn, $d) . "'", $faculty_divisions);
    $nav_div_sql = implode(',', $escaped_divs);
}

// Count pending applications for badge
$pending_apps_count = 0;
if ($nav_div_sql !== '') {
    $p_res = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM internships i JOIN users u ON i.student_id = u.id WHERE u.division IN ($nav_div_sql) AND i.status = 'Pending'");
    if ($p_res) {
        $pending_apps_count = (int)(mysqli_fetch_assoc($p_res)['cnt'] ?? 0);
    }
}

// Count unread notifications
$unread_notif_count = 0;
$fac_id_curr = (int)($_SESSION['user_id'] ?? 0);
$notif_res = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM admin_notifications WHERE target_role IN ('faculty', 'all')");
if ($notif_res) {
    $unread_notif_count = (int)(mysqli_fetch_assoc($notif_res)['cnt'] ?? 0);
}

$activePage = $activePage ?? '';

function f_active($page, $current)
{
    return $page === $current ? ' class="sidebar-nav-item active"' : ' class="sidebar-nav-item"';
}
?>

<div class="app">
    <!-- Sidebar -->
    <aside class="sidebar" id="facultySidebar">
        <!-- Brand Header -->
        <div class="sidebar-brand-box">
            <div class="sidebar-crest-row">
                <img src="../assets/images/mit_adt_crest.png" alt="MIT-ADT Crest" class="sidebar-crest-img" onerror="this.src='../assets/images/MIT_LOGO.jpg'">
                <div class="sidebar-crest-text">
                    <div class="sidebar-crest-title">MIT-ADT University</div>
                    <div class="sidebar-crest-subtitle">PUNE, INDIA</div>
                </div>
            </div>
            <div class="sidebar-gold-line"></div>
        </div>

        <!-- Faculty Profile Card -->
        <div class="sidebar-profile-card">
            <div class="sidebar-avatar-circle"><?php echo h($faculty_initial); ?></div>
            <div class="sidebar-profile-meta">
                <div class="sidebar-profile-name" title="<?php echo h($faculty_name); ?>"><?php echo h($faculty_name); ?></div>
                <div class="sidebar-profile-role">Faculty Guide / Mentor</div>
                <div class="sidebar-profile-division"><?php echo h($faculty_division); ?></div>
            </div>
        </div>

        <!-- Navigation Menu -->
        <div class="sidebar-nav-container">
            <div class="sidebar-nav-section-title">Workspace</div>
            <ul class="sidebar-nav-list">
                <li<?php echo f_active('dashboard.php', $activePage); ?>>
                    <a href="dashboard.php">
                        <i class="fa-solid fa-house"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li<?php echo f_active('internship_oversight.php', $activePage); ?>>
                    <a href="internship_oversight.php">
                        <i class="fa-solid fa-clipboard-check"></i>
                        <span>Internship Oversight</span>
                        <?php if ($pending_apps_count > 0): ?>
                            <span class="sidebar-badge sidebar-badge-count"><?php echo $pending_apps_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li<?php echo f_active('student_directory.php', $activePage); ?>>
                    <a href="student_directory.php">
                        <i class="fa-solid fa-users"></i>
                        <span>Student Directory</span>
                    </a>
                </li>
                <li<?php echo f_active('student_portfolios.php', $activePage); ?>>
                    <a href="student_portfolios.php">
                        <i class="fa-solid fa-address-card"></i>
                        <span>Student Portfolios</span>
                    </a>
                </li>
                <li<?php echo f_active('evaluations_marks.php', $activePage); ?>>
                    <a href="evaluations_marks.php">
                        <i class="fa-solid fa-graduation-cap"></i>
                        <span>Evaluations & Marks</span>
                    </a>
                </li>
                <li<?php echo f_active('reports_analytics.php', $activePage); ?>>
                    <a href="reports_analytics.php">
                        <i class="fa-solid fa-chart-pie"></i>
                        <span>Reports & Analytics</span>
                    </a>
                </li>
                <li<?php echo f_active('manage_notifications.php', $activePage); ?>>
                    <a href="manage_notifications.php">
                        <i class="fa-solid fa-bell"></i>
                        <span>Notifications</span>
                        <?php if ($unread_notif_count > 0): ?>
                            <span class="sidebar-badge sidebar-badge-count"><?php echo $unread_notif_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
            </ul>

            <div class="sidebar-nav-section-title">AI & Support</div>
            <ul class="sidebar-nav-list">
                <li<?php echo f_active('ai_assistant.php', $activePage); ?>>
                    <a href="ai_assistant.php">
                        <i class="fa-solid fa-wand-magic-sparkles"></i>
                        <span>AI Assistant</span>
                        <span class="sidebar-badge sidebar-badge-new">New</span>
                    </a>
                </li>
                <li<?php echo f_active('change_password.php', $activePage); ?>>
                    <a href="change_password.php">
                        <i class="fa-solid fa-gear"></i>
                        <span>Settings</span>
                    </a>
                </li>
                <li<?php echo f_active('help.php', $activePage); ?>>
                    <a href="help.php">
                        <i class="fa-solid fa-circle-question"></i>
                        <span>Help & Support</span>
                    </a>
                </li>
            </ul>
        </div>

        <!-- Transparent Dome Illustration at Bottom -->
        <div class="sidebar-bottom-art">
            <img src="../assets/images/sidebar_dome_bottom.png" alt="MIT-ADT World Peace Dome" onerror="this.style.display='none'">
        </div>

        <!-- Logout Link -->
        <div class="sidebar-footer-row">
            <a href="../logout.php" class="sidebar-logout-btn">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
                <span>Logout</span>
            </a>
        </div>
    </aside>

    <!-- Mobile Backdrop -->
    <div class="sidebar-backdrop" id="facultySidebarBackdrop"></div>

    <!-- Main Content Shell -->
    <main class="main" id="mainContent">
        <!-- Universal Topbar -->
        <header class="topbar">
            <div style="display: flex; align-items: center; gap: 14px;">
                <button type="button" class="mobile-menu-toggle" id="facultyMenuToggle" aria-label="Toggle Navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="topbar-left">
                    <div class="topbar-greeting-name">
                        <span>Good morning, <?php echo h($faculty_name); ?></span>
                        <span>👋</span>
                    </div>
                    <div class="topbar-greeting-small">Welcome to your mentorship workspace</div>
                </div>
            </div>

            <!-- Global Search -->
            <form action="student_directory.php" method="GET" class="topbar-search-wrap">
                <i class="fa-solid fa-magnifying-glass topbar-search-icon"></i>
                <input type="text" name="q" id="globalSearchInput" class="topbar-search-input" placeholder="Search students, PRN, companies, skills..." autocomplete="off">
                <span class="topbar-search-shortcut">Ctrl K</span>
            </form>

            <!-- Topbar Right Actions -->
            <div class="topbar-right">
                <a href="manage_notifications.php" class="topbar-btn" title="Notifications">
                    <i class="fa-regular fa-bell"></i>
                    <?php if ($unread_notif_count > 0): ?>
                        <span class="topbar-btn-badge"><?php echo $unread_notif_count; ?></span>
                    <?php endif; ?>
                </a>

                <div class="topbar-user-menu">
                    <button type="button" class="topbar-user-btn" id="topbarUserDropdownTrigger">
                        <div class="topbar-user-avatar"><?php echo h($faculty_initial); ?></div>
                        <span class="topbar-user-name"><?php echo h($faculty_name_clean ?: $faculty_name); ?></span>
                        <i class="fa-solid fa-chevron-down" style="font-size: 0.72rem; color: #94a3b8; margin-left: 2px;"></i>
                    </button>

                    <div class="user-dropdown-popover" id="topbarUserDropdownPopover">
                        <div style="padding: 6px 12px 8px; border-bottom: 1px solid #f1f5f9;">
                            <div style="font-size: 0.82rem; font-weight: 700; color: #1e1b4b;"><?php echo h($faculty_name); ?></div>
                            <div style="font-size: 0.72rem; color: #64748b;"><?php echo h($faculty_division); ?></div>
                        </div>
                        <a href="student_directory.php" class="user-dropdown-item"><i class="fa-solid fa-users"></i>Student Directory</a>
                        <a href="internship_oversight.php" class="user-dropdown-item"><i class="fa-solid fa-clipboard-check"></i>Applications</a>
                        <a href="evaluations_marks.php" class="user-dropdown-item"><i class="fa-solid fa-award"></i>Evaluations</a>
                        <div class="user-dropdown-divider"></div>
                        <a href="change_password.php" class="user-dropdown-item"><i class="fa-solid fa-key"></i>Change Password</a>
                        <a href="help.php" class="user-dropdown-item"><i class="fa-solid fa-circle-question"></i>Help & Support</a>
                        <div class="user-dropdown-divider"></div>
                        <a href="../logout.php" class="user-dropdown-item" style="color: #ef4444;"><i class="fa-solid fa-right-from-bracket" style="color: #ef4444;"></i>Logout</a>
                    </div>
                </div>
            </div>
        </header>
